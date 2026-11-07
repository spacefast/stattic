<?php
/**
 * Zero Admin SPA
 *
 * This file serves as the entry point for the Zero Admin SPA.
 *
 * @package Zero_Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

set_current_screen();

// Do this action to populate the admin bar menu items. This is needed because the admin bar is not shown in ZeroAdmin, so its items won't be populated by default.
global $wp_admin_bar;
do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );

// Remove unwanted deprecated handler added by `WP_Admin_Bar::initialize`.
remove_action( 'admin_head', 'wp_admin_bar_header' );

// Remove unwanted scripts and styles that were enqueued during `admin_init` and `admin_bar_menu`.
foreach ( wp_scripts()->queue as $script ) {
	wp_dequeue_script( $script );
}
foreach ( wp_styles()->queue as $style ) {
	wp_dequeue_style( $style );
}

// Run `zero_admin_init`.
do_action( 'zero_admin_init' );

// Always enable the experimental DataViews media modal.
wp_register_script( 'zero-admin-gutenberg-experiments', '', array(), GUTENBERG_VERSION, false );
wp_add_inline_script( 'zero-admin-gutenberg-experiments', 'window.__experimentalDataViewsMediaModal = true' );
wp_enqueue_script( 'zero-admin-gutenberg-experiments' );

// Get boot module asset file for dependencies.
$asset_file = include ZERO_ADMIN_PLUGIN_DIR . 'build/packages/boot/index.asset.php';

// This script serves two purposes:
// 1. It ensures all the globals that are made available to the modules are loaded.
// 2. It initializes the boot module as an inline script.
wp_register_script( 'zero-admin-prerequisites', '', $asset_file['dependencies'], $asset_file['version'], true );

/*
 * Expose the default dashboard layout for client-side restore.
 *
 * This calls apply_filters on an empty array to simulate a fresh user,
 * collecting all default widgets injected by plugins via the
 * `zero_admin_dashboard_widgets` filter.
 *
 * This is safe to call here because `do_action( 'zero_admin_init' )` has
 * already completed at this point, which means all plugins have registered
 * their `zero_admin_dashboard_widgets` filter callbacks.
 */
$dashboard_default_layout = apply_filters( 'zero_admin_dashboard_widgets', array() );

