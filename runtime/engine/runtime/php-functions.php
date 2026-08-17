<?php

declare(strict_types=1);

/**
 * PHP Functions — the `t=php` response action (slice A).
 *
 * Executes a committed `functions/<route>.php` IN THIS php-fpm worker, after
 * `_stattic_tenant_harden()` (tenant-prelude.php) has jailed the worker's
 * filesystem view to the space's own content store and scrubbed every platform
 * secret from the environment. In-process `include` — not a subprocess — is the
 * owner-ruled execution model: a wp.cloud site owns no second fpm pool, no
 * per-space uid and no `disable_functions`, so the only containment available
 * is the one the engine applies to its own worker before handing over control
 * (see tenant-prelude.php's header for the full model and its limits).
 *
 * Hard sequencing contract, in order, all before the prelude runs:
 *   1. every engine `require` (nothing outside the jail is readable after);
 *   2. the visitor identity verify (it reads `.stattic/**` keys the jail
 *      denies) — sf_auth() serves the PRE-VERIFIED result, it never verifies;
 *   3. every brokered capability's configuration: the database credential, and
 *      the service broker's binary path plus the platform service keys. All of
 *      it is read from the process environment the scrub is about to empty, so
 *      a capability that resolved anything lazily would resolve it to nothing;
 *   4. the request body read, the scratch tmp mkdir, the admission slot flock.
 * After the prelude: the tenant include, and a response path that touches no
 * file outside the jail — platform error pages are template files, so every
 * post-harden failure answers as an inline problem+json document instead.
 *
 * One request executes ONE space's handler, enforced by the `active` latch
 * below and load-bearing for the prelude: `open_basedir` can only tighten, so
 * a second space's pin in the same request would be trapped by the first
 * (tenant-prelude.php "PER-REQUEST INI RESET is load-bearing").
 *
 * Admission is a per-space set of flock slots rather than
 * `_stattic_admission_acquire_or_shed`: that lane's release reopens counter
 * files under the private root at shutdown, which the jail (still pinned when
 * shutdown functions run) would deny — leaking the slot for the whole stale
 * window. A flock held on a pre-opened handle is released by the kernel when
 * the request's handles close, jail or no jail, crash or no crash.
 */

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/admission.php';
require_once __DIR__ . '/../shared/cache-policy.php';
require_once __DIR__ . '/../shared/problem.php';
// The database credential and the socket live here and only here: sf_db() hands
// tenant code operation frames, never a connection.
require_once __DIR__ . '/../shared/db-broker.php';
// sf_spam()/sf_email() run the native `service-broker` executor, the same
// one-shot child the Functions relay spawns for a dispatched worker.
require_once __DIR__ . '/../shared/native-process.php';
require_once __DIR__ . '/../shared/errors.php';
require_once __DIR__ . '/../shared/runtime-log.php';
require_once __DIR__ . '/tenant-prelude.php';
require_once __DIR__ . '/access-rules.php';
// Identity-shape authority: sf_auth() answers exactly what a Zero endpoint's
// `auth` context answers, from the same `_stattic_zero_auth_context`.
require_once __DIR__ . '/zero.php';

/**
 * Response header names only the platform may decide, beyond the generated
 * PLATFORM_OWNED lists: `x-accel-*` is nginx's internal control surface — a
 * handler-set `X-Accel-Redirect` would make nginx serve an arbitrary origin
 * file with platform authority.
 */
const STATTIC_PHP_FUNCTIONS_DENIED_HEADER_PREFIXES = ['x-accel-'];

/**
 * What this lane grants the platform-service broker, in the broker's own wire
 * vocabulary (`ServiceGrant::from_wire` in
 * crates/stattic-zero-runner/src/services.rs is what enforces it). It is the
 * sf_* surface written out: the two capabilities this file exposes and no
 * others, so an executor that somehow received a different frame is refused
 * inside the child as well as here. `gravatar.profile` is deliberately absent —
 * there is no sf_gravatar(), and a grant nothing can spend is only reach.
 */
const STATTIC_PHP_FUNCTIONS_SERVICE_GRANT = ['spam.check', 'email.send'];
const STATTIC_PHP_FUNCTIONS_SERVICE_GRANT_ENV = 'SPACEFAST_SERVICE_BROKER_GRANT';

// A visitor is holding an admission slot on this space's fpm pool for the whole
// call, and the broker's own upstream timeout is 5s — this bounds a child that
// hangs before or after that, not the upstream itself.
const STATTIC_PHP_FUNCTIONS_SERVICE_TIMEOUT_MS = 15000;
// One JSON line: a verdict, a message id, or a refusal. Anything approaching
// these means the child is broken rather than the answer being large.
const STATTIC_PHP_FUNCTIONS_SERVICE_STDOUT_MAX_BYTES = 1048576;
const STATTIC_PHP_FUNCTIONS_SERVICE_STDERR_MAX_BYTES = 65536;

/** Per-request bridge state, shared with the sf_* helpers. */
function &_stattic_php_functions_state(): array
{
    static $state = [
        'active' => false,
        'method' => 'GET',
        'auth' => null,
        'raw_body' => null,
        'private' => false,
        'noindex' => false,
        'database' => false,
        'services' => null,
    ];

    return $state;
}

