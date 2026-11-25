<?php
/**
 * Plugin Name: SpacePress Runtime
 * Version: {{spVersion}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * Requires Plugins: {{runtimeRequires}}
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
// The runtime's files, by content: what a prebuilt page was rendered with (SpacePress\Render\Prebuilt).
defined('SP_RUNTIME_HASH') || define('SP_RUNTIME_HASH', '{{runtimeHash}}');
require_once __DIR__ . '/sp/bootstrap.php';
\SpacePress\Wp\Runtime::boot(__FILE__);
