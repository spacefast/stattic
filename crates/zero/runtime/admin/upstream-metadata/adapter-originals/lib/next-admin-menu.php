<?php
/**
 * Zero Admin SPA
 *
 * This files defines the default menu of Zero Admin SPA
 *
 * @package Zero_Admin
 */

/**
 * Register a menu item for the Zero Admin SPA.
 */
function zero_admin_register_menu_items() {
	zero_admin_register_menu_item(
		'dashboard',
		array(
			'to'    => '/',
			'label' => __( 'Dashboard', 'zero-admin' ),
		)
	);

	// Register as menu items the existing public post types
	// that are set to show in the menu and available in REST API.
	$query             = array(
		'show_ui'      => true,
		'show_in_menu' => true,
		'show_in_rest' => true,
	);
	$public_post_types = get_post_types( $query, 'objects' );
	foreach ( $public_post_types as $post_type ) {
		// Get taxonomies associated with this post type.
		$taxonomies        = get_object_taxonomies( $post_type->name, 'objects' );
		$public_taxonomies = array_filter(
			$taxonomies,
			function ( $taxonomy ) {
				return $taxonomy->show_ui && $taxonomy->show_in_menu && current_user_can( $taxonomy->cap->manage_terms );
			}
		);

		// If the post type has no taxonomies, register it as a direct menu item.
		// If the post type has taxonomies, register it as a dropdown menu.
		if ( empty( $public_taxonomies ) ) {
			zero_admin_register_menu_item(
				$post_type->name,
				array(
					'to'    => '/types/' . $post_type->name,
					'label' => $post_type->labels->name,
					'icon'  => $post_type->menu_icon,
				)
			);
		} else {
			$parent_id = $post_type->name;
			zero_admin_register_menu_item(
				$parent_id,
				array(
					'label'       => $post_type->labels->name,
					'icon'        => $post_type->menu_icon,
					'parent_type' => 'dropdown',
				)
			);

			zero_admin_register_menu_item(
				$post_type->name . '-all',
				array(
					'to'     => '/types/' . $post_type->name . '/list',
					'label'  => $post_type->labels->all_items,
					'parent' => $parent_id,
				)
			);

			foreach ( $public_taxonomies as $taxonomy ) {
				$icon = $taxonomy->hierarchical ? 'dashicons-category' : 'dashicons-tag';

				zero_admin_register_menu_item(
					$post_type->name . '-' . $taxonomy->name,
					array(
						'to'     => '/types/' . $post_type->name . '/taxonomies/' . $taxonomy->name,
						'label'  => $taxonomy->labels->menu_name ?? $taxonomy->labels->name,
						'parent' => $parent_id,
					)
				);
			}
		}
	}

	// Design menu for theme customization.
	if ( current_user_can( 'edit_theme_options' ) ) {
		zero_admin_register_menu_item(
			'design',
			array(
				'label'       => __( 'Design', 'zero-admin' ),
				'parent_type' => 'drilldown',
			)
		);
		// Register child menu item.
		zero_admin_register_menu_item(
			'design-styles',
			array(
				'to'       => '/design/styles',
				'label'    => __( 'Styles', 'zero-admin' ),
				'parent'   => 'design',
				'position' => 10,
			)
		);

		zero_admin_register_menu_item(
			'design-navigation',
			array(
				'to'       => '/design/navigation',
				'label'    => __( 'Navigation', 'zero-admin' ),
				'parent'   => 'design',
				'position' => 20,
			)
		);

		if ( current_theme_supports( 'block-templates' ) ) {
			zero_admin_register_menu_item(
				'design-templates',
				array(
					'to'       => '/types/wp_template',
					'label'    => __( 'Templates', 'zero-admin' ),
					'parent'   => 'design',
					'position' => 30,
				)
			);
		}
	}

	if ( current_user_can( 'switch_themes' ) ) {
		zero_admin_register_menu_item(
			'design-themes',
			array(
				'to'       => '/design/themes',
				'label'    => __( 'Themes', 'zero-admin' ),
				'parent'   => 'design',
				'position' => 40,
			)
		);
	}

	if ( current_user_can( 'list_users' ) ) {
		zero_admin_register_menu_item(
			'users',
			array(
				'to'    => '/users',
				'label' => __( 'Users', 'zero-admin' ),
			)
		);
	}

	// Single‐site “Plugins”.
	if ( ! is_multisite() && current_user_can( 'activate_plugins' ) ) {
		// Register the top‐level Plugins menu.
		// This is only available if the user can activate plugins.
		zero_admin_register_menu_item(
			'plugins',
			array(
				'label'       => __( 'Plugins', 'zero-admin' ),
				'parent_type' => 'dropdown',
			)
		);

		// Installed Plugins.
		// This is only available if the user can activate plugins.
		zero_admin_register_menu_item(
			'plugins-list',
			array(
				'to'     => '/plugins',
				'label'  => __( 'Installed Plugins', 'zero-admin' ),
				'parent' => 'plugins',
			)
		);

		// Add New Plugin.
		// This is only available if the user can install plugins.
		if ( current_user_can( 'install_plugins' ) ) {
			zero_admin_register_menu_item(
				'install-plugin',
				array(
					'to'     => '/plugins/install',
					'label'  => __( 'Add New', 'zero-admin' ),
					'parent' => 'plugins',
				)
			);
		}
	}
}

