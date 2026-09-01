<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/admission.php';
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/http.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/upload-policy.php';
require_once __DIR__ . '/auth.php';

const STATTIC_RUNTIME_UPLOAD_HAVE_MAX_SHAS = 2048;
const STATTIC_RUNTIME_INGEST_CONCURRENCY_PER_SPACE = 4;
const STATTIC_RUNTIME_UPLOAD_SOURCE_URL_TIMEOUT_SECONDS = 30;
const STATTIC_RUNTIME_UPLOAD_SOURCE_URL_CONNECT_TIMEOUT_SECONDS = 5;
const STATTIC_RUNTIME_BULK_CAS_MAX_COMPRESSED_BYTES = 134217728; // 128 MiB
const STATTIC_RUNTIME_BULK_CAS_MAX_EXPANDED_BYTES = 134217728; // 128 MiB
const STATTIC_RUNTIME_BULK_CAS_MAX_BLOBS = 2000;

// Manifest scale ceilings: the runtime's last-resort boundary, not plan policy.
// Plan caps are lower and enforced by the control plane. Keep these in parity
// with MANIFEST_MAX_FILES_CEILING, MANIFEST_MAX_PATH_BYTES and
// MAX_VERSION_FILE_SIZE_BYTES in packages/common/src/utils/publish-policy.ts;
// a skipped or compromised control plane is why the boundary exists.
const STATTIC_RUNTIME_MANIFEST_MAX_FILES = 100000;
const STATTIC_RUNTIME_MANIFEST_MAX_PATH_BYTES = 1024;
const STATTIC_RUNTIME_MANIFEST_MAX_FILE_BYTES = 67108864; // 64 MiB

function _stattic_runtime_publish_pins_store(string $privateRoot, string $spaceId): array
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    // Every pin carries an `expires_at` and the GC treats an expired one as
    // absent, so pins/ reclaims on the same clock instead of growing forever.
    return _stattic_record_store(
        _stattic_space_root($privateRoot, $spaceId) . '/pins',
        ['retention' => ['field' => 'expires_at']],
    );
}

function _stattic_runtime_publish_pin_id(string $uploadId): string
{
    return 'publish-' . _stattic_runtime_id($uploadId, 'upload_id');
}

/** @return list<string> */
function _stattic_runtime_publish_session_shas(array $session): array
{
    $shas = [];
    foreach (['manifest', 'retained_files'] as $field) {
        foreach ((is_array($session[$field] ?? null) ? $session[$field] : []) as $entry) {
            $sha = is_array($entry) && is_string($entry['sha256'] ?? null)
                ? strtolower(trim($entry['sha256']))
                : '';
            if (_stattic_is_sha256_hex($sha)) {
                $shas[$sha] = true;
            }
        }
    }
    $result = array_keys($shas);
    sort($result, SORT_STRING);
    return $result;
}

/** @return array<string,int> */
function _stattic_runtime_publish_session_declared_sizes(array $session): array
{
    $sizes = [];
    foreach ((is_array($session['manifest'] ?? null) ? $session['manifest'] : []) as $entry) {
        if (is_array($entry) && is_string($entry['sha256'] ?? null)) {
            $sizes[strtolower($entry['sha256'])] = (int) ($entry['size'] ?? -1);
        }
    }
    return $sizes;
}

// A pin is the only thing that stops the GC collecting bytes; `expires_at`
// bounds it. Fail-closed both ways: this writer refuses an unbounded pin, and
// the GC (`_stattic_tier_space_pinned_shas`) treats an unparseable one as
// expired.
function _stattic_runtime_publish_session_write_pin(string $privateRoot, string $spaceId, string $uploadId, array $session): void
{
    $expiresAt = _stattic_record_store_timestamp($session['expires_at'] ?? null);
    if ($expiresAt === null) {
        _stattic_problem_response(
            500,
            'upload_session_pin_unbounded',
            'Publish session has no usable expiry, so its blob pin cannot be written.'
        );
    }
    _stattic_record_store_put(
        _stattic_runtime_publish_pins_store($privateRoot, $spaceId),
        _stattic_runtime_publish_pin_id($uploadId),
        [
            'shas' => _stattic_runtime_publish_session_shas($session),
            // Normalized: the GC parses this, so store the form that always
            // parses rather than whatever the session carried.
            'expires_at' => gmdate('c', $expiresAt),
        ],
    );
}

function _stattic_runtime_publish_session_create(string $privateRoot, string $spaceId, string $uploadId, array $record): array
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $uploadId = _stattic_runtime_id($uploadId, 'upload_id');
    $store = _stattic_runtime_publish_sessions_store($privateRoot, $spaceId);
    _stattic_record_store_ensure($store);
    _stattic_record_store_sweep($store);
    if (!_stattic_record_store_claim($store, $uploadId, $record, (int) (strtotime((string) ($record['expires_at'] ?? '')) ?: time()))) {
        $existing = _stattic_record_store_get($store, $uploadId);
        if ($existing === null) {
            _stattic_problem_response(500, 'upload_session_create_failed', 'Could not create the publish session.');
        }
        return $existing;
    }
    _stattic_runtime_publish_session_write_pin($privateRoot, $spaceId, $uploadId, $record);
    return $record;
}

function _stattic_runtime_publish_session_load(string $privateRoot, string $spaceId, string $uploadId): ?array
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $uploadId = _stattic_runtime_id($uploadId, 'upload_id');
    $store = _stattic_runtime_publish_sessions_store($privateRoot, $spaceId);
    $session = _stattic_record_store_get($store, $uploadId);
    if ($session === null) {
        return null;
    }
    $expiresAt = strtotime((string) ($session['expires_at'] ?? ''));
    if ($expiresAt !== false && $expiresAt < time()) {
        _stattic_runtime_publish_session_release($privateRoot, $spaceId, $uploadId);
        return null;
    }
    return $session;
}

