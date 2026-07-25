<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/safety.php';
require_once __DIR__ . '/access-rules.php';

const STATTIC_ZERO_REQUEST_BODY_MAX_BYTES = 1048576;
const STATTIC_ZERO_CALLBACK_EVENT_MAX_BYTES = 65536;
const STATTIC_ZERO_CALLBACK_TIMEOUT_SECONDS = 2;
const STATTIC_ZERO_CALLBACK_TOKEN_MAX_BYTES = 2048;
const STATTIC_ZERO_CALLBACK_MAX_EVENTS_PER_REQUEST = 100;
const STATTIC_ZERO_CALLBACK_TOTAL_BUDGET_SECONDS = 5.0;
const STATTIC_ZERO_REPLAY_QUERY_MAX_BYTES = 512;
const STATTIC_ZERO_REPLAY_EVENT_ID_MAX_BYTES = 160;
const SPACEFAST_ZERO_REALTIME_TOKEN_MAX_BYTES = 2048;
const STATTIC_ZERO_CONFIG_PATH = 'zero/config.json';

// Zero endpoint cache-policy relay: an endpoint-declared Cache-Control (the
// runner's response headers, or a `_headers` rule on the path) is honored and
// mirrored to the edge tier (CDN-Cache-Control / Surrogate-Control — the house
// convention from serve.php's shared-cache headers, minus the platform TTL
// injection: dynamic responses only ever get the TTL they declared). With NO
// declared policy the response is forced `no-store` — a dynamic response must
// never inherit an ambient cacheable default. On an access-protected path the
// declared policy is discarded entirely and the response pins
// `private, no-store` (same verdict signal the proxy cache relay reads).
// The policy strings themselves are the shared platform constants in
// shared/context.php (STATTIC_CACHE_CONTROL_NO_STORE / _PRIVATE_NO_STORE).
const STATTIC_ZERO_EDGE_MIRROR_HEADERS = ['cdn-cache-control', 'surrogate-control'];

function _stattic_invoke_zero(
    array $action,
    string $versionRoot,
    array $version,
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
        _stattic_zero_send_config_response($config, $requestMethod, $serving);
    }
    if ($operation === 'auth_start' || $operation === 'auth_sign_out') {
        _stattic_zero_send_auth_redirect($config, $operation, $requestMethod, $requestHost);
    }
    if ($operation === 'realtime_events') {
        _stattic_zero_send_realtime_events($config, $requestMethod);
    }
    if ($operation === 'run') {
        _stattic_zero_send_run_response($config, $parentRoot, $serving, $requestMethod, $requestHost);
    }

    $body = file_get_contents('php://input');
    if (!is_string($body)) {
        $body = '';
    }
    if (strlen($body) > STATTIC_ZERO_REQUEST_BODY_MAX_BYTES) {
        _stattic_zero_error(413, 'zero_request_body_too_large', 'Zero request body is too large.');
    }

    $artifactPath = _stattic_zero_endpoint_index_matches(
        $parentRoot,
        (string) $action['endpoint_id'],
        (string) $action['zero_artifact']
    ) ? null : (string) $action['zero_artifact'];
    $envelope = _stattic_zero_envelope(
        $parentRoot,
        $serving,
        (string) $action['endpoint_id'],
        is_string($action['schema_hash'] ?? null) ? $action['schema_hash'] : null,
        [
            'method' => $requestMethod,
            'path' => $requestPath,
            'uri' => $requestUri,
            'host' => $requestHost,
            'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
            'params' => is_array($action['params'] ?? null) ? $action['params'] : [],
        ],
        $body,
        $config,
        $artifactPath
    );

    [$runnerResponse, $runnerBody] = _stattic_zero_execute_envelope($envelope, $config, 'Zero request envelope could not be encoded.');
    _stattic_zero_send_runner_response($runnerResponse, $runnerBody, $responseHeaders, $requestMethod);
}

