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
// Core only builds `$wp_admin_bar` when the bar is shown; a host that hides it
// still needs the object to collect the menu items this page reads.
global $wp_admin_bar;
if ( ! $wp_admin_bar instanceof WP_Admin_Bar ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	$wp_admin_bar = new WP_Admin_Bar(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	// No add_menus(), like content-admin-api.php: it only adds WordPress.org help links.
	$wp_admin_bar->initialize();
}
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

// Always enable the experimental DataViews media modal. The Gutenberg plugin
// is optional here: fall back to the core WordPress version for cache busting.
wp_register_script( 'zero-admin-gutenberg-experiments', '', array(), defined( 'GUTENBERG_VERSION' ) ? GUTENBERG_VERSION : get_bloginfo( 'version' ), false );
wp_add_inline_script( 'zero-admin-gutenberg-experiments', 'window.__experimentalDataViewsMediaModal = true' );
wp_enqueue_script( 'zero-admin-gutenberg-experiments' );

// The boot module dependencies come from the committed module build.
$asset_file = is_file( ZERO_ADMIN_PLUGIN_DIR . 'build/packages/boot/index.asset.php' )
	? include ZERO_ADMIN_PLUGIN_DIR . 'build/packages/boot/index.asset.php'
	: array(
		'dependencies' => array(),
		'version'      => ZERO_ADMIN_VERSION,
	);

// This script serves two purposes:
// 1. It ensures all the globals that are made available to the modules are loaded.
// 2. It initializes the boot module as an inline script.
wp_register_script( 'zero-admin-prerequisites', '', $asset_file['dependencies'], $asset_file['version'], true );

// Pass data to script module via global variables.
wp_add_inline_script(
	'zero-admin-prerequisites',
	sprintf(
		'import( "@zero-admin/boot" ).then( mod => mod.init( %s, %s, %s, %s, %s ) );',
		wp_json_encode( zero_admin_get_admin_routes(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_admin_menu_items(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_admin_meta_menu_items(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_init_modules(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( zero_admin_get_boot_context(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
	)
);

/**
 * Context the page hands the app at boot. Plugins may add keys through the filter.
 *
 * @return array
 */
function zero_admin_get_boot_context() {
	return apply_filters(
		'zero_admin_boot_context',
		array_merge( zero_admin_get_dashboard_context(), array(
			'logout_url' => html_entity_decode( wp_logout_url() ),
			'dashboard_widget_types' => zero_admin_get_dashboard_widgets(),
			'dashboard_default_layout' => apply_filters( 'zero_admin_dashboard_widgets', array() ),
			// Where this admin is mounted and where wp-admin is: a WordPress installed below the
			// origin root (`/blog/`) routes and frames correctly only if the app learns both here.
			'mount_url'  => zero_admin_url( '/' ),
			'admin_url'  => admin_url(),
		) )
	);
}

// Transport comes from registered native types, independently of editor preferences.
$zero_admin_native = array();
foreach ( \Zero\Wp\Runtime::plugin()->schema->collections() as $name => $collection ) {
    $type = isset( $collection['wp']['postType'] ) ? get_post_type_object( $collection['wp']['postType'] ) : null;
    if ( $type && $type->show_in_rest ) {
        $zero_admin_native[ $name ] = array( 'postType' => $type->name, 'metaPrefix' => $collection['wp']['metaPrefix'] ?? 'zero_', 'path' => '/' . ( $type->rest_namespace ?: 'wp/v2' ) . '/' . ( $type->rest_base ?: $type->name ) );
    }
}
wp_add_inline_script( 'wp-api-fetch', 'window.zeroAdmin = ' . wp_json_encode( array(
    'native' => $zero_admin_native,
    'readable' => zero_admin_navigation(),
    'role' => \Zero\Wp\CoreAccess::viewer()->user['role'] ?? 'subscriber',
    'mount' => zero_admin_url(),
), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';', 'after' );

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

// Native widgets are lazy script modules and need entries in the import map.
foreach ( zero_admin_get_dashboard_widgets() as $widget ) {
    foreach ( array( 'render_module', 'widget_module' ) as $key ) {
        if ( ! empty( $widget[$key] ) ) {
            $boot_dependencies[] = array( 'import' => 'dynamic', 'id' => $widget[$key] );
        }
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
	<title>Zero</title>
	<style>
		@media (prefers-reduced-motion: no-preference) {
			@view-transition {
				navigation: auto;
			}
		}

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
