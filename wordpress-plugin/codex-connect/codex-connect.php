<?php
/**
 * Plugin Name: Codex接続
 * Description: Codex（ChatGPT）から記事のリライトを行うための接続設定をまとめて行います。Codex用ユーザーとアプリケーションパスワードの作成、Codexに貼り付ける値の表示、記事ごとのリライトメモ、ログインなしで見られるプレビューリンクと変更点の比較、予約反映、作業履歴と週のまとめ、一時停止スイッチ、記事の点検、サイト情報の書き出しを行います。
 * Version: 0.4.1
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: codex-wp-template
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/hallelujah097hallelujah-design/codex-wp-template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CODEX_CONNECT_VERSION  = '0.4.1';
const CODEX_CONNECT_FILE     = __FILE__;
const CODEX_CONNECT_USER     = 'codex';
const CODEX_CONNECT_APP_NAME = 'Codex';

/*
 * Some hosts drop the Authorization header before it reaches PHP. Codex also sends the
 * same credentials in X-Codex-Auth, which passes through, so copy it into the basic-auth
 * fields WordPress reads for application passwords. This runs when the plugin file loads,
 * before WordPress determines the current user.
 */
function codex_connect_map_auth_header() {
	if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) || empty( $_SERVER['HTTP_X_CODEX_AUTH'] ) ) {
		return;
	}
	$value = trim( wp_unslash( $_SERVER['HTTP_X_CODEX_AUTH'] ) );
	if ( 0 !== stripos( $value, 'basic ' ) ) {
		return;
	}
	$decoded = base64_decode( substr( $value, 6 ), true );
	if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
		return;
	}
	list( $user, $pass )      = explode( ':', $decoded, 2 );
	$_SERVER['PHP_AUTH_USER'] = $user;
	$_SERVER['PHP_AUTH_PW']   = $pass;
}
codex_connect_map_auth_header();

require_once __DIR__ . '/includes/notes.php';
require_once __DIR__ . '/includes/mode.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/preview.php';
require_once __DIR__ . '/includes/site-info.php';
require_once __DIR__ . '/includes/links.php';
require_once __DIR__ . '/includes/compare.php';
require_once __DIR__ . '/includes/schedule.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/summary.php';
require_once __DIR__ . '/includes/updater.php';

/**
 * Name used in the Codex Personal vault (WP_SITE_<key> / WP_AUTH_<key>), built from the
 * site address so every site gets its own name: https://www.example.co.jp/blog -> EXAMPLE_CO_JP_BLOG.
 * tools/wp.py uses the same rule.
 */
function codex_connect_site_key() {
	$parts = wp_parse_url( home_url() );
	$base  = preg_replace( '/^www\./', '', strtolower( $parts['host'] ) ) . strtolower( untrailingslashit( $parts['path'] ?? '' ) );
	return strtoupper( trim( preg_replace( '/[^a-z0-9]+/', '_', $base ), '_' ) );
}

/*
 * Public status endpoint so connection problems can be diagnosed without credentials.
 * It reports only whether each header arrived, never their values.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'codex-connect/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return array(
						'plugin_version'             => CODEX_CONNECT_VERSION,
						'authorization_header'       => ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
						'codex_header'               => ! empty( $_SERVER['HTTP_X_CODEX_AUTH'] ),
						'application_passwords'      => wp_is_application_passwords_available(),
						'logged_in_as_codex_user'    => codex_connect_is_codex_user(),
						'mode'                       => codex_connect_mode(),
					);
				},
			)
		);
	}
);

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Codex接続', 'Codex接続', 'manage_options', 'codex-connect', 'codex_connect_render_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=codex-connect' ) ) . '">設定</a>' );
		return $links;
	}
);

/**
 * Creates the Codex editor user if needed, replaces its Codex application password,
 * and returns the new plain-text password (shown once).
 */
function codex_connect_issue_credentials() {
	$user = get_user_by( 'login', CODEX_CONNECT_USER );
	if ( ! $user ) {
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$user_id = wp_insert_user(
			array(
				'user_login' => CODEX_CONNECT_USER,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => CODEX_CONNECT_USER . '@' . $host . '.invalid',
				'role'       => 'editor',
				'display_name' => 'Codex',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user = get_user_by( 'id', $user_id );
	} elseif ( ! in_array( 'editor', (array) $user->roles, true ) && ! in_array( 'administrator', (array) $user->roles, true ) ) {
		$user->set_role( 'editor' );
	}

	foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
		if ( CODEX_CONNECT_APP_NAME === $item['name'] ) {
			WP_Application_Passwords::delete_application_password( $user->ID, $item['uuid'] );
		}
	}
	$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => CODEX_CONNECT_APP_NAME ) );
	if ( is_wp_error( $created ) ) {
		return $created;
	}
	return array(
		'user'     => $user->user_login,
		'password' => $created[0],
	);
}

function codex_connect_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array(
		'connect' => '接続',
		'log'     => '作業履歴',
		'audit'   => '記事の点検',
		'info'    => 'サイト情報',
	);
	$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'connect';
	?>
	<div class="wrap codex-connect">
		<h1>Codex接続</h1>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=codex-connect&tab=' . $key ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'log' === $tab ) {
			codex_connect_render_log_tab();
		} elseif ( 'audit' === $tab ) {
			codex_connect_render_audit_tab();
		} elseif ( 'info' === $tab ) {
			codex_connect_render_info_tab();
		} else {
			codex_connect_render_connect_tab();
		}
		?>
	</div>
	<?php
}

