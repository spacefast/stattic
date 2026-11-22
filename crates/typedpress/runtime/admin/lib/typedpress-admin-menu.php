<?php
/**
 * TypedPress Admin SPA
 *
 * Defines the core WordPress navigation for TypedPress Admin with progressive disclosure
 * ("hide until used") and structured sidebar sections.
 *
 * @package TypedPress_Admin
 */

/**
 * Whether WordPress Pages should appear in the Content section.
 *
 * Shown when the app declares `page.md` routes, when the site already has non-auto-draft Pages,
 * or when the site has no `tp/app/` frontend (traditional WordPress theme site).
 *
 * @return bool
 */
function tp_admin_pages_relevant() {
	$plugin = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
	if ( $plugin ) {
		$app = $plugin->schema->app();
		if ( null === $app ) {
			return true;
		}
		foreach ( (array) ( $app['routes'] ?? array() ) as $route ) {
			if ( ! empty( $route['contentPage'] ) || str_ends_with( (string) ( $route['page'] ?? '' ), '.md' ) ) {
				return true;
			}
		}
	}
	$counts = wp_count_posts( 'page' );
	if ( is_object( $counts ) ) {
		foreach ( array( 'publish', 'draft', 'future', 'pending', 'private' ) as $status ) {
			if ( ! empty( $counts->$status ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Whether the site renders a public frontend / block theme (so Design is relevant).
 *
 * @return bool
 */
function tp_admin_design_relevant() {
	$plugin = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
	if ( $plugin && false === $plugin->schema->frontend() ) {
		return false;
	}
	return true;
}

/**
 * Whether Discussion settings are relevant (comments enabled on a collection, existing comments, or pings on).
 *
 * @return bool
 */
function tp_admin_discussion_relevant() {
	if ( function_exists( 'tp_admin_comments_relevant' ) && tp_admin_comments_relevant() ) {
		return true;
	}
	$plugin = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
	if ( $plugin && method_exists( $plugin->schema, 'pings' ) && $plugin->schema->pings() ) {
		return true;
	}
	return false;
}

/**
 * Register core WordPress menu items for TypedPress Admin.
 */
function tp_admin_register_menu_items() {
	$plugin   = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
	$managed  = class_exists( '\\TypedPress\\Wp\\Runtime' ) && \TypedPress\Wp\Runtime::managed();
	$sealed   = $plugin !== null && $plugin->schema->sealed() !== null;
	$identity = class_exists( '\\TypedPress\\Identity\\Plugin' );

	tp_admin_register_menu_item(
		'dashboard',
		array(
			'to'       => '/',
			'label'    => __( 'Home', 'typedpress-admin' ),
			'icon'     => 'home',
			'section'  => 'home',
			'position' => 10,
		)
	);

	// Register existing public WordPress post types not owned by TypedPress collections.
	$query             = array(
		'show_ui'      => true,
		'show_in_menu' => true,
		'show_in_rest' => true,
	);
	$public_post_types = apply_filters( 'tp_admin_native_post_types', get_post_types( $query, 'objects' ) );
	$native_pos        = 200;
	$commerce_pos      = 310;
	foreach ( $public_post_types as $post_type ) {
		if ( 'page' === $post_type->name && ! tp_admin_pages_relevant() ) {
			continue;
		}
		$is_commerce = in_array( $post_type->name, array( 'product', 'shop_order' ), true );

		$taxonomies        = get_object_taxonomies( $post_type->name, 'objects' );
		$public_taxonomies = array_filter(
			$taxonomies,
			function ( $taxonomy ) {
				return $taxonomy->show_ui && $taxonomy->show_in_menu && current_user_can( $taxonomy->cap->manage_terms );
			}
		);

		$section = $is_commerce ? 'commerce' : 'content';
		$pos     = $is_commerce ? ++$commerce_pos : ( 'page' === $post_type->name ? 200 : ++$native_pos );

		if ( empty( $public_taxonomies ) ) {
			tp_admin_register_menu_item(
				$post_type->name,
				array(
					'to'       => '/types/' . $post_type->name,
					'label'    => $post_type->labels->name,
					'icon'     => $is_commerce ? 'store' : $post_type->menu_icon,
					'section'  => $section,
					'position' => $pos,
				)
			);
		} else {
			$parent_id = $post_type->name;
			tp_admin_register_menu_item(
				$parent_id,
				array(
					'label'       => $post_type->labels->name,
					'icon'        => $is_commerce ? 'store' : $post_type->menu_icon,
					'parent_type' => 'dropdown',
					'section'     => $section,
					'position'    => $pos,
				)
			);

			tp_admin_register_menu_item(
				$post_type->name . '-all',
				array(
					'to'     => '/types/' . $post_type->name . '/list',
					'label'  => $post_type->labels->all_items,
					'parent' => $parent_id,
				)
			);

			foreach ( $public_taxonomies as $taxonomy ) {
				tp_admin_register_menu_item(
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

	// Design menu for theme & template customization (shown whenever the site has a frontend).
	if ( tp_admin_design_relevant() && ( current_user_can( 'edit_theme_options' ) || current_user_can( 'switch_themes' ) ) ) {
		tp_admin_register_menu_item(
			'design',
			array(
				'label'       => __( 'Design', 'typedpress-admin' ),
				'icon'        => 'brush',
				'parent_type' => 'drilldown',
				'section'     => 'site',
				'position'    => 500,
			)
		);

		if ( current_user_can( 'edit_theme_options' ) ) {
			tp_admin_register_menu_item(
				'design-styles',
				array(
					'to'       => '/design/styles',
					'label'    => __( 'Styles', 'typedpress-admin' ),
					'parent'   => 'design',
					'position' => 10,
				)
			);

			tp_admin_register_menu_item(
				'design-navigation',
				array(
					'to'       => '/design/navigation',
					'label'    => __( 'Navigation', 'typedpress-admin' ),
					'parent'   => 'design',
					'position' => 20,
				)
			);

			if ( current_theme_supports( 'block-templates' ) ) {
				tp_admin_register_menu_item(
					'design-templates',
					array(
						'to'       => '/types/wp_template',
						'label'    => __( 'Templates', 'typedpress-admin' ),
						'parent'   => 'design',
						'position' => 30,
					)
				);
			}
		}

		if ( current_user_can( 'switch_themes' ) ) {
			tp_admin_register_menu_item(
				'design-themes',
				array(
					'to'       => '/design/themes',
					'label'    => __( 'Themes', 'typedpress-admin' ),
					'parent'   => 'design',
					'position' => 40,
				)
			);
		}
	}

	// Users (unified with Identity when TypedPress Identity is active).
	if ( current_user_can( 'list_users' ) ) {
		if ( $identity && current_user_can( 'manage_options' ) ) {
			tp_admin_register_menu_item(
				'users',
				array(
					'label'       => __( 'Users', 'typedpress-admin' ),
					'icon'        => 'people',
					'parent_type' => 'dropdown',
					'section'     => 'system',
					'position'    => 700,
				)
			);
			tp_admin_register_menu_item(
				'users-all',
				array(
					'to'       => '/users',
					'label'    => __( 'All Users', 'typedpress-admin' ),
					'parent'   => 'users',
					'position' => 10,
				)
			);
			if ( current_user_can( 'create_users' ) ) {
				tp_admin_register_menu_item(
					'users-add',
					array(
						'to'       => '/users/add',
						'label'    => __( 'Add User', 'typedpress-admin' ),
						'parent'   => 'users',
						'position' => 20,
					)
				);
			}
			tp_admin_register_menu_item(
				'users-identity',
				array(
					'to'       => '/identity',
					'label'    => __( 'Identity & Auth', 'typedpress-admin' ),
					'parent'   => 'users',
					'position' => 30,
				)
			);
		} else {
			tp_admin_register_menu_item(
				'users',
				array(
					'to'       => '/users',
					'label'    => __( 'Users', 'typedpress-admin' ),
					'icon'     => 'people',
					'section'  => 'system',
					'position' => 700,
				)
			);
		}
	}

	// Plugins: top-level on self-hosted non-sealed WordPress; on SpaceFast/managed or sealed sites,
	// plugins are declarative in typedpress.config.ts so they move under Settings → Plugins.
	if ( ! is_multisite() && current_user_can( 'activate_plugins' ) && ! $managed && ! $sealed ) {
		if ( current_user_can( 'install_plugins' ) ) {
			tp_admin_register_menu_item(
				'plugins',
				array(
					'label'       => __( 'Plugins', 'typedpress-admin' ),
					'icon'        => 'plugins',
					'parent_type' => 'dropdown',
					'section'     => 'system',
					'position'    => 710,
				)
			);
			tp_admin_register_menu_item(
				'plugins-list',
				array(
					'to'     => '/plugins',
					'label'  => __( 'Installed Plugins', 'typedpress-admin' ),
					'parent' => 'plugins',
				)
			);
			tp_admin_register_menu_item(
				'install-plugin',
				array(
					'to'     => '/plugins/install',
					'label'  => __( 'Add New', 'typedpress-admin' ),
					'parent' => 'plugins',
				)
			);
		} else {
			tp_admin_register_menu_item(
				'plugins',
				array(
					'to'       => '/plugins',
					'label'    => __( 'Plugins', 'typedpress-admin' ),
					'icon'     => 'plugins',
					'section'  => 'system',
					'position' => 710,
				)
			);
		}
	}
}

/**
 * Register unified Settings menu items (including Tools folded cleanly into Settings,
 * and Experiments removed).
 */
function tp_admin_register_settings_menu_items() {
	global $submenu;
	$can_manage_options = current_user_can( 'manage_options' );
	$permitted_tools    = array();
	if ( isset( $submenu['tools.php'] ) && is_array( $submenu['tools.php'] ) ) {
		foreach ( $submenu['tools.php'] as $item ) {
			$slug = $item[2] ?? '';
			$cap  = $item[1] ?? '';
			if ( '' !== $slug && 'tools.php' !== $slug && current_user_can( $cap ) ) {
				$permitted_tools[] = $item;
			}
		}
	}

	if ( ! $can_manage_options && empty( $permitted_tools ) ) {
		return;
	}

	$plugin  = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
	$managed = class_exists( '\\TypedPress\\Wp\\Runtime' ) && \TypedPress\Wp\Runtime::managed();
	$sealed  = $plugin !== null && $plugin->schema->sealed() !== null;

	if ( $can_manage_options ) {
		tp_admin_register_menu_item(
			'settings',
			array(
				'to'          => '/settings/general',
				'label'       => __( 'Settings', 'typedpress-admin' ),
				'icon'        => 'settings',
				'parent_type' => 'submenu',
				'section'     => 'system',
				'position'    => 720,
			)
		);

		tp_admin_register_menu_item(
			'settings-general',
			array(
				'to'       => '/settings/general',
				'label'    => __( 'General', 'typedpress-admin' ),
				'parent'   => 'settings',
				'position' => 10,
			)
		);
		tp_admin_register_menu_item(
			'settings-reading',
			array(
				'to'       => '/settings/reading',
				'label'    => __( 'Reading', 'typedpress-admin' ),
				'parent'   => 'settings',
				'position' => 20,
			)
		);

		$skip_settings = array( 'options-general.php', 'options-reading.php' );
		if ( ! tp_admin_discussion_relevant() ) {
			$skip_settings[] = 'options-discussion.php';
		}
		tp_admin_register_classic_children( 'settings', null, null, 'options-general.php', $skip_settings );

		// On managed/sealed sites, keep a read-only Plugins view reachable under Settings.
		if ( ( $managed || $sealed ) && ! is_multisite() && current_user_can( 'activate_plugins' ) ) {
			tp_admin_register_menu_item(
				'settings-plugins',
				array(
					'to'       => '/plugins',
					'label'    => __( 'Plugins', 'typedpress-admin' ),
					'icon'     => 'plugins',
					'parent'   => 'settings',
					'position' => 80,
				)
			);
		}

		// Fold wp-admin's Tools (Site Health, Import, Export, Personal Data) cleanly inside Settings
		// instead of taking a top-level root sidebar slot.
		tp_admin_register_classic_children( 'settings', null, null, 'tools.php', array( 'tools.php' ) );
	} else {
		$first_tool_slug = (string) ( $permitted_tools[0][2] ?? 'export.php' );
		tp_admin_register_menu_item(
			'tools',
			array(
				'to'          => tp_admin_get_menu_to_from_slug( $first_tool_slug, 'tools.php' ),
				'label'       => __( 'Tools', 'typedpress-admin' ),
				'icon'        => 'tool',
				'parent_type' => 'submenu',
				'section'     => 'system',
				'position'    => 720,
			)
		);
		tp_admin_register_classic_children( 'tools', null, null, 'tools.php', array( 'tools.php' ) );
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
function tp_admin_get_menu_to_from_slug( $slug, $parent_slug = 'admin.php' ) {
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

	$admin_url = admin_url();
	if ( strpos( $final_slug, $admin_url ) === 0 ) {
		$final_slug = str_replace( $admin_url, '', $final_slug );
	}

	$parts      = wp_parse_url( $final_slug );
	$final_slug = str_replace( '&amp;', '&', $final_slug );

	return empty( $parts['host'] ) ? tp_admin_classic_route( $final_slug ) : $final_slug;
}

/**
 * Extracts the wp-admin filename from an href if it points to the current site's wp-admin.
 *
 * @param string $href The href URL to process.
 * @return string|null The wp-admin filename (e.g., 'about.php') or null if not applicable.
 */
function tp_admin_get_wp_admin_slug( $href ) {
	$admin_url = admin_url();

	if ( str_starts_with( $href, $admin_url ) ) {
		return substr( $href, strlen( $admin_url ) );
	}

	return null;
}

/**
 * Register genuine third-party plugin menus under the Extensions section.
 *
 * Internal TypedPress plumbing (SCF, Identity, app menu) and core menus are excluded so they
 * never clutter the root sidebar.
 */
function tp_admin_register_classic_menu_items() {
	global $menu, $submenu;

	$core_menu_items = array(
		'index.php',
		'upload.php',
		'edit-comments.php',
		'themes.php',
		'plugins.php',
		'users.php',
		'tools.php',
		'options-general.php',
		'typedpress-admin',
		'typedpress-identity',
		'zero-identity',
		'separator1',
		'separator2',
		'separator-last',
	);

	// Hide Secure Custom Fields (compiler implementation detail) unless self-hosted Developer Mode is on.
	if ( ! tp_admin_developer_mode() ) {
		$core_menu_items[] = 'edit.php?post_type=acf-field-group';
		$core_menu_items[] = 'acf-field-group';
	}

	// Exclude custom post types and taxonomies from classic menu registration.
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

	$core_menu_items = apply_filters( 'tp_admin_classic_menu_items_exclude', $core_menu_items );

	$ext_pos = 600;
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

			// Group WooCommerce plugin menus cleanly under Commerce.
			$is_commerce = in_array( $menu_slug, array( 'woocommerce', 'wc-admin', 'wc-admin&path=/analytics/overview', 'woocommerce-marketing' ), true )
				|| str_starts_with( $menu_slug, 'wc-' );

			$menu_id   = 'classic-' . sanitize_key( $menu_slug );
			$menu_args = array(
				'to'       => tp_admin_get_menu_to_from_slug( $menu_slug ),
				'label'    => trim( wp_strip_all_tags( preg_replace( '/<span.*/s', '', $menu_title ) ) ),
				'icon'     => $menu_icon ?: ( $is_commerce ? 'store' : 'plugins' ),
				'section'  => $is_commerce ? 'commerce' : 'extensions',
				'position' => $is_commerce ? 350 : ++$ext_pos,
			);

			if ( $has_submenus ) {
				$menu_args['parent_type'] = 'dropdown';
			}

			tp_admin_register_menu_item( $menu_id, $menu_args );

			if ( $has_submenus ) {
				foreach ( $submenu[ $menu_slug ] as $submenu_item ) {
					if ( empty( $submenu_item[0] ) ) {
						continue;
					}

					if ( ! empty( $submenu_item[1] ) && ! current_user_can( $submenu_item[1] ) ) {
						continue;
					}

					if ( in_array( $submenu_item[2], $core_menu_items, true ) ) {
						continue;
					}

					tp_admin_register_menu_item(
						'classic-submenu-' . sanitize_key( $menu_slug . '-' . $submenu_item[2] ),
						array(
							'to'     => tp_admin_get_menu_to_from_slug( $submenu_item[2], $menu_slug ),
							'label'  => trim( wp_strip_all_tags( preg_replace( '/<span.*/s', '', $submenu_item[0] ) ) ),
							'parent' => $menu_id,
						)
					);
				}
			}
		}
	}
}

/**
 * Register non-core WordPress meta menu items in TypedPress Admin.
 */
function tp_admin_register_classic_meta_menu_items() {
	global $wp_admin_bar;
	$wp_admin_bar_nodes = $wp_admin_bar->get_nodes();

	if ( empty( $wp_admin_bar_nodes ) || ! is_array( $wp_admin_bar_nodes ) ) {
		return;
	}

	$core_menu_items = array(
		'new-media',
		'new-user',
	);

	$query      = array(
		'show_ui'      => true,
		'show_in_rest' => true,
	);
	$post_types = get_post_types( $query, 'objects' );
	foreach ( $post_types as $post_type ) {
		$core_menu_items[] = 'new-' . $post_type->name;
	}

	$core_menu_items = apply_filters( 'tp_admin_classic_meta_menu_items_exclude', $core_menu_items );

	foreach ( $wp_admin_bar_nodes as $node ) {
		$menu_id = $node->id;

		if ( in_array( $node->id, $core_menu_items, true ) ) {
			continue;
		}

		$to = '';
		if ( isset( $node->href ) && $node->href ) {
			$wp_admin_slug = tp_admin_get_wp_admin_slug( $node->href );
			if ( null !== $wp_admin_slug ) {
				$to = tp_admin_get_menu_to_from_slug( $wp_admin_slug );
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

		tp_admin_register_menu_item( $menu_id, $menu_args, true );
	}
}

add_action( 'tp_admin_init', 'tp_admin_register_menu_items' );
add_action( 'tp_admin_init', 'tp_admin_register_classic_menu_items' );
add_action( 'tp_admin_init', 'tp_admin_register_classic_meta_menu_items' );
add_action( 'tp_admin_init', 'tp_admin_register_settings_menu_items', 99999 );
