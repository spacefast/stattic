<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';

// The ONE response writer for every runtime lane that answers with a body it
// built itself: it owns HEAD suppression, replace-semantics on Content-Type and
// header casing. File bodies go out through the two emitters below instead.
function _stattic_response_send(int $status, string $body, string $mediaType = '', array $headers = []): never
{
    http_response_code($status);
    if ($mediaType !== '') {
        header('Content-Type: ' . $mediaType, true);
    }
    foreach ($headers as $name => $value) {
        if (is_string($name) && is_scalar($value)) {
            header($name . ': ' . (string) $value, true);
        }
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $body;
    }
    exit;
}

/**
 * @param array<string,mixed> $body
 * @param array<string,scalar> $headers
 */
function _stattic_json_response(int $status, array $body, string $mediaType = 'application/json', array $headers = []): never
{
    // SSH dispatch: the response rides stdout as one {status, body} envelope.
    // Always exit 0 — transport failures are the non-zero/garbage cases.
    if (defined('STATTIC_RUNTIME_DISPATCH_CLI')) {
        echo json_encode(['status' => $status, 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    _stattic_response_send(
        $status,
        json_encode($body, JSON_UNESCAPED_SLASHES) . "\n",
        $mediaType . '; charset=utf-8',
        $headers + ['Cache-Control' => 'no-store, no-cache, must-revalidate'],
    );
}

/**
 * RFC 9457 problem document served as application/problem+json.
 *
 * @param array<string,mixed> $extra
 * @param array<string,scalar> $headers
 */
function _stattic_problem_response(int $status, string $code, string $message, array $extra = [], array $headers = []): never
{
    _stattic_json_response(
        $status,
        _stattic_problem_document($status, $code, $message, $extra),
        STATTIC_PROBLEM_MEDIA_TYPE,
        $headers,
    );
}

/**
 * The one 405. Allow is not optional — a refusal that does not name the methods
 * it would accept is unusable to every client, human or agent.
 *
 * `$options`: `code`/`message` answer a problem document, `body`/`media_type`
 * a lane-shaped body (the SDK answers JavaScript so a <script> tag still
 * parses), neither answers an empty body. `headers` rides on top.
 */
function _stattic_method_not_allowed(string $allow, array $options = []): never
{
    $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
    $headers['Allow'] = $allow;
    $headers['Cache-Control'] ??= 'no-store';
    $code = is_string($options['code'] ?? null) ? $options['code'] : '';
    if ($code !== '') {
        _stattic_problem_response(405, $code, (string) ($options['message'] ?? ''), [], $headers);
    }
    _stattic_response_send(
        405,
        (string) ($options['body'] ?? ''),
        (string) ($options['media_type'] ?? ''),
        $headers,
    );
}

// --- file bodies -----------------------------------------------------------

function _stattic_send_file_headers(array $headers): void
{
    // Nothing in the header map names these, so a value set earlier in the
    // request would otherwise survive next to the canonical Cache-Control.
    header_remove('Pragma');
    header_remove('Expires');
    foreach ($headers as $name => $value) {
        header_remove($name);
        header($name . ': ' . $value);
    }
}

// Never readfile()/fpassthru(): they buffer the whole body before the first byte.
//
// @param resource $stream
function _stattic_stream_file($stream, int $bytesToSend): void
{
    $remaining = $bytesToSend;
    while ($remaining > 0 && !feof($stream)) {
        $chunk = fread($stream, min(65536, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $remaining -= strlen($chunk);
        echo $chunk;
    }
}