// Releasing drops the session and its pin. An unavailable stripe lock means the
// critical section never ran: retry once, then journal for retention to finish.
function _stattic_runtime_publish_session_release(string $privateRoot, string $spaceId, string $uploadId): void
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $uploadId = _stattic_runtime_id($uploadId, 'upload_id');
    $sessions = _stattic_runtime_publish_sessions_store($privateRoot, $spaceId);
    $release = static function () use ($privateRoot, $spaceId, $uploadId, $sessions): bool {
        return _stattic_record_store_mutate($sessions, $uploadId, static function (?array $_session) use ($privateRoot, $spaceId, $uploadId, $sessions): bool {
            _stattic_record_store_delete($sessions, $uploadId);
            _stattic_record_store_delete(
                _stattic_runtime_publish_pins_store($privateRoot, $spaceId),
                _stattic_runtime_publish_pin_id($uploadId),
            );
            return true;
        }) === true;
    };
    if ($release() || $release()) {
        return;
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'publish_session_release_deferred',
        'space_id' => $spaceId,
        'upload_id' => $uploadId,
        'reason' => 'session_lock_unavailable',
    ]);
}

function _stattic_runtime_publish_session_replace(string $privateRoot, string $spaceId, string $uploadId, callable $change): array
{
    $store = _stattic_runtime_publish_sessions_store($privateRoot, $spaceId);
    $updated = _stattic_record_store_mutate($store, $uploadId, static function (?array $session) use ($privateRoot, $spaceId, $uploadId, $store, $change): array {
        if ($session === null) {
            _stattic_problem_response(404, 'upload_not_found', 'Publish session not found.');
        }
        $next = $change($session);
        if (!is_array($next)) {
            _stattic_problem_response(500, 'upload_session_update_failed', 'Publish session update returned an invalid record.');
        }
        // json_decode(..., true) cannot tell an empty object from an empty
        // list, so a session with no accepted uploads would persist
        // `accepted: []`, which the Rust finalizer rejects.
        $next['accepted'] = _stattic_runtime_json_object(
            is_array($next['accepted'] ?? null) ? $next['accepted'] : []
        );
        // Pin first: a digest established by a path upload or URL fetch must be
        // protected before it becomes an accepted CAS object.
        _stattic_runtime_publish_session_write_pin($privateRoot, $spaceId, $uploadId, $next);
        _stattic_record_store_put($store, $uploadId, $next);
        return $next;
    });
    if (!is_array($updated)) {
        _stattic_problem_response(503, 'upload_session_lock_unavailable', 'Publish session is busy.');
    }
    return $updated;
}

function _stattic_runtime_publish_session_bind_sha(string $privateRoot, string $spaceId, string $uploadId, string $filePath, string $sha, int $size): array
{
    return _stattic_runtime_publish_session_replace($privateRoot, $spaceId, $uploadId, static function (array $session) use ($filePath, $sha, $size): array {
        $found = false;
        $manifest = is_array($session['manifest'] ?? null) ? $session['manifest'] : [];
        foreach ($manifest as $index => $entry) {
            if (!is_array($entry) || ($entry['path'] ?? null) !== $filePath) {
                continue;
            }
            $found = true;
            if ((int) ($entry['size'] ?? -1) !== $size) {
                _stattic_problem_response(422, 'upload_size_mismatch', 'Fetched file size does not match the manifest.', ['details' => ['path' => $filePath, 'declared_size' => (int) ($entry['size'] ?? -1), 'received_size' => $size]]);
            }
            $declared = is_string($entry['sha256'] ?? null) ? strtolower($entry['sha256']) : '';
            if ($declared !== '' && !hash_equals($declared, $sha)) {
                _stattic_problem_response(422, 'upload_hash_mismatch', 'Fetched file hash does not match the manifest.', ['details' => ['path' => $filePath, 'declared_sha256' => $declared, 'received_sha256' => $sha]]);
            }
            $manifest[$index]['sha256'] = $sha;
            break;
        }
        if (!$found) {
            _stattic_problem_response(422, 'upload_path_not_declared', 'Path ' . $filePath . ' was not declared in this version\'s manifest.', ['details' => ['path' => $filePath]]);
        }
        $session['manifest'] = $manifest;
        return $session;
    });
}

function _stattic_runtime_publish_session_accept(string $privateRoot, string $spaceId, string $uploadId, string $sha, int $size): array
{
    return _stattic_runtime_publish_session_accept_many($privateRoot, $spaceId, $uploadId, [$sha => $size]);
}

/** @param array<string,int> $shas */
function _stattic_runtime_publish_session_accept_many(string $privateRoot, string $spaceId, string $uploadId, array $shas): array
{
    return _stattic_runtime_publish_session_replace($privateRoot, $spaceId, $uploadId, static function (array $session) use ($shas): array {
        $declaredSizes = _stattic_runtime_publish_session_declared_sizes($session);
        $accepted = is_array($session['accepted'] ?? null) ? $session['accepted'] : [];
        foreach ($shas as $sha => $size) {
            if (!array_key_exists($sha, $declaredSizes)) {
                _stattic_problem_response(422, 'upload_sha_not_declared', 'Blob sha256 was not declared in this publish session.', ['details' => ['sha256' => $sha]]);
            }
            if ($declaredSizes[$sha] !== $size) {
                _stattic_problem_response(422, 'upload_size_mismatch', 'Blob size does not match the publish manifest.', ['details' => ['sha256' => $sha, 'declared_size' => $declaredSizes[$sha], 'received_size' => $size]]);
            }
            $accepted[$sha] = $size;
        }
        ksort($accepted, SORT_STRING);
        $session['accepted'] = _stattic_runtime_json_object($accepted);
        return $session;
    });
}

