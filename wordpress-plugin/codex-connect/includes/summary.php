<?php
/**
 * Dashboard box "Codexの今週の作業": what Codex did in the last 7 days, what is waiting
 * for a decision, and what is scheduled, built from the work history and article memos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_dashboard_setup',
	function () {
		if ( current_user_can( 'edit_posts' ) ) {
			wp_add_dashboard_widget( 'codex_connect_summary', 'Codexの今週の作業', 'codex_connect_render_summary' );
		}
	}
);

function codex_connect_count_status( $status ) {
	$q = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'future', 'private' ),
			'meta_key'       => '_codex_status',
			'meta_value'     => $status,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	return (int) $q->found_posts;
}

function codex_connect_render_summary() {
	$since   = wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	$log     = array_filter( get_option( 'codex_connect_log', array() ), fn( $r ) => $r['time'] >= $since && $r['ok'] );
	$applied = array();
	$drafts  = 0;
	$images  = 0;
	$memos   = 0;
	foreach ( $log as $r ) {
		if ( '下書きを作成' === $r['action'] ) {
			$drafts++;
		} elseif ( '画像をアップロード' === $r['action'] ) {
			$images++;
		} elseif ( 'メモを更新' === $r['action'] && '記事メモを更新' === $r['task'] ) {
			$memos++;
		} elseif ( '記事を更新' === $r['action'] && in_array( $r['task'], array( 'リライト案を反映', '予約反映' ), true ) && 0 !== strpos( $r['title'], '【' ) && ! isset( $applied[ $r['post_id'] ] ) ) {
			$applied[ $r['post_id'] ] = $r; // history is newest first, so this keeps the latest
		}
	}
	$waiting = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'draft',
			's'              => '【リライト案 #',
			'search_columns' => array( 'post_title' ),
			'posts_per_page' => 20,
		)
	);
	$waiting = array_filter( $waiting, 'codex_connect_draft_source' );
	$mode    = codex_connect_mode();
	if ( 'on' !== $mode ) {
		echo '<p style="color:#996800"><strong>Codexからの操作は「' . esc_html( 'off' === $mode ? '一時停止' : '読み取りのみ' ) . '」です。</strong></p>';
	}
	echo '<ul style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:0 0 12px">';
	$stats = array(
		'反映した記事'       => count( $applied ),
		'作ったリライト案'   => $drafts,
		'確認待ちのリライト案' => count( $waiting ),
		'要リライトの記事'   => codex_connect_count_status( 'needs_rewrite' ),
		'鮮度チェックのメモ' => $memos,
		'追加した画像'       => $images,
	);
	foreach ( $stats as $label => $n ) {
		printf( '<li style="margin:0;padding:8px;background:#f6f7f7;border-radius:4px"><span style="font-size:20px;font-weight:600">%d</span><br><span style="color:#50575e">%s</span></li>', (int) $n, esc_html( $label ) );
	}
	echo '</ul>';
	if ( $waiting ) {
		echo '<p><strong>確認待ち</strong>（プレビューを見て、Codexに「反映して」か「破棄して」と伝えてください）</p><ul>';
		foreach ( array_slice( $waiting, 0, 5 ) as $d ) {
			$at = codex_connect_scheduled_at( $d->ID );
			printf(
				'<li><a href="%s" target="_blank">%s</a>%s　<a href="%s">変更点</a></li>',
				esc_url( get_preview_post_link( $d ) ),
				esc_html( preg_replace( '/^【リライト案 #\d+】/u', '', $d->post_title ) ),
				$at ? '<span style="color:#996800">（' . esc_html( $at ) . ' に反映予定）</span>' : '',
				esc_url( admin_url( 'admin.php?page=codex-connect-compare&draft=' . $d->ID ) )
			);
		}
		echo '</ul>';
	}
	if ( $applied ) {
		echo '<p><strong>今週反映した記事</strong></p><ul>';
		foreach ( array_slice( $applied, 0, 5 ) as $r ) {
			printf( '<li><a href="%s">%s</a>（%s）</li>', esc_url( get_permalink( $r['post_id'] ) ), esc_html( $r['title'] ), esc_html( substr( $r['time'], 5, 11 ) ) );
		}
		echo '</ul>';
	}
	printf( '<p><a href="%s">作業履歴をすべて見る</a></p>', esc_url( admin_url( 'tools.php?page=codex-connect&tab=log' ) ) );
}
