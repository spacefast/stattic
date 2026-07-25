<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/auth.php';

// The ONE admin-API dispatcher. Every admin surface — management, file-fetch
// (scanner reads), and upload — dispatches through the same table-driven
// primitive: route rows declare their methods, pattern, auth lane, write-lock
// scope, and whether they stream binary bytes; the dispatcher owns route-path
// derivation, the management-hostname assert, CORS, OPTIONS 204, per-lane JWT
// verification, write-lock acquisition, and the error envelope.
//
// Auth lanes are hard security boundaries. Each lane verifies its own JWT
// audience and nothing else:
//   management — aud stattic-runtime-management: action + operation_id +
//     URL-scope claims, single-use jti replay guard.
//   file_fetch — aud stattic-runtime-file-fetch: READ-ONLY, multi-use within
//     its short TTL (deliberately NO jti replay cache — see
//     _stattic_runtime_require_file_fetch_jwt), token accepted from the
//     Authorization header or (per row) the ?access= query fallback.
//   upload — aud stattic-runtime-upload: Authorization: Bearer required, the
//     session-scope claims are asserted by the handlers against session state.
// A token minted for one lane can never authorize another lane's row: the
// lane's verifier pins its own `aud` before any claim is trusted.
//
// Handler modules load lazily per matched row, so each surface keeps exactly
// its pre-unification module set (the upload hot path never parses the
// management module tree and vice versa; the public serve path loads none of
// this file).
//
// Surfaces map to lanes: the management entrypoint (/__spacefast/api.php and
// the SSH dispatch transport) hosts the management + file_fetch lanes; the
// upload entrypoint (/__spacefast/upload.php) hosts the upload lane. The
// surface also picks the route-path derivation and the per-lane 404 code
// (runtime_route_not_found vs runtime_upload_route_not_found;
// runtime_file_fetch_route_not_found stays with the file-fetch rows).
function _stattic_runtime_admin_api(string $privateRoot, string $method, string $requestPath, string $surface): void
{
    _stattic_runtime_api_register_error_envelope();
    if ($surface === 'upload') {
        $routePath = _stattic_runtime_upload_api_route_path($requestPath);
        if (!is_string($routePath)) {
            _stattic_runtime_upload_route_not_found();
        }
    } else {
        $routePath = _stattic_runtime_management_api_route_path($requestPath);
        if (!is_string($routePath)) {
            _stattic_runtime_route_not_found();
        }
    }
    _stattic_runtime_assert_api_hostname();
    _stattic_runtime_cors_headers();

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    foreach (_stattic_runtime_admin_routes($privateRoot, $method, $surface) as $route) {
        if (preg_match($route['pattern'], $routePath, $matches) !== 1) {
            continue;
        }
        // SSH dispatch (admin/dispatch.php) carries one JSON envelope on
        // stdout; rows that stream binary bytes (or write raw responses)
        // stay HTTP-only regardless of request method.
        if ($route['binary'] && defined('STATTIC_RUNTIME_DISPATCH_CLI')) {
            _stattic_json_response(400, ['error' => ['code' => 'runtime_dispatch_unsupported_path', 'message' => 'Binary endpoints are not dispatchable over this transport.']]);
        }
        if (!in_array($method, $route['methods'], true)) {
            $mismatch = $route['method_mismatch'];
            if ($mismatch === null) {
                continue;
            }
            header('Allow: ' . $mismatch['allow'], false);
            _stattic_json_response(405, ['error' => ['code' => $mismatch['code'], 'message' => $mismatch['message']]]);
        }
        _stattic_runtime_admin_run_route($privateRoot, $route, $matches);
    }

    if ($surface === 'upload') {
        _stattic_runtime_upload_route_not_found();
    }
    _stattic_runtime_route_not_found();
}