// Encode the invoke envelope, run the runner, validate its body, and dispatch
// callback events — the shared execute tail of both invoke lanes. Each caller
// keeps its distinct final send; $encodeFailureMessage preserves each lane's
// exact encode-failure body text.
function _stattic_zero_execute_envelope(array $envelope, array $config, string $encodeFailureMessage): array
{
    $payload = json_encode($envelope, JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        _stattic_zero_error(500, 'zero_envelope_encode_failed', $encodeFailureMessage);
    }

    $runnerResponse = _stattic_zero_run_process($payload, $config);
    $runnerBody = _stattic_zero_validated_runner_body($runnerResponse);
    _stattic_zero_send_callback_events($runnerResponse, $envelope, $config);

    return [$runnerResponse, $runnerBody];
}

function _stattic_zero_endpoint_index_matches(string $versionRoot, string $endpointId, string $artifactPath): bool
{
    static $indexes = [];
    $path = $versionRoot . '/zero/endpoints-index.json';
    if (!array_key_exists($path, $indexes)) {
        $decoded = _stattic_runtime_read_json($path);
        $indexes[$path] = is_array($decoded) && is_array($decoded['endpoints'] ?? null)
            ? $decoded['endpoints']
            : [];
    }

    return ($indexes[$path][$endpointId] ?? null) === $artifactPath;
}

function _stattic_zero_run_process(string $payload, array $config): array
{
    $result = _stattic_runtime_run_subprocess(
        [_stattic_zero_runner_binary()],
        _stattic_zero_runner_base_env($config),
        $payload
    );
    if (!$result['spawned']) {
        _stattic_zero_error(502, 'zero_runner_unavailable', 'Zero runner is unavailable.');
    }
    $stdout = $result['stdout'];
    if ($result['exitCode'] !== 0 || !is_string($stdout) || $stdout === '') {
        _stattic_zero_error(502, 'zero_runner_failed', _stattic_zero_debug_message('Zero runner failed. Exit code: ' . (string) $result['exitCode'] . '.', $result['stderr']));
    }

    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        _stattic_zero_error(502, 'zero_runner_invalid_response', _stattic_zero_debug_message('Zero runner returned an invalid response.', $stdout));
    }

    return $decoded;
}

function _stattic_zero_send_activating_response(string $requestMethod): void
{
    _stattic_zero_json_response(503, [
        'error' => [
            'code' => 'zero_activating',
            'message' => 'Zero endpoints are activating on a dedicated runtime.',
        ],
    ], $requestMethod);
}

function _stattic_zero_validated_runner_body(array $runnerResponse): string
{
    $status = $runnerResponse['status'] ?? null;
    if (!is_int($status) || $status < 100 || $status > 599) {
        _stattic_zero_error(502, 'zero_runner_invalid_status', 'Zero runner returned an invalid status.');
    }
    if (array_key_exists('bodyBase64', $runnerResponse)) {
        $body = is_string($runnerResponse['bodyBase64'])
            ? base64_decode($runnerResponse['bodyBase64'], true)
            : false;
    } else {
        $body = $runnerResponse['body'] ?? '';
    }
    if (!is_string($body)) {
        _stattic_zero_error(502, 'zero_runner_invalid_body', 'Zero runner returned an invalid body.');
    }
    return $body;
}

