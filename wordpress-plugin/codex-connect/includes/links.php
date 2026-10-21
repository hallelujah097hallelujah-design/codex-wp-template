<?php
/**
 * Every published article in one light list (title, URL, categories, tags) so Codex can
 * pick internal links that really exist instead of guessing URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'codex-connect/v1',
			'/links',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 500 ),
				),
				'callback'            => function ( WP_REST_Request $request ) {
					$query = new WP_Query(
						array(
							'post_type'      => array( 'post', 'page' ),
							'post_status'    => 'publish',
							'posts_per_page' => min( 1000, max( 1, (int) $request['per_page'] ) ),
							'paged'          => max( 1, (int) $request['page'] ),
							'orderby'        => 'date',
							'order'          => 'DESC',
							'no_found_rows'  => false,
						)
					);
					$items = array();
					foreach ( $query->posts as $post ) {
						$item = array(
							'id'    => $post->ID,
							'type'  => $post->post_type,
							'title' => get_the_title( $post ),
							'url'   => get_permalink( $post ),
							'date'  => get_the_date( 'Y-m-d', $post ),
						);
						if ( 'post' === $post->post_type ) {
							$item['categories'] = wp_list_pluck( get_the_category( $post->ID ), 'name' );
							$item['tags']       = wp_list_pluck( (array) get_the_tags( $post->ID ), 'name' );
						}
						$items[] = $item;
					}
					return array(
						'total' => (int) $query->found_posts,
						'pages' => (int) $query->max_num_pages,
						'page'  => max( 1, (int) $request['page'] ),
						'items' => $items,
					);
				},
			)
		);
	}
);