/**
 * The `t=php` dispatch: containment, then capability, then the tenant file.
 */
function _stattic_php_functions_serve(array $context, array $action, string $requestPath): never
{
    $state = &_stattic_php_functions_state();
    if ($state['active']) {
        // One request, one space, one handler — the prelude's jail cannot be
        // re-pinned, so a second dispatch in this worker request is a platform
        // invariant failure, never a second include.
        _stattic_php_functions_problem(500, 'runtime_invariant_violation', 'A PHP function already executed on this request.');
    }

    $method = (string) $context['method'];
    if ($method === 'TRACE' || $method === 'CONNECT') {
        _stattic_render_method_not_allowed_lazy();
    }
    // Control paths stay terminal exactly as on the JS Functions lane: a tenant
    // handler must never answer `/__spacefast/...` or `/_headers` for the platform.
    if (_stattic_path_is_reserved($requestPath)) {
        _stattic_php_functions_problem(404, 'not_found', 'Not found.');
    }

    $privateRoot = (string) $context['private_root'];
    $spaceId = (string) $context['space_id'];
    $serving = is_array($context['serving']) ? $context['serving'] : [];
    $sha = is_string($action['sha'] ?? null) ? $action['sha'] : '';
    if ($spaceId === '' || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
        _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime PHP function action metadata is malformed.');
    }

    // The handler bytes live in the space's own content store — which is also
    // the prelude jail, so the include target stays readable after hardening
    // and a handler can never be resolved outside its own space.
    $phpRoot = _stattic_space_root($privateRoot, $spaceId) . '/blobs';
    $handler = $phpRoot . '/' . substr($sha, 0, 2) . '/' . $sha;
    if (!is_file($handler)) {
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime PHP function handler blob is missing.');
    }

    // ---- admission: one flock slot per concurrent execution ---------------
    $limit = _stattic_admission_configured_limit($serving);
    $slotRoot = $privateRoot . '/runtime/admission';
    _stattic_runtime_mkdir($slotRoot);
    $held = false;
    for ($slot = 0; $slot < $limit; $slot++) {
        $handle = _stattic_lock_acquire(
            $slotRoot . '/' . _stattic_admission_sanitize_key($spaceId) . '.php-fx.slot' . $slot,
            STATTIC_LOCK_TRY
        );
        if ($handle !== false) {
            // Held for the request's lifetime; the kernel releases it when the
            // request's handles close, which no jail can prevent.
            $held = true;
            break;
        }
    }
    if (!$held) {
        _stattic_admission_record_shed($privateRoot, $spaceId, $limit, 'php_function');
        _stattic_render_admission_shed(STATTIC_ADMISSION_RETRY_AFTER_SECONDS);
    }

    // ---- everything the handler may ask for, resolved BEFORE the jail -----
    $state['method'] = $method;
    $state['private'] = (bool) $context['private_cache'];
    $state['noindex'] = !empty(is_array($context['host_entry'] ?? null) ? $context['host_entry']['noindex'] ?? null : null);
    // The engine's already-verified visitor identity: same verify, same shape
    // as a Zero endpoint's auth context. Runs now because verification reads
    // the `.stattic/**` key material the jail denies.
    $state['auth'] = _stattic_zero_auth_context($serving, (string) $context['host']);

    // Same reason, same moment: the database credential is resolved from
    // platform configuration the jail is about to deny, and bound into the
    // broker's process memory. The handler is handed frames, never a DSN or a
    // connection — but note what that is and is not worth. In-process binding
    // is not a credential boundary against the tenant: this is the same
    // process, and tenant-prelude.php's own header says a handler can still
    // `exec` its way to any on-disk secret, which is precisely why cross-team
    // PHP never co-locates. What binding here DOES buy is that the credential
    // never reaches the handler by accident, and that a subprocess the handler
    // spawns still inherits a scrubbed environment.
    //
    // One resolution feeds both bindings: the service broker writes an accepted
    // email into this space's own outbox, so it needs the same database URL.
    $runnerEnv = _stattic_php_functions_runner_env((string) $context['version_root']);
    $state['database'] = _stattic_php_functions_bind_database($runnerEnv);
    $state['services'] = _stattic_php_functions_bind_services($context, $runnerEnv);

    $bodyCap = STATTIC_RUNTIME_EXECUTION_BODY_BYTES_DEFAULT;
    $raw = in_array($method, ['GET', 'HEAD'], true)
        ? ''
        : (string) @file_get_contents('php://input', false, null, 0, $bodyCap + 1);
    if (strlen($raw) > $bodyCap) {
        _stattic_php_functions_problem(413, 'payload_too_large', 'Request body exceeds the PHP function body limit.');
    }
    $state['raw_body'] = $raw;

    // Per-request scratch tmp inside the space's own private area: part of the
    // jail, so uploads/sessions/sys-temp land here and the cleanup below still
    // works after hardening.
    $scratchTmp = _stattic_space_root($privateRoot, $spaceId) . '/tmp/php-fx-' . bin2hex(random_bytes(8));
    _stattic_runtime_mkdir($scratchTmp);
    register_shutdown_function(static function () use ($scratchTmp): void {
        foreach (glob($scratchTmp . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($scratchTmp);
    });

    // From the first output byte — however it is produced, including a tenant
    // flush() — the platform re-takes the headers it owns.
    header_register_callback('_stattic_php_functions_enforce_platform_headers');
    ob_start();
    $state['active'] = true;

    // ---- containment, then control handover -------------------------------
    try {
        _stattic_tenant_harden($spaceId, $phpRoot, $scratchTmp);
    } catch (Throwable $error) {
        // Fail closed: never run tenant code unhardened. File-free response —
        // the platform error pages are templates the jail may already deny.
        _stattic_php_functions_problem(500, 'runtime_invariant_violation', 'PHP function containment failed.');
    }

    try {
        // A bare scope: the handler sees the sf_* helpers and superglobals,
        // never the bridge's locals.
        (static function (string $__stattic_handler): void {
            include $__stattic_handler;
        })($handler);
    } catch (Throwable $error) {
        // Through the runtime log writer, not a bare error_log: an untagged
        // line lands in the same file but the space's own log surface cannot
        // tell it from one of the engine's, so the author never sees the throw
        // that took their handler down.
        _stattic_runtime_log_write([
            'level' => 'error',
            'message' => $error->getMessage(),
            'handlerName' => $requestPath,
            'metadata' => ['uncaught' => true, 'class' => $error::class],
        ], (string) $context['version_id']);
        _stattic_php_functions_problem(500, 'php_function_error', 'The PHP function failed.');
    }

    // The handler produced its response with echo/header() and returned.
    $body = (string) ob_get_clean();
    $status = http_response_code();
    _stattic_php_functions_send(is_int($status) && $status > 0 ? $status : 200, [], $body);
}

/**
 * Platform header ownership at send time. Runs as the header callback, so it
 * fires however the response starts — the bridge's own send, a tenant echo
 * that fills the buffer, or an explicit tenant flush().
 */
function _stattic_php_functions_enforce_platform_headers(): void
{
    $state = &_stattic_php_functions_state();
    if (!$state['active']) {
        return;
    }
    $hasCacheControl = false;
    foreach (headers_list() as $line) {
        $name = strtolower(trim((string) strstr($line, ':', true)));
        if ($name === '') {
            continue;
        }
        // The platform-owned set is context.php's (every lane strips the same
        // names); this lane adds only its own prefixes on top.
        $denied = _stattic_platform_owns_header($name);
        foreach (STATTIC_PHP_FUNCTIONS_DENIED_HEADER_PREFIXES as $prefix) {
            $denied = $denied || str_starts_with($name, $prefix);
        }
        if ($denied) {
            header_remove($name);
            continue;
        }
        if ($name === 'cache-control') {
            $hasCacheControl = true;
        }
    }
    // A protected space's dynamic response is never shared-cacheable, whatever
    // the handler declared; an open space keeps a handler-declared policy and
    // otherwise defaults to no-store — a function's URL is un-purgeable.
    if ($state['private']) {
        header('cache-control: ' . STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
    } elseif (!$hasCacheControl) {
        header('cache-control: ' . STATTIC_CACHE_CONTROL_NO_STORE, true);
    }
    if ($state['noindex']) {
        header('x-robots-tag: noindex, nofollow', true);
    }
}

/**
 * The one response terminal for this lane. File-free by contract: it may run
 * after the jail is pinned, so it touches memory and the output stream only.
 */
function _stattic_php_functions_send(int $status, array $headers, string $body): never
{
    $state = &_stattic_php_functions_state();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        header('content-length: ' . strlen($body), true);
    }
    if ($state['method'] !== 'HEAD') {
        echo $body;
    }
    exit;
}

/** An RFC 9457 problem response, through the same file-free terminal. */
function _stattic_php_functions_problem(int $status, string $code, string $detail): never
{
    $body = json_encode(_stattic_problem_document($status, $code, $detail), JSON_UNESCAPED_SLASHES);
    _stattic_php_functions_send($status, [
        'content-type' => STATTIC_PROBLEM_MEDIA_TYPE,
        'cache-control' => STATTIC_CACHE_CONTROL_NO_STORE,
    ], ($body === false ? '{}' : $body) . "\n");
}

// ---------------------------------------------------------------------------
// The sf_* helper prelude — the API a functions/<route>.php handler writes to.
// ---------------------------------------------------------------------------

/**
 * The parsed request body. JSON bodies decode to arrays, form bodies (urlencoded
 * and multipart) answer as the parsed fields; any other body answers as its raw
 * string, and no body answers null. A malformed JSON body is the caller's
 * error and fails loudly as 400 — never a silent null.
 */
function sf_body(): array|string|null
{
    static $resolved = false;
    static $parsed = null;
    if ($resolved) {
        return $parsed;
    }
    $resolved = true;
    $state = &_stattic_php_functions_state();
    $raw = (string) ($state['raw_body'] ?? '');
    $contentType = strtolower(trim((string) strstr(($_SERVER['CONTENT_TYPE'] ?? '') . ';', ';', true)));
    if ($contentType === 'application/x-www-form-urlencoded' || $contentType === 'multipart/form-data') {
        return $parsed = $_POST;
    }
    if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            _stattic_php_functions_problem(400, 'invalid_request_body', 'The request body is not valid JSON.');
        }

        return $parsed = $decoded;
    }

    return $parsed = ($raw === '' ? null : $raw);
}

