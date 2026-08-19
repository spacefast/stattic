<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/pointers.php';
require_once __DIR__ . '/../shared/purge.php';
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/safety.php';
require_once __DIR__ . '/../shared/server-file.php';
require_once __DIR__ . '/upload-policy.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/generate.php';
require_once __DIR__ . '/finalize-rust.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/tier.php';
require_once __DIR__ . '/retention.php';
require_once __DIR__ . '/zero-db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/build-source.php';
require_once __DIR__ . '/engine-update.php';

function _stattic_runtime_with_write_lock(string $privateRoot, callable $callback): void
{
    $lockDir = $privateRoot . '/runtime';
    _stattic_runtime_mkdir($lockDir);
    _stattic_runtime_acquire_write_lock($lockDir . '/write.lock', $callback);
}

// A route earns this lock ONLY when every write it performs transitively stays
// inside spaces/{spaceId}/ (see the route table in admin/api.php). The lock
// file lives outside the space tree on purpose — that is what lets delete_space
// hold it while unlinking the tree.
function _stattic_runtime_with_space_write_lock(string $privateRoot, string $spaceId, callable $callback): void
{
    _stattic_space_write_lock_with(
        $privateRoot,
        $spaceId,
        STATTIC_LOCK_WAIT,
        _stattic_runtime_write_lock_unavailable(...),
        static function () use ($callback): void {
            $callback();
        },
    );
}

// The route index is the one site-shared artifact per-space mutations write, so
// it takes this always-innermost lock (acquired inside
// _stattic_runtime_update_route_index / _rebuild_route_index). Lock ordering is
// strictly site -> space -> index; every acquire path must follow it.
function _stattic_runtime_with_route_index_lock(string $privateRoot, callable $callback): void
{
    $lockDir = $privateRoot . '/routes';
    _stattic_runtime_mkdir($lockDir);
    _stattic_runtime_acquire_write_lock($lockDir . '/index.lock', $callback);
}

// apps/control-plane treats the 503 runtime_write_lock_unavailable code as
// retryable.
function _stattic_runtime_write_lock_unavailable(): never
{
    _stattic_problem_response(
        503,
        'runtime_write_lock_unavailable',
        'Runtime write lock is unavailable.',
        ['details' => ['timeout_ms' => STATTIC_LOCK_DEADLINE_MS]],
    );
}

function _stattic_runtime_acquire_write_lock(string $lockPath, callable $callback): void
{
    _stattic_lock_with(
        $lockPath,
        STATTIC_LOCK_WAIT,
        _stattic_runtime_write_lock_unavailable(...),
        static function () use ($callback): void {
            $callback();
        },
    );
}

function _stattic_runtime_create_version(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $versionId = isset($body['version_id']) && is_string($body['version_id'])
        ? _stattic_runtime_id($body['version_id'], 'version_id')
        : _stattic_runtime_new_id('dep');
    if (is_dir(_stattic_version_root($privateRoot, $spaceId, $versionId))) {
        _stattic_problem_response(409, 'version_already_committed', 'Version already exists.');
    }
    $files = _stattic_runtime_manifest_files($body['files'] ?? []);
    $retainedFiles = _stattic_runtime_manifest_files($body['retained_files'] ?? []);
    $reusableVersionId = isset($body['reusable_version_id']) && is_string($body['reusable_version_id'])
        ? _stattic_runtime_id($body['reusable_version_id'], 'reusable_version_id')
        : null;
    $retention = _stattic_runtime_retention_mode($body['retention'] ?? null, $reusableVersionId, $retainedFiles);
    $uploadId = _stattic_runtime_new_id('upl');
    $createdAt = gmdate('c');
    $expiresAt = isset($body['expires_at']) && is_string($body['expires_at']) && strtotime($body['expires_at']) !== false
        ? gmdate('c', (int) strtotime($body['expires_at']))
        : gmdate('c', time() + STATTIC_RUNTIME_UPLOAD_SESSION_DEFAULT_TTL_SECONDS);
    $manifestHash = isset($body['manifest_hash']) && is_string($body['manifest_hash']) ? $body['manifest_hash'] : null;
    $session = _stattic_runtime_publish_session_create($privateRoot, $spaceId, $uploadId, [
        'upload_id' => $uploadId,
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'manifest' => $files,
        'accepted' => (object) [],
        'created_at' => $createdAt,
        'expires_at' => $expiresAt,
        'runtime_instance_id' => is_string($claims['runtime_instance_id'] ?? null) ? $claims['runtime_instance_id'] : null,
        'manifest_hash' => $manifestHash,
        'retained_files' => $retainedFiles,
        'reusable_version_id' => $reusableVersionId,
        'retention' => $retention,
        'metadata' => is_array($body['metadata'] ?? null) ? $body['metadata'] : [],
    ]);
    if (($session['space_id'] ?? null) !== $spaceId || ($session['version_id'] ?? null) !== $versionId) {
        _stattic_problem_response(500, 'upload_session_create_failed', 'Could not create the publish session.');
    }

    _stattic_runtime_journal_management_diagnostic($privateRoot, $claims, [
        'event' => 'version_created',
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'upload_id' => $uploadId,
        'file_count' => count($files),
    ]);

    _stattic_json_response(201, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'upload_id' => $uploadId,
        'created_at' => $createdAt,
    ]);
}

function _stattic_runtime_upload_session_not_found(): never
{
    _stattic_problem_response(
        404,
        'version_upload_not_found',
        'Version upload session not found: sessions are deleted after finalize and expire (default 24h). Create a new version (POST /spaces/{spaceId}/versions) to obtain a fresh upload_id.',
    );
    exit;
}

function _stattic_runtime_get_upload_session(string $privateRoot, string $spaceId, string $versionId): void
{
    $session = null;
    $uploadId = null;
    $now = time();
    // A lock='none' read: expired records are skipped, never released. Reclaiming
    // a record drops its GC pin too, and that write belongs to the retention
    // sweep, which holds the per-space lock the GC and finalize also take.
    foreach (_stattic_record_store_records(_stattic_runtime_publish_sessions_store($privateRoot, $spaceId)) as $candidateId => $candidate) {
        if (($candidate['space_id'] ?? null) !== $spaceId || ($candidate['version_id'] ?? null) !== $versionId) {
            continue;
        }
        $expiresAt = strtotime((string) ($candidate['expires_at'] ?? ''));
        if ($expiresAt !== false && $expiresAt < $now) {
            continue;
        }
        $session = $candidate;
        $uploadId = $candidateId;
        break;
    }
    if ($session === null || $uploadId === null) {
        _stattic_runtime_upload_session_not_found();
    }
    $accepted = is_array($session['accepted'] ?? null) ? $session['accepted'] : [];
    $files = [];
    $pending = [];
    foreach ((is_array($session['manifest'] ?? null) ? $session['manifest'] : []) as $declared) {
        if (!is_array($declared) || !is_string($declared['path'] ?? null)) {
            continue;
        }
        $sha = is_string($declared['sha256'] ?? null) ? strtolower($declared['sha256']) : '';
        $uploaded = $sha !== '' && array_key_exists($sha, $accepted) && (int) $accepted[$sha] === (int) ($declared['size'] ?? -1);
        $entry = [
            'path' => $declared['path'],
            'size' => (int) ($declared['size'] ?? 0),
            'uploaded' => $uploaded,
        ];
        if ($sha !== '') {
            $entry['sha256'] = $sha;
        }
        $files[] = $entry;
        if (!$uploaded) {
            $pending[] = $declared['path'];
        }
    }
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'upload_id' => $uploadId,
        'created_at' => is_string($session['created_at'] ?? null) ? $session['created_at'] : null,
        'expires_at' => is_string($session['expires_at'] ?? null) ? $session['expires_at'] : null,
        'manifest_hash' => is_string($session['manifest_hash'] ?? null) ? $session['manifest_hash'] : null,
        'files' => $files,
        'pending_paths' => $pending,
    ]);
}

