<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/cache-policy.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/native-process.php';
require_once __DIR__ . '/../shared/safety.php';
require_once __DIR__ . '/../shared/html-insert.php';
require_once __DIR__ . '/../shared/runtime-log.php';
require_once __DIR__ . '/access-rules.php';

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
const STATTIC_ZERO_CONFIG_PATH = 'zero/config.json';
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
    require_once __DIR__ . '/../shared/bootstrap-config.php';
    $operation = is_string($action['operation'] ?? null) ? $action['operation'] : 'endpoint';
    $parentRoot = dirname($versionRoot);
    $config = _stattic_zero_runtime_config($parentRoot);
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
        _stattic_zero_send_run_response($config, $parentRoot, $serving, $requestMethod, $requestHost);
    }

    $body = _stattic_bounded_request_body(STATTIC_ZERO_REQUEST_BODY_MAX_BYTES);
    if ($body === null) {
        _stattic_problem_refused(413, 'zero_request_body_too_large', 'Zero request body is too large.');
    }

    // Always explicit: the runner validates the artifact's own endpoint_id
    // against the envelope after reading it, which is the check that matters.
    $artifactPath = (string) $action['artifact'];
    $envelope = _stattic_zero_envelope(
        $parentRoot,
        $serving,
        (string) $action['endpoint'],
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
        $artifactPath
    );

    [$runnerResponse, $runnerBody] = _stattic_zero_execute_envelope($envelope, $config, 'Zero request envelope could not be encoded.');
    _stattic_zero_send_runner_response(
        $runnerResponse,
        $runnerBody,
        $responseHeaders,
        $requestMethod,
        _stattic_html_insert_snippets($serving)
    );
}
function _stattic_zero_execute_envelope(array $envelope, array $config, string $encodeFailureMessage): array
{
    $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        _stattic_problem_refused(500, 'zero_envelope_encode_failed', $encodeFailureMessage);
    }

    $runnerResponse = _stattic_zero_run_process($payload, $config, _stattic_zero_service_identity($envelope));
    $runnerBody = _stattic_zero_validated_runner_body($runnerResponse);
    _stattic_zero_send_callback_events($runnerResponse, $envelope, $config);

    return [$runnerResponse, $runnerBody];
}

/**
 * The identity a brokered service call is attributed to.
 *
 * The request id is the invocation key, so two calls in one request share it
 * and an outbox row is written once per (invocation, effect). It comes from the
 * envelope the runtime built, never from a tenant-visible field.
 */
function _stattic_zero_service_identity(array $envelope): array
{
    $context = is_array($envelope['context'] ?? null) ? $envelope['context'] : [];
    $headers = is_array($envelope['request']['headers'] ?? null) ? $envelope['request']['headers'] : [];
    $requestId = is_string($headers['x-request-id'] ?? null) ? $headers['x-request-id'] : '';
    return [
        'spaceId' => is_string($context['spaceId'] ?? null) ? $context['spaceId'] : '',
        'versionId' => is_string($context['versionId'] ?? null) ? $context['versionId'] : '',
        // Constrained rather than trusted: it becomes half of a primary key.
        'invocationId' => _stattic_service_invocation_id($requestId),
    ];
}