/** Send `$data` as a JSON response and finish the request. */
function sf_json(mixed $data, int $status = 200): never
{
    $body = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        _stattic_php_functions_problem(500, 'php_function_error', 'The PHP function produced unencodable JSON.');
    }
    _stattic_php_functions_send($status, ['content-type' => 'application/json; charset=utf-8'], $body . "\n");
}

/**
 * The labeled database environment for this version, resolved exactly as the
 * capsule runner resolves it: a space that declares `DATABASE_URL` reaches the
 * same database from PHP and from a capsule, and a space that declares none
 * reaches the provider database the site already owns. Read once — it costs a
 * version-config read, and both brokered capabilities need the answer.
 *
 * @return array<string,string>
 */
function _stattic_php_functions_runner_env(string $versionRoot): array
{
    $config = $versionRoot === ''
        ? []
        : _stattic_runtime_read_json($versionRoot . '/' . STATTIC_ZERO_CONFIG_PATH);

    return _stattic_zero_runner_base_env(is_array($config) ? $config : []);
}

/**
 * Bind the space's database into the broker, before the jail. Returns whether a
 * database is attached at all; sf_db() answers with a named problem rather than
 * a driver error when it is not.
 *
 * @param array<string,string> $env
 */
function _stattic_php_functions_bind_database(array $env): bool
{
    $url = is_string($env['SPACEFAST_ZERO_DATABASE_URL'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL'] : '';
    $source = is_string($env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] ?? null)
        ? $env['SPACEFAST_ZERO_DATABASE_URL_SOURCE']
        : null;
    if ($url === '') {
        // An empty grant, not an absent one: `null` means every capability, so
        // an unattached space must be told what it may do rather than left to
        // the default.
        _stattic_db_broker_bind(null, null);
        _stattic_db_broker_grant([]);

        return false;
    }
    _stattic_db_broker_bind($url, $source);
    // The broker's own capability names (db-broker.php `_stattic_db_broker_capability_granted`).
    _stattic_db_broker_grant(['db.read', 'db.write']);

    return true;
}

/**
 * Resolve everything the platform-service broker needs, before the jail: the
 * platform's service credentials and the native binary's path both come from
 * configuration the scrub is about to empty. The credentials have nowhere else
 * to come from at all — bind after hardening and the broker is handed nothing,
 * which the suite pins by asserting a refusal that only a configured sender
 * list can produce. The binary's path degrades more quietly: it falls back to
 * the engine's sibling `bin/`, so a post-jail resolve keeps working on a
 * manifest install and silently misses on a release-root one
 * (`SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT`, scrubbed by then). Resolved here so
 * neither case depends on which install layout is underneath.
 *
 * The invocation identity is minted HERE and never read from a request header.
 * It is half of the outbox's primary key, so a visitor who could name it could
 * collide with another visitor's queued message and have it silently deduped
 * away. Each call spends its own ordinal of it (see the call below).
 *
 * @param array<string,string> $runnerEnv
 * @return array{binary:string,env:array<string,string>,invocation:string,calls:int}
 */
function _stattic_php_functions_bind_services(array $context, array $runnerEnv): array
{
    return [
        'binary' => _stattic_runtime_native_binary(),
        // Identical construction to the relay's executor env
        // (functions-relay.php `_stattic_functions_relay_executor_env`), so a
        // brokered call behaves the same whether it came from a dispatched
        // worker or from a handler in this process.
        'env' => $runnerEnv + _stattic_service_broker_env([
            'spaceId' => (string) $context['space_id'],
            'versionId' => (string) $context['version_id'],
            // Per call, not per request — set at the call site.
            'invocationId' => '',
        ]) + [
            STATTIC_PHP_FUNCTIONS_SERVICE_GRANT_ENV => implode(',', STATTIC_PHP_FUNCTIONS_SERVICE_GRANT),
        ],
        'invocation' => 'phpfx_' . bin2hex(random_bytes(12)),
        'calls' => 0,
    ];
}

/**
 * The verified visitor identity — the engine's own verification, performed
 * before the handler ran, in the exact shape a Zero endpoint's `auth` context
 * has: `user`, `userId`, `provider`, `isGuest`, `isAuthenticated`, and the
 * profile fields when identified.
 */
function sf_auth(): array
{
    $state = &_stattic_php_functions_state();

    return is_array($state['auth']) ? $state['auth'] : _stattic_zero_guest_auth_context();
}

// ---- brokered capabilities -------------------------------------------------

/**
 * A database operation the broker refused or the driver failed. `$errorCode` is the
 * platform's own error vocabulary (`zero_db_query_failed`,
 * `zero_db_capability_denied`, …) — the same code the capsule lane surfaces for
 * the same failure, so one contract explains both. Uncaught, it becomes a 500
 * the way any other handler exception does; the message is logged, never sent.
 */
final class SpacefastDbError extends RuntimeException
{
    public function __construct(
        // Not `$code`: Exception already owns that name as a protected int.
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

/**
 * The tenant's view of the database: three verbs over the broker, and nothing
 * that could carry a credential. Every call is a frame in and rows out, so the
 * handler holds no connection, no DSN and no statement handle — the same
 * arrangement the capsule lane has, expressed for a classic PHP file.
 *
 * Parameters are always bound, never interpolated: `?` placeholders are the
 * only supported shape, matching the capsule SDK's tagged template.
 */
final class SpacefastDb
{
    /**
     * Read rows. `SELECT id, title FROM notes WHERE author = ?`.
     *
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $answer = $this->send(['sql' => $sql, 'params' => array_values($params)]);
        $rows = $answer['rows'] ?? [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * Write, and learn what the write did.
     *
     * @param list<mixed> $params
     * @return array{affectedRows:int|string,lastInsertId:int|string}
     */
    public function execute(string $sql, array $params = []): array
    {
        $answer = $this->send(['sql' => $sql, 'params' => array_values($params), 'mode' => 'execute']);

        return [
            'affectedRows' => $answer['affectedRows'] ?? 0,
            'lastInsertId' => $answer['lastInsertId'] ?? 0,
        ];
    }

    /**
     * Run statements as one unit: all of them commit, or the first failure
     * rolls the whole set back and throws. Each entry is
     * `['sql' => ..., 'params' => [...], 'mode' => 'execute']`; a `mode` of
     * `execute` writes, its absence reads.
     *
     * @param list<array{sql:string,params?:list<mixed>,mode?:string}> $statements
     * @return list<array<string,mixed>>
     */
    public function transaction(array $statements): array
    {
        $frames = [];
        foreach ($statements as $statement) {
            $frames[] = [
                'sql' => (string) ($statement['sql'] ?? ''),
                'params' => array_values(is_array($statement['params'] ?? null) ? $statement['params'] : []),
                'mode' => (string) ($statement['mode'] ?? 'query'),
            ];
        }
        $answer = $this->send(['mode' => 'transaction', 'statements' => $frames]);
        $results = $answer['results'] ?? [];

        return is_array($results) ? $results : [];
    }

    /**
     * @param array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function send(array $operation): array
    {
        $encoded = json_encode($operation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new SpacefastDbError('zero_db_operation_invalid', 'Zero DB operation could not be encoded.');
        }
        // BIGINT_AS_STRING both ways: the broker prints unsigned 64-bit values
        // PHP's int cannot hold, and rounding them through a float here would
        // undo the exactness the encoder went to trouble for.
        $decoded = json_decode(_stattic_db_broker_execute($encoded), true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($decoded)) {
            throw new SpacefastDbError('zero_db_operation_invalid', 'Zero DB response could not be parsed.');
        }
        if (($decoded['ok'] ?? false) !== true) {
            throw new SpacefastDbError(
                is_string($decoded['code'] ?? null) ? $decoded['code'] : 'zero_db_operation_invalid',
                is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Zero DB operation failed.'
            );
        }

        return $decoded;
    }
}

/**
 * The space's database. Answers a named problem — not a driver error — when the
 * space has no database attached, because that is a publish-time fact about the
 * space rather than something the handler did wrong.
 */
function sf_db(): SpacefastDb
{
    $state = &_stattic_php_functions_state();
    if ($state['database'] !== true) {
        _stattic_php_functions_problem(503, 'php_function_database_unavailable', 'This Space has no database attached.');
    }
    static $handle = null;
    if (!$handle instanceof SpacefastDb) {
        $handle = new SpacefastDb();
    }

    return $handle;
}

// ---- brokered platform services --------------------------------------------

/**
 * A platform service refused the call, or could not be reached. `$errorCode` is
 * the broker's own vocabulary (`service_not_configured`,
 * `service_capability_denied`, `service_upstream_unavailable`,
 * `email_sender_unverified`, …) — the same codes the capsule tier surfaces for
 * the same failures, so one contract explains both. The one code with no
 * capsule twin is `service_broker_unavailable`: over there the broker is
 * in-process and cannot fail to start.
 *
 * Uncaught, it becomes a 500 the way any other handler exception does. A
 * handler that wants to branch — hold a comment when spam checking is down
 * rather than lose it — catches it and reads `errorCode`.
 */
final class SpacefastServiceError extends RuntimeException
{
    public function __construct(
        // Not `$code`: Exception already owns that name as a protected int.
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

/**
 * Akismet's recognised content types, mirroring `SPAM_CONTENT_TYPES` in
 * packages/common/src/contracts/runtime-services.ts. The vendor is not in the
 * name of anything an author touches, but the vocabulary is Akismet's because
 * the classifier is trained on it.
 */
const STATTIC_PHP_FUNCTIONS_SPAM_TYPES = ['comment', 'reply', 'forum-post', 'contact-form', 'signup', 'message'];

/**
 * One frame to the native `service-broker` executor, one answer back — the same
 * child, the same protocol and the same grant the Functions relay hands a
 * dispatched worker (functions-relay.php `SPACEFAST_FUNCTIONS_RELAY_BROKERS`).
 * Reusing it is what keeps a spam verdict identical whether it was asked for
 * from a capsule, a Functions worker, or this file.
 *
 * ---------------------------------------------------------------------------
 * Spawning a subprocess AFTER the jail: what actually happens
 * ---------------------------------------------------------------------------
 * It works, and the suite proves it rather than arguing it: `open_basedir` is a
 * check inside PHP's own file APIs, so it never reaches `proc_open` — which is
 * the same fact tenant-prelude.php states from the other direction (CANNOT.A:
 * the prelude does not and cannot remove `exec`/`proc_open`, and a child's
 * filesystem view is not bounded by the jail). The binary sits outside the
 * jail; nothing here stats it first, because that stat WOULD be denied — a
 * failed spawn is already the answer.
 *
 * So this call is not contained from the handler in any OS sense. What it buys
 * is narrower and worth stating plainly:
 *   * the Akismet key and the database URL are handed to THIS child as an
 *     explicit environment, built here — the scrub emptied the worker's own
 *     environment before the handler ran, so a subprocess the HANDLER spawns
 *     inherits neither, and `proc_open` gets no inherited environment either;
 *   * the credential is never a value tenant code was given, so it cannot leak
 *     by accident — through a var_dump, a stack trace, or an echoed config;
 *   * the grant is enforced inside the child as well as here, so a frame naming
 *     an ungranted service is refused even if this file were wrong.
 * What it does NOT buy: this is one process with tenant code, and a handler can
 * call the engine's own functions or `exec` its way to anything on disk that
 * the fpm uid can read. That residual is why cross-team PHP never co-locates
 * (tenant-prelude.php, trust model) — not something this file fixes.
 *
 * @param array<string,mixed> $payload
 */
function _stattic_php_functions_service_call(string $service, string $operation, array $payload): mixed
{
    $state = &_stattic_php_functions_state();
    $bundle = $state['services'];
    if (!is_array($bundle)) {
        throw new SpacefastServiceError('service_broker_unavailable', 'Platform services are not bound on this request.');
    }
    // An object, never a list: the broker deserializes the payload into a map,
    // and an empty PHP array would encode as `[]` and be refused as malformed.
    $frame = json_encode(
        ['service' => $service, 'operation' => $operation, 'payload' => (object) $payload],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($frame)) {
        throw new SpacefastServiceError('service_payload_invalid', 'The service frame could not be encoded.');
    }

    // The executor's lifetime is one frame, so its email effect counter always
    // starts at zero — which makes the invocation id, not the counter, what
    // separates two sends. Each call spends its own ordinal of this request's
    // minted identity; two sf_email() calls in one request are therefore two
    // outbox rows rather than one row written twice under the same key.
    $env = $bundle['env'];
    $env['SPACEFAST_SERVICE_INVOCATION_ID'] = $bundle['invocation'] . '_' . (string) $bundle['calls'];
    $state['services']['calls'] = (int) $bundle['calls'] + 1;

    $result = _stattic_runtime_run_subprocess(
        [(string) $bundle['binary'], 'service-broker'],
        $env,
        $frame,
        null,
        STATTIC_PHP_FUNCTIONS_SERVICE_TIMEOUT_MS,
        STATTIC_PHP_FUNCTIONS_SERVICE_STDOUT_MAX_BYTES,
        STATTIC_PHP_FUNCTIONS_SERVICE_STDERR_MAX_BYTES
    );
    $stdout = trim((string) $result['stdout']);
    if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0 || $stdout === '') {
        // Distinct from every refusal the broker itself produces: a handler can
        // tell "the platform could not ask" from "the service said no".
        throw new SpacefastServiceError('service_broker_unavailable', 'The platform service broker did not answer.');
    }
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        throw new SpacefastServiceError('service_broker_unavailable', 'The platform service broker returned an unreadable answer.');
    }
    if (($decoded['ok'] ?? false) !== true) {
        throw new SpacefastServiceError(
            is_string($decoded['code'] ?? null) ? $decoded['code'] : 'service_payload_invalid',
            is_string($decoded['message'] ?? null) ? $decoded['message'] : 'The platform service refused the call.'
        );
    }

    return $decoded['result'] ?? null;
}

/**
 * Check a submission for spam and get a verdict back:
 * `['spam' => bool, 'discard' => bool]`. `discard` is Akismet's "blatant,
 * pervasive" flag — its own guidance is that such a submission may be dropped
 * outright rather than held for review, so it is surfaced instead of folded in.
 *
 *   $verdict = sf_spam([
 *       'content' => $body['message'],
 *       'authorName' => $body['name'],
 *       'authorEmail' => $body['email'],
 *       'userIp' => $visitorIp,
 *       'type' => 'contact-form',
 *   ]);
 *
 * This detached PHP helper uses the complete `SpamSubmission` contract
 * (packages/common/src/contracts/runtime-services.ts): `content` and `userIp`
 * are required, `type` defaults to `comment`, `userAgent` and `referrer`
 * default to this request's own headers, and `permalink`, `authorName`,
 * `authorEmail`, `authorUrl`, `authorRole`, `createdAt` and `languages` are
 * optional.
 *
 * `userIp` is the one the platform will NOT fill in, and the reason is worth
 * knowing: `REMOTE_ADDR` here is the provider's proxy rather than the visitor,
 * and every edge header that claims otherwise reaches PHP client-settable
 * (access-rules.php, live probe). Defaulting it would hand Akismet one address
 * for every visitor of every site on the box — a worse verdict than none, and
 * shared reputation nobody chose. The caller names it here. Zero capsules use
 * the request-aware contract instead, and the trusted invocation envelope
 * supplies the address before the same broker is called.
 *
 * The correction verbs (`report_spam` / `report_ham`) stay on the capsule
 * surface: they belong to a moderation flow, not to the request that took the
 * submission, and nothing in this lane has one yet.
 *
 * @param array<string,mixed> $submission
 * @return array{spam:bool,discard:bool}
 */
function sf_spam(array $submission): array
{
    $verdict = _stattic_php_functions_service_call('spam', 'check', _stattic_php_functions_spam_payload($submission));

    return [
        'spam' => is_array($verdict) && ($verdict['spam'] ?? false) === true,
        'discard' => is_array($verdict) && ($verdict['discard'] ?? false) === true,
    ];
}

/**
 * Queue one message and get its id back.
 *
 *   $messageId = sf_email([
 *       'to' => $body['email'],
 *       'subject' => 'Thanks for writing in',
 *       'text' => "We got your message.\n",
 *   ]);
 *
 * Fields are `EmailMessage` from the shared contract: `to` (an address, an
 * `['email' => ..., 'name' => ...]` pair, or a list of either), `subject`, and
 * at least one of `text` / `html`; `cc`, `bcc`, `replyTo` and `headers` are
 * optional. `from` is optional here — it defaults to the space's first verified
 * sender, which this request already resolved — and an explicit one must still
 * be a verified sender or the broker refuses with `email_sender_unverified`.
 * Who a space may send as is management state, not version content: rolling a
 * version back does not roll back which addresses it has proven it owns.
 *
 * The id names the message; it is not a claim that anything was delivered.
 * Accepting it writes a row in this space's own outbox, which a later delivery
 * pass drains.
 *
 * That outbox row lands on the BROKER's connection, not on this request's — so
 * unlike a capsule's send, it does not join an sf_db() transaction. A handler
 * that rolls its transaction back has still queued the mail. Send after the
 * write it is announcing, not inside it. A Space with no database at all has
 * nowhere to queue, and the broker says so: `email_outbox_unavailable`.
 *
 * @param array<string,mixed> $message
 */
function sf_email(array $message): string
{
    $answer = _stattic_php_functions_service_call('email', 'send', _stattic_php_functions_email_payload($message));
    $messageId = is_array($answer) && is_string($answer['messageId'] ?? null) ? $answer['messageId'] : '';
    if ($messageId === '') {
        throw new SpacefastServiceError('email_message_invalid', 'The message was not given an id.');
    }

    return $messageId;
}

/**
 * The `SpamSubmission` normalization, mirroring `spamPayload` in the shared
 * client source. It runs here rather than in the broker for the same reason it
 * runs in that client: an author gets the field name they wrote back, before a
 * process is spawned. The broker validates again — this is convenience, not the
 * boundary.
 *
 * @param array<string,mixed> $submission
 * @return array<string,mixed>
 */
function _stattic_php_functions_spam_payload(array $submission): array
{
    $content = is_string($submission['content'] ?? null) ? $submission['content'] : '';
    if ($content === '') {
        throw new SpacefastServiceError('service_payload_invalid', 'Submission content is required.');
    }
    $userIp = is_string($submission['userIp'] ?? null) ? trim($submission['userIp']) : '';
    if ($userIp === '') {
        throw new SpacefastServiceError(
            'service_payload_invalid',
            'Submission userIp is required: a verdict without it is unreliable.'
        );
    }
    $type = is_string($submission['type'] ?? null) && $submission['type'] !== '' ? $submission['type'] : 'comment';
    if (!in_array($type, STATTIC_PHP_FUNCTIONS_SPAM_TYPES, true)) {
        throw new SpacefastServiceError(
            'service_payload_invalid',
            'Submission type must be one of: ' . implode(', ', STATTIC_PHP_FUNCTIONS_SPAM_TYPES) . '.'
        );
    }
    if (($submission['authorRole'] ?? null) !== null && $submission['authorRole'] !== 'administrator') {
        // Akismet passes that role unconditionally, so it is an author's escape
        // hatch for their own team and nothing wider.
        throw new SpacefastServiceError('service_payload_invalid', 'Submission authorRole may only be "administrator".');
    }

    $payload = ['content' => $content, 'userIp' => $userIp, 'type' => $type];
    foreach (
        ['userAgent', 'referrer', 'permalink', 'authorName', 'authorEmail', 'authorUrl', 'authorRole', 'createdAt']
        as $field
    ) {
        if (is_string($submission[$field] ?? null) && $submission[$field] !== '') {
            $payload[$field] = $submission[$field];
        }
    }
    // These two the runtime CAN fill honestly: they are this request's own
    // headers, the same values a capsule handler reads off its Request. An
    // explicit one still wins — a handler proxying a submission knows better.
    foreach (['userAgent' => 'HTTP_USER_AGENT', 'referrer' => 'HTTP_REFERER'] as $field => $header) {
        if (!isset($payload[$field]) && is_string($_SERVER[$header] ?? null) && $_SERVER[$header] !== '') {
            $payload[$field] = $_SERVER[$header];
        }
    }
    $languages = is_array($submission['languages'] ?? null)
        ? array_values(array_filter($submission['languages'], 'is_string'))
        : [];
    if ($languages !== []) {
        $payload['languages'] = $languages;
    }

    return $payload;
}

/**
 * The `EmailMessage` normalization, mirroring `emailPayload` in the shared
 * client source — same required fields, same address coercion, same header
 * rules, so a message that sends from a capsule sends from here.
 *
 * @param array<string,mixed> $message
 * @return array<string,mixed>
 */
function _stattic_php_functions_email_payload(array $message): array
{
    $subject = is_string($message['subject'] ?? null) ? $message['subject'] : '';
    if ($subject === '') {
        throw new SpacefastServiceError('service_payload_invalid', 'Message subject is required.');
    }
    $text = is_string($message['text'] ?? null) ? $message['text'] : '';
    $html = is_string($message['html'] ?? null) ? $message['html'] : '';
    if ($text === '' && $html === '') {
        throw new SpacefastServiceError('service_payload_invalid', 'Message must carry text, html, or both.');
    }

    $payload = [
        'from' => _stattic_php_functions_email_address(
            $message['from'] ?? _stattic_php_functions_default_sender(),
            'from'
        ),
        'to' => _stattic_php_functions_email_address_list($message['to'] ?? null, 'to'),
        'subject' => $subject,
    ];
    if ($text !== '') {
        $payload['text'] = $text;
    }
    if ($html !== '') {
        $payload['html'] = $html;
    }
    foreach (['cc', 'bcc'] as $field) {
        if (($message[$field] ?? null) !== null) {
            $payload[$field] = _stattic_php_functions_email_address_list($message[$field], $field);
        }
    }
    if (($message['replyTo'] ?? null) !== null) {
        $payload['replyTo'] = _stattic_php_functions_email_address($message['replyTo'], 'replyTo');
    }
    if (($message['headers'] ?? null) !== null) {
        if (!is_array($message['headers'])) {
            throw new SpacefastServiceError('service_payload_invalid', 'Message headers must be an object.');
        }
        $headers = [];
        foreach ($message['headers'] as $name => $value) {
            if (!is_string($value)) {
                throw new SpacefastServiceError('service_payload_invalid', 'Header ' . $name . ' must be a string.');
            }
            if (preg_match('/^[\x21-\x39\x3b-\x7e]+$/', (string) $name) !== 1) {
                throw new SpacefastServiceError('service_payload_invalid', 'Header ' . $name . ' is not a valid header name.');
            }
            if (preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new SpacefastServiceError(
                    'service_payload_invalid',
                    'Header ' . $name . ' must not contain line breaks or NUL.'
                );
            }
            $headers[(string) $name] = $value;
        }
        $payload['headers'] = (object) $headers;
    }

    return $payload;
}

/**
 * The space's first verified sender, so the common call is
 * `sf_email(['to' => …, 'subject' => …, 'text' => …])`. The list was resolved
 * before the jail with everything else; an empty one leaves `from` empty and
 * the broker refuses, which is the same answer it gives a capsule.
 */
function _stattic_php_functions_default_sender(): string
{
    $state = &_stattic_php_functions_state();
    $bundle = is_array($state['services']) ? $state['services'] : [];
    $senders = is_array($bundle['env'] ?? null) && is_string($bundle['env']['SPACEFAST_SERVICE_EMAIL_SENDERS'] ?? null)
        ? explode(',', $bundle['env']['SPACEFAST_SERVICE_EMAIL_SENDERS'])
        : [];
    foreach ($senders as $sender) {
        if (trim($sender) !== '') {
            return trim($sender);
        }
    }

    return '';
}

/**
 * @return array{email:string,name?:string}
 */
function _stattic_php_functions_email_address(mixed $value, string $field): array
{
    if (is_string($value)) {
        if (trim($value) === '') {
            throw new SpacefastServiceError('service_payload_invalid', $field . ' must not be empty.');
        }

        return ['email' => trim($value)];
    }
    if (is_array($value) && is_string($value['email'] ?? null) && trim($value['email']) !== '') {
        $address = ['email' => trim($value['email'])];
        if (is_string($value['name'] ?? null) && $value['name'] !== '') {
            $address['name'] = $value['name'];
        }

        return $address;
    }

    throw new SpacefastServiceError(
        'service_payload_invalid',
        $field . ' must be an address or an ["email" => …, "name" => …] pair.'
    );
}

/**
 * @return list<array{email:string,name?:string}>
 */
function _stattic_php_functions_email_address_list(mixed $value, string $field): array
{
    // A single ['email' => …] pair is one recipient, not a list of one; only a
    // list-shaped array is many.
    $entries = is_array($value) && array_is_list($value) ? $value : [$value];
    if ($entries === []) {
        throw new SpacefastServiceError('service_payload_invalid', $field . ' must name at least one recipient.');
    }

    return array_map(
        static fn (mixed $entry): array => _stattic_php_functions_email_address($entry, $field),
        $entries
    );
}
