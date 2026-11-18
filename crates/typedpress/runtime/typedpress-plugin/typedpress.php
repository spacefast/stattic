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
defined('ABSPATH') || exit;

define('TP_PLUGIN_VERSION', '{{tpVersion}}');
// The plugin's files, by content: `typedpress push` replaces a plugin that isn't the one it carries.
define('TP_PLUGIN_HASH', '{{pluginHash}}');
define('TP_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/src/Trees.php';
require_once __DIR__ . '/src/Mount.php';
require_once __DIR__ . '/src/Requirements.php';
require_once __DIR__ . '/src/Receiver.php';
require_once __DIR__ . '/src/Setup.php';
require_once __DIR__ . '/src/Deployments.php';

// The site's live build mounts once every plugin has loaded, before WordPress initializes. A build
// that can't mount leaves WordPress serving and is reported on the TypedPress screen.
\TypedPressPlugin\Site::register();
\TypedPressPlugin\Mount::run();
\TypedPressPlugin\Receiver::register();
\TypedPressPlugin\Setup::register();
\TypedPressPlugin\Deployments::register();
register_activation_hook(__FILE__, [\TypedPressPlugin\Setup::class, 'activated']);