function _stattic_zero_send_runner_response(array $runnerResponse, string $body, array $baseHeaders, string $requestMethod): void
{
    $status = (int) ($runnerResponse['status'] ?? 0);

    $runnerHeaders = is_array($runnerResponse['headers'] ?? null) ? $runnerResponse['headers'] : [];
    // Never-shared-cache verdict: pinned by the unified access enforcement that
    // ran before this dispatch (same signal the proxy cache relay reads).
    $privateResponse = _spacefast_access_private_cache_flag();
    $cachePlan = _stattic_zero_response_cache_plan($baseHeaders, $runnerHeaders, $privateResponse);

    http_response_code($status);
    _stattic_zero_send_headers($baseHeaders, $cachePlan['suppress']);
    _stattic_zero_send_headers($runnerHeaders, $cachePlan['suppress']);
    foreach ($cachePlan['lines'] as [$name, $value]) {
        // Replace: the computed cache policy is THE policy — it stomps anything
        // emitted earlier in the request (platform host headers included).
        header($name . ': ' . $value, true);
    }
    if (_stattic_config_value('SPACEFAST_ZERO_METRICS_HEADER') === '1' && is_array($runnerResponse['metrics'] ?? null)) {
        $metrics = json_encode($runnerResponse['metrics'], JSON_UNESCAPED_SLASHES);
        if (is_string($metrics)) {
            header('X-Spacefast-Zero-Runner-Metrics: ' . base64_encode($metrics));
        }
    }

    if ($requestMethod === 'HEAD') {
        exit;
    }

    echo $body;
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
            || isset(SPACEFAST_PLATFORM_MANAGED_HEADERS[$lower])
            || str_starts_with($lower, 'x-spacefast-')
            || str_starts_with($lower, 'x-stattic-')
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

// Computes the cache-policy emission plan for a Zero endpoint success response:
// which header names the base/runner relay must suppress, and the exact policy
// lines emitted after the relay. Pure — tests/unit.php exercises it directly.
// $baseHeaders are the `_headers`-rule headers for the path, $runnerHeaders the
// runner-declared response headers (value: string or string list); the runner
// outranks the base for the declared policy.
function _stattic_zero_response_cache_plan(array $baseHeaders, array $runnerHeaders, bool $privateResponse): array
{
    if ($privateResponse) {
        // Protection outranks every declared policy; validators (ETag /
        // Last-Modified) still relay, inert under no-store — same posture as
        // the proxy cache relay's private pin.
        return [
            'suppress' => array_merge(STATTIC_PRIVATE_STRIPPED_CACHE_HEADERS, STATTIC_ZERO_EDGE_MIRROR_HEADERS),
            'lines' => [
                ['Cache-Control', STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE],
                ['CDN-Cache-Control', STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE],
                ['Surrogate-Control', STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE],
            ],
        ];
    }

    $declaredCacheControl = _stattic_zero_declared_header_value($runnerHeaders, 'cache-control')
        ?? _stattic_zero_declared_header_value($baseHeaders, 'cache-control');
    $declaredExpires = _stattic_zero_declared_header_value($runnerHeaders, 'expires')
        ?? _stattic_zero_declared_header_value($baseHeaders, 'expires');
    if ($declaredCacheControl === null && $declaredExpires !== null) {
        // Expires-only policy: the declaration relays as-is; forcing no-store
        // on top would stomp it (same posture as the proxy cache relay).
        return ['suppress' => ['cache-control'], 'lines' => []];
    }

    $policy = $declaredCacheControl ?? STATTIC_CACHE_CONTROL_NO_STORE;
    $lines = [['Cache-Control', $policy]];
    foreach ([['CDN-Cache-Control', 'cdn-cache-control'], ['Surrogate-Control', 'surrogate-control']] as [$mirrorName, $mirrorLower]) {
        // Mirror the effective policy to the edge tier unless the endpoint
        // addressed that tier itself (progressive disclosure: a runner-declared
        // CDN-Cache-Control / Surrogate-Control is respected verbatim).
        if (
            _stattic_zero_declared_header_value($runnerHeaders, $mirrorLower) === null
            && _stattic_zero_declared_header_value($baseHeaders, $mirrorLower) === null
        ) {
            $lines[] = [$mirrorName, $policy];
        }
    }
    return ['suppress' => ['cache-control'], 'lines' => $lines];
}

// Last emittable value declared for $lowerName in a headers map (matching
// _stattic_zero_send_headers' emission rules); a string list joins into one
// comma-combined field value. Null when absent or not emittable.
function _stattic_zero_declared_header_value(array $headers, string $lowerName): ?string
{
    $declared = null;
    foreach ($headers as $name => $value) {
        if (!is_string($name) || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || strtolower($name) !== $lowerName) {
            continue;
        }
        if (is_array($value)) {
            $items = array_values(array_filter($value, 'is_string'));
            if ($items !== []) {
                $declared = implode(', ', $items);
            }
            continue;
        }
        if (is_string($value) && $value !== '') {
            $declared = $value;
        }
    }
    return $declared;
}

function _stattic_zero_request_headers(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!is_string($value)) {
            continue;
        }
        if (str_starts_with($key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }
    foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $serverKey => $headerName) {
        if (is_string($_SERVER[$serverKey] ?? null)) {
            $headers[$headerName] = $_SERVER[$serverKey];
        }
    }

    return $headers;
}