// Applies the matched row's lane: lazy module load, the lane's exact JWT
// verification, and (management only) write-lock acquisition. Handlers respond
// and exit; a handler that returns falls back into the dispatch loop exactly
// like the pre-unification if-chains did.
function _stattic_runtime_admin_run_route(string $privateRoot, array $route, array $matches): void
{
    $handler = $route['handler'];

    if ($route['lane'] === 'management') {
        require_once __DIR__ . '/management.php';
        // Management mutations on a many-space shared site can exceed the
        // default PHP execution budget (the route-index rebuild walks every
        // space); a mid-rebuild kill surfaces as a provider-edge 504 with the
        // work half done (e2e checkpoint 2). Management requests are
        // JWT-authed, low-volume and never on the public hot path, so lift
        // the limit.
        @set_time_limit(0);
        $required = [];
        foreach ($route['scope'] as $field => $captureIndex) {
            $required[$field] = _stattic_runtime_id($matches[$captureIndex], $field);
        }
        $claims = _stattic_runtime_require_management_jwt($privateRoot, $route['action'], $required);
        // Handlers trust the dispatcher's scope validation and never re-run it:
        // they receive the validated (trimmed) ids positionally.
        $args = [$privateRoot, ...array_values($required), $claims];
        if ($route['locked']) {
            $spaceLockId = _stattic_runtime_space_write_lock_scope($route['action'], $required);
            if ($spaceLockId !== null) {
                _stattic_runtime_with_space_write_lock($privateRoot, $spaceLockId, static function () use ($handler, $args): void {
                    $handler(...$args);
                });
            } else {
                _stattic_runtime_with_write_lock($privateRoot, static function () use ($handler, $args): void {
                    $handler(...$args);
                });
            }
        } else {
            $handler(...$args);
        }
        return;
    }

    if ($route['lane'] === 'file_fetch') {
        require_once __DIR__ . '/file-fetch.php';
        // Row-declared scope resolver: validates request inputs (422/404
        // before any token is read) and returns the exact claim profile the
        // file-fetch JWT must carry.
        $auth = ($route['auth'])($matches);
        $claims = _stattic_runtime_require_file_fetch_jwt($privateRoot, $auth['required'], $auth['bearer_only'] ?? false);
        _stattic_runtime_assert_file_fetch_claims_absent($claims, $auth['absent']);
        $handler($matches, $claims, $auth);
        return;
    }

    require_once __DIR__ . '/upload.php';
    _stattic_runtime_reject_s3_control_operation($route['methods'][0]);
    $claims = _stattic_runtime_require_upload_authorization($privateRoot);
    $handler($matches, $claims);
}

// The unified route table, built per surface. Row fields: lane, methods,
// pattern, binary (streams bytes / writes a raw response — never dispatchable
// over the SSH envelope transport), method_mismatch (null = keep scanning,
// otherwise the 405 answered when the pattern matches but the method does
// not), and the lane-specific auth inputs (management: action/scope/locked;
// file_fetch: the scope-resolver closure; upload: the Allow method for the
// S3-control-operation guard).
function _stattic_runtime_admin_routes(string $privateRoot, string $method, string $surface): array
{
    if ($surface === 'upload') {
        return _stattic_runtime_admin_upload_routes($privateRoot);
    }
    return array_merge(
        _stattic_runtime_admin_file_fetch_routes($privateRoot, $method),
        _stattic_runtime_admin_management_routes()
    );
}

