<?php
declare(strict_types=1);

require_once __DIR__ . '/.stattic/engine/shared/context.php';

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (isset(SPACEFAST_RUNTIME_ENTRYPOINT_PATHS[$requestPath])) {
    return;
}

require_once __DIR__ . '/.stattic/engine/init.php';
