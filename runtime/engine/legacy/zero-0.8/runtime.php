<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/page-routes.php';

require_once __DIR__ . '/../../runtime/identity-context.php';

require_once __DIR__ . '/../../shared/storage.php';
require_once __DIR__ . '/../../shared/response.php';
require_once __DIR__ . '/../../shared/cache-policy.php';
require_once __DIR__ . '/../../shared/content-access.php';
require_once __DIR__ . '/../../shared/artifacts.php';
require_once __DIR__ . '/../../shared/egress.php';
require_once __DIR__ . '/../../shared/native-process.php';
require_once __DIR__ . '/../../shared/safety.php';
require_once __DIR__ . '/../../shared/html-insert.php';
require_once __DIR__ . '/../../shared/runtime-log.php';
require_once __DIR__ . '/../../shared/functions-wire.generated.php';
require_once __DIR__ . '/../../runtime/access-rules.php';

const STATTIC_ZERO_REQUEST_BODY_MAX_BYTES = 1048576;
const STATTIC_ZERO_CALLBACK_EVENT_MAX_BYTES = 65536;
const STATTIC_ZERO_CALLBACK_TIMEOUT_SECONDS = 2;
const STATTIC_ZERO_CALLBACK_TOKEN_MAX_BYTES = 2048;
const STATTIC_ZERO_CALLBACK_MAX_EVENTS_PER_REQUEST = 100;
// The same bound on the log lane. A handler logging in a loop is a tenant bug,
// and the cost of it lands on the shared shipper rather than on this box.
const STATTIC_ZERO_LOG_MAX_LINES_PER_REQUEST = 100;
// Realtime callbacks are best effort and run after the response. FPM retains
// the worker after fastcgi_finish_request(), so never spend seconds here.
const STATTIC_ZERO_CALLBACK_TOTAL_BUDGET_SECONDS = 0.25;
const STATTIC_ZERO_REPLAY_QUERY_MAX_BYTES = 512;
const STATTIC_ZERO_REPLAY_EVENT_ID_MAX_BYTES = 160;
const STATTIC_ZERO_REALTIME_TOKEN_MAX_BYTES = 2048;
// Per-request tenant-code budget for the native runner subprocess, mirroring
// the functions relay's executor bounds.
const STATTIC_ZERO_RUNNER_TIMEOUT_MS = 30000;
const STATTIC_ZERO_RUNNER_STDOUT_MAX_BYTES = 16777216;
const STATTIC_ZERO_RUNNER_STDERR_MAX_BYTES = 65536;

// Zero responses are always no-store: wp.cloud's shared cache is keyed only by
// host+path+query and ignores Vary/Cookie, so nothing declared at response time
// can prove an endpoint is identity-independent before that lookup.

function _stattic_invoke_zero(
    array $action,
    string $versionRoot,
    array $serving,
    string $requestHost,
    string $requestPath,
    string $requestUri,
    string $requestMethod,
    array $responseHeaders = []
): void {
    require_once __DIR__ . '/../../shared/bootstrap-config.php';
    $operation = is_string($action['operation'] ?? null) ? $action['operation'] : 'endpoint';
    $config = _stattic_zero_runtime_config($versionRoot);
    $config['billing'] = is_array($serving['billing'] ?? null) ? $serving['billing'] : [];
    $config['email'] = is_array($serving['email'] ?? null) ? $serving['email'] : null;
    if ($operation === 'config') {
        _stattic_zero_send_config_response($config, $serving);
    }
    if ($operation === 'auth_start' || $operation === 'auth_sign_out') {
        _stattic_zero_send_auth_redirect($config, $serving, $operation, $requestMethod, $requestHost);
    }
    if ($operation === 'realtime_events') {
        _stattic_zero_send_realtime_events($config, $requestMethod);
    }
    if ($operation === 'run') {
        _stattic_zero_send_run_response($config, $versionRoot, $serving, $requestMethod, $requestHost);
    }

    $executionMode = is_string($action['execution_mode'] ?? null) && $action['execution_mode'] !== ''
        ? $action['execution_mode']
        : _stattic_zero_derived_endpoint_execution_mode($requestMethod);
    $auth = $executionMode === 'write' && !_stattic_zero_admit_write_session($serving, $requestHost, false)
        ? _stattic_zero_sessionless_guest()
        : null;

    $body = _stattic_bounded_request_body(STATTIC_ZERO_REQUEST_BODY_MAX_BYTES);
    if ($body === null) {
        _stattic_problem_refused(413, 'zero_request_body_too_large', 'Zero request body is too large.');
    }

    // Always explicit: the runner validates the artifact's own endpoint_id
    // against the envelope after reading it, which is the check that matters.
    $artifactPath = (string) $action['artifact'];
    $envelope = _stattic_zero_envelope(
        $versionRoot,
        $serving,
        (string) $action['endpoint'],
        $executionMode,
        is_string($action['schema_hash'] ?? null) ? $action['schema_hash'] : null,
        [
            'method' => $requestMethod,
            'path' => $requestPath,
            // The visitor's link secret stops at this runtime: a handler that
            // echoes its own request line must not be echoing a share token.
            'uri' => _stattic_redact_access_secrets($requestUri),
            'host' => $requestHost,
            'query' => _stattic_strip_access_query_token((string) ($_SERVER['QUERY_STRING'] ?? '')),
            'params' => is_array($action['params'] ?? null) ? $action['params'] : [],
        ],
        $body,
        $config,
        $artifactPath,
        $auth
    );

    [$runnerResponse, $runnerBody] = _stattic_zero_execute_envelope($envelope, $config, 'Zero request envelope could not be encoded.');
    _stattic_zero_send_runner_response(
        $runnerResponse,
        $runnerBody,
        $responseHeaders,
        $requestMethod,
        _stattic_html_insert_payload($serving)
    );
}
function _stattic_zero_execute_envelope(array $envelope, array $config, string $encodeFailureMessage, int $timeoutMs = STATTIC_ZERO_RUNNER_TIMEOUT_MS): array
{
    $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        _stattic_problem_refused(500, 'zero_envelope_encode_failed', $encodeFailureMessage);
    }

    $runnerResponse = _stattic_zero_run_process($payload, $config, _stattic_zero_service_identity($envelope), $timeoutMs);
    $runnerBody = _stattic_zero_validated_runner_body($runnerResponse);
    _stattic_zero_send_callback_events($runnerResponse, $envelope, $config);

    return [$runnerResponse, $runnerBody];
}

