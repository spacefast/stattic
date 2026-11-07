<?php
/**
 * Plugin Name: Zero Admin
 * Description: WordPress admin and Zero collections, tables, media and globals in one optional interface.
 * Version: {{zeroVersion}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * Requires Plugins: zero-runtime
 * License: GPL-2.0-or-later
 * Text Domain: zero-admin
 *
 * @package Zero_Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZERO_ADMIN_VERSION', '{{zeroVersion}}' );
define( 'ZERO_ADMIN_FILE', __FILE__ );
define( 'ZERO_ADMIN_REWRITE_REGEX', 'zero-admin(?:/.*)?$' );
define( 'ZERO_ADMIN_REWRITE_OPTION', 'zero_admin_rewrite' );

register_activation_hook( __FILE__, 'zero_admin_activate' );
register_deactivation_hook( __FILE__, 'zero_admin_deactivate' );
// The app plugin registers itself with the runtime at plugins_loaded priority 1; decide after it.
add_action( 'plugins_loaded', 'zero_admin_boot', 5 );

/**
 * Register the admin only when a Zero app is active; otherwise say so once and do nothing.
 */
function zero_admin_boot() {
	if ( ! zero_admin_has_app() ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Zero Admin: no Zero app is active on this site, so the admin is not available.', 'zero-admin' ) . '</p></div>';
			}
		);
		return;
	}

	require_once __DIR__ . '/lib/load-libs.php';

	add_action( 'init', 'zero_admin_add_rewrite_rule' );
	add_filter( 'rest_request_before_callbacks', 'zero_admin_rest_transport_query', 10, 3 );
	add_action( 'admin_init', 'zero_admin_persist_rewrite_rule' );
	add_filter( 'query_vars', 'zero_admin_query_vars' );
	add_action( 'template_redirect', 'zero_admin_render_page', 0 );
	add_action( 'admin_menu', 'zero_admin_menu_page' );
	add_action( 'admin_init', 'zero_admin_menu_redirect' );
	add_filter( 'plugin_row_meta', 'zero_admin_plugin_meta_links', 10, 2 );
	add_action( 'admin_init', 'zero_admin_handle_iframe_mode' );
}

/**
 * The one rewrite rule: everything under /zero-admin/ is the app.
 */
function zero_admin_add_rewrite_rule() {
	add_rewrite_rule( ZERO_ADMIN_REWRITE_REGEX, 'index.php?zero_admin=1', 'top' );
}

/**
 * Activation runs after `init` already fired for this request: register, then persist.
 */
function zero_admin_activate() {
	if ( ! zero_admin_has_app() ) {
		return;
	}
	zero_admin_add_rewrite_rule();
	flush_rewrite_rules();
	update_option( ZERO_ADMIN_REWRITE_OPTION, ZERO_ADMIN_VERSION, false );
}

/**
 * Deactivation runs with the rule registered in this request: remove it, then persist.
 */
function zero_admin_deactivate() {
	global $wp_rewrite;
	if ( $wp_rewrite instanceof WP_Rewrite && isset( $wp_rewrite->extra_rules_top[ ZERO_ADMIN_REWRITE_REGEX ] ) ) {
		unset( $wp_rewrite->extra_rules_top[ ZERO_ADMIN_REWRITE_REGEX ] );
	}
	flush_rewrite_rules();
	delete_option( ZERO_ADMIN_REWRITE_OPTION );
}

/**
 * A plugin updated by copying files in has no activation; persist the rule once per version.
 */
function zero_admin_persist_rewrite_rule() {
	if ( get_option( ZERO_ADMIN_REWRITE_OPTION ) === ZERO_ADMIN_VERSION ) {
		return;
	}
	zero_admin_add_rewrite_rule();
	flush_rewrite_rules();
	update_option( ZERO_ADMIN_REWRITE_OPTION, ZERO_ADMIN_VERSION, false );
}

/**
 * @param string[] $vars Public query variables.
 * @return string[]
 */
function zero_admin_query_vars( $vars ) {
	$vars[] = 'zero_admin';
	return $vars;
}

/**
 * The URL of an admin route.
 *
 * @param string $path Route path. Default '/'.
 * @return string
 */
function zero_admin_url( $path = '/' ) {
	return site_url( '/zero-admin/' . ltrim( $path, '/' ) );
}

/**
 * True when a Zero app is registered with the runtime; the admin has nothing to show otherwise.
 *
 * @return bool
 */
function zero_admin_has_app() {
	return class_exists( '\\Zero\\Wp\\Runtime' ) && \Zero\Wp\Runtime::plugin() !== null;
}

/**
 * Serve the app for /zero-admin/ (signed-in users only).
 */
function zero_admin_render_page() {
	if ( '1' !== get_query_var( 'zero_admin' ) ) {
		return;
	}
	$request = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
	$mount = wp_parse_url( zero_admin_url(), PHP_URL_PATH );
	$return_url = zero_admin_url( str_starts_with( $request, $mount ) ? substr( $request, strlen( $mount ) ) : '/' );
	// This is a frontend page: WordPress authenticates it with the logged-in
	// session cookie, whose scope includes the site (not the wp-admin cookie).
	if ( force_ssl_admin() && ! is_ssl() ) {
		wp_safe_redirect( set_url_scheme( $return_url, 'https' ) );
		exit;
	}
	if ( ! is_user_logged_in() ) {
		nocache_headers();
		wp_safe_redirect( wp_login_url( $return_url ) );
		exit;
	}
	if ( ! current_user_can( 'read' ) || \Zero\Wp\CoreAccess::viewer() === null ) {
		wp_die( esc_html__( 'You cannot access Zero Admin.', 'zero-admin' ), '', array( 'response' => 403 ) );
	}
	nocache_headers();
	if ( ! function_exists( 'set_current_screen' ) ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}
	require plugin_dir_path( __FILE__ ) . 'lib/pages/admin.php';
	exit;
}

/**
 * One wp-admin menu entry that opens the app.
 */
function zero_admin_menu_page() {
	add_menu_page( __( 'Zero', 'zero-admin' ), __( 'Zero', 'zero-admin' ), 'read', 'zero-admin', '__return_null', 'dashicons-dashboard' );
}

/**
 * The menu entry is a redirect, not a page.
 */
function zero_admin_menu_redirect() {
	if ( 'zero-admin' !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a redirect, no state change.
		return;
	}
	$route = is_string( $_GET['p'] ?? null ) ? wp_unslash( $_GET['p'] ) : '/'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( zero_admin_url( $route ), 302 );
	exit;
}

/**
 * @param string[] $links Plugin metadata links.
 * @param string   $file  Plugin file.
 * @return string[]
 */
function zero_admin_plugin_meta_links( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="' . esc_url( zero_admin_url( '/' ) ) . '">' . esc_html__( 'Open Zero Admin', 'zero-admin' ) . '</a>';
	}
	return $links;
}

/**
 * apiFetch adds WordPress's locale hint to every request. WordPress has consumed
 * it before callbacks run; it is transport metadata, not a Zero list option.
 */
function zero_admin_rest_transport_query( $response, $handler, $request ) {
    if ( str_starts_with( $request->get_route(), '/zero/v1/' ) ) {
        $query = $request->get_query_params();
        unset( $query['_locale'] );
        $request->set_query_params( $query );
    }
    return $response;
}
