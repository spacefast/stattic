<?php
/**
 * Plugin Name: TypedPress Admin
 * Description: The default admin for TypedPress apps: WordPress and TypedPress's collections, tables, media and globals at /wp-admin/new/. Classic wp-admin stays available.
 * Version: {{tpVersion}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * Requires Plugins: typedpress-runtime
 * License: GPL-2.0-or-later
 * Text Domain: typedpress-admin
 *
 * @package TypedPress_Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TP_ADMIN_VERSION', '{{tpVersion}}' );
define( 'TP_ADMIN_FILE', __FILE__ );
// The upstream mount: `/wp-admin/new/<route>` is wp-admin's `admin.php?page=typedpress-admin`.
define( 'TP_ADMIN_REWRITE_REGEX', 'wp-admin/new(/.*)?$' );
define( 'TP_ADMIN_REWRITE_OPTION', 'tp_admin_rewrite' );

register_activation_hook( __FILE__, 'tp_admin_activate' );
register_deactivation_hook( __FILE__, 'tp_admin_deactivate' );
// The app plugin registers itself with the runtime at plugins_loaded priority 1; decide after it.
add_action( 'plugins_loaded', 'tp_admin_boot', 5 );

/**
 * Register the admin only when a TypedPress app is active; otherwise say so once and do nothing.
 */
function tp_admin_boot() {
	if ( ! tp_admin_has_app() ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'TypedPress Admin: no TypedPress app is active on this site, so the admin is not available.', 'typedpress-admin' ) . '</p></div>';
			}
		);
		return;
	}

	require_once __DIR__ . '/lib/load-libs.php';

	add_action( 'init', 'tp_admin_add_rewrite_rule' );
	add_filter( 'mod_rewrite_rules', 'tp_admin_remove_qsa_flag' );
	add_action( 'wp_loaded', 'tp_admin_persist_rewrite_rule' );
	add_filter( 'do_parse_request', 'tp_admin_front_request', -10 );
	add_action( 'admin_menu', 'tp_admin_menu_page' );
	add_action( 'admin_init', 'tp_admin_render_page' );
	add_action( 'admin_init', 'tp_admin_handle_iframe_mode' );
	add_action( 'admin_init', 'tp_admin_redirect_classic_page', 1 );
	add_filter( 'login_redirect', 'tp_admin_login_redirect', 10, 3 );
	add_filter( 'plugin_row_meta', 'tp_admin_plugin_meta_links', 10, 2 );
}

/**
 * The upstream rule. It points outside index.php, so WordPress writes it to `.htaccess` (Apache);
 * a server that never reads it sends the URL to index.php, where tp_admin_front_request() answers.
 */
function tp_admin_add_rewrite_rule() {
	add_rewrite_rule( TP_ADMIN_REWRITE_REGEX, 'wp-admin/admin.php?page=typedpress-admin', 'top' );
}

/**
 * The route is in the path; the substitution's own query must not be merged with the request's.
 *
 * @param string $rules The rewrite rules.
 * @return string
 */
function tp_admin_remove_qsa_flag( $rules ) {
	return preg_replace( '/^(RewriteRule.*wp-admin\/new.*)\[QSA,L\]$/m', '$1[L]', $rules );
}

/**
 * Write the rule into the server's rewrite file, once per version, on the first web request after
 * activation or an update. WP-CLI never writes `.htaccess`, so it leaves this to that request.
 * WordPress writes the file when it runs under Apache with mod_rewrite and the file is writable;
 * elsewhere the pretty URL takes the redirect in tp_admin_front_request().
 */
function tp_admin_persist_rewrite_rule() {
	// A build mounted from the store writes the rule when the host initializes it, never on a request.
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || \TypedPress\Wp\Runtime::managed() || get_option( TP_ADMIN_REWRITE_OPTION ) === TP_ADMIN_VERSION ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	tp_admin_add_rewrite_rule();
	flush_rewrite_rules( true );
	update_option( TP_ADMIN_REWRITE_OPTION, TP_ADMIN_VERSION, true );
	$GLOBALS['tp_admin_rule_written'] = true;
}

