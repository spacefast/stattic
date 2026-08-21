<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/auth.php';

// The one bootstrap for the two direct admin entrypoints (entrypoints/
// management.php, entrypoints/upload.php): identity on the wire, the
// undeployed 503, then dispatch onto the surface's route table.
function _stattic_runtime_admin_entrypoint(string $engineRoot, string $surface): void
{
    $storageRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
    _stattic_emit_runtime_identity();
    if (!is_dir($storageRoot)) {
        _stattic_problem_response(503, 'runtime_undeployed', 'Runtime storage is not provisioned on this site.');
    }
    [$requestMethod, $requestPath] = _stattic_runtime_entrypoint_request();
    _stattic_runtime_admin_api($storageRoot, $requestMethod, $requestPath, $surface);
}

// Auth lanes are hard security boundaries: a token minted for one lane can never
// authorize another's row, because each lane's verifier pins its own `aud` before
// any claim is trusted.
function _stattic_runtime_admin_api(string $privateRoot, string $method, string $requestPath, string $surface): void
{
    _stattic_runtime_admin_register_error_envelope();
    // Host gate BEFORE route resolution: a public hostname must get the
    // deliberately-generic runtime_api_not_found, never a route-specific 404
    // that would confirm the management/upload API lives here.
    _stattic_runtime_assert_api_hostname();
    // CORS BEFORE route resolution, and the preflight answered before it too.
    // A browser only ever sees a response it is allowed to read: a 404 emitted
    // without these headers reaches the caller as an opaque CORS failure that
    // names the wrong problem, and a preflight that 404s blocks the real
    // request outright. A preflight carries no credentials and asserts nothing
    // about the route, so answering it ahead of resolution is the contract, not
    // a shortcut — the actual request is still resolved and authorized below.
    _stattic_runtime_cors_headers();
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    [$resolveRoutePath, $routeNotFound] = $surface === 'upload'
        ? [_stattic_runtime_upload_api_route_path(...), _stattic_runtime_upload_route_not_found(...)]
        : [_stattic_runtime_management_api_route_path(...), _stattic_runtime_route_not_found(...)];

    $routePath = $resolveRoutePath($requestPath);
    if (!is_string($routePath)) {
        $routeNotFound();
    }

    foreach (_stattic_runtime_admin_routes($surface) as $route) {
        if (preg_match($route['pattern'], $routePath, $matches) !== 1) {
            continue;
        }
        if (!in_array($method, $route['methods'], true)) {
            $mismatch = $route['method_mismatch'];
            if ($mismatch === null) {
                continue;
            }
            _stattic_method_not_allowed($mismatch['allow'], [
                'code' => $mismatch['code'],
                'message' => $mismatch['message'],
            ]);
        }
        // SSH dispatch (admin/dispatch.php) carries one JSON envelope on stdout,
        // so rows that stream bytes or write raw responses stay HTTP-only.
        if ($route['binary'] && defined('STATTIC_RUNTIME_DISPATCH_CLI')) {
            _stattic_problem_response(400, 'runtime_dispatch_unsupported_path', 'Binary endpoints are not dispatchable over this transport.');
        }
        _stattic_runtime_admin_run_route($privateRoot, $route, $matches);
    }

    $routeNotFound();
}

