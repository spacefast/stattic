<?php
declare(strict_types=1);

require_once __DIR__ . '/legacy-zero.php';

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/canonical-json.php';
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/pointers.php';
require_once __DIR__ . '/../shared/purge.php';
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/safety.php';
require_once __DIR__ . '/../shared/server-file.php';
require_once __DIR__ . '/../shared/application-journal.php';
require_once __DIR__ . '/../shared/security-telemetry.php';
require_once __DIR__ . '/upload-policy.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/generate.php';
require_once __DIR__ . '/finalize-rust.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/cas.php';
require_once __DIR__ . '/retention.php';
require_once __DIR__ . '/zero-db.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/build-source.php';
require_once __DIR__ . '/static-zip.php';
require_once __DIR__ . '/engine-update.php';
require_once __DIR__ . '/components.php';

function _stattic_runtime_with_write_lock(string $privateRoot, callable $callback): void
{
    _stattic_runtime_acquire_write_lock($privateRoot . '/runtime/write.lock', $callback);
}

function _stattic_runtime_application_journal_drain(string $privateRoot, array $claims): void
{
    unset($privateRoot, $claims);
    $body = _stattic_json_body();
    $sink = is_string($body['sink'] ?? null) ? $body['sink'] : '';
    $limit = is_int($body['limit'] ?? null) ? $body['limit'] : STATTIC_APPLICATION_JOURNAL_MAX_PAGE;
    $leaseSeconds = is_int($body['lease_seconds'] ?? null) ? $body['lease_seconds'] : 60;
    if (
        preg_match('/^[A-Za-z0-9:_-]{1,160}$/', $sink) !== 1
        || $limit < 1 || $limit > STATTIC_APPLICATION_JOURNAL_MAX_PAGE
        || $leaseSeconds < 5 || $leaseSeconds > 900
    ) {
        _stattic_problem_response(422, 'application_journal_drain_invalid', 'Application journal drain request is invalid.');
    }
    _stattic_db_broker_bind_provider();
    $connection = _stattic_db_broker_connection();
    if (!$connection instanceof mysqli) {
        _stattic_problem_response(503, 'application_journal_unavailable', 'Application journal storage is unavailable.', [
            'details' => ['stage' => 'connect', 'runtimeCode' => $connection['code']],
        ]);
    }
    try {
        $claims = _stattic_application_journal_claim($connection, $sink, $limit, $leaseSeconds);
    } catch (Throwable $error) {
        error_log('spacefast application journal claim failed type=' . get_debug_type($error) . ' code=' . (int) $error->getCode());
        _stattic_problem_response(503, 'application_journal_unavailable', 'Application journal storage is unavailable.', [
            'details' => ['stage' => 'claim', 'driverCode' => (int) $error->getCode()],
        ]);
    }
    _stattic_json_response(200, ['claims' => $claims]);
}

function _stattic_runtime_application_journal_complete(string $privateRoot, array $claims): void
{
    unset($privateRoot, $claims);
    $body = _stattic_json_body();
    $receipts = $body['receipts'] ?? null;
    if (!is_array($receipts) || !array_is_list($receipts) || count($receipts) > STATTIC_APPLICATION_JOURNAL_MAX_PAGE) {
        _stattic_problem_response(422, 'application_journal_receipts_invalid', 'Application journal receipts are invalid.');
    }
    _stattic_db_broker_bind_provider();
    $connection = _stattic_db_broker_connection();
    if (!$connection instanceof mysqli) {
        _stattic_problem_response(503, 'application_journal_unavailable', 'Application journal storage is unavailable.', [
            'details' => ['stage' => 'connect', 'runtimeCode' => $connection['code']],
        ]);
    }
    $recorded = 0;
    $stale = 0;
    foreach ($receipts as $receipt) {
        if (!is_array($receipt)) {
            $stale += 1;
            continue;
        }
        if (_stattic_application_journal_complete($connection, $receipt)) {
            $recorded += 1;
        } else {
            $stale += 1;
        }
    }
    _stattic_json_response(200, ['recorded' => $recorded, 'stale' => $stale]);
}

// A route earns this lock ONLY when every write it performs transitively stays
// inside spaces/{spaceId}/ (see the route table in admin/api.php). The lock
// file lives outside the space tree so delete_space can hold it while
// unlinking the tree.
function _stattic_runtime_with_space_write_lock(string $privateRoot, string $spaceId, callable $callback): void
{
    _stattic_space_write_lock_with(
        $privateRoot,
        $spaceId,
        STATTIC_LOCK_WAIT,
        _stattic_runtime_write_lock_unavailable(...),
        $callback,
    );
}

// The route index is the one site-shared artifact per-space mutations write, so
// it takes this always-innermost lock (acquired inside
// _stattic_runtime_update_route_index / _rebuild_route_index). Lock ordering is
// strictly site -> space -> index; every acquire path must follow it.
function _stattic_runtime_with_route_index_lock(string $privateRoot, callable $callback): void
{
    _stattic_runtime_acquire_write_lock($privateRoot . '/routes/index.lock', $callback);
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
    _stattic_runtime_mkdir(dirname($lockPath));
    _stattic_lock_with($lockPath, STATTIC_LOCK_WAIT, _stattic_runtime_write_lock_unavailable(...), $callback);
}

/**
 * The open session declaring a version, by its pointer in the session index.
 * A pointer at a session that was released, swept or expired since is stale;
 * $repair drops it, and only a caller holding the space write lock may ask
 * for that (a lock-free reader could otherwise drop the pointer a concurrent
 * create just wrote). Sessions that predate the index are indexed by one walk
 * of the store, one record at a time, after which the marker says a version
 * without a pointer has no open session. The marker only answers once the
 * pointer path settled: a pointer or session that could not be read is not
 * proof the version has no session, so that lookup walks instead. A walk that
 * could not read every record indexes what it read and leaves the marker for a
 * later walk; a record that reads but does not decode is skipped, since no
 * session can be loaded from it either.
 */
function _stattic_runtime_upload_session_for_version(string $privateRoot, string $spaceId, string $versionId, bool $repair = false): ?array
{
    $sessions = _stattic_runtime_publish_sessions_store($privateRoot, $spaceId);
    $index = _stattic_runtime_publish_session_index_store($privateRoot, $spaceId);
    $pointerRead = _stattic_runtime_publish_session_index_read_settled($index, $versionId);
    $settled = $pointerRead['state'] !== 'unavailable';
    $pointer = $pointerRead['record'];
    if (is_array($pointer)) {
        $uploadId = is_string($pointer['upload_id'] ?? null) && _stattic_runtime_id_valid($pointer['upload_id'])
            ? $pointer['upload_id']
            : null;
        $sessionRead = $uploadId === null
            ? ['state' => 'absent', 'record' => null]
            : _stattic_runtime_publish_session_index_read_settled($sessions, $uploadId);
        $session = $sessionRead['record'];
        if (is_array($session) && _stattic_runtime_upload_session_declares($session, $spaceId, $versionId)) {
            return ['upload_id' => $uploadId, 'session' => $session];
        }
        if ($sessionRead['state'] === 'unavailable') {
            // An unreadable session is not a stale pointer: keep it.
            $settled = false;
        } elseif ($repair) {
            _stattic_record_store_delete($index, $versionId);
        }
    }
    $marker = _stattic_runtime_publish_session_index_marker($privateRoot, $spaceId);
    clearstatcache(true, $marker);
    if ($settled && is_file($marker)) {
        return null;
    }
    $found = null;
    $complete = true;
    $ids = _stattic_record_store_ids($sessions);
    foreach ($ids as $candidateId) {
        // Absence must be proven: a record this walk could not read is not one
        // the marker may declare gone. A record it read but could not decode is
        // different: nothing can load that session either, and it stays on disk
        // under evidence retention, so it must not hold the marker back forever.
        $read = _stattic_runtime_publish_session_index_read_settled($sessions, $candidateId);
        if ($read['state'] === 'unavailable') {
            $complete = false;
            continue;
        }
        $candidate = $read['record'];
        if (!is_array($candidate) || ($candidate['space_id'] ?? null) !== $spaceId || _stattic_runtime_publish_session_expired($candidate)) {
            continue;
        }
        $candidateVersion = $candidate['version_id'] ?? null;
        if (!is_string($candidateVersion) || !_stattic_runtime_id_valid($candidateVersion)) {
            continue;
        }
        if (_stattic_record_store_get($index, $candidateVersion) === null) {
            _stattic_runtime_publish_session_index_put($privateRoot, $spaceId, $candidateId, $candidate);
        }
        if ($found === null && $candidateVersion === $versionId) {
            $found = ['upload_id' => $candidateId, 'session' => $candidate];
        }
    }
    if ($complete) {
        _stattic_record_store_ensure($index);
        _stattic_runtime_write_private_string($marker, gmdate('c') . "\n");
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'publish_session_index_built',
        'space_id' => $spaceId,
        'sessions' => count($ids),
        'complete' => $complete,
    ]);
    return $found;
}

/**
 * A record read that tells "could not read" apart from "not there". A read can
 * fail once and succeed next, so a failed read is retried: what the retry
 * yields is the record. Bytes that still do not decode are damage, and read as
 * `damaged` with no record, since nothing can load them either. Only a read
 * that produced no bytes at all is `unavailable`.
 *
 * @return array{state: string, record: ?array}
 */
