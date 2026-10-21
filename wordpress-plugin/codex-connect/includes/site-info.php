<?php
/**
 * Site details Codex needs before writing for a site: theme, active plugins, categories,
 * tags, post counts. Available over REST and as a download under Tools → Codex接続.
 * Holds no passwords, emails, or user data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function codex_connect_site_info() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$theme   = wp_get_theme();
	$plugins = array();
	$all     = get_plugins();
	foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
		if ( isset( $all[ $file ] ) ) {
			$plugins[] = array( 'name' => $all[ $file ]['Name'], 'version' => $all[ $file ]['Version'] );
		}
	}
	$terms = function ( $taxonomy, $args ) {
		$list = array();
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) + $args ) as $t ) {
			$list[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count, 'parent' => $t->parent );
		}
		return $list;
	};
	$posts = wp_count_posts( 'post' );
	$pages = wp_count_posts( 'page' );
	return array(
		'name'          => get_bloginfo( 'name' ),
		'description'   => get_bloginfo( 'description' ),
		'url'           => home_url(),
		'language'      => get_locale(),
		'timezone'      => wp_timezone_string(),
		'wordpress'     => get_bloginfo( 'version' ),
		'php'           => PHP_VERSION,
		'theme'         => array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
		),
		'plugins'       => $plugins,
		'permalinks'    => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : '（基本）',
		'counts'        => array(
			'posts_published' => (int) $posts->publish,
			'posts_draft'     => (int) $posts->draft,
			'pages_published' => (int) $pages->publish,
		),
		'categories'    => $terms( 'category', array( 'number' => 300 ) ),
		'tags'          => $terms( 'post_tag', array( 'number' => 100, 'orderby' => 'count', 'order' => 'DESC' ) ),
		'codex_connect' => array( 'version' => CODEX_CONNECT_VERSION, 'mode' => codex_connect_mode() ),
		'exported_at'   => wp_date( 'Y-m-d H:i' ),
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'codex-connect/v1',
			'/site-info',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => 'codex_connect_site_info',
			)
		);
	}
);

add_action(
	'admin_post_codex_connect_export',
	function () {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'codex_connect_export' ) ) {
			wp_die( '権限がありません' );
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="site-info-' . sanitize_file_name( $host ) . '.json"' );
		echo wp_json_encode( codex_connect_site_info(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}
);

function codex_connect_render_info_tab() {
	$info = codex_connect_site_info();
	echo '<p>Codexがこのサイト向けに書くときの参考情報です。パスワードやメールアドレスなどは含みません。Codexは自分で読み取れるので、通常はダウンロード不要です。</p>';
	echo '<table class="form-table" role="presentation">';
	$rows = array(
		'サイト名'        => $info['name'],
		'テーマ'          => $info['theme']['name'] . ' ' . $info['theme']['version'] . ( $info['theme']['parent'] ? '（親テーマ：' . $info['theme']['parent'] . '）' : '' ),
		'WordPress / PHP' => $info['wordpress'] . ' / ' . $info['php'],
		'公開記事'        => $info['counts']['posts_published'] . '件（下書き ' . $info['counts']['posts_draft'] . '件）',
		'カテゴリー'      => count( $info['categories'] ) . '個',
		'有効なプラグイン' => implode( '、', wp_list_pluck( $info['plugins'], 'name' ) ),
	);
	foreach ( $rows as $label => $value ) {
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}
	echo '</table>';
	printf(
		'<p><a class="button button-primary" href="%s">サイト情報をダウンロード（JSON）</a></p>',
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=codex_connect_export' ), 'codex_connect_export' ) )
	);
}