function _stattic_runtime_finalize_version(string $privateRoot, string $spaceId, string $versionId, array $claims): void
{
    $body = _stattic_json_body();
    $uploadId = isset($body['upload_id']) && is_string($body['upload_id'])
        ? _stattic_runtime_id($body['upload_id'], 'upload_id')
        : '';
    $session = $uploadId !== '' ? _stattic_runtime_publish_session_load($privateRoot, $spaceId, $uploadId) : null;
    if ($session === null || ($session['space_id'] ?? null) !== $spaceId || ($session['version_id'] ?? null) !== $versionId) {
        $descriptor = $body['session'] ?? null;
        $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
        if (is_array($descriptor) && !is_dir($versionRoot)) {
            $session = _stattic_runtime_materialize_lazy_upload_session($privateRoot, $uploadId, [
                'space_id' => $spaceId,
                'version_id' => $versionId,
                'runtime_instance_id' => $claims['runtime_instance_id'] ?? null,
            ], $descriptor, true);
            // Materialize hands back a pre-existing record untouched, so a
            // colliding upload_id created under another space/version would
            // return a foreign session. Bind it to this request's scope first.
            if (($session['space_id'] ?? null) !== $spaceId || ($session['version_id'] ?? null) !== $versionId) {
                _stattic_problem_response(403, 'upload_scope_forbidden', 'Upload session does not match this space and version.');
            }
        } else {
            // A replayed receipt still carries `session`: never recreate one once
            // the version root exists, that would overwrite the committed version.
            _stattic_runtime_finalize_idempotent_ready_response($privateRoot, $spaceId, $versionId, $body, $claims);
            _stattic_runtime_upload_session_not_found();
        }
    }
    if (($session['lazy'] ?? false) === true && is_array($body['session'] ?? null)) {
        $descriptor = $body['session'];
        if (($descriptor['upload_id'] ?? null) === $uploadId && ($descriptor['space_id'] ?? null) === $spaceId && ($descriptor['version_id'] ?? null) === $versionId) {
            if (array_key_exists('retained_files', $descriptor)) {
                $session['retained_files'] = _stattic_runtime_manifest_files($descriptor['retained_files']);
            }
            if (array_key_exists('reusable_version_id', $descriptor)) {
                $session['reusable_version_id'] = is_string($descriptor['reusable_version_id'])
                    ? _stattic_runtime_id($descriptor['reusable_version_id'], 'reusable_version_id')
                    : null;
            }
            $session['retention'] = _stattic_runtime_retention_mode(
                $descriptor['retention'] ?? null,
                is_string($session['reusable_version_id'] ?? null) ? $session['reusable_version_id'] : null,
                is_array($session['retained_files'] ?? null) ? $session['retained_files'] : [],
            );
            $session = _stattic_runtime_publish_session_replace($privateRoot, $spaceId, $uploadId, static function (array $current) use ($session): array {
                $current['retained_files'] = $session['retained_files'];
                $current['reusable_version_id'] = $session['reusable_version_id'];
                $current['retention'] = $session['retention'];
                return $current;
            });
        }
    }

    _stattic_runtime_publish_session_require_complete($session);
    $finalized = _stattic_runtime_finalize_with_rust(
        $privateRoot,
        $spaceId,
        $versionId,
        $uploadId,
        $session,
        $body,
    );
    _stattic_runtime_publish_session_release($privateRoot, $spaceId, $uploadId);
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $zeroFinalize = is_array($body['zero'] ?? null) ? $body['zero'] : null;
    // The config artifact is the marker that turns the pattern-route lane on,
    // so it follows the compiled Zero pack, not the caller's `zero` block.
    if ($zeroFinalize !== null || _stattic_runtime_version_has_zero_pack($versionRoot)) {
        _stattic_runtime_write_zero_config_artifact($versionRoot, $zeroFinalize ?? []);
    }
    $functionsFinalize = is_array($body['functions'] ?? null) ? $body['functions'] : null;
    if ($functionsFinalize !== null) {
        _stattic_runtime_write_functions_config_artifact($versionRoot, $functionsFinalize);
    }
    _stattic_runtime_apply_zero_migrations($versionRoot);
    $finalizedEvent = [
        'event' => 'version_finalized',
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'upload_id' => $uploadId,
        'file_count' => (int) $finalized['fileCount'],
    ];
    if (isset($body['activate']) && is_array($body['activate'])) {
        $activation = $body['activate'];
        $routeName = _stattic_runtime_activation_route_name($activation);
        $pointer = _stattic_runtime_write_route_pointer($privateRoot, $spaceId, $routeName, $versionId, $activation, $claims, true);
        $activationEventId = is_string($pointer['activation_event_id'] ?? null)
            ? $pointer['activation_event_id']
            : null;
        $purge = is_array($pointer['purge'] ?? null) ? $pointer['purge'] : null;
        $changedPaths = is_array($pointer['changed_paths'] ?? null) ? $pointer['changed_paths'] : [];
        $finalizedEvent['route_name'] = $routeName;
        if ($changedPaths !== []) {
            $finalizedEvent['changed_paths'] = $changedPaths;
        }
        if (is_string($activation['previous_version_id'] ?? null) && $activation['previous_version_id'] !== '') {
            $finalizedEvent['previous_version_id'] = $activation['previous_version_id'];
        }
    }
    _stattic_runtime_journal_management_diagnostic($privateRoot, $claims, $finalizedEvent);
    _stattic_runtime_finalize_ready_response(
        $privateRoot,
        $spaceId,
        $versionId,
        $versionRoot,
        $activationEventId ?? null,
        null,
        $purge ?? null,
        // The one field that CANNOT come from immutable metadata: what this run
        // cost. It describes the run, not the version, so it rides the
        // finalizer's envelope and stops here on a replay.
        is_array($finalized['telemetry'] ?? null) ? $finalized['telemetry'] : null,
    );
}

function _stattic_runtime_apply_version_zero_migrations(string $privateRoot, string $spaceId, string $versionId): void
{
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    if (!is_dir($versionRoot)) {
        _stattic_problem_response(404, 'version_not_found', 'Version not found.');
    }
    if (!is_file($versionRoot . '/zero/migrations.json')) {
        _stattic_problem_response(409, 'zero_migrations_not_found', 'This version has no stored Zero migration plan.');
    }
    _stattic_runtime_apply_zero_migrations($versionRoot);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'applied' => true,
    ]);
}

function _stattic_runtime_activation_route_name(array $activation): string
{
    return isset($activation['route_name']) && is_string($activation['route_name'])
        ? _stattic_runtime_id($activation['route_name'], 'route_name')
        : 'production';
}

/**
 * The finalize receipt. `manifest` is read from the version's catalog on both
 * the first answer and every replay, so a retried finalize returns byte-identical
 * files to the one that committed them — and so the control plane holds ONE
 * file identity, the runtime's.
 */
function _stattic_runtime_finalize_ready_response(string $privateRoot, string $spaceId, string $versionId, string $versionRoot, ?string $activationEventId = null, ?array $metadata = null, ?array $purge = null, ?array $telemetry = null): never
{
    $metadata ??= _stattic_runtime_read_json($versionRoot . '/metadata.json');
    $readinessTarget = is_array($metadata) && is_array($metadata['readinessTarget'] ?? null)
        ? $metadata['readinessTarget']
        : null;
    $readinessStatuses = is_array($readinessTarget)
        ? ($readinessTarget['expected_statuses'] ?? null)
        : null;
    $allowedReadinessStatuses = [200, 301, 302, 303, 307, 308, 401, 403, 404];
    if (
        !is_array($readinessTarget)
        || !is_string($readinessTarget['path'] ?? null)
        || !str_starts_with($readinessTarget['path'], '/')
        || !is_array($readinessStatuses)
        || count($readinessStatuses) === 0
        || !array_is_list($readinessStatuses)
        || array_filter($readinessStatuses, static fn ($status): bool => !is_int($status) || !in_array($status, $allowedReadinessStatuses, true)) !== []
    ) {
        _stattic_problem_response(
            500,
            'runtime_readiness_target_missing',
            'The runtime-authored readiness target is unavailable.',
        );
    }
    $catalog = _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
    if ($catalog === null) {
        _stattic_problem_response(
            500,
            'runtime_file_catalog_invalid',
            'The finalized version wrote no readable file catalog.',
        );
    }
    // Everything below is read from immutable metadata rather than from the
    // finalizer's return envelope, because a replayed finalize never re-runs the
    // finalizer: the version's compiled truth has to answer identically the
    // second time. The control plane stores these verbatim — it no longer
    // compiles the version a second time to guess at them.
    $digests = is_array($metadata['catalogDigests'] ?? null) ? $metadata['catalogDigests'] : null;
    $delta = is_array($metadata['catalogDelta'] ?? null) ? $metadata['catalogDelta'] : null;
    $previewImagePath = is_string($metadata['previewImagePath'] ?? null) && $metadata['previewImagePath'] !== ''
        ? $metadata['previewImagePath']
        : null;
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'status' => 'ready',
        'zero_endpoint_count' => _stattic_runtime_zero_endpoint_count($versionRoot),
        'manifest' => _stattic_runtime_catalog_manifest($catalog),
        'preview_image_path' => $previewImagePath,
        'readiness_target' => $readinessTarget,
        ...($digests !== null ? ['catalog_digests' => $digests] : []),
        ...($delta !== null ? ['delta' => [
            'added' => (int) ($delta['added'] ?? 0),
            'changed' => (int) ($delta['changed'] ?? 0),
            'removed' => (int) ($delta['removed'] ?? 0),
        ]] : []),
        'diagnostics' => _stattic_runtime_metadata_list($metadata, 'diagnostics'),
        'variable_digests' => is_array($metadata['variableDigests'] ?? null) ? (object) $metadata['variableDigests'] : (object) [],
        'system_variable_dependencies' => _stattic_runtime_metadata_list($metadata, 'systemVariableDependencies'),
        'routing' => _stattic_runtime_finalize_routing($metadata),
        // Stage timings and counts for the run that just happened. Absent on a
        // replay, which finalizes nothing.
        ...($telemetry !== null ? ['telemetry' => $telemetry] : []),
        ...($activationEventId !== null ? ['activation_event_id' => $activationEventId] : []),
        ...($purge !== null ? ['purge' => $purge] : []),
    ]);
}

function _stattic_runtime_metadata_list(?array $metadata, string $key): array
{
    $value = $metadata[$key] ?? null;
    return is_array($value) && array_is_list($value) ? $value : [];
}

/**
 * The version's compiled rule counts and its proxy rules. The counts are the
 * control plane's version-row projection; the proxy rules are what it overlays
 * the publishing team's plan onto. The artifact itself stays plan-agnostic —
 * this is a report of what compiled, never a verdict on it.
 */
function _stattic_runtime_finalize_routing(?array $metadata): array
{
    $routing = is_array($metadata['routing'] ?? null) ? $metadata['routing'] : [];
    $proxyRules = [];
    foreach (_stattic_runtime_metadata_list($routing, 'proxyRules') as $rule) {
        if (!is_array($rule) || !is_string($rule['source'] ?? null) || !is_string($rule['destination'] ?? null)) {
            continue;
        }
        $proxyRules[] = ['source' => $rule['source'], 'destination' => $rule['destination']];
    }
    return [
        'redirect_rule_count' => (int) ($routing['redirectRuleCount'] ?? 0),
        'header_rule_count' => (int) ($routing['headerRuleCount'] ?? 0),
        'proxy_rule_count' => (int) ($routing['proxyRuleCount'] ?? 0),
        'proxy_rules' => $proxyRules,
    ];
}

