<?php
declare(strict_types=1);

// The operator drain lane, CLI only:
// `php engine/entrypoints/purge.php --private-root=…` over SSH. The engine
// itself never spawns this — wp.cloud's FPM pool has no php CLI in its mount
// namespace, so request-time purges drain in-process post-response
// (shared/purge.php). This worker exists for operators: it runs the SAME
// `_stattic_runtime_purge_drain`, which loads WordPress and calls the provider
// bridge, against whatever the durable queue holds.
//
// It takes no purge TARGET from its caller: everything it does comes from the
// durable queue. It is never mounted as a public alias.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "purge.php runs on the CLI only\n");
    exit(2);
}

$storageRoot = '';
foreach (array_slice(is_array($argv ?? null) ? $argv : [], 1) as $_stattic_purge_arg) {
    if (is_string($_stattic_purge_arg) && str_starts_with($_stattic_purge_arg, '--private-root=')) {
        $storageRoot = substr($_stattic_purge_arg, strlen('--private-root='));
    }
}
if ($storageRoot === '') {
    fwrite(STDERR, "usage: purge.php --private-root=<path>\n");
    exit(2);
}
$engineRoot = dirname(__DIR__);

require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/purge.php';

if (!is_dir($storageRoot)) {
    fwrite(STDERR, "runtime storage is not provisioned\n");
    exit(3);
}

// A full bootstrap prints whatever tenant code feels like printing, so the
// receipt is a sentinel-prefixed line the operator greps for rather than
// "the contents of stdout".
const STATTIC_RUNTIME_PURGE_RESULT_SENTINEL = '__SPACEFAST_PURGE_RESULT__';

$summary = _stattic_runtime_purge_drain($storageRoot);

$encoded = json_encode($summary, JSON_UNESCAPED_SLASHES);
echo "\n" . STATTIC_RUNTIME_PURGE_RESULT_SENTINEL . (is_string($encoded) ? $encoded : '{}') . "\n";
exit(0);
