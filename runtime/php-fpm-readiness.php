<?php
declare(strict_types=1);

// Transition probe: runs on both sides of a PHP upgrade and never loads the
// runtime. It reports the FPM process that served this request, before a new
// runtime is installed.
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

$nonce = is_string($_GET['nonce'] ?? null) ? $_GET['nonce'] : '';
if (
    preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
    || basename(__FILE__) !== 'php-fpm-readiness-' . $nonce . '.php'
) {
    http_response_code(400);
    exit(1);
}

header('Content-Type: application/json');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

echo json_encode([
    'nonce' => $nonce,
    'php_version' => PHP_VERSION,
    'php_version_id' => PHP_VERSION_ID,
    'php_sapi' => PHP_SAPI,
], JSON_THROW_ON_ERROR) . "\n";
