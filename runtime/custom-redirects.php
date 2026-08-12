<?php
declare(strict_types=1);

// D103 is WITHDRAWN: the guard stays. wp.cloud runs this hook ahead of EVERY
// request, so a hard require is a site-wide 500 whenever the engine tree is
// missing or half-landed — including on the POST to __spacefast/installer.php
// that would put the tree back, which makes the failure unrecoverable without
// provider intervention. The gate fails open instead: with no engine there is
// nothing here for it to protect. The access wall, the private-namespace 403 and
// the alias dispatch all live in the tree that is gone, and so do the four
// entrypoint aliases' targets, so returning exposes only the webroot's own
// static files, which php-fpm already served directly. The resident installer is
// shipped separately by bootstrap and survives, and returning is what lets
// php-fpm reach it.
$spacefastRuntimeContext = __DIR__ . '/.stattic/engine/shared/context.php';
if (!is_file($spacefastRuntimeContext)) {
    return;
}
require_once $spacefastRuntimeContext;

$requestPath = _stattic_request_uri_path((string) ($_SERVER['REQUEST_URI'] ?? '/'));
if (isset(SPACEFAST_RUNTIME_ENTRYPOINT_PATHS[$requestPath])) {
    return;
}

// wp.cloud requires this hook before it defines DB_PASSWORD, which
// Atomic_Persistent_Data needs to decrypt Spacefast config. Define it from the
// provider environment (mirroring env.php's helper, which then leaves the
// existing constant alone) and scrub the raw value before authored PHP runs.
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

require_once __DIR__ . '/.stattic/engine/init.php';
