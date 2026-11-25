<?php
/**
 * SpacePress Admin preview functionality.
 *
 * @package SpacePress_Admin
 */

/**
 * Check if we are in SpacePress Admin site preview mode.
 */
function sp_admin_is_site_preview() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	return array_key_exists( 'na_site_preview', $_GET ) && 1 === (int) $_GET['na_site_preview'];
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Hide admin bar when in SpacePress Admin site preview mode.
 *
 * SpacePress: otherwise keep WordPress's own answer. Upstream returned true outside the preview, which
 * showed the admin bar (and loaded its scripts) to anonymous visitors.
 *
 * @param bool $show Whether WordPress would show the admin bar.
 * @return bool
 */
function sp_admin_hide_admin_bar( $show ) {
	return $show && ! sp_admin_is_site_preview();
}
add_filter( 'show_admin_bar', 'sp_admin_hide_admin_bar', 1 );

/**
 * Make the body inert for SpacePress Admin site preview.
 *
 * This is used to prevent interaction with the site preview, which is
 * intended to be read-only. It sets the `inert` attribute on the body element.
 */
function sp_admin_make_body_inert() {
	if ( ! sp_admin_is_site_preview() ) {
		return;
	}
	if ( doing_action( 'wp_body_open' ) ) {
		remove_action( 'wp_footer', 'sp_admin_make_body_inert' );
	}
	echo '<script>if ( document.body ) document.body.inert = true;</script>';
}

// The wp_body_open action was only introduced in WordPress 5.2, and a
// non-negligible number of themes still don't have support, so fallback to
// wp_footer for older themes.
add_action( 'wp_body_open', 'sp_admin_make_body_inert' );
add_action( 'wp_footer', 'sp_admin_make_body_inert' );
