<?php
/**
 * WP-Admin polyfills.
 *
 * Provides server-side polyfills for behaviors that wp-admin normally handles
 * during classic page loads, but which do not fire in a JavaScript SPA context or organically.
 * These ensure parity when actions are performed via REST or other async flows.
 *
 * @package Zero_Admin
 * @since 1.0.0
 */

/**
 * Handles the activated_plugin action to maintain the recently_activated option.
 *
 * When a plugin is activated, it should be removed from the recently_activated
 * list (both on single site and on Multisite network admin when applicable),
 * mirroring the behavior in wp-admin/plugins.php. This ensures the REST-driven
 * flows keep parity with classic admin flows.
 *
 * @since 1.0.0
 *
 * @param string $plugin        Path to the main plugin file from the plugins directory.
 * @param bool   $network_wide  Whether the plugin is being activated for all sites in the network.
 *                              This parameter is only meaningful on Multisite.
 * @return void
 */
function zero_admin_handle_activated_plugin( $plugin, $network_wide ) {
	// When a plugin is activated, remove it from the recently_activated list.
	if ( is_multisite() && $network_wide ) {
		$recent = (array) get_site_option( 'recently_activated' );
		unset( $recent[ $plugin ] );
		update_site_option( 'recently_activated', $recent );
	} else {
		$recent = (array) get_option( 'recently_activated' );
		unset( $recent[ $plugin ] );
		update_option( 'recently_activated', $recent, false );
	}
}
add_action( 'activated_plugin', 'zero_admin_handle_activated_plugin', 10, 2 );

/**
 * Handles the deactivated_plugin action to maintain the recently_activated option.
 *
 * When a plugin is deactivated, it should be added (or updated) in the
 * recently_activated list with the current timestamp, both on single site and
 * Multisite when deactivated network-wide. This mirrors wp-admin/plugins.php
 * behavior so that REST-driven flows remain consistent.
 *
 * @since 1.0.0
 *
 * @param string $plugin                 Path to the main plugin file from the plugins directory.
 * @param bool   $network_deactivating   Whether the plugin is being deactivated for all sites in the network.
 *                                       This parameter is only meaningful on Multisite.
 * @return void
 */
function zero_admin_handle_deactivated_plugin( $plugin, $network_deactivating ) {
	// When a plugin is deactivated, add/update its timestamp in recently_activated.
	if ( is_multisite() && $network_deactivating ) {
		update_site_option( 'recently_activated', array( $plugin => time() ) + (array) get_site_option( 'recently_activated' ) );
	} else {
		update_option( 'recently_activated', array( $plugin => time() ) + (array) get_option( 'recently_activated' ), false );
	}
}
add_action( 'deactivated_plugin', 'zero_admin_handle_deactivated_plugin', 10, 2 );
