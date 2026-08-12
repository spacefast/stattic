<?php
declare(strict_types=1);

// Dedicated CLI entry point for exercising shared/native-process.php's real
// subprocess runner (_stattic_runtime_run_subprocess) from the bun test
// runner. The runner is a pure process-plumbing helper with no HTTP surface,
// so there is nothing for the manifest-driven startRuntime() harness to hit;
// this script requires the engine file directly from its real repo path,
// mirroring s3-cli.php. See runtime/tests/subprocess.test.ts for the driver.
//
// Protocol: argv[1] is a single JSON request object; stdout is a single JSON
// response line. Captured bytes are reported as length + sha256 rather than
// echoed, so a megabyte round trip stays cheap to assert on.
require_once __DIR__ . '/../engine/shared/context.php';
require_once __DIR__ . '/../engine/shared/native-process.php';

$request = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($request)) {
    fwrite(STDERR, "invalid_request_json\n");
    exit(2);
}

// Deterministic, incompressible-enough filler so a truncated or reordered
// stdin round trip changes the digest.
function subprocess_cli_payload(int $bytes): string
{
    if ($bytes <= 0) {
        return '';
    }
    $unit = '';
    for ($i = 0; $i < 256; $i++) {
        $unit .= chr($i);
    }
    return substr(str_repeat($unit, intdiv($bytes, 256) + 1), 0, $bytes);
}

$stdinBytes = (int) ($request['stdin_bytes'] ?? 0);
$stdin = $stdinBytes > 0 ? subprocess_cli_payload($stdinBytes) : null;

if (($request['missing_binary'] ?? false) === true) {
    $cmd = [__DIR__ . '/does-not-exist-spacefast-subprocess-probe'];
} else {
    $cmd = [PHP_BINARY, '-r', (string) ($request['child'] ?? '')];
}

$result = _stattic_runtime_run_subprocess($cmd, is_array($request['env'] ?? null) ? $request['env'] : null, $stdin);

echo json_encode([
    'spawned' => $result['spawned'],
    'exitCode' => $result['exitCode'],
    'stdoutLength' => strlen($result['stdout']),
    'stdoutSha256' => hash('sha256', $result['stdout']),
    'stderrLength' => strlen($result['stderr']),
    'stderrSha256' => hash('sha256', $result['stderr']),
    'stdinSha256' => hash('sha256', $stdin ?? ''),
]) . "\n";
