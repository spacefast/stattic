<?php
declare(strict_types=1);

// CLI entry point that drives shared/s3.php's signer/client operations against
// a fake S3 endpoint without booting the full HTTP runtime. The driver is
// runtime/tests/s3.test.ts.
//
// Protocol: argv[1] is a JSON request object, stdout is one JSON response line.
// Binary bodies are base64-encoded under *_base64 keys.
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
            // Uploads go through _stattic_s3_multi_put, so there is no
            // _stattic_s3_put wrapper. Drive the real request path directly.
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
        $stream = _stattic_s3_stream_get(
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
        echo json_encode([
            'ok' => $stream['error'] === null && $status !== null && $status >= 200 && $status < 400,
            'status' => $status,
            'headers' => $headers,
            'body_base64' => base64_encode($body),
            'error' => $stream['error'],
        ]) . "\n";
        break;

    case 'put_wrong_hash':
        // Test-only: declares a wrong x-amz-content-sha256 with the real
        // signing primitives, proving a mismatched hash is rejected
        // independent of the Authorization-shape check. No production PUT
        // call site does this; they all compute the real hash.
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
        $result = _stattic_http_request(_stattic_s3_transport_request(
            $bucketRow,
            $credentials,
            $locator,
            'PUT',
            ['content-length' => (string) strlen($body)],
            str_repeat('0', 64),
            ['body' => $body, 'resolve' => $options['resolve'] ?? []]
        ));
        echo json_encode(['ok' => $result['ok'], 'status' => $result['status']]) . "\n";
        break;

    case 'multi_put':
        $items = is_array($request['items'] ?? null) ? $request['items'] : [];
        echo json_encode(_stattic_s3_multi_put($items, (int) ($request['streams'] ?? 4))) . "\n";
        break;

    default:
        fwrite(STDERR, "unknown_op:{$op}\n");
        exit(2);
}
