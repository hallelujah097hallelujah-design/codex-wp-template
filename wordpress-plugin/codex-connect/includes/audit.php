<?php
/**
 * Article check-up: images without a description (alt) and broken links, across all
 * published articles. Runs a few articles per request so it never times out; the admin
 * tab drives it with a button, and Codex can run it with `wp.py audit`.
 * Links are checked from the server, so it works even though Codex cannot reach other sites.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CODEX_CONNECT_AUDIT_BATCH = 3;

/** 'ok', 'broken' (404/410 or the site does not exist) or 'unknown' (blocked, timeout...). */
function codex_connect_check_url( $url ) {
	$key    = 'codex_url_' . md5( $url );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return $cached;
	}
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = wp_parse_url( $url, PHP_URL_HOST );
	$post_id = $host === $home ? url_to_postid( $url ) : 0;
	if ( $post_id && get_post( $post_id ) && 'publish' === get_post_status( $post_id ) ) {
		$status = array( 'state' => 'ok', 'code' => 200 );
	} else {
		$args     = array( 'timeout' => 6, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 (compatible; CodexConnect link check)' );
		$response = $host === $home ? wp_remote_head( $url, $args ) : wp_safe_remote_head( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 400, 403, 405, 501 ), true ) ) { // some sites refuse HEAD; ask again with GET
			$args['limit_response_size'] = 2048;
			$response                    = $host === $home ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
			$code                        = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}
		if ( is_wp_error( $response ) ) {
			$msg    = $response->get_error_message();
			// wp_safe_remote_* reports an address that does not resolve as "not a valid URL"
			$gone   = false !== stripos( $msg, 'resolve' ) || false !== stripos( $msg, 'refused' ) || gethostbyname( $host ) === $host;
			$status = array( 'state' => $gone ? 'broken' : 'unknown', 'code' => 0, 'message' => $gone ? 'サイトが見つかりません' : '応答がありません' );
		} elseif ( in_array( $code, array( 404, 410 ), true ) ) {
			$status = array( 'state' => 'broken', 'code' => $code, 'message' => 'ページが見つかりません' );
		} elseif ( $code >= 400 ) {
			$status = array( 'state' => 'unknown', 'code' => $code, 'message' => '確認できませんでした' );
		} else {
			$status = array( 'state' => 'ok', 'code' => $code );
		}
	}
	set_transient( $key, $status, DAY_IN_SECONDS );
	return $status;
}

function codex_connect_audit_post( $post ) {
	$issues  = array();
	$content = $post->post_content;
	if ( preg_match_all( '#<img\b[^>]*>#i', $content, $imgs ) ) {
		foreach ( $imgs[0] as $img ) {
			if ( ! preg_match( '#\balt\s*=\s*"([^"]*)"#i', $img, $alt ) || '' === trim( $alt[1] ) ) {
				preg_match( '#\bsrc\s*=\s*"([^"]*)"#i', $img, $src );
				$issues[] = array( 'type' => 'alt', 'label' => '画像の説明文（alt）がない', 'detail' => $src[1] ?? '' );
			}
		}
	}
	if ( preg_match_all( '#<a\b[^>]*\bhref\s*=\s*"([^"]+)"#i', $content, $links ) ) {
		foreach ( array_slice( array_unique( $links[1] ), 0, 100 ) as $href ) {
			$href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
			if ( '/' === ( $href[0] ?? '' ) && '/' !== ( $href[1] ?? '' ) ) {
				$href = home_url( $href );
			}
			if ( ! preg_match( '#^https?://#i', $href ) ) {
				continue; // #anchor, mailto:, tel:
			}
			$status = codex_connect_check_url( $href );
			if ( 'ok' !== $status['state'] ) {
				$issues[] = array(
					'type'   => 'broken' === $status['state'] ? 'link' : 'link_unknown',
					'label'  => 'broken' === $status['state'] ? 'リンク切れ' : 'リンク先を確認できない',
					'detail' => $href,
					'status' => $status['message'] . ( $status['code'] ? '（' . $status['code'] . '）' : '' ),
				);
			}
		}
	}
	return $issues;
}

