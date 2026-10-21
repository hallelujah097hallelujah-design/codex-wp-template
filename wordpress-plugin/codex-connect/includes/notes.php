<?php
/**
 * Per-article notes shared between Codex tasks: rewrite status, what to fix, sources, last check.
 * Stored as protected post meta, readable and writable over REST by users who can edit the post,
 * and shown in the admin post list and edit screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CODEX_NOTE_FIELDS = array(
	'_codex_status'     => 'リライト状態',
	'_codex_note'       => 'メモ',
	'_codex_sources'    => '根拠（URL）',
	'_codex_checked_at' => '最終チェック日',
);

function codex_connect_statuses() {
	return array(
		''              => '—',
		'needs_rewrite' => '要リライト',
		'in_progress'   => '対応中',
		'done'          => '完了',
		'ok'            => '問題なし',
	);
}

add_action(
	'init',
	function () {
		foreach ( array_keys( CODEX_NOTE_FIELDS ) as $key ) {
			register_post_meta(
				'post',
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'default'       => '',
					'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}
);

/*
 * Work queue for Codex: posts filtered by rewrite status, with their notes.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'codex-connect/v1',
			'/queue',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'status' => array( 'type' => 'string', 'default' => 'needs_rewrite' ),
					'limit'  => array( 'type' => 'integer', 'default' => 50 ),
				),
				'callback'            => function ( WP_REST_Request $request ) {
					$query = new WP_Query(
						array(
							'post_type'      => 'post',
							'post_status'    => array( 'publish', 'future', 'private' ),
							'posts_per_page' => min( 200, max( 1, (int) $request['limit'] ) ),
							'orderby'        => 'modified',
							'order'          => 'ASC',
							'meta_key'       => '_codex_status',
							'meta_value'     => sanitize_key( $request['status'] ),
						)
					);
					$items = array();
					foreach ( $query->posts as $post ) {
						$item = array(
							'id'       => $post->ID,
							'title'    => get_the_title( $post ),
							'link'     => get_permalink( $post ),
							'modified' => $post->post_modified,
						);
						foreach ( array_keys( CODEX_NOTE_FIELDS ) as $key ) {
							$item[ ltrim( str_replace( '_codex_', '', $key ), '_' ) ] = get_post_meta( $post->ID, $key, true );
						}
						$items[] = $item;
					}
					return array(
						'status' => $request['status'],
						'total'  => (int) $query->found_posts,
						'posts'  => $items,
					);
				},
			)
		);
	}
);

/*
 * Update an article's memo without touching the post itself, so its "last modified"
 * date (shown on many themes) does not change just because a note was recorded.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'codex-connect/v1',
			'/note/(?P<id>\\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
				'callback'            => function ( WP_REST_Request $request ) {
					$post = get_post( (int) $request['id'] );
					if ( ! $post || 'post' !== $post->post_type ) {
						return new WP_Error( 'codex_not_found', '記事が見つかりません', array( 'status' => 404 ) );
					}
					$fields = array(
						'status'     => '_codex_status',
						'note'       => '_codex_note',
						'sources'    => '_codex_sources',
						'checked_at' => '_codex_checked_at',
					);
					foreach ( $fields as $param => $key ) {
						$value = $request->get_param( $param );
						if ( null === $value ) {
							continue;
						}
						if ( 'status' === $param && ! array_key_exists( $value, codex_connect_statuses() ) ) {
							return new WP_Error( 'codex_bad_status', '状態の値が正しくありません', array( 'status' => 400 ) );
						}
						update_post_meta( $post->ID, $key, sanitize_textarea_field( $value ) );
					}
					$result = array( 'id' => $post->ID );
					foreach ( $fields as $param => $key ) {
						$result[ $param ] = get_post_meta( $post->ID, $key, true );
					}
					return $result;
				},
			)
		);
	}
);

/*
 * Admin post list: a "Codex" column and a status filter.
 */
add_filter(
	'manage_post_posts_columns',
	function ( $columns ) {
		$columns['codex'] = 'Codex';
		return $columns;
	}
);