function codex_connect_render_connect_tab() {
	$result = null;
	$error  = '';

	if ( isset( $_POST['codex_connect_mode'] ) && check_admin_referer( 'codex_connect_mode' ) ) {
		$mode = sanitize_key( wp_unslash( $_POST['codex_connect_mode'] ) );
		if ( array_key_exists( $mode, codex_connect_modes() ) ) {
			update_option( 'codex_connect_mode', $mode );
			echo '<div class="notice notice-success"><p>Codexからの操作を「' . esc_html( strtok( codex_connect_modes()[ $mode ], '（' ) ) . '」にしました。</p></div>';
		}
	}

	if ( isset( $_POST['codex_connect_issue'] ) && check_admin_referer( 'codex_connect_issue' ) ) {
		if ( ! wp_is_application_passwords_available() ) {
			$error = 'このサイトではアプリケーションパスワードが使えない状態です。サイトがhttpsで表示されているか、セキュリティ系プラグインで無効にされていないか確認してください。';
		} else {
			$issued = codex_connect_issue_credentials();
			if ( is_wp_error( $issued ) ) {
				$error = $issued->get_error_message();
			} else {
				$result = $issued;
			}
		}
	}

	$site_url = untrailingslashit( home_url() );
	$domain   = wp_parse_url( $site_url, PHP_URL_HOST );
	$key      = codex_connect_site_key();
	?>
	<p>Codex（ChatGPT）からこのサイトの記事をリライトするための設定です。ボタンを押すと、Codex専用の「編集者」ユーザー（<code><?php echo esc_html( CODEX_CONNECT_USER ); ?></code>）とアプリケーションパスワードを作り、Codexに貼り付ける値を表示します。</p>
	<p>Codexに登録する名前は、このサイトのアドレスから自動で決まります（<code>WP_SITE_<?php echo esc_html( $key ); ?></code>）。サイトごとに名前が違うので、別のサイトの登録を上書きしてしまうことはありません。</p>

	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<?php if ( $result ) : ?>
		<?php $auth = 'Basic ' . base64_encode( $result['user'] . ':' . $result['password'] ); ?>
		<div class="notice notice-success"><p>接続情報を作成しました。この値は<strong>この画面でしか表示されません</strong>。CodexのPersonal vaultに貼り付けてから画面を閉じてください。同じ名前がすでにある場合は、値を新しいものに置き換えてください。</p></div>
		<h2>1. 環境変数</h2>
		<table class="form-table" role="presentation">
			<tr><th>名前</th><td><?php codex_connect_copy_field( 'cc-site-k', 'WP_SITE_' . $key ); ?></td></tr>
			<tr><th>値</th><td><?php codex_connect_copy_field( 'cc-site-v', $site_url ); ?></td></tr>
		</table>
		<h2>2. ネットワークシークレット</h2>
		<table class="form-table" role="presentation">
			<tr><th>名前</th><td><?php codex_connect_copy_field( 'cc-auth-k', 'WP_AUTH_' . $key ); ?></td></tr>
			<tr><th>値</th><td><?php codex_connect_copy_field( 'cc-auth-v', $auth ); ?></td></tr>
			<tr><th>許可するドメイン</th><td><?php codex_connect_copy_field( 'cc-dom', $domain ); ?></td></tr>
		</table>
		<script>
		document.querySelectorAll('.codex-connect [data-copy]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById(btn.getAttribute('data-copy'));
				input.select();
				var done = function () { btn.textContent = 'コピーしました'; setTimeout(function () { btn.textContent = 'コピー'; }, 1500); };
				if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
				else { document.execCommand('copy'); done(); }
			});
		});
		</script>
	<?php endif; ?>

	<h2><?php echo $result ? 'もう一度作り直す' : '接続情報を作成する'; ?></h2>
	<form method="post">
		<?php wp_nonce_field( 'codex_connect_issue' ); ?>
		<?php submit_button( $result ? '作り直す（前のパスワードは使えなくなります）' : '接続情報を作成', 'primary', 'codex_connect_issue' ); ?>
	</form>

	<h2>Codexからの操作</h2>
	<form method="post">
		<?php wp_nonce_field( 'codex_connect_mode' ); ?>
		<fieldset>
			<?php foreach ( codex_connect_modes() as $value => $label ) : ?>
				<label style="display:block;margin:.4em 0"><input type="radio" name="codex_connect_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( codex_connect_mode(), $value ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description">パスワードはそのままで、Codexだけを止められます。サイトの表示や、あなたが管理画面で行う作業には影響しません。</p>
		<?php submit_button( '保存', 'secondary', 'codex_connect_mode_save' ); ?>
	</form>
	<?php
}

function codex_connect_copy_field( $id, $value ) {
	printf(
		'<input type="text" readonly id="%1$s" value="%2$s" class="regular-text code" style="width:min(100%%,36em)" onclick="this.select()"> <button type="button" class="button" data-copy="%1$s">コピー</button>',
		esc_attr( $id ),
		esc_attr( $value )
	);
}
