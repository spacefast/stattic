<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/admin/api.php';
_stattic_runtime_admin_entrypoint($engineRoot, 'management');