/** @return list<string> */
function _stattic_runtime_publish_session_missing_paths(array $session): array
{
    $accepted = is_array($session['accepted'] ?? null) ? $session['accepted'] : [];
    $missing = [];
    foreach ((is_array($session['manifest'] ?? null) ? $session['manifest'] : []) as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
            continue;
        }
        $sha = is_string($entry['sha256'] ?? null) ? strtolower($entry['sha256']) : '';
        $size = (int) ($entry['size'] ?? -1);
        if ($sha === '' || !array_key_exists($sha, $accepted) || (int) $accepted[$sha] !== $size) {
            $missing[] = $entry['path'];
        }
    }
    return $missing;
}

function _stattic_runtime_publish_session_require_complete(array $session): void
{
    $missing = _stattic_runtime_publish_session_missing_paths($session);
    if ($missing === []) {
        return;
    }
    $count = count($missing);
    $summary = array_slice($missing, 0, 20);
    _stattic_problem_response(
        409,
        'version_upload_incomplete',
        'Version upload has missing files: ' . implode(', ', $summary) . ($count > 20 ? ', ...' : ''),
        ['details' => ['missingPaths' => array_slice($missing, 0, 100), 'missingCount' => $count]],
    );
}

function _stattic_runtime_reject_s3_control_operation(string $allowedMethod): void
{
    if (_stattic_runtime_non_platform_upload_query() !== []) {
        _stattic_runtime_unsupported_s3_operation($allowedMethod);
    }
    foreach (['HTTP_X_AMZ_COPY_SOURCE', 'HTTP_X_AMZ_ACL', 'HTTP_X_AMZ_TAGGING', 'HTTP_X_AMZ_WEBSITE_REDIRECT_LOCATION'] as $header) {
        if (isset($_SERVER[$header]) && (string) $_SERVER[$header] !== '') {
            _stattic_runtime_unsupported_s3_operation($allowedMethod);
        }
    }
}

/** @return list<string> */
function _stattic_runtime_non_platform_upload_query(): array
{
    $unsupported = [];
    foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $part) {
        if ($part === '') {
            continue;
        }
        $name = rawurldecode(str_replace('+', '%20', explode('=', $part, 2)[0] ?? ''));
        if (!in_array($name, ['route', 'op', 'upload_id', 'path'], true)) {
            $unsupported[] = $name;
        }
    }
    return $unsupported;
}

function _stattic_runtime_unsupported_s3_operation(string $allowedMethod): never
{
    _stattic_problem_response(405, 'runtime_upload_operation_not_supported', 'Runtime upload only supports publish-session blob, file, and URL-fetch operations.', [], ['Allow' => $allowedMethod]);
}

function _stattic_runtime_upload_claim_session_id(array $claims): string
{
    $uploadId = is_string($claims['deploy_session_id'] ?? null) ? $claims['deploy_session_id'] : '';
    if ($uploadId === '') {
        _stattic_problem_response(403, 'upload_scope_forbidden', 'Upload token requires a deploy_session_id claim.');
    }
    return _stattic_runtime_id($uploadId, 'upload_id');
}

function _stattic_runtime_upload_session(string $privateRoot, string $uploadId, array $claims): array
{
    $uploadId = _stattic_runtime_id($uploadId, 'upload_id');
    $spaceId = is_string($claims['space_id'] ?? null) ? _stattic_runtime_id($claims['space_id'], 'space_id') : '';
    if ($spaceId === '') {
        _stattic_problem_response(403, 'upload_scope_forbidden', 'Upload token requires a space_id claim.');
    }
    $session = _stattic_runtime_publish_session_load($privateRoot, $spaceId, $uploadId);
    if ($session === null) {
        $descriptor = $claims['session'] ?? null;
        if (!is_array($descriptor)) {
            _stattic_problem_response(404, 'upload_not_found', 'Publish session not found.');
        }
        $session = _stattic_runtime_materialize_lazy_upload_session($privateRoot, $uploadId, $claims, $descriptor, false);
    }
    _stattic_runtime_assert_upload_scope($uploadId, $session, $claims);
    return $session;
}

function _stattic_runtime_upload_session_for_space(string $privateRoot, string $spaceId, array $claims): array
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $uploadId = _stattic_runtime_upload_claim_session_id($claims);
    if (!is_string($claims['space_id'] ?? null) || !hash_equals($spaceId, $claims['space_id'])) {
        _stattic_problem_response(403, 'upload_scope_forbidden', 'Upload token scope does not match this space.');
    }
    return [$uploadId, _stattic_runtime_upload_session($privateRoot, $uploadId, $claims)];
}

