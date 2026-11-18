<?php
/**
 * TypedPress Admin SPA
 *
 * This file provides the API for registering admin routes in the TypedPress Admin SPA.
 *
 * @package TypedPress_Admin
 */

// Global storage for registered admin routes.
global $tp_admin_routes;
$tp_admin_routes = array();

/**
 * Register an admin route for the TypedPress Admin SPA.
 *
 * This function registers a route that will be available in the TypedPress Admin interface.
 * Routes consist of a path and either a content module or route configuration script (or both).
 *
 * @since 1.0.0
 *
 * @param string      $path             The route path (e.g., '/settings/general').
 * @param string|null $content_module   The module ID for the route content (JavaScript module). Can be null for route-only configurations.
 * @param string|null $route_module Optional. The script ID for route configuration (loader, beforeLoad, etc.).
 * @param bool        $before_load  Optional. Whether the route module exports a beforeLoad handler that must run at boot.
 * @param array|null  $static_data  Optional. Static route data available at boot.
 * @param int|null    $gc_time      Optional. TanStack Router gcTime (milliseconds) for loader cache eviction.
 *                                  TypedPress Admin-only: not present in Gutenberg's routing framework. Remove when migrating to Gutenberg.
 */
function tp_admin_register_admin_route( $path, $content_module, $route_module = null, $before_load = false, $static_data = null, $gc_time = null ) {
	global $tp_admin_routes;

	if ( empty( $path ) || ( empty( $content_module ) && empty( $route_module ) ) ) {
		_doing_it_wrong( __FUNCTION__, 'Route must have a "path" and at least one of "content_module" or "route_module".', '1.0.0' );
		return;
	}

	$route = array(
		'path' => $path,
	);

	if ( ! empty( $content_module ) ) {
		$route['content_module'] = $content_module;
	}

	if ( ! empty( $route_module ) ) {
		$route['route_module'] = $route_module;
	}

	if ( $before_load ) {
		$route['before_load'] = true;
	}

	if ( ! empty( $static_data ) ) {
		$route['static_data'] = $static_data;
	}

	if ( isset( $gc_time ) ) {
		$route['gc_time'] = $gc_time;
	}

	$tp_admin_routes[] = $route;
}

/**
 * Get all registered admin routes.
 *
 * @return array Array of registered admin routes.
 */
function tp_admin_get_admin_routes() {
	global $tp_admin_routes;
	return $tp_admin_routes;
}