/** Checks the next few articles; offset 0 starts a new check-up. */
function codex_connect_audit_run( $offset ) {
	$offset = max( 0, (int) $offset );
	$query  = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => CODEX_CONNECT_AUDIT_BATCH,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	$state = 0 === $offset ? array( 'started' => wp_date( 'Y-m-d H:i' ), 'finished' => '', 'checked' => 0, 'issues' => array() ) : get_option( 'codex_connect_audit', array( 'issues' => array() ) );
	foreach ( $query->posts as $post ) {
		foreach ( codex_connect_audit_post( $post ) as $issue ) {
			$state['issues'][] = array( 'post_id' => $post->ID, 'title' => get_the_title( $post ) ) + $issue;
		}
	}
	$state['checked'] = $offset + count( $query->posts );
	$state['total']   = (int) $query->found_posts;
	$done             = $state['checked'] >= $state['total'];
	if ( $done ) {
		$state['finished'] = wp_date( 'Y-m-d H:i' );
	}
	update_option( 'codex_connect_audit', $state, false );
	return array(
		'checked'     => $state['checked'],
		'total'       => $state['total'],
		'done'        => $done,
		'next_offset' => $done ? null : $state['checked'],
		'issues'      => count( $state['issues'] ),
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'codex-connect/v1',
			'/audit',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
					'callback'            => function () {
						return get_option( 'codex_connect_audit', array( 'started' => '', 'issues' => array() ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
					'args'                => array( 'offset' => array( 'type' => 'integer', 'default' => 0 ) ),
					'callback'            => function ( WP_REST_Request $request ) {
						return codex_connect_audit_run( $request['offset'] );
					},
				),
			)
		);
	}
);

function codex_connect_render_audit_tab() {
	$state = get_option( 'codex_connect_audit', array() );
	echo '<p>公開中の全記事について、<strong>画像の説明文（alt）の抜け</strong>と<strong>リンク切れ</strong>を調べます。記事数が多いと数分かかります。この画面を開いたままお待ちください。</p>';
	echo '<p><button type="button" class="button button-primary" id="codex-audit-start">点検を始める</button> <span id="codex-audit-progress"></span></p>';
	if ( ! empty( $state['started'] ) ) {
		printf(
			'<p>前回の点検：%s（%s／%d記事）　見つかった項目：%d件</p>',
			esc_html( $state['started'] ),
			$state['finished'] ? '完了' : '途中まで',
			(int) ( $state['total'] ?? 0 ),
			count( $state['issues'] )
		);
	}
	if ( ! empty( $state['issues'] ) ) {
		$order = array( 'link' => 0, 'alt' => 1, 'link_unknown' => 2 );
		usort( $state['issues'], fn( $a, $b ) => $order[ $a['type'] ] <=> $order[ $b['type'] ] );
		echo '<table class="widefat striped"><thead><tr><th>記事</th><th>内容</th><th>場所</th><th>状態</th></tr></thead><tbody>';
		foreach ( $state['issues'] as $i ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td style="color:%s">%s</td><td style="word-break:break-all"><code>%s</code></td><td>%s</td></tr>',
				esc_url( get_edit_post_link( $i['post_id'] ) ),
				esc_html( $i['title'] ),
				'link' === $i['type'] ? '#b32d2e' : ( 'alt' === $i['type'] ? '#996800' : '#50575e' ),
				esc_html( $i['label'] ),
				esc_html( $i['detail'] ),
				esc_html( $i['status'] ?? '' )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">「リンク先を確認できない」は、相手のサイトが自動チェックを断っているだけのことが多いです。気になるものだけ開いて確かめてください。Codexに「点検で見つかった画像の説明文を直して」と頼むこともできます。</p>';
	}
	?>
	<script>
	(function () {
		var btn = document.getElementById('codex-audit-start'), out = document.getElementById('codex-audit-progress');
		var url = <?php echo wp_json_encode( rest_url( 'codex-connect/v1/audit' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
		function step(offset) {
			fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ offset: offset }) })
				.then(function (r) { return r.json(); })
				.then(function (r) {
					if (r.code) { out.textContent = 'エラー：' + r.message; btn.disabled = false; return; }
					out.textContent = r.checked + ' / ' + r.total + ' 記事を点検しました（見つかった項目 ' + r.issues + ' 件）';
					if (r.done) { location.reload(); } else { step(r.next_offset); }
				})
				.catch(function () { out.textContent = '通信が途切れました。もう一度押してください。'; btn.disabled = false; });
		}
		btn.addEventListener('click', function () { btn.disabled = true; out.textContent = '点検中…'; step(0); });
	})();
	</script>
	<?php
}
