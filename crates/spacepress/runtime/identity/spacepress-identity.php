<?php
/**
 * Plugin Name: SpacePress Identity
 * Description: Accounts, verified emails, passkeys, MFA and an OpenID Connect issuer for WordPress.
 * Version: 0.3.0
 * Requires at least: 7.1
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: spacepress-identity
 */
declare(strict_types=1);
defined("ABSPATH") || exit();
require __DIR__ . '/src/autoload.php';
register_activation_hook(__FILE__, static function (): void {
    // On a site still under the pre-release name the runtime moves identity's tables first, then installs.
    if (!\SpacePress\Identity\Plugin::storageCurrent()) {
        return;
    }
    global $wpdb;
    \SpacePress\Identity\Schema::migrate(new \SpacePress\Identity\DB($wpdb));
    \SpacePress\Identity\Keys::current();
    if (!wp_next_scheduled("sp_identity_maintenance")) {
        wp_schedule_event(time() + 60, "hourly", "sp_identity_maintenance");
    }
});
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook("sp_identity_maintenance");
    wp_clear_scheduled_hook("sp_identity_password_inventory");
});
\SpacePress\Identity\Plugin::boot(__FILE__);