function _stattic_zero_run_process(string $payload, array $config, array $identity): array
{
    $result = _stattic_runtime_run_subprocess(
        [_stattic_runtime_native_binary(), 'invoke'],
        _stattic_zero_runner_base_env($config) + _stattic_service_broker_env($identity),
        $payload,
        null,
        STATTIC_ZERO_RUNNER_TIMEOUT_MS,
        STATTIC_ZERO_RUNNER_STDOUT_MAX_BYTES,
        STATTIC_ZERO_RUNNER_STDERR_MAX_BYTES
    );
    if (!$result['spawned']) {
        _stattic_problem_refused(502, 'zero_runner_unavailable', 'Zero runner is unavailable.');
    }
    $stdout = $result['stdout'];
    if ($result['exitCode'] !== 0 || !is_string($stdout) || $stdout === '') {
        _stattic_problem_refused(502, 'zero_runner_failed', _stattic_zero_debug_message('Zero runner failed. Exit code: ' . (string) $result['exitCode'] . '.', $result['stderr']));
    }

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        _stattic_problem_refused(502, 'zero_runner_invalid_response', _stattic_zero_debug_message('Zero runner returned an invalid response.', $stdout));
    }

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
    array $insertSnippets = []
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
    _stattic_zero_send_headers($runnerHeaders, $cachePolicy['suppress']);
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
    _stattic_html_insert_stream_begin($insertSnippets);
    echo $body;
    _stattic_html_insert_stream_end();
    exit;
}

function _stattic_zero_send_headers(array $headers, array $suppressLowerNames = []): void
{
    foreach ($headers as $name => $value) {
        if (!is_string($name) || $name === '' || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
            continue;
        }
        $lower = strtolower($name);
        if (
            $lower === 'content-length'
            || in_array($lower, $suppressLowerNames, true)
            || _stattic_platform_managed_header($lower)
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
    require_once __DIR__ . '/../shared/upstream-relay.php';
    return _stattic_relay_inbound_headers(true);
}

function _stattic_zero_runtime_config(string $versionRoot): array
{
    $decoded = _stattic_runtime_read_json($versionRoot . '/' . STATTIC_ZERO_CONFIG_PATH);
    return is_array($decoded) ? $decoded : [];
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
            'provider' => is_string($auth['provider'] ?? null) ? $auth['provider'] : 'gravatar',
            'signInPath' => is_string($auth['signInPath'] ?? null) ? $auth['signInPath'] : null,
            'signInUrl' => null,
            'signOutPath' => is_string($auth['signOutPath'] ?? null) ? $auth['signOutPath'] : null,
            'signOutUrl' => null,
            'returnToParam' => is_string($auth['returnToParam'] ?? null) && $auth['returnToParam'] !== '' ? $auth['returnToParam'] : 'returnTo',
        ],
        'realtime' => [
            'mode' => $realtimeMode,
            'centralUrl' => is_string($realtime['centralUrl'] ?? null) && $realtime['centralUrl'] !== '' ? $realtime['centralUrl'] : null,
            'path' => '/__spacefast/zero/realtime',
            'replayPath' => is_string($realtime['replayUrl'] ?? null) && $realtime['replayUrl'] !== '' ? '/__spacefast/zero/realtime/events' : null,
            'runPath' => '/__spacefast/zero/run',
            'resourceKey' => $castResourceKey !== '' ? $castResourceKey : null,
            'ticketPath' => $realtimeMode === 'cast' && $castResourceKey !== ''
                ? STATTIC_ZERO_REALTIME_TICKET_PATH
                : null,
            'socketPath' => '/api/socket/:name',
            'spaceId' => is_string($serving['space_id'] ?? null) && $serving['space_id'] !== '' ? $serving['space_id'] : null,
            'versionId' => is_string($serving['version_id'] ?? null) && $serving['version_id'] !== '' ? $serving['version_id'] : null,
        ],
    ]);
}

