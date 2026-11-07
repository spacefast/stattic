<?php
/**
 * Plugin Name: {{label}} (Zero app)
 * Version: {{zeroVersion}}+{{schemaHash}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * Requires Plugins: {{requires}}
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
register_activation_hook(__FILE__, static function (): void {
    $plugin = class_exists(\Zero\Wp\Runtime::class) ? \Zero\Wp\Runtime::app(__DIR__, __FILE__) : null;
    if ($plugin === null) {
        wp_die(esc_html('Zero: activate a compatible Zero Runtime and the required plugins first.'), 'Zero', ['back_link' => true]);
    }
    $plugin->activate();
});
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\Zero\Wp\Runtime::class)) {
        add_action('admin_notices', static fn () => printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('Zero: the zero-runtime plugin is not active; {{name}} is not running.')));
        return;
    }
    \Zero\Wp\Runtime::app(__DIR__, __FILE__);
}, 1);