function _stattic_runtime_materialize_lazy_upload_session(string $privateRoot, string $uploadId, array $expected, array $descriptor, bool $includeRetained): array
{
    foreach (['upload_id' => $uploadId, 'space_id' => (string) ($expected['space_id'] ?? ''), 'version_id' => (string) ($expected['version_id'] ?? '')] as $key => $value) {
        $actual = is_string($descriptor[$key] ?? null) ? $descriptor[$key] : '';
        if ($value === '' || !hash_equals($value, $actual)) {
            _stattic_problem_response(403, 'upload_scope_forbidden', 'Upload token session descriptor does not match its scope (field: ' . $key . ').');
        }
    }
    $spaceId = _stattic_runtime_id((string) $expected['space_id'], 'space_id');
    $versionId = _stattic_runtime_id((string) $expected['version_id'], 'version_id');
    if (is_dir(_stattic_version_root($privateRoot, $spaceId, $versionId))) {
        _stattic_problem_response(409, 'version_already_committed', 'Version already exists.');
    }
    $manifest = _stattic_runtime_manifest_files($descriptor['files'] ?? []);
    $retained = $includeRetained ? _stattic_runtime_manifest_files($descriptor['retained_files'] ?? [], true) : [];
    $reusableVersionId = $includeRetained && is_string($descriptor['reusable_version_id'] ?? null)
        ? _stattic_runtime_id($descriptor['reusable_version_id'], 'reusable_version_id')
        : null;
    $record = [
        'upload_id' => $uploadId,
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'manifest' => $manifest,
        'accepted' => (object) [],
        'created_at' => gmdate('c'),
        'expires_at' => is_string($descriptor['expires_at'] ?? null) && strtotime($descriptor['expires_at']) !== false
            ? gmdate('c', (int) strtotime($descriptor['expires_at']))
            : gmdate('c', time() + STATTIC_RUNTIME_UPLOAD_SESSION_DEFAULT_TTL_SECONDS),
        'runtime_instance_id' => is_string($expected['runtime_instance_id'] ?? null) ? $expected['runtime_instance_id'] : null,
        'manifest_hash' => is_string($descriptor['manifest_hash'] ?? null) ? $descriptor['manifest_hash'] : null,
        'retained_files' => $retained,
        'reusable_version_id' => $reusableVersionId,
        // The PUT lane's descriptor carries no retention data; the full one
        // arrives with the finalize body.
        'retention' => $includeRetained
            ? _stattic_runtime_retention_mode($descriptor['retention'] ?? null, $reusableVersionId, $retained)
            : 'none',
        'metadata' => is_array($descriptor['metadata'] ?? null) ? $descriptor['metadata'] : [],
        'lazy' => true,
    ];
    $session = _stattic_runtime_publish_session_create($privateRoot, $spaceId, $uploadId, $record);
    if (($session['space_id'] ?? null) !== $spaceId || ($session['version_id'] ?? null) !== $versionId || ($session['manifest'] ?? null) !== $manifest) {
        _stattic_problem_response(403, 'upload_scope_forbidden', 'Durable publish session does not match the signed session descriptor.');
    }
    return $session;
}

function _stattic_runtime_assert_upload_scope(string $uploadId, array $session, array $claims): void
{
    foreach (['deploy_session_id' => $uploadId, 'space_id' => (string) ($session['space_id'] ?? ''), 'version_id' => (string) ($session['version_id'] ?? '')] as $key => $value) {
        $actual = isset($claims[$key]) ? (string) $claims[$key] : '';
        if ($actual === '' || !hash_equals($value, $actual)) {
            _stattic_problem_response(403, 'upload_scope_forbidden', 'Upload token scope does not match this session (claim: ' . $key . ').');
        }
    }
}

/**
 * The publish's retention intent, taken from the wire and NEVER inferred.
 *
 * 'all':  retain every path of `reusable_version_id`, materialized from that
 *         version's own catalog at finalize, under the space lock.
 * 'list': retain exactly `retained_files`. An EMPTY list is meaningful: it
 *         drops every path the base held.
 * 'none': retain nothing.
 *
 * Absent is legal only when no reusable version is named. Never derive 'all'
 * from "base named + empty list": the runtime has no delete list, so a deleted
 * path is exactly a path missing from the retained set.
 */
function _stattic_runtime_retention_mode(mixed $value, ?string $reusableVersionId, array $retainedFiles): string
{
    $mode = is_string($value) ? $value : null;
    if ($mode === null) {
        if ($reusableVersionId !== null) {
            _stattic_problem_response(422, 'retention_required', 'A reusable version requires an explicit retention mode.');
        }
        $mode = 'none';
    }
    if (!in_array($mode, ['all', 'list', 'none'], true)) {
        _stattic_problem_response(422, 'retention_invalid', 'Retention must be one of: all, list, none.');
    }
    if ($mode !== 'none' && $reusableVersionId === null) {
        _stattic_problem_response(422, 'reusable_version_required', 'Retention requires a reusable version.');
    }
    if ($mode !== 'list' && $retainedFiles !== []) {
        _stattic_problem_response(422, 'retention_invalid', 'Retained files require retention mode "list".');
    }
    return $mode;
}

function _stattic_runtime_manifest_files(mixed $files, bool $allowInternalArtifacts = false): array
{
    if (!is_array($files)) {
        _stattic_problem_response(422, 'invalid_files', 'Version files must be an array.');
    }
    // Nothing downstream re-checks file count or path length, so refuse an
    // unpublishable manifest here, before a session reserves a pin.
    if (count($files) > STATTIC_RUNTIME_MANIFEST_MAX_FILES) {
        _stattic_problem_response(413, 'manifest_too_many_files', 'Version manifest declares more than ' . STATTIC_RUNTIME_MANIFEST_MAX_FILES . ' files.', ['details' => ['file_count' => count($files), 'limit' => STATTIC_RUNTIME_MANIFEST_MAX_FILES]]);
    }
    $normalized = [];
    $seenPaths = [];
    $sizesBySha = [];
    foreach ($files as $file) {
        if (!is_array($file) || !is_string($file['path'] ?? null) || !isset($file['size'])) {
            _stattic_problem_response(422, 'invalid_file', 'Each version file requires path and size.');
        }
        $entry = ['path' => _stattic_runtime_file_path($file['path']), 'size' => max(0, (int) $file['size'])];
        if (!$allowInternalArtifacts || !_stattic_path_is_internal_artifact($entry['path'])) {
            _stattic_runtime_assert_static_upload_path($entry['path']);
        }
        if (strlen($entry['path']) > STATTIC_RUNTIME_MANIFEST_MAX_PATH_BYTES) {
            _stattic_problem_response(422, 'manifest_path_too_long', 'File paths support up to ' . STATTIC_RUNTIME_MANIFEST_MAX_PATH_BYTES . ' bytes in canonical form.', ['details' => ['path' => $entry['path'], 'bytes' => strlen($entry['path']), 'limit' => STATTIC_RUNTIME_MANIFEST_MAX_PATH_BYTES]]);
        }
        if ($entry['size'] > STATTIC_RUNTIME_MANIFEST_MAX_FILE_BYTES) {
            _stattic_problem_response(413, 'manifest_file_too_large', 'File ' . $entry['path'] . ' exceeds the ' . STATTIC_RUNTIME_MANIFEST_MAX_FILE_BYTES . ' byte per-file limit.', ['details' => ['path' => $entry['path'], 'size' => $entry['size'], 'limit' => STATTIC_RUNTIME_MANIFEST_MAX_FILE_BYTES]]);
        }
        if (isset($seenPaths[$entry['path']])) {
            _stattic_problem_response(422, 'manifest_duplicate_path', 'Version manifest declares the same canonical path twice.', ['details' => ['path' => $entry['path']]]);
        }
        $seenPaths[$entry['path']] = true;
        if (is_string($file['sha256'] ?? null)) {
            $sha = strtolower(trim($file['sha256']));
            if (!_stattic_is_sha256_hex($sha)) {
                _stattic_problem_response(422, 'invalid_blob_sha', 'File sha256 is invalid.', ['details' => ['path' => $entry['path']]]);
            }
            if (isset($sizesBySha[$sha]) && $sizesBySha[$sha] !== $entry['size']) {
                _stattic_problem_response(422, 'manifest_sha_size_conflict', 'One sha256 cannot declare multiple sizes.', ['details' => ['sha256' => $sha]]);
            }
            $sizesBySha[$sha] = $entry['size'];
            $entry['sha256'] = $sha;
        }
        if (is_string($file['contentType'] ?? null)) {
            $entry['contentType'] = $file['contentType'];
        }
        $normalized[] = $entry;
    }
    return $normalized;
}