// Error envelope for the admin API. It is armed before the host gate and JWT,
// so an unauthenticated caller reaches these handlers: they must answer a fixed
// generic 500 carrying only a correlation id, and send the real Throwable /
// fatal text (paths, include chains) to error_log alone. The correlation id is
// minted once per request and shared by both handlers so an operator can match
// the response the caller saw to the logged cause.
function _stattic_runtime_admin_register_error_envelope(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    $correlationId = bin2hex(random_bytes(8));

    set_exception_handler(static function (Throwable $error) use ($correlationId): void {
        error_log(sprintf(
            'spacefast admin api uncaught %s [%s]: %s',
            get_debug_type($error),
            $correlationId,
            $error->getMessage()
        ));
        _stattic_problem_response(
            500,
            'runtime_internal_error',
            'The runtime API hit an unexpected error.',
            ['details' => ['correlation_id' => $correlationId]]
        );
    });

    register_shutdown_function(static function () use ($correlationId): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $type = is_int($error['type'] ?? null) ? $error['type'] : 0;
        if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (!defined('STATTIC_RUNTIME_DISPATCH_CLI') && headers_sent()) {
            return;
        }
        error_log(sprintf(
            'spacefast admin api fatal type=%d [%s]: %s',
            $type,
            $correlationId,
            isset($error['message']) && is_string($error['message']) ? $error['message'] : ''
        ));
        _stattic_problem_response(
            500,
            'runtime_fatal_error',
            'The runtime API hit a fatal error.',
            ['details' => ['correlation_id' => $correlationId]]
        );
    });
}

// Handlers respond and exit; a handler that RETURNS falls back into the dispatch
// loop and keeps matching later rows.
function _stattic_runtime_admin_run_route(string $privateRoot, array $route, array $matches): void
{
    $handler = $route['handler'];

    if ($route['lane'] === 'management') {
        require_once __DIR__ . '/management.php';
        // A route-index rebuild on a many-space site can exceed the default PHP
        // execution budget, and a mid-rebuild kill leaves the work half done
        // behind a provider-edge 504. These requests are JWT-authed, low-volume
        // and never on the public hot path.
        set_time_limit(0);
        $required = [];
        foreach ($route['scope'] as $field => $captureIndex) {
            $required[$field] = _stattic_runtime_id($matches[$captureIndex], $field);
        }
        $claims = _stattic_runtime_require_management_jwt($privateRoot, $route['action'], $required);
        // Handlers trust this scope validation and never re-run it: they receive
        // the validated (trimmed) ids positionally.
        $args = [$privateRoot, ...array_values($required), $claims];
        $spaceLockId = null;
        if ($route['lock'] === 'space') {
            // An unresolvable scope falls back to the site lock: correctness
            // over concurrency.
            $spaceLockId = isset($required['space_id']) && is_string($required['space_id']) && $required['space_id'] !== ''
                ? $required['space_id']
                : null;
        }
        if ($spaceLockId !== null) {
            _stattic_runtime_with_space_write_lock($privateRoot, $spaceLockId, static function () use ($handler, $args): void {
                $handler(...$args);
            });
        } elseif ($route['lock'] !== 'none') {
            _stattic_runtime_with_write_lock($privateRoot, static function () use ($handler, $args): void {
                $handler(...$args);
            });
        } else {
            $handler(...$args);
        }
        return;
    }

    require_once __DIR__ . '/upload.php';
    _stattic_runtime_reject_s3_control_operation($route['methods'][0]);
    $claims = _stattic_runtime_require_upload_jwt($privateRoot);
    $args = [$privateRoot, ...array_slice($matches, 1, $route['captures']), $claims];
    $handler(...$args);
}

// Row fields: lane, methods, pattern, binary (streams bytes / writes a raw
// response — never dispatchable over the SSH envelope transport),
// method_mismatch (null = keep scanning, otherwise the 405 answered when the
// pattern matches but the method does not), plus the lane-specific auth inputs.
function _stattic_runtime_admin_routes(string $surface): array
{
    if ($surface === 'upload') {
        return _stattic_runtime_admin_upload_routes();
    }
    return _stattic_runtime_admin_management_routes();
}