function _stattic_runtime_finalize_idempotent_ready_response(string $privateRoot, string $spaceId, string $versionId, array $body = [], array $claims = []): void
{
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $metadata = _stattic_runtime_read_json($versionRoot . '/metadata.json');
    if (
        !is_array($metadata)
        || ($metadata['spaceId'] ?? null) !== $spaceId
        || ($metadata['versionId'] ?? null) !== $versionId
        || !_stattic_runtime_version_finalized($versionRoot)
    ) {
        return;
    }

    // A retried finalize+activate must still converge the route pointer: the
    // first attempt may have died after finalizing artifacts but before the
    // pointer write. The conditional write makes a pure duplicate retry a no-op.
    if (isset($body['activate']) && is_array($body['activate'])) {
        $activation = $body['activate'];
        $pointer = _stattic_runtime_write_route_pointer($privateRoot, $spaceId, _stattic_runtime_activation_route_name($activation), $versionId, $activation, $claims, true);
        $activationEventId = is_string($pointer['activation_event_id'] ?? null)
            ? $pointer['activation_event_id']
            : null;
        $purge = is_array($pointer['purge'] ?? null) ? $pointer['purge'] : null;
    }

    _stattic_runtime_finalize_ready_response($privateRoot, $spaceId, $versionId, $versionRoot, $activationEventId ?? null, $metadata, $purge ?? null);
}

function _stattic_runtime_zero_endpoint_count(string $versionRoot): int
{
    $index = _stattic_runtime_read_json($versionRoot . '/zero/endpoints-index.json');
    return is_array($index) && is_array($index['endpoints'] ?? null) ? count($index['endpoints']) : 0;
}

function _stattic_runtime_route_intent_hostnames(string $spaceRoot, ?array $intent = null): array
{
    $hostnames = [];
    $intent ??= _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json');
    if (is_array($intent) && is_array($intent['routes'] ?? null)) {
        foreach ($intent['routes'] as $route) {
            if (is_array($route) && is_string($route['hostname'] ?? null)) {
                $hostnames[$route['hostname']] = true;
            }
        }
    }
    return array_keys($hostnames);
}

// The whole-domain purge every space mutation owes the edge. `$hostnames` is
// supplied only when the caller already narrowed the set (a tombstone push);
// otherwise it is the space's full event hostname set.
function _stattic_runtime_purge_space_now(string $privateRoot, string $spaceId, string $reason, array $paths = [], ?array $hostnames = null): array
{
    return _stattic_runtime_purge_now($privateRoot, [
        'hostnames' => $hostnames ?? _stattic_runtime_space_event_hostnames(_stattic_space_root($privateRoot, $spaceId)),
        'paths' => $paths,
        'reason' => $reason,
    ]);
}

// The exact hostname set a space_deleted event carries — the control plane signs
// this set before asking the runtime to delete the space, so the state preflight
// and the mutation must keep deriving it here, together.
function _stattic_runtime_space_event_hostnames(string $spaceRoot, ?array $intent = null, ?array $tombstones = null): array
{
    $hostnames = array_fill_keys(_stattic_runtime_route_intent_hostnames($spaceRoot, $intent), true);
    $tombstones ??= _stattic_runtime_read_json($spaceRoot . '/tombstones.json');
    if (is_array($tombstones) && is_array($tombstones['hostnames'] ?? null)) {
        foreach ($tombstones['hostnames'] as $hostname) {
            if (is_string($hostname)) {
                $hostnames[$hostname] = true;
            }
        }
    }
    return array_keys($hostnames);
}

function _stattic_runtime_state_summary(string $privateRoot): array
{
    // The reconcile handshake compares generations as opaque strings, so the
    // pointer's int gen is stringified rather than widening that contract.
    $current = _sf_pointer_read('routes', $privateRoot . '/routes/current.json');
    $generation = is_array($current) && is_int($current['gen'] ?? null)
        ? (string) $current['gen']
        : null;
    $spaces = [];
    foreach (glob($privateRoot . '/spaces/*', GLOB_ONLYDIR) ?: [] as $spaceRoot) {
        $routes = [];
        foreach (glob($spaceRoot . '/routes/*.json') ?: [] as $pointerPath) {
            $pointer = _stattic_runtime_read_json($pointerPath);
            if (
                is_array($pointer)
                && is_string($pointer['route_name'] ?? null)
                && is_string($pointer['version_id'] ?? null)
            ) {
                $routes[$pointer['route_name']] = $pointer['version_id'];
            }
        }
        $intentDoc = _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json');
        $intent = is_array($intentDoc) ? $intentDoc : [];
        $tombstonesDoc = _stattic_runtime_read_json($spaceRoot . '/tombstones.json');
        $tombstones = is_array($tombstonesDoc) ? $tombstonesDoc : [];
        $spaces[] = [
            'space_id' => basename($spaceRoot),
            'routes' => (object) $routes,
            'tombstone_count' => is_array($tombstones['hostnames'] ?? null)
                ? count($tombstones['hostnames'])
                : 0,
            'intent_hostnames' => _stattic_runtime_route_intent_hostnames($spaceRoot, $intent),
            'hostnames' => _stattic_runtime_space_event_hostnames($spaceRoot, $intent, $tombstones),
        ];
    }
    return ['routes_generation' => $generation, 'spaces' => $spaces];
}

function _stattic_runtime_state_route(string $privateRoot): void
{
    $summary = _stattic_runtime_state_summary($privateRoot);
    _stattic_json_response(200, [
        'ok' => true,
        'runtime' => 'stattic-php',
        'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
        'routes_generation' => $summary['routes_generation'],
        'spaces' => $summary['spaces'],
    ]);
}

// The journal IS the event sink and the cursor is the only delivery state. A
// cursor is not deduplication: the control plane must commit it together with
// the side effects of the page it just processed.
const STATTIC_RUNTIME_EVENT_DRAIN_MAX = 100;

function _stattic_runtime_journal_cursor_path(string $privateRoot): string
{
    return $privateRoot . '/runtime/journal-cursor.json';
}

/** @return array{file: string, offset: int, inode: int} */
function _stattic_runtime_journal_cursor_normalize(mixed $cursor): array
{
    $cursor = is_array($cursor) ? $cursor : [];
    return [
        // basename() so a stored cursor can never point the reader outside
        // runtime/ — the file name is data that survived a round trip.
        'file' => is_string($cursor['file'] ?? null) ? basename($cursor['file']) : '',
        'offset' => is_int($cursor['offset'] ?? null) ? max(0, $cursor['offset']) : 0,
        // The generation's inode. Rotation renames `journal.jsonl` out from
        // under a name-only cursor, so the name alone does not identify which
        // bytes an offset belongs to; shared/storage.php resolves by this and
        // never compares offsets across files. 0 = a pre-upgrade cursor.
        'inode' => is_int($cursor['inode'] ?? null) ? max(0, $cursor['inode']) : 0,
    ];
}

/** @return array{file: string, offset: int, inode: int} */
function _stattic_runtime_journal_cursor_read(string $privateRoot): array
{
    return _stattic_runtime_journal_cursor_normalize(
        _stattic_runtime_read_json(_stattic_runtime_journal_cursor_path($privateRoot))
    );
}

function _stattic_runtime_journal_cursor_write(string $privateRoot, array $cursor): void
{
    _stattic_runtime_mkdir($privateRoot . '/runtime');
    _stattic_runtime_write_json_atomic(
        _stattic_runtime_journal_cursor_path($privateRoot),
        _stattic_runtime_journal_cursor_normalize($cursor) + ['updated_at' => gmdate('c')]
    );
}

// Opaque to the client: the per-event `cursor` on the drain response is the
// published coordinate. This token carries the same position so `deliveries`
// acks stay self-describing, and only this file ever encodes or decodes it.
function _stattic_runtime_journal_cursor_token(array $cursor): string
{
    $encoded = (string) json_encode(
        _stattic_runtime_journal_cursor_normalize($cursor),
        JSON_UNESCAPED_SLASHES
    );
    return _stattic_base64url_encode($encoded);
}

/** @return array{file: string, offset: int, inode: int}|null */
function _stattic_runtime_journal_cursor_from_token(string $token): ?array
{
    $cursor = json_decode(_stattic_base64url_decode($token), true);
    if (!is_array($cursor) || !is_string($cursor['file'] ?? null) || !is_int($cursor['offset'] ?? null)) {
        return null;
    }
    return _stattic_runtime_journal_cursor_normalize($cursor);
}

// Cursors order by generation age then byte offset — the journal's own read
// order (shared/storage.php `_stattic_runtime_journal_files`), never by file
// name: after a rotation a stored `journal.jsonl` names the ROTATED generation,
// and ranking it by name would put it ahead of that generation's own ack.
function _stattic_runtime_journal_cursor_rank(string $privateRoot, array $cursor): array
{
    return _stattic_runtime_journal_cursor_position(
        $privateRoot,
        _stattic_runtime_journal_files($privateRoot),
        (string) ($cursor['file'] ?? ''),
        (int) ($cursor['offset'] ?? 0),
        (int) ($cursor['inode'] ?? 0)
    );
}

function _stattic_runtime_journal_cursor_advances(string $privateRoot, array $from, array $to): bool
{
    return _stattic_runtime_journal_cursor_rank($privateRoot, $to)
        > _stattic_runtime_journal_cursor_rank($privateRoot, $from);
}

/**
 * One page of journal records, each paired with the cursor that resumes AFTER it.
 * Read one record at a time: the per-record coordinate is what a partial
 * acknowledgement needs, and the batch reader only reports the page end.
 *
 * @return array{records: list<array{entry: array, cursor: array}>, cursor: array}
 */
function _stattic_runtime_journal_page(string $privateRoot, array $cursor, int $max): array
{
    $records = [];
    for ($read = 0; $read < $max; $read += 1) {
        $next = _stattic_runtime_journal_read($privateRoot, $cursor, 1);
        if (($next['entries'] ?? []) === []) {
            break;
        }
        $cursor = _stattic_runtime_journal_cursor_normalize($next['cursor']);
        $records[] = ['entry' => $next['entries'][0], 'cursor' => $cursor];
    }
    return ['records' => $records, 'cursor' => $cursor];
}

