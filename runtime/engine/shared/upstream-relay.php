<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';

/**
 * One relay for every lane that puts an untrusted upstream's response on the
 * visitor's connection. A proxy route's origin and a tenant's Functions worker
 * are the same trust class, both answering on the space's own hostname, so both
 * cross here and a lane's difference is an option, never a second filter.
 *
 * The header state machine lives one layer down, in http.php: a status line
 * resets the collected block, and on_headers fires once for the first non-1xx
 * block, so a 1xx preamble's headers can never reach a visitor.
 */

// Hop-by-hop / transport headers describe the hop we terminate (RFC 9110 §7.6.1);
// Content-Length is dropped because this server re-frames the relayed body.
const STATTIC_RELAY_HOP_BY_HOP_RESPONSE_HEADERS = ['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'transfer-encoding', 'upgrade', 'content-length'];

// Upstream cache metadata must not replace the platform policy the lane decided.
const STATTIC_RELAY_STRIPPED_RESPONSE_HEADERS = ['pragma', 'surrogate-control', 'cdn-cache-control'];

// A proxy origin must not plant state on the space hostname. The Functions host
// is different: it executes the space's own application, so its cookies are the
// application's response state and must reach the browser.
const STATTIC_RELAY_STRIPPED_PROXY_STATE_HEADERS = ['set-cookie', 'set-cookie2'];

// Inbound framing that describes the hop we terminate, not the one we open.
const STATTIC_RELAY_DENIED_REQUEST_HEADERS = ['host', 'content-length', 'connection', 'keep-alive', 'transfer-encoding', 'te', 'trailers', 'upgrade', 'expect'];

// Spacefast-Access-* is platform-owned identity forwarding, runtime→upstream: an
// inbound copy is forged by definition.
const STATTIC_RELAY_DENIED_REQUEST_PREFIXES = ['spacefast-access-'];

function _stattic_relay_header_prefixed(string $lowerName, array $prefixes): bool
{
    return array_any($prefixes, static fn (string $prefix): bool => str_starts_with($lowerName, $prefix));
}

// Header-injection guard for every value this runtime hands an upstream. The
// response direction needs none: http.php trims and splits one header line at a
// time, so no control character survives into a relayed value.
function _stattic_relay_safe_header_value(string $value): string
{
    return (string) preg_replace('/[\x00-\x1f\x7f]/', '', $value);
}

/**
 * The request's headers, from the request params themselves.
 *
 * Not getallheaders(): php-fpm derives it from exactly these keys, and it does
 * not EXIST on the plain CLI, where a cron's dispatch would silently relay no
 * headers at all. $_SERVER is the one spelling that holds on every lane, and
 * the same map the strip and the platform assertion in shared/context.php
 * operate on.
 *
 * @return array<string,string>
 */
function _stattic_relay_inbound_headers(bool $lowercase = false): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !is_scalar($value)) {
            continue;
        }
        // CGI/1.1 §4.1: the entity headers arrive without the HTTP_ prefix.
        if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
            $name = $key;
        } elseif (str_starts_with($key, 'HTTP_')) {
            $name = substr($key, 5);
        } else {
            continue;
        }
        // One pass builds the caller's casing, lowercase for Zero and canonical
        // Header-Case for the relay, so nobody rebuilds the map after.
        $name = strtolower(str_replace('_', '-', $name));
        $headers[$lowercase ? $name : ucwords($name, '-')] = (string) $value;
    }
    return $headers;
}

/**
 * The visitor's own headers, filtered once for every upstream lane.
 *   allow          ?list<string>  lowercase names that may forward at all;
 *                                 null forwards everything not denied
 *   deny           list<string>   lane-specific extra names
 *   deny_prefixes  list<string>   lane-specific extra prefixes
 *
 * @return list<array{0: string, 1: string}>
 */
