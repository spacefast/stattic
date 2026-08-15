<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
$storageRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
_stattic_emit_runtime_identity();

if (!is_dir($storageRoot)) {
    _stattic_problem_response(503, 'runtime_undeployed', 'Runtime storage is not provisioned on this site.');
}

[$requestMethod, $requestPath] = _stattic_runtime_entrypoint_request();

require_once $engineRoot . '/admin/api.php';
_stattic_runtime_admin_api($storageRoot, $requestMethod, $requestPath, 'management');