/**
 * Register settings menu items.
 */
function zero_admin_register_settings_menu_items() {
	if ( current_user_can( 'manage_options' ) ) {
		zero_admin_register_menu_item(
			'settings',
			array(
				'to'          => '/settings/general',
				'label'       => __( 'Settings', 'zero-admin' ),
				'icon'        => 'dashicons-admin-settings',
				'parent_type' => 'submenu',
			)
		);

		// Register children.
		zero_admin_register_menu_item(
			'settings-general',
			array(
				'to'     => '/settings/general',
				'label'  => __( 'General', 'zero-admin' ),
				'parent' => 'settings',
			)
		);
		zero_admin_register_menu_item(
			'settings-reading',
			array(
				'to'     => '/settings/reading',
				'label'  => __( 'Reading', 'zero-admin' ),
				'parent' => 'settings',
			)
		);
		zero_admin_register_menu_item(
			'settings-experiments',
			array(
				'to'     => '/settings/experiments',
				'label'  => __( 'Experiments', 'zero-admin' ),
				'parent' => 'settings',
			)
		);
	}
}

/**
 * Get the menu URL for a menu item from its slug, following WordPress core logic.
 *
 * @param string $slug The slug of the menu item.
 * @param string $parent_slug Optional. Parent menu slug for submenu items.
 *
 * @return string The to URL for the menu item.
 */
function zero_admin_get_menu_to_from_slug( $slug, $parent_slug = 'admin.php' ) {
	// Apply WordPress core logic. See `_wp_menu_output` in
	// `wp-admin/includes/menu-header.php`.
	// https://github.com/WordPress/wordpress-develop/blob/d71b1d89ee166c9685fb357ab02b1260e9b0c8f3/src/wp-admin/menu-header.php#L157-L196.
	$menu_hook = get_plugin_page_hook( $slug, $parent_slug );
	$menu_file = $slug;
	$pos       = strpos( $menu_file, '?' );

	if ( false !== $pos ) {
		$menu_file = substr( $menu_file, 0, $pos );
	}

	if ( ! empty( $menu_hook )
		|| ( ( 'index.php' !== $slug )
			&& file_exists( WP_PLUGIN_DIR . "/$menu_file" )
			&& ! file_exists( ABSPATH . "/wp-admin/$menu_file" ) )
	) {
		$final_slug = 'admin.php?page=' . $slug;
	} else {
		$final_slug = $slug;
	}

	// Clean up admin URL base if present.
	$admin_url = admin_url();
	if ( strpos( $final_slug, $admin_url ) === 0 ) {
		$final_slug = str_replace( $admin_url, '', $final_slug );
	}

	$parts = wp_parse_url( $final_slug );

	// Decode &amp; to &.
	$final_slug = str_replace( '&amp;', '&', $final_slug );

	// Do not wrap external URLs.
	return empty( $parts['host'] ) ? '/legacy/' . rawurlencode( $final_slug ) : $final_slug;
}

