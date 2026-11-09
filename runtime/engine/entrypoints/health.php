<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);

// No bootstrap-config here: this is the most-polled public path and must not pay
// the persistent-data decrypt.
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/response.php';
$storageRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
_stattic_emit_runtime_identity();

_stattic_json_response(200, [
    'ok' => true,
    'runtime' => 'stattic-php',
    'schema' => STATTIC_RUNTIME_SCHEMA,
    'engine_version' => SPACEFAST_RUNTIME_ENGINE_VERSION,
    'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
    // Where that engine runs from: the site's own release, or the Spacefast chroot.
    'engine_source' => ($GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE'] ?? null) === 'chroot' ? 'chroot' : 'site',
    // Why, from the thin loader's selection, and the revision its channel
    // resolved to: the only outside view of a site that selects the chroot and
    // still runs its own release. A loader older than that rule reports
    // neither.
    'engine_source_reason' => is_string($GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE_REASON'] ?? null)
        ? $GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE_REASON']
        : null,
    'chroot_revision' => is_string($GLOBALS['SPACEFAST_RUNTIME_CHROOT_REVISION'] ?? null)
        ? $GLOBALS['SPACEFAST_RUNTIME_CHROOT_REVISION']
        : null,
    // The contract version of the thin loader that handed this request over;
    // null behind a loader that predates them.
    'loader_version' => is_int($GLOBALS['SPACEFAST_RUNTIME_LOADER_VERSION'] ?? null)
        ? $GLOBALS['SPACEFAST_RUNTIME_LOADER_VERSION']
        : null,
    'site_state' => is_dir($storageRoot) ? 'configured' : 'unconfigured',
    'request_hostname' => _stattic_normalize_host_authority((string) ($_SERVER['HTTP_HOST'] ?? '')),
], 'application/json', ['Cache-Control' => 'no-store']);