function _stattic_zero_runtime_config(string $versionRoot): array
{
    $decoded = _stattic_runtime_read_json($versionRoot . '/' . STATTIC_ZERO_CONFIG_PATH);
    return is_array($decoded) ? $decoded : [];
}

function _stattic_zero_send_config_response(array $config, string $requestMethod, array $serving = []): void
{
    $realtime = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
    $castResourceKey = is_string($realtime['castResourceKey'] ?? null) ? trim($realtime['castResourceKey']) : '';
    $realtimeMode = is_string($realtime['mode'] ?? null) ? $realtime['mode'] : 'stattic-central';
    _stattic_zero_json_response(200, [
        'runtimeKind' => 'zero',
        'auth' => [
            'provider' => is_string($auth['provider'] ?? null) ? $auth['provider'] : 'wpcom',
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
            'socketPath' => '/api/socket/:name',
            'spaceId' => is_string($serving['space_id'] ?? null) && $serving['space_id'] !== '' ? $serving['space_id'] : null,
            'versionId' => is_string($serving['version_id'] ?? null) && $serving['version_id'] !== '' ? $serving['version_id'] : null,
        ],
    ], $requestMethod);
}

function _stattic_zero_send_run_response(array $config, string $versionRoot, array $serving, string $requestMethod, string $requestHost): void
{
    if ($requestMethod !== 'POST') {
        _stattic_zero_error(405, 'zero_method_not_allowed', 'Zero run route requires POST.');
    }
    $body = file_get_contents('php://input');
    $decoded = is_string($body) ? json_decode($body, true) : null;
    $op = is_array($decoded) && is_string($decoded['op'] ?? null) ? $decoded['op'] : '';
    if ($op === 'auth.get') {
        _stattic_zero_json_response(200, [
            'ok' => true,
            'auth' => _stattic_zero_auth_context($serving, $requestHost),
        ], $requestMethod);
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
        ], $requestMethod);
    }

    $artifactPath = _stattic_zero_run_artifact_path($versionRoot, $runId);
    if ($artifactPath === null) {
        _stattic_zero_json_response(404, [
            'ok' => false,
            'error' => [
                'code' => 'zero_endpoint_not_found',
                'message' => 'Zero run handler is not present in the compiled run index.',
            ],
        ], $requestMethod);
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
    _stattic_zero_send_run_frame($op, $name, is_array($decoded) ? $decoded : [], $runnerResponse, $runnerBody, $requestMethod);
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
        _stattic_zero_error(422, 'zero_artifact_invalid', 'Zero run artifact is malformed.');
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
            'origin' => _stattic_zero_request_origin((string) ($request['host'] ?? '')),
            'query' => (string) ($request['query'] ?? ''),
            'headers' => _stattic_runtime_json_object(_stattic_zero_request_headers()),
            'params' => _stattic_runtime_json_object(is_array($request['params'] ?? null) ? $request['params'] : []),
            'bodyBase64' => base64_encode($body),
        ],
        'context' => [
            'spaceId' => (string) ($serving['space_id'] ?? ''),
            'versionId' => is_string($serving['version_id'] ?? null) ? $serving['version_id'] : '',
            'schemaHash' => $schemaHash,
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

function _stattic_zero_send_run_frame(string $op, string $name, array $request, array $runnerResponse, string $runnerBody, string $requestMethod): void
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
        ], $requestMethod);
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
        ], static fn($entry) => $entry !== null), $requestMethod);
    }

    $changes = _stattic_zero_run_changed_values($runnerResponse);
    _stattic_zero_json_response(200, array_filter([
        'id' => is_scalar($id) ? $id : null,
        'op' => 'mutation.result',
        'ok' => true,
        'result' => $value,
        'changedTables' => $changes['tables'],
        'changedQueries' => $changes['queries'],
    ], static fn($entry) => $entry !== null), $requestMethod);
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
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : $event;
        foreach (['changedTables', 'tables'] as $key) {
            foreach (is_array($payload[$key] ?? null) ? $payload[$key] : [] as $value) {
                if (is_string($value) && $value !== '') {
                    $tables[$value] = $value;
                }
            }
        }
        foreach (['changedQueries', 'invalidate'] as $key) {
            foreach (is_array($payload[$key] ?? null) ? $payload[$key] : [] as $value) {
                if (is_string($value) && $value !== '') {
                    $queries[$value] = $value;
                }
            }
        }
    }
    return ['tables' => array_values($tables), 'queries' => array_values($queries)];
}

