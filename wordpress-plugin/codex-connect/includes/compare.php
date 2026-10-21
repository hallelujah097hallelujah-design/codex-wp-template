<?php
/**
 * Before/after view of a rewrite draft against its original article, colour-coded.
 * Opens from the post list ("変更点を比べる") for logged-in editors, or without login
 * from the same secret link as the preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Original article for a 【リライト案 #ID】 draft, or null. */
function codex_connect_draft_source( $draft ) {
	if ( ! $draft || ! preg_match( '/^【リライト案 #(\d+)】/u', $draft->post_title, $m ) ) {
		return null;
	}
	return get_post( (int) $m[1] );
}

/** Article body as plain lines: block markup removed, one paragraph/heading/list item per line. */
function codex_connect_plain_lines( $content ) {
	$content = preg_replace( '/<!--.*?-->/s', '', $content );
	$content = preg_replace( '#<(h[1-6])[^>]*>#i', "\n■ ", $content );
	$content = preg_replace( '#<img[^>]*?alt="([^"]*)"[^>]*>#i', "\n［画像：$1］\n", $content );
	$content = preg_replace( '#<img[^>]*>#i', "\n［画像］\n", $content );
	$content = preg_replace( '#<a [^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '$2［→$1］', $content );
	$content = preg_replace( '#</?(p|div|li|tr|blockquote|figure|figcaption|br|table|ul|ol|h[1-6])[^>]*>#i', "\n", $content );
	$content = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' );
	$lines   = array_filter( array_map( 'trim', explode( "\n", $content ) ), 'strlen' );
	return implode( "\n", $lines );
}

function codex_connect_compare_html( $draft, $source ) {
	$title = wp_text_diff( $source->post_title, preg_replace( '/^【リライト案 #\d+】/u', '', $draft->post_title ) );
	$body  = wp_text_diff(
		codex_connect_plain_lines( $source->post_content ),
		codex_connect_plain_lines( $draft->post_content ),
		array( 'title_left' => 'いまの記事', 'title_right' => 'リライト案' )
	);
	$html  = '<h2>タイトル</h2>' . ( $title ? $title : '<p>変更なし</p>' );
	$html .= '<h2>本文</h2>' . ( $body ? $body : '<p>変更なし</p>' );
	return $html;
}

function codex_connect_compare_css() {
	return '<style>.diff{width:100%;border-collapse:collapse;table-layout:fixed;font-size:14px;line-height:1.7}
.diff th{text-align:left;padding:6px 8px;background:#f0f0f1}.diff td{vertical-align:top;padding:4px 8px;word-break:break-all}
.diff col.content{width:50%}.diff .diff-deletedline{background:#fcf0f1}.diff .diff-addedline{background:#edfaef}
.diff .screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}.diff .dashicons{display:none}
.diff del{background:#facfd2;text-decoration:none}.diff ins{background:#b8e6bf;text-decoration:none}.diff col.ltype,.diff td:empty{width:0}</style>';
}

/* Admin screen (hidden from the menu): tools.php?page=codex-connect-compare&draft=ID */
add_action(
	'admin_menu',
	function () {
		add_submenu_page( '', 'リライト案の比較', 'リライト案の比較', 'edit_posts', 'codex-connect-compare', 'codex_connect_render_compare_page' );
	}
);

function codex_connect_render_compare_page() {
	$draft  = get_post( isset( $_GET['draft'] ) ? (int) $_GET['draft'] : 0 );
	$source = codex_connect_draft_source( $draft );
	echo '<div class="wrap"><h1>リライト案の比較</h1>';
	if ( ! $source || ! current_user_can( 'edit_post', $draft->ID ) ) {
		echo '<p>リライト案が見つかりません。</p></div>';
		return;
	}
	printf(
		'<p>「%s」の、いまの記事（左）とリライト案（右）です。<span style="background:#facfd2">赤</span>が消える部分、<span style="background:#b8e6bf">緑</span>が増える部分です。</p><p><a class="button" href="%s" target="_blank">リライト案をプレビュー</a></p>',
		esc_html( $source->post_title ),
		esc_url( get_preview_post_link( $draft ) )
	);
	echo codex_connect_compare_css() . codex_connect_compare_html( $draft, $source ) . '</div>'; // wp_text_diff output is escaped
}

function codex_connect_compare_url( $draft ) {
	$preview = codex_connect_preview_url( $draft );
	return $preview ? add_query_arg( 'codex_compare', '1', $preview ) : '';
}

/* Login-free version, using the preview link's secret. */
add_action(
	'template_redirect',
	function () {
		if ( empty( $_GET['codex_compare'] ) || empty( $_GET['codex_preview'] ) ) {
			return;
		}
		$draft  = get_post( isset( $_GET['p'] ) ? (int) $_GET['p'] : ( isset( $_GET['page_id'] ) ? (int) $_GET['page_id'] : 0 ) );
		$source = codex_connect_draft_source( $draft );
		$saved  = $draft ? get_post_meta( $draft->ID, '_codex_preview_token', true ) : '';
		$token  = sanitize_text_field( wp_unslash( $_GET['codex_preview'] ) );
		if ( ! $source || ! $saved || ! hash_equals( $saved, $token ) || (int) get_post_meta( $draft->ID, '_codex_preview_expires', true ) < time() ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		$preview = remove_query_arg( 'codex_compare' );
		echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		echo '<title>リライト案の比較</title><style>body{font-family:system-ui,sans-serif;max-width:1100px;margin:24px auto;padding:0 16px;color:#1d2327;background:#fff}</style>' . codex_connect_compare_css() . '</head><body>';
		printf(
			'<h1>リライト案の比較</h1><p>「%s」の、いまの記事（左）とリライト案（右）です。<span style="background:#facfd2">赤</span>が消える部分、<span style="background:#b8e6bf">緑</span>が増える部分です。</p><p><a href="%s">リライト案のプレビューを見る</a></p>',
			esc_html( $source->post_title ),
			esc_url( $preview )
		);
		echo codex_connect_compare_html( $draft, $source ) . '</body></html>';
		exit;
	},
	0
);

add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( codex_connect_draft_source( $post ) && 'draft' === $post->post_status && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['codex_compare'] = sprintf( '<a href="%s">変更点を比べる</a>', esc_url( admin_url( 'admin.php?page=codex-connect-compare&draft=' . $post->ID ) ) );
		}
		return $actions;
	},
	10,
	2
);
