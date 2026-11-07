<?php
/**
 * Post type registration overrides and default view configuration.
 *
 * @package ZeroAdmin
 */

/**
 * Register default view arguments for post types.
 *
 * @param array  $args      Post type arguments.
 * @param string $post_type Post type name.
 * @return array Modified post type arguments.
 */
function zero_admin_register_default_view_arg_for_post_type( $args, $post_type ) {
	$default_view = array(
		'type'       => 'table',
		'perPage'    => 20,
		'sort'       => array(
			'field'     => 'date',
			'direction' => 'desc',
		),
		'titleField' => 'title',
		'mediaField' => 'featured_media',
		'fields'     => array( 'status', 'author', 'date' ),
		'showLevels' => false,
		'showMedia'  => true,
	);

	$default_layouts = array(
		'list'  => array(),
		'grid'  => array(),
		'table' => array(),
	);

	$quick_edit_form = (object) array();

	// Configure post type specific layouts.
	switch ( $post_type ) {
		case 'post':
			$default_view['fields'] = array( 'author', 'status', 'comment_count', 'category', 'post_tag', 'date' );
			$default_layouts        = array(
				'table' => array(
					'fields'       => array( 'author', 'status', 'comment_count', 'category', 'post_tag', 'date' ),
					'mediaField'   => 'featured_media',
					'groupByField' => null,
				),
				'grid'  => array(
					'fields'       => array(),
					'mediaField'   => 'featured_mixed',
					'groupByField' => 'year',
				),
				'list'  => array(
					'fields'       => array( 'author', 'status' ),
					'mediaField'   => 'featured_media',
					'groupByField' => null,
				),
			);
			$quick_edit_form        = array(
				'layout' => array( 'type' => 'panel' ),
				'fields' => array(
					'title',
					array(
						'id'     => 'featured_media',
						'layout' => array( 'type' => 'regular' ),
					),
					array(
						'id'       => 'status',
						'label'    => __( 'Status & Visibility', 'zero-admin' ),
						'children' => array( 'status', 'password' ),
					),
					'author',
					'date',
					'slug',
					'parent',
					'comment_status',
					array(
						'id'     => 'template',
						'label'  => __( 'Template', 'zero-admin' ),
						'layout' => array(
							'type'          => 'regular',
							'labelPosition' => 'side',
						),
					),
				),
			);
			break;

		case 'page':
			$default_view['type']   = 'list';
			$default_view['sort']   = array(
				'field'     => 'title',
				'direction' => 'asc',
			);
			$default_view['fields'] = array( 'status' );
			$default_layouts        = array(
				'table' => array(
					'fields' => array( 'status', 'date', 'author' ),
					'layout' => array(
						'styles' => array(
							'author' => array(
								'align' => 'start',
							),
						),
					),
				),
				'grid'  => array( 'fields' => array() ),
				'list'  => array( 'fields' => array( 'status' ) ),
			);
			$quick_edit_form        = array(
				'layout' => array( 'type' => 'panel' ),
				'fields' => array(
					'title',
					array(
						'id'     => 'featured_media',
						'layout' => array( 'type' => 'regular' ),
					),
					array(
						'id'       => 'status',
						'label'    => __( 'Status & Visibility', 'zero-admin' ),
						'children' => array( 'status', 'password' ),
					),
					'author',
					'date',
					'slug',
					'parent',
					'comment_status',
					array(
						'id'     => 'template',
						'label'  => __( 'Template', 'zero-admin' ),
						'layout' => array(
							'type'          => 'regular',
							'labelPosition' => 'side',
						),
					),
				),
			);
			break;

		case 'wp_template':
			$default_view['mediaField']       = 'content-preview';
			$default_view['descriptionField'] = 'description';
			$default_view['showMedia']        = false;
			$default_view['fields']           = array();
			$default_layouts                  = array(
				'table' => array( 'showMedia' => false ),
				'grid'  => array( 'showMedia' => true ),
				'list'  => array( 'showMedia' => false ),
			);
			break;

		case 'attachment':
			$default_view['type']       = 'grid';
			$default_view['mediaField'] = 'media_thumbnail';
			$default_view['fields']     = array();
			$default_view['showTitle']  = false;
			$default_layouts            = array(
				'list'  => array(
					'fields'    => array( 'author', 'date', 'media_dimensions', 'filesize' ),
					'showTitle' => true,
				),
				'grid'  => array(
					'fields'    => array(),
					'showTitle' => false,
				),
				'table' => array(
					'fields'    => array( 'author', 'date', 'media_dimensions', 'filesize' ),
					'showTitle' => true,
				),
			);
			break;
	}

	if ( 'attachment' === $post_type ) {
		$args['default_view']    = $default_view;
		$args['default_layouts'] = $default_layouts;
		$args['import_handler']  = plugin_dir_url( __DIR__ ) . 'build/packages/attachment-importer/index.js';

		$args['quick_edit_form'] = array(
			'layout' => array(
				'type' => 'panel',
			),
			'fields' => array(
				'date',
				'author',
				'filename',
				'mime_type',
				'filesize',
				'media_dimensions',
				'attached_to',
				array(
					'id'     => 'title',
					'layout' => array(
						'type'          => 'regular',
						'labelPosition' => 'top',
					),
				),
				array(
					'id'     => 'alt_text',
					'layout' => array(
						'type'          => 'regular',
						'labelPosition' => 'top',
					),
				),
				array(
					'id'     => 'caption',
					'layout' => array(
						'type'          => 'regular',
						'labelPosition' => 'top',
					),
				),
				array(
					'id'     => 'description',
					'layout' => array(
						'type'          => 'regular',
						'labelPosition' => 'top',
					),
				),
			),
		);

		if ( ! isset( $args['views'] ) ) {
			$tabs = array(
				array(
					'media_type' => 'image',
					'label'      => __( 'Images', 'zero-admin' ),
				),
				array(
					'media_type' => 'video',
					'label'      => __( 'Videos', 'zero-admin' ),
				),
				array(
					'media_type' => 'audio',
					'label'      => __( 'Audio', 'zero-admin' ),
				),
				array(
					'media_type' => 'application',
					'label'      => __( 'Files', 'zero-admin' ),
				),
			);

			$args['views'] = array_merge(
				array(
					array(
						'slug'  => 'all',
						/* translators: Label for showing all posts regardless of status */
						'label' => __( 'All', 'zero-admin' ),
						'view'  => $default_view,
					),
				),
				array_map(
					function ( $tab ) use ( $default_view ) {
						return array(
							'slug'  => $tab['media_type'],
							'label' => $tab['label'],
							'view'  => array_merge(
								$default_view,
								array(
									'filters' => array_merge(
										$default_view['filters'] ?? array(),
										array(
											array(
												'field'    => 'media_type',
												'operator' => 'is',
												'value'    => $tab['media_type'],
											),
										)
									),
								)
							),
						);
					},
					$tabs
				)
			);
		}
	} elseif (
		( isset( $args['show_ui'] ) && true === $args['show_ui'] ) ||
		( ! isset( $args['show_ui'] ) && isset( $args['public'] ) && true === $args['public'] )
	) {
		if ( ! isset( $args['default_view'] ) ) {
			$args['default_view'] = $default_view;
		}
		if ( ! isset( $args['default_layouts'] ) ) {
			$args['default_layouts'] = $default_layouts;
		}
		if ( ! isset( $args['quick_edit_form'] ) ) {
			$args['quick_edit_form'] = $quick_edit_form;
		}
		if ( ! isset( $args['views'] ) ) {
			$args['views'] = array(
				array(
					'slug'  => 'all',
					/* translators: Label for showing all posts regardless of status */
					'label' => __( 'All', 'zero-admin' ),
					'view'  => $default_view,
				),
			);

			// Mapped from Gutenberg's STATUSES object at
			// packages/fields/src/fields/status/status-elements.tsx
			//
			// DataForm doesn't support custom statuses yet. This is something to solve
			// across Zero Admin and Gutenberg, so let's draw the same limits here.
			$allowed_status_slugs = array(
				'draft',
				'future',
				'pending',
				'private',
				'publish',
				'trash',
			);

			$statuses     = get_post_stati( array( 'internal' => false ), 'object' );
			$trash_status = get_post_status_object( 'trash' );
			if ( $trash_status ) {
				$statuses['trash'] = $trash_status;
			}
			foreach ( $statuses as $status ) {
				// Check permissions. This closely mirrors `check_read_permission` from
				// `WP_REST_Post_Statuses_Controller`, except only the relevant post type
				// is checked.
				$can_read_status = true;
				if ( ! $status->public && ( false === $status->internal || 'trash' === $status->name ) ) {
					if ( ! isset( $args['capabilities'] ) ) {
						$args['capabilities'] = array();
					}
					if ( ! isset( $args['capability_type'] ) ) {
						$args['capability_type'] = 'post';
					}
					if ( ! isset( $args['map_meta_cap'] ) || null === $args['map_meta_cap'] ) {
						$args['map_meta_cap'] = empty( $args['capabilities'] ) && in_array( $args['capability_type'], array( 'post', 'page' ), true );
					}
					$post_type_cap   = get_post_type_capabilities( (object) $args );
					$can_read_status = current_user_can( $post_type_cap->edit_posts );
				}

				if (
					$can_read_status &&
					in_array( $status->name, $allowed_status_slugs, true ) &&
					( $status->show_in_admin_all_list || 'trash' === $status->name )
				) {
					$args['views'][] = array(
						'slug'  => $status->name,
						'label' => $status->label,
						'view'  => array_merge(
							$default_view,
							array(
								'filters' => array_merge(
									$default_view['filters'] ?? array(),
									array(
										array(
											'field'    => 'status',
											'operator' => 'is',
											'value'    => $status->name,
										),
									)
								),
							)
						),
					);
				}
			}
		}
	} elseif ( 'wp_template' === $post_type ) {
		$args['default_view']    = $default_view;
		$args['default_layouts'] = $default_layouts;
		// TODO: we need to define the right fields for the quick edit form for wp_template.
		$args['quick_edit_form'] = array(
			'layout' => array( 'type' => 'panel' ),
			'fields' => array(
				'title',
				array(
					'id'     => 'featured_media',
					'layout' => array( 'type' => 'regular' ),
				),
				array(
					'id'       => 'status',
					'label'    => __( 'Status & Visibility', 'zero-admin' ),
					'children' => array( 'status', 'password' ),
				),
				'author',
				'date',
				'slug',
				'parent',
				'comment_status',
				array(
					'id'     => 'template',
					'label'  => __( 'Template', 'zero-admin' ),
					'layout' => array(
						'type'          => 'regular',
						'labelPosition' => 'side',
					),
				),
			),
		);
	} else {
		$args['default_view']    = array();
		$args['default_layouts'] = array();
		$args['quick_edit_form'] = array();
	}

	if ( 'post' === $post_type ) {
		$args['import_handler'] = plugin_dir_url( __DIR__ ) . 'build/packages/post-importer/index.js';
	}

	return $args;
}
add_filter( 'register_post_type_args', 'zero_admin_register_default_view_arg_for_post_type', 10, 2 );

/**
 * Enable show_ui for wp_template so that the templates route is available..
 *
 * @param array $args Post type arguments.
 * @return array Modified post type arguments.
 */
function zero_admin_enable_template_show_ui( $args ) {
	$args['show_ui'] = true;
	return $args;
}
add_filter( 'register_wp_template_post_type_args', 'zero_admin_enable_template_show_ui', 10, 1 );


/**
 * Add explicit support for the duplicate-post DataViews action for post types.
 * The action is not guaranteed to work with all post types, so it should be opt-in.
 */
function zero_admin_add_duplicate_post_support() {
	add_post_type_support( 'post', 'duplicate-post' );
	add_post_type_support( 'page', 'duplicate-post' );
}
add_action( 'init', 'zero_admin_add_duplicate_post_support' );
