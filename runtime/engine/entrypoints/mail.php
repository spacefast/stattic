<?php
declare(strict_types=1);

// The native service broker sends Space mail through this host-owned endpoint.
// The broker cannot load WordPress, and the FPM environment it runs in has no
// PHP CLI to spawn, so a normal FPM request is what reaches wp_mail.

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/storage.php';
require_once $engineRoot . '/shared/space-mail.php';

_stattic_emit_runtime_identity();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    _stattic_method_not_allowed('POST', [
        'code' => 'space_mail_method_not_allowed',
        'message' => 'The mail endpoint accepts POST requests.',
    ]);
}

$installRoot = _stattic_runtime_install_root($engineRoot);
$privateRoot = $installRoot . '/storage';
_stattic_access_private_root($privateRoot);

$grant = _stattic_space_mail_verify_grant(
    $privateRoot,
    (string) ($_SERVER[STATTIC_SPACE_MAIL_GRANT_SERVER_VAR] ?? '')
);
if ($grant === null) {
    _stattic_problem_response(401, 'space_mail_grant_invalid', 'The mail grant is invalid or expired.');
}

$rawBody = _stattic_bounded_request_body(STATTIC_SPACE_MAIL_REQUEST_MAX_BYTES);
if ($rawBody === null) {
    _stattic_problem_response(413, 'space_mail_request_too_large', 'The mail request exceeds its byte limit.');
}
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    _stattic_problem_response(400, 'space_mail_request_invalid', 'The mail request must be a JSON object.');
}

// WordPress reports a failed bootstrap by printing a page and exiting. Nothing
// it prints may reach the broker, which only needs to know this send failed.
$completed = false;
ob_start();
register_shutdown_function(static function () use (&$completed): void {
    if ($completed) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    _stattic_problem_response(502, 'space_mail_transport_failed', 'The mail transport did not complete.');
});

$accepted = false;
try {
    $accepted = _stattic_space_mail_send($payload, dirname($installRoot), $privateRoot, $grant);
} catch (Throwable $error) {
    error_log('space mail send failed type=' . get_debug_type($error));
}
$completed = true;
while (ob_get_level() > 0) {
    ob_end_clean();
}
_stattic_json_response(200, ['accepted' => $accepted], 'application/json', ['Cache-Control' => 'private, no-store']);