/**
 * The identity a brokered service call is attributed to.
 *
 * The identity comes from the envelope the runtime built.
 */
function _stattic_zero_service_identity(array $envelope): array
{
    $context = is_array($envelope['context'] ?? null) ? $envelope['context'] : [];
    $request = is_array($envelope['request'] ?? null) ? $envelope['request'] : [];
    $headers = is_array($envelope['request']['headers'] ?? null) ? $envelope['request']['headers'] : [];
    $requestId = is_string($headers['x-request-id'] ?? null) ? $headers['x-request-id'] : '';
    $origin = is_string($request['origin'] ?? null) ? rtrim($request['origin'], '/') : '';
    $contentUrl = preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?$#', $origin) === 1
        ? $origin . STATTIC_RUNTIME_CONTENT_API_PATH
        : '';
    $auth = is_array($envelope['auth'] ?? null) ? $envelope['auth'] : [];
    $subject = $auth['subject'] ?? $auth['userId'] ?? null;
    $visitor = ($auth['provider'] ?? '') !== 'service' && ($auth['isAuthenticated'] ?? false) === true && is_string($subject)
        ? ['subject' => $subject] : null;
    if ($visitor !== null && is_string($auth['email'] ?? null)) $visitor['email'] = $auth['email'];
    if ($visitor !== null && is_bool($auth['emailVerified'] ?? null)) $visitor['emailVerified'] = $auth['emailVerified'];
    return [
        'visitor' => $visitor,
        'spaceId' => is_string($context['spaceId'] ?? null) ? $context['spaceId'] : '',
        'versionId' => is_string($context['versionId'] ?? null) ? $context['versionId'] : '',
        // Constrained rather than trusted: it becomes half of a primary key.
        'invocationId' => _stattic_service_invocation_id($requestId),
        'contentUrl' => $contentUrl,
        // These remain runtime-owned broker environment. They are deliberately
        // absent from the tenant-visible request envelope.
        'contentCookie' => _stattic_content_access_cookie_header(
            is_string($_SERVER['HTTP_COOKIE'] ?? null) ? $_SERVER['HTTP_COOKIE'] : ''
        ),
        'contentAuthorization' => _stattic_content_access_authorization_header(
            _stattic_platform_bearer_token_from_request()
        ),
    ];
}

function _stattic_zero_run_process(string $payload, array $config, array $identity, int $timeoutMs = STATTIC_ZERO_RUNNER_TIMEOUT_MS): array
{
    require_once __DIR__ . '/../../shared/retail-usage.php';
    $startedCpu = _stattic_retail_cpu_microseconds(getrusage()) + _stattic_retail_cpu_microseconds(getrusage(1));
    $result = _stattic_runtime_run_subprocess(
        [_stattic_runtime_native_binary(), 'invoke'],
        _stattic_zero_runner_base_env($config) + _stattic_service_broker_env($identity, $config),
        $payload,
        null,
        $timeoutMs,
        STATTIC_ZERO_RUNNER_STDOUT_MAX_BYTES,
        STATTIC_ZERO_RUNNER_STDERR_MAX_BYTES
    );
    if (!$result['spawned']) {
        _stattic_problem_refused(502, 'zero_runner_unavailable', 'Zero runner is unavailable.');
    }
    $finishedCpu = _stattic_retail_cpu_microseconds(getrusage()) + _stattic_retail_cpu_microseconds(getrusage(1));
    // The child has been reaped, so getrusage includes its real active CPU.
    // Measure this invocation before any deferred platform work runs.
    _stattic_record_retail_usage(_stattic_access_private_root(), (string) ($identity['spaceId'] ?? ''), 'runtime_cpu_microseconds', max(0, $finishedCpu - $startedCpu));
    _stattic_record_retail_usage(_stattic_access_private_root(), (string) ($identity['spaceId'] ?? ''), 'origin_invocations', 1);
    $stdout = $result['stdout'];
    if ($result['exitCode'] !== 0 || !is_string($stdout) || $stdout === '') {
        _stattic_problem_refused(502, 'zero_runner_failed', _stattic_zero_debug_message('Zero runner failed. Exit code: ' . (string) $result['exitCode'] . '.', $result['stderr']));
    }

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        _stattic_problem_refused(502, 'zero_runner_invalid_response', _stattic_zero_debug_message('Zero runner returned an invalid response.', $stdout));
    }

    _stattic_security_zero_outbound($decoded, $identity);
    unset($decoded["outbound"], $decoded["outboundDropped"]);
    return $decoded;
}