// Management lane rows: [method, pattern, action, scope (claim key => capture
// index, ascending), locked, binary, handler]. The dispatcher validates the
// scope ids (422 before auth), verifies the management JWT for the action, and
// calls the handler as ($privateRoot, ...$scopeIds, $claims) — inside the
// private write lock when the route mutates runtime state. Handlers that do
// not need the claims simply omit the trailing parameter (PHP ignores the extra
// positional argument), so never widen one of those without declaring the
// `array $claims` it would otherwise silently receive.
function _stattic_runtime_admin_management_routes(): array
{
    $rows = [
        ['GET', '#^/state$#', 'read_state', [], false, false, '_stattic_runtime_state_route'],
        ['POST', '#^/events/drain$#', 'drain_events', [], false, false, '_stattic_runtime_drain_callback_events'],
        // Job runner (§22): create/tick are site-wide (no URL-scoped space —
        // space_id rides the JWT claim instead) and tick holds only its own
        // lane lock, never the global write lock — locked=false throughout.
        ['POST', '#^/jobs$#', 'create_engine_job', [], false, false, '_stattic_runtime_jobs_create_route'],
        ['POST', '#^/jobs/tick$#', 'tick_engine_jobs', [], false, false, '_stattic_runtime_jobs_tick_route'],
        ['GET', '#^/jobs/([^/]+)$#', 'get_engine_job', ['job_id' => 1], false, false, '_stattic_runtime_jobs_get_route'],
        ['POST', '#^/transfer/bundle$#', 'transfer_bundle', [], true, false, '_stattic_runtime_transfer_bundle_route'],
        ['POST', '#^/transfer/commit$#', 'transfer_commit', [], true, false, '_stattic_runtime_transfer_commit_route'],
        ['POST', '#^/transfer/abort$#', 'transfer_abort', [], true, false, '_stattic_runtime_transfer_abort_route'],
        ['GET', '#^/access-events$#', 'access_events', [], false, false, '_stattic_runtime_read_access_events'],
        ['POST', '#^/spaces/([^/]+)/versions$#', 'create_version', ['space_id' => 1], false, false, '_stattic_runtime_create_version'],
        ['GET', '#^/spaces/([^/]+)/versions/([^/]+)/uploads$#', 'get_upload_session', ['space_id' => 1, 'version_id' => 2], false, false, '_stattic_runtime_get_upload_session'],
        ['POST', '#^/spaces/([^/]+)/versions/([^/]+)/finalize$#', 'finalize_version', ['space_id' => 1, 'version_id' => 2], true, false, '_stattic_runtime_finalize_version'],
        ['POST', '#^/spaces/([^/]+)/versions/([^/]+)/delete$#', 'delete_version', ['space_id' => 1, 'version_id' => 2], true, false, '_stattic_runtime_delete_version'],
        ['PUT', '#^/spaces/([^/]+)/routes/([^/]+)$#', 'update_route', ['space_id' => 1, 'route_name' => 2], true, false, '_stattic_runtime_put_route'],
        ['PUT', '#^/spaces/([^/]+)/hostname-intent$#', 'update_hostname_intent', ['space_id' => 1], true, false, '_stattic_runtime_put_hostname_intent'],
        ['PUT', '#^/spaces/([^/]+)/tombstones$#', 'update_tombstones', ['space_id' => 1], true, false, '_stattic_runtime_put_tombstones'],
        ['PUT', '#^/spaces/([^/]+)/retention-policy$#', 'update_retention_policy', ['space_id' => 1], true, false, '_stattic_runtime_put_retention_policy'],
        ['POST', '#^/spaces/([^/]+)/access/revocations$#', 'revoke_grant', ['space_id' => 1], true, false,
            static fn (string $privateRoot, string $spaceId, array $claims) => _stattic_runtime_update_access_revocations($privateRoot, $spaceId, $claims, true)],
        ['DELETE', '#^/spaces/([^/]+)/access/revocations$#', 'unrevoke_grant', ['space_id' => 1], true, false,
            static fn (string $privateRoot, string $spaceId, array $claims) => _stattic_runtime_update_access_revocations($privateRoot, $spaceId, $claims, false)],
        ['POST', '#^/spaces/([^/]+)/delete$#', 'delete_space', ['space_id' => 1], true, false, '_stattic_runtime_delete_space'],
        ['POST', '#^/spaces/([^/]+)/repair$#', 'repair_space', ['space_id' => 1], true, false, '_stattic_runtime_repair_space'],
        ['POST', '#^/spaces/([^/]+)/exports$#', 'start_space_export', ['space_id' => 1], false, false, '_stattic_runtime_start_space_export'],
        // Export/import handles are top-level resources (spec route table): the create call
        // is space-nested, but every step/archive/map call addresses the handle id and the
        // per-step JWT is scoped by export_id/import_id, not space_id.
        ['POST', '#^/exports/([^/]+)/step$#', 'step_space_export', ['export_id' => 1], false, false, '_stattic_runtime_step_space_export'],
        ['GET', '#^/exports/([^/]+)/archive$#', 'download_space_export', ['export_id' => 1], false, true, '_stattic_runtime_download_space_export'],
        ['POST', '#^/spaces/([^/]+)/imports$#', 'start_space_import', ['space_id' => 1], false, false, '_stattic_runtime_start_space_import'],
        ['PUT', '#^/imports/([^/]+)/archive$#', 'upload_space_import', ['import_id' => 1], false, true, '_stattic_runtime_upload_space_import_archive'],
        ['GET', '#^/imports/([^/]+)/archive$#', 'download_space_import_archive', ['import_id' => 1], false, true, '_stattic_runtime_download_space_import_archive'],
        ['PUT', '#^/imports/([^/]+)/map$#', 'map_space_import', ['import_id' => 1], true, false, '_stattic_runtime_map_space_import'],
        ['GET', '#^/imports/([^/]+)$#', 'get_space_import', ['import_id' => 1], false, false, '_stattic_runtime_get_space_import'],
        ['POST', '#^/imports/([^/]+)/step$#', 'step_space_import', ['import_id' => 1], true, false, '_stattic_runtime_step_space_import'],
    ];

    $routes = [];
    foreach ($rows as [$routeMethod, $pattern, $action, $scope, $locked, $binary, $handler]) {
        $routes[] = [
            'lane' => 'management',
            'methods' => [$routeMethod],
            'pattern' => $pattern,
            'binary' => $binary,
            // Management routes never 405: a method mismatch keeps scanning
            // and falls through to the surface 404, exactly as before.
            'method_mismatch' => null,
            'action' => $action,
            'scope' => $scope,
            'locked' => $locked,
            'handler' => $handler,
        ];
    }
    return $routes;
}