function _stattic_zero_send_auth_redirect(array $config, string $operation, string $requestMethod, string $requestHost): void
{
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
    $targetKey = $operation === 'auth_start' ? 'signInUrl' : 'signOutUrl';
    $target = is_string($auth[$targetKey] ?? null) ? trim((string) $auth[$targetKey]) : '';
    if (
        $target === ''
        || !preg_match('/^https?:\/\//i', $target)
        || !_stattic_zero_platform_callback_url_allowed($target)
    ) {
        _stattic_zero_error(404, 'zero_auth_unavailable', 'Zero hosted auth is not configured.');
    }

    $returnToParam = is_string($auth['returnToParam'] ?? null) && $auth['returnToParam'] !== ''
        ? (string) $auth['returnToParam']
        : 'returnTo';
    if ($operation === 'auth_sign_out') {
        // Sign-out is the ONE runtime logout route — it clears the single
        // `spacefast_access` cookie (access-plan §6.1). No Zero-specific cookie.
        $returnTo = _stattic_zero_auth_return_to($requestHost, $returnToParam);
        $path = parse_url($returnTo, PHP_URL_PATH);
        $query = parse_url($returnTo, PHP_URL_QUERY);
        $returnPath = (is_string($path) && $path !== '' ? $path : '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
        $redirect = _stattic_zero_request_origin($requestHost) . SPACEFAST_ACCESS_LOGOUT_PATH . '?return=' . rawurlencode($returnPath);
    } else {
        $redirect = _stattic_zero_auth_url_with_return_to($target, $returnToParam, $requestHost);
    }
    http_response_code(302);
    header('Location: ' . $redirect, true, 302);
    header('Cache-Control: no-store', false);
    if ($requestMethod !== 'HEAD') {
        echo "Redirecting.\n";
    }
    exit;
}

// Zero identity is THE visitor token, verified locally (access-plan §6.1, X-2).
// Shoo is gone: no remote oracle, no second cookie. `user:`/`email:` grants
// become the commenter identity (email is the display identity); every other
// case (anonymous, or a pass carrying only pw:/link: grants) is `guest:local`.
function _stattic_zero_auth_context(array $serving, string $requestHost): array
{
    $verified = _spacefast_verify_cookie_identity($serving, $requestHost);
    if ($verified === null) {
        return _stattic_zero_guest_auth_context();
    }
    return _stattic_zero_identity_from_grants($verified);
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

function _stattic_zero_identity_from_grants(array $verified): array
{
    $grants = is_array($verified['grants'] ?? null) ? $verified['grants'] : [];
    $sub = is_string($verified['sub'] ?? null) ? $verified['sub'] : '';
    $email = null;
    $userId = null;
    foreach ($grants as $grant) {
        if (!is_string($grant)) {
            continue;
        }
        if ($email === null && str_starts_with($grant, 'email:') && strlen($grant) > 6) {
            $email = substr($grant, 6);
        }
        if ($userId === null && str_starts_with($grant, 'user:') && strlen($grant) > 5) {
            $userId = substr($grant, 5);
        }
    }
    if ($userId === null && str_starts_with($sub, 'user:') && strlen($sub) > 5) {
        $userId = substr($sub, 5);
    }
    // Only an identifying (user:/email:) grant becomes a commenter; a pass with
    // only pw:/link: grants is anonymous for comment identity.
    if ($userId === null && $email === null) {
        return _stattic_zero_guest_auth_context();
    }
    $id = $userId !== null ? 'user:' . $userId : 'email:' . $email;
    $display = $email ?? $id;
    $user = ['id' => $id, 'displayName' => $display];
    if ($email !== null) {
        $user['email'] = $email;
    }
    return array_filter([
        'user' => $user,
        'userId' => $id,
        'provider' => 'spacefast',
        'isGuest' => false,
        'isAuthenticated' => true,
        'email' => $email,
        'displayName' => $display,
    ], static fn($value) => $value !== null);
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
    return _stattic_zero_request_origin($requestHost) . '/';
}

function _stattic_zero_safe_return_to(string $value, string $requestHost): ?string
{
    if (strlen($value) > 2048 || str_contains($value, "\0")) {
        return null;
    }
    $origin = _stattic_zero_request_origin($requestHost);
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
    return $parts[0] ?? $host;
}

function _stattic_zero_request_origin(string $requestHost): string
{
    $proto = is_string($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null)
        ? strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO']))
        : '';
    if ($proto !== 'http' && $proto !== 'https') {
        $proto = 'https';
    }
    return $proto . '://' . $requestHost;
}

function _stattic_zero_send_realtime_events(array $config, string $requestMethod): void
{
    $realtime = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $replayUrl = is_string($realtime['replayUrl'] ?? null) ? trim($realtime['replayUrl']) : '';
    if ($replayUrl === '' || !_stattic_zero_platform_callback_url_allowed($replayUrl)) {
        _stattic_zero_error(404, 'zero_replay_unavailable', 'Zero realtime replay is unavailable.');
    }
    $query = _stattic_zero_replay_query_string($requestMethod);
    $url = $query === '' ? $replayUrl : $replayUrl . (str_contains($replayUrl, '?') ? '&' : '?') . $query;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => _stattic_zero_replay_headers(),
            'timeout' => STATTIC_ZERO_CALLBACK_TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = _stattic_runtime_http_response_status($http_response_header ?? []);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        _stattic_zero_error(502, 'zero_replay_failed', 'Zero realtime replay failed.');
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8', false);
    header('Cache-Control: no-store', false);
    if ($requestMethod !== 'HEAD') {
        echo $body;
    }
    exit;
}

function _stattic_zero_replay_headers(): array
{
    $headers = ['Accept: application/json'];
    $token = _stattic_config_value('SPACEFAST_ZERO_REALTIME_TOKEN');
    if ($token === '') {
        $token = $_SERVER['HTTP_X_SPACEFAST_ZERO_REALTIME_TOKEN'] ?? null;
    }
    if (
        is_string($token)
        && $token !== ''
        && strlen($token) <= SPACEFAST_ZERO_REALTIME_TOKEN_MAX_BYTES
        && !str_contains($token, "\r")
        && !str_contains($token, "\n")
    ) {
        $headers[] = 'X-Spacefast-Zero-Realtime-Token: ' . $token;
    }
    return $headers;
}

function _stattic_zero_replay_query_string(string $requestMethod): string
{
    $rawQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if (strlen($rawQuery) > STATTIC_ZERO_REPLAY_QUERY_MAX_BYTES) {
        _stattic_zero_error(400, 'zero_replay_query_invalid', 'Zero replay query is too large.');
    }
    $query = [];
    $afterEventId = $_GET['afterEventId'] ?? null;
    if ($afterEventId !== null) {
        if (!is_string($afterEventId) || $afterEventId === '' || strlen($afterEventId) > STATTIC_ZERO_REPLAY_EVENT_ID_MAX_BYTES || !preg_match('/^[A-Za-z0-9._:-]+$/', $afterEventId)) {
            _stattic_zero_error(400, 'zero_replay_query_invalid', 'Zero replay cursor is invalid.');
        }
        $query['afterEventId'] = $afterEventId;
    }
    $limit = $_GET['limit'] ?? null;
    if ($limit !== null) {
        if (is_array($limit) || !preg_match('/^[0-9]+$/', (string) $limit)) {
            _stattic_zero_error(400, 'zero_replay_query_invalid', 'Zero replay limit is invalid.');
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
        || !_stattic_zero_platform_callback_url_allowed($url)
    ) {
        return;
    }
    // Runner event counts are tenant-controlled: cap deliveries per request so
    // a chatty handler cannot flood the platform callback endpoint from the
    // shutdown loop.
    $events = array_slice($runnerResponse['events'], 0, STATTIC_ZERO_CALLBACK_MAX_EVENTS_PER_REQUEST);
    $spaceId = (string) ($envelope['context']['spaceId'] ?? '');
    $versionId = (string) ($envelope['context']['versionId'] ?? '');
    // Deliver after the client response is flushed so callback round trips
    // never add user-visible latency.
    register_shutdown_function(static function () use ($events, $url, $token, $spaceId, $versionId, $callbackBudgetSeconds): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0 && @ob_end_flush() !== false) {
            }
            flush();
        }
        $createdAt = gmdate('c');
        // Each post already carries a per-request timeout; the shared budget
        // bounds total post-flush worker hold when the receiver is slow, so a
        // stalled control plane cannot pin PHP workers for cap x timeout.
        $deadline = microtime(true) + $callbackBudgetSeconds;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                return;
            }
            _stattic_zero_post_callback_event($url, $token, [
                ...$event,
                'space_id' => $spaceId,
                'version_id' => $versionId,
                'created_at' => $createdAt,
            ]);
        }
    });
}