/**
 * Whether the rule is recorded as written for this version of TypedPress Admin (read without writing).
 *
 * @return bool
 */
function tp_admin_rewrite_recorded() {
	return get_option( TP_ADMIN_REWRITE_OPTION ) === TP_ADMIN_VERSION;
}

/**
 * Records the rule as written for this version, after the caller flushed the rules with it registered.
 */
function tp_admin_record_rewrite() {
	update_option( TP_ADMIN_REWRITE_OPTION, TP_ADMIN_VERSION, true );
}

/**
 * Activation from wp-admin is a web request: write the rule now. From WP-CLI, the next web request does.
 */
function tp_admin_activate() {
	if ( ! tp_admin_has_app() ) {
		return;
	}
	// Activating from the Plugins screen includes this file after plugins_loaded, so
	// tp_admin_boot() has not run in this request: the rule's filter must be added here too.
	add_filter( 'mod_rewrite_rules', 'tp_admin_remove_qsa_flag' );
	delete_option( TP_ADMIN_REWRITE_OPTION );
	tp_admin_persist_rewrite_rule();
}

/**
 * Deactivation runs with the rule registered in this request: remove it, then rewrite the file.
 */
function tp_admin_deactivate() {
	global $wp_rewrite;
	if ( $wp_rewrite instanceof WP_Rewrite ) {
		unset( $wp_rewrite->non_wp_rules[ TP_ADMIN_REWRITE_REGEX ] );
	}
	flush_rewrite_rules( true );
	delete_option( TP_ADMIN_REWRITE_OPTION );
}

/**
 * The URL of an admin route: the upstream pretty form, `/wp-admin/new/<route>`.
 *
 * @param string $path Route path. Default '/'.
 * @return string
 */
function tp_admin_url( $path = '/' ) {
	return admin_url( 'new/' . ltrim( $path, '/' ) );
}

/**
 * The same route in the form every server serves: wp-admin's `admin.php?page=typedpress-admin&p=<route>`.
 *
 * @param string $path Route path. Default '/'.
 * @return string
 */
function tp_admin_query_url( $path = '/' ) {
	return add_query_arg( 'p', rawurlencode( '/' . ltrim( $path, '/' ) ), admin_url( 'admin.php?page=typedpress-admin' ) );
}

/**
 * True when a TypedPress app is registered with the runtime; the admin has nothing to show otherwise.
 *
 * @return bool
 */
function tp_admin_has_app() {
	return class_exists( '\\TypedPress\\Wp\\Runtime' ) && \TypedPress\Wp\Runtime::plugin() !== null;
}

/**
 * The part of the request path below a mount (`/wp-admin/new`), or null when it isn't under it.
 *
 * @param string $path  Request path.
 * @param string $mount Mount URL.
 * @return string|null
 */
function tp_admin_route_below( $path, $mount ) {
	$base = untrailingslashit( (string) wp_parse_url( $mount, PHP_URL_PATH ) );
	if ( $path === $base || str_starts_with( $path, $base . '/' ) ) {
		return '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
	}
	return null;
}

/**
 * Requests for the admin that reached index.php: the old `/typedpress-admin/<route>` (a permanent redirect
 * to the mount), and `/wp-admin/new/<route>` on a server that does not apply the rule (one that
 * ignores `.htaccess`): there the route takes wp-admin's query form, which every server serves.
 *
 * @param bool $parse Whether WordPress should parse the request.
 * @return bool
 */
function tp_admin_front_request( $parse ) {
	$uri   = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never printed.
	$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
	$old   = tp_admin_route_below( $path, home_url( '/typedpress-admin' ) );
	if ( null !== $old ) {
		wp_safe_redirect( tp_admin_url( $old ) . ( '' !== $query ? '?' . $query : '' ), 301 );
		exit;
	}
	$route = tp_admin_route_below( $path, tp_admin_url( '/' ) );
	if ( null === $route ) {
		return $parse;
	}
	nocache_headers();
	// This request wrote the rule: the server serves the pretty URL from now on.
	if ( ! empty( $GLOBALS['tp_admin_rule_written'] ) && got_mod_rewrite() ) {
		wp_safe_redirect( tp_admin_url( $route ) . ( '' !== $query ? '?' . $query : '' ), 302 );
		exit;
	}
	// The route's own query travels inside `p`, as the router reads it: beside it, its `page` would
	// replace wp-admin's `page=typedpress-admin`.
	wp_safe_redirect( tp_admin_query_url( $route . ( '' !== $query ? '?' . $query : '' ) ), 302 );
	exit;
}