// File-fetch lane rows (the read-only scanner surface, C2). Every row streams
// raw bytes or writes its response outside the JSON envelope, so all rows are
// binary (HTTP-only). The scope resolvers validate request inputs before any
// token is read and pin the exact claim profile per request shape.
function _stattic_runtime_admin_file_fetch_routes(string $privateRoot, string $method): array
{
    $methodMismatch = [
        'allow' => 'GET, HEAD',
        'code' => 'runtime_file_fetch_method_not_allowed',
        'message' => 'Runtime file fetch only supports GET and HEAD.',
    ];

    return [
        // .../versions/{versionId}/scan-manifest?variant_route=production
        //
        // This is deliberately derived only when the background scanner asks for
        // it. Finalize remains unaware of scanning and cannot fail publication.
        // File metadata shards are the serving path's authoritative map of
        // substituted/generated base bytes; template-variants.php carries the
        // selected live route's overlay.
        [
            'lane' => 'file_fetch',
            'methods' => ['GET', 'HEAD'],
            'pattern' => '#^/spaces/([^/]+)/versions/([^/]+)/scan-manifest$#',
            'binary' => true,
            'method_mismatch' => $methodMismatch,
            'auth' => static function (array $matches): array {
                $spaceId = _stattic_runtime_id($matches[1], 'space_id');
                $versionId = _stattic_runtime_id($matches[2], 'version_id');
                if (!isset($_GET['variant_route']) || !is_string($_GET['variant_route']) || $_GET['variant_route'] === '') {
                    _stattic_json_response(422, ['error' => ['code' => 'runtime_scan_manifest_variant_route_required', 'message' => 'A scan manifest variant route is required.']]);
                }
                $variantRoute = _stattic_runtime_id($_GET['variant_route'], 'variant_route');
                return [
                    'required' => [
                        'space_id' => $spaceId,
                        'version_id' => $versionId,
                        'scope' => 'scan_manifest',
                        'variant_route' => $variantRoute,
                    ],
                    'absent' => ['path', 'sha256'],
                    'bearer_only' => true,
                ];
            },
            'handler' => static function (array $matches, array $claims, array $auth) use ($privateRoot, $method): void {
                $required = $auth['required'];
                _stattic_runtime_scan_manifest_response($privateRoot, $required['space_id'], $required['version_id'], $required['variant_route'], $method);
            },
        ],
        // .../versions/{versionId}/file?path=...
        [
            'lane' => 'file_fetch',
            'methods' => ['GET', 'HEAD'],
            'pattern' => '#^/spaces/([^/]+)/versions/([^/]+)/file$#',
            'binary' => true,
            'method_mismatch' => $methodMismatch,
            'auth' => static function (array $matches): array {
                $spaceId = _stattic_runtime_id($matches[1], 'space_id');
                $versionId = _stattic_runtime_id($matches[2], 'version_id');
                $path = isset($_GET['path']) && is_string($_GET['path']) ? $_GET['path'] : '';
                if ($path === '') {
                    _stattic_json_response(422, ['error' => ['code' => 'runtime_file_fetch_path_required', 'message' => 'A file path is required.']]);
                }
                return [
                    'required' => [
                        'space_id' => $spaceId,
                        'version_id' => $versionId,
                        'path' => _stattic_runtime_file_path($path),
                    ],
                    'absent' => ['scope', 'sha256', 'variant_route'],
                ];
            },
            'handler' => static function (array $matches, array $claims, array $auth) use ($privateRoot, $method): void {
                $required = $auth['required'];
                _stattic_runtime_stream_version_file($privateRoot, $required['space_id'], $required['version_id'], $required['path'], $method);
            },
        ],
        // .../versions/{versionId}/files/by-hash/{sha256}
        [
            'lane' => 'file_fetch',
            'methods' => ['GET', 'HEAD'],
            // The hash segment is matched loosely so a malformed hash still
            // answers the lane's own 404 (the scope resolver enforces the
            // 64-hex shape before any token is read, exactly like the old
            // strict pattern falling through to the lane 404).
            'pattern' => '#^/spaces/([^/]+)/versions/([^/]+)/files/by-hash/([^/]+)$#',
            'binary' => true,
            'method_mismatch' => $methodMismatch,
            'auth' => static function (array $matches): array {
                if (preg_match('/^[0-9a-f]{64}$/', $matches[3]) !== 1) {
                    _stattic_json_response(404, ['error' => ['code' => 'runtime_file_fetch_route_not_found', 'message' => 'Runtime file fetch route not found.']]);
                }
                $spaceId = _stattic_runtime_id($matches[1], 'space_id');
                $versionId = _stattic_runtime_id($matches[2], 'version_id');
                $sha256 = strtolower($matches[3]);
                $variantRoute = isset($_GET['variant_route']) && is_string($_GET['variant_route']) && $_GET['variant_route'] !== ''
                    ? _stattic_runtime_id($_GET['variant_route'], 'variant_route')
                    : null;
                $required = [
                    'space_id' => $spaceId,
                    'version_id' => $versionId,
                    'sha256' => $sha256,
                ];
                if ($variantRoute !== null) {
                    $required['variant_route'] = $variantRoute;
                }
                return [
                    'required' => $required,
                    'absent' => $variantRoute === null ? ['scope', 'path', 'variant_route'] : ['scope', 'path'],
                ];
            },
            'handler' => static function (array $matches, array $claims, array $auth) use ($privateRoot, $method): void {
                $required = $auth['required'];
                $variantRoute = $required['variant_route'] ?? null;
                $location = _stattic_runtime_version_location_for_hash($privateRoot, $required['space_id'], $required['version_id'], $required['sha256'], $variantRoute);
                if ($location === null) {
                    _stattic_json_response(404, ['error' => ['code' => 'runtime_file_fetch_not_found', 'message' => 'No file in this version matches the requested hash.']]);
                }
                _stattic_runtime_stream_version_file(
                    $privateRoot,
                    $required['space_id'],
                    $required['version_id'],
                    $location['path'],
                    $method,
                    $location['variant_route']
                );
            },
        ],
    ];
}