function _stattic_zero_validated_runner_body(array $runnerResponse): string
{
    $status = $runnerResponse['status'] ?? null;
    if (!is_int($status) || $status < 100 || $status > 599) {
        _stattic_problem_refused(502, 'zero_runner_invalid_status', 'Zero runner returned an invalid status.');
    }
    if (array_key_exists('bodyBase64', $runnerResponse)) {
        $body = is_string($runnerResponse['bodyBase64'])
            ? base64_decode($runnerResponse['bodyBase64'], true)
            : false;
    } else {
        $body = $runnerResponse['body'] ?? '';
    }
    if (!is_string($body)) {
        _stattic_problem_refused(502, 'zero_runner_invalid_body', 'Zero runner returned an invalid body.');
    }
    return $body;
}

function _stattic_zero_send_runner_response(
    array $runnerResponse,
    string $body,
    array $baseHeaders,
    string $requestMethod,
    array $insertPayload = []
): void
{
    $status = (int) ($runnerResponse['status'] ?? 0);

    $runnerHeaders = is_array($runnerResponse['headers'] ?? null) ? $runnerResponse['headers'] : [];
    // A cookie-bearing response may be principal-shaped even on a public route,
    // so it keeps the canonical private boundary.
    $cachePolicy = _stattic_cache_policy([
        'private' => _stattic_access_private_cache_flag() || is_string($_SERVER['HTTP_COOKIE'] ?? null),
        'public' => STATTIC_CACHE_CONTROL_NO_STORE,
    ]);

    http_response_code($status);
    _stattic_zero_send_headers($baseHeaders, $cachePolicy['suppress']);
    _stattic_zero_send_headers($runnerHeaders, $cachePolicy['suppress'], in_array($status, [301, 302, 303, 307, 308], true));
    _stattic_cache_policy_send($cachePolicy);
    if (_stattic_config_value('SPACEFAST_ZERO_METRICS_HEADER') === '1' && is_array($runnerResponse['metrics'] ?? null)) {
        $metrics = json_encode($runnerResponse['metrics'], JSON_UNESCAPED_SLASHES);
        if (is_string($metrics)) {
            header('X-Spacefast-Zero-Runner-Metrics: ' . base64_encode($metrics));
        }
    }

    if ($requestMethod === 'HEAD') {
        exit;
    }

    // D44. Every header the response carries is set above, so the filter can
    // read the content type it matches on, and only the endpoint's own body
    // passes through it.
    _stattic_html_insert_stream_begin($insertPayload);
    echo $body;
    _stattic_html_insert_stream_end();
    exit;
}

function _stattic_zero_send_headers(array $headers, array $suppressLowerNames = [], bool $allowLocation = false): void
{
    foreach ($headers as $name => $value) {
        if (!is_string($name) || $name === '' || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
            continue;
        }
        $lower = strtolower($name);
        if (
            $lower === 'content-length'
            || in_array($lower, $suppressLowerNames, true)
            || (_stattic_platform_managed_header($lower) && !($allowLocation && $lower === 'location'))
            // The send-time boundary as well as the publisher-input one: a
            // runner must not emit platform-owned headers (a8c-*, x-ac, …)
            // whatever the cache-policy sender clears afterwards.
            || _stattic_platform_owns_header($lower)
        ) {
            continue;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    header($name . ': ' . $item, false);
                }
            }
            continue;
        }
        if (is_string($value)) {
            header($name . ': ' . $value, false);
        }
    }
}

function _stattic_zero_request_headers(): array
{
    require_once __DIR__ . '/../../shared/upstream-relay.php';
    return _stattic_relay_inbound_headers(true);
}

function _stattic_zero_send_config_response(array $config, array $serving = []): never
{
    $realtime = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
    $castResourceKey = is_string($realtime['castResourceKey'] ?? null) ? trim($realtime['castResourceKey']) : '';
    $realtimeMode = is_string($realtime['mode'] ?? null) ? $realtime['mode'] : 'central';
    _stattic_zero_json_response(200, [
        'runtimeKind' => 'zero',
        'auth' => [
            'requireSignIn' => ($auth['requireSignIn'] ?? false) === true,
            'provider' => is_string($auth['provider'] ?? null) ? $auth['provider'] : 'gravatar',
            'signInPath' => '/__zero/auth/start',
            'signInUrl' => null,
            'signOutPath' => '/__zero/auth/sign-out',
            'signOutUrl' => null,
            'signOutMethod' => ($serving['users']['enabled'] ?? false) === true ? 'POST' : 'GET',
            'returnToParam' => is_string($auth['returnToParam'] ?? null) && $auth['returnToParam'] !== '' ? $auth['returnToParam'] : 'returnTo',
        ],
        'realtime' => [
            'mode' => $realtimeMode,
            'centralUrl' => is_string($realtime['centralUrl'] ?? null) && $realtime['centralUrl'] !== '' ? $realtime['centralUrl'] : null,
            'path' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/realtime',
            'replayPath' => is_string($realtime['replayUrl'] ?? null) && $realtime['replayUrl'] !== '' ? STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/realtime/events' : null,
            'runPath' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/run',
            'resourceKey' => $castResourceKey !== '' ? $castResourceKey : null,
            'ticketPath' => $realtimeMode === 'cast' && $castResourceKey !== ''
                ? STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH
                : null,
            'socketPath' => '/api/socket/:name',
            'spaceId' => is_string($serving['space_id'] ?? null) && $serving['space_id'] !== '' ? $serving['space_id'] : null,
            'versionId' => is_string($serving['version_id'] ?? null) && $serving['version_id'] !== '' ? $serving['version_id'] : null,
        ],
    ]);
}