// Every ingest lane charges the same per-space slot, but the call point differs
// per handler, so it stays explicit rather than a shared prologue hook.
function _stattic_runtime_upload_admit(string $privateRoot, string $spaceId): void
{
    _stattic_admission_acquire_or_shed($privateRoot, [
        'space_id' => $spaceId,
        'admission' => ['concurrency' => STATTIC_RUNTIME_INGEST_CONCURRENCY_PER_SPACE],
    ], 'ingest');
}

function _stattic_runtime_declared_upload_file(array $session, string $filePath): ?array
{
    return array_find(
        is_array($session['manifest'] ?? null) ? $session['manifest'] : [],
        static fn (mixed $entry): bool => is_array($entry) && ($entry['path'] ?? null) === $filePath
    );
}

function _stattic_runtime_canonical_upload_path(string $encodedPath): string
{
    $filePath = _stattic_runtime_file_path(rawurldecode($encodedPath));
    $canonicalEncodedPath = str_replace('%2F', '/', rawurlencode($filePath));
    if (!hash_equals($canonicalEncodedPath, $encodedPath)) {
        _stattic_problem_response(403, 'upload_path_not_canonical', 'Upload request path must use canonical encoding.');
    }
    return $filePath;
}

function _stattic_runtime_upload_blobs_have(string $privateRoot, string $spaceId, array $claims): void
{
    [$uploadId, $session] = _stattic_runtime_upload_session_for_space($privateRoot, $spaceId, $claims);
    $body = _stattic_json_body();
    $shas = $body['shas'] ?? null;
    if (!is_array($shas) || !array_is_list($shas)) {
        _stattic_problem_response(422, 'invalid_blob_shas', 'shas must be a JSON array.');
    }
    if (count($shas) > STATTIC_RUNTIME_UPLOAD_HAVE_MAX_SHAS) {
        _stattic_problem_response(413, 'blob_have_too_many_shas', 'Blob negotiation accepts at most 2048 sha256 values.');
    }
    $missing = [];
    $declaredSizes = _stattic_runtime_publish_session_declared_sizes($session);
    $accepted = [];
    foreach ($shas as $sha) {
        $sha = is_string($sha) ? strtolower($sha) : '';
        if (!_stattic_is_sha256_hex($sha)) {
            _stattic_problem_response(422, 'invalid_blob_sha', 'Blob sha256 is invalid.');
        }
        $residentSize = _stattic_runtime_blob_size($privateRoot, (string) $session['space_id'], $sha);
        $declaredSize = array_key_exists($sha, $declaredSizes) ? $declaredSizes[$sha] : null;
        // Negotiation answers "must you send this?". A resident object whose
        // length differs from the declared size is one only the publisher can
        // restore: report it missing so the bytes go back in flight, or the
        // finalizer refuses the version and no client action can fix it.
        if ($residentSize === null || ($declaredSize !== null && $residentSize !== $declaredSize)) {
            $missing[] = $sha;
        } elseif ($declaredSize !== null) {
            $accepted[$sha] = $declaredSize;
        }
    }
    if ($accepted !== []) {
        _stattic_runtime_publish_session_accept_many($privateRoot, (string) $session['space_id'], $uploadId, $accepted);
    }
    _stattic_json_response(200, ['missing' => $missing]);
}

// The one upload receipt: verified bytes committed to the CAS, the session
// credited, the ETag echoing the digest.
function _stattic_runtime_upload_accepted(string $privateRoot, string $spaceId, string $uploadId, string $tmpPath, string $sha, int $size): never
{
    _stattic_runtime_blob_commit_verified($privateRoot, $spaceId, $tmpPath, $sha);
    _stattic_runtime_publish_session_accept($privateRoot, $spaceId, $uploadId, $sha, $size);
    header('ETag: "' . $sha . '"', false);
    _stattic_json_response(200, ['ok' => true, 'sha256' => $sha, 'size' => $size]);
}