add_action(
	'manage_post_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'codex' !== $column ) {
			return;
		}
		$statuses = codex_connect_statuses();
		$status   = get_post_meta( $post_id, '_codex_status', true );
		$note     = get_post_meta( $post_id, '_codex_note', true );
		$checked  = get_post_meta( $post_id, '_codex_checked_at', true );
		if ( ! $status && ! $note ) {
			echo '—';
			return;
		}
		$colors = array( 'needs_rewrite' => '#b32d2e', 'in_progress' => '#996800', 'done' => '#00813a', 'ok' => '#50575e' );
		printf(
			'<strong style="color:%s">%s</strong>',
			esc_attr( $colors[ $status ] ?? '#50575e' ),
			esc_html( $statuses[ $status ] ?? $status )
		);
		if ( $checked ) {
			echo '<br><small>' . esc_html( $checked ) . '</small>';
		}
		if ( $note ) {
			echo '<br><span title="' . esc_attr( $note ) . '">' . esc_html( wp_trim_words( $note, 30, '…' ) ) . '</span>';
		}
	},
	10,
	2
);

add_action(
	'restrict_manage_posts',
	function ( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['codex_status'] ) ? sanitize_key( wp_unslash( $_GET['codex_status'] ) ) : '';
		echo '<select name="codex_status"><option value="">Codex：すべて</option>';
		foreach ( codex_connect_statuses() as $value => $label ) {
			if ( '' === $value ) {
				continue;
			}
			printf( '<option value="%s"%s>Codex：%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
);

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || empty( $_GET['codex_status'] ) ) {
			return;
		}
		$query->set( 'meta_key', '_codex_status' );
		$query->set( 'meta_value', sanitize_key( wp_unslash( $_GET['codex_status'] ) ) );
	}
);

/*
 * Edit screen: a box to read and change the note by hand.
 */
add_action(
	'add_meta_boxes_post',
	function () {
		add_meta_box( 'codex-connect-note', 'Codexメモ', 'codex_connect_render_note_box', 'post', 'side' );
	}
);

function codex_connect_render_note_box( $post ) {
	wp_nonce_field( 'codex_connect_note', 'codex_connect_note_nonce' );
	$status = get_post_meta( $post->ID, '_codex_status', true );
	?>
	<p>
		<label for="codex-status"><strong>リライト状態</strong></label><br>
		<select id="codex-status" name="codex_status_field" style="width:100%">
			<?php foreach ( codex_connect_statuses() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="codex-note"><strong>メモ</strong></label>
		<textarea id="codex-note" name="codex_note_field" rows="5" style="width:100%"><?php echo esc_textarea( get_post_meta( $post->ID, '_codex_note', true ) ); ?></textarea>
	</p>
	<p>
		<label for="codex-sources"><strong>根拠（URL、1行に1つ）</strong></label>
		<textarea id="codex-sources" name="codex_sources_field" rows="3" style="width:100%"><?php echo esc_textarea( get_post_meta( $post->ID, '_codex_sources', true ) ); ?></textarea>
	</p>
	<?php
	$checked = get_post_meta( $post->ID, '_codex_checked_at', true );
	if ( $checked ) {
		echo '<p>最終チェック日：' . esc_html( $checked ) . '</p>';
	}
}

add_action(
	'save_post_post',
	function ( $post_id ) {
		if ( ! isset( $_POST['codex_connect_note_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['codex_connect_note_nonce'] ), 'codex_connect_note' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = isset( $_POST['codex_status_field'] ) ? sanitize_key( wp_unslash( $_POST['codex_status_field'] ) ) : '';
		if ( array_key_exists( $status, codex_connect_statuses() ) ) {
			update_post_meta( $post_id, '_codex_status', $status );
		}
		if ( isset( $_POST['codex_note_field'] ) ) {
			update_post_meta( $post_id, '_codex_note', sanitize_textarea_field( wp_unslash( $_POST['codex_note_field'] ) ) );
		}
		if ( isset( $_POST['codex_sources_field'] ) ) {
			update_post_meta( $post_id, '_codex_sources', sanitize_textarea_field( wp_unslash( $_POST['codex_sources_field'] ) ) );
		}
	}
);