function _stattic_zero_send_run_response(array $config, string $versionRoot, array $serving, string $requestMethod, string $requestHost, ?string $relayBody = null, ?array $relayAuth = null): void
{
    $config['billing'] = is_array($serving['billing'] ?? null) ? $serving['billing'] : [];
    $config['email'] = is_array($serving['email'] ?? null) ? $serving['email'] : null;
    if ($requestMethod !== 'POST') {
        _stattic_method_not_allowed('POST', [
            'code' => 'zero_method_not_allowed',
            'message' => 'Zero run route requires POST.',
        ]);
    }
    $body = $relayBody ?? _stattic_bounded_request_body(STATTIC_ZERO_REQUEST_BODY_MAX_BYTES);
    if ($body === null) {
        _stattic_problem_refused(413, 'zero_request_body_too_large', 'Zero request body is too large.');
    }
    $decoded = json_decode($body, true);
    $op = is_array($decoded) && is_string($decoded['op'] ?? null) ? $decoded['op'] : '';
    if ($op === 'auth.get') {
        $identity = _stattic_zero_auth_context($serving, $requestHost);
        if ($relayAuth === null) _stattic_zero_upgrade_guest($config, $versionRoot, $serving, $requestHost, $identity);
        _stattic_zero_json_response(200, [
            'ok' => true,
            'auth' => $identity,
        ]);
    }
    $auth = $relayAuth === null
        && ($op === 'mutation.run' || $op === 'action.run')
        && !_stattic_zero_admit_write_session($serving, $requestHost, true)
        ? _stattic_zero_sessionless_guest()
        : $relayAuth;
    $name = is_array($decoded) && is_string($decoded['name'] ?? null) ? trim((string) $decoded['name']) : '';
    if ($name === 'zeroGuestUpgrade') _stattic_problem_refused(403, 'zero_auth_upgrade_internal', 'Guest upgrades are resolved by authentication.');
    $runId = _stattic_zero_run_id($op, $name);
    if ($runId === null) {
        _stattic_zero_json_response(501, [
            'ok' => false,
            'error' => [
                'code' => 'zero_run_operation_unsupported',
                'message' => 'This Zero run operation requires a generated run handler.',
            ],
        ]);
    }

    $artifactPath = _stattic_zero_run_artifact_path($versionRoot, $runId);
    if ($artifactPath === null) {
        _stattic_zero_json_response(404, [
            'ok' => false,
            'error' => [
                'code' => 'zero_endpoint_not_found',
                'message' => 'Zero run handler is not present in the compiled run index.',
            ],
        ]);
    }
    $artifact = _stattic_zero_run_artifact($versionRoot, $artifactPath);
    $schemaHash = is_array($artifact['db'] ?? null) && is_string($artifact['db']['schemaHash'] ?? null)
        ? (string) $artifact['db']['schemaHash']
        : null;
    $envelope = _stattic_zero_envelope(
        $versionRoot,
        $serving,
        $runId,
        _stattic_zero_run_execution_mode($runId, $artifact),
        $schemaHash,
        [
            'method' => 'POST',
            // Frozen server bundles bake a host adapter that compares this
            // string exactly (isInternalRun); it stays the legacy spelling
            // forever regardless of which alias the request arrived on.
            'path' => '/__spacefast/zero/run',
            'uri' => '/__spacefast/zero/run',
            'host' => $requestHost,
            'query' => '',
            'params' => [],
        ],
        is_string($body) ? $body : '',
        $config,
        $artifactPath,
        $auth
    );
    if ($relayAuth !== null) {
        $headers = (array) $envelope['request']['headers'];
        foreach (array_keys($headers) as $name) {
            if ($name === 'authorization' || str_starts_with($name, SPACEFAST_FUNCTIONS_DISPATCH_HEADER_PREFIX)) unset($headers[$name]);
        }
        $envelope['request']['headers'] = _stattic_runtime_json_object($headers);
    }
    [$runnerResponse, $runnerBody] = _stattic_zero_execute_envelope($envelope, $config, 'Zero run envelope could not be encoded.', ($artifact['executionMode'] ?? null) === 'action' ? 5000 : STATTIC_ZERO_RUNNER_TIMEOUT_MS);
    _stattic_zero_send_run_frame($op, $name, is_array($decoded) ? $decoded : [], $runnerResponse, $runnerBody);
}

/**
 * Whose identity a write runs under, decided before tenant code runs.
 *
 * True: the visitor session applies — an exchanged platform bearer, no
 * cookie, or a cookie sent from the Space's exact origin. False: the write
 * runs without its session (_stattic_zero_sessionless_guest), because it
 * carries no browser provenance at all, neither Origin nor Sec-Fetch-Site.
 * Every supported browser sends Origin on a write, so this is a
 * server-to-server caller, typically a webhook sender whose HTTP client keeps
 * a cookie jar. A cross-site page cannot steer it, but its cookie must not
 * lend it a visitor's identity either, and refusing it would fail every
 * delivery after the first one. Any other cookie-carrying write that does not
 * prove the exact origin is refused.
 */
