<?php
declare(strict_types=1);

// This is an internal stdin protocol. It is never an HTTP entrypoint.
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI) {
    http_response_code(404);
    exit;
}

ob_start();
try {
    $input = json_decode((string) stream_get_contents(STDIN, 16385), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['privateRoot'] ?? null)
        || !is_string($input['spaceId'] ?? null) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $input['spaceId'])
        || !is_string($input['host'] ?? null) || !preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]+)?\z/', $input['host'])
        || !in_array($input['scheme'] ?? null, ['http', 'https'], true)
        || !is_array($input['settings'] ?? null) || !is_string($input['cookie'] ?? null)
        || strlen($input['cookie']) > 512) {
        throw new RuntimeException('invalid_input');
    }
    $publicRoot = dirname(dirname($input['privateRoot']));
    $_SERVER['HTTP_HOST'] = $input['host'];
    $_SERVER['SERVER_NAME'] = explode(':', $input['host'])[0];
    $_SERVER['HTTPS'] = $input['scheme'] === 'https' ? 'on' : 'off';
    $_SERVER['REQUEST_URI'] = '/__zero/auth/user';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_COOKIE = ['sfi_session' => $input['cookie']];
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $input['spaceId'];
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $input['privateRoot'];
    $GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] = $input['scheme'] . '://' . $input['host'];
    $GLOBALS['SPACEFAST_PAGE_SERVING'] = ['space_id' => $input['spaceId'], 'users' => $input['settings']];
    define('WP_USE_THEMES', false);
} catch (Throwable $error) {
    while (ob_get_level() > 0) ob_end_clean();
    fwrite(STDERR, "space_users_session_input_failed\n");
    exit(1);
}

function spacefast_space_users_session_reply(): void
{
  try {
    if (!function_exists('spacefast_space_users_auth') || !spacefast_space_users_available()) {
        throw new RuntimeException('component_unavailable');
    }
    $data = spacefast_space_users_auth();
    ob_end_clean();
    echo json_encode(['data' => $data], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    while (ob_get_level() > 0) ob_end_clean();
    // Neither the session secret nor provider/database diagnostics cross this boundary.
    fwrite(STDERR, "space_users_session_verification_failed\n");
    exit(1);
}
}