// Stage-and-verify for both body lanes: bytes staged under the declared size,
// then the staged length reconciled with it. $subject is the identifying detail
// (path or sha256) the problem documents lead with.
//
// @return array{0:string,1:int,2:string} tmp path, received size, sha256
function _stattic_runtime_upload_staged(string $privateRoot, string $noun, array $subject, int $declaredSize): array
{
    $streamed = _stattic_runtime_blob_stage_stream($privateRoot, _stattic_request_body_stream(), $declaredSize);
    if (($streamed['ok'] ?? false) !== true) {
        if (($streamed['reason'] ?? null) === 'too_large') {
            _stattic_problem_response(422, 'upload_size_mismatch', $noun . ' exceeds its declared size.', ['details' => $subject + ['declared_size' => $declaredSize, 'received_size' => (int) ($streamed['size'] ?? 0)]]);
        }
        _stattic_problem_response(500, 'upload_write_failed', $noun . ' bytes could not be staged.');
    }
    $tmpPath = (string) $streamed['tmp_path'];
    $receivedSize = (int) $streamed['size'];
    if ($receivedSize !== $declaredSize) {
        unlink($tmpPath);
        _stattic_problem_response(422, 'upload_size_mismatch', $noun . ' size does not match the publish manifest.', ['details' => $subject + ['declared_size' => $declaredSize, 'received_size' => $receivedSize]]);
    }

    return [$tmpPath, $receivedSize, strtolower((string) $streamed['sha256'])];
}

function _stattic_runtime_bulk_cas_problem(string $code, string $message, array $details = []): never
{
    _stattic_problem_response(
        str_contains($code, 'too_large') || str_contains($code, 'exceeded') ? 413 : 422,
        $code,
        $message,
        $details === [] ? [] : ['details' => $details],
    );
}

function _stattic_runtime_upload_bulk_cas(string $privateRoot, string $uploadId, array $claims): void
{
    if (!class_exists('ZipArchive')) {
        _stattic_problem_response(503, 'runtime_zip_extension_unavailable', 'Runtime zip support is unavailable.');
    }
    set_time_limit(150);
    $uploadId = _stattic_runtime_id($uploadId, 'upload_id');
    $session = _stattic_runtime_upload_session($privateRoot, $uploadId, $claims);
    $spaceId = (string) $session['space_id'];
    _stattic_runtime_upload_admit($privateRoot, $spaceId);

    $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (!is_string($declaredLength) || preg_match('/\A[1-9][0-9]*\z/', $declaredLength) !== 1) {
        _stattic_problem_response(411, 'bulk_cas_length_required', 'Bulk CAS upload requires Content-Length.');
    }
    if ((int) $declaredLength > STATTIC_RUNTIME_BULK_CAS_MAX_COMPRESSED_BYTES) {
        _stattic_runtime_bulk_cas_problem(
            'bulk_cas_archive_too_large',
            'Bulk CAS ZIP exceeds the compressed upload limit.',
            ['size' => (int) $declaredLength, 'limit' => STATTIC_RUNTIME_BULK_CAS_MAX_COMPRESSED_BYTES],
        );
    }
    $stagedArchive = _stattic_runtime_blob_stage_stream(
        $privateRoot,
        _stattic_request_body_stream(),
        STATTIC_RUNTIME_BULK_CAS_MAX_COMPRESSED_BYTES,
    );
    if (($stagedArchive['ok'] ?? false) !== true) {
        if (($stagedArchive['reason'] ?? null) === 'too_large') {
            _stattic_runtime_bulk_cas_problem(
                'bulk_cas_archive_too_large',
                'Bulk CAS ZIP exceeds the compressed upload limit.',
                ['limit' => STATTIC_RUNTIME_BULK_CAS_MAX_COMPRESSED_BYTES],
            );
        }
        _stattic_problem_response(500, 'bulk_cas_archive_write_failed', 'Bulk CAS ZIP could not be staged.');
    }
    $archivePath = (string) $stagedArchive['tmp_path'];
    $stagedBlobs = [];
    register_shutdown_function(static function () use ($archivePath, &$stagedBlobs): void {
        if (is_file($archivePath)) unlink($archivePath);
        foreach ($stagedBlobs as $staged) {
            $tmpPath = is_array($staged) ? ($staged['tmp_path'] ?? null) : null;
            if (is_string($tmpPath) && is_file($tmpPath)) unlink($tmpPath);
        }
    });
    if ((int) ($stagedArchive['size'] ?? -1) !== (int) $declaredLength) {
        _stattic_runtime_bulk_cas_problem(
            'bulk_cas_size_mismatch',
            'Bulk CAS ZIP size does not match Content-Length.',
            ['declared_size' => (int) $declaredLength, 'received_size' => (int) ($stagedArchive['size'] ?? -1)],
        );
    }

    $archive = new ZipArchive();
    if ($archive->open($archivePath, ZipArchive::RDONLY) !== true || $archive->numFiles < 1) {
        _stattic_runtime_bulk_cas_problem('bulk_cas_archive_invalid', 'Bulk CAS upload must be a non-empty ZIP archive.');
    }
    if ($archive->numFiles > STATTIC_RUNTIME_BULK_CAS_MAX_BLOBS) {
        _stattic_runtime_bulk_cas_problem(
            'bulk_cas_blob_count_exceeded',
            'Bulk CAS ZIP contains too many blobs.',
            ['count' => $archive->numFiles, 'limit' => STATTIC_RUNTIME_BULK_CAS_MAX_BLOBS],
        );
    }
    $declaredSizes = _stattic_runtime_publish_session_declared_sizes($session);
    $entries = [];
    $expandedBytes = 0;
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
        if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
            _stattic_runtime_bulk_cas_problem('bulk_cas_archive_invalid', 'Bulk CAS ZIP contains unreadable entry metadata.');
        }
        $sha = (string) $stat['name'];
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1) {
            _stattic_runtime_bulk_cas_problem(
                'bulk_cas_entry_invalid',
                'Bulk CAS ZIP entries must be named by their lowercase SHA-256.',
                ['entry' => $sha],
            );
        }
        if (isset($entries[$sha])) {
            _stattic_runtime_bulk_cas_problem(
                'bulk_cas_blob_duplicate',
                'Bulk CAS ZIP contains the same blob twice.',
                ['sha256' => $sha],
            );
        }
        $size = (int) ($stat['size'] ?? -1);
        if ($size < 0 || !array_key_exists($sha, $declaredSizes)) {
            _stattic_runtime_bulk_cas_problem(
                'bulk_cas_blob_not_declared',
                'Bulk CAS ZIP contains a blob that this publish session did not declare.',
                ['sha256' => $sha],
            );
        }
        if ($declaredSizes[$sha] !== $size) {
            _stattic_runtime_bulk_cas_problem(
                'bulk_cas_blob_size_mismatch',
                'Bulk CAS ZIP entry size does not match the publish manifest.',
                ['sha256' => $sha, 'declared_size' => $declaredSizes[$sha], 'received_size' => $size],
            );
        }
        if ((int) ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
            _stattic_runtime_bulk_cas_problem('bulk_cas_entry_invalid', 'Bulk CAS ZIP cannot contain encrypted entries.', ['sha256' => $sha]);
        }
        $expandedBytes += $size;
        if ($expandedBytes > STATTIC_RUNTIME_BULK_CAS_MAX_EXPANDED_BYTES) {
            _stattic_runtime_bulk_cas_problem(
                'bulk_cas_expanded_size_exceeded',
                'Bulk CAS ZIP expands beyond the upload limit.',
                ['size' => $expandedBytes, 'limit' => STATTIC_RUNTIME_BULK_CAS_MAX_EXPANDED_BYTES],
            );
        }
        $entries[$sha] = ['index' => $index, 'size' => $size];
    }

    foreach ($entries as $sha => $entry) {
        $stream = $archive->getStreamIndex((int) $entry['index'], ZipArchive::FL_UNCHANGED);
        if (!is_resource($stream)) {
            _stattic_runtime_bulk_cas_problem('bulk_cas_archive_invalid', 'Bulk CAS ZIP entry could not be read.', ['sha256' => $sha]);
        }
        $staged = _stattic_runtime_blob_stage_stream($privateRoot, $stream, (int) $entry['size']);
        if (($staged['ok'] ?? false) !== true || (int) ($staged['size'] ?? -1) !== (int) $entry['size']) {
            _stattic_runtime_bulk_cas_problem('bulk_cas_blob_size_mismatch', 'Bulk CAS ZIP entry bytes do not match their declared size.', ['sha256' => $sha]);
        }
        $actualSha = strtolower((string) ($staged['sha256'] ?? ''));
        if (!hash_equals($sha, $actualSha)) {
            _stattic_runtime_bulk_cas_problem(
                'bulk_cas_blob_sha_mismatch',
                'Bulk CAS ZIP entry bytes do not match their SHA-256 name.',
                ['declared_sha256' => $sha, 'received_sha256' => $actualSha],
            );
        }
        $stagedBlobs[$sha] = ['tmp_path' => (string) $staged['tmp_path'], 'size' => (int) $staged['size']];
    }
    $archive->close();
    unlink($archivePath);

    $accepted = [];
    foreach ($stagedBlobs as $sha => $staged) {
        _stattic_runtime_blob_commit_verified($privateRoot, $spaceId, (string) $staged['tmp_path'], $sha);
        $accepted[$sha] = (int) $staged['size'];
    }
    _stattic_runtime_publish_session_accept_many($privateRoot, $spaceId, $uploadId, $accepted);
    _stattic_json_response(200, [
        'ok' => true,
        'accepted' => ['blobs' => count($accepted), 'bytes' => array_sum($accepted)],
    ]);
}