function _stattic_zero_admit_write_session(
    array $serving,
    string $requestHost,
    bool $requireJson
): bool {
    if (_stattic_platform_bearer_token_from_request() !== null) {
        $platformIdentity = _stattic_platform_identity_token(
            $serving,
            $requestHost,
            _stattic_runtime_request_path()
        );
        $identity = $platformIdentity !== ''
            ? _stattic_current_session_identity($serving, $requestHost)
            : null;
        $claims = is_array($identity) && is_array($identity['claims'] ?? null)
            ? $identity['claims']
            : [];
        if (in_array(
            $claims['purpose'] ?? null,
            [STATTIC_HANDOFF_PURPOSE, STATTIC_RUNTIME_BEARER_PURPOSE],
            true
        )) {
            return true;
        }
        // Explicit machine authority never falls back to an ambient cookie or
        // a public Grant. A malformed, wrong-purpose, or failed exchange is a
        // hard denial before tenant code runs.
        _stattic_problem_refused(403, 'access_denied', 'Access denied.');
    }
    $origin = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));
    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($origin === '' && $fetchSite === '') {
        return false;
    }
    if (
        _stattic_visitor_cookie_from_request() === ''
        || _stattic_verify_cookie_identity($serving, $requestHost) === null
    ) {
        return true;
    }
    $expectedOrigin = strtolower(_stattic_runtime_request_origin($requestHost));
    $contentType = strtolower(trim((string) strstr(($_SERVER['CONTENT_TYPE'] ?? '') . ';', ';', true)));
    $contentTypeAllowed = $requireJson
        ? $contentType === 'application/json'
        : !in_array($contentType, [
            '',
            'application/x-www-form-urlencoded',
            'multipart/form-data',
            'text/plain',
        ], true);
    if (
        $origin === ''
        || !hash_equals($expectedOrigin, $origin)
        || $fetchSite !== 'same-origin'
        || !$contentTypeAllowed
    ) {
        _stattic_problem_refused(
            403,
            'zero_mutation_origin_invalid',
            $requireJson
                ? 'Cookie-authenticated Zero mutations require a same-origin JSON request.'
                : 'Cookie-authenticated Zero writes require a same-origin request with a non-safelisted content type.'
        );
    }
    return true;
}

function _stattic_zero_run_id(string $op, string $name): ?string
{
    if ($name === '' || strlen($name) > 128 || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        return null;
    }
    if ($op === 'query.subscribe' || $op === 'query.run') {
        return 'query_' . $name;
    }
    if ($op === 'mutation.run') {
        return 'mutation_' . $name;
    }
    if ($op === 'action.run') {
        return 'action_' . $name;
    }
    return null;
}

/**
 * The execution lane a run invocation runs under.
 *
 * The artifact declares it — a post-law compiler stamps `read`, `write` or
 * `action` on every run — but only within what the operation allows. The two
 * are one fact named twice, and the engine is the only place both are in hand:
 * the run id comes from the request's operation, the mode from the compiled
 * artifact. Let them disagree and `query.run` reaches the write or action lane
 * while the cookie mutation gate above guards only `mutation.run` and
 * `action.run` and the reply frame still says `query.result` — a query
 * committing writes with neither the origin check nor the changed-table
 * bookkeeping a mutation gets. So a disagreement is a malformed artifact, and
 * `action.run` keeps its two lanes: the untransacted one and the `write` a
 * pre-law action was published with.
 *
 * Only an artifact finalized before the intake stamped modes carries none; for
 * that one the operation says what it always meant.
 */
function _stattic_zero_run_execution_mode(string $runId, array $artifact): string
{
    $declared = $artifact['executionMode'] ?? null;
    if (!is_string($declared) || $declared === '') {
        return _stattic_zero_derived_run_execution_mode($runId);
    }
    if (!in_array($declared, _stattic_zero_run_execution_modes($runId), true)) {
        _stattic_problem_refused(
            422,
            'zero_artifact_invalid',
            'Zero run artifact declares an execution mode its operation does not allow.'
        );
    }
    return $declared;
}

function _stattic_zero_run_artifact_path(string $versionRoot, string $runId): ?string
{
    $index = _stattic_runtime_read_json($versionRoot . '/zero/runs-index.json');
    if (
        !is_array($index)
        || ($index['format'] ?? null) !== 'stattic.zero.runs-index.v1'
        || ($index['artifact_kind'] ?? null) !== 'zero_runs_index'
        || !is_array($index['runs'] ?? null)
    ) {
        return null;
    }
    $artifactPath = $index['runs'][$runId] ?? null;
    return is_string($artifactPath) && _stattic_zero_private_artifact_path_valid($artifactPath)
        ? $artifactPath
        : null;
}

function _stattic_zero_run_artifact(string $versionRoot, string $artifactPath): array
{
    $artifact = _stattic_runtime_read_json($versionRoot . '/' . $artifactPath);
    if (!is_array($artifact) || ($artifact['format'] ?? null) !== 'stattic.zero.run.v1') {
        _stattic_problem_refused(422, 'zero_artifact_invalid', 'Zero run artifact is malformed.');
    }
    return $artifact;
}

