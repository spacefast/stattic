<?php
/**
 * Plugin Name: TypedPress
 * Description: Runs a TypedPress app on this site. Activating it installs the plugins TypedPress needs; then deploy from a project with `typedpress login` and `typedpress push`.
 * Version: {{tpVersion}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace TypedPressPlugin;

defined('ABSPATH') || exit;

define('TP_PLUGIN_VERSION', '{{tpVersion}}');
// The plugin's files, by content: `typedpress push` replaces a plugin that isn't the one it carries.
define('TP_PLUGIN_HASH', '{{pluginHash}}');
define('TP_PLUGIN_FILE', __FILE__);

// WordPress invokes these callbacks on separate request paths. Only the live mount is needed
// on an ordinary page. The receiver also defines its Refusal exception in the same file.
spl_autoload_register(static function (string $class): void {
    static $files = [
        'typedpressplugin\\site' => 'Site', 'typedpressplugin\\mount' => 'Mount', 'typedpressplugin\\setup' => 'Setup',
        'typedpressplugin\\receiver' => 'Receiver', 'typedpressplugin\\refusal' => 'Receiver', 'typedpressplugin\\trees' => 'Trees',
        'typedpressplugin\\requirements' => 'Requirements', 'typedpressplugin\\deployments' => 'Deployments',
    ];
    $class = strtolower($class);
    if (isset($files[$class])) {
        require_once __DIR__ . '/src/' . $files[$class] . '.php';
    }
});

Site::register();
Mount::run();
add_action('admin_init', [Receiver::class, 'authorizeCli'], PHP_INT_MAX);
add_action('wp_create_application_password', [Receiver::class, 'createdPassword'], PHP_INT_MAX, 4);
add_action('rest_api_init', [Receiver::class, 'routes']);
add_action('admin_menu', [Setup::class, 'menu']);
add_action('admin_post_tp_setup', [Setup::class, 'handle']);
add_action('admin_notices', [Setup::class, 'notice']);
add_action('wp_abilities_api_categories_init', [Deployments::class, 'category']);
add_action('wp_abilities_api_init', [Deployments::class, 'abilities']);
add_action('tp_admin_screens', [Deployments::class, 'screen']);
register_activation_hook(__FILE__, [Setup::class, 'activated']);
register_deactivation_hook(__FILE__, static function (): void {
    class_exists(\TypedPress\Wp\PublicFiles::class) && \TypedPress\Wp\PublicFiles::removeSymlinks();
});