function _stattic_relay_request_headers(array $lane = []): array
{
    $headerMap = _stattic_relay_inbound_headers();
    $allow = is_array($lane['allow'] ?? null) ? $lane['allow'] : null;
    $deny = [...STATTIC_RELAY_DENIED_REQUEST_HEADERS, ...(is_array($lane['deny'] ?? null) ? $lane['deny'] : [])];
    $denyPrefixes = [...STATTIC_RELAY_DENIED_REQUEST_PREFIXES, ...(is_array($lane['deny_prefixes'] ?? null) ? $lane['deny_prefixes'] : [])];

    $forwarded = [];
    foreach ($headerMap as $name => $value) {
        $name = (string) $name;
        $lowerName = strtolower($name);
        if (
            in_array($lowerName, $deny, true)
            || _stattic_relay_header_prefixed($lowerName, $denyPrefixes)
            || ($allow !== null && !in_array($lowerName, $allow, true))
            || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1
        ) {
            continue;
        }
        $forwarded[] = [$name, _stattic_relay_safe_header_value(is_scalar($value) ? (string) $value : '')];
    }
    return $forwarded;
}

// The platform's request id when the edge sent a usable one, otherwise a new
// one under the lane's prefix.
function _stattic_relay_request_id(string $prefix): string
{
    $existing = $_SERVER['HTTP_X_SPACEFAST_REQUEST_ID'] ?? null;
    if (is_string($existing) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $existing) === 1) {
        return $existing;
    }
    return $prefix . bin2hex(random_bytes(12));
}

/**
 * The visitor's request body for an upstream, with its exact byte size (null
 * when the request declared none).
 *
 * PHP parses a multipart/form-data POST into $_POST and $_FILES before any
 * script runs and leaves php://input empty, so that one shape is re-encoded
 * from what PHP kept (see _stattic_relay_multipart_body). Detectable truncation
 * by PHP, or a form that cannot be re-encoded whole, is refused here with a
 * runtime log line rather than reaching the upstream as a smaller form that
 * looks complete.
 *
 * @return array{stream: resource|false, size: ?int}
 */
function _stattic_relay_request_body(string $requestId, string $versionId): array
{
    $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (
        $declared === null
        || $declared === 0
        || strtolower(trim((string) strstr($contentType . ';', ';', true))) !== 'multipart/form-data'
        || preg_match('/[;,]\s*boundary=(?:"([^"]+)"|([^\s;,]+))/i', $contentType, $match) !== 1
        || _stattic_request_body_override() !== null
    ) {
        $stream = _stattic_request_body_stream();
        return ['stream' => $stream, 'size' => $stream === false ? null : $declared];
    }
    $startup = $GLOBALS['SPACEFAST_REQUEST_STARTUP_ERROR'] ?? null;
    $warning = is_array($startup) && is_string($startup['message'] ?? null) ? $startup['message'] : '';
    // Before the raw pass-through: a parse PHP stopped part way leaves the
    // unread tail in php://input, which on its own reads as a complete form.
    $body = _stattic_relay_form_cut_short($warning);
    if ($body === null && _stattic_request_body_contents(1) !== '') {
        // PHP left the body unread (not a POST, or over post_max_size).
        $stream = _stattic_request_body_stream();
        return ['stream' => $stream, 'size' => $stream === false ? null : $declared];
    }
    $body ??= _stattic_relay_form_vars_cut_short(
        $warning,
        $_POST,
        $_FILES,
        $_GET,
        $_COOKIE,
        (int) ini_get('max_input_vars'),
        (int) ini_get('max_file_uploads'),
    );
    $body ??= _stattic_relay_multipart_body($match[1] !== '' ? $match[1] : $match[2], $_POST, $_FILES);
    if (isset($body['stream'])) {
        return $body;
    }

    require_once __DIR__ . '/runtime-log.php';
    require_once __DIR__ . '/errors.php';
    _stattic_runtime_log_write([
        'level' => 'error',
        'requestId' => $requestId,
        'message' => 'The request body could not be forwarded whole.',
        'metadata' => ['code' => 'request_body_not_relayed', 'cause' => $body['cause']],
    ], $versionId);
    $headers = ['X-Spacefast-Request-Id' => $requestId];
    match ($body['status']) {
        413 => _stattic_render_platform_page('request-too-large', 413, $headers, "The request exceeds this site's upload limits.\n"),
        400 => _stattic_render_platform_page('bad-request', 400, $headers, "The form data is malformed.\n"),
        default => _stattic_render_platform_page('runtime-invariant-error', 500, $headers, "The request body could not be forwarded.\n"),
    };
    exit;
}