function _stattic_zero_private_artifact_path_valid(string $path): bool
{
    return str_starts_with($path, 'zero/runs/')
        && str_ends_with($path, '.json')
        && _stattic_runtime_relative_artifact_path_valid($path);
}

function _stattic_zero_envelope(
    string $versionRoot,
    array $serving,
    string $endpointId,
    string $executionMode,
    ?string $schemaHash,
    array $request,
    string $body,
    array $config,
    string $artifactPath,
    ?array $auth = null
): array {
    return [
        'protocol' => 'stattic.zero.invoke.v1',
        'versionRoot' => $versionRoot,
        'endpointId' => $endpointId,
        'executionMode' => $executionMode,
        'artifactPath' => $artifactPath,
        'request' => [
            'method' => (string) ($request['method'] ?? ''),
            'path' => (string) ($request['path'] ?? ''),
            'uri' => (string) ($request['uri'] ?? ''),
            'host' => (string) ($request['host'] ?? ''),
            'origin' => 'https://' . (string) ($request['host'] ?? ''),
            'query' => (string) ($request['query'] ?? ''),
            'headers' => _stattic_runtime_json_object(_stattic_zero_request_headers()),
            'params' => _stattic_runtime_json_object(is_array($request['params'] ?? null) ? $request['params'] : []),
            'bodyBase64' => base64_encode($body),
        ],
        'context' => [
            'spaceId' => (string) ($serving['space_id'] ?? ''),
            'versionId' => is_string($serving['version_id'] ?? null) ? $serving['version_id'] : '',
            'schemaHash' => $schemaHash,
            'visitorIp' => _stattic_zero_trusted_visitor_ip(),
            'egressScope' => _stattic_egress_scope($serving),
        ],
        'auth' => $auth ?? _stattic_zero_auth_context($serving, (string) ($request['host'] ?? '')),
        'variables' => _stattic_runtime_json_object(_stattic_zero_string_map(is_array($config['variableValues'] ?? null) ? $config['variableValues'] : [])),
    ];
}

function _stattic_zero_send_run_frame(string $op, string $name, array $request, array $runnerResponse, string $runnerBody): never
{
    $status = (int) ($runnerResponse['status'] ?? 0);
    if ($status < 200 || $status >= 300) {
        _stattic_zero_json_response($status, [
            'op' => 'error',
            'ok' => false,
            'error' => _stattic_zero_run_body_json($runnerBody) ?? [
                'code' => 'zero_runner_failed',
                'message' => 'Zero run handler failed.',
            ],
        ]);
    }
    $value = _stattic_zero_run_body_json($runnerBody);
    // Null is a handler result. Filter only absent envelope metadata, never
    // the data/result payload a client uses to distinguish null from missing.
    $args = is_array($request['args'] ?? null) ? $request['args'] : [];
    $id = $request['id'] ?? null;
    if ($op === 'query.subscribe' || $op === 'query.run') {
        _stattic_zero_json_response(200, array_filter([
            'id' => is_scalar($id) ? $id : null,
            'op' => 'query.result',
            'ok' => true,
            'name' => $name,
            'args' => $args === [] ? null : $args,
        ], static fn($entry) => $entry !== null) + ['data' => $value]);
    }
    if ($op === 'action.run') {
        // An action writes nothing, so there is no changed-table bookkeeping to
        // feed a subscriber: the client reads `result` and nothing else. Work
        // that must land in the database goes through a mutation.
        _stattic_zero_json_response(200, array_filter([
            'id' => is_scalar($id) ? $id : null,
            'op' => 'action.result',
            'ok' => true,
        ], static fn($entry) => $entry !== null) + ['result' => $value]);
    }
    $changes = _stattic_zero_run_changed_values($runnerResponse);
    _stattic_zero_json_response(200, array_filter([
        'id' => is_scalar($id) ? $id : null,
        'op' => 'mutation.result',
        'ok' => true,
        'changedTables' => $changes['tables'],
        'changedQueries' => $changes['queries'],
    ], static fn($entry) => $entry !== null) + ['result' => $value]);
}

