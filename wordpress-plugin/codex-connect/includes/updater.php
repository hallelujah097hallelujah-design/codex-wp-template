<?php
/**
 * Updates from the template repository: WordPress shows "update available" for this plugin
 * like any other, and the site owner can update with one click (or turn on auto-update).
 * The repository must be public for sites to read the update file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CODEX_CONNECT_UPDATE_URL' ) ) {
	define( 'CODEX_CONNECT_UPDATE_URL', 'https://raw.githubusercontent.com/hallelujah097hallelujah-design/codex-wp-template/main/wordpress-plugin/update.json' );
}

/** The update file, fetched at most every 6 hours. Returns null when it cannot be read. */
function codex_connect_remote_release( $force = false ) {
	$cached = get_site_transient( 'codex_connect_release' );
	if ( ! $force && false !== $cached ) {
		return $cached ? $cached : null;
	}
	$release  = null;
	$response = wp_safe_remote_get( CODEX_CONNECT_UPDATE_URL, array( 'timeout' => 8 ) );
	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['package'] ) && 0 === strpos( $data['package'], 'https://' ) ) {
			$release = $data;
		}
	}
	// Remember failures too (as ''), so an unreachable file does not slow every admin page
	set_site_transient( 'codex_connect_release', $release ? $release : '', $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release;
}

add_filter(
	'pre_set_site_transient_update_plugins',
	function ( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = codex_connect_remote_release();
		$file    = plugin_basename( CODEX_CONNECT_FILE );
		$item    = (object) array(
			'id'           => 'codex-connect',
			'slug'         => 'codex-connect',
			'plugin'       => $file,
			'new_version'  => $release ? $release['version'] : CODEX_CONNECT_VERSION,
			'url'          => 'https://github.com/hallelujah097hallelujah-design/codex-wp-template',
			'package'      => $release ? $release['package'] : '',
			'requires'     => $release['requires'] ?? '',
			'tested'       => $release['tested'] ?? '',
			'requires_php' => $release['requires_php'] ?? '',
		);
		if ( $release && version_compare( $release['version'], CODEX_CONNECT_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			unset( $transient->response[ $file ] );
			$transient->no_update[ $file ] = $item; // also makes the "auto-update" toggle available
		}
		return $transient;
	}
);

/* "View details" popup in the plugin list. */
add_filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'codex-connect' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = codex_connect_remote_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Codex接続',
			'slug'          => 'codex-connect',
			'version'       => $release['version'],
			'requires'      => $release['requires'] ?? '',
			'tested'        => $release['tested'] ?? '',
			'download_link' => $release['package'],
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( $release['changelog'] ?? '' ) ) ),
		);
	},
	10,
	3
);

/* Check again right after an update so the notice disappears. */
add_action(
	'upgrader_process_complete',
	function () {
		delete_site_transient( 'codex_connect_release' );
	}
);
