<?php
/**
 * Plugin Name: {{label}} (TypedPress app)
 * Version: {{tpVersion}}+{{schemaHash}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * Requires Plugins: {{requires}}
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
register_activation_hook(__FILE__, static function (): void {
    $plugin = class_exists(\TypedPress\Wp\Runtime::class) ? \TypedPress\Wp\Runtime::app(__DIR__, __FILE__) : null;
    if ($plugin === null) {
        wp_die(esc_html('TypedPress: activate a compatible TypedPress Runtime and the required plugins first.'), 'TypedPress', ['back_link' => true]);
    }
    $plugin->activate();
});
register_deactivation_hook(__FILE__, static function (): void {
    class_exists(\TypedPress\Wp\Hardening::class) && \TypedPress\Wp\Hardening::deactivate();
    class_exists(\TypedPress\Wp\InstallMarker::class) && \TypedPress\Wp\InstallMarker::forget();
});
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\TypedPress\Wp\Runtime::class)) {
        add_action('admin_notices', static fn () => printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('TypedPress: the typedpress-runtime plugin is not active; {{name}} is not running.')));
        return;
    }
    \TypedPress\Wp\Runtime::app(__DIR__, __FILE__);
}, 1);
