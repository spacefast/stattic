<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$engineRoot = $root . '/.stattic/engine';
$storageRoot = $root . '/.stattic/storage';
$publishRoot = realpath($root);

if (!is_string($publishRoot) || !is_dir($storageRoot)) {
    require_once $engineRoot . '/shared/errors.php';
    _stattic_render_platform_page('undeployed', 503, [], "This site has not been deployed yet.\n");
}

require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';

[$requestMethod, $requestPath] = _stattic_runtime_entrypoint_request();

$requestHost = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));

require_once $engineRoot . '/runtime/serve.php';
require_once $engineRoot . '/runtime/access-rules.php';

$serving = _stattic_load_lazy_serving_config($storageRoot, $requestHost, $requestPath, $requestMethod);
if (!is_array($serving)) {
    _stattic_render_platform_action(_stattic_undeployed_action());
}

_spacefast_access_handle_callback($serving, $requestHost, $storageRoot);
