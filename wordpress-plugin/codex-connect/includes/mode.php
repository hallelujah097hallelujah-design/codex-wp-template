<?php
/**
 * On/off switch for Codex: normal, read-only, or paused. Applies only to the Codex user,
 * so the site itself and people logged in as themselves are never affected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function codex_connect_modes() {
	return array(
		'on'       => '通常（読み書きできる）',
		'readonly' => '読み取りのみ（記事を読むだけ。下書き作成・反映・画像追加はできない）',
		'off'      => '一時停止（Codexからは何もできない）',
	);
}

function codex_connect_mode() {
	$mode = get_option( 'codex_connect_mode', 'on' );
	return array_key_exists( $mode, codex_connect_modes() ) ? $mode : 'on';
}

function codex_connect_is_codex_user() {
	return is_user_logged_in() && wp_get_current_user()->user_login === CODEX_CONNECT_USER;
}

add_filter(
	'rest_pre_dispatch',
	function ( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result || ! codex_connect_is_codex_user() || '/codex-connect/v1/status' === $request->get_route() ) {
			return $result;
		}
		$mode  = codex_connect_mode();
		// The article check-up only reads articles, so it is allowed in read-only mode
		$write = ! in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true )
			&& 0 !== strpos( $request->get_route(), '/codex-connect/v1/audit' );
		if ( 'off' === $mode ) {
			$error = new WP_Error( 'codex_paused', 'このサイトではCodexからの操作が一時停止されています', array( 'status' => 503 ) );
		} elseif ( 'readonly' === $mode && $write ) {
			$error = new WP_Error( 'codex_readonly', 'このサイトはCodexから読み取りのみに設定されています', array( 'status' => 403 ) );
		} else {
			return $result;
		}
		if ( $write && function_exists( 'codex_connect_log' ) ) {
			codex_connect_log( $request, '止めました（' . ( 'off' === $mode ? '一時停止中' : '読み取りのみ' ) . '）', 0, false );
		}
		return $error;
	},
	10,
	3
);

/* Reminder on every admin screen while Codex is not fully on, so a pause is never forgotten. */
add_action(
	'admin_notices',
	function () {
		$mode = codex_connect_mode();
		if ( 'on' === $mode || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>Codexからの操作は現在「%s」です。<a href="%s">Codex接続の設定で変更</a></p></div>',
			esc_html( 'off' === $mode ? '一時停止' : '読み取りのみ' ),
			esc_url( admin_url( 'tools.php?page=codex-connect' ) )
		);
	}
);