/**
 * Whether PHP's request-startup warning says it stopped reading this multipart
 * body part way. PHP drops the rest of the form silently apart from this one
 * warning, which custom-redirects.php and init.php capture before anything can
 * replace it. The input-variables warning can come from the query string or
 * cookies too, so _stattic_relay_form_vars_cut_short weighs it against what PHP
 * kept.
 *
 * @return array{status: int, cause: string}|null
 */
function _stattic_relay_form_cut_short(string $warning): ?array
{
    if (
        str_contains($warning, 'Maximum number of allowable file uploads')
        || str_contains($warning, 'Multipart body parts limit exceeded')
    ) {
        return ['status' => 413, 'cause' => $warning];
    }
    if (str_contains($warning, 'File Upload Mime headers garbled')) {
        return ['status' => 400, 'cause' => $warning];
    }
    return null;
}

/**
 * Whether PHP stopped keeping this form's fields at max_input_vars. Past that
 * limit PHP drops every further field, keeps the files, and raises "Input
 * variables exceeded", the same warning a long query string or cookie header
 * raises. Each source has its own count, parsed GET, then the body, then
 * cookies, and the last warning wins. So the warning is the body's unless the
 * query string or cookies are full themselves, and a full body means the body's
 * warning was hidden by the cookies'. A file count at max_file_uploads means a
 * file-limit warning may have been hidden the same way. One case stays open: a
 * body that repeats names, so it kept fewer values than it counted, in a request
 * whose query string or cookies are also full.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed> $files
 * @param array<string,mixed> $get
 * @param array<string,mixed> $cookie
 * @return array{status: int, cause: string}|null
 */
function _stattic_relay_form_vars_cut_short(
    string $warning,
    array $post,
    array $files,
    array $get,
    array $cookie,
    int $maxVars,
    int $maxFiles,
): ?array {
    if ($maxVars <= 0 || !str_contains($warning, 'Input variables exceeded')) {
        return null;
    }
    $fileParts = array_sum(array_map(
        static fn (mixed $file): int => is_array($file) ? _stattic_relay_leaf_count([$file['error'] ?? null]) : 0,
        $files,
    ));
    if (
        _stattic_relay_leaf_count($post) >= $maxVars
        || ($maxFiles > 0 && $fileParts >= $maxFiles)
        || (_stattic_relay_leaf_count($get) < $maxVars && _stattic_relay_leaf_count($cookie) < $maxVars)
    ) {
        return ['status' => 413, 'cause' => $warning];
    }
    return null;
}

/** How many values a parsed input holds, nested lists and rows included. */
function _stattic_relay_leaf_count(array $values): int
{
    return array_sum(array_map(
        static fn (mixed $value): int => is_array($value) ? _stattic_relay_leaf_count($value) : 1,
        $values,
    ));
}

/**
 * A parsed multipart form, re-encoded under the visitor's own boundary so the
 * forwarded Content-Type still describes it. Answers the body and its size, or
 * the refusal: 413 for a file over upload_max_filesize, 500 when the bytes
 * could not be read back or buffered.
 *
 * Reconstruction is lossy, accepted because the host has no raw-body hook.
 * Original field order is lost (files follow fields), repeated names without
 * `[]` collapse (including `files` from <input multiple>), and PHP mangles names:
 * `.` and spaces become `_`, while scalar lists like `i[0]` become `i[]` here.
 * Raw bytes, boundary framing and per-part headers beyond filename and content
 * type are not preserved; the boundary token above is reused.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed> $files
 * @param resource|null $buffer where the body is written; a fresh php://temp by default
 * @return array{stream: resource, size: int}|array{status: int, cause: string}
 */
