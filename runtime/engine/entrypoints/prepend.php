<?php
declare(strict_types=1);

// Reached only from the loader, which has already classified the request path
// against the same generated entrypoint table and requires this file for the
// visitor lane alone. Re-deriving that here would be the request's second
// classification and could only ever disagree with the one that routed it.
$engineRoot = dirname(__DIR__);
if (!is_file($engineRoot . '/shared/context.php')) {
    return;
}

if (!defined('DB_PASSWORD')) {
    $spacefastDbPassword = getenv('DB_PASSWORD');
    if (is_string($spacefastDbPassword)) {
        define('DB_PASSWORD', $spacefastDbPassword);
        if (PHP_SAPI !== 'cli') {
            unset($_SERVER['DB_PASSWORD'], $_ENV['DB_PASSWORD']);
            putenv('DB_PASSWORD');
        }
    }
    unset($spacefastDbPassword);
}

require_once $engineRoot . '/init.php';
