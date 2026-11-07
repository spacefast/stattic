<?php
/**
 * Dashboard context API - Allows plugins to provide context to the dashboard route loader.
 *
 * @package ZeroAdmin
 */

/**
 * Get dashboard context data passed to JavaScript.
 *
 * Plugins can use the 'zero_admin_dashboard_context' filter to add custom
 * context that will be available in the dashboard route loader. This allows
 * plugins to override the dashboard or provide additional state without
 * modifying core zero-admin code.
 *
 * @return array Dashboard context data.
 */
function zero_admin_get_dashboard_context() {
	$context = array(
		'logout_url' => html_entity_decode( wp_logout_url() ),
	);

	/**
	 * Filter dashboard context before passing to JavaScript.
	 *
	 * Plugins can add custom context here that will be available in the
	 * dashboard route loader. Common use cases:
	 *
	 * - Override the entire dashboard with a custom component
	 * - Provide state flags that affect dashboard rendering
	 * - Pass configuration data to dashboard widgets
	 *
	 * Example:
	 *
	 *     add_filter( 'zero_admin_dashboard_context', function( $context ) {
	 *         $context['overrideComponent'] = '@my-plugin/custom-dashboard';
	 *         return $context;
	 *     } );
	 *
	 * @param array $context Dashboard context data.
	 */
	return apply_filters( 'zero_admin_dashboard_context', $context );
}
