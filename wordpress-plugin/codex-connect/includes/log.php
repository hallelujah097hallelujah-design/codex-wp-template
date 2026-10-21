<?php
/**
 * Work history: every change the Codex user makes over the REST API (drafts, updates,
 * trash, images, memos), shown under Tools → Codex接続 → 作業履歴.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CODEX_CONNECT_LOG_MAX = 500;

function codex_connect_log_label( WP_REST_Request $request ) {
	$route  = $request->get_route();
	$method = $request->get_method();
	if ( preg_match( '#^/wp/v2/(posts|pages)$#', $route ) && 'POST' === $method ) {
		return '下書きを作成';
	}
	if ( preg_match( '#^/wp/v2/(posts|pages)/\d+$#', $route ) ) {
		return 'DELETE' === $method ? 'ゴミ箱へ移動' : '記事を更新';
	}
	if ( preg_match( '#^/wp/v2/media#', $route ) ) {
		return 'DELETE' === $method ? '画像を削除' : '画像をアップロード';
	}
	if ( 0 === strpos( $route, '/codex-connect/v1/note/' ) ) {
		return 'メモを更新';
	}
	if ( 0 === strpos( $route, '/codex-connect/v1/preview/' ) ) {
		return 'プレビューリンクを発行';
	}
	if ( 0 === strpos( $route, '/codex-connect/v1/schedule/' ) ) {
		return 'DELETE' === $method ? '予約反映を取り消し' : '予約反映を設定';
	}
	return $method . ' ' . $route;
}

function codex_connect_log( WP_REST_Request $request, $result, $post_id = 0, $ok = true ) {
	if ( ! $post_id && preg_match( '#/(\d+)$#', $request->get_route(), $m ) ) {
		$post_id = (int) $m[1];
	}
	// wp.py names the task it is doing (e.g. "リライト案を反映"), percent-encoded
	$task = sanitize_text_field( rawurldecode( (string) $request->get_header( 'x_codex_action' ) ) );
	codex_connect_log_entry( $task, codex_connect_log_label( $request ), $post_id, $result, $ok );
}

/* Also used for work done without a request, such as a scheduled apply. */
function codex_connect_log_entry( $task, $action, $post_id, $result, $ok = true ) {
	$log = get_option( 'codex_connect_log', array() );
	array_unshift(
		$log,
		array(
			'time'    => current_time( 'mysql' ),
			'task'    => mb_substr( (string) $task, 0, 60 ),
			'action'  => $action,
			'post_id' => (int) $post_id,
			'title'   => $post_id ? get_the_title( $post_id ) : '',
			'result'  => $result,
			'ok'      => $ok,
		)
	);
	update_option( 'codex_connect_log', array_slice( $log, 0, CODEX_CONNECT_LOG_MAX ), false );
}

add_filter(
	'rest_post_dispatch',
	function ( $response, $server, WP_REST_Request $request ) {
		// The article check-up only reads; logging each batch would flood the history
		if ( in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) || ! codex_connect_is_codex_user()
			|| 0 === strpos( $request->get_route(), '/codex-connect/v1/audit' ) ) {
			return $response;
		}
		$status = $response instanceof WP_REST_Response ? $response->get_status() : 500;
		if ( $response instanceof WP_REST_Response && in_array( $response->get_data()['code'] ?? '', array( 'codex_paused', 'codex_readonly' ), true ) ) {
			return $response; // already logged when it was stopped
		}
		$data    = $response instanceof WP_REST_Response ? $response->get_data() : array();
		$post_id = is_array( $data ) && isset( $data['id'] ) && is_numeric( $data['id'] ) ? (int) $data['id'] : 0;
		if ( is_array( $data ) && isset( $data['previous']['id'] ) ) {
			$post_id = (int) $data['previous']['id']; // DELETE responses
		}
		$ok = $status < 400;
		codex_connect_log( $request, $ok ? '成功' : '失敗（' . ( $data['message'] ?? $status ) . '）', $post_id, $ok );
		return $response;
	},
	10,
	3
);

function codex_connect_render_log_tab() {
	if ( isset( $_POST['codex_connect_clear_log'] ) && check_admin_referer( 'codex_connect_clear_log' ) ) {
		delete_option( 'codex_connect_log' );
		echo '<div class="notice notice-success"><p>作業履歴を消去しました。</p></div>';
	}
	$log = get_option( 'codex_connect_log', array() );
	echo '<p>Codexがこのサイトで行った変更（下書きの作成、記事の更新、画像の追加、メモなど）の記録です。新しい順に最大' . (int) CODEX_CONNECT_LOG_MAX . '件まで残ります。記事を読んだだけの操作は記録しません。</p>';
	if ( ! $log ) {
		echo '<p><em>まだ記録はありません。</em></p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>日時</th><th>作業</th><th>操作</th><th>記事</th><th>結果</th></tr></thead><tbody>';
	foreach ( $log as $row ) {
		$title = $row['title'] ? $row['title'] : ( $row['post_id'] ? '#' . $row['post_id'] : '—' );
		if ( $row['post_id'] && get_post( $row['post_id'] ) ) {
			$title = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $row['post_id'] ) ), esc_html( $title ) );
		} else {
			$title = esc_html( $title );
		}
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="color:%s">%s</td></tr>',
			esc_html( $row['time'] ),
			esc_html( $row['task'] ? $row['task'] : '—' ),
			esc_html( $row['action'] ),
			$title, // escaped above
			$row['ok'] ? '#00813a' : '#b32d2e',
			esc_html( $row['result'] )
		);
	}
	echo '</tbody></table>';
	echo '<form method="post" style="margin-top:1em">';
	wp_nonce_field( 'codex_connect_clear_log' );
	submit_button( '履歴を消去', 'secondary', 'codex_connect_clear_log', false, array( 'onclick' => "return confirm('作業履歴をすべて消去しますか？')" ) );
	echo '</form>';
}