// Management lane rows: [method, pattern, action, scope (claim key => capture
// index, ascending), lock, binary, handler]. Handlers are called as
// ($privateRoot, ...$scopeIds, $claims); ones that omit the trailing parameter
// still receive it positionally, so never widen such a signature without
// declaring the `array $claims` it would silently pick up.
//
// A route earns lock='space' ONLY when every write it performs transitively is
// confined to that one space's storage. delete_space MUST share
// finalize_version's per-space lock: a publish writing into the space tree while
// the delete unlinks it recreates a deleted Space.
function _stattic_runtime_admin_management_routes(): array
{
    $rows = [
        ['GET', '#^/state$#', 'read_state', [], 'none', false, '_stattic_runtime_state_route'],
        ['POST', '#^/events/drain$#', 'drain_events', [], 'none', false, '_stattic_runtime_drain_callback_events'],
        ['POST', '#^/events/ack$#', 'ack_events', [], 'none', false, '_stattic_runtime_ack_callback_events'],
        ['POST', '#^/jobs$#', 'create_engine_job', [], 'none', false, '_stattic_runtime_jobs_create_route'],
        ['POST', '#^/jobs/tick$#', 'tick_engine_jobs', [], 'none', false, '_stattic_runtime_jobs_tick_route'],
        ['GET', '#^/jobs/([^/]+)$#', 'get_engine_job', ['job_id' => 1], 'none', false, '_stattic_runtime_jobs_get_route'],
        ['POST', '#^/engine/update$#', 'update_engine', [], 'none', false, '_stattic_engine_update_route'],
        ['GET', '#^/spaces/([^/]+)/versions/([^/]+)/zero/db/dump$#', 'zero_db_dump', ['space_id' => 1, 'version_id' => 2], 'none', false, '_stattic_zero_db_dump'],
        ['GET', '#^/spaces/([^/]+)/versions/([^/]+)/zero/db/export$#', 'zero_db_export', ['space_id' => 1, 'version_id' => 2], 'none', false, '_stattic_zero_db_export'],
        ['GET', '#^/spaces/([^/]+)/storage$#', 'storage_list', ['space_id' => 1], 'none', false, '_stattic_storage_list'],
        ['DELETE', '#^/spaces/([^/]+)/storage/([a-f0-9]{32})$#', 'storage_delete', ['space_id' => 1, 'storage_object_id' => 2], 'space', false, '_stattic_storage_object_delete'],
        ['GET', '#^/storage/read-key$#', 'storage_read_key', [], 'none', false, '_stattic_storage_read_key_get'],
        ['POST', '#^/storage/read-key/rotate$#', 'rotate_storage_read_key', [], 'site', false, '_stattic_storage_read_key_rotate'],
        ['PUT', '#^/spaces/([^/]+)/build-sources/([^/]+)$#', 'build_source_put', ['space_id' => 1, 'build_id' => 2], 'space', true, '_stattic_build_source_put'],
        ['GET', '#^/spaces/([^/]+)/build-sources/([^/]+)$#', 'build_source_get', ['space_id' => 1, 'build_id' => 2], 'none', false, '_stattic_build_source_get'],
        ['GET', '#^/spaces/([^/]+)/build-sources/([^/]+)/body$#', 'build_source_read', ['space_id' => 1, 'build_id' => 2], 'none', true, '_stattic_build_source_read'],
        ['DELETE', '#^/spaces/([^/]+)/build-sources/([^/]+)$#', 'build_source_delete', ['space_id' => 1, 'build_id' => 2], 'space', false, '_stattic_build_source_delete'],
        ['POST', '#^/spaces/([^/]+)/versions$#', 'create_version', ['space_id' => 1], 'space', false, '_stattic_runtime_create_version'],
        ['GET', '#^/spaces/([^/]+)/versions/([^/]+)/uploads$#', 'get_upload_session', ['space_id' => 1, 'version_id' => 2], 'none', false, '_stattic_runtime_get_upload_session'],
        ['GET', '#^/spaces/([^/]+)/versions/([^/]+)/source$#', 'read_version_source', ['space_id' => 1, 'version_id' => 2], 'none', true, '_stattic_runtime_read_version_source_route'],
        ['GET', '#^/spaces/([^/]+)/versions/([^/]+)/files$#', 'list_version_files', ['space_id' => 1, 'version_id' => 2], 'none', false, '_stattic_runtime_list_version_files_route'],
        ['POST', '#^/spaces/([^/]+)/purge$#', 'purge_space', ['space_id' => 1], 'none', false, '_stattic_runtime_purge_space_route'],
        ['POST', '#^/spaces/([^/]+)/versions/([^/]+)/finalize$#', 'finalize_version', ['space_id' => 1, 'version_id' => 2], 'space', false, '_stattic_runtime_finalize_version'],
        ['POST', '#^/spaces/([^/]+)/versions/([^/]+)/zero/migrate$#', 'apply_zero_migrations', ['space_id' => 1, 'version_id' => 2], 'space', false, '_stattic_runtime_apply_version_zero_migrations'],
        ['POST', '#^/spaces/([^/]+)/versions/([^/]+)/delete$#', 'delete_version', ['space_id' => 1, 'version_id' => 2], 'space', false, '_stattic_runtime_delete_version'],
        ['PUT', '#^/spaces/([^/]+)/routes/([^/]+)$#', 'update_route', ['space_id' => 1, 'route_name' => 2], 'space', false, '_stattic_runtime_put_route'],
        ['PUT', '#^/spaces/([^/]+)/hostname-intent$#', 'update_hostname_intent', ['space_id' => 1], 'space', false, '_stattic_runtime_put_hostname_intent'],
        ['PUT', '#^/spaces/([^/]+)/tombstones$#', 'update_tombstones', ['space_id' => 1], 'space', false, '_stattic_runtime_put_tombstones'],
        ['PUT', '#^/spaces/([^/]+)/retention-policy$#', 'update_retention_policy', ['space_id' => 1], 'space', false, '_stattic_runtime_put_retention_policy'],
        ['POST', '#^/spaces/([^/]+)/delete$#', 'delete_space', ['space_id' => 1], 'space', false, '_stattic_runtime_delete_space'],
        ['POST', '#^/spaces/([^/]+)/repair$#', 'repair_space', ['space_id' => 1], 'site', false, '_stattic_runtime_repair_space'],
    ];

    $routes = [];
    foreach ($rows as [$routeMethod, $pattern, $action, $scope, $lock, $binary, $handler]) {
        $routes[] = [
            'lane' => 'management',
            'methods' => [$routeMethod],
            'pattern' => $pattern,
            'binary' => $binary,
            // Management routes never 405: a method mismatch keeps scanning and
            // falls through to the surface 404.
            'method_mismatch' => null,
            'action' => $action,
            'scope' => $scope,
            'lock' => $lock,
            'handler' => $handler,
        ];
    }
    return $routes;
}