function _stattic_zero_send_run_response(array $config, string $versionRoot, array $serving, string $requestMethod, string $requestHost): void
{
    if ($requestMethod !== 'POST') {
        _stattic_method_not_allowed('POST', [
            'code' => 'zero_method_not_allowed',
            'message' => 'Zero run route requires POST.',
        ]);
    }
    $body = _stattic_bounded_request_body(STATTIC_ZERO_REQUEST_BODY_MAX_BYTES);
    if ($body === null) {
        _stattic_problem_refused(413, 'zero_request_body_too_large', 'Zero request body is too large.');
    }
    $decoded = json_decode($body, true);
    $op = is_array($decoded) && is_string($decoded['op'] ?? null) ? $decoded['op'] : '';
    if ($op === 'auth.get') {
        _stattic_zero_json_response(200, [
            'ok' => true,
            'auth' => _stattic_zero_auth_context($serving, $requestHost),
        ]);
    }
    $name = is_array($decoded) && is_string($decoded['name'] ?? null) ? trim((string) $decoded['name']) : '';
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
        $schemaHash,
        [
            'method' => 'POST',
            'path' => '/__spacefast/zero/run',
            'uri' => '/__spacefast/zero/run',
            'host' => $requestHost,
            'query' => '',
            'params' => [],
        ],
        is_string($body) ? $body : '',
        $config,
        $artifactPath
    );
    [$runnerResponse, $runnerBody] = _stattic_zero_execute_envelope($envelope, $config, 'Zero run envelope could not be encoded.');
    _stattic_zero_send_run_frame($op, $name, is_array($decoded) ? $decoded : [], $runnerResponse, $runnerBody);
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

/**
 * Returns visitor identity only from a server-owned FastCGI parameter.
 *
 * A request header named Spacefast-Visitor-Ip lands in
 * HTTP_SPACEFAST_VISITOR_IP and cannot reach this key. REMOTE_ADDR is also
 * excluded: on wp.cloud it is the provider proxy, not the browser. The ingress
 * must set SPACEFAST_VISITOR_IP per request after resolving its trusted peer.
 */
function _stattic_zero_trusted_visitor_ip(): ?string
{
    $serverValue = $_SERVER['SPACEFAST_VISITOR_IP'] ?? getenv('SPACEFAST_VISITOR_IP');
    $candidate = is_string($serverValue) ? trim($serverValue) : '';
    return $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false
        ? $candidate
        : null;
}

function _stattic_zero_envelope(
    string $versionRoot,
    array $serving,
    string $endpointId,
    ?string $schemaHash,
    array $request,
    string $body,
    array $config,
    ?string $artifactPath
): array {
    $envelope = [
        'protocol' => 'stattic.zero.invoke.v1',
        'versionRoot' => $versionRoot,
        'endpointId' => $endpointId,
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
            'authRef' => 'current',
            'variablesRef' => 'finalized',
        ],
        'auth' => _stattic_zero_auth_context($serving, (string) ($request['host'] ?? '')),
        'variables' => _stattic_runtime_json_object(_stattic_zero_string_map(is_array($config['variableValues'] ?? null) ? $config['variableValues'] : [])),
    ];
    if ($artifactPath !== null) {
        $envelope['artifactPath'] = $artifactPath;
    }

    return $envelope;
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
    $args = is_array($request['args'] ?? null) ? $request['args'] : [];
    $id = $request['id'] ?? null;
    if ($op === 'query.subscribe' || $op === 'query.run') {
        _stattic_zero_json_response(200, array_filter([
            'id' => is_scalar($id) ? $id : null,
            'op' => 'query.result',
            'ok' => true,
            'name' => $name,
            'args' => $args === [] ? null : $args,
            'data' => $value,
        ], static fn($entry) => $entry !== null));
    }
    if ($op === 'action.run') {
        _stattic_zero_json_response(200, array_filter([
            'id' => is_scalar($id) ? $id : null,
            'op' => 'action.result',
            'ok' => true,
            'result' => $value,
        ], static fn($entry) => $entry !== null));
    }

    $changes = _stattic_zero_run_changed_values($runnerResponse);
    _stattic_zero_json_response(200, array_filter([
        'id' => is_scalar($id) ? $id : null,
        'op' => 'mutation.result',
        'ok' => true,
        'result' => $value,
        'changedTables' => $changes['tables'],
        'changedQueries' => $changes['queries'],
    ], static fn($entry) => $entry !== null));
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

