<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$engineRoot = $root . '/.stattic/engine';
$storageRoot = $root . '/.stattic/storage';
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';

if (!is_dir($storageRoot)) {
    _stattic_json_response(503, ['error' => ['code' => 'runtime_undeployed', 'message' => 'Runtime storage is not provisioned on this site.']]);
}

[$requestMethod, $requestPath] = _stattic_runtime_entrypoint_request();

// One dispatcher for the whole management surface: the unified admin route
// table (admin/api.php) hosts both the management and file-fetch lanes and
// lazily loads whichever handler module the matched row needs.
require_once $engineRoot . '/admin/api.php';
_stattic_runtime_admin_api($storageRoot, $requestMethod, $requestPath, 'management');
