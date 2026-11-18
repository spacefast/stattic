<?php
/** Configures only a TypedPress-owned local development site. Credentials are disposable and local only. */
declare(strict_types=1);

$spec = json_decode(file_get_contents($args[0]), true, 512, JSON_THROW_ON_ERROR);
if (realpath(ABSPATH) !== realpath($spec['site'])) {
    WP_CLI::error('TypedPress development site identity does not match.');
}
foreach (['mbstring', 'gd', 'sodium', 'pdo_sqlite'] as $extension) {
    if (!extension_loaded($extension)) {
        WP_CLI::error("Studio PHP is missing $extension.");
    }
}
if (version_compare(get_bloginfo('version'), $spec['wordpress'], '<')) {
    WP_CLI::error('WordPress is older than the TypedPress platform requirement.');
}
if (!defined('SQLITE_DRIVER_VERSION') || version_compare(SQLITE_DRIVER_VERSION, $spec['sqlite'], '<')) {
    WP_CLI::error('Studio SQLite integration is older than the TypedPress platform requirement.');
}
if (!get_option('tp_dev_provisioned')) {
    update_option('permalink_structure', '/%postname%/');
    flush_rewrite_rules(false);
    update_option('tp_dev_provisioned', true);
}
if (class_exists(\TypedPress\Wp\Platform::class)) {
    $problems = \TypedPress\Wp\Platform::problems($spec['localized']);
    if ($problems !== []) {
        WP_CLI::error(implode('; ', $problems));
    }
}
echo "TypedPress local WordPress ready.\n";
