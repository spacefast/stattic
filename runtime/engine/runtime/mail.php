<?php
declare(strict_types=1);

// The native service broker invokes this host-owned script synchronously.
// A separate PHP process can load WordPress outside a tenant worker's jail.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('log_errors', '1');
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
require_once __DIR__ . '/../shared/mail.php';
$payload = json_decode((string) file_get_contents('php://stdin', false, null, 0, 1048577), true);
$accepted = false;
ob_start();
try {
    if (is_array($payload) && is_string($argv[1] ?? null)) {
        $accepted = _stattic_mail_send_metered($payload, $argv[1]);
    }
} catch (Throwable $error) {
    error_log('spacefast mail send failed type=' . get_debug_type($error));
} finally {
    ob_end_clean();
}
fwrite(STDOUT, json_encode($accepted) . "\n");