function _stattic_runtime_drained_event(array $entry, array $cursor): array
{
    $eventId = (string) ($entry['event_id'] ?? '');
    return [
        'delivery_id' => _stattic_runtime_journal_cursor_token($cursor),
        // The position to ack THROUGH this event: a client that stops here
        // commits exactly this cursor. The page cursor is the last one.
        'cursor' => _stattic_runtime_journal_cursor_normalize($cursor),
        'event_id' => $eventId,
        ...(is_string($entry['operation_id'] ?? null) ? ['operation_id' => $entry['operation_id']] : []),
        'event' => $entry,
    ];
}

function _stattic_runtime_event_drain_invalid(): never
{
    _stattic_problem_response(422, 'runtime_event_drain_invalid', 'Runtime event drain request is invalid.');
}

function _stattic_runtime_drain_callback_events(string $privateRoot, array $claims): void
{
    $body = _stattic_json_body();
    if (!is_string($body['session_id'] ?? null)) {
        _stattic_runtime_event_drain_invalid();
    }
    _stattic_runtime_id($body['session_id'], 'session_id');
    $finishSession = $body['finish_session'] ?? false;
    if (!is_bool($finishSession)) {
        _stattic_runtime_event_drain_invalid();
    }
    if (!$finishSession && !is_string($body['page_id'] ?? null)) {
        _stattic_runtime_event_drain_invalid();
    }
    $targetEventId = null;
    if (array_key_exists('target_event_id', $body)) {
        if (!is_string($body['target_event_id'])) {
            _stattic_runtime_event_drain_invalid();
        }
        $targetEventId = _stattic_runtime_id($body['target_event_id'], 'target_event_id');
    }
    // session_id/page_id/exclude_deliveries are accepted and unused, so finishing
    // a session is a no-op that reports an empty page.
    $cursor = _stattic_runtime_journal_cursor_read($privateRoot);
    if ($finishSession) {
        _stattic_runtime_drain_response($privateRoot, $cursor, [], 0);
    }

    $page = _stattic_runtime_journal_page($privateRoot, $cursor, STATTIC_RUNTIME_EVENT_DRAIN_MAX);
    $events = [];
    foreach ($page['records'] as $record) {
        $entry = $record['entry'];
        // Only management events carry an event_id. Everything else in the
        // journal is operator diagnostics (job lifecycle, GC, purge receipts) and
        // is skipped over — the cursor still advances past it.
        if (!is_array($entry) || !is_string($entry['event_id'] ?? null) || $entry['event_id'] === '') {
            continue;
        }
        if ($targetEventId !== null && $entry['event_id'] !== $targetEventId) {
            continue;
        }
        $events[] = _stattic_runtime_drained_event($entry, $record['cursor']);
    }

    // The control plane only compares pending_count against zero, so probe for
    // "is there more" rather than counting.
    $more = _stattic_runtime_journal_read($privateRoot, $page['cursor'], 1);
    _stattic_runtime_drain_response(
        $privateRoot,
        $page['cursor'],
        $events,
        ($more['entries'] ?? []) === [] ? 0 : 1
    );
}

function _stattic_runtime_drain_response(string $privateRoot, array $cursor, array $events, int $pending): never
{
    _stattic_json_response(200, [
        // Permanently zero; they stay in the envelope until the control-plane
        // response schema drops them.
        'delivered_count' => 0,
        'failed_count' => 0,
        'expired_count' => 0,
        'returned_count' => count($events),
        'pending_count' => $pending,
        'cursor' => _stattic_runtime_journal_cursor_normalize($cursor),
        'events' => $events,
    ]);
}

/**
 * Advances the persisted cursor. `{cursor: …}` is authoritative; `{deliveries:
 * […]}` is the same commit per event and the furthest one wins. On a delivery
 * acknowledgement the persisted cursor never moves BACKWARDS, so a replayed page
 * cannot re-deliver everything after it.
 */
function _stattic_runtime_ack_callback_events(string $privateRoot): void
{
    $reject = static function (): never {
        _stattic_problem_response(422, 'runtime_event_ack_invalid', 'Runtime event acknowledgements are invalid.');
    };
    $body = _stattic_json_body();
    if (isset($body['session_id']) && !is_string($body['session_id'])) {
        $reject();
    }

    $stored = _stattic_runtime_journal_cursor_read($privateRoot);
    $cursor = null;
    if (array_key_exists('cursor', $body)) {
        if (!is_array($body['cursor'])) {
            $reject();
        }
        $cursor = _stattic_runtime_journal_cursor_normalize($body['cursor']);
    }

    $acknowledged = 0;
    $stale = 0;
    if (array_key_exists('deliveries', $body)) {
        $deliveries = $body['deliveries'];
        if (
            !is_array($deliveries)
            || !array_is_list($deliveries)
            || count($deliveries) > STATTIC_RUNTIME_EVENT_DRAIN_MAX
        ) {
            $reject();
        }
        $furthest = $stored;
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery) || !is_string($delivery['delivery_id'] ?? null)) {
                $reject();
            }
            $candidate = _stattic_runtime_journal_cursor_from_token($delivery['delivery_id']);
            if ($candidate === null) {
                $stale += 1;
                continue;
            }
            $acknowledged += 1;
            if (_stattic_runtime_journal_cursor_advances($privateRoot, $furthest, $candidate)) {
                $furthest = $candidate;
            }
        }
        if ($cursor === null && $acknowledged > 0) {
            $cursor = $furthest;
        }
    }

    if ($cursor === null) {
        $reject();
    }
    _stattic_runtime_journal_cursor_write($privateRoot, $cursor);

    _stattic_json_response(200, [
        'acknowledged_count' => $acknowledged,
        'idempotent_count' => 0,
        'stale_count' => $stale,
        'cursor' => $cursor,
    ]);
}

// The one authority for "this version HAS Zero" during finalize: a Space with
// no Zero must not acquire the pattern-route lane.
function _stattic_runtime_version_has_zero_pack(string $versionRoot): bool
{
    return is_file($versionRoot . '/zero/routes.php')
        || is_file($versionRoot . '/zero/endpoints-index.json')
        || is_file($versionRoot . '/zero/runs-index.json');
}

function _stattic_runtime_write_zero_config_artifact(string $versionRoot, array $zero): void
{
    _stattic_runtime_mkdir($versionRoot . '/zero');
    _stattic_runtime_write_json_atomic($versionRoot . '/zero/config.json', _stattic_runtime_zero_config_artifact($zero));
}

// Security: this artifact names the execution host, granted capabilities, and
// the worker's broker credential, so it lives BESIDE the version's file tree —
// a publish reaches `files/` and nothing else, so no upload can forge it. Fields
// are copied one by one so an unrecognised key cannot land in it either.
function _stattic_runtime_write_functions_config_artifact(string $versionRoot, array $functions): void
{
    _stattic_runtime_mkdir($versionRoot . '/functions');
    _stattic_runtime_write_json_atomic(
        $versionRoot . '/functions/config.json',
        _stattic_runtime_functions_config_artifact($functions)
    );
    $artifact = is_array($functions['artifact'] ?? null) ? $functions['artifact'] : [];
    _stattic_runtime_write_php_atomic(
        $versionRoot . '/functions/routes.php',
        _stattic_runtime_functions_routes_artifact(
            is_array($artifact['routes'] ?? null) ? $artifact['routes'] : []
        )
    );
}

/**
 * The compiled dispatch route table serve time reads: a request matching no
 * entry never wakes the worker. A `subtree` route expands into the path itself
 * plus its `:splat` descendant pattern; an entry failing validation is dropped,
 * which fails closed.
 */
function _stattic_runtime_functions_routes_artifact(array $routes): array
{
    $exact = [];
    $byFirstSegment = [];
    $fallback = [];
    $seen = [];
    $append = static function (?string $method, string $pattern) use (&$exact, &$byFirstSegment, &$fallback, &$seen): void {
        $key = ($method ?? '*') . ' ' . $pattern;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $entry = ['method' => $method, 'pattern' => $pattern];
        if (!str_contains($pattern, ':')) {
            $exact[] = $entry;
            return;
        }
        $first = explode('/', trim($pattern, '/'), 2)[0];
        if ($first !== '' && !str_starts_with($first, ':')) {
            $byFirstSegment[$first][] = $entry;
        } else {
            $fallback[] = $entry;
        }
    };
    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }
        $path = $route['path'] ?? null;
        if (!_stattic_runtime_route_pattern_valid($path)) {
            continue;
        }
        $method = is_string($route['method'] ?? null) && $route['method'] !== ''
            ? strtoupper($route['method'])
            : null;
        $append($method, $path);
        if (($route['subtree'] ?? false) === true) {
            $base = rtrim($path, '/');
            $append($method, $base === '' ? '/:splat' : $base . '/:splat');
        }
    }
    ksort($byFirstSegment, SORT_STRING);

    return [
        'runtime_schema' => STATTIC_RUNTIME_SCHEMA,
        'runtime_engine_version' => SPACEFAST_RUNTIME_ENGINE_VERSION,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'format' => 'stattic.functions.routes.v1',
        'artifact_kind' => 'functions_routes',
        'exact' => $exact,
        'by_first_segment' => $byFirstSegment,
        'fallback' => $fallback,
    ];
}

function _stattic_runtime_functions_config_artifact(array $functions): array
{
    return [
        'runtimeKind' => 'functions',
        'artifact' => is_array($functions['artifact'] ?? null) ? $functions['artifact'] : [],
        'host' => is_array($functions['host'] ?? null) ? $functions['host'] : [],
        // Fail closed at the relay: a grant the control plane did not send is no
        // grant, so a malformed or truncated config must default to empty.
        'grantedCapabilities' => _stattic_runtime_functions_capabilities($functions['grantedCapabilities'] ?? null),
        'relay' => is_array($functions['relay'] ?? null) ? $functions['relay'] : null,
        // Where invocation counts go, which is the control plane and not this
        // origin. Absent means uncounted, never unserved.
        'usage' => is_array($functions['usage'] ?? null) ? $functions['usage'] : null,
        // The credential the purge intake compares (`purge.token`, presented as
        // `sf-purge-token`) and dispatch forwards as `sf-fx-purge-token`. Absent
        // means no purge channel: the worker's revalidates wait out the CDN's
        // own TTL, never a refused dispatch.
        'purge' => is_array($functions['purge'] ?? null) ? $functions['purge'] : null,
        'variables' => is_array($functions['variables'] ?? null) ? $functions['variables'] : [],
        'variableValues' => is_array($functions['variableValues'] ?? null) ? _stattic_zero_string_map($functions['variableValues']) : [],
        'inspect' => is_array($functions['inspect'] ?? null) ? $functions['inspect'] : [],
    ];
}