function _stattic_zero_run_body_json(string $body): mixed
{
    $body = trim($body);
    if ($body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
}

function _stattic_zero_run_changed_values(array $runnerResponse): array
{
    $tables = [];
    $queries = [];
    foreach (is_array($runnerResponse['events'] ?? null) ? $runnerResponse['events'] : [] as $event) {
        if (!is_array($event)) {
            continue;
        }
        // The runner's template normalizes handler input before emitting, so
        // only the canonical keys ever arrive.
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        foreach (is_array($payload['changedTables'] ?? null) ? $payload['changedTables'] : [] as $value) {
            if (is_string($value) && $value !== '') {
                $tables[$value] = $value;
            }
        }
        foreach (is_array($payload['changedQueries'] ?? null) ? $payload['changedQueries'] : [] as $value) {
            if (is_string($value) && $value !== '') {
                $queries[$value] = $value;
            }
        }
    }
    return ['tables' => array_values($tables), 'queries' => array_values($queries)];
}

function _stattic_zero_upgrade_guest(array $config, string $versionRoot, array $serving, string $host, array $identity): void
{
    if (($identity['isSignedIn'] ?? false) !== true) return;
    $verified = _stattic_verify_cookie_identity($serving, $host);
    $record = _stattic_access_identity_record($verified) ?? [];
    $anonymousId = _stattic_collab_anonymous_id($record);
    if ($anonymousId === null) return;
    $runId = _stattic_zero_run_id('mutation.run', 'zeroGuestUpgrade');
    $artifactPath = _stattic_zero_run_artifact_path($versionRoot, $runId);
    if ($artifactPath === null || !_stattic_zero_admit_write_session($serving, $host, true)) return;
    $artifact = _stattic_zero_run_artifact($versionRoot, $artifactPath);
    $schemaHash = $artifact['db']['schemaHash'] ?? null;
    $envelope = _stattic_zero_envelope($versionRoot, $serving, $runId, 'write', $schemaHash,
        ['method' => 'POST', 'path' => '/__spacefast/zero/run', 'uri' => '/__spacefast/zero/run', 'host' => $host, 'query' => '', 'params' => []],
        '{}', $config, $artifactPath, [...$identity, 'guestUserId' => 'guest:' . $anonymousId]);
    [$response, $body] = _stattic_zero_execute_envelope($envelope, $config, 'Guest upgrade could not be encoded.');
    if (($response['status'] ?? 500) >= 400 && str_contains($body, 'zero_guest_upgrade_retry:')) {
        [$response, $body] = _stattic_zero_execute_envelope($envelope, $config, 'Guest upgrade retry could not be encoded.');
    }
    if (($response['status'] ?? 500) < 200 || ($response['status'] ?? 500) >= 300) {
        _stattic_zero_send_runner_response($response, $body, [], 'POST');
    }
}

// A write served as if it had sent no Cookie header. Everything after this reads
// the request without one: the tenant's request headers, the content broker's
// forwarded session, the response's privacy boundary. Zero handlers cannot set
// cookies, so none of the dropped ones was the handler's own. The guest is a
// pseudonym for this one request and is never written back as a cookie.
function _stattic_zero_sessionless_guest(): array
{
    unset($_SERVER['HTTP_COOKIE']);
    $_COOKIE = [];
    return _stattic_zero_guest_auth_context(_stattic_collab_mint_anonymous_id());
}

function _stattic_zero_send_realtime_events(array $config, string $requestMethod): void
{
    require_once __DIR__ . '/../../shared/http.php';

    $realtime = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $replayUrl = is_string($realtime['replayUrl'] ?? null) ? trim($realtime['replayUrl']) : '';
    if (!_stattic_platform_destination_allowed($replayUrl)) {
        _stattic_problem_refused(404, 'zero_replay_unavailable', 'Zero realtime replay is unavailable.');
    }
    $query = _stattic_zero_replay_query_string($requestMethod);
    $result = _stattic_http_request([
        'url' => $query === '' ? $replayUrl : $replayUrl . (str_contains($replayUrl, '?') ? '&' : '?') . $query,
        'headers' => _stattic_zero_replay_headers($config),
        'connect_timeout' => STATTIC_ZERO_CALLBACK_TIMEOUT_SECONDS,
        'timeout' => STATTIC_ZERO_CALLBACK_TIMEOUT_SECONDS,
        'schemes' => ['https', 'http'],
    ]);
    if ($result['error'] !== null) {
        _stattic_problem_refused(502, 'zero_replay_failed', 'Zero realtime replay failed.');
    }
    $headers = _stattic_http_header_map($result['headers']);
    _stattic_response_send($result['status'], $result['body'], $headers['content-type'] ?? 'application/json; charset=utf-8', ['Cache-Control' => 'no-store']);
}

// `x-spacefast-runtime-realtime-token` is the upstream credential name emitted
// by packages/zero and presented upstream here. The fleet-secret value comes
// only from process config: a visitor-supplied request header is untrusted (not
// in the edge strip list) and must never be replayed upstream as a platform
// credential.
function _stattic_zero_replay_headers(array $config): array
{
    $headers = ['Accept: application/json'];
    $realtime = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $eventCallback = is_array($realtime['eventCallback'] ?? null) ? $realtime['eventCallback'] : [];
    $callbackToken = $eventCallback['token'] ?? null;
    if (
        is_string($callbackToken)
        && $callbackToken !== ''
        && strlen($callbackToken) <= STATTIC_ZERO_REALTIME_TOKEN_MAX_BYTES
        && !str_contains($callbackToken, "\r")
        && !str_contains($callbackToken, "\n")
    ) {
        $headers[] = 'Authorization: Bearer ' . $callbackToken;
        return $headers;
    }
    $token = _stattic_config_value('SPACEFAST_ZERO_REALTIME_TOKEN');
    if (
        $token !== ''
        && strlen($token) <= STATTIC_ZERO_REALTIME_TOKEN_MAX_BYTES
        && !str_contains($token, "\r")
        && !str_contains($token, "\n")
    ) {
        $headers[] = 'X-Spacefast-Runtime-Realtime-Token: ' . $token;
    }
    return $headers;
}

function _stattic_zero_replay_query_string(string $requestMethod): string
{
    $rawQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if (strlen($rawQuery) > STATTIC_ZERO_REPLAY_QUERY_MAX_BYTES) {
        _stattic_problem_refused(400, 'zero_replay_query_invalid', 'Zero replay query is too large.');
    }
    $query = [];
    $afterEventId = $_GET['afterEventId'] ?? null;
    if ($afterEventId !== null) {
        if (!is_string($afterEventId) || $afterEventId === '' || strlen($afterEventId) > STATTIC_ZERO_REPLAY_EVENT_ID_MAX_BYTES || !preg_match('/^[A-Za-z0-9._:-]+$/', $afterEventId)) {
            _stattic_problem_refused(400, 'zero_replay_query_invalid', 'Zero replay cursor is invalid.');
        }
        $query['afterEventId'] = $afterEventId;
    }
    $limit = $_GET['limit'] ?? null;
    if ($limit !== null) {
        if (is_array($limit) || !preg_match('/^[0-9]+$/', (string) $limit)) {
            _stattic_problem_refused(400, 'zero_replay_query_invalid', 'Zero replay limit is invalid.');
        }
        $query['limit'] = (string) max(1, min(100, (int) $limit));
    }
    return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function _stattic_zero_send_callback_events(
    array $runnerResponse,
    array $envelope,
    array $config,
    float $callbackBudgetSeconds = STATTIC_ZERO_CALLBACK_TOTAL_BUDGET_SECONDS
): void
{
    if (!is_array($runnerResponse['events'] ?? null) || $runnerResponse['events'] === []) {
        return;
    }
    $versionId = (string) ($envelope['context']['versionId'] ?? '');
    // A capsule's own output never travels to the control plane. It is a
    // runtime log like a PHP function's or a worker's, so it goes where runtime
    // logs live: PHP's error log on this box, which the provider ships and
    // serves back. Writing it is a local append with nothing waiting on it.
    $callbackEvents = [];
    $logged = 0;
    foreach ($runnerResponse['events'] as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (($event['event'] ?? null) === 'zero.log') {
            if ($logged >= STATTIC_ZERO_LOG_MAX_LINES_PER_REQUEST) {
                continue;
            }
            $logged += 1;
            _stattic_runtime_log_write([
                'level' => $event['level'] ?? null,
                'message' => $event['message'] ?? null,
                'metadata' => $event['metadata'] ?? null,
                'handlerName' => $event['mutation_name'] ?? null,
                'requestId' => $event['request_id'] ?? null,
                'timestamp' => $event['timestamp'] ?? null,
            ], $versionId);
            continue;
        }
        $callbackEvents[] = $event;
    }
    if ($callbackEvents === []) {
        return;
    }
    $realtime = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $eventCallback = is_array($realtime['eventCallback'] ?? null) ? $realtime['eventCallback'] : [];
    $url = $eventCallback['url'] ?? null;
    $token = $eventCallback['token'] ?? null;
    if (
        !is_string($url)
        || $url === ''
        || !is_string($token)
        || $token === ''
        || strlen($token) > STATTIC_ZERO_CALLBACK_TOKEN_MAX_BYTES
        || str_contains($token, "\r")
        || str_contains($token, "\n")
        || !_stattic_platform_destination_allowed($url)
    ) {
        return;
    }
    // Event counts are tenant-controlled: cap deliveries so a chatty handler
    // cannot flood the platform callback endpoint.
    $events = array_slice($callbackEvents, 0, STATTIC_ZERO_CALLBACK_MAX_EVENTS_PER_REQUEST);
    $spaceId = (string) ($envelope['context']['spaceId'] ?? '');
    _stattic_defer(static function () use ($events, $url, $token, $spaceId, $versionId, $callbackBudgetSeconds): void {
        $createdAt = gmdate('c');
        // Bounds total post-flush worker hold: without it a stalled control
        // plane pins a PHP worker for cap x timeout.
        $deadline = microtime(true) + $callbackBudgetSeconds;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                return;
            }
            $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
            if ($remainingMs < 1) {
                return;
            }
            _stattic_zero_post_callback_event($url, $token, [
                ...$event,
                'space_id' => $spaceId,
                'version_id' => $versionId,
                'created_at' => $createdAt,
            ], min(STATTIC_ZERO_CALLBACK_TIMEOUT_SECONDS * 1000, $remainingMs));
        }
    });
}

