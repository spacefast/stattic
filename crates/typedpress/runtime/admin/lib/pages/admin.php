<?php
/**
 * TypedPress Admin SPA
 *
 * This file serves as the entry point for the TypedPress Admin SPA.
 *
 * @package TypedPress_Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

set_current_screen();

// Do this action to populate the admin bar menu items. This is needed because the admin bar is not shown in TypedPressAdmin, so its items won't be populated by default.
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

// Run `tp_admin_init`.
do_action( 'tp_admin_init' );

// Always enable the experimental DataViews media modal. The Gutenberg plugin
// is optional here: fall back to the core WordPress version for cache busting.
wp_register_script( 'typedpress-admin-gutenberg-experiments', '', array(), defined( 'GUTENBERG_VERSION' ) ? GUTENBERG_VERSION : get_bloginfo( 'version' ), false );
wp_add_inline_script( 'typedpress-admin-gutenberg-experiments', 'window.__experimentalDataViewsMediaModal = true' );
wp_enqueue_script( 'typedpress-admin-gutenberg-experiments' );

// The boot module dependencies come from the committed module build.
$asset_file = is_file( TP_ADMIN_PLUGIN_DIR . 'build/packages/boot/index.asset.php' )
	? include TP_ADMIN_PLUGIN_DIR . 'build/packages/boot/index.asset.php'
	: array(
		'dependencies' => array(),
		'version'      => TP_ADMIN_VERSION,
	);

// This script serves two purposes:
// 1. It ensures all the globals that are made available to the modules are loaded.
// 2. It initializes the boot module as an inline script.
wp_register_script( 'typedpress-admin-prerequisites', '', $asset_file['dependencies'], $asset_file['version'], true );

// Pass data to script module via global variables.
wp_add_inline_script(
	'typedpress-admin-prerequisites',
	sprintf(
		'import( "@typedpress-admin/boot" ).then( mod => mod.init( %s, %s, %s, %s, %s ) );',
		wp_json_encode( tp_admin_get_admin_routes(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( tp_admin_get_admin_menu_items(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( tp_admin_get_admin_meta_menu_items(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( tp_admin_get_init_modules(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( tp_admin_get_boot_context(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
	)
);

/**
 * Context the page hands the app at boot. Plugins may add keys through the filter.
 *
 * @return array
 */
function tp_admin_get_boot_context() {
	$plugin = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
	return apply_filters(
		'tp_admin_boot_context',
		array_merge( tp_admin_get_dashboard_context(), array(
			'logout_url'               => html_entity_decode( wp_logout_url() ),
			'dashboard_widget_types'   => tp_admin_get_dashboard_widgets(),
			'dashboard_default_layout' => apply_filters( 'tp_admin_dashboard_widgets', array() ),
			'declarative_widgets'      => function_exists( 'tp_admin_get_declarative_widgets' ) ? tp_admin_get_declarative_widgets() : array(),
			'dashboards'               => function_exists( 'tp_admin_get_dashboards' ) ? tp_admin_get_dashboards() : array(),
			'managed'                  => class_exists( '\\TypedPress\\Wp\\Runtime' ) && \TypedPress\Wp\Runtime::managed(),
			'sealed'                   => $plugin !== null && $plugin->schema->sealed() !== null,
			'has_store'                => $plugin !== null && $plugin->schema->commerce() !== null,
			'identity_active'          => class_exists( '\\TypedPress\\Identity\\Plugin' ),
			// Where this admin is mounted and where wp-admin is: a WordPress installed below the
			// origin root (`/blog/`) routes and frames correctly only if the app learns both here.
			'mount_url'                => tp_admin_url( '/' ),
			'admin_url'                => admin_url(),
		) )
	);
}

// Transport comes from registered native types, independently of editor preferences.
$tp_admin_native = array();
foreach ( \TypedPress\Wp\Runtime::plugin()->schema->collections() as $name => $collection ) {
    $type = isset( $collection['wp']['postType'] ) ? get_post_type_object( $collection['wp']['postType'] ) : null;
    if ( $type && $type->show_in_rest ) {
        $tp_admin_native[ $name ] = array( 'postType' => $type->name, 'metaPrefix' => $collection['wp']['metaPrefix'] ?? 'tp_', 'path' => '/' . ( $type->rest_namespace ?: 'wp/v2' ) . '/' . ( $type->rest_base ?: $type->name ) );
    }
}
wp_add_inline_script( 'wp-api-fetch', 'window.tpAdmin = ' . wp_json_encode( array(
    'native' => $tp_admin_native,
    'readable' => tp_admin_navigation(),
    'role' => \TypedPress\Wp\CoreAccess::viewer()->user['role'] ?? 'subscriber',
    'mount' => tp_admin_url(),
    // TypedPress's API (/wp-json/typedpress/v1): the admin's own reads and writes go through it, as every client's do.
    'api' => \TypedPress\Wp\Facade::url(),
), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';', 'after' );

// Preload endpoints used by `getEntityRecord` (entity configs) and by the root route loader.
$tp_admin_preload_paths = array(
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

$tp_admin_preload_data = array_reduce(
	$tp_admin_preload_paths,
	'rest_preload_api_request',
	array()
);

wp_add_inline_script(
	'wp-api-fetch',
	sprintf(
		'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );',
		wp_json_encode( $tp_admin_preload_data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
	),
	'after'
);

// Build dependencies for the boot module.
$boot_dependencies = array(
	array(
		'import' => 'static',
		'id'     => '@typedpress-admin/boot',
	),
);


// Add all registered routes as dependencies.
foreach ( tp_admin_get_admin_routes() as $route ) {
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
foreach ( tp_admin_get_dashboard_widgets() as $widget ) {
    foreach ( array( 'render_module', 'widget_module' ) as $key ) {
        if ( ! empty( $widget[$key] ) ) {
            $boot_dependencies[] = array( 'import' => 'dynamic', 'id' => $widget[$key] );
        }
    }
}

// Add all registered init modules as dependencies.
foreach ( tp_admin_get_init_modules() as $init_module ) {
	$boot_dependencies[] = array(
		'import' => 'static',
		'id'     => $init_module,
	);
}

// Add all field collection modules as dependencies.
foreach ( tp_admin_get_all_field_collections() as $collection ) {
	if ( isset( $collection['fields_module'] ) ) {
		$boot_dependencies[] = array(
			'import' => 'dynamic',
			'id'     => $collection['fields_module'],
		);
	}
}

// This just an empty script to ensure @typedpress-admin/boot is added to the import map.
wp_register_script_module(
	'typedpress-admin-boot',
	TP_ADMIN_PLUGIN_URL . 'routes.js',
	$boot_dependencies
);

// Enqueue styles for the WordPress libraries we're using.
wp_enqueue_style( 'wp-components' );
wp_enqueue_style( 'wp-commands' );

// Enqueue the TypedPress Admin SPA itself.
wp_enqueue_script( 'typedpress-admin-prerequisites' );
wp_enqueue_script_module( 'typedpress-admin-boot' );

// Output the admin page.
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>TypedPress Admin</title>
	<style>
		/* TypedPress: the router starts view transitions on navigation; without the document's opt-in
		   the browser aborts each one with an error. */
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
$hook_suffix = 'typedpress-admin';

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
<body class="<?php echo esc_attr( apply_filters( 'tp_admin_body_class', 'typedpress-admin' ) ); ?>">
	<div id="typedpress-admin-app" style="height: 100vh;box-sizing: border-box;"></div>
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

tp_admin_print_import_map();
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