// Upload lane rows (the S3-shaped ingest surface). Row order preserves the
// pre-unification if-chain: batch first (any method answers its 405), then the
// method-gated parts/complete/fetch shapes that fall through to the generic
// file PUT — a PUT whose literal path ends in /complete or /fetch is stored as
// that file, exactly as before.
function _stattic_runtime_admin_upload_routes(string $privateRoot): array
{
    return [
        [
            'lane' => 'upload',
            'methods' => ['POST'],
            'pattern' => '#^/([^/]+)/batch$#',
            'binary' => false,
            'method_mismatch' => [
                'allow' => 'POST',
                'code' => 'runtime_upload_operation_not_supported',
                'message' => 'Runtime batch upload only supports POST.',
            ],
            'handler' => static fn (array $matches, array $claims) => _stattic_runtime_upload_batch($privateRoot, $matches[1], $claims),
        ],
        [
            'lane' => 'upload',
            'methods' => ['PUT'],
            'pattern' => '#^/([^/]+)/parts/([0-9]{1,5})/files/(.+)$#',
            'binary' => false,
            'method_mismatch' => null,
            'handler' => static fn (array $matches, array $claims) => _stattic_runtime_upload_file_part($privateRoot, $matches[1], $matches[3], (int) $matches[2], $claims),
        ],
        [
            'lane' => 'upload',
            'methods' => ['POST'],
            'pattern' => '#^/([^/]+)/complete/files/(.+)$#',
            'binary' => false,
            'method_mismatch' => null,
            'handler' => static fn (array $matches, array $claims) => _stattic_runtime_complete_chunked_upload($privateRoot, $matches[1], $matches[2], $claims),
        ],
        [
            'lane' => 'upload',
            'methods' => ['POST'],
            'pattern' => '#^/([^/]+)/fetch/files/(.+)$#',
            'binary' => false,
            'method_mismatch' => null,
            'handler' => static fn (array $matches, array $claims) => _stattic_runtime_upload_file_from_url($privateRoot, $matches[1], $matches[2], $claims),
        ],
        [
            'lane' => 'upload',
            'methods' => ['PUT'],
            'pattern' => '#^/([^/]+)/files/(.+)$#',
            'binary' => false,
            'method_mismatch' => [
                'allow' => 'PUT',
                'code' => 'runtime_upload_operation_not_supported',
                'message' => 'Runtime file upload only supports PUT.',
            ],
            'handler' => static fn (array $matches, array $claims) => _stattic_runtime_upload_file($privateRoot, $matches[1], $matches[2], $claims),
        ],
    ];
}