// Shared prelude of both file lanes: resolves a path-addressed upload to its
// session, space and declared entry, admission included.
//
// @return array{0:string,1:string,2:string,3:array} upload id, space id, path, entry
function _stattic_runtime_upload_file_target(string $privateRoot, string $uploadId, string $encodedPath, array $claims): array
{
    $uploadId = _stattic_runtime_id($uploadId, 'upload_id');
    $session = _stattic_runtime_upload_session($privateRoot, $uploadId, $claims);
    $spaceId = (string) $session['space_id'];
    $filePath = _stattic_runtime_canonical_upload_path($encodedPath);
    $entry = _stattic_runtime_declared_upload_file($session, $filePath);
    if ($entry === null) {
        _stattic_problem_response(422, 'upload_path_not_declared', 'Path ' . $filePath . ' was not declared in this version\'s manifest.', ['details' => ['path' => $filePath]]);
    }
    _stattic_runtime_upload_admit($privateRoot, $spaceId);

    return [$uploadId, $spaceId, $filePath, $entry];
}

function _stattic_runtime_upload_blob(string $privateRoot, string $spaceId, string $sha, array $claims): void
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $sha = strtolower(trim($sha));
    if (!_stattic_is_sha256_hex($sha)) {
        _stattic_problem_response(422, 'invalid_blob_sha', 'Blob sha256 is invalid.');
    }
    [$uploadId, $session] = _stattic_runtime_upload_session_for_space($privateRoot, $spaceId, $claims);
    $declaredSize = _stattic_runtime_publish_session_declared_sizes($session)[$sha] ?? null;
    if ($declaredSize === null || $declaredSize < 0) {
        _stattic_problem_response(422, 'upload_sha_not_declared', 'Blob sha256 was not declared in this publish session.', ['details' => ['sha256' => $sha]]);
    }
    _stattic_runtime_upload_admit($privateRoot, $spaceId);
    [$tmpPath, $receivedSize, $actualSha] = _stattic_runtime_upload_staged($privateRoot, 'Blob', ['sha256' => $sha], $declaredSize);
    if (!hash_equals($sha, $actualSha)) {
        unlink($tmpPath);
        _stattic_problem_response(422, 'upload_hash_mismatch', 'Blob bytes do not match the URL sha256.', ['details' => ['declared_sha256' => $sha, 'received_sha256' => $actualSha]]);
    }
    _stattic_runtime_upload_accepted($privateRoot, $spaceId, $uploadId, $tmpPath, $sha, $receivedSize);
}