// Zero realtime keeps its own LIVE lane (contracts §10): the journal is the only
// sink for management events, but a realtime event that arrives after the room
// has moved on is worthless, so it goes straight to the Cast callback instead of
// through a pull cursor. This transport is local on purpose, and nothing else
// in the runtime may grow a second one. Delivery is best effort: the caller
// runs it after the response flush, inside a time budget, with no retry lane.
function _stattic_zero_post_callback_event(string $url, string $token, array $event, int $timeoutMs): void
{
    require_once __DIR__ . '/../../shared/http.php';
    $payload = json_encode(['event' => $event], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || strlen($payload) > STATTIC_ZERO_CALLBACK_EVENT_MAX_BYTES) {
        return;
    }
    // The callback is deliberately best effort, but PHP 8.5's NoDiscard
    // contract requires the caller to acknowledge the transport receipt.
    $receipt = _stattic_http_request([
        'url' => $url,
        'method' => 'POST',
        'headers' => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        'body' => $payload,
        'connect_timeout_ms' => $timeoutMs,
        'timeout_ms' => $timeoutMs,
        'schemes' => ['https', 'http'],
        // The receipt is not read; cap it so a chatty endpoint cannot make the
        // worker hold a body it will discard.
        'max_body_bytes' => 4096,
    ]);
    unset($receipt);
}
