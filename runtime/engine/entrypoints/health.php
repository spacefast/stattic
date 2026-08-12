<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$engineRoot = $root . '/.stattic/engine';
$storageRoot = $root . '/.stattic/storage';

// No bootstrap-config here: this is the most-polled public path and must not pay
// the persistent-data decrypt.
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/response.php';
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