function _stattic_runtime_functions_capabilities($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $capabilities = [];
    foreach ($value as $capability) {
        if (is_string($capability) && $capability !== '') {
            $capabilities[] = $capability;
        }
    }
    return $capabilities;
}

function _stattic_runtime_zero_config_artifact(array $zero): array
{
    $artifact = [
        'runtimeKind' => 'zero',
        'artifact' => is_array($zero['artifact'] ?? null) ? $zero['artifact'] : [],
        'install' => is_array($zero['install'] ?? null) ? $zero['install'] : [],
        'variables' => is_array($zero['variables'] ?? null) ? $zero['variables'] : [],
        'variableValues' => is_array($zero['variableValues'] ?? null) ? _stattic_zero_string_map($zero['variableValues']) : [],
        'auth' => is_array($zero['auth'] ?? null) ? $zero['auth'] : [],
        'realtime' => is_array($zero['realtime'] ?? null) ? $zero['realtime'] : [],
        'inspect' => is_array($zero['inspect'] ?? null) ? $zero['inspect'] : [],
    ];
    if (in_array($zero['databaseUrlSource'] ?? null, ['application', 'provider'], true)) {
        // Only ever from the authenticated control-plane finalize request; never
        // inferred from tenant variable contents.
        $artifact['databaseUrlSource'] = $zero['databaseUrlSource'];
    }
    if (array_key_exists('migrations', $zero)) {
        $artifact['migrations'] = $zero['migrations'];
    }
    return $artifact;
}

function _stattic_runtime_put_route(string $privateRoot, string $spaceId, string $routeName, array $claims): void
{
    $body = _stattic_json_body();
    $versionId = isset($body['version_id']) && is_string($body['version_id'])
        ? _stattic_runtime_id($body['version_id'], 'version_id')
        : '';
    if ($versionId === '' || !_stattic_runtime_version_finalized(_stattic_version_root($privateRoot, $spaceId, $versionId))) {
        _stattic_problem_response(404, 'version_not_found', 'Version not found.');
    }
    if (array_key_exists('routes', $body)) {
        _stattic_problem_response(422, 'routes_input_retired', 'Runtime compiles routes from hostname intent. Send production_hostnames and version_hostnames.');
    }
    $storeIntent = array_key_exists('production_hostnames', $body)
        || array_key_exists('version_hostnames', $body)
        || array_key_exists('proxy_host_routes', $body);
    $pointer = _stattic_runtime_write_route_pointer($privateRoot, $spaceId, $routeName, $versionId, $body, $claims, $storeIntent);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'route_name' => $routeName,
        'version_id' => $versionId,
        ...($pointer['unchanged'] ? ['unchanged' => true] : []),
        ...(is_string($pointer['activation_event_id'] ?? null)
            ? ['activation_event_id' => $pointer['activation_event_id']]
            : []),
        ...(is_array($pointer['purge'] ?? null) ? ['purge' => $pointer['purge']] : []),
    ]);
}

function _stattic_runtime_put_hostname_intent(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $routes = _stattic_runtime_routes_from_hostname_intent('production', $body);
    _stattic_runtime_store_hostname_intent($privateRoot, $spaceId, $routes, $claims);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    // Whole-domain: an intent change re-points hostnames, so which paths changed
    // is not a question this mutation can answer.
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'route_count' => count($routes),
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'hostname_intent_updated'),
    ]);
}

function _stattic_runtime_route_updated_event(string $spaceId, string $routeName, string $versionId, array $hostnames, array $changedPaths, bool $changedPathsKnown, array $body): array
{
    $event = [
        'event' => 'route_updated',
        'space_id' => $spaceId,
        'route_name' => $routeName,
        'version_id' => $versionId,
        'hostnames' => $hostnames,
        'changed_paths' => $changedPaths,
        'changed_paths_known' => $changedPathsKnown,
    ];
    if (is_string($body['previous_version_id'] ?? null) && $body['previous_version_id'] !== '') {
        $event['previous_version_id'] = $body['previous_version_id'];
    }
    return $event;
}

