<?php
/**
 * Next Admin SPA
 *
 * This file provides the API for setting up the dashboard widgets layout
 * in the Next Admin SPA.
 *
 * @package Zero_Admin
 */

/**
 * Extend persisted preferences with third-party widgets.
 *
 * This function intercepts calls to get_user_meta for persisted_preferences
 * and dynamically adds third-party widgets through the filter.
 *
 * @param mixed  $pre      - The value to return.
 * @param int    $user_id  - User ID.
 * @param string $meta_key - Meta key.
 * @return mixed The extended value with third-party widgets.
 */
function zero_admin_extend_user_metadata_layout( $pre, $user_id, $meta_key ) {
	global $wpdb;

	$expected_key = $wpdb->get_blog_prefix() . 'persisted_preferences';
	if ( $meta_key !== $expected_key ) {
		return $pre;
	}

	// Avoid recursion when reading the user meta.
	remove_filter( 'get_user_metadata', __FUNCTION__, 99 );
	$base = get_user_meta( $user_id, $meta_key, true );
	add_filter( 'get_user_metadata', __FUNCTION__, 99, 3 );

	if ( ! is_array( $base ) ) {
		$base = array();
	}

	if (
		! isset( $base['core/admin']['dashboardLayout'] ) ||
		! is_array( $base['core/admin']['dashboardLayout'] )
	) {
		$base['core/admin']['dashboardLayout'] = array();
	}

	$committed = $base['core/admin']['dashboardLayout'];

	/**
	 * Filter the dashboard widgets layout.
	 *
	 * @since 1.0.0
	 *
	 * @param array $committed - Current widgets from user preferences.
	 * @return array Modified widgets with inStage flag.
	 */
	$extended = apply_filters( 'zero_admin_dashboard_widgets', $committed );

	$base['core/admin']['dashboardLayout'] = $extended;

	return array( $base );
}

add_filter( 'get_user_metadata', 'zero_admin_extend_user_metadata_layout', 99, 3 );
