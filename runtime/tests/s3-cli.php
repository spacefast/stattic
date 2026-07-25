<?php
declare(strict_types=1);

// Dedicated CLI entry point for exercising shared/s3.php's real functions
// against a live (fake) S3-compatible endpoint from the bun test runner.
// shared/s3.php is not yet listed in engine-manifest.json (a later
// integrator wires the tiered-serve/moves-V2 call sites and adds it there),
// so the manifest-driven startRuntime() harness in harness.ts can't see it
// yet. This script requires the file directly from its real repo path
// instead, mirroring how unit.php require_once's engine files directly for
// pure-logic coverage — see runtime/tests/s3.test.ts for the driver.
//
// Protocol: argv[1] is a single JSON request object; stdout is a single
// JSON response line. Binary bodies are base64-encoded under *_base64 keys
// so the protocol stays plain-text-safe.
require_once __DIR__ . '/../engine/shared/s3.php';

$request = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($request)) {
    fwrite(STDERR, "invalid_request_json\n");
    exit(2);
}

function s3_cli_bucket_row(array $request): ?array
{
    $bucketId = (string) ($request['bucket_id'] ?? '');
    return $bucketId === '' ? null : _stattic_s3_bucket_row($bucketId, (bool) ($request['force_reload'] ?? false));
}

$op = (string) ($request['op'] ?? '');
$key = (string) ($request['key'] ?? '');
$options = is_array($request['options'] ?? null) ? $request['options'] : [];

switch ($op) {
    case 'get':
    case 'head':
    case 'put':
        $bucketRow = s3_cli_bucket_row($request);
        if ($bucketRow === null) {
            echo json_encode(['ok' => false, 'status' => 0, 'error' => 's3_bucket_unknown']) . "\n";
            break;
        }
        if ($op === 'put') {
            // No production caller does a single-object PUT anymore (uploads go
            // through _stattic_s3_multi_put), so there is no _stattic_s3_put
            // wrapper to call — drive the real request path directly.
            $decoded = base64_decode((string) ($request['body_base64'] ?? ''), true);
            $result = _stattic_s3_request($bucketRow, 'put', 'PUT', $key, ['body' => $decoded === false ? '' : $decoded] + $options);
        } elseif ($op === 'head') {
            $result = _stattic_s3_head($bucketRow, $key, $options);
        } else {
            $result = _stattic_s3_get($bucketRow, $key, $options);
        }
        if (array_key_exists('body', $result)) {
            $result['body_base64'] = base64_encode((string) $result['body']);
            unset($result['body']);
        }
        echo json_encode($result) . "\n";
        break;

    case 'stream_get':
        $remote = ['bucket' => (string) ($request['bucket_id'] ?? ''), 'key' => $key];
        $range = isset($request['range']) && is_string($request['range']) ? $request['range'] : null;
        $status = null;
        $headers = [];
        $body = '';
        $stream = _stattic_s3_open(
            $remote,
            $range,
            function (int $s, array $h) use (&$status, &$headers): void {
                $status = $s;
                $headers = $h;
            },
            function (string $chunk) use (&$body): void {
                $body .= $chunk;
            },
            $options
        );
        if ($stream === false) {
            echo json_encode(['ok' => false, 'status' => 0, 'error' => 's3_open_failed']) . "\n";
            break;
        }
        curl_exec($stream['handle']);
        $errno = curl_errno($stream['handle']);
        echo json_encode([
            'ok' => $errno === 0 && $status !== null && $status >= 200 && $status < 400,
            'status' => $status,
            'headers' => $headers,
            'body_base64' => base64_encode($body),
            'error' => $errno === 0 ? null : curl_error($stream['handle']),
        ]) . "\n";
        break;

    case 'put_wrong_hash':
        // Test-only: no shared/s3.php PUT call site ever does this (every one
        // computes the real hash) — this deliberately declares a wrong
        // x-amz-content-sha256 using the same exported signing primitives to
        // prove a mismatched hash is rejected independent of the
        // Authorization-shape check (plan §18 probe: wrong hash -> 400).
        $bucketRow = s3_cli_bucket_row($request);
        if ($bucketRow === null) {
            echo json_encode(['ok' => false, 'status' => 0, 'error' => 's3_bucket_unknown']) . "\n";
            break;
        }
        $decoded = base64_decode((string) ($request['body_base64'] ?? ''), true);
        $body = $decoded === false ? '' : $decoded;
        $credentials = _stattic_s3_credentials($bucketRow, 'put');
        $locator = _stattic_s3_locator($bucketRow, $key);
        if ($locator === null) {
            echo json_encode(['ok' => false, 'status' => 0, 'error' => 's3_bucket_config_invalid']) . "\n";
            break;
        }
        $wrongHash = str_repeat('0', 64);
        $extraHeaders = ['content-length' => (string) strlen($body)];
        $signedHeaders = _stattic_s3_sign($bucketRow, $credentials, 'PUT', $locator['host'], $locator['path'], $extraHeaders, $wrongHash);
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => $locator['url'],
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => _stattic_s3_header_lines($signedHeaders),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => STATTIC_S3_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => STATTIC_S3_TOTAL_TIMEOUT_SECONDS,
        ];
        if (isset($options['resolve']) && is_array($options['resolve'])) {
            $curlOptions[CURLOPT_RESOLVE] = array_map('strval', $options['resolve']);
        }
        curl_setopt_array($ch, $curlOptions);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        echo json_encode(['ok' => $status >= 200 && $status < 300, 'status' => $status]) . "\n";
        break;

    case 'multi_get':
        $items = is_array($request['items'] ?? null) ? $request['items'] : [];
        echo json_encode(_stattic_s3_multi_get($items, (int) ($request['streams'] ?? 4))) . "\n";
        break;

    case 'multi_put':
        $items = is_array($request['items'] ?? null) ? $request['items'] : [];
        echo json_encode(_stattic_s3_multi_put($items, (int) ($request['streams'] ?? 4))) . "\n";
        break;

    default:
        fwrite(STDERR, "unknown_op:{$op}\n");
        exit(2);
}