function _stattic_runtime_upload_file(string $privateRoot, string $uploadId, string $encodedPath, array $claims): void
{
    [$uploadId, $spaceId, $filePath, $entry] = _stattic_runtime_upload_file_target($privateRoot, $uploadId, $encodedPath, $claims);
    [$tmpPath, $receivedSize, $sha] = _stattic_runtime_upload_staged($privateRoot, 'File', ['path' => $filePath], (int) $entry['size']);
    _stattic_runtime_publish_session_bind_sha($privateRoot, $spaceId, $uploadId, $filePath, $sha, $receivedSize);
    _stattic_runtime_upload_accepted($privateRoot, $spaceId, $uploadId, $tmpPath, $sha, $receivedSize);
}

function _stattic_runtime_fetch_url_from_body(): array
{
    $body = _stattic_json_body();
    $url = is_string($body['url'] ?? null) ? trim($body['url']) : '';
    if ($url === '') {
        _stattic_problem_response(400, 'upload_source_url_required', 'URL upload requires a non-empty url.');
    }
    return _stattic_runtime_assert_fetch_url($url);
}

function _stattic_runtime_assert_fetch_url(string $url): array
{
    $parts = parse_url($url);
    $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
    $host = is_array($parts) && is_string($parts['host'] ?? null) ? trim($parts['host']) : '';
    if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        _stattic_problem_response(422, 'upload_source_url_invalid', 'URL uploads require an absolute HTTPS URL without credentials.');
    }
    $port = (int) ($parts['port'] ?? 443);
    if ($port < 1 || $port > 65535 || !_stattic_egress_host_allowed($host, $port)) {
        _stattic_problem_response(422, 'upload_source_url_forbidden', 'URL upload host is not allowed.');
    }
    $ips = _stattic_egress_resolve_public_ips($host, $port);
    if ($ips === null) {
        _stattic_problem_response(422, 'upload_source_url_unresolvable', 'URL upload host could not be resolved.');
    }
    return ['url' => $url, 'resolve' => _stattic_egress_curl_resolve_entries($host, $port, $ips)];
}

function _stattic_runtime_stream_url_to_tmp(array $source, string $tmpPath, int $limit): array
{
    $sink = _stattic_runtime_stream_sink_open($tmpPath, $limit, 0, 'x+b');
    if ($sink === false) {
        _stattic_problem_response(500, 'upload_write_failed', 'Could not stage fetched bytes.');
    }
    $status = 0;
    $tooLarge = false;
    $result = _stattic_http_request([
        'url' => (string) $source['url'],
        'headers' => ['Accept: */*', 'User-Agent: Spacefast-Runtime-Upload/1'],
        'connect_timeout' => STATTIC_RUNTIME_UPLOAD_SOURCE_URL_CONNECT_TIMEOUT_SECONDS,
        'timeout' => STATTIC_RUNTIME_UPLOAD_SOURCE_URL_TIMEOUT_SECONDS,
        'resolve' => is_array($source['resolve'] ?? null) ? $source['resolve'] : [],
        'on_headers' => static function (int $responseStatus, array $headerPairs) use (&$status, &$tooLarge, $limit): bool {
            $status = $responseStatus;
            foreach ($headerPairs as [$name, $value]) {
                if (strtolower($name) === 'content-length' && preg_match('/^[0-9]+$/', trim($value)) === 1 && (int) $value > $limit) {
                    $tooLarge = true;
                    return false;
                }
            }
            return true;
        },
        'sink' => static function (string $chunk) use ($sink, &$status, &$tooLarge): bool {
            if ($status < 200 || $status >= 300) {
                return true;
            }
            if (_stattic_runtime_stream_sink_write($sink, $chunk) === false) {
                $tooLarge = $sink->reason === 'too_large';
                return false;
            }
            return true;
        },
    ]);
    $streamed = $tooLarge ? _stattic_runtime_stream_sink_abort($sink, 'too_large') : _stattic_runtime_stream_sink_finish($sink);
    if ($tooLarge) {
        _stattic_problem_response(422, 'upload_size_mismatch', 'Fetched file exceeds the declared size.');
    }
    if (($streamed['ok'] ?? false) !== true) {
        _stattic_problem_response(500, 'upload_write_failed', 'Fetched bytes could not be written.');
    }
    if (($result['error'] ?? null) !== null || $status < 200 || $status >= 300) {
        unlink((string) $streamed['tmp_path']);
        _stattic_problem_response(422, 'upload_source_url_fetch_failed', 'Source URL did not return a successful response.');
    }
    return $streamed;
}

function _stattic_runtime_upload_file_from_url(string $privateRoot, string $uploadId, string $encodedPath, array $claims): void
{
    [$uploadId, $spaceId, $filePath, $entry] = _stattic_runtime_upload_file_target($privateRoot, $uploadId, $encodedPath, $claims);
    $source = _stattic_runtime_fetch_url_from_body();
    $stagingRoot = $privateRoot . '/runtime/blob-staging';
    _stattic_runtime_mkdir($stagingRoot);
    $tmpPath = $stagingRoot . '/url-' . bin2hex(random_bytes(12)) . '.tmp';
    // Problem documents exit the process, and PHP does not unwind `finally`
    // through exit(), so only a shutdown hook reclaims the staged bytes. On
    // success the file has already been renamed away.
    register_shutdown_function(static function () use ($tmpPath): void {
        if (is_file($tmpPath)) {
            unlink($tmpPath);
        }
    });
    $streamed = _stattic_runtime_stream_url_to_tmp($source, $tmpPath, (int) $entry['size']);
    $sha = strtolower((string) $streamed['sha256']);
    $size = (int) $streamed['size'];
    _stattic_runtime_publish_session_bind_sha($privateRoot, $spaceId, $uploadId, $filePath, $sha, $size);
    _stattic_runtime_upload_accepted($privateRoot, $spaceId, $uploadId, (string) $streamed['tmp_path'], $sha, $size);
}
