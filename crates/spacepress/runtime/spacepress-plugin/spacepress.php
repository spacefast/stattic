<?php
/**
 * Plugin Name: SpacePress
 * Description: Runs a SpacePress app on this site. Activating it installs the plugins SpacePress needs; then deploy from a project with `spacepress login` and `spacepress push`.
 * Version: {{spVersion}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace SpacePressPlugin;

defined('ABSPATH') || exit;

define('SP_PLUGIN_VERSION', '{{spVersion}}');
// The plugin's files, by content: `spacepress push` replaces a plugin that isn't the one it carries.
define('SP_PLUGIN_HASH', '{{pluginHash}}');
define('SP_PLUGIN_FILE', __FILE__);

// WordPress invokes these callbacks on separate request paths. Only the live mount is needed
// on an ordinary page. The receiver also defines its Refusal exception in the same file.
spl_autoload_register(static function (string $class): void {
    static $files = [
        'spacepressplugin\\site' => 'Site', 'spacepressplugin\\mount' => 'Mount', 'spacepressplugin\\setup' => 'Setup',
        'spacepressplugin\\receiver' => 'Receiver', 'spacepressplugin\\refusal' => 'Receiver', 'spacepressplugin\\trees' => 'Trees',
        'spacepressplugin\\requirements' => 'Requirements', 'spacepressplugin\\deployments' => 'Deployments', 'spacepressplugin\\legacy' => 'Legacy',
    ];
    $class = strtolower($class);
    if (isset($files[$class])) {
        require_once __DIR__ . '/src/' . $files[$class] . '.php';
    }
});

Legacy::register();
Site::register();
Mount::run();
add_action('admin_init', [Receiver::class, 'authorizeCli'], PHP_INT_MAX);
add_action('wp_create_application_password', [Receiver::class, 'createdPassword'], PHP_INT_MAX, 4);
add_action('rest_api_init', [Receiver::class, 'routes']);
add_action('admin_menu', [Setup::class, 'menu']);
add_action('admin_post_sp_setup', [Setup::class, 'handle']);
add_action('admin_notices', [Setup::class, 'notice']);
add_action('wp_abilities_api_categories_init', [Deployments::class, 'category']);
add_action('wp_abilities_api_init', [Deployments::class, 'abilities']);
add_action('sp_admin_screens', [Deployments::class, 'screen']);
register_activation_hook(__FILE__, [Setup::class, 'activated']);
register_deactivation_hook(__FILE__, static function (): void {
    class_exists(\SpacePress\Wp\PublicFiles::class) && \SpacePress\Wp\PublicFiles::removeSymlinks();
});
