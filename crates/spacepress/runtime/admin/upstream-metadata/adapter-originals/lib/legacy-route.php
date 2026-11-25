<?php
/**
 * Override Legacy WP-Admin pages to support First Admin legacy routes.
 *
 * @package FirstAdmin
 */

/**
 * Handle iframe mode - call the parent window's _firstAdminLoad function if
 * present.
 */
function first_admin_handle_iframe_mode() {
	add_action(
		'admin_head',
		function () {
			echo '<script>window.frameElement?._firstAdminLoad?.();</script>';
		}
	);
}