function _stattic_runtime_publish_session_index_read_settled(array $store, string $id): array
{
    $read = _stattic_record_store_read($store, $id);
    if ($read['state'] !== 'unavailable') {
        return $read;
    }
    $raw = _sf_read_racing(_stattic_record_store_path($store, $id));
    if (!is_string($raw)) {
        return ['state' => 'unavailable', 'record' => null];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded)
        ? ['state' => 'present', 'record' => $decoded]
        : ['state' => 'damaged', 'record' => null];
}

function _stattic_runtime_upload_session_declares(array $session, string $spaceId, string $versionId): bool
{
    return ($session['space_id'] ?? null) === $spaceId
        && ($session['version_id'] ?? null) === $versionId
        && !_stattic_runtime_publish_session_expired($session);
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
    $retainedFiles = _stattic_runtime_manifest_files($body['retained_files'] ?? [], true);
    $reusableVersionId = isset($body['reusable_version_id']) && is_string($body['reusable_version_id'])
        ? _stattic_runtime_id($body['reusable_version_id'], 'reusable_version_id')
        : null;
    // After the base is known: the ceiling's nongrowth escape is about what the
    // reusable version materializes, not about this declaration alone.
    _stattic_runtime_assert_manifest_total_bytes($files, $privateRoot, $spaceId, $reusableVersionId);
    $retention = _stattic_runtime_retention_mode($body['retention'] ?? null, $reusableVersionId, $retainedFiles);
    $uploadId = _stattic_runtime_new_id('upl');
    $createdAt = gmdate('c');
    $requestedExpiresAt = _stattic_runtime_publish_session_expiry($body['expires_at'] ?? null);
    $expiresAt = $requestedExpiresAt ?? gmdate('c', time() + STATTIC_RUNTIME_UPLOAD_SESSION_DEFAULT_TTL_SECONDS);
    $manifestHash = isset($body['manifest_hash']) && is_string($body['manifest_hash']) ? $body['manifest_hash'] : null;
    $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
    $requestDigest = _stattic_runtime_canonical_request_digest([
        'version_id' => $versionId,
        'files' => $files,
        'retained_files' => $retainedFiles,
        'reusable_version_id' => $reusableVersionId,
        'retention' => $retention,
        'manifest_hash' => $manifestHash,
        'expires_at' => $requestedExpiresAt,
        'metadata' => $metadata,
    ]);
    $existing = _stattic_runtime_upload_session_for_version($privateRoot, $spaceId, $versionId, true);
    if ($existing !== null) {
        $session = $existing['session'];
        if (($session['create_request_digest'] ?? null) !== $requestDigest) {
            _stattic_problem_response(
                409,
                'version_create_conflict',
                'Version upload session already exists with a different create request.',
            );
        }
        _stattic_json_response(200, [
            'space_id' => $spaceId,
            'version_id' => $versionId,
            'upload_id' => $existing['upload_id'],
            'created_at' => (string) ($session['created_at'] ?? ''),
        ]);
    }
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
        'metadata' => $metadata,
        'create_request_digest' => $requestDigest,
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
        ['details' => ['create_replay_safe' => true]],
    );
    exit;
}

function _stattic_runtime_get_upload_session(string $privateRoot, string $spaceId, string $versionId): void
{
    // A lock='none' read: expired records are skipped, never released, because
    // releasing one is a write under the per-space lock finalize also takes.
    $found = _stattic_runtime_upload_session_for_version($privateRoot, $spaceId, $versionId);
    if ($found === null) {
        _stattic_runtime_upload_session_not_found();
    }
    $session = $found['session'];
    $uploadId = $found['upload_id'];
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
                $session['retained_files'] = _stattic_runtime_manifest_files($descriptor['retained_files'], true);
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
    require_once __DIR__ . '/../shared/zero-v2.php';
    $functionsFinalize = is_array($body['functions'] ?? null) ? $body['functions'] : null;
    if ($functionsFinalize !== null) _stattic_runtime_apply_d1_migrations($functionsFinalize, $spaceId);
    $zeroLock = _stattic_zero_v2_finalize_lock($privateRoot, $spaceId, $session);
    try {
        $finalized = _stattic_runtime_finalize_with_rust($privateRoot, $spaceId, $versionId, $uploadId, $session, $body);
        try {
            _stattic_zero_v2_write_variables($privateRoot, $spaceId, $versionId, $body['zero_v2']['variableValues'] ?? []);
            require_once __DIR__ . '/../runtime/zero-v2-realtime.php';
            _stattic_zero_v2_write_realtime($privateRoot, $spaceId, $versionId, $body['zero_v2']['realtime'] ?? null);
            _stattic_zero_v2_initialize($privateRoot, $spaceId, $versionId, $body);
        } catch (Throwable $error) {
            _stattic_problem_response(422, 'zero_v2_initialize_failed', 'Zero could not initialize against this box WordPress platform.', ['details' => ['reason' => $error->getMessage()]]);
        }
    } finally {
        if (is_resource($zeroLock)) { flock($zeroLock, LOCK_UN); fclose($zeroLock); }
    }
    _stattic_runtime_publish_session_release($privateRoot, $spaceId, $uploadId);
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    _stattic_legacy_zero_write_version_config($versionRoot, $body);
    if ($functionsFinalize !== null) {
        _stattic_runtime_write_functions_config_artifact($versionRoot, $functionsFinalize);
    }
    // A control plane from before the shared credential sends it inside the
    // Zero or Functions config instead; both name the same one credential.
    $connectorsToken = $body['connectors_token']
        ?? $body['zero']['connectors']['token']
        ?? $body['functions']['connectors']['token']
        ?? null;
    if (is_string($connectorsToken) && $connectorsToken !== '') {
        _stattic_runtime_write_private_string($versionRoot . '/' . STATTIC_CONNECTORS_TOKEN_PATH, $connectorsToken);
    }
    // Every version's no-build client has types, with or without roles.
    $connectorsTypes = $body['connectors_types'] ?? null;
    if (is_string($connectorsTypes) && $connectorsTypes !== '') {
        _stattic_runtime_write_private_string($versionRoot . '/' . STATTIC_SPACE_CLIENT_TYPES_PATH, $connectorsTypes);
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

function _stattic_runtime_activation_route_name(array $activation): string
{
    return isset($activation['route_name']) && is_string($activation['route_name'])
        ? _stattic_runtime_id($activation['route_name'], 'route_name')
        : 'production';
}

/**
 * The finalize receipt. `manifest` comes from the version's catalog on the first
 * answer and every replay, so a retry returns byte-identical files and the
 * control plane holds ONE file identity, the runtime's.
 */
function _stattic_runtime_finalize_ready_response(string $privateRoot, string $spaceId, string $versionId, string $versionRoot, ?string $activationEventId = null, ?array $metadata = null, ?array $purge = null, ?array $telemetry = null): never
{
    require_once __DIR__ . '/../shared/zero-v2.php';
    $metadata ??= _stattic_runtime_read_json($versionRoot . '/metadata.json');
    if (is_file($versionRoot . '/.zero-compiled/manifest.json') && !is_file($versionRoot . '/.zero-compiled/ready.json')) {
        _stattic_problem_response(409, 'zero_v2_not_initialized', 'The Zero version has not initialized.');
    }
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
    // Read from immutable metadata, not the finalizer's return envelope: a
    // replayed finalize never re-runs the finalizer and still has to answer
    // identically. The control plane stores these verbatim.
    $digests = is_array($metadata['catalogDigests'] ?? null) ? $metadata['catalogDigests'] : null;
    $delta = is_array($metadata['catalogDelta'] ?? null) ? $metadata['catalogDelta'] : null;
    $previewImagePath = is_string($metadata['previewImagePath'] ?? null) && $metadata['previewImagePath'] !== ''
        ? $metadata['previewImagePath']
        : null;
    $routeInventory = _stattic_runtime_finalize_route_inventory($metadata);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'status' => 'ready',
        'zero_endpoint_count' => _stattic_runtime_zero_endpoint_count($versionRoot),
        ...(_stattic_zero_v2_seed_receipt($versionRoot) ?? []),
        ...(is_array($metadata['zeroV2']['summary'] ?? null) ? ['zero_v2_app' => $metadata['zeroV2']['summary']] : []),
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
        ...($routeInventory !== null ? ['route_inventory' => $routeInventory] : []),
        // Stage timings and counts for the run that just happened.
        ...($telemetry !== null ? ['telemetry' => $telemetry] : []),
        ...($activationEventId !== null ? ['activation_event_id' => $activationEventId] : []),
        ...($purge !== null ? ['purge' => $purge] : []),
    ]);
}

function _stattic_runtime_finalize_route_inventory(?array $metadata): ?array
{
    $inventory = $metadata['routeInventory'] ?? null;
    if (
        !is_array($inventory)
        || ($inventory['format'] ?? null) !== 'stattic.route-inventory.v1'
        || !is_array($inventory['routes'] ?? null)
        || !array_is_list($inventory['routes'])
    ) {
        return null;
    }
    return $inventory;
}

function _stattic_runtime_metadata_list(?array $metadata, string $key): array
{
    $value = $metadata[$key] ?? null;
    return is_array($value) && array_is_list($value) ? $value : [];
}

