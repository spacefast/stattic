<?php
declare(strict_types=1);

// Dedicated CLI entry point for exercising sf_fetch()'s real transport
// (runtime/php-functions.php) from the bun test runner, mirroring
// subprocess-cli.php and s3-cli.php.
//
// sf_fetch's serve-lane refusals are covered where they belong, on a real
// request through startRuntime() (php-functions.test.ts). What cannot be
// reached from there is the transport itself: whether an ambient proxy can
// choose the peer, what a redirect hop carries, and how a relative Location
// resolves. Each of those needs a real TLS upstream and a real process
// environment, so this entry point requires the engine directly and lets the
// driver own the servers.
//
// Protocol: argv[1] is a single JSON request object
// (`url`, `options`, `scope`); stdout is a single JSON response line, either
// `{"ok":true,"status":...,"headers":...,"body":...}` or
// `{"ok":false,"code":"zero_fetch_..."}`.

require_once __DIR__ . '/../engine/runtime/php-functions.php';

$request = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($request) || !is_string($request['url'] ?? null)) {
    fwrite(STDERR, "invalid_request_json\n");
    exit(2);
}

// The dispatch binds this from `$serving` before the jail; there is no serve
// lane here, so the driver names the scope it is testing.
$state = &_stattic_php_functions_state();
$state['egress_scope'] = ($request['scope'] ?? null) === 'trusted'
    ? STATTIC_EGRESS_SCOPE_TRUSTED
    : STATTIC_EGRESS_SCOPE_OPEN;

try {
    $response = sf_fetch($request['url'], is_array($request['options'] ?? null) ? $request['options'] : []);
    echo json_encode(['ok' => true] + $response, JSON_UNESCAPED_SLASHES), "\n";
} catch (SpacefastFetchError $error) {
    echo json_encode(['ok' => false, 'code' => $error->errorCode], JSON_UNESCAPED_SLASHES), "\n";
}