function _stattic_zero_send_auth_redirect(
    array $config,
    array $serving,
    string $operation,
    string $requestMethod,
    string $requestHost
): void
{
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
    $returnToParam = is_string($auth['returnToParam'] ?? null) && $auth['returnToParam'] !== ''
        ? (string) $auth['returnToParam']
        : 'returnTo';
    $returnTo = _stattic_zero_auth_return_to($requestHost, $returnToParam);
    $path = parse_url($returnTo, PHP_URL_PATH);
    $query = parse_url($returnTo, PHP_URL_QUERY);
    $returnPath = (is_string($path) && $path !== '' ? $path : '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
    if ($operation === 'auth_sign_out') {
        // The one runtime logout route: it clears the single host session
        // cookie. There is no Zero-specific cookie.
        $redirect = 'https://' . $requestHost . STATTIC_ACCESS_LOGOUT_PATH . '?return=' . rawurlencode($returnPath);
    } else {
        $descriptor = _stattic_access_page_descriptor($serving);
        $accountUrl = is_array($descriptor) && is_string($descriptor['accountUrl'] ?? null)
            ? $descriptor['accountUrl']
            : '';
        if ($accountUrl !== '') {
            $separator = str_contains($accountUrl, '?') ? '&' : '?';
            $redirect = $accountUrl
                . $separator . 'host=' . rawurlencode(_stattic_zero_hostname_without_port($requestHost))
                . '&return=' . rawurlencode($returnPath);
        } else {
            $target = is_string($auth['signInUrl'] ?? null) ? trim((string) $auth['signInUrl']) : '';
            if (!_stattic_platform_destination_allowed($target)) {
                _stattic_problem_refused(404, 'zero_auth_unavailable', 'Zero hosted auth is not configured.');
            }
            $redirect = _stattic_zero_auth_url_with_return_to($target, $returnToParam, $requestHost);
        }
    }
    http_response_code(302);
    header('Location: ' . $redirect, true, 302);
    header('Cache-Control: no-store', false);
    if ($requestMethod !== 'HEAD') {
        echo "Redirecting.\n";
    }
    exit;
}

// Identity is the principal alone; what the session may do never decides who it is.
function _stattic_zero_auth_context(array $serving, string $requestHost): array
{
    return _stattic_zero_identity_from_principal(
        _stattic_verify_cookie_identity($serving, $requestHost)
    );
}

function _stattic_zero_guest_auth_context(): array
{
    return [
        'user' => null,
        'userId' => 'guest:local',
        'displayName' => 'Local',
        'provider' => 'guest',
        'isGuest' => true,
        'isAuthenticated' => false,
    ];
}

function _stattic_zero_identity_from_principal(?array $verified): array
{
    $principal = _stattic_access_identity_principal($verified);
    if (!_stattic_access_principal_is_identified($principal)) {
        return _stattic_zero_guest_auth_context();
    }

    $profile = _stattic_access_public_profile($verified['profile'] ?? null) ?? [];
    $displayName = is_string($profile['name'] ?? null)
        ? $profile['name']
        : (is_string($profile['username'] ?? null) ? $profile['username'] : $principal);
    $avatarUrl = is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null;
    $profileUrl = _stattic_zero_gravatar_profile_url($avatarUrl);
    $user = ['id' => $principal, 'displayName' => $displayName];
    if ($avatarUrl !== null) {
        $user['picture'] = $avatarUrl;
    }
    if ($profileUrl !== null) {
        $user['profileUrl'] = $profileUrl;
    }
    return [
        'user' => $user,
        'userId' => $principal,
        'provider' => 'gravatar',
        'isGuest' => false,
        'isAuthenticated' => true,
        'displayName' => $displayName,
        ...($avatarUrl !== null ? ['picture' => $avatarUrl] : []),
        ...($profileUrl !== null ? ['profileUrl' => $profileUrl] : []),
    ];
}

// The avatar URL carries the profile hash, so the profile page derives without
// ever touching the email behind it.
function _stattic_zero_gravatar_profile_url(?string $avatarUrl): ?string
{
    if ($avatarUrl === null) {
        return null;
    }
    $pattern = '~\Ahttps?://(?:[a-z0-9-]+\.)?gravatar\.com/avatar/([a-fA-F0-9]{32,64})(?:[/?#]|\z)~';
    if (preg_match($pattern, $avatarUrl, $matches) !== 1) {
        return null;
    }
    return 'https://gravatar.com/' . strtolower($matches[1]);
}

function _stattic_zero_auth_url_with_return_to(string $target, string $returnToParam, string $requestHost): string
{
    $returnTo = _stattic_zero_auth_return_to($requestHost, $returnToParam);
    $separator = str_contains($target, '?') ? '&' : '?';
    return $target . $separator . rawurlencode($returnToParam) . '=' . rawurlencode($returnTo);
}

function _stattic_zero_auth_return_to(string $requestHost, string $returnToParam): string
{
    $rawValue = $_GET[$returnToParam] ?? null;
    $raw = is_string($rawValue) ? trim($rawValue) : '';
    if ($raw !== '') {
        $safe = _stattic_zero_safe_return_to($raw, $requestHost);
        if ($safe !== null) {
            return $safe;
        }
    }
    return 'https://' . $requestHost . '/';
}

function _stattic_zero_safe_return_to(string $value, string $requestHost): ?string
{
    if (strlen($value) > 2048 || str_contains($value, "\0")) {
        return null;
    }
    $origin = 'https://' . $requestHost;
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        return $origin . $value;
    }
    $parts = parse_url($value);
    $originParts = parse_url($origin);
    if (!is_array($parts) || !is_array($originParts)) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $expectedScheme = strtolower((string) ($originParts['scheme'] ?? ''));
    $expectedHost = strtolower((string) ($originParts['host'] ?? _stattic_zero_hostname_without_port($requestHost)));
    if (
        ($scheme !== 'http' && $scheme !== 'https')
        || $scheme !== $expectedScheme
        || $host === ''
        || $host !== $expectedHost
        || isset($parts['user'])
        || isset($parts['pass'])
        || _stattic_zero_effective_url_port($parts) !== _stattic_zero_effective_url_port($originParts)
    ) {
        return null;
    }
    return $value;
}

