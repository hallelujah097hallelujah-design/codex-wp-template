<?php
/**
 * Scheduled apply: "明日の朝9時に反映して". At the chosen time the rewrite draft replaces the
 * original article the same way `wp.py apply` does: the old text is kept as a backup draft
 * in the trash (30 days) and the article memo is marked done.
 * Runs on WP-Cron, which fires on the next page visit after the time, so a quiet site may
 * apply a few minutes late.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CODEX_CONNECT_SCHEDULE_HOOK = 'codex_connect_scheduled_apply';

/** Same steps as `wp.py apply`. Returns the updated post ID or WP_Error. */
function codex_connect_apply_draft( $draft_id, $keep_title = false ) {
	$draft  = get_post( $draft_id );
	$source = codex_connect_draft_source( $draft );
	if ( ! $source || 'draft' !== $draft->post_status ) {
		return new WP_Error( 'codex_not_draft', 'リライト案の下書きが見つかりません' );
	}
	// Content was saved by the Codex editor; do not let a user-less cron run filter it again
	$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	if ( $kses ) {
		kses_remove_filters();
	}
	$update = array(
		'ID'           => $source->ID,
		'post_content' => $draft->post_content,
	);
	if ( ! $keep_title ) {
		$update['post_title'] = preg_replace( '/^【リライト案 #\d+】/u', '', $draft->post_title );
	}
	$backup = wp_update_post(
		wp_slash(
			array(
				'ID'           => $draft->ID,
				'post_title'   => '【リライト前のバックアップ #' . $source->ID . '】' . $source->post_title,
				'post_content' => $source->post_content,
			)
		),
		true
	);
	$result = is_wp_error( $backup ) ? $backup : wp_update_post( wp_slash( $update ), true );
	if ( $kses ) {
		kses_init_filters();
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	wp_trash_post( $draft->ID );
	update_post_meta( $source->ID, '_codex_status', 'done' );
	update_post_meta( $source->ID, '_codex_checked_at', wp_date( 'Y-m-d' ) );
	return $source->ID;
}

add_action(
	CODEX_CONNECT_SCHEDULE_HOOK,
	function ( $draft_id ) {
		$draft = get_post( $draft_id );
		$keep  = (bool) get_post_meta( $draft_id, '_codex_apply_keep_title', true );
		delete_post_meta( $draft_id, '_codex_apply_at' );
		delete_post_meta( $draft_id, '_codex_apply_keep_title' );
		if ( 'off' === codex_connect_mode() || 'readonly' === codex_connect_mode() ) {
			codex_connect_log_entry( '予約反映', '記事を更新', $draft_id, '止めました（Codexが一時停止または読み取りのみのため）', false );
			return;
		}
		$source = codex_connect_draft_source( $draft );
		$result = codex_connect_apply_draft( $draft_id, $keep );
		codex_connect_log_entry(
			'予約反映',
			'記事を更新',
			$source ? $source->ID : $draft_id,
			is_wp_error( $result ) ? '失敗（' . $result->get_error_message() . '）' : '成功',
			! is_wp_error( $result )
		);
	}
);

function codex_connect_scheduled_at( $draft_id ) {
	$ts = wp_next_scheduled( CODEX_CONNECT_SCHEDULE_HOOK, array( (int) $draft_id ) );
	return $ts ? wp_date( 'Y-m-d H:i', $ts ) : '';
}

add_action(
	'rest_api_init',
	function () {
		$can = function ( WP_REST_Request $request ) {
			$draft  = get_post( (int) $request['id'] );
			$source = codex_connect_draft_source( $draft );
			return $source && current_user_can( 'edit_post', $draft->ID ) && current_user_can( 'edit_post', $source->ID );
		};
		register_rest_route(
			'codex-connect/v1',
			'/schedule/(?P<id>\\d+)',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'args'                => array(
						'at'         => array( 'type' => 'string', 'required' => true, 'description' => 'サイトの時刻で Y-m-d H:i' ),
						'keep_title' => array( 'type' => 'boolean', 'default' => false ),
					),
					'callback'            => function ( WP_REST_Request $request ) {
						$id   = (int) $request['id'];
						$when = date_create_immutable_from_format( 'Y-m-d H:i', trim( $request['at'] ), wp_timezone() );
						if ( ! $when ) {
							return new WP_Error( 'codex_bad_time', '日時は「2026-10-21 09:00」の形で指定してください', array( 'status' => 400 ) );
						}
						if ( $when->getTimestamp() < time() + 60 ) {
							return new WP_Error( 'codex_past_time', 'すでに過ぎた日時です', array( 'status' => 400 ) );
						}
						wp_clear_scheduled_hook( CODEX_CONNECT_SCHEDULE_HOOK, array( $id ) );
						wp_schedule_single_event( $when->getTimestamp(), CODEX_CONNECT_SCHEDULE_HOOK, array( $id ) );
						update_post_meta( $id, '_codex_apply_at', $when->format( 'Y-m-d H:i' ) );
						update_post_meta( $id, '_codex_apply_keep_title', $request['keep_title'] ? '1' : '' );
						return array( 'draft_id' => $id, 'apply_at' => codex_connect_scheduled_at( $id ), 'timezone' => wp_timezone_string() );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $can,
					'callback'            => function ( WP_REST_Request $request ) {
						$id = (int) $request['id'];
						wp_clear_scheduled_hook( CODEX_CONNECT_SCHEDULE_HOOK, array( $id ) );
						delete_post_meta( $id, '_codex_apply_at' );
						return array( 'draft_id' => $id, 'apply_at' => null );
					},
				),
			)
		);
	}
);

/* Trashing or deleting the draft by hand cancels its schedule. */
add_action(
	'wp_trash_post',
	function ( $post_id ) {
		wp_clear_scheduled_hook( CODEX_CONNECT_SCHEDULE_HOOK, array( (int) $post_id ) );
	}
);

/* Show the time in the post list, next to the draft's title. */
add_filter(
	'display_post_states',
	function ( $states, $post ) {
		$at = 'draft' === $post->post_status ? codex_connect_scheduled_at( $post->ID ) : '';
		if ( $at ) {
			$states['codex_apply'] = '予約反映 ' . $at;
		}
		return $states;
	},
	10,
	2
);