/**
 * Serve the app for `admin.php?page=typedpress-admin` (and `/wp-admin/new/`, which the rule maps there).
 * admin.php has already signed the visitor in (auth_redirect) and built wp-admin's menus.
 */
function tp_admin_render_page() {
	if ( 'typedpress-admin' !== ( $_GET['page'] ?? '' ) || wp_doing_ajax() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page render, no state change.
		return;
	}
	$request = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never printed.
	$below   = tp_admin_route_below( (string) wp_parse_url( $request, PHP_URL_PATH ), tp_admin_url( '/' ) );
	$pretty  = null !== $below;
	// A route that was renamed (`/legacy/<slug>` is `/classic/<slug>`) answers with its new address.
	$route   = $pretty ? $below : ( is_string( $_GET['p'] ?? null ) ? wp_unslash( $_GET['p'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, then redirected through wp_safe_redirect.
	$renamed = null === $route ? null : tp_admin_renamed_route( $route );
	if ( null !== $renamed ) {
		$query = $pretty ? (string) wp_parse_url( $request, PHP_URL_QUERY ) : '';
		wp_safe_redirect( $pretty ? tp_admin_url( $renamed ) . ( '' !== $query ? '?' . $query : '' ) : tp_admin_query_url( $renamed ), 301 );
		exit;
	}
	// The menu entry has no route: send it to the mount, like upstream.
	if ( ! $pretty && ! is_string( $_GET['p'] ?? null ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( tp_admin_url( '/' ) );
		exit;
	}
	if ( ! current_user_can( 'read' ) || \TypedPress\Wp\CoreAccess::viewer() === null ) {
		wp_die( esc_html__( 'You cannot access TypedPress Admin.', 'typedpress-admin' ), '', array( 'response' => 403 ) );
	}
	nocache_headers();
	require plugin_dir_path( __FILE__ ) . 'lib/pages/admin.php';
	exit;
}

/**
 * After login, WordPress's default destination (wp-admin's index) becomes this admin. A destination
 * the visitor asked for is kept, except the classic dashboard itself, which is the default's own
 * URL; classic wp-admin stays one click away.
 *
 * @param string           $redirect_to The destination.
 * @param string           $requested   The requested destination.
 * @param WP_User|WP_Error $user        The user who signed in.
 * @return string
 */
function tp_admin_login_redirect( $redirect_to, $requested, $user ) {
	if ( ! $user instanceof WP_User || ! $user->has_cap( 'read' ) ) {
		return $redirect_to;
	}
	// WordPress's own default is `/wp-admin/`, and its login form posts it as `redirect_to` even when
	// nobody asked for a page, so `/wp-admin/` itself can't be told apart from the default: it lands
	// here. Every other page a visitor asked for is kept.
	if ( in_array( $redirect_to, array( admin_url(), 'wp-admin/' ), true ) ) {
		return tp_admin_url( '/' );
	}
	return $redirect_to;
}

/**
 * The wp-admin menu entry that opens this admin.
 */
function tp_admin_menu_page() {
	add_menu_page( __( 'TypedPress', 'typedpress-admin' ), __( 'TypedPress', 'typedpress-admin' ), 'read', 'typedpress-admin', '__return_null', 'dashicons-dashboard' );
}

/**
 * @param string[] $links Plugin metadata links.
 * @param string   $file  Plugin file.
 * @return string[]
 */
function tp_admin_plugin_meta_links( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="' . esc_url( tp_admin_url( '/' ) ) . '">' . esc_html__( 'Open TypedPress Admin', 'typedpress-admin' ) . '</a>';
	}
	return $links;
}