// Upload lane rows: [method, pattern, binary (streams bytes / writes a raw
// response), captures (how many pattern groups the handler takes), the 405
// message answered when the pattern matches but the method does not — null
// keeps scanning and falls through to the surface 404]. Handlers are called as
// ($privateRoot, ...$captures, $claims), the same shape the management lane
// uses; the upload JWT resolves the session from its OWN claims, so a capture
// is never the authorization.
function _stattic_runtime_admin_upload_routes(): array
{
    $rows = [
        ['POST', '#^/spaces/([^/]+)/blobs/have$#', false, 1, 'Blob negotiation only supports POST.', '_stattic_runtime_upload_blobs_have'],
        ['PUT', '#^/([^/]+)/files/(.+)$#', true, 2, 'File upload only supports PUT.', '_stattic_runtime_upload_file'],
        ['PUT', '#^/spaces/([^/]+)/blobs/([^/]+)$#', true, 2, 'Blob upload only supports PUT.', '_stattic_runtime_upload_blob'],
        ['POST', '#^/([^/]+)/fetch/files/(.+)$#', false, 2, null, '_stattic_runtime_upload_file_from_url'],
    ];

    $routes = [];
    foreach ($rows as [$routeMethod, $pattern, $binary, $captures, $mismatch, $handler]) {
        $routes[] = [
            'lane' => 'upload',
            'methods' => [$routeMethod],
            'pattern' => $pattern,
            'binary' => $binary,
            'method_mismatch' => $mismatch === null ? null : [
                'allow' => $routeMethod,
                'code' => 'runtime_upload_operation_not_supported',
                'message' => $mismatch,
            ],
            'captures' => $captures,
            'handler' => $handler,
        ];
    }
    return $routes;
}