// The one route-pointer flip, for route PUT and finalize+activate alike. An
// unchanged conditional write must stay fully silent — no journal event means no
// edge purge for reconcile-sweep and plan-sync re-pushes.
function _stattic_runtime_write_route_pointer(string $privateRoot, string $spaceId, string $routeName, string $versionId, array $body, array $claims, bool $storeIntent): array
{
    $configDigest = is_string($body['config_digest'] ?? null) && $body['config_digest'] !== ''
        ? $body['config_digest']
        : null;
    $routePath = _stattic_route_pointer_path($privateRoot, $spaceId, $routeName);
    $previousRoute = _stattic_runtime_read_json($routePath);
    // Guarded on $storeIntent because compiling also validates the intent body,
    // and a route write carrying no intent must not start rejecting bodies it
    // never inspected.
    $intentRoutes = $storeIntent ? _stattic_runtime_routes_from_hostname_intent($routeName, $body) : [];
    $operationId = is_string($claims['operation_id'] ?? null)
        ? trim($claims['operation_id'])
        : '';
    $requestDigest = _stattic_runtime_route_activation_request_digest($body);
    $storedActivationEventId = is_string($previousRoute['activation_event_id'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/', $previousRoute['activation_event_id']) === 1
        ? $previousRoute['activation_event_id']
        : null;
    // The authenticated operation is the idempotency key for the irreversible
    // pointer flip: a finalize retry carries changed_paths by design, so the
    // config-digest no-op below cannot identify it.
    if (
        $storedActivationEventId !== null
        && $operationId !== ''
        && ($previousRoute['activation_operation_id'] ?? null) === $operationId
        && ($previousRoute['activation_request_digest'] ?? null) === $requestDigest
        && ($previousRoute['version_id'] ?? null) === $versionId
        && (!$storeIntent || !_stattic_runtime_hostname_intent_changed($privateRoot, $spaceId, $intentRoutes))
    ) {
        // Repair a receipt persisted before the route-index generation was
        // published, before claiming the activation is already complete.
        _stattic_runtime_update_route_index($privateRoot, $spaceId);
        return [
            'changed_paths' => [],
            'unchanged' => true,
            'activation_event_id' => $storedActivationEventId,
        ];
    }
    if (
        $configDigest !== null
        && !array_key_exists('changed_paths', $body)
        && _stattic_runtime_route_pointer_unchanged($privateRoot, $spaceId, $routeName, $versionId, $configDigest, is_array($previousRoute) ? $previousRoute : [])
        && (!$storeIntent || !_stattic_runtime_hostname_intent_changed($privateRoot, $spaceId, $intentRoutes))
    ) {
        $routeEvent = _stattic_runtime_route_updated_event(
            $spaceId,
            $routeName,
            $versionId,
            _stattic_runtime_affected_intent_hostnames($privateRoot, $spaceId, $routeName),
            [],
            false,
            $body
        );
        return [
            'changed_paths' => [],
            'unchanged' => true,
            'activation_event_id' => $storedActivationEventId
                ?? _stattic_runtime_management_event_id($claims, $routeEvent),
        ];
    }
    $config = _stattic_runtime_route_config($body['config'] ?? null);
    _stattic_runtime_write_route(
        $privateRoot,
        $spaceId,
        $routeName,
        $versionId,
        $config,
        $configDigest
    );
    _stattic_runtime_store_unified_access_from_config($privateRoot, $spaceId, $body['config'] ?? null);
    if ($storeIntent) {
        _stattic_runtime_store_hostname_intent($privateRoot, $spaceId, $intentRoutes, $claims);
    }
    $changedPathsKnown = false;
    $changedPaths = _stattic_runtime_changed_path_list($body['changed_paths'] ?? null, $changedPathsKnown);
    // The same hostname set feeds the journal event and the purge: a path purge
    // that missed a hostname the event named would leave that host stale.
    $hostnames = _stattic_runtime_affected_intent_hostnames($privateRoot, $spaceId, $routeName);
    $routeEvent = _stattic_runtime_route_updated_event(
        $spaceId,
        $routeName,
        $versionId,
        $hostnames,
        $changedPaths,
        $changedPathsKnown,
        $body
    );
    // A config-only access change keeps the same version pointer, so version ids
    // cannot say whether cached anonymous bytes just became private — the
    // before/after exposure digests answer that. Presence of the previous field
    // means a route existed; null means treat it conservatively.
    $previousExposure = null;
    if (is_array($previousRoute)) {
        $previousConfig = is_array($previousRoute['config'] ?? null)
            ? $previousRoute['config']
            : [];
        $previousExposure = _stattic_runtime_public_exposure_descriptor($previousConfig);
        $routeEvent['previous_public_exposure_digest'] =
            _stattic_runtime_public_exposure_digest($previousConfig);
        $routeEvent['public_exposure_digest'] =
            _stattic_runtime_public_exposure_digest($config);
        $routeEvent['previous_public_exposure'] = $previousExposure;
        $routeEvent['public_exposure'] =
            _stattic_runtime_public_exposure_descriptor($config);
    }
    // The index/pointer publish happens BEFORE the journal record that announces
    // it: that record is a promise the activation is already SERVING. The
    // activation event id is the response's and the pointer's receipt — the
    // journal copy is a diagnostic, never a delivery.
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    $activationEventId = _stattic_runtime_management_event_id($claims, $routeEvent);
    _stattic_runtime_journal_management_diagnostic($privateRoot, $claims, $routeEvent);
    _stattic_runtime_store_route_activation_event_id(
        $routePath,
        $spaceId,
        $routeName,
        $versionId,
        $activationEventId,
        $operationId,
        $requestDigest
    );
    // Access flipping public→private owes EVERY hostname the space has ever
    // answered on (route intent + tombstones) a full sweep: those aliases can
    // hold formerly-public responses, including year-TTL immutable assets on
    // the version hosts a content activation deliberately skips (their bytes
    // never change, so a publish must NOT purge them). Every other write purges
    // only the route's own serving hostnames.
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $purge = _stattic_runtime_purge_now(
        $privateRoot,
        _stattic_runtime_exposure_became_private(
            $previousExposure,
            _stattic_runtime_public_exposure_descriptor($config),
        )
            ? [
                'hostnames' => _stattic_runtime_access_sweep_hostnames(
                    _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json'),
                    _stattic_runtime_read_json($spaceRoot . '/tombstones.json'),
                ),
                'reason' => 'space_access_privatized',
            ]
            : [
                'hostnames' => $hostnames,
                'paths' => $changedPathsKnown ? $changedPaths : [],
                'reason' => 'route_updated',
            ],
    );
    return [
        'changed_paths' => $changedPaths,
        'unchanged' => false,
        'activation_event_id' => $activationEventId,
        'purge' => $purge,
    ];
}

function _stattic_runtime_store_route_activation_event_id(string $routePath, string $spaceId, string $routeName, string $versionId, string $activationEventId, string $operationId, string $requestDigest): void
{
    $stored = _stattic_runtime_read_json($routePath);
    if (
        !is_array($stored)
        || ($stored['space_id'] ?? null) !== $spaceId
        || ($stored['route_name'] ?? null) !== $routeName
        || ($stored['version_id'] ?? null) !== $versionId
    ) {
        _stattic_problem_response(
            500,
            'runtime_route_pointer_missing',
            'The runtime route pointer could not retain its activation receipt.',
        );
    }
    $stored['activation_event_id'] = $activationEventId;
    $stored['activation_operation_id'] = $operationId;
    $stored['activation_request_digest'] = $requestDigest;
    _stattic_runtime_write_json_atomic($routePath, $stored);
}

function _stattic_runtime_route_activation_request_digest(array $body): string
{
    $canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($canonicalize, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = $canonicalize($entry);
        }
        return $value;
    };
    return hash(
        'sha256',
        (string) json_encode($canonicalize($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function _stattic_runtime_public_exposure_digest(array $config): ?string
{
    $digest = is_string($config['public_exposure_digest'] ?? null)
        ? strtolower($config['public_exposure_digest'])
        : '';
    return preg_match('/\A[a-f0-9]{64}\z/', $digest) === 1 ? $digest : null;
}

function _stattic_runtime_public_exposure_descriptor(array $config): ?array
{
    $descriptor = $config['public_exposure'] ?? null;
    if (
        !is_array($descriptor)
        || !is_int($descriptor['v'] ?? null)
        || $descriptor['v'] < 1
        || !is_bool($descriptor['public'] ?? null)
        || !is_string($descriptor['authorizationDigest'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/', $descriptor['authorizationDigest']) !== 1
        || !array_key_exists('contentTypes', $descriptor)
        || (
            !is_null($descriptor['contentTypes'])
            && (!is_array($descriptor['contentTypes']) || !array_is_list($descriptor['contentTypes']))
        )
        || !is_bool($descriptor['externalProxy'] ?? null)
        || !is_string($descriptor['unmodeled'] ?? null)
    ) {
        return null;
    }
    if (is_array($descriptor['contentTypes'])) {
        foreach ($descriptor['contentTypes'] as $contentType) {
            if (!is_string($contentType)) {
                return null;
            }
        }
    }
    return $descriptor;
}

function _stattic_runtime_route_pointer_unchanged(string $privateRoot, string $spaceId, string $routeName, string $versionId, string $configDigest, ?array $stored = null): bool
{
    $stored ??= _stattic_runtime_read_json(_stattic_route_pointer_path($privateRoot, $spaceId, $routeName));
    if (
        !is_array($stored)
        || ($stored['version_id'] ?? null) !== $versionId
        || ($stored['config_digest'] ?? null) !== $configDigest
    ) {
        return false;
    }
    $config = is_array($stored['config'] ?? null) ? $stored['config'] : [];
    if (!array_key_exists('authorization', $config)) {
        return true;
    }
    $authorization = $config['authorization'];
    if (!is_array($authorization)) {
        return false;
    }
    require_once __DIR__ . '/../runtime/access-rules.php';
    return _stattic_authorization_projection_compiled($authorization);
}

// Deliberately order-sensitive: a differing order costs a normal write, never a
// wrongly skipped one.
function _stattic_runtime_hostname_intent_changed(string $privateRoot, string $spaceId, array $incomingRoutes): bool
{
    $stored = _stattic_runtime_read_json(_stattic_space_root($privateRoot, $spaceId) . '/hostname-intent.json');
    $storedRoutes = is_array($stored) && is_array($stored['routes'] ?? null) ? $stored['routes'] : null;
    if ($storedRoutes === null) {
        return true;
    }
    $incoming = [];
    foreach ($incomingRoutes as $route) {
        $incoming[] = _stattic_runtime_normalize_route($route);
    }
    return $incoming !== $storedRoutes;
}

function _stattic_runtime_write_route(string $privateRoot, string $spaceId, string $routeName, string $versionId, array $config, ?string $configDigest = null): void
{
    _stattic_runtime_mkdir(_stattic_space_routes_root($privateRoot, $spaceId));
    _stattic_runtime_write_json_atomic(_stattic_route_pointer_path($privateRoot, $spaceId, $routeName), [
        'space_id' => $spaceId,
        'route_name' => $routeName,
        'version_id' => $versionId,
        'config' => $config,
        ...($configDigest !== null ? ['config_digest' => $configDigest] : []),
        'updated_at' => gmdate('c'),
    ]);
}

// Serve-time plan entitlements, read fresh on every route compile. An explicit
// null clears the doc back to fail-closed defaults; absence leaves it untouched.
function _stattic_runtime_store_entitlements(string $privateRoot, string $spaceId, mixed $raw): void
{
    $path = _stattic_space_root($privateRoot, $spaceId) . '/entitlements.json';
    if ($raw === null) {
        _stattic_runtime_rm_recursive($path);
        return;
    }
    if (!is_array($raw)) {
        _stattic_problem_response(422, 'invalid_entitlements', 'Entitlements must be an object.');
    }
    _stattic_runtime_write_json_atomic($path, [
        'space_id' => $spaceId,
        'entitlements' => [
            'externalProxy' => !empty($raw['externalProxy']),
        ],
        'updated_at' => gmdate('c'),
    ]);
}

function _stattic_runtime_put_tombstones(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $hostnames = _stattic_runtime_hostname_list($body['hostnames'] ?? []);
    $mode = isset($body['mode']) && is_string($body['mode']) && in_array($body['mode'], ['replace', 'add', 'remove'], true)
        ? $body['mode']
        : 'replace';
    // The served page differs by reason. Both are ignored on `remove`; absence
    // preserves the generic tombstone.
    $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;
    $category = isset($body['category']) && is_string($body['category']) ? $body['category'] : null;
    $tombstoneCount = _stattic_runtime_store_space_tombstones($privateRoot, $spaceId, $hostnames, $mode, $reason, $category);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_tombstones_updated',
        'space_id' => $spaceId,
        'tombstone_count' => $tombstoneCount,
        'hostnames' => $hostnames,
    ]);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'tombstone_count' => $tombstoneCount,
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'space_tombstones_updated', [], $hostnames),
    ]);
}

// The engine only STORES the policy — deletion happens on the housekeeping tick,
// which re-checks every id against live route pointers before any rm. An empty
// list clears the policy.
function _stattic_runtime_put_retention_policy(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $raw = $body['prunable_version_ids'] ?? null;
    if (!is_array($raw)) {
        _stattic_problem_response(422, 'runtime_retention_policy_invalid', 'prunable_version_ids must be an array of version ids.');
    }
    $versionIds = [];
    foreach ($raw as $versionId) {
        if (!is_string($versionId)) {
            _stattic_problem_response(422, 'runtime_retention_policy_invalid', 'prunable_version_ids must be an array of version ids.');
        }
        $versionIds[_stattic_runtime_id($versionId, 'version_id')] = true;
    }
    _stattic_runtime_write_json_atomic(_stattic_space_root($privateRoot, $spaceId) . '/retention-policy.json', [
        'space_id' => $spaceId,
        'prunable_version_ids' => array_keys($versionIds),
        'updated_at' => gmdate('c'),
    ]);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_retention_policy_updated',
        'space_id' => $spaceId,
        'prunable_count' => count($versionIds),
    ]);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'prunable_count' => count($versionIds),
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'space_retention_policy_updated'),
    ]);
}

function _stattic_runtime_delete_space(string $privateRoot, string $spaceId, array $claims): void
{
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $hostnames = _stattic_runtime_space_event_hostnames($spaceRoot);
    $tombstones = _stattic_runtime_read_json($spaceRoot . '/tombstones.json');
    _stattic_runtime_rm_recursive($spaceRoot);
    // Tombstones must survive the rm: retired hostnames keep serving the
    // tombstone page rather than degrading to the generic undeployed 503.
    if (is_array($tombstones) && is_array($tombstones['hostnames'] ?? null) && $tombstones['hostnames'] !== []) {
        _stattic_runtime_mkdir($spaceRoot);
        _stattic_runtime_write_json_atomic($spaceRoot . '/tombstones.json', $tombstones);
    }
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_deleted',
        'space_id' => $spaceId,
        'hostnames' => $hostnames,
    ]);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'status' => 'deleted',
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'space_deleted', [], $hostnames),
    ]);
}

