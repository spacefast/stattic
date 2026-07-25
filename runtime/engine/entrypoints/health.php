<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$engineRoot = $root . '/.stattic/engine';
$storageRoot = $root . '/.stattic/storage';

// No bootstrap-config here: the response is built entirely from engine
// constants and the request host, and health is the public no-auth path
// monitors poll most often — it must not pay the persistent-data decrypt.
require_once $engineRoot . '/shared/context.php';

$requestHost = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));

http_response_code(200);
header('Content-Type: application/json; charset=utf-8', false);
header('Cache-Control: no-store', false);
$body = json_encode([
    'ok' => true,
    'runtime' => 'stattic-php',
    'schema' => STATTIC_RUNTIME_SCHEMA,
    'engine_version' => SPACEFAST_RUNTIME_ENGINE_VERSION,
    'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
    'site_state' => is_dir($storageRoot) ? 'configured' : 'unconfigured',
    'request_hostname' => $requestHost,
], JSON_UNESCAPED_SLASHES);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && is_string($body)) {
    echo $body;
}
