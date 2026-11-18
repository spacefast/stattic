<?php
/**
 * Plugin Name: TypedPress Runtime
 * Version: {{tpVersion}}
 * Requires at least: {{wpVersion}}
 * Requires PHP: {{phpVersion}}
 * Requires Plugins: {{runtimeRequires}}
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
// The runtime's files, by content: what a prebuilt page was rendered with (TypedPress\Render\Prebuilt).
defined('TP_RUNTIME_HASH') || define('TP_RUNTIME_HASH', '{{runtimeHash}}');
require_once __DIR__ . '/tp/bootstrap.php';
\TypedPress\Wp\Runtime::boot(__FILE__);