function _stattic_runtime_delete_version(string $privateRoot, string $spaceId, string $versionId, array $claims): void
{
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    if (!is_dir($versionRoot)) {
        _stattic_problem_response(404, 'version_not_found', 'Version not found.');
    }
    _stattic_runtime_rm_recursive($versionRoot);
    // The bytes stay in the space's shared CAS until the collector runs, so
    // removing the directory alone does not stop a link minted a moment ago:
    // the pool still holds this version's catalog and gate sha map for the rest
    // of their TTL and would keep resolving them. Retire both now.
    _stattic_runtime_forget_version_caches($spaceId, $versionId);
    $hostnames = _stattic_runtime_affected_intent_hostnames($privateRoot, $spaceId, null, $versionId);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'version_deleted',
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'hostnames' => $hostnames,
        // Kept in the event because the control plane still reads it, and
        // honestly empty: remote-blob refcounts are the demote lane's to report.
        'remote_shas' => [],
    ]);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'status' => 'deleted',
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'version_deleted', [], $hostnames),
    ]);
}

function _stattic_runtime_repair_space(string $privateRoot, string $spaceId, array $claims): void
{
    if (!is_dir(_stattic_space_root($privateRoot, $spaceId))) {
        _stattic_problem_response(404, 'space_not_found', 'Space not found.');
    }
    _stattic_runtime_rebuild_route_index($privateRoot);
    _stattic_runtime_rm_recursive($privateRoot . '/runtime/repair-state.json');
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_repaired',
        'space_id' => $spaceId,
    ]);
    // A repair exists because the projection was wrong, so what the edge cached
    // while it was wrong is suspect: purge the whole domain rather than guess.
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'status' => 'repaired',
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'space_repaired'),
    ]);
}

function _stattic_runtime_route_config(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $config = [];
    if (array_key_exists('public_exposure_digest', $raw)) {
        $config['public_exposure_digest'] = _stattic_runtime_public_exposure_digest($raw);
    }
    if (array_key_exists('public_exposure', $raw)) {
        $config['public_exposure'] = _stattic_runtime_public_exposure_descriptor($raw);
    }
    if (array_key_exists('authorization', $raw)) {
        require_once __DIR__ . '/../runtime/access-rules.php';
        $authorization = _stattic_authorization_projection_compiled($raw['authorization'])
            ? $raw['authorization']
            : _stattic_compile_authorization_projection($raw['authorization']);
        if ($raw['authorization'] !== null && $authorization === null) {
            _stattic_problem_response(422, 'authorization_projection_invalid', 'Authorization projection is invalid.');
        }
        $config['authorization'] = $authorization;
    }
    if (array_key_exists('visitor_issuer', $raw)) {
        $config['visitor_issuer'] = is_string($raw['visitor_issuer'])
            && $raw['visitor_issuer'] !== ''
            ? $raw['visitor_issuer']
            : null;
    }
    if (array_key_exists('visitor_jwks', $raw)) {
        $config['visitor_jwks'] = is_array($raw['visitor_jwks'])
            ? $raw['visitor_jwks']
            : null;
    }
    if (array_key_exists('projection_generation', $raw)) {
        $config['projection_generation'] = is_int($raw['projection_generation'])
            && $raw['projection_generation'] >= 0
            ? $raw['projection_generation']
            : null;
    }
    if (array_key_exists('anonymous_expires_at', $raw)) {
        $config['anonymous_expires_at'] = is_string($raw['anonymous_expires_at']) && $raw['anonymous_expires_at'] !== ''
            ? $raw['anonymous_expires_at']
            : null;
    }
    if (array_key_exists('content_types', $raw)) {
        // Exact types or `prefix/*` wildcards; explicit null (or a malformed
        // doc) clears the list.
        $contentTypes = $raw['content_types'];
        $allowed = is_array($contentTypes) && is_array($contentTypes['allowed'] ?? null)
            ? array_values(array_filter($contentTypes['allowed'], static fn ($value): bool => is_string($value) && $value !== ''))
            : null;
        $config['content_types'] = $allowed === null ? null : array_filter([
            'allowed' => $allowed,
            'blocked_message' => is_string($contentTypes['blocked_message'] ?? null) && $contentTypes['blocked_message'] !== ''
                ? $contentTypes['blocked_message']
                : null,
        ], static fn ($value): bool => $value !== null);
    }
    if (is_array($raw['admission'] ?? null)) {
        $concurrency = $raw['admission']['concurrency'] ?? null;
        if (is_int($concurrency) || (is_string($concurrency) && preg_match('/^[0-9]+$/', $concurrency) === 1)) {
            $config['admission'] = ['concurrency' => max(1, (int) $concurrency)];
        }
    }
    if (array_key_exists('sdk', $raw)) {
        // `config` is carried verbatim (the serve path validates each field it
        // reads); only the envelope and the body ceiling are enforced here.
        $sdk = $raw['sdk'];
        $body = is_array($sdk) ? ($sdk['body'] ?? null) : null;
        $config['sdk'] = is_array($sdk)
            && is_array($sdk['config'] ?? null)
            && ($body === null || (is_string($body) && strlen($body) <= 5242880))
            ? [
                'revision' => is_string($sdk['revision'] ?? null) ? $sdk['revision'] : null,
                'config' => $sdk['config'],
                'body' => is_string($body) ? $body : null,
            ]
            : null;
    }
    return $config;
}

function _stattic_runtime_store_unified_access_from_config(string $privateRoot, string $spaceId, mixed $config): void
{
    // Canonical Grants are the sole owner-configurable access policy, so every
    // apply removes both retired runtime policy documents.
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    _stattic_runtime_rm_recursive($spaceRoot . '/policy.json');
    _stattic_runtime_rm_recursive($spaceRoot . '/policy-secrets.json');
    if (is_array($config) && array_key_exists('entitlements', $config)) {
        _stattic_runtime_store_entitlements($privateRoot, $spaceId, $config['entitlements']);
    }
}

// root.json is the LAST byte a finalize writes, which is what makes it the
// finalized marker: a version root without one is mid-finalize or never was.
function _stattic_runtime_version_finalized(string $versionRoot): bool
{
    return is_file($versionRoot . '/' . STATTIC_RUNTIME_VERSION_ROOT_POINTER_FILE);
}

// One page of the catalog. Bounded because this route answers both the
// dashboard's file browser and the abuse scanner's enumeration, and a
// hundred-thousand-file version must not decide either one's memory ceiling.
const STATTIC_RUNTIME_VERSION_FILES_PAGE_DEFAULT = 1000;
const STATTIC_RUNTIME_VERSION_FILES_PAGE_MAX = 2000;

/**
 * Replace the provider's inherited response status line for a raw source body.
 *
 * wp.cloud enters direct PHP entrypoints with its own not-found status line.
 * `http_response_code()` updates PHP's numeric code but does not replace that
 * already-staged line, so the edge can pair the right bytes with 404/problem
 * headers. Emit the complete line last, the same way WordPress `status_header`
 * does, so the response is one coherent status + header + body tuple.
 */
function _stattic_runtime_version_source_status(int $status): void
{
    $reason = match ($status) {
        200 => 'OK',
        204 => 'No Content',
        default => throw new InvalidArgumentException('Unsupported version source status.'),
    };
    $protocol = is_string($_SERVER['SERVER_PROTOCOL'] ?? null)
        ? (string) $_SERVER['SERVER_PROTOCOL']
        : '';
    if (!in_array($protocol, ['HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3'], true)) {
        $protocol = 'HTTP/1.0';
    }
    header($protocol . ' ' . $status . ' ' . $reason, true, $status);
}

function _stattic_runtime_version_source_reset_headers(): void
{
    foreach (['Content-Type', 'Content-Length', 'Cache-Control', 'Pragma', 'Expires'] as $name) {
        header_remove($name);
    }
}

/**
 * Read one bounded source object through the instance-pinned management lane.
 *
 * Finalize needs a handful of private compile inputs before the version catalog
 * exists. Those bytes must not round-trip through the public blob/download
 * surface: provider X-Accel handling is a serving concern, and a failure there
 * must not make an already accepted upload unreadable to its own finalizer.
 *
 * A fresh path is selected by upload_id + sha256. A retained path is selected
 * by path against this route's finalized version. Absence is a 204 because the
 * caller's source-reader contract treats a missing optional file as null.
 */
function _stattic_runtime_read_version_source_route(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    array $_claims
): void {
    $uploadId = _stattic_runtime_version_files_param('upload_id');
    $sha256 = _stattic_runtime_version_files_param('sha256');
    $path = _stattic_runtime_version_files_param('path');
    $rawMaxBytes = _stattic_runtime_version_files_param('max_bytes');
    if (
        $rawMaxBytes === null
        || preg_match('/\A[1-9][0-9]{0,7}\z/', $rawMaxBytes) !== 1
        || (int) $rawMaxBytes > STATTIC_RUNTIME_MANIFEST_MAX_FILE_BYTES
    ) {
        _stattic_problem_response(
            422,
            'runtime_version_source_request_invalid',
            'max_bytes must be a positive integer within the runtime file-size limit.'
        );
    }
    $maxBytes = (int) $rawMaxBytes;
    $resolved = null;
    if ($uploadId !== null || $sha256 !== null) {
        if (
            $uploadId === null
            || $sha256 === null
            || $path !== null
            || !_stattic_id_valid($uploadId)
            || preg_match('/\A[a-f0-9]{64}\z/', strtolower($sha256)) !== 1
        ) {
            _stattic_problem_response(
                422,
                'runtime_version_source_request_invalid',
                'Fresh source reads require exactly upload_id and sha256.'
            );
        }
        $session = _stattic_runtime_publish_session_load($privateRoot, $spaceId, $uploadId);
        if (!is_array($session) || ($session['version_id'] ?? null) !== $versionId) {
            _stattic_runtime_version_source_reset_headers();
            header('Cache-Control: private, no-store', true);
            _stattic_runtime_version_source_status(204);
            exit;
        }
        $resolved = _stattic_runtime_publish_session_blob(
            $privateRoot,
            $spaceId,
            $uploadId,
            strtolower($sha256)
        );
    } elseif ($path !== null) {
        $resolved = _stattic_runtime_resolve_version_file(
            $privateRoot,
            $spaceId,
            $versionId,
            $path,
            'source'
        );
    } else {
        _stattic_problem_response(
            422,
            'runtime_version_source_request_invalid',
            'Source reads require upload_id + sha256 or path.'
        );
    }
    if (!is_array($resolved)) {
        _stattic_runtime_version_source_reset_headers();
        header('Cache-Control: private, no-store', true);
        _stattic_runtime_version_source_status(204);
        exit;
    }
    $blobPath = _stattic_runtime_blob_path($privateRoot, $spaceId, (string) $resolved['sha']);
    $size = @filesize($blobPath);
    if (!is_file($blobPath) || !is_int($size)) {
        _stattic_problem_response(
            503,
            'runtime_version_source_unavailable',
            'Version source bytes are unavailable on this runtime instance.'
        );
    }
    if ($size > $maxBytes) {
        _stattic_runtime_version_source_reset_headers();
        header('Cache-Control: private, no-store', true);
        _stattic_runtime_version_source_status(204);
        exit;
    }
    $stream = @fopen($blobPath, 'rb');
    if ($stream === false) {
        _stattic_problem_response(
            503,
            'runtime_version_source_unavailable',
            'Version source bytes are unavailable on this runtime instance.'
        );
    }
    _stattic_runtime_version_source_reset_headers();
    header('Content-Type: ' . (string) ($resolved['mime'] ?? 'application/octet-stream'), true);
    header('Content-Length: ' . $size, true);
    header('Cache-Control: private, no-store', true);
    header('X-Content-Type-Options: nosniff', true);
    _stattic_runtime_version_source_status(200);
    _stattic_stream_file($stream, $size);
    fclose($stream);
    exit;
}

