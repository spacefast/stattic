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
    'site_state' => is_dir($storageRoot) ? 'configured' : 'unconfigured',
    'request_hostname' => _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? '')),
], 'application/json', ['Cache-Control' => 'no-store']);