/**
 * The version's compiled rule counts and its proxy rules. The counts are the
 * control plane's version-row projection; the proxy rules are what it overlays
 * the publishing team's plan onto. The artifact stays plan-agnostic: a report
 * of what compiled, never a verdict on it.
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
    $response = [
        'redirect_rule_count' => (int) ($routing['redirectRuleCount'] ?? 0),
        'header_rule_count' => (int) ($routing['headerRuleCount'] ?? 0),
        'proxy_rule_count' => (int) ($routing['proxyRuleCount'] ?? 0),
        'proxy_rules' => $proxyRules,
    ];
    // Placement's own output, carried through untouched: the provider rules the
    // control plane reconciles onto the edge, and every rule's verdict. The
    // finalizer decides both and this engine reads neither, so re-deriving a
    // shape for them here would only be a second opinion nobody asked for. Both
    // keys are absent from the artifact whenever placement is off.
    foreach (['edgeRules' => 'edge_rules', 'placement' => 'placement'] as $key => $field) {
        $value = _stattic_runtime_metadata_list($routing, $key);
        if ($value !== []) {
            $response[$field] = $value;
        }
    }
    return $response;
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

    // A retried finalize+activate must still sync the route pointer: the
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

// The whole-domain purge every space mutation owes the edge. `$hostnames` is
// supplied when the caller already narrowed the set (a tombstone push, an
// intent diff); otherwise it is the space's full event hostname set.
function _stattic_runtime_purge_space_now(string $privateRoot, string $spaceId, string $reason, ?array $hostnames = null): array
{
    return _stattic_runtime_purge_now($privateRoot, [
        'hostnames' => $hostnames
            ?? _stattic_runtime_space_sweep_hostnames(_stattic_space_root($privateRoot, $spaceId)),
        'reason' => $reason,
    ]);
}

function _stattic_runtime_state_summary(string $privateRoot): array
{
    // The reconcile handshake compares generations as opaque strings, so the
    // pointer's int gen is stringified rather than widening that contract.
    $currentRead = _sf_pointer_read('routes', $privateRoot . '/routes/current.json');
    if ($currentRead['kind'] === 'unavailable') {
        throw new RuntimeException('runtime state pointer unavailable');
    }
    $current = $currentRead['value'];
    if ($currentRead['kind'] === 'present' && (!is_array($current) || !is_int($current['gen'] ?? null))) {
        throw new RuntimeException('runtime state pointer invalid');
    }
    $generation = is_array($current) && is_int($current['gen'] ?? null)
        ? (string) $current['gen']
        : null;
    $spaces = [];
    foreach (_stattic_runtime_space_roots_strict($privateRoot) as $spaceRoot) {
        $routes = [];
        foreach (_stattic_runtime_directory_entries_strict($spaceRoot . '/routes') as $pointerPath) {
            if (!is_file($pointerPath) || !str_ends_with($pointerPath, '.json')) {
                continue;
            }
            $pointer = _stattic_runtime_read_json_strict($pointerPath);
            if (!is_array($pointer) || !is_string($pointer['route_name'] ?? null) || !is_string($pointer['version_id'] ?? null)) {
                throw new RuntimeException('runtime state route pointer invalid: ' . $pointerPath);
            }
            $routes[$pointer['route_name']] = $pointer['version_id'];
        }
        $intent = _stattic_runtime_space_routing_doc($spaceRoot, 'intent') ?? [];
        $tombstones = _stattic_runtime_space_routing_doc($spaceRoot, 'tombstones') ?? [];
        $spaces[] = [
            'space_id' => basename($spaceRoot),
            'zero_worker' => _stattic_runtime_read_json($spaceRoot . '/zero-v2-worker-status.json'),
            'routes' => (object) $routes,
            'tombstone_count' => count($tombstones['hostnames'] ?? []),
            'intent_hostnames' => _stattic_runtime_route_intent_hostnames($spaceRoot, $intent),
            'hostnames' => _stattic_runtime_access_sweep_hostnames($intent, $tombstones),
        ];
    }
    return ['routes_generation' => $generation, 'spaces' => $spaces];
}

function _stattic_runtime_state_route(string $privateRoot): void
{
    try {
        $summary = _stattic_runtime_state_summary($privateRoot);
    } catch (Throwable $error) {
        error_log('spacefast runtime state unavailable message=' . $error->getMessage());
        _stattic_problem_response(503, 'runtime_management_unavailable', 'Runtime state is unavailable.');
    }
    _stattic_json_response(200, [
        'ok' => true,
        'runtime' => 'stattic-php',
        'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
        'engine_source' => _stattic_engine_serves_from_chroot() ? 'chroot' : 'site',
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
        // basename(): the stored name is data that survived a round trip, so it
        // must never point the reader outside runtime/.
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

// Cursors order by generation age then byte offset, the journal's own read
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
 * The page cursor is the last record's; one record past the page answers
 * whether there is more without a second read.
 *
 * @return array{records: list<array{entry: array, cursor: array}>, cursor: array, more: bool}
 */
