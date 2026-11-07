<?php
/**
 * Plugin Name: Zero Identity
 * Description: Accounts, verified emails, passkeys, MFA and an OpenID Connect issuer for WordPress.
 * Version: 0.3.0
 * Requires at least: 7.1
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: zero-identity
 */
declare(strict_types=1);
defined("ABSPATH") || exit();
require_once __DIR__ . "/vendor-prefixed/autoload.php";
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, "Zero\\Identity\\") && !str_starts_with($class, "Zero\\Identity\\Vendor\\")) {
        $file = __DIR__ . "/src/" . str_replace("\\", "/", substr($class, 14)) . ".php";
        if (is_file($file)) {
            require $file;
        }
    }
});
register_activation_hook(__FILE__, static function (): void {
    global $wpdb;
    \Zero\Identity\Schema::migrate(new \Zero\Identity\DB($wpdb));
    \Zero\Identity\Keys::current();
    if (!wp_next_scheduled("zero_identity_maintenance")) {
        wp_schedule_event(time() + 60, "hourly", "zero_identity_maintenance");
    }
});
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook("zero_identity_maintenance");
    wp_clear_scheduled_hook("zero_identity_password_inventory");
});
\Zero\Identity\Plugin::boot(__FILE__);