/**
 * Extracts the wp-admin filename from an href if it points to the current site's wp-admin.
 *
 * @param string $href The href URL to process.
 * @return string|null The wp-admin filename (e.g., 'about.php') or null if not applicable.
 */
function zero_admin_get_wp_admin_slug( $href ) {
	$admin_url        = admin_url();
	$admin_url_parsed = wp_parse_url( $admin_url );
	$href_parsed      = wp_parse_url( $href );

	if ( str_starts_with( $href, $admin_url ) ) {
		return substr( $href, strlen( $admin_url ) );
	}

	return null;
}

/**
 * Register non-core WordPress menu items in Zero Admin.
 *
 * This function scans the WordPress admin menu globals to find menu items
 * that are not part of core WordPress and registers them in Zero Admin.
 */
function zero_admin_register_legacy_menu_items() {
	global $menu, $submenu;

	// Core WordPress menu items to exclude.
	$core_menu_items = array(
		'index.php',           // Dashboard.
		'upload.php',          // Media.
		'edit-comments.php',   // Comments.
		'themes.php',          // Appearance.
		'plugins.php',         // Plugins.
		'users.php',           // Users.
		'tools.php',           // Tools.
		'options-general.php', // Settings.
		'zero-admin',          // Zero Admin (avoid circular reference).
		'separator1',          // Separator.
		'separator2',          // Separator.
		'separator-last',      // Separator.
	);

	// Exclude custom post types and taxonomies from legacy menu registration.
	$query             = array(
		'public'       => true,
		'show_ui'      => true,
		'show_in_rest' => true,
	);
	$public_post_types = get_post_types( $query, 'objects' );
	foreach ( $public_post_types as $post_type ) {
		if ( 'post' === $post_type->name ) {
			$core_menu_items[] = 'edit.php';
		} else {
			$core_menu_items[] = 'edit.php?post_type=' . $post_type->name;
		}
	}

	/**
	 * Filter the list of core menu items to exclude from legacy menu registration.
	 *
	 * @param array $core_menu_items Array of menu slugs to exclude.
	 */
	$core_menu_items = apply_filters( 'zero_admin_legacy_menu_items_exclude', $core_menu_items );

	if ( ! empty( $menu ) ) {
		foreach ( $menu as $menu_item ) {
			if ( empty( $menu_item[2] ) || strpos( $menu_item[2], 'separator' ) !== false ) {
				continue;
			}

			if ( in_array( $menu_item[2], $core_menu_items, true ) ) {
				continue;
			}

			if ( ! empty( $menu_item[1] ) && ! current_user_can( $menu_item[1] ) ) {
				continue;
			}

			$menu_title = $menu_item[0];
			$menu_slug  = $menu_item[2];
			$menu_icon  = isset( $menu_item[6] ) ? $menu_item[6] : null;

			$has_submenus = isset( $submenu[ $menu_slug ] ) && ! empty( $submenu[ $menu_slug ] );

			$menu_id   = 'legacy-' . sanitize_key( $menu_slug );
			$menu_args = array(
				'to'    => zero_admin_get_menu_to_from_slug( $menu_slug ),
				'label' => preg_replace( '/<.*/', '', $menu_title ),
				'icon'  => $menu_icon,
			);

			if ( $has_submenus ) {
				$menu_args['parent_type'] = 'dropdown';
			}

			zero_admin_register_menu_item( $menu_id, $menu_args );

			if ( $has_submenus ) {
				foreach ( $submenu[ $menu_slug ] as $submenu_item ) {
					if ( empty( $submenu_item[0] ) ) {
						continue;
					}

					if ( ! empty( $submenu_item[1] ) && ! current_user_can( $submenu_item[1] ) ) {
						continue;
					}

					// Check if this submenu item is in the exclusion list.
					if ( in_array( $submenu_item[2], $core_menu_items, true ) ) {
						continue;
					}

					zero_admin_register_menu_item(
						'legacy-submenu-' . sanitize_key( $menu_slug . '-' . $submenu_item[2] ),
						array(
							'to'     => zero_admin_get_menu_to_from_slug( $submenu_item[2], $menu_slug ),
							'label'  => preg_replace( '/<.*/', '', $submenu_item[0] ),
							'parent' => $menu_id,
						)
					);
				}
			}
		}
	}
}

