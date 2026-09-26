<?php
declare(strict_types=1);

// Provider-owned retries, independent of visitor traffic and Space lifetime.
if (PHP_SAPI !== 'cli') {
    exit(2);
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once dirname(__DIR__) . '/shared/bootstrap-config.php';
require_once dirname(__DIR__) . '/runtime/cli-invoke.php';
require_once dirname(__DIR__) . '/shared/purge.php';

['flags' => $flags] = _stattic_cli_flags(is_array($argv ?? null) ? $argv : []);
$privateRoot = _stattic_cli_private_root(dirname(__DIR__), trim((string) ($flags['private-root'] ?? '')));
// CLI has no FPM prepend. Load the site's provider configuration through its
// WordPress bootstrap only when retry work needs the local gateway credential.
$pending = _stattic_record_store_ids(_stattic_runtime_purge_store($privateRoot));
if ($pending !== [] && _stattic_runtime_edge_purge_endpoint() === null) {
    $wpLoad = dirname($privateRoot, 2) . '/wp-load.php';
    if (is_file($wpLoad)) {
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        require_once $wpLoad;
    }
}
$complete = _stattic_runtime_purge_drain($privateRoot, microtime(true) + 45);
fwrite(STDOUT, json_encode(['complete' => $complete]) . "\n");
exit($complete ? 0 : 1);