function _stattic_runtime_journal_page(string $privateRoot, array $cursor, int $max): array
{
    $records = _stattic_runtime_journal_read($privateRoot, $cursor, $max + 1);
    $more = count($records) > $max;
    $records = array_slice($records, 0, $max);
    $last = end($records);
    return [
        'records' => $records,
        'cursor' => $last === false ? $cursor : $last['cursor'],
        'more' => $more,
    ];
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
    if (($body['security_telemetry'] ?? null) !== true) {
        _stattic_problem_response(409, 'runtime_event_reader_incompatible', 'The journal reader must support security telemetry.');
    }

    _stattic_security_flush_drops($privateRoot);
    $page = _stattic_runtime_journal_page($privateRoot, $cursor, STATTIC_RUNTIME_EVENT_DRAIN_MAX);
    $events = [];
    foreach ($page['records'] as $record) {
        $entry = $record['entry'];
        // Only management events carry an event_id. Everything else is operator
        // diagnostics (job lifecycle, GC, purge receipts): skipped, but the
        // cursor still advances past it.
        if (!is_array($entry) || !is_string($entry['event_id'] ?? null) || $entry['event_id'] === '') {
            continue;
        }
        if ($targetEventId !== null && $entry['event_id'] !== $targetEventId) {
            continue;
        }
        $events[] = _stattic_runtime_drained_event($entry, $record['cursor']);
    }

    // The control plane only compares pending_count against zero, so report
    // "is there more" rather than counting.
    _stattic_runtime_drain_response($privateRoot, $page['cursor'], $events, $page['more'] ? 1 : 0);
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
    if (($body['security_telemetry'] ?? null) !== true) {
        _stattic_problem_response(409, 'runtime_event_reader_incompatible', 'The journal reader must support security telemetry.');
    }
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

// Security: this artifact names the execution host, granted capabilities, and
// the worker's broker credential, so it lives BESIDE the version's file tree. A
// publish reaches `files/` and nothing else, so no upload can forge it. Fields
// are copied one by one so an unrecognised key cannot land in it either.
function _stattic_runtime_write_functions_config_artifact(string $versionRoot, array $functions): void
{
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

function _stattic_runtime_put_route(string $privateRoot, string $spaceId, string $routeName, array $claims): void
{
    $body = _stattic_json_body();
    $versionId = isset($body['version_id']) && is_string($body['version_id'])
        ? _stattic_runtime_id($body['version_id'], 'version_id')
        : '';
    if ($versionId === '' || !_stattic_runtime_version_finalized(_stattic_version_root($privateRoot, $spaceId, $versionId))) {
        _stattic_problem_response(404, 'version_not_found', 'Version not found.');
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

function _stattic_runtime_delete_route(string $privateRoot, string $spaceId, string $routeName, array $claims): void
{
    // Intent survives deletion, so retries retain the same purge set even if
    // the pointer was removed before an interrupted index publication.
    $hostnames = _stattic_runtime_affected_intent_hostnames($privateRoot, $spaceId, $routeName);
    _stattic_runtime_prepare_purge($privateRoot, $spaceId, $hostnames, 'route_deleted');
    $routePath = _stattic_route_pointer_path($privateRoot, $spaceId, $routeName);
    _stattic_runtime_assert_private_path($routePath);
    if ((file_exists($routePath) || is_link($routePath)) && !unlink($routePath)) {
        throw new RuntimeException('runtime route deletion failed: ' . $routePath);
    }
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'route_name' => $routeName,
        'status' => 'deleted',
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'route_deleted', $hostnames),
    ]);
}

function _stattic_runtime_put_hostname_intent(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $routes = _stattic_runtime_routes_from_hostname_intent('production', $body);
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $previous = _stattic_runtime_space_routing_doc($spaceRoot, 'intent');
    $repairHostnames = null;
    // A crashed write may have persisted intent without publishing its index.
    // Repair that projection before replacing the document used by the diff.
    if ($previous !== null) {
        _stattic_runtime_update_route_index($privateRoot, $spaceId, static function (array $changedHostnames) use ($privateRoot, &$repairHostnames): void {
            $changedHostnames = array_values(array_diff($changedHostnames, $repairHostnames ?? []));
            if ($changedHostnames === []) {
                return;
            }
            $repairHostnames = [...($repairHostnames ?? []), ...$changedHostnames];
            if (_stattic_runtime_require_edge_purge_endpoint() !== null) {
                _stattic_runtime_purge_enqueue($privateRoot, $changedHostnames, 'hostname_intent_repaired', [
                    $privateRoot . '/routes/index.lock',
                ]);
            }
        });
    }
    if ($previous !== null && !_stattic_runtime_hostname_intent_changed($previous, $routes)) {
        _stattic_json_response(200, [
            'space_id' => $spaceId,
            'route_count' => count($routes),
            ...($repairHostnames !== null && $repairHostnames !== [] && _stattic_runtime_edge_purge_endpoint() !== null
                ? ['purge' => _stattic_runtime_purge_now($privateRoot, ['hostnames' => $repairHostnames, 'reason' => 'hostname_intent_repaired'])]
                : []),
        ]);
    }
    $hostnames = array_values(array_unique([
        ...($repairHostnames ?? []),
        ..._stattic_runtime_hostname_intent_purge_diff($previous['routes'] ?? [], $routes),
    ]));
    _stattic_runtime_prepare_purge($privateRoot, $spaceId, $hostnames, 'hostname_intent_updated');
    _stattic_runtime_store_hostname_intent_from_snapshot($privateRoot, $spaceId, $routes, $previous, $claims);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    // Purge each changed mapping's whole host, including additions that may
    // have cached a miss. Unchanged retained-version hosts keep their cache.
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'route_count' => count($routes),
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'hostname_intent_updated', $hostnames),
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
// unchanged conditional write must stay fully silent. No journal event means no
// edge purge for reconcile-sweep and plan-sync re-pushes.
function _stattic_runtime_write_route_pointer(string $privateRoot, string $spaceId, string $routeName, string $versionId, array $body, array $claims, bool $storeIntent): array
{
    $configDigest = is_string($body['config_digest'] ?? null) && $body['config_digest'] !== ''
        ? $body['config_digest']
        : null;
    $compiled = _stattic_version_root($privateRoot, $spaceId, $versionId) . '/.zero-compiled';
    if (is_file($compiled . '/manifest.json') && !is_file($compiled . '/ready.json')) {
        _stattic_problem_response(409, 'zero_v2_not_initialized', 'The Zero version has not initialized.');
    }
    $routePath = _stattic_route_pointer_path($privateRoot, $spaceId, $routeName);
    // Snapshot every document that can change the write or its purge before
    // mutating anything. The space lock prevents a concurrent writer from
    // changing these snapshots underneath this activation.
    $previousRoute = _stattic_runtime_read_json_strict($routePath);
    if ($previousRoute !== null && (!is_array($previousRoute) || !is_string($previousRoute['route_name'] ?? null) || !is_string($previousRoute['version_id'] ?? null))) {
        throw new RuntimeException('runtime route pointer is invalid: ' . $routePath);
    }
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $previousIntentDoc = _stattic_runtime_space_routing_doc($spaceRoot, 'intent');
    $previousIntent = $previousIntentDoc ?? [];
    $tombstones = _stattic_runtime_space_routing_doc($spaceRoot, 'tombstones') ?? [];
    // Guarded on $storeIntent because compiling also validates the intent body,
    // and a route write carrying no intent must not start rejecting bodies it
    // never inspected.
    $intentRoutes = $storeIntent ? _stattic_runtime_routes_from_hostname_intent($routeName, $body) : [];
    $intentChanged = $storeIntent
        && _stattic_runtime_hostname_intent_changed($previousIntent, $intentRoutes);
    $operationId = is_string($claims['operation_id'] ?? null)
        ? trim($claims['operation_id'])
        : '';
    // An older engine may have stored the incoming digest while dropping fields
    // it did not understand. Compare today's parsed config before a no-op.
    $config = _stattic_runtime_route_config($body['config'] ?? null);
    $configUnchanged = ($previousRoute['config'] ?? null) === $config;
    $requestDigest = _stattic_runtime_canonical_request_digest($body);
    $storedActivationEventId = is_string($previousRoute['activation_event_id'] ?? null)
        && _stattic_is_sha256_hex($previousRoute['activation_event_id'])
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
        && !$intentChanged
        && $configUnchanged
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
    // Matching config bytes do not prove the route-index publish completed.
    // Without a retained activation receipt, replay the commit and finish it.
    if (
        $storedActivationEventId !== null
        && $configDigest !== null
        && !array_key_exists('changed_paths', $body)
        && _stattic_runtime_route_pointer_unchanged($privateRoot, $spaceId, $routeName, $versionId, $configDigest, is_array($previousRoute) ? $previousRoute : [])
        && !$intentChanged
        && $configUnchanged
    ) {
        // The guard above proves the receipt is present, so the event id is the
        // stored one. The durable queue independently owns any pending purge.
        _stattic_runtime_update_route_index($privateRoot, $spaceId);
        return [
            'changed_paths' => [],
            'unchanged' => true,
            'activation_event_id' => $storedActivationEventId,
        ];
    }
    $releasedTombstoneHostnames = [];
    if ($storeIntent) {
        $tombstoned = [];
        foreach ($tombstones['hostnames'] ?? [] as $hostname) {
            if (is_string($hostname)) {
                $normalized = _stattic_runtime_normalize_route_hostname($hostname);
                if ($normalized !== '') {
                    $tombstoned[$normalized] = true;
                }
            }
        }
        // Only the activated version's own immutable hostnames are released.
        // Older retained version hostnames stay tombstoned: a tombstone added
        // after their publication must outrank a later route reconcile.
        foreach (_stattic_runtime_affected_intent_hostnames_from_routes($intentRoutes, null, $versionId) as $hostname) {
            if (isset($tombstoned[$hostname])) {
                $releasedTombstoneHostnames[] = $hostname;
            }
        }
    }
    $effectiveIntentRoutes = $storeIntent
        ? $intentRoutes
        : (is_array($previousIntent['routes'] ?? null) ? $previousIntent['routes'] : []);
    $hostnames = array_values(array_unique(array_merge(
        _stattic_runtime_affected_intent_hostnames_from_routes($effectiveIntentRoutes, $routeName),
        _stattic_runtime_affected_intent_hostnames_from_routes($previousIntent['routes'] ?? [], $routeName),
        $releasedTombstoneHostnames,
    )));
    $previousConfig = is_array($previousRoute['config'] ?? null) ? $previousRoute['config'] : [];
    $previousExposure = is_array($previousRoute) ? _stattic_runtime_public_exposure_descriptor($previousConfig) : null;
    $nextExposure = _stattic_runtime_public_exposure_descriptor($config);
    $needsSweep = _stattic_runtime_exposure_needs_sweep($previousExposure, $nextExposure);
    $purgeHosts = $needsSweep
        ? array_values(array_unique([
            ..._stattic_runtime_access_sweep_hostnames($previousIntent, $tombstones),
            ..._stattic_runtime_access_sweep_hostnames($storeIntent ? ['routes' => $intentRoutes] : $previousIntent, $tombstones),
        ]))
        : $hostnames;
    _stattic_runtime_prepare_purge($privateRoot, $spaceId, $purgeHosts, $needsSweep ? 'space_access_privatized' : 'route_updated');
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
        _stattic_runtime_store_hostname_intent_from_snapshot(
            $privateRoot,
            $spaceId,
            $intentRoutes,
            $previousIntentDoc,
            $claims,
        );
    }
    // Route intent and its immutable-hostname release are one commit under the
    // per-space lock. Write both documents before publishing the shared route
    // index, so a crash exposes either the old state or the complete new state.
    // Exact replays returned above never erase a tombstone added after commit.
    if ($storeIntent && $releasedTombstoneHostnames !== []) {
        _stattic_runtime_store_space_tombstones(
            $privateRoot,
            $spaceId,
            $releasedTombstoneHostnames,
            'remove'
        );
    }
    $changedPathsKnown = false;
    $changedPaths = _stattic_runtime_changed_path_list($body['changed_paths'] ?? null, $changedPathsKnown);
    // The same hostname set feeds the journal event and the purge: a path purge
    // that missed a hostname the event named would leave that host stale.
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
    // cannot say whether cached anonymous bytes just became private. The
    // before/after exposure digests answer that. Presence of the previous field
    // means a route existed; null means treat it conservatively.
    if (is_array($previousRoute)) {
        $routeEvent['previous_public_exposure_digest'] =
            _stattic_runtime_public_exposure_digest($previousConfig);
        $routeEvent['public_exposure_digest'] =
            _stattic_runtime_public_exposure_digest($config);
        $routeEvent['previous_public_exposure'] = $previousExposure;
        $routeEvent['public_exposure'] = $nextExposure;
    }
    // The index/pointer publish happens BEFORE the journal record that announces
    // it: that record is a promise the activation is already SERVING. The
    // activation event id is the response's and the pointer's receipt. The
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
    // Narrowing anonymous access owes EVERY hostname the space has ever
    // answered on (route intent + tombstones) a full sweep: the edge holds
    // cached copies of formerly-public content on every alias, including
    // the immutable version hosts a content activation deliberately skips
    // (their bytes never change, so a publish must NOT purge them). Every
    // other write purges only the route's own serving hostnames.
    $purge = _stattic_runtime_purge_now($privateRoot, [
        'hostnames' => $purgeHosts,
        'reason' => $needsSweep ? 'space_access_privatized' : 'route_updated',
    ]);
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

function _stattic_runtime_canonical_request_digest(array $body): string
{
    return hash('sha256', _stattic_canonical_json($body));
}

function _stattic_runtime_public_exposure_digest(array $config): ?string
{
    $digest = is_string($config['public_exposure_digest'] ?? null)
        ? strtolower($config['public_exposure_digest'])
        : '';
    return _stattic_is_sha256_hex($digest) ? $digest : null;
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
        || !_stattic_is_sha256_hex($descriptor['authorizationDigest'])
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
function _stattic_runtime_hostname_intent_changed(array $stored, array $incomingRoutes): bool
{
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
    $purgeHostnames = array_values(array_unique([...$hostnames, ..._stattic_runtime_space_sweep_hostnames(_stattic_space_root($privateRoot, $spaceId))]));
    _stattic_runtime_prepare_purge($privateRoot, $spaceId, $purgeHostnames, 'space_tombstones_updated');
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
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'space_tombstones_updated', $purgeHostnames),
    ]);
}