function _stattic_relay_multipart_body(string $boundary, array $post, array $files, mixed $buffer = null): array
{
    $parts = [];
    foreach ($files as $name => $file) {
        if (is_array($file)) {
            _stattic_relay_multipart_files((string) $name, $file, [], $parts);
        }
    }
    foreach ($parts as $part) {
        if ($part['error'] === UPLOAD_ERR_INI_SIZE || $part['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['status' => 413, 'cause' => 'upload_error_' . $part['error']];
        }
        if ($part['error'] !== UPLOAD_ERR_OK && $part['error'] !== UPLOAD_ERR_NO_FILE) {
            return ['status' => 500, 'cause' => 'upload_error_' . $part['error']];
        }
    }

    $stream = is_resource($buffer) ? $buffer : fopen('php://temp', 'w+b');
    if ($stream === false) {
        return ['status' => 500, 'cause' => 'body_buffer_unavailable'];
    }
    // php://temp opens in memory and moves to disk past 2 MiB, so a full temp
    // directory surfaces as a short write, never as a failed open.
    $write = static fn (string $bytes): bool => fwrite($stream, $bytes) === strlen($bytes);
    $whole = true;
    foreach (_stattic_relay_multipart_fields($post) as [$name, $value]) {
        $whole = $whole && $write('--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . _stattic_relay_multipart_quote($name) . "\"\r\n\r\n" . $value . "\r\n");
    }
    foreach ($whole ? $parts : [] as $part) {
        $whole = $write('--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . _stattic_relay_multipart_quote($part['name'])
            . '"; filename="' . _stattic_relay_multipart_quote($part['filename']) . "\"\r\n"
            . ($part['type'] === '' ? '' : 'Content-Type: ' . _stattic_relay_safe_header_value($part['type']) . "\r\n") . "\r\n");
        if ($whole && $part['error'] === UPLOAD_ERR_OK) {
            $source = fopen($part['tmp_name'], 'rb');
            $whole = $source !== false && stream_copy_to_stream($source, $stream) === $part['size'];
            if ($source !== false) {
                fclose($source);
            }
        }
        $whole = $whole && $write("\r\n");
        if (!$whole) {
            break;
        }
    }
    $whole = $whole && $write('--' . $boundary . "--\r\n");
    if (!$whole) {
        fclose($stream);
        return ['status' => 500, 'cause' => 'body_write_short'];
    }
    $size = (int) ftell($stream);
    rewind($stream);
    return ['stream' => $stream, 'size' => $size];
}

/**
 * Whether a nested level's members are named `name[]`. PHP reads `name[]` and
 * `name[0]` alike only when the member is a value; a row of nested fields needs
 * its index, or each of its fields would start a row of its own.
 */
function _stattic_relay_multipart_bare_list(array $members): bool
{
    return array_is_list($members) && !array_any($members, static fn (mixed $member): bool => is_array($member));
}

/**
 * $_POST back to its form parts, in PHP's order.
 *
 * @return list<array{0: string, 1: string}>
 */
function _stattic_relay_multipart_fields(array $values, ?string $prefix = null): array
{
    $pairs = [];
    $bare = _stattic_relay_multipart_bare_list($values);
    foreach ($values as $key => $value) {
        $name = $prefix === null ? (string) $key : $prefix . '[' . ($bare ? '' : (string) $key) . ']';
        if (is_array($value)) {
            array_push($pairs, ..._stattic_relay_multipart_fields($value, $name));
        } else {
            $pairs[] = [$name, (string) $value];
        }
    }
    return $pairs;
}

/**
 * One $_FILES entry back to its file parts. A nested name (`f[]`, `f[0][doc]`)
 * spreads every attribute into parallel arrays keyed by the same path, and
 * `error` carries that shape for every one of them.
 *
 * @param list<int|string> $path
 * @param list<array{name: string, filename: string, type: string, tmp_name: string, error: int, size: int}> $parts
 */
function _stattic_relay_multipart_files(string $name, array $file, array $path, array &$parts): void
{
    $attribute = static function (string $attribute) use ($file, $path): mixed {
        $value = $file[$attribute] ?? '';
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? '') : '';
        }
        return $value;
    };
    $error = $attribute('error');
    if (is_array($error)) {
        $bare = _stattic_relay_multipart_bare_list($error);
        foreach (array_keys($error) as $key) {
            _stattic_relay_multipart_files($name . '[' . ($bare ? '' : (string) $key) . ']', $file, [...$path, $key], $parts);
        }
        return;
    }
    $text = static fn (string $name): string => is_scalar($value = $attribute($name)) ? (string) $value : '';
    // full_path is the name the browser sent; `name` is PHP's basename of it.
    $filename = $text('full_path');
    $parts[] = [
        'name' => $name,
        'filename' => $filename !== '' ? $filename : $text('name'),
        'type' => $text('type'),
        'tmp_name' => $text('tmp_name'),
        'error' => (int) $error,
        'size' => (int) $text('size'),
    ];
}

