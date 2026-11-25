<?php
/**
 * SpacePress Admin SPA
 *
 * This file provides the API for registering admin dashboard widgets in the SpacePress Admin SPA.
 *
 * @package SpacePress_Admin
 */

// Global storage for admin dashboard widgets.
global $sp_admin_dashboard_widgets;
$sp_admin_dashboard_widgets = array();

/**
 * Register a dashboard widget for the SpacePress Admin SPA.
 *
 * @since 1.0.0
 *
 * @param string $name The widget type name (e.g., 'core-weather').
 * @param string $render_module The render module name for the widget.
 * @param string $widget_module The widget metadata module name for the widget.
 */
function sp_admin_register_dashboard_widget( $name, $render_module, $widget_module ) {
	global $sp_admin_dashboard_widgets;

	if ( empty( $name ) || empty( $render_module ) || empty( $widget_module ) ) {
		_doing_it_wrong( __FUNCTION__, 'Dashboard widget must have "name", "render_module", and "widget_module".', '1.0.0' );
		return;
	}

	$sp_admin_dashboard_widgets[] = array(
		'name'          => $name,
		'render_module' => $render_module,
		'widget_module' => $widget_module,
	);
}

/**
 * Get all registered dashboard widgets.
 *
 * @return array Array of registered dashboard widgets.
 */
function sp_admin_get_dashboard_widgets() {
	global $sp_admin_dashboard_widgets;

	/**
	 * Filters the registered widget types for the SpacePress Admin SPA.
	 *
	 * @since 1.0.0
	 *
	 * @param array $widgets Array of widget definitions. Each item has `name`, `render_module`,
	 *                       and `widget_module` keys.
	 */
	return apply_filters( 'sp_admin_registered_widgets', $sp_admin_dashboard_widgets );
}
