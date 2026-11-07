<?php
/**
 * WordPress REST API extensions.
 *
 * @package Zero_Admin
 */

add_filter(
	'rest_post_type_collection_params',
	function ( $params ) {
		$params['default_view']    = array(
			'description' => 'Default DataViews config for the post type',
			'type'        => 'object',
			'default'     => (object) array(),
		);
		$params['quick_edit_form'] = array(
			'description' => 'Default DataForm config for the quick edit form for the post type',
			'type'        => 'object',
			'default'     => (object) array(),
		);
		return $params;
	}
);

add_filter(
	'rest_prepare_post_type',
	function ( $response, $post_type ) {
		$response->data['default_view']    = $post_type->default_view;
		$response->data['default_layouts'] = $post_type->default_layouts;
		$response->data['quick_edit_form'] = $post_type->quick_edit_form;
		if ( isset( $post_type->views ) ) {
			$response->data['views'] = $post_type->views;
		}
		if ( isset( $post_type->import_handler ) ) {
			$response->data['import_handler'] = $post_type->import_handler;
		}
		return $response;
	},
	10,
	2
);

/**
 * Allow auto-draft status in WordPress REST API for all post types
 */
function zero_admin_allow_auto_draft_in_rest_api() {
	$auto_draft_filter = function ( $schema ) {
		if ( isset( $schema['properties']['status']['enum'] ) ) {
			$schema['properties']['status']['enum'][] = 'auto-draft';
		}
		return $schema;
	};

	// Get post types that should support auto-draft.
	$post_types = get_post_types(
		array(
			'show_in_rest' => true,
			'show_ui'      => true,
		),
		'objects'
	);

	foreach ( $post_types as $post_type ) {
		$supports_editor   = post_type_supports( $post_type->name, 'editor' );
		$supports_title    = post_type_supports( $post_type->name, 'title' );
		$is_wp_core_system = strpos( $post_type->name, 'wp_' ) === 0;
		$is_attachment     = 'attachment' === $post_type->name;

		// Only add auto-draft to content post types:
		// - Must support either editor or title (content editing capabilities).
		// - Exclude WordPress core system types (wp_block, wp_navigation, etc.).
		// - Exclude attachments (media files don't need auto-draft status).
		$should_support_auto_draft = ( $supports_editor || $supports_title ) &&
										! $is_wp_core_system &&
										! $is_attachment;

		if ( $should_support_auto_draft ) {
			add_filter( "rest_{$post_type->name}_item_schema", $auto_draft_filter );
		}
	}
}

add_action( 'rest_api_init', 'zero_admin_allow_auto_draft_in_rest_api' );

/**
 * Add comment_status filtering support to REST API for all post types that support comments
 */
function zero_admin_add_comment_status_filter_to_rest_api() {
	$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );

	foreach ( $post_types as $post_type ) {
		if ( post_type_supports( $post_type->name, 'comments' ) ) {
			add_filter(
				"rest_{$post_type->name}_query",
				function ( $args, $request ) {
					if ( ! empty( $request['comment_status'] ) ) {
						$args['comment_status'] = sanitize_text_field( $request['comment_status'] );
					}

					if ( ! empty( $request['comment_status_exclude'] ) ) {
						$excluded_status        = sanitize_text_field( $request['comment_status_exclude'] );
						$args['comment_status'] = 'open' === $excluded_status ? 'closed' : 'open';
					}

					return $args;
				},
				10,
				2
			);
		}
	}
}

add_action( 'rest_api_init', 'zero_admin_add_comment_status_filter_to_rest_api' );

/**
 * Add comment_status parameters to REST API collection params for all post types that support comments
 */
function zero_admin_add_comment_status_collection_params() {
	$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );

	foreach ( $post_types as $post_type ) {
		if ( post_type_supports( $post_type->name, 'comments' ) ) {
			add_filter(
				"rest_{$post_type->name}_collection_params",
				function ( $params ) {
					$params['comment_status'] = array(
						'description' => 'Limit result set to posts with a specific comment status.',
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
					);

					$params['comment_status_exclude'] = array(
						'description' => 'Exclude posts with a specific comment status.',
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
					);

					return $params;
				}
			);
		}
	}
}