function _stattic_zero_post_callback_event(string $url, string $token, array $event): void
{
    _spacefast_post_callback_event(
        $url,
        $token,
        $event,
        STATTIC_ZERO_CALLBACK_TIMEOUT_SECONDS,
        STATTIC_ZERO_CALLBACK_EVENT_MAX_BYTES
    );
}

function _stattic_zero_platform_callback_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (($scheme !== 'https' && $scheme !== 'http') || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        return false;
    }
    $allowed = array_filter(array_map(
        static fn(string $entry): string => strtolower(trim($entry)),
        explode(',', _stattic_config_value('SPACEFAST_ZERO_CALLBACK_ALLOWED_HOSTS')),
    ));
    if ($allowed === []) {
        return true;
    }
    foreach ($allowed as $allowedHost) {
        if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
            return true;
        }
    }
    return false;
}

function _stattic_zero_error(int $status, string $code, string $message): void
{
    _stattic_zero_json_response($status, [
        'error' => ['code' => $code, 'message' => $message],
    ], $_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function _stattic_zero_json_response(int $status, array $body, string $requestMethod): void
{
    if (isset($body['error']) && is_array($body['error']) && is_string($body['error']['code'] ?? null) && !isset($body['error']['docsUrl'])) {
        $body['error']['docsUrl'] = 'https://spacefast.com/docs/errors/' . rawurlencode($body['error']['code']);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8', false);
    header('Cache-Control: no-store', false);
    if ($requestMethod !== 'HEAD') {
        echo json_encode($body, JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit;
}