function _stattic_zero_effective_url_port(array $parts): int
{
    if (isset($parts['port']) && is_int($parts['port'])) {
        return $parts['port'];
    }
    return strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443;
}

function _stattic_zero_hostname_without_port(string $host): string
{
    if (str_starts_with($host, '[')) {
        $end = strpos($host, ']');
        return $end === false ? $host : substr($host, 1, $end - 1);
    }
    $parts = explode(':', $host, 2);
    return array_first($parts) ?? $host;
}

function _stattic_zero_send_realtime_events(array $config, string $requestMethod): void
{
    require_once __DIR__ . '/../shared/http.php';

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
    if (!$result['ok']) {
        _stattic_problem_refused(502, 'zero_replay_failed', 'Zero realtime replay failed.');
    }
    _stattic_response_send(200, $result['body'], 'application/json; charset=utf-8', ['Cache-Control' => 'no-store']);
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
    array $config
): void
{
    $callbackBudgetSeconds = STATTIC_ZERO_CALLBACK_TOTAL_BUDGET_SECONDS;
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
    require_once __DIR__ . '/../shared/http.php';
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

// The zero lane's whole contribution to the shared emitter is its cache policy
// (see the no-store note at the top of this file). Endpoint-lane refusals are
// RFC 9457 problem documents; the run lane instead keeps `ok:false` frames
// (_stattic_zero_send_run_frame) because its reader discriminates on `ok`, not
// on status.
function _stattic_zero_json_response(int $status, array $body): never
{
    _stattic_json_response($status, $body, 'application/json', ['Cache-Control' => 'no-store']);
}
