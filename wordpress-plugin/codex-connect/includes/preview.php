<?php
/**
 * Login-free preview links for rewrite drafts: anyone with the link can view the draft
 * until it expires, is published, or is trashed. Search engines are told not to index it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CODEX_CONNECT_PREVIEW_DAYS = 7;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'codex-connect/v1',
			'/preview/(?P<id>\\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
				'callback'            => function ( WP_REST_Request $request ) {
					$post = get_post( (int) $request['id'] );
					if ( ! $post || in_array( $post->post_status, array( 'publish', 'trash' ), true ) ) {
						return new WP_Error( 'codex_preview_unavailable', '公開中またはゴミ箱の記事にはプレビューリンクを作れません', array( 'status' => 400 ) );
					}
					$token   = wp_generate_password( 32, false, false );
					$expires = time() + CODEX_CONNECT_PREVIEW_DAYS * DAY_IN_SECONDS;
					update_post_meta( $post->ID, '_codex_preview_token', $token );
					update_post_meta( $post->ID, '_codex_preview_expires', $expires );
					return array(
						'id'      => $post->ID,
						'url'     => codex_connect_preview_url( $post ),
						'compare' => codex_connect_draft_source( $post ) ? codex_connect_compare_url( $post ) : '',
						'expires' => wp_date( 'Y-m-d H:i', $expires ),
					);
				},
			)
		);
	}
);

function codex_connect_preview_url( $post ) {
	$token = get_post_meta( $post->ID, '_codex_preview_token', true );
	if ( ! $token || (int) get_post_meta( $post->ID, '_codex_preview_expires', true ) < time() ) {
		return '';
	}
	$query = 'page' === $post->post_type ? array( 'page_id' => $post->ID ) : array( 'p' => $post->ID );
	return add_query_arg( $query + array( 'preview' => 'true', 'codex_preview' => $token ), home_url( '/' ) );
}

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_preview() || empty( $_GET['codex_preview'] ) ) {
			return;
		}
		add_filter( 'posts_results', 'codex_connect_preview_posts', 10, 2 );
	}
);

/* WordPress hides drafts from visitors after this filter, so mark the matching draft as viewable for this request only. */
function codex_connect_preview_posts( $posts, $query ) {
	remove_filter( 'posts_results', 'codex_connect_preview_posts', 10 );
	if ( 1 !== count( $posts ) ) {
		return $posts;
	}
	$post  = $posts[0];
	$token = sanitize_text_field( wp_unslash( $_GET['codex_preview'] ) );
	$saved = get_post_meta( $post->ID, '_codex_preview_token', true );
	if ( ! $saved || ! hash_equals( $saved, $token ) || (int) get_post_meta( $post->ID, '_codex_preview_expires', true ) < time() ) {
		return $posts;
	}
	$post->post_status = 'publish'; // in memory only; nothing is saved
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	return $posts;
}

/* The link stops working once the draft is published or trashed (e.g. after "反映して"). */
add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( in_array( $new_status, array( 'publish', 'trash' ), true ) ) {
			delete_post_meta( $post->ID, '_codex_preview_token' );
			delete_post_meta( $post->ID, '_codex_preview_expires' );
		}
	},
	10,
	3
);

/* A "ログインなしで見る" link next to drafts that have one, so the owner can copy it. */
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		$url = codex_connect_preview_url( $post );
		if ( $url && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['codex_preview'] = sprintf( '<a href="%s" target="_blank" rel="noopener">ログインなしで見る</a>', esc_url( $url ) );
		}
		return $actions;
	},
	10,
	2
);