function _stattic_runtime_version_files_request_invalid(string $message): never
{
    _stattic_problem_response(422, 'runtime_version_files_request_invalid', $message);
}

/**
 * THE version file list: one bounded catalog page, both views, for every reader
 * that used to reconstruct a file list from its own projection.
 *
 * `view=source` is the uploaded, pre-substitution object — what the file browser
 * shows. `view=served` is the immutable byte a visitor receives, which is what
 * the scanner enumerates; private compile inputs have no served object and drop
 * out of that view entirely. `channel` adds a template-variant route's own bytes
 * as extra rows carrying `variant_route`, so the scanner still sees the exact
 * bytes that channel can serve — it is a served-view dimension and nothing else.
 *
 * `public` comes from the catalog, the single visibility implementation.
 *
 * `path` narrows the page to one exact entry, so a per-file lookup costs one
 * bounded round trip instead of a full drain. A path the view does not hold is
 * an EMPTY page, not a 404: absence is the answer the caller asked for.
 */
function _stattic_runtime_list_version_files_route(string $privateRoot, string $spaceId, string $versionId): void
{
    $view = _stattic_runtime_version_files_param('view') ?? 'source';
    if (!in_array($view, STATTIC_RUNTIME_VERSION_FILE_VIEWS, true)) {
        _stattic_runtime_version_files_request_invalid('view must be "source" or "served".');
    }
    $channel = _stattic_runtime_version_files_param('channel');
    if ($channel !== null) {
        if ($view !== 'served') {
            _stattic_runtime_version_files_request_invalid('channel selects template-variant bytes and requires view=served.');
        }
        $channel = _stattic_runtime_id($channel, 'channel');
    }
    $exactPath = _stattic_runtime_version_files_param('path');
    if ($exactPath !== null) {
        $exactPath = _stattic_runtime_file_path_or_null($exactPath);
        if ($exactPath === null) {
            _stattic_runtime_version_files_request_invalid('path is not a canonical version path.');
        }
    }
    $publicOnly = _stattic_runtime_version_files_flag('public_only');
    $prefix = _stattic_runtime_version_files_param('prefix') ?? '';
    $query = _stattic_runtime_version_files_param('q') ?? '';
    $limit = STATTIC_RUNTIME_VERSION_FILES_PAGE_DEFAULT;
    $rawLimit = _stattic_runtime_version_files_param('limit');
    if ($rawLimit !== null) {
        if (preg_match('/\A[1-9][0-9]{0,5}\z/', $rawLimit) !== 1) {
            _stattic_runtime_version_files_request_invalid('limit must be a positive integer.');
        }
        $limit = min(STATTIC_RUNTIME_VERSION_FILES_PAGE_MAX, (int) $rawLimit);
    }
    $after = null;
    $rawCursor = _stattic_runtime_version_files_param('cursor');
    if ($rawCursor !== null) {
        $after = _stattic_runtime_version_files_cursor_decode($rawCursor);
        if ($after === null) {
            _stattic_runtime_version_files_request_invalid('cursor is invalid.');
        }
    }

    if (!_stattic_runtime_version_finalized(_stattic_version_root($privateRoot, $spaceId, $versionId))) {
        _stattic_problem_response(404, 'version_not_found', 'Version not found.');
    }
    $catalog = _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
    if ($catalog === null) {
        // A committed version with no readable catalog is a repair job, not a
        // degraded read: there is no second projection to reconstruct it from.
        _stattic_problem_response(
            409,
            'runtime_file_catalog_invalid',
            'This version has no readable file catalog.',
        );
    }

    $rows = [];
    foreach ($catalog['paths'] as $path => $entry) {
        if (!is_string($path) || $path === '' || !is_array($entry)) {
            continue;
        }
        $public = ($entry['public'] ?? false) === true;
        if ($publicOnly && !$public) {
            continue;
        }
        $object = _stattic_runtime_catalog_object($entry[$view] ?? null);
        if ($object === null) {
            continue;
        }
        $rows[] = _stattic_runtime_version_files_row($path, $object, $public, null);
    }
    if ($channel !== null) {
        $variant = is_array($catalog['variants'][$channel] ?? null) ? $catalog['variants'][$channel] : [];
        foreach ($variant as $path => $object) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            // A variant substitutes bytes for a path the base already carries,
            // so the base entry owns the visibility bit.
            $base = is_array($catalog['paths'][$path] ?? null) ? $catalog['paths'][$path] : [];
            $public = ($base['public'] ?? false) === true;
            if ($publicOnly && !$public) {
                continue;
            }
            $normalized = _stattic_runtime_catalog_object($object);
            if ($normalized === null) {
                continue;
            }
            $rows[] = _stattic_runtime_version_files_row($path, $normalized, $public, $channel);
        }
    }

    $rows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => _stattic_runtime_version_files_matches($row['path'], $exactPath, $prefix, $query)
    ));
    usort($rows, static fn (array $left, array $right): int => strcmp(
        _stattic_runtime_version_files_sort_key($left),
        _stattic_runtime_version_files_sort_key($right)
    ));
    if ($after !== null) {
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => strcmp(_stattic_runtime_version_files_sort_key($row), $after) > 0
        ));
    }
    $page = array_slice($rows, 0, $limit);
    $nextCursor = count($rows) > $limit && $page !== []
        ? _stattic_runtime_version_files_cursor_encode(
            _stattic_runtime_version_files_sort_key($page[count($page) - 1])
        )
        : null;

    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'view' => $view,
        'files' => $page,
        'next_cursor' => $nextCursor,
    ]);
}

/** @return array{path: string, size: int, sha256: string, content_type: string, public: bool, variant_route?: string} */
function _stattic_runtime_version_files_row(string $path, array $object, bool $public, ?string $variantRoute): array
{
    return [
        'path' => $path,
        'size' => $object['size'],
        'sha256' => $object['sha'],
        'content_type' => $object['mime'],
        'public' => $public,
        ...($variantRoute !== null ? ['variant_route' => $variantRoute] : []),
    ];
}

// Base rows before their variant rows, then by path — the same order the cursor
// walks, so a page boundary never re-delivers or skips a row.
function _stattic_runtime_version_files_sort_key(array $row): string
{
    return (string) ($row['variant_route'] ?? '') . "\0" . (string) $row['path'];
}

function _stattic_runtime_version_files_matches(string $path, ?string $exactPath, string $prefix, string $query): bool
{
    if ($exactPath !== null && $path !== $exactPath) {
        return false;
    }
    if ($prefix !== '' && !str_starts_with($path, $prefix)) {
        return false;
    }
    return $query === '' || stripos($path, $query) !== false;
}

// A query parameter as a non-empty string, or null when absent. A repeated or
// array-shaped parameter is a caller bug and says so rather than being coerced
// into a value the caller never sent.
function _stattic_runtime_version_files_param(string $name): ?string
{
    $raw = $_GET[$name] ?? null;
    if ($raw === null) {
        return null;
    }
    if (!is_string($raw)) {
        _stattic_runtime_version_files_request_invalid($name . ' must be a single value.');
    }
    $raw = trim($raw);
    return $raw === '' ? null : $raw;
}

function _stattic_runtime_version_files_flag(string $name): bool
{
    $raw = _stattic_runtime_version_files_param($name);
    if ($raw === null) {
        return false;
    }
    if (!in_array($raw, ['1', '0', 'true', 'false'], true)) {
        _stattic_runtime_version_files_request_invalid($name . ' must be one of "1", "0", "true", "false".');
    }
    return $raw === '1' || $raw === 'true';
}

// Opaque to the caller and self-describing to us: the sort key of the last row
// already delivered.
function _stattic_runtime_version_files_cursor_encode(string $sortKey): string
{
    return _stattic_base64url_encode($sortKey);
}

function _stattic_runtime_version_files_cursor_decode(string $cursor): ?string
{
    $decoded = _stattic_base64url_decode($cursor);
    return $decoded !== '' ? $decoded : null;
}

function _stattic_runtime_purge_space_route(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $paths = $body['urls'] ?? null;
    if ($paths !== null && (!is_array($paths) || !array_is_list($paths))) {
        _stattic_problem_response(422, 'runtime_purge_request_invalid', 'urls must be a list of request paths.');
    }
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'control_plane_purge', $paths ?? []),
    ]);
}
