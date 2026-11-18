<?php
/**
 * First Admin preview functionality.
 *
 * @package First_Admin
 */

/**
 * Check if we are in First Admin site preview mode.
 */
function first_admin_is_site_preview() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	return array_key_exists( 'na_site_preview', $_GET ) && 1 === (int) $_GET['na_site_preview'];
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Hide admin bar when in First Admin site preview mode.
 */
function first_admin_hide_admin_bar() {
	return ! first_admin_is_site_preview();
}
add_action( 'show_admin_bar', 'first_admin_hide_admin_bar', 1 );

/**
 * Make the body inert for First Admin site preview.
 *
 * This is used to prevent interaction with the site preview, which is
 * intended to be read-only. It sets the `inert` attribute on the body element.
 */
function first_admin_make_body_inert() {
	if ( ! first_admin_is_site_preview() ) {
		return;
	}
	if ( doing_action( 'wp_body_open' ) ) {
		remove_action( 'wp_footer', 'first_admin_make_body_inert' );
	}
	echo '<script>if ( document.body ) document.body.inert = true;</script>';
}

// The wp_body_open action was only introduced in WordPress 5.2, and a
// non-negligible number of themes still don't have support, so fallback to
// wp_footer for older themes.
add_action( 'wp_body_open', 'first_admin_make_body_inert' );
add_action( 'wp_footer', 'first_admin_make_body_inert' );
