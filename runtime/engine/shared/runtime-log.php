<?php
declare(strict_types=1);

// THE runtime log writer.
//
// Everything a space's own code prints goes through here and lands in PHP's
// error log — a Zero mutation's console output, a PHP function's uncaught
// throwable, and a Cloudflare Worker's tail events, which reach the box by
// calling back to this origin (runtime/functions-relay.php) after the worker's
// response has already gone out. There is exactly one reason it is that file
// and not a nicer one under the private root: the provider ships the PHP error
// log off the box and serves it back through its log API, and it ships nothing
// else. A record written anywhere else on this filesystem cannot be read by the
// product, however well organised the file is.
//
// One line per record, always. The provider's shipper indexes by line, so a
// record carrying a raw newline arrives as two documents and the second is
// unreadable garbage. JSON encoding is what guarantees that, and the marker is
// what lets the reader find our record inside a message the shipper may have
// prefixed. The reader is `decodeRuntimeLogRecord` in
// apps/control-plane/src/wp-cloud/observability.ts; the marker string is the
// contract between them.
//
// Writing is a local append and nothing waits on it — no lock, no network, no
// retry. That is the whole reason the log surface can promise it never sits in
// a request's way while still being written the moment the line happens.

// Must match RUNTIME_LOG_MARKER in the control plane. The version digit is part
// of the marker because these lines live 28 days in a log we do not own: a
// changed record shape has to become a new marker, never a silent
// reinterpretation of records an older runtime already wrote.
const SPACEFAST_RUNTIME_LOG_MARKER = 'sf-log/1 ';

// The provider's index truncates long messages, and a truncated line is a line
// the reader cannot parse at all — the JSON never closes. Bounding the two
// tenant-controlled fields keeps a record whole.
const SPACEFAST_RUNTIME_LOG_MESSAGE_MAX_BYTES = 4096;
const SPACEFAST_RUNTIME_LOG_METADATA_MAX_BYTES = 4096;

// The contract's four levels, plus the aliases real tail streams emit. An
// unknown level must never drop the line — that would hide the output the
// developer came for — so it resolves to `info`.
const SPACEFAST_RUNTIME_LOG_LEVELS = [
    'debug' => 'debug',
    'info' => 'info',
    'log' => 'info',
    'notice' => 'info',
    'warn' => 'warn',
    'warning' => 'warn',
    'error' => 'error',
    'fatal' => 'error',
];

function _stattic_runtime_log_level(mixed $value): string
{
    $level = is_string($value) ? strtolower($value) : '';
    return SPACEFAST_RUNTIME_LOG_LEVELS[$level] ?? 'info';
}

function _stattic_runtime_log_string(mixed $value, int $maxBytes): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    return strlen($value) > $maxBytes ? substr($value, 0, $maxBytes) : $value;
}

/**
 * The message, rendered the way a developer expects to read it.
 *
 * Tenant code hands over whatever it printed, which for a structured logger is
 * an object. JSON is the answer there because the alternative —
 * "[object Object]", or PHP's "Array" — is the single most common complaint
 * about hosted log pipelines.
 */
function _stattic_runtime_log_message(mixed $value): string
{
    if (is_string($value)) {
        $message = $value;
    } elseif ($value === null) {
        $message = '';
    } elseif (is_scalar($value)) {
        $message = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    } else {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        $message = is_string($encoded) ? $encoded : '';
    }
    return (string) _stattic_runtime_log_string($message, SPACEFAST_RUNTIME_LOG_MESSAGE_MAX_BYTES);
}

/**
 * Metadata survives only if it is a JSON object that fits.
 *
 * Dropping an oversized or wrongly-shaped bag is deliberate: the message is the
 * thing the developer wrote, and losing the whole record to a bad metadata
 * value would trade the useful half for the decorative one.
 */
function _stattic_runtime_log_metadata(mixed $value): array
{
    if (!is_array($value) || $value === [] || array_is_list($value)) {
        return [];
    }
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || strlen($encoded) > SPACEFAST_RUNTIME_LOG_METADATA_MAX_BYTES) {
        return [];
    }
    return $value;
}

/**
 * Normalises one tenant-supplied record into the wire shape and writes it.
 *
 * `versionId` is the caller's to supply and never the record's: a worker naming
 * someone else's version must not be able to file its output under it. Every
 * other field is untrusted.
 *
 * `id` is minted here rather than derived on read. The provider's log has no
 * identity of its own, and a caller paging a window needs a stable key for a
 * line it may meet again at a page boundary.
 */
function _stattic_runtime_log_write(array $record, string $versionId): void
{
    $timestamp = _stattic_runtime_log_timestamp($record['timestamp'] ?? null);
    $line = json_encode([
        'id' => bin2hex(random_bytes(12)),
        'timestamp' => $timestamp,
        'level' => _stattic_runtime_log_level($record['level'] ?? null),
        'requestId' => _stattic_runtime_log_string($record['requestId'] ?? null, 128),
        'versionId' => $versionId === '' ? null : $versionId,
        'handlerName' => _stattic_runtime_log_string($record['handlerName'] ?? null, 256),
        'message' => _stattic_runtime_log_message($record['message'] ?? null),
        'metadata' => (object) _stattic_runtime_log_metadata($record['metadata'] ?? null),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    error_log(SPACEFAST_RUNTIME_LOG_MARKER . $line);
}

/**
 * When the record was written, as the writer saw it.
 *
 * Tenant clocks are not ours, and the provider stamps its own `@timestamp` on
 * ingest anyway — but that stamp is the moment the line was *indexed*, minutes
 * after the fact. Carrying the emitter's own time is what makes ordering inside
 * a burst mean anything. An unusable value falls back to now rather than
 * dropping the line.
 */
function _stattic_runtime_log_timestamp(mixed $value): string
{
    if (is_string($value) && $value !== '') {
        $parsed = strtotime($value);
        if ($parsed !== false) {
            return gmdate('Y-m-d\TH:i:s.v\Z', $parsed);
        }
    }
    if (is_int($value) || is_float($value)) {
        // Tail streams report epoch milliseconds; anything outside a plausible
        // range is a broken clock, not a timestamp.
        $seconds = (int) ($value > 100000000000 ? $value / 1000 : $value);
        if ($seconds > 0 && $seconds < 4102444800) {
            return gmdate('Y-m-d\TH:i:s.v\Z', $seconds);
        }
    }
    return gmdate('Y-m-d\TH:i:s.v\Z');
}