// Records which share-image screenshots the control plane has seen render for
// the live version, replacing the previous record. Serving advertises only
// these (_stattic_auto_share_image_ready). Only the live version's hosts can
// match a record, so only they are purged for cached pages to pick up their
// image; a record for a version that is no longer live is refused.
function _stattic_runtime_put_share_images(string $privateRoot, string $spaceId, array $claims): void
{
    require_once __DIR__ . '/../shared/preview-images.php';
    $body = _stattic_json_body();
    $versionId = $body['version_id'] ?? null;
    $urls = $body['urls'] ?? null;
    if (
        !is_string($versionId)
        || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $versionId) !== 1
        || !is_array($urls)
        || !array_is_list($urls)
        || count($urls) > 64
        || !array_all($urls, static fn (mixed $url): bool => is_string($url)
            && strlen($url) <= 2048
            && preg_match('~\Ahttps?://[^/?#\s]+/[^\s#]*\z~', $url) === 1)
    ) {
        _stattic_problem_response(422, 'share_images_invalid', 'Share images need a version_id and at most 64 page URLs.');
    }
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    if (!is_dir($spaceRoot)) {
        _stattic_problem_response(404, 'space_not_found', 'The Space does not exist on this runtime.');
    }
    $live = _stattic_runtime_read_json_strict(_stattic_route_pointer_path($privateRoot, $spaceId, 'production'));
    if (!is_array($live) || ($live['version_id'] ?? null) !== $versionId) {
        _stattic_problem_response(409, 'share_images_version_not_live', 'Share images can only be recorded for the live version.');
    }
    $urls = array_values(array_unique($urls));
    $liveHostnames = [];
    foreach ((_stattic_runtime_space_routing_doc($spaceRoot, 'intent') ?? [])['routes'] ?? [] as $route) {
        if (
            is_array($route)
            && is_string($route['hostname'] ?? null)
            && ($route['target']['type'] ?? null) === 'route'
            && ($route['target']['route_name'] ?? null) === 'production'
        ) {
            $liveHostnames[] = $route['hostname'];
        }
    }
    // A repeat of the stored record (a preview publish re-warms the live
    // version) changes no served page, so it purges nothing.
    $readyPath = $spaceRoot . '/' . STATTIC_AUTO_SHARE_IMAGE_READY_FILE;
    $previous = is_file($readyPath) ? json_decode((string) @file_get_contents($readyPath), true) : null;
    $unchanged = is_array($previous)
        && ($previous['version_id'] ?? null) === $versionId
        && is_array($previous['urls'] ?? null)
        && array_diff($urls, $previous['urls']) === []
        && array_diff($previous['urls'], $urls) === [];
    $purge = !$unchanged && $urls !== [] && $liveHostnames !== [];
    if ($purge) {
        _stattic_runtime_prepare_purge($privateRoot, $spaceId, $liveHostnames, 'space_share_images_updated');
    }
    _stattic_runtime_write_json_atomic($readyPath, [
        'version_id' => $versionId,
        'urls' => $urls,
    ]);
    _stattic_runtime_journal_management_diagnostic($privateRoot, $claims, [
        'event' => 'space_share_images_updated',
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'url_count' => count($urls),
    ]);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'url_count' => count($urls),
        ...($purge ? ['purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'space_share_images_updated', $liveHostnames)] : []),
    ]);
}

function _stattic_runtime_delete_space(string $privateRoot, string $spaceId, array $claims): void
{
    require_once __DIR__ . '/storage.php';
    $retained = _stattic_storage_has_commerce_retention($privateRoot, $spaceId);
    if ($retained === null) _stattic_problem_response(503, 'storage_unavailable', 'Paid asset retention could not be checked.');
    if ($retained) _stattic_problem_response(409, 'storage_object_retained', 'This Space retains purchased files. Resolve paid-file retention before deleting it.');
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    // Snapshot every purge/tombstone input before removing any bytes. An
    // unavailable input aborts the delete instead of producing an incomplete
    // purge set or silently dropping retired-host state.
    $intent = _stattic_runtime_space_routing_doc($spaceRoot, 'intent') ?? [];
    $tombstones = _stattic_runtime_space_routing_doc($spaceRoot, 'tombstones') ?? [];
    $hostnames = _stattic_runtime_access_sweep_hostnames($intent, $tombstones);
    _stattic_runtime_prepare_purge($privateRoot, $spaceId, $hostnames, 'space_deleted');
    _stattic_runtime_rm_recursive($spaceRoot);
    // Tombstones must survive the rm: retired hostnames keep serving the
    // tombstone page rather than degrading to the generic undeployed 503.
    if (($tombstones['hostnames'] ?? []) !== []) {
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
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'space_deleted', $hostnames),
    ]);
}

/** @return list<string> */
function _stattic_runtime_repair_hostnames(string $privateRoot): array
{
    $hostnames = array_fill_keys(_stattic_runtime_all_space_sweep_hostnames($privateRoot), true);
    $pointer = _stattic_runtime_read_route_pointer($privateRoot);
    if ($pointer === false) {
        throw new RuntimeException('route index read failed: ' . $privateRoot . '/routes/current.json');
    }
    if (is_array($pointer)) {
        foreach ([...array_values(is_array($pointer['shards'] ?? null) ? $pointer['shards'] : []), $pointer['wildcards'] ?? null] as $name) {
            if (!is_string($name)) {
                continue;
            }
            $shard = _stattic_runtime_read_route_shard($privateRoot . '/routes/' . $name);
            if ($shard === null) {
                continue;
            }
            foreach (array_keys($shard['hostnames'] + $shard['host_routes']) as $hostname) {
                $hostnames[(string) $hostname] = true;
            }
        }
    }
    return _stattic_runtime_purge_hostname_list(array_keys($hostnames));
}

function _stattic_runtime_repair_space(string $privateRoot, string $spaceId, array $claims): void
{
    if (!is_dir(_stattic_space_root($privateRoot, $spaceId))) {
        _stattic_problem_response(404, 'space_not_found', 'Space not found.');
    }
    // The rebuild repairs every Space's projection, so include every Space's
    // hostname as well as names from the old index before changing any pointer.
    $hostnames = _stattic_runtime_repair_hostnames($privateRoot);
    if (_stattic_runtime_require_edge_purge_endpoint() !== null) {
        _stattic_runtime_purge_enqueue($privateRoot, $hostnames, 'space_repaired', [
            $privateRoot . '/runtime/write.lock',
            $privateRoot . '/routes/index.lock',
        ]);
    }
    _stattic_runtime_rebuild_route_index($privateRoot);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_repaired',
        'space_id' => $spaceId,
    ]);
    // A repair exists because the projection was wrong, so what the edge cached
    // while it was wrong is suspect: purge the whole domain rather than guess.
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'status' => 'repaired',
        'purge' => _stattic_runtime_purge_now($privateRoot, ['hostnames' => $hostnames, 'reason' => 'space_repaired']),
    ]);
}

/**
 * Space Users settings in the one form every engine reader uses: the original
 * flat shape. The control plane sends either that shape or the two-part
 * `zeroUsers` / `signInWithSpacefast` shape (runtime.users.sign-in-shape.v1); the
 * two-part shape also keeps whether each part is on, which the flat shape
 * cannot say, under `parts`. Null for anything malformed.
 */
function _stattic_runtime_users_config(mixed $users): ?array
{
    $isBool = static fn (mixed $value): bool => is_bool($value);
    $google = static function (mixed $google, array $modes): ?array {
        if (!is_array($google) || !in_array($google['mode'] ?? null, $modes, true)) return null;
        if ($google['mode'] !== 'direct') return ['mode' => $google['mode']];
        return is_string($google['clientId'] ?? null) && $google['clientId'] !== '' ? ['mode' => 'direct', 'clientId' => $google['clientId']] : null;
    };
    if (!is_array($users)) return null;
    if (array_key_exists('zeroUsers', $users)) {
        $zero = $users['zeroUsers'] ?? null;
        $access = $users['signInWithSpacefast'] ?? null;
        $direct = is_array($zero) ? $google($zero['providers']['google'] ?? null, ['disabled', 'direct']) : null;
        $providers = is_array($access) ? ($access['providers'] ?? null) : null;
        if (!is_array($zero) || !$isBool($zero['enabled'] ?? null) || $direct === null || !is_array($access)
            || !$isBool($access['enabled'] ?? null) || !is_array($providers)
            || !$isBool($providers['spacefast'] ?? null) || !$isBool($providers['managedGoogle'] ?? null) || !$isBool($providers['wpcom'] ?? null)) {
            return null;
        }
        $on = $access['enabled'];
        return [
            'enabled' => $zero['enabled'] || $on,
            'providers' => [
                // Direct Google is Zero Users': it is off with Zero Users, whatever is stored.
                'google' => $direct['mode'] === 'direct' && $zero['enabled'] ? $direct : ['mode' => $on && $providers['managedGoogle'] ? 'managed' : 'disabled'],
                'gravatar' => ['enabled' => $on && $providers['wpcom']],
                'spacefast' => ['enabled' => $on && $providers['spacefast']],
            ],
            'parts' => ['zeroUsers' => $zero['enabled'], 'signInWithSpacefast' => $on],
        ];
    }
    $providers = $users['providers'] ?? null;
    $flatGoogle = is_array($providers) ? $google($providers['google'] ?? null, ['disabled', 'managed', 'direct']) : null;
    if (!$isBool($users['enabled'] ?? null) || $flatGoogle === null
        || !$isBool($providers['gravatar']['enabled'] ?? null) || !$isBool($providers['spacefast']['enabled'] ?? null)) {
        return null;
    }
    return [
        'enabled' => $users['enabled'],
        'providers' => [
            'google' => $flatGoogle,
            'gravatar' => ['enabled' => $providers['gravatar']['enabled']],
            'spacefast' => ['enabled' => $providers['spacefast']['enabled']],
        ],
    ];
}