// The HTML form encoding's escapes for a quoted part name or filename.
function _stattic_relay_multipart_quote(string $value): string
{
    return str_replace(["\n", "\r", '"'], ['%0A', '%0D', '%22'], $value);
}

/**
 * What an untrusted upstream may put on the space's hostname. Everything not
 * named below relays verbatim, EXCEPT the internal-redirect/sendfile family:
 * under nginx X-Accel-Redirect/X-Sendfile would make the server serve an
 * arbitrary internal path (every space's private version tree) and
 * X-Accel-Limit-Rate pins the worker.
 *   suppress       array<string, true>  platform names that win over the upstream
 *   deny           list<string>         lane-specific extra names
 *   deny_prefixes  list<string>         lane-specific extra prefixes
 *   allow_cookies   bool                 relay response cookies from the app
 *   filter_value   ?callable            map a value, or return null to omit it
 *
 * @return list<array{0: string, 1: string}>
 */
function _stattic_relay_response_header_lines(array $upstreamHeaders, array $cachePolicy, array $lane = []): array
{
    // Lazy: a lane that never reaches an upstream has no headers to filter and
    // should not pay for the policy module.
    require_once __DIR__ . '/safety.php';

    $deny = [
        ...STATTIC_RELAY_HOP_BY_HOP_RESPONSE_HEADERS,
        ...STATTIC_RELAY_STRIPPED_RESPONSE_HEADERS,
        ...(!empty($lane['allow_cookies']) ? [] : STATTIC_RELAY_STRIPPED_PROXY_STATE_HEADERS),
        ...(is_array($lane['deny'] ?? null) ? $lane['deny'] : []),
        // A decided policy replaces the upstream's cache metadata outright; a
        // lane that declared none (a public space's worker) keeps it.
        ...(($cachePolicy['cache_control'] ?? null) !== null ? STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS : []),
    ];
    $denyPrefixes = is_array($lane['deny_prefixes'] ?? null) ? $lane['deny_prefixes'] : [];
    $filterValue = is_callable($lane['filter_value'] ?? null) ? $lane['filter_value'] : null;
    $suppress = is_array($lane['suppress'] ?? null) ? $lane['suppress'] : [];

    $lines = [];
    foreach ($upstreamHeaders as [$name, $value]) {
        $name = (string) $name;
        $value = (string) $value;
        $lowerName = strtolower($name);
        if (
            in_array($lowerName, $deny, true)
            || isset(SPACEFAST_INTERNAL_REDIRECT_HEADERS[$lowerName])
            || isset($suppress[$lowerName])
            || _stattic_relay_header_prefixed($lowerName, $denyPrefixes)
        ) {
            continue;
        }
        if ($filterValue !== null) {
            $value = $filterValue($lowerName, $value);
            if ($value === null) {
                continue;
            }
        }
        $lines[] = [$name, $value];
    }
    return $lines;
}

function _stattic_relay_send_response_headers(array $lines, array $cachePolicy): void
{
    if (!empty($cachePolicy['private'])) {
        _stattic_clear_private_content_response_headers();
    }
    foreach ($lines as [$name, $value]) {
        // Cache-Control replaces any host-level default, never appends to it.
        header($name . ': ' . $value, strtolower((string) $name) === 'cache-control');
    }
}

// Mid-stream failure: the status and some bytes are already on the wire, so
// appending an error page would corrupt the response. PHP cannot force an
// abortive close through FastCGI, so ending the script mid-body is the
// strongest truncation signal available.
function _stattic_relay_abort_after_headers(bool $headersSent): void
{
    if ($headersSent) {
        exit;
    }
}