/**
 * Register non-core WordPress meta menu items in Zero Admin.
 *
 * This function scans the WordPress admin bar menu items
 * that are not part of core WordPress and registers them in Zero Admin.
 */
function zero_admin_register_legacy_meta_menu_items() {
	global $wp_admin_bar;
	// `get_nodes` returns an array of cloned items. Mutating them is fine.
	// See https://github.com/WordPress/wordpress-develop/blob/c726220a21d13fdb5409372b652c9460c59ce1db/src/wp-includes/class-wp-admin-bar.php#L237
	$wp_admin_bar_nodes = $wp_admin_bar->get_nodes();

	if ( empty( $wp_admin_bar_nodes ) || ! is_array( $wp_admin_bar_nodes ) ) {
		return;
	}

	// Core WordPress menu items to exclude.
	$core_menu_items = array(
		'new-media', // New Media.
		'new-user',  // New User.
	);

	// Exclude custom post types and taxonomies from legacy menu registration.
	$query      = array(
		'show_ui'      => true,
		'show_in_rest' => true,
	);
	$post_types = get_post_types( $query, 'objects' );
	foreach ( $post_types as $post_type ) {
		$core_menu_items[] = 'new-' . $post_type->name;
	}

	/**
	 * Filter the list of core menu items to exclude from legacy menu registration.
	 *
	 * @param array $core_menu_items Array of menu slugs to exclude.
	 */
	$core_menu_items = apply_filters( 'zero_admin_legacy_meta_menu_items_exclude', $core_menu_items );

	foreach ( $wp_admin_bar_nodes as $node ) {
		$menu_id = $node->id;

		if ( in_array( $node->id, $core_menu_items, true ) ) {
			continue;
		}

		// Set 'to' property based on href or empty string.
		$to = '';
		if ( isset( $node->href ) && $node->href ) {
			$wp_admin_slug = zero_admin_get_wp_admin_slug( $node->href );
			if ( null !== $wp_admin_slug ) {
				$to = zero_admin_get_menu_to_from_slug( $wp_admin_slug );
			} else {
				$to = $node->href;
			}
		}
		$menu_args = array(
			'to'     => $to,
			'label'  => wp_strip_all_tags( $node->title ),
			'parent' => ! empty( $node->parent ) ? $node->parent : null,
		);
		if ( isset( $node->group ) && $node->group ) {
			$menu_args['parent_type'] = 'dropdown';
		} else {
			$menu_args['parent_type'] = 'drilldown';
		}

		zero_admin_register_menu_item( $menu_id, $menu_args, true );
	}
}

add_action( 'zero_admin_init', 'zero_admin_register_menu_items' );
add_action( 'zero_admin_init', 'zero_admin_register_legacy_menu_items' );
add_action( 'zero_admin_init', 'zero_admin_register_legacy_meta_menu_items' );
add_action( 'zero_admin_init', 'zero_admin_register_settings_menu_items', 99999 );