add_action( 'rest_api_init', 'zero_admin_add_comment_status_collection_params' );

/**
 * Add post_count field to user REST API
 */
function zero_admin_register_user_post_count_field() {
	register_rest_field(
		'user',
		'post_count',
		array(
			'get_callback' => function ( $user ) {
				return count_user_posts( $user['id'] );
			},
			'schema'       => array(
				'description' => 'Number of posts by the user.',
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'zero_admin_register_user_post_count_field' );

/**
 * Add comment count field to REST API response for posts. Don't compute it, as
 * it will be exposed by `zero_admin_prepare_comment_count_rest_field`.
 */
function zero_admin_register_comment_count_rest_field() {
	register_rest_field(
		'post',
		'comment_count',
		array(
			'schema' => array(
				'description' => __( 'Number of approved comments for this post.', 'zero-admin' ),
				'type'        => 'integer',
			),
		)
	);
}

/**
 * Expose `WP_Post`'s `comment_count` property in REST API response for posts.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Post          $post     Post object.
 */
function zero_admin_prepare_comment_count_rest_field( $response, $post ) {
	$response->data['comment_count'] = (int) $post->comment_count;
	return $response;
}

add_action( 'rest_api_init', 'zero_admin_register_comment_count_rest_field' );
add_filter( 'rest_prepare_post', 'zero_admin_prepare_comment_count_rest_field', 10, 2 );

/**
 * Overrides the REST controller for the `attachments` post type.
 *
 * @param array $args Array of arguments for registering a post type.
 *                          See the register_post_type() function for accepted arguments.
 *
 * @return array Array of arguments for registering a post type.
 */
function zero_admin_override_attachments_endpoint( array $args ): array {
	$args['rest_controller_class']   = 'Zero_Admin_REST_Attachments_Controller';
	$args['late_route_registration'] = true;

	return $args;
}
add_filter( 'register_attachment_post_type_args', 'zero_admin_override_attachments_endpoint' );

/**
 * Get update information for a specific theme.
 *
 * @param array $theme The theme data from the REST API response.
 * @return array|null Theme update information.
 */
function zero_admin_get_theme_update_info( $theme ) {
	if ( is_multisite() || ! current_user_can( 'update_themes' ) ) {
		return null;
	}

	$stylesheet = $theme['stylesheet'] ?? '';

	if ( empty( $stylesheet ) ) {
		return null;
	}

	// Get theme updates from transient.
	$themes_update = get_site_transient( 'update_themes' );
	if ( ! $themes_update || ! $themes_update->response ) {
		wp_update_themes();
		$themes_update = get_site_transient( 'update_themes' );
	}

	// Check if there's an update available for this specific theme.
	if ( ! $themes_update || ! isset( $themes_update->response[ $stylesheet ] ) ) {
		return array(
			'has_update'      => false,
			'current_version' => $theme['version'] ?? '',
			'new_version'     => null,
			'update_url'      => null,
			'package'         => null,
		);
	}

	$update = $themes_update->response[ $stylesheet ];

	return array(
		'has_update'      => true,
		'current_version' => $theme['version'] ?? '',
		'new_version'     => $update['new_version'] ?? '',
		'update_url'      => $update['url'] ?? '',
		'package'         => $update['package'] ?? '',
	);
}

/**
 * Register the `update_info` field in the themes REST API response.
 *
 * This field provides information about available updates for themes.
 */
function zero_admin_register_theme_update_info_field() {
	register_rest_field(
		'theme',
		'update_info',
		array(
			'get_callback' => 'zero_admin_get_theme_update_info',
			'schema'       => array(
				'description' => 'Theme update information',
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'properties'  => array(
					'has_update'      => array(
						'type'        => 'boolean',
						'description' => 'Whether an update is available for this theme',
					),
					'new_version'     => array(
						'type'        => 'string',
						'description' => 'New version available',
					),
					'current_version' => array(
						'type'        => 'string',
						'description' => 'Current installed version',
					),
					'update_url'      => array(
						'type'        => 'string',
						'description' => 'URL for theme update information',
					),
					'package'         => array(
						'type'        => 'string',
						'description' => 'Download package URL',
					),
				),
			),
		)
	);
}

add_action( 'rest_api_init', 'zero_admin_register_theme_update_info_field' );

/**
 * Get theme auto-updates data.
 *
 * @param array $theme The theme data from the REST API response.
 * @return array Theme auto-updates data.
 */
function zero_admin_get_theme_auto_updates_data( $theme ) {
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';

	$themes     = wp_prepare_themes_for_js();
	$theme_data = null;
	foreach ( $themes as $prepared_theme ) {
		if ( $prepared_theme['id'] === $theme['stylesheet'] ) {
			$theme_data = $prepared_theme;
			break;
		}
	}

	if ( $theme_data ) {
		return $theme_data['autoupdate'];
	} else {
		return array(
			'enabled'   => false,
			'supported' => false,
			'forced'    => false,
		);
	}
}

/**
 * Register the `auto_updates` field in the themes REST API response.
 *
 * This field provides information about whether theme auto-updates are or can be enabled.
 */
function zero_admin_register_theme_auto_updates_field() {
	register_rest_field(
		'theme',
		'auto_updates',
		array(
			'get_callback' => 'zero_admin_get_theme_auto_updates_data',
			'schema'       => array(
				'description' => 'Theme auto-updates information',
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'properties'  => array(
					'enabled'   => array(
						'type'        => 'boolean',
						'description' => 'Whether auto-updates are enabled for this theme',
					),
					'supported' => array(
						'type'        => 'boolean',
						'description' => 'Whether auto-updates are supported for this theme',
					),
					'forced'    => array(
						'type'        => 'boolean',
						'description' => 'Whether auto-updates are forced for this theme',
					),
				),
			),
		)
	);
}

add_action( 'rest_api_init', 'zero_admin_register_theme_auto_updates_field' );

/**
 * Register custom collection params for user REST API to support field-specific search.
 *
 * @since 1.0.0
 *
 * @param array $params Collection parameters.
 * @return array Modified collection parameters.
 */
function zero_admin_register_user_search_collection_params( $params ) {
	$params['email_search'] = array(
		'description'       => __( 'Search users by email address (partial match).', 'zero-admin' ),
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
	);

	$params['email_search_not'] = array(
		'description'       => __( 'Exclude users whose email matches (partial match).', 'zero-admin' ),
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
	);

	$params['name_search'] = array(
		'description'       => __( 'Search users by display name (partial match).', 'zero-admin' ),
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
	);

	$params['name_search_not'] = array(
		'description'       => __( 'Exclude users whose display name matches (partial match).', 'zero-admin' ),
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
	);

	$validate_date = function ( $value ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Date must be in YYYY-MM-DD format.', 'zero-admin' )
			);
		}
		return true;
	};

	$params['registered_after'] = array(
		'description'       => __( 'Limit result set to users registered on or after a given date (YYYY-MM-DD).', 'zero-admin' ),
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'validate_callback' => $validate_date,
	);

	$params['registered_before'] = array(
		'description'       => __( 'Limit result set to users registered on or before a given date (YYYY-MM-DD).', 'zero-admin' ),
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'validate_callback' => $validate_date,
	);

	return $params;
}
add_filter( 'rest_user_collection_params', 'zero_admin_register_user_search_collection_params' );

/**
 * Apply field-specific search params to the user query.
 *
 * Uses a closure to capture search values and apply them as WHERE clauses
 * in the pre_user_query action, avoiding global state.
 *
 * @since 1.0.0
 *
 * @param array           $prepared_args Array of arguments for WP_User_Query.
 * @param WP_REST_Request $request       The REST request.
 * @return array Modified arguments.
 */
function zero_admin_apply_user_search_params( $prepared_args, $request ) {
	if ( ! current_user_can( 'list_users' ) ) {
		return $prepared_args;
	}

	$email_search      = $request->get_param( 'email_search' );
	$email_search_not  = $request->get_param( 'email_search_not' );
	$name_search       = $request->get_param( 'name_search' );
	$name_search_not   = $request->get_param( 'name_search_not' );
	$registered_after  = $request->get_param( 'registered_after' );
	$registered_before = $request->get_param( 'registered_before' );

	$has_field_search = $email_search || $email_search_not || $name_search || $name_search_not;
	$has_date_filter  = $registered_after || $registered_before;

	if ( $has_field_search || $has_date_filter ) {
		$search = array(
			'email'             => $email_search ? $email_search : '',
			'email_not'         => $email_search_not ? $email_search_not : '',
			'name'              => $name_search ? $name_search : '',
			'name_not'          => $name_search_not ? $name_search_not : '',
			'registered_after'  => $registered_after ? $registered_after : '',
			'registered_before' => $registered_before ? $registered_before : '',
		);

		$callback = function ( $query ) use ( $search, &$callback ) {
			global $wpdb;

			if ( ! empty( $search['email'] ) ) {
				$term                = '%' . $wpdb->esc_like( $search['email'] ) . '%';
				$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.user_email LIKE %s", $term );
			}

			if ( ! empty( $search['email_not'] ) ) {
				$term                = '%' . $wpdb->esc_like( $search['email_not'] ) . '%';
				$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.user_email NOT LIKE %s", $term );
			}

			// Name search uses first_name and last_name usermeta to match the UI,
			// which renders names from these meta fields (with display_name as fallback).
			// See also: zero_admin_ciab_extend_user_search() in settings-users.php.
			if ( ! empty( $search['name'] ) ) {
				$term               = '%' . $wpdb->esc_like( $search['name'] ) . '%';
				$query->query_from .= " LEFT JOIN {$wpdb->usermeta} AS na_fn ON ({$wpdb->users}.ID = na_fn.user_id AND na_fn.meta_key = 'first_name')";
				$query->query_from .= " LEFT JOIN {$wpdb->usermeta} AS na_ln ON ({$wpdb->users}.ID = na_ln.user_id AND na_ln.meta_key = 'last_name')";

				if ( false === stripos( $query->query_fields, 'DISTINCT' ) ) {
					$query->query_fields = 'DISTINCT ' . $query->query_fields;
				}
				$query->query_where .= $wpdb->prepare(
					" AND ({$wpdb->users}.display_name LIKE %s OR na_fn.meta_value LIKE %s OR na_ln.meta_value LIKE %s)",
					$term,
					$term,
					$term
				);
			}

			if ( ! empty( $search['name_not'] ) ) {
				$term = '%' . $wpdb->esc_like( $search['name_not'] ) . '%';
				if ( empty( $search['name'] ) ) {
					$query->query_from .= " LEFT JOIN {$wpdb->usermeta} AS na_fn ON ({$wpdb->users}.ID = na_fn.user_id AND na_fn.meta_key = 'first_name')";
					$query->query_from .= " LEFT JOIN {$wpdb->usermeta} AS na_ln ON ({$wpdb->users}.ID = na_ln.user_id AND na_ln.meta_key = 'last_name')";

					if ( false === stripos( $query->query_fields, 'DISTINCT' ) ) {
						$query->query_fields = 'DISTINCT ' . $query->query_fields;
					}
				}
				$query->query_where .= $wpdb->prepare(
					" AND ({$wpdb->users}.display_name NOT LIKE %s AND (na_fn.meta_value IS NULL OR na_fn.meta_value NOT LIKE %s) AND (na_ln.meta_value IS NULL OR na_ln.meta_value NOT LIKE %s))",
					$term,
					$term,
					$term
				);
			}

			if ( ! empty( $search['registered_after'] ) ) {
				$query->query_where .= $wpdb->prepare(
					" AND {$wpdb->users}.user_registered >= %s",
					$search['registered_after'] . ' 00:00:00'
				);
			}

			if ( ! empty( $search['registered_before'] ) ) {
				$query->query_where .= $wpdb->prepare(
					" AND {$wpdb->users}.user_registered <= %s",
					$search['registered_before'] . ' 23:59:59'
				);
			}

			remove_action( 'pre_user_query', $callback );
		};
		add_action( 'pre_user_query', $callback );
	}

	return $prepared_args;
}
add_filter( 'rest_user_query', 'zero_admin_apply_user_search_params', 10, 2 );