// Pass data to script module via global variables.
wp_add_inline_script(
	'zero-admin-prerequisites',
	sprintf(
		'import( "@zero-admin/boot" ).then( mod => mod.init( %s, %s, %s, %s, %s, %s, %s ) );',
		wp_json_encode( zero_admin_get_admin_routes(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_admin_menu_items(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_admin_meta_menu_items(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_dashboard_widgets(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_init_modules(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_dashboard_context(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( $dashboard_default_layout, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
	)
);

// Preload endpoints used by `getEntityRecord` (entity configs) and by the root route loader.
$zero_admin_preload_paths = array(
	// Config for the `postType` entity kind (fetched by Gutenberg `loadPostTypeEntities`).
	'/wp/v2/types?context=view',
	// Config for the `taxonomy` entity kind (fetched by Gutenberg `loadTaxonomyEntities`).
	'/wp/v2/taxonomies?context=view',
	// Config for the `root/site` entity (fetched by Gutenberg `loadSiteEntity`).
	array( '/wp/v2/settings', 'OPTIONS' ),
	array( '/wp/v2/settings', 'GET' ),
	// Current user object (loaded by the root route loader).
	'/wp/v2/users/me',
	// `baseURL` + `baseURLParams` of the `__unstableBase` entity (loaded by the root route loader).
	'/?_fields=' . implode(
		',',
		array(
			'description',
			'gmt_offset',
			'home',
			'name',
			'site_icon_url',
			'site_logo',
			'timezone_string',
			'url',
			'page_for_posts',
			'page_on_front',
			'show_on_front',
		)
	),
);

$zero_admin_preload_data = array_reduce(
	$zero_admin_preload_paths,
	'rest_preload_api_request',
	array()
);

wp_add_inline_script(
	'wp-api-fetch',
	sprintf(
		'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );',
		wp_json_encode( $zero_admin_preload_data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
	),
	'after'
);

// Build dependencies for the boot module.
$boot_dependencies = array(
	array(
		'import' => 'static',
		'id'     => '@zero-admin/boot',
	),
);


// Add all registered routes as dependencies.
foreach ( zero_admin_get_admin_routes() as $route ) {
	if ( isset( $route['route_module'] ) ) {
		$boot_dependencies[] = array(
			'import' => ! empty( $route['before_load'] ) ? 'static' : 'dynamic',
			'id'     => $route['route_module'],
		);
	}
	if ( isset( $route['content_module'] ) ) {
		$boot_dependencies[] = array(
			'import' => 'dynamic',
			'id'     => $route['content_module'],
		);
	}
}

// Add all registered dashboard widgets as dependencies.
foreach ( zero_admin_get_dashboard_widgets() as $widget ) {
	if ( isset( $widget['widget_module'] ) ) {
		$boot_dependencies[] = array(
			'import' => 'static',
			'id'     => $widget['widget_module'],
		);
	}
	if ( isset( $widget['render_module'] ) ) {
		$boot_dependencies[] = array(
			'import' => 'dynamic',
			'id'     => $widget['render_module'],
		);
	}
}

// Add all registered init modules as dependencies.
foreach ( zero_admin_get_init_modules() as $init_module ) {
	$boot_dependencies[] = array(
		'import' => 'static',
		'id'     => $init_module,
	);
}

// Add all field collection modules as dependencies.
foreach ( zero_admin_get_all_field_collections() as $collection ) {
	if ( isset( $collection['fields_module'] ) ) {
		$boot_dependencies[] = array(
			'import' => 'dynamic',
			'id'     => $collection['fields_module'],
		);
	}
}

// This just an empty script to ensure @zero-admin/boot is added to the import map.
wp_register_script_module(
	'zero-admin-boot',
	ZERO_ADMIN_PLUGIN_URL . 'routes.js',
	$boot_dependencies
);

// Enqueue styles for the WordPress libraries we're using.
wp_enqueue_style( 'wp-components' );
wp_enqueue_style( 'wp-commands' );

// Enqueue the Zero Admin SPA itself.
wp_enqueue_script( 'zero-admin-prerequisites' );
wp_enqueue_script_module( 'zero-admin-boot' );

// Output the admin page.
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Zero Admin</title>
	<style>
		html {
			background: #f1f1f1;
			color: #444;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			font-size: 13px;
			line-height: 1.4em;
		}

		body {
			margin: 0;
		}
	</style>
<?php

global $hook_suffix;

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$hook_suffix = 'zero-admin';

// BEGIN see wp-admin/admin-header.php.

print_admin_styles();
print_head_scripts();

/**
 * Fires in head section for a specific admin page.
 *
 * The dynamic portion of the hook name, `$hook_suffix`, refers to the hook suffix
 * for the admin page.
 *
 * @since 2.1.0
 */
do_action( "admin_head-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

/**
 * Fires in head section for all admin pages.
 *
 * @since 2.1.0
 */
do_action( 'admin_head' );

// END see wp-admin/admin-header.php.

?>
</head>
<body class="<?php echo esc_attr( apply_filters( 'zero_admin_body_class', 'zero-admin' ) ); ?>">
	<div id="zero-admin-app" style="height: 100vh;box-sizing: border-box;"></div>
<?php

// BEGIN see wp-admin/admin-footer.php.

/**
 * Prints scripts or data before the default footer scripts.
 *
 * @since 1.2.0
 *
 * @param string $data The data to print.
 */
do_action( 'admin_footer', '' );

zero_admin_print_import_map();
print_footer_scripts();
wp_script_modules()->print_enqueued_script_modules();
wp_script_modules()->print_script_module_preloads();
wp_script_modules()->print_script_module_data();

/**
 * Prints scripts or data after the default footer scripts.
 *
 * The dynamic portion of the hook name, `$hook_suffix`,
 * refers to the global hook suffix of the current page.
 *
 * @since 2.8.0
 */
do_action( "admin_footer-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

// END see wp-admin/admin-footer.php.

?>
</body>
</html>
