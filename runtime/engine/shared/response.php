<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';

// Every HTTP lane loads this module, including the WordPress front controller.
// Apply the policy when headers leave PHP, after publisher headers and removals.
if (PHP_SAPI !== 'cli') {
    header_register_callback('_stattic_send_document_isolation_headers');
}

// Browsers combine Permissions-Policy fields before Structured Fields parsing.
// Keep valid publisher members, excluding the platform-owned document-domain.
function _stattic_document_publisher_policies(array $policies): array
{
    $fields = [];
    foreach ($policies as $policy) {
        if ($policy === 'document-domain=()') {
            continue;
        }
        $parser = __DIR__ . '/structured-fields.php';
        if (!is_file($parser)) {
            return [];
        }
        require_once $parser;
        $members = (new StatticStructuredFields($policy))->dictionaryMembers();
        unset($members['document-domain']);
        if ($members !== []) {
            $fields[] = implode(', ', $members);
        }
    }
    return $fields;
}

// WordPress can flush or exit inside a front controller or template. This
// streaming output handler reclaims PHP's sole header callback at output.
// A flush before output or a later outer buffer can bypass PHP enforcement.
function _stattic_document_isolation_stream_begin(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header_register_callback('_stattic_send_document_isolation_headers');
    }
    ob_start(static function (string $bytes, int $phase): string {
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) === 0) {
            _stattic_enforce_document_isolation();
        }
        return $bytes;
    }, 1);
}

function _stattic_enforce_document_isolation(): void
{
    if (headers_sent()) {
        return;
    }
    // Reclaim PHP's sole header callback so a later exit() still lands on it.
    if (PHP_SAPI !== 'cli') {
        header_register_callback('_stattic_send_document_isolation_headers');
    }
    _stattic_send_document_isolation_headers();
}

// The platform's own access refusals name their stable code in this header
// (`sf fetch` prints it). It is a diagnostic, never an authorization signal:
// the send boundary below drops it from an ordinary authored header map (a
// TypedPress app, WordPress) unless the engine's refusal emitter recorded it,
// but code a Space runs in this process can still set it, so a client reads
// it as what the responding Space reported.
const STATTIC_REFUSAL_HEADER = 'X-Spacefast-Refusal';

/** The engine's refusal code for this response, as its emitter recorded it. */
function _stattic_refusal_header_code(?string $set = null): ?string
{
    static $code = null;
    if ($set !== null) $code = $set;
    return $code;
}

/** Records that the engine is answering with its own refusal `$code`. */
function _stattic_refusal_header_mark(string $code): void
{
    _stattic_refusal_header_code($code);
}

/**
 * The authenticated-response cache ceiling. Set once a request needed a Grant
 * to be admitted, or once a person was projected into WordPress for it; from
 * then on the response leaves PHP `private, no-store` with the edge opt-out,
 * whatever the handler, the app or its render cache set. The edge keys on host
 * and path and ignores Cookie and Vary, so one public copy of a response that
 * depended on who asked would answer everyone.
 */
function _stattic_authenticated_response(?bool $set = null): bool
{
    static $marked = false;
    if ($set === true) $marked = true;
    return $marked;
}

function _stattic_send_document_isolation_headers(): void
{
    if (headers_sent()) {
        return;
    }
    if (_stattic_authenticated_response()) {
        header_remove('Expires');
        header_remove('Surrogate-Control');
        header('Cache-Control: private, no-store', true);
        header('a8c-edge-cache: no-cache', true);
    }
    // An authored header map's copy goes; the one the engine's emitter
    // recorded is sent.
    header_remove(STATTIC_REFUSAL_HEADER);
    $refusal = _stattic_refusal_header_code();
    if ($refusal !== null) {
        header(STATTIC_REFUSAL_HEADER . ': ' . $refusal, true);
    }
    $policies = [];
    $frameAncestors = false;
    foreach (headers_list() as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $separator)));
        if ($name === 'permissions-policy') {
            $policies[] = trim(substr($line, $separator + 1));
        } elseif ($name === 'content-security-policy'
            && preg_match('/(?:^|[;,])\s*frame-ancestors(?:\s|;|,|$)/i', substr($line, $separator + 1)) === 1) {
            $frameAncestors = true;
        }
    }
    // frame-ancestors is the framing policy wherever one is stated: the
    // dashboard frames the editor (wp-admin, SpacePress /wp-admin/new/) by
    // it, while WordPress core adds `X-Frame-Options: SAMEORIGIN` on every
    // admin and login screen. Browsers already let CSP win; sending both only
    // misleads caches, scanners and older clients about which one applies.
    if ($frameAncestors) {
        header_remove('X-Frame-Options');
    }
    // These controls have no effect on non-document responses. Stating them on
    // every response also covers HTML produced with PHP's default media type.
    header('Origin-Agent-Cluster: ?1', true);
    header('Permissions-Policy: document-domain=()', true);
    foreach (_stattic_document_publisher_policies($policies) as $policy) {
        header('Permissions-Policy: ' . $policy, false);
    }
    if (
        getenv('SPACEFAST_RUNTIME_TEST_MODE') === '1'
        && ($_SERVER['HTTP_X_SPACEFAST_TEST_INCLUDED_FILES'] ?? '') === '1'
    ) {
        $deprecated = [];
        foreach (get_included_files() as $includedFile) {
            $normalized = str_replace('\\', '/', $includedFile);
            if (str_contains($normalized, '/engine/deprecated/')) {
                $deprecated[] = substr($normalized, (int) strpos($normalized, '/engine/deprecated/') + 1);
            }
        }
        header('X-Spacefast-Test-Deprecated-Includes: ' . ($deprecated === [] ? 'none' : implode(',', $deprecated)), true);
    }
}

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
    _stattic_enforce_document_isolation();
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
    // Always exit 0: transport failures are the non-zero/garbage cases.
    if (defined('STATTIC_RUNTIME_DISPATCH_CLI')) {
        echo json_encode(['status' => $status, 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    // wp.cloud's Nginx layer may otherwise buffer the complete upstream
    // request, defeating fastcgi_finish_request(): the PHP worker starts its
    // deferred purge while the control plane is still waiting for bytes. Only
    // lanes that opted into pre-deferred flushing get this instruction.
    if (
        function_exists('_stattic_flush_response_before_deferred')
        && _stattic_flush_response_before_deferred()
    ) {
        $headers += ['X-Accel-Buffering' => 'no'];
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

// The no-store problem refusal every visitor-facing lane (Zero, storage,
// functions relay) answers errors with.
function _stattic_problem_refused(int $status, string $code, string $message): never
{
    _stattic_problem_response($status, $code, $message, [], ['Cache-Control' => 'no-store']);
}

/**
 * The one 405. Allow is not optional: a refusal that does not name the methods
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
// Header maps go out through _stattic_send_response_headers (context.php).

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
