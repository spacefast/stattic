<?php
/**
 * Override Legacy WP-Admin pages to support Zero Admin legacy routes.
 *
 * @package ZeroAdmin
 */

/**
 * Handle iframe mode - call the parent window's _zeroAdminLoad function if
 * present.
 */
function zero_admin_handle_iframe_mode() {
	add_action(
		'admin_head',
		function () {
			echo '<script>window.frameElement?._zeroAdminLoad?.();</script>';
		}
	);
}
