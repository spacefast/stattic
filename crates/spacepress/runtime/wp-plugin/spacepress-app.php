<?php
/**
 * Plugin Name: {{label}} (SpacePress app)
 * Version: {{spVersion}}+{{schemaHash}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * Requires Plugins: {{requires}}
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
register_activation_hook(__FILE__, static function (): void {
    $plugin = class_exists(\SpacePress\Wp\Runtime::class) ? \SpacePress\Wp\Runtime::app(__DIR__, __FILE__) : null;
    if ($plugin === null) {
        wp_die(esc_html('SpacePress: activate a compatible SpacePress Runtime and the required plugins first.'), 'SpacePress', ['back_link' => true]);
    }
    $plugin->activate();
});
register_deactivation_hook(__FILE__, static function (): void {
    class_exists(\SpacePress\Wp\Hardening::class) && \SpacePress\Wp\Hardening::deactivate();
    class_exists(\SpacePress\Wp\InstallMarker::class) && \SpacePress\Wp\InstallMarker::forget();
    class_exists(\SpacePress\Wp\PublicFiles::class) && \SpacePress\Wp\PublicFiles::removeSymlinks();
});
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\SpacePress\Wp\Runtime::class)) {
        add_action('admin_notices', static fn () => printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('SpacePress: the spacepress-runtime plugin is not active; {{name}} is not running.')));
        return;
    }
    \SpacePress\Wp\Runtime::app(__DIR__, __FILE__);
}, 1);