function _stattic_runtime_route_config(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $config = [];
    if (array_key_exists('users', $raw)) {
        $users = _stattic_runtime_users_config($raw['users']);
        if ($users === null) _stattic_problem_response(422, 'space_users_config_invalid', 'Users settings are invalid.');
        $config['users'] = $users;
    }
    if (array_key_exists('usersProviderConfig', $raw)) {
        $private = $raw['usersProviderConfig'];
        if ($private !== null && (!is_array($private)
            || !is_string($private['issuer'] ?? null) || !is_string($private['clientId'] ?? null)
            || !is_string($private['googleStartUrl'] ?? null) || !is_string($private['gravatarStartUrl'] ?? null)
            || !is_string($private['revision'] ?? null) || !is_array($private['availability'] ?? null)
            || !array_key_exists('directGoogle', $private)
            || ($private['directGoogle'] !== null && (!is_array($private['directGoogle'])
                || !is_string($private['directGoogle']['clientId'] ?? null) || !is_string($private['directGoogle']['clientSecret'] ?? null))))) {
            _stattic_problem_response(422, 'space_users_provider_config_invalid', 'Users provider configuration is invalid.');
        }
        $config['usersProviderConfig'] = $private;
    }

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
    $config['zero_generation'] = in_array($raw['zero_generation'] ?? null, ['none', 'legacy_0_8', 'v2'], true) ? $raw['zero_generation'] : 'none';
    $config['zero_generation_set_at'] = is_string($raw['zero_generation_set_at'] ?? null) ? $raw['zero_generation_set_at'] : null;
    if (array_key_exists('ownership_revision', $raw)) {
        $config['ownership_revision'] = is_string($raw['ownership_revision']) ? $raw['ownership_revision'] : null;
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
    if (is_int($raw['billing']['emailRecipientsDaily'] ?? null) && $raw['billing']['emailRecipientsDaily'] > 0) {
        $config['billing'] = ['emailRecipientsDaily' => $raw['billing']['emailRecipientsDaily']];
    }
    if (array_key_exists('email', $raw)) {
        $email = $raw['email'];
        $domain = is_array($email) && is_string($email['senderDomain'] ?? null)
            ? strtolower($email['senderDomain'])
            : '';
        if ($email !== null && ($domain === '' || strlen($domain) > 253
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $domain) !== 1)) {
            _stattic_problem_response(422, 'space_email_config_invalid', 'Email sender domain is invalid.');
        }
        $config['email'] = $email === null ? null : ['senderDomain' => $domain];
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

// These emitters deliberately bypass _stattic_send_response_headers: the
// instance-pinned source lane answers the finalizer, not a visitor, and its
// reset + explicit status protocol must not pick up the platform edge policy.
function _stattic_runtime_version_source_reset_headers(): void
{
    foreach (['Content-Type', 'Content-Length', 'Cache-Control', 'Pragma', 'Expires'] as $name) {
        header_remove($name);
    }
}

// The caller's source-reader contract treats a missing optional file as null.
function _stattic_runtime_version_source_empty(): never
{
    if (defined('STATTIC_RUNTIME_DISPATCH_CLI')) {
        _stattic_json_response(204, [
            'body_base64' => '',
            'size' => 0,
            'content_type' => 'application/octet-stream',
        ]);
    }
    _stattic_runtime_version_source_reset_headers();
    header('Cache-Control: ' . STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
    _stattic_runtime_version_source_status(204);
    exit;
}

/** @param resource $stream */
function _stattic_runtime_version_source_send($stream, int $size, string $contentType): never
{
    if (defined('STATTIC_RUNTIME_DISPATCH_CLI')) {
        $bytes = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            _stattic_problem_response(
                503,
                'runtime_version_source_unavailable',
                'Version source bytes are unavailable on this runtime instance.'
            );
        }
        _stattic_json_response(200, [
            'body_base64' => base64_encode($bytes),
            'size' => $size,
            'content_type' => $contentType,
        ]);
    }
    _stattic_runtime_version_source_reset_headers();
    header('Content-Type: ' . $contentType, true);
    header('Content-Length: ' . $size, true);
    header('Cache-Control: ' . STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
    header('X-Content-Type-Options: nosniff', true);
    _stattic_runtime_version_source_status(200);
    _stattic_stream_file($stream, $size);
    fclose($stream);
    exit;
}

/**
 * Read one bounded version object through the instance-pinned management lane.
 *
 * Finalize needs a handful of private compile inputs before the version catalog
 * exists. Those bytes must not round-trip through the public blob/download
 * surface: provider X-Accel handling is a serving concern, and a failure there
 * must not make an already accepted upload unreadable to its own finalizer.
 *
 * A fresh source object is selected by upload_id + sha256. A finalized object
 * is selected by path and view against this version's catalog. Absence is a
 * 204 because the caller's file-reader contract treats a missing optional file
 * as null.
 */
function _stattic_runtime_read_version_evidence_route(string $privateRoot, string $spaceId, string $versionId, array $claims): void
{
    _stattic_runtime_read_version_source_route($privateRoot, $spaceId, $versionId, $claims, true);
}

function _stattic_runtime_read_version_source_route(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    array $_claims,
    bool $readOnly = false
): void {
    $uploadId = _stattic_runtime_version_files_param('upload_id');
    $sha256 = _stattic_runtime_version_files_param('sha256');
    $path = _stattic_runtime_version_files_param('path');
    $view = _stattic_runtime_version_files_param('view') ?? 'source';
    $channel = _stattic_runtime_version_files_param('channel');
    if ($channel !== null) {
        if (!$readOnly || $view !== 'served') _stattic_problem_response(422, 'runtime_version_source_request_invalid', 'channel requires a served evidence read.');
        $channel = _stattic_runtime_id($channel, 'channel');
    }
    $rawMaxBytes = _stattic_runtime_version_files_param('max_bytes');
    if (!in_array($view, STATTIC_RUNTIME_VERSION_FILE_VIEWS, true)) {
        _stattic_problem_response(
            422,
            'runtime_version_source_request_invalid',
            'view must be "source" or "served".'
        );
    }
    if (
        $rawMaxBytes === null
        || preg_match('/\A[1-9][0-9]{0,7}\z/', $rawMaxBytes) !== 1
        || (int) $rawMaxBytes > SPACEFAST_UPLOAD_MAX_FILE_BYTES
    ) {
        _stattic_problem_response(
            422,
            'runtime_version_source_request_invalid',
            'max_bytes must be a positive integer within the runtime file-size limit.'
        );
    }
    $maxBytes = (int) $rawMaxBytes;
    if ($readOnly && ($uploadId !== null || $sha256 !== null || $path === null)) {
        _stattic_problem_response(422, 'runtime_version_source_request_invalid', 'Evidence reads require a finalized catalog path.');
    }
    if ($readOnly && !_stattic_runtime_version_finalized(_stattic_version_root($privateRoot, $spaceId, $versionId))) {
        _stattic_runtime_version_source_empty();
    }
    $resolved = null;
    if ($uploadId !== null || $sha256 !== null) {
        if (
            $uploadId === null
            || $sha256 === null
            || $path !== null
            || $view !== 'source'
            || !_stattic_id_valid($uploadId)
            || !_stattic_is_sha256_hex(strtolower($sha256))
        ) {
            _stattic_problem_response(
                422,
                'runtime_version_source_request_invalid',
                'Fresh source reads require exactly upload_id and sha256.'
            );
        }
        $session = _stattic_runtime_publish_session_load($privateRoot, $spaceId, $uploadId);
        if (!is_array($session) || ($session['version_id'] ?? null) !== $versionId) {
            _stattic_runtime_version_source_empty();
        }
        $resolved = _stattic_runtime_publish_session_blob(
            $privateRoot,
            $spaceId,
            $uploadId,
            strtolower($sha256)
        );
    } elseif ($path !== null) {
        if ($channel !== null) {
            if (_stattic_runtime_file_path_or_null($path, true) !== $path || _stattic_path_is_internal_artifact($path)) _stattic_runtime_version_source_empty();
            $catalog = _stattic_runtime_version_catalog_read_only($privateRoot, $spaceId, $versionId);
            $resolved = _stattic_runtime_catalog_object(is_array($catalog) ? ($catalog['variants'][$channel][$path] ?? null) : null);
        } else {
            $resolved = _stattic_runtime_resolve_version_file(
                $privateRoot,
                $spaceId,
                $versionId,
                $path,
                $view,
                $readOnly
            );
        }
    } else {
        _stattic_problem_response(
            422,
            'runtime_version_source_request_invalid',
            'Source reads require upload_id + sha256 or path.'
        );
    }
    if (!is_array($resolved)) {
        _stattic_runtime_version_source_empty();
    }
    if ($readOnly) {
        $bytes = _stattic_runtime_read_evidence_object($privateRoot, $spaceId, $resolved, $maxBytes);
        if ($bytes === null) {
            _stattic_runtime_version_source_empty();
        }
        _stattic_runtime_version_source_reset_headers();
        header('Content-Type: application/octet-stream', true);
        header('Content-Length: ' . strlen($bytes), true);
        header('Cache-Control: ' . STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        header('X-Content-Type-Options: nosniff', true);
        _stattic_runtime_version_source_status(200);
        echo $bytes;
        exit;
    }
    $blobPath = _stattic_runtime_blob_path($privateRoot, $spaceId, (string) $resolved['sha']);
    $size = is_file($blobPath) ? filesize($blobPath) : false;
    if (!is_int($size)) {
        _stattic_problem_response(
            503,
            'runtime_version_source_unavailable',
            'Version source bytes are unavailable on this runtime instance.'
        );
    }
    if ($size > $maxBytes) {
        _stattic_runtime_version_source_empty();
    }
    $stream = fopen($blobPath, 'rb');
    if ($stream === false) {
        _stattic_problem_response(
            503,
            'runtime_version_source_unavailable',
            'Version source bytes are unavailable on this runtime instance.'
        );
    }
    _stattic_runtime_version_source_send($stream, $size, (string) ($resolved['mime'] ?? 'application/octet-stream'));
}

/** Immutable incident readers use JSON only: no PHP sidecar creation or execution. */
function _stattic_runtime_investigation_catalog(string $privateRoot, string $spaceId, string $versionId): ?array
{
    $root = _stattic_version_root($privateRoot, $spaceId, $versionId);
    if (!_stattic_runtime_version_finalized($root)) return null;
    $file = _stattic_runtime_investigation_path($root, 'metadata.json');
    if ($file !== null && filesize($file) > 16777216) _stattic_problem_response(503, 'runtime_investigation_catalog_budget_exceeded', 'The immutable catalog exceeds the bounded investigation read.');
    $catalog = _stattic_runtime_version_catalog_read_only($privateRoot, $spaceId, $versionId);
    if (is_array($catalog) && count($catalog['paths']) > 50000) _stattic_problem_response(503, 'runtime_investigation_catalog_budget_exceeded', 'The immutable catalog exceeds the bounded investigation row limit.');
    return $catalog;
}

function _stattic_runtime_investigation_path(string $root, string $path): ?string
{
    $base = realpath($root);
    $resolved = realpath($root . '/' . $path);
    return is_string($base) && is_string($resolved) && str_starts_with($resolved, $base . '/') && is_file($resolved) ? $resolved : null;
}

function _stattic_runtime_investigation_json(string $root, string $path, int $limit): ?array
{
    $file = _stattic_runtime_investigation_path($root, $path);
    if ($file === null) return null;
    $stream = fopen($file, 'rb');
    if ($stream === false) return null;
    $bytes = stream_get_contents($stream, $limit + 1);
    fclose($stream);
    if (!is_string($bytes) || strlen($bytes) > $limit) return null;
    $value = json_decode($bytes, true, 32);
    return is_array($value) ? $value : null;
}

function _stattic_runtime_execution_kind(string $path): string
{
    if (str_starts_with($path, '__spacefast/functions/bundles/')) return 'functions_bundle';
    if ($path === '__spacefast/zero/bundles/server.qjs.mjs') return 'zero_bundle';
    return 'internal_unknown';
}

function _stattic_runtime_execution_row(string $kind, string $path, ?string $sha = null, ?int $size = null, string $state = 'unavailable'): array
{
    return ['id' => hash('sha256', $kind . "\0" . $path), 'kind' => $kind, 'path' => $path, 'sha256' => $sha, 'size' => $size, 'state' => $state];
}

/** Indexes are bounded; artifact metadata and bodies are read only for the requested page. */
function _stattic_runtime_execution_candidates(string $root, array $catalog): array
{
    $rows = [];
    foreach ($catalog['paths'] as $path => $entry) {
        if (!is_string($path) || _stattic_runtime_file_path_or_null($path, true) !== $path || !_stattic_path_is_internal_artifact($path)) continue;
        $kind = _stattic_runtime_execution_kind($path);
        $object = is_array($entry) ? _stattic_runtime_catalog_object($entry['source'] ?? null) : null;
        $row = _stattic_runtime_execution_row($kind, $path, $object['sha'] ?? null, $object['size'] ?? null, $kind === 'internal_unknown' ? 'unsupported' : (is_array($object) ? 'available' : 'unavailable'));
        $rows[$row['id']] = $row;
    }
    foreach (['endpoints', 'runs'] as $lane) {
        $indexPath = 'zero/' . $lane . '-index.json';
        if (!file_exists($root . '/' . $indexPath)) continue;
        $index = _stattic_runtime_investigation_json($root, $indexPath, 1048576);
        $entries = is_array($index) ? ($index[$lane] ?? null) : null;
        if (!is_array($entries) || count($entries) > 10000 || ($index['format'] ?? null) !== 'stattic.zero.' . $lane . '-index.v1' || ($index['artifact_kind'] ?? null) !== 'zero_' . $lane . '_index') {
            foreach (['zero_source', 'zero_bytecode'] as $kind) {
                $row = _stattic_runtime_execution_row($kind, $indexPath);
                $rows[$row['id']] = $row;
            }
            continue;
        }
        foreach ($entries as $identity => $artifactPath) {
            if (!is_string($artifactPath) || preg_match('~\Azero/' . $lane . '/[a-z0-9_]+\.json\z~', $artifactPath) !== 1) {
                foreach (['zero_source', 'zero_bytecode'] as $kind) {
                    $row = _stattic_runtime_execution_row($kind, $indexPath . '#' . hash('sha256', (string) $identity));
                    $rows[$row['id']] = $row;
                }
                continue;
            }
            foreach (['zero_source' => '.source.js', 'zero_bytecode' => '.bytecode'] as $kind => $suffix) {
                $path = substr($artifactPath, 0, -5) . $suffix;
                $row = _stattic_runtime_execution_row($kind, $path);
                $row['_metadata'] = $artifactPath;
                $row['_identity'] = (string) $identity;
                $row['_identity_key'] = $lane === 'endpoints' ? 'endpointId' : 'runId';
                $rows[$row['id']] = $row;
            }
        }
    }
    ksort($rows, SORT_STRING);
    return $rows;
}

function _stattic_runtime_execution_resolve(string $root, array $row): array
{
    if (!isset($row['_metadata'])) return $row;
    $metadata = _stattic_runtime_investigation_json($root, $row['_metadata'], 65536);
    $source = $row['kind'] === 'zero_source';
    $pathKey = $source ? 'sourcePath' : 'bytecodePath';
    $shaKey = $source ? 'sourceSha256' : 'bytecodeSha256';
    $sha = is_array($metadata) ? ($metadata[$shaKey] ?? null) : null;
    $file = _stattic_runtime_investigation_path($root, $row['path']);
    $size = $file !== null ? filesize($file) : false;
    $expectedKind = $row['_identity_key'] === 'endpointId' ? 'endpoint' : 'run';
    if (is_array($metadata) && ($metadata['kind'] ?? null) === $expectedKind && ($metadata['format'] ?? null) === 'stattic.zero.' . $expectedKind . '.v1' && ($metadata[$row['_identity_key']] ?? null) === $row['_identity'] && ($metadata[$pathKey] ?? null) === $row['path'] && is_string($sha) && preg_match('/\Asha256:([a-f0-9]{64})\z/', $sha, $match) === 1) {
        $row['sha256'] = $match[1];
        $row['size'] = is_int($size) ? $size : null;
        $row['state'] = is_int($size) ? 'available' : 'unavailable';
    }
    unset($row['_metadata'], $row['_identity'], $row['_identity_key']);
    return $row;
}

function _stattic_runtime_list_execution_artifacts_route(string $privateRoot, string $spaceId, string $versionId): void
{
    $root = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $catalog = _stattic_runtime_investigation_catalog($privateRoot, $spaceId, $versionId);
    if ($catalog === null) _stattic_problem_response(503, 'runtime_execution_catalog_unavailable', 'The immutable execution catalog is unavailable.');
    $limit = _stattic_runtime_version_files_param('limit') ?? '50';
    $cursor = _stattic_runtime_version_files_param('cursor');
    $id = _stattic_runtime_version_files_param('artifact_id');
    if (preg_match('/\A[1-9][0-9]?\z|\A100\z/', $limit) !== 1 || ($cursor !== null && !_stattic_is_sha256_hex($cursor)) || ($id !== null && !_stattic_is_sha256_hex($id))) _stattic_runtime_version_files_request_invalid('Invalid execution catalog page selector.');
    $candidates = _stattic_runtime_execution_candidates($root, $catalog);
    $page = [];
    $next = null;
    foreach ($candidates as $key => $row) {
        if (($id !== null && $key !== $id) || ($cursor !== null && strcmp($key, $cursor) <= 0)) continue;
        if (count($page) >= (int) $limit) { $next = $page[count($page) - 1]['id']; break; }
        $page[] = _stattic_runtime_execution_resolve($root, $row);
    }
    $channels = array_keys(is_array($catalog['variants'] ?? null) ? $catalog['variants'] : []);
    sort($channels, SORT_STRING);
    _stattic_json_response(200, ['space_id' => $spaceId, 'version_id' => $versionId, 'artifacts' => $page, 'next_cursor' => $next, 'channels' => array_slice($channels, 0, 8), 'channels_omitted' => max(0, count($channels) - 8)]);
}

/**
 * Incident-only byte reader for immutable compiled code. The management JWT
 * binds both identities; the selector must match the committed catalog and
 * physical bytes. It never grants a browser URL, reads a seed, or executes code.
 */
function _stattic_runtime_read_version_investigation_code_artifact_route(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    array $_claims
): void {
    $path = _stattic_runtime_version_files_param('path');
    $sha256 = _stattic_runtime_version_files_param('sha256');
    $rawSize = _stattic_runtime_version_files_param('size');
    $rawMaxBytes = _stattic_runtime_version_files_param('max_bytes');
    $normalized = is_string($path) ? _stattic_runtime_file_path_or_null($path, true) : null;
    $codePath = $normalized === '__spacefast/zero/bundles/server.qjs.mjs'
        || (is_string($normalized) && str_starts_with($normalized, '__spacefast/functions/bundles/'))
        || (is_string($normalized) && preg_match('~\Azero/(endpoints|runs)/[a-z0-9_]+\.(source\.js|bytecode)\z~', $normalized) === 1);
    if (!$codePath || $normalized !== $path) {
        _stattic_runtime_version_file_not_found();
    }
    if (
        !is_string($sha256) || !_stattic_is_sha256_hex($sha256)
        || !is_string($rawSize) || preg_match('/\A(?:0|[1-9][0-9]{0,7})\z/', $rawSize) !== 1
        || !is_string($rawMaxBytes) || preg_match('/\A[1-9][0-9]{0,7}\z/', $rawMaxBytes) !== 1
        || (int) $rawMaxBytes > 8388608
    ) {
        _stattic_problem_response(422, 'runtime_code_artifact_request_invalid', 'Code reads require the exact catalog SHA-256 and size and a byte budget up to 8 MiB.');
    }
    $root = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $catalog = _stattic_runtime_investigation_catalog($privateRoot, $spaceId, $versionId);
    $entry = is_array($catalog) ? ($catalog['paths'][$normalized] ?? null) : null;
    $resolved = is_array($entry) ? _stattic_runtime_catalog_object($entry['source'] ?? null) : null;
    if (str_starts_with($normalized, 'zero/')) {
        $kind = str_ends_with($normalized, '.source.js') ? 'zero_source' : 'zero_bytecode';
        $id = hash('sha256', $kind . "\0" . $normalized);
        $candidate = is_array($catalog) ? (_stattic_runtime_execution_candidates($root, $catalog)[$id] ?? null) : null;
        $row = is_array($candidate) ? _stattic_runtime_execution_resolve($root, $candidate) : null;
        $resolved = is_array($row) && $row['state'] === 'available' ? ['sha' => $row['sha256'], 'size' => $row['size']] : null;
    }
    if (!is_array($resolved)) {
        _stattic_runtime_version_source_empty();
    }
    if ((string) $resolved['sha'] !== $sha256 || (int) $resolved['size'] !== (int) $rawSize) {
        _stattic_problem_response(409, 'runtime_code_artifact_identity_mismatch', 'The requested code does not match the committed version catalog.');
    }
    if ((int) $rawSize > (int) $rawMaxBytes) {
        _stattic_runtime_version_source_empty();
    }
    if (!str_starts_with($normalized, 'zero/')) {
        $bytes = _stattic_runtime_read_evidence_object($privateRoot, $spaceId, $resolved, (int) $rawMaxBytes);
        if ($bytes === null) _stattic_runtime_version_source_empty();
        _stattic_runtime_version_source_reset_headers();
        header('Content-Type: application/octet-stream', true);
        header('Content-Length: ' . strlen($bytes), true);
        header('Cache-Control: ' . STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        header('X-Content-Type-Options: nosniff', true);
        _stattic_runtime_version_source_status(200);
        echo $bytes;
        exit;
    }
    $blobPath = _stattic_runtime_investigation_path($root, $normalized);
    $stream = $blobPath !== null ? fopen($blobPath, 'rb') : false;
    if ($stream === false) {
        _stattic_problem_response(503, 'runtime_code_artifact_unavailable', 'Compiled code bytes are unavailable on this runtime instance.');
    }
    $state = fstat($stream);
    $actualSha = hash_init('sha256');
    $hashedBytes = hash_update_stream($actualSha, $stream, (int) $rawSize + 1);
    if (!is_array($state) || ($state['size'] ?? null) !== (int) $rawSize || $hashedBytes !== (int) $rawSize || hash_final($actualSha) !== $sha256) {
        fclose($stream);
        _stattic_problem_response(409, 'runtime_code_artifact_identity_mismatch', 'Compiled code bytes do not match their immutable identity.');
    }
    rewind($stream);
    _stattic_runtime_version_source_send($stream, (int) $rawSize, 'application/octet-stream');
}

function _stattic_runtime_version_files_request_invalid(string $message): never
{
    _stattic_problem_response(422, 'runtime_version_files_request_invalid', $message);
}

/**
 * THE version file list: one bounded catalog page, both views.
 *
 * `view=source` is the uploaded, pre-substitution object the file browser shows.
 * `view=served` is the immutable byte a visitor receives, which is what the
 * scanner enumerates; private compile inputs have no served object and drop out
 * of that view entirely. `channel` adds a template-variant route's own bytes as
 * extra rows carrying `variant_route`, so the scanner sees the exact bytes that
 * channel can serve. It is a served-view dimension and nothing else.
 *
 * `public` comes from the catalog, the single visibility implementation.
 *
 * `path` narrows the page to one exact entry, so a per-file lookup costs one
 * bounded round trip instead of a full drain. A path the view does not hold is
 * an EMPTY page, not a 404.
 */
function _stattic_runtime_list_version_evidence_route(string $privateRoot, string $spaceId, string $versionId, array $claims): void
{
    _stattic_runtime_list_version_files_route($privateRoot, $spaceId, $versionId, $claims, true);
}

function _stattic_runtime_list_version_files_route(string $privateRoot, string $spaceId, string $versionId, array $_claims = [], bool $readOnly = false): void
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
        $exactPath = _stattic_runtime_file_path_or_null($exactPath, $readOnly);
        if ($exactPath === null) {
            _stattic_runtime_version_files_request_invalid('path is not a canonical version path.');
        }
    }
    $publicOnly = _stattic_runtime_version_files_flag('public_only');
    $includeInternal = _stattic_runtime_version_files_flag('include_internal');
    if ($includeInternal && ($view !== 'source' || $publicOnly || $channel !== null)) {
        _stattic_runtime_version_files_request_invalid('include_internal requires the unfiltered source view.');
    }
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
    $catalog = $readOnly
        ? _stattic_runtime_version_catalog_read_only($privateRoot, $spaceId, $versionId)
        : _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
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
        // JSON object keys that are decimal filenames decode as integer keys.
        if ($readOnly) {
            $path = (string) $path;
        }
        if (
            !is_string($path)
            || $path === ''
            || (!$includeInternal && _stattic_path_is_internal_artifact($path))
            || !is_array($entry)
        ) {
            continue;
        }
        $public = ($entry['public'] ?? false) === true;
        if ($publicOnly && !$public) {
            continue;
        }
        $object = $readOnly
            ? _stattic_runtime_evidence_catalog_object($entry[$view] ?? null)
            : _stattic_runtime_catalog_object($entry[$view] ?? null);
        if ($object === null) {
            continue;
        }
        $rows[] = _stattic_runtime_version_files_row($path, $object, $public, null);
    }
    if ($channel !== null) {
        $variant = is_array($catalog['variants'][$channel] ?? null) ? $catalog['variants'][$channel] : [];
        foreach ($variant as $path => $object) {
            if ($readOnly) {
                $path = (string) $path;
            }
            if (!is_string($path) || $path === '' || (!$includeInternal && _stattic_path_is_internal_artifact($path))) {
                continue;
            }
            // A variant substitutes bytes for a path the base already carries,
            // so the base entry owns the visibility bit.
            $base = is_array($catalog['paths'][$path] ?? null) ? $catalog['paths'][$path] : [];
            $public = ($base['public'] ?? false) === true;
            if ($publicOnly && !$public) {
                continue;
            }
            $normalized = $readOnly
                ? _stattic_runtime_evidence_catalog_object($object)
                : _stattic_runtime_catalog_object($object);
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
            _stattic_runtime_version_files_sort_key(array_last($page))
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

// Base rows before their variant rows, then by path, the same order the cursor
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
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'purge' => _stattic_runtime_purge_space_now($privateRoot, $spaceId, 'control_plane_purge'),
    ]);
}
