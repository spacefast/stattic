<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/safety.php';
require_once __DIR__ . '/upload-policy.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/generate.php';
require_once __DIR__ . '/finalize-rust.php';
require_once __DIR__ . '/space-archive.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/transfer.php';
require_once __DIR__ . '/tier.php';

const SPACEFAST_RUNTIME_WRITE_LOCK_TIMEOUT_MS = 10000;
const SPACEFAST_RUNTIME_WRITE_LOCK_RETRY_US = 100000;

// Management route handlers + write-lock primitives. Routing lives in the
// unified admin dispatcher (admin/api.php): its management-lane rows declare
// action/scope/lock/binary and lazily require this module when one matches.

function _stattic_runtime_with_write_lock(string $privateRoot, callable $callback): void
{
    $lockDir = $privateRoot . '/runtime';
    _stattic_runtime_mkdir($lockDir);
    _stattic_runtime_acquire_write_lock($lockDir . '/write.lock', $callback);
}

// Per-space write lock (runtime hardening — production incident commit
// a06d0571c: one site-wide flock serialized EVERY mutating management route
// across every space on a many-space shared site). A route earns this ONLY
// when every write its handler performs — including everything it calls
// transitively — stays confined to that one space's own storage
// (spaces/{spaceId}/...). See _stattic_runtime_space_write_lock_scope below
// for the write-target evidence behind each route's classification. Same
// timeout/retry/journaling semantics as the site-wide lock, just scoped to
// spaces/{spaceId}/write.lock so unrelated spaces never contend.
function _stattic_runtime_with_space_write_lock(string $privateRoot, string $spaceId, callable $callback): void
{
    $lockDir = _spacefast_space_root($privateRoot, $spaceId);
    _stattic_runtime_mkdir($lockDir);
    _stattic_runtime_acquire_write_lock($lockDir . '/write.lock', $callback);
}

// The route index (routes/current.php + immutable generations) is the ONE
// site-shared artifact per-space mutations still write. It takes its own
// dedicated, always-innermost lock (acquired INSIDE
// _stattic_runtime_update_route_index / _stattic_runtime_rebuild_route_index,
// see generate.php) so a space-locked mutation (route PUT, finalize+activate)
// and a site-locked one (space delete, import step) on another space serialize
// their generation writes against each other without sharing the outer site
// lock. Lock ordering is strictly site -> space -> index; every acquire path
// follows it, so no cycle exists.
function _stattic_runtime_with_route_index_lock(string $privateRoot, callable $callback): void
{
    $lockDir = $privateRoot . '/routes';
    _stattic_runtime_mkdir($lockDir);
    _stattic_runtime_acquire_write_lock($lockDir . '/index.lock', $callback);
}

// Per-request re-entrancy tracking: flock() calls on two handles to the SAME
// file conflict even within one process, so a handler that already holds a
// lock and reaches a nested acquire of that same lock (a space-locked route
// PUT whose config carries revocations reaches
// _stattic_runtime_store_revocations_replace, which takes the same space
// lock; an index update falling back to a full rebuild re-enters the index
// lock) would self-deadlock into the 10s timeout 503. Held paths are tracked
// per request (statics reset per request under FPM and the CLI server; a
// handler exiting mid-callback releases the flock with the request anyway).
function &_stattic_runtime_held_write_lock_paths(): array
{
    static $held = [];
    return $held;
}

// Shared flock-with-timeout body for the site-wide, per-space, and
// route-index write locks: identical retry cadence, identical 503
// runtime_write_lock_unavailable contract (apps/control-plane treats this
// code as retryable), identical exclusive-then-unlock discipline. Re-entrant
// per request: an acquire of an already-held path runs the callback inline.
function _stattic_runtime_acquire_write_lock(string $lockPath, callable $callback): void
{
    _stattic_runtime_assert_private_path($lockPath);
    $held = &_stattic_runtime_held_write_lock_paths();
    if (isset($held[$lockPath])) {
        $callback();
        return;
    }
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) {
        _stattic_json_response(503, ['error' => ['code' => 'runtime_write_lock_unavailable', 'message' => 'Runtime write lock is unavailable.']]);
    }

    $deadline = microtime(true) + (SPACEFAST_RUNTIME_WRITE_LOCK_TIMEOUT_MS / 1000);
    while (!flock($handle, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            fclose($handle);
            _stattic_json_response(503, [
                'error' => [
                    'code' => 'runtime_write_lock_unavailable',
                    'message' => 'Runtime write lock is unavailable.',
                    'details' => ['timeout_ms' => SPACEFAST_RUNTIME_WRITE_LOCK_TIMEOUT_MS],
                ],
            ]);
        }
        usleep(SPACEFAST_RUNTIME_WRITE_LOCK_RETRY_US);
    }

    $held[$lockPath] = true;
    try {
        $callback();
    } finally {
        unset($held[$lockPath]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// Per-space vs site-wide write-lock classification. A route earns the
// per-space lock ONLY when every write its handler performs — including
// everything it calls transitively — is confined to that one space's own
// storage (plus the two self-serialized shared artifacts below), AND the
// space id the dispatcher would lock on is guaranteed to be the space the
// handler actually mutates. Two shared artifacts no longer force a route onto
// the site lock, because they serialize themselves:
//   - the journal (runtime/journal.jsonl): appended via
//     file_put_contents(FILE_APPEND | LOCK_EX) — atomic without any outer
//     lock (see _stattic_runtime_append_journal);
//   - the route index (routes/current.php + generations): every writer goes
//     through _stattic_runtime_update_route_index /
//     _stattic_runtime_rebuild_route_index, which take their own innermost
//     routes/index.lock (see _stattic_runtime_with_route_index_lock).
// Callback spool files are content-addressed one-shot writes and need no
// lock. Verified against actual write targets (not the route's name):
//
//   revoke_grant / unrevoke_grant (_stattic_runtime_update_access_revocations)
//     -> write only spaces/{spaceId}/revocations.json for the URL/JWT-scoped
//        space, never touch the route index. SPACE-SCOPED. This is exactly
//        the pair behind the production contention (a06d0571c): instant
//        sharing revokes racing same-site CI deploys.
//   update_route (_stattic_runtime_put_route) -> route pointer json, unified
//     access policy/secrets/entitlements json, revocations (nested space-lock
//     RMW — re-entrant no-op now that the dispatcher already holds it),
//     hostname intent json: all spaces/{spaceId}/-confined; then journal +
//     index (self-serialized above). SPACE-SCOPED. This is the "tiny config
//     PUT" that used to queue for minutes behind another space's finalize on
//     the same shared site.
//   update_hostname_intent, update_tombstones, update_retention_policy ->
//     spaces/{spaceId}/hostname-intent.json / tombstones.json /
//     retention-policy.json + journal (+ index for the first two).
//     SPACE-SCOPED for the same reason.
//   finalize_version (_stattic_runtime_finalize_version) -> write-audited
//     end-to-end: version-tree writes (files/, files-original/, files-variants/,
//     pages/, zero/ incl. the .runtime-compiler-* temp dir, metadata.json,
//     serving/php-manifest/headers/redirects artifacts, file-shards) are all
//     spaces/{spaceId}/versions/{versionId}/-confined; the per-space blob CAS
//     (spaces/{spaceId}/blobs/, race-safe content-addressed rename — the
//     local blob GC in tier.php keys its per-blob exclusion on THIS
//     per-space lock, so finalize's blob_has -> blob_put -> blob_link and
//     delete_version's nlink teardown stay atomic against reclamation) and the
//     finalize+activate pointer flip (route pointer + unified access +
//     hostname intent, all space files; nested space-lock RMW for revocations
//     is a re-entrant no-op) are space-confined too. The shared writes are
//     exactly the self-serialized artifacts above (journal, route index) plus
//     one-shot randomly/content-addressed-named files (runtime/blob-staging/*,
//     runtime/link-probe/*, runtime/callbacks/pending/{eventId}.json) and the
//     upload-session teardown (runtime/uploads/{uploadId} — owned by exactly
//     this space's session, validated against the URL space_id; upload-lane
//     writers never held the management lock anyway). The idempotent-retry
//     path re-runs only the pointer flip. Nothing downstream ever acquires
//     the site lock (ordering invariant holds). SPACE-SCOPED — this was the
//     minutes-long site-lock hold that queued every other space's config PUT.
//   delete_version (_stattic_runtime_delete_version) -> shard reads, then
//     rm of spaces/{spaceId}/versions/{versionId}, journal + callback spool
//     (self-serialized), index update (index lock). SPACE-SCOPED.
//   map_space_import / step_space_import (import.php) -> their writes are
//     space-confined, but the space they mutate is the job's owning space
//     from runtime/space-imports/{importId}/status.json, which the URL/JWT
//     space_id of the space-nested route variants is NOT validated against —
//     locking on the request's space id could lock the WRONG space. Imports
//     are rare, heavyweight operations, so they keep the SITE-WIDE lock
//     rather than duplicating the handler's owner resolution here.
//   delete_space -> stays SITE-WIDE: its rm_recursive of the space root
//     deletes spaces/{spaceId}/write.lock itself. Under a per-space lock a
//     later same-space request would fopen() a FRESH inode and lock that,
//     while an in-flight holder still holds the unlinked inode's flock — two
//     "holders" at once. The site lock file lives outside the space tree, so
//     deletes keep it.
//   repair_space -> stays SITE-WIDE: the recovery hammer. It rebuilds the
//     full cross-space index and clears the site-wide runtime/repair-state.json;
//     serializing it against every mutation is the point of running a repair.
//   transfer_bundle / transfer_commit / transfer_abort -> stay SITE-WIDE:
//     their space id rides the request BODY (job payload), not the URL scope
//     the dispatcher locks on ($required carries no space_id), and commit
//     installs whole version trees plus transfer-staging state.
//
// Same-space overlap between a SPACE-SCOPED route and a same-space SITE-WIDE
// route (e.g. finalize_version racing delete_space on the same space) is not
// excluded by these locks — but the control plane already serializes runtime
// work per space (runtime-delivery capabilities), every store here is an
// atomic full-replace (last-writer-wins, no torn reads), the one true RMW
// (revocations) keeps its own space lock on BOTH paths, and both paths end by
// recompiling the index from on-disk state under the index lock, so the last
// writer converges it.
//
// Returns the space id to lock on, or null for the site-wide lock. Any
// unresolvable scope (should not happen for the classified actions) also
// falls back to null — correctness over concurrency.
const STATTIC_RUNTIME_SPACE_SCOPED_LOCK_ACTIONS = [
    'revoke_grant',
    'unrevoke_grant',
    'update_route',
    'update_hostname_intent',
    'update_tombstones',
    'update_retention_policy',
    'finalize_version',
    'delete_version',
];

function _stattic_runtime_space_write_lock_scope(string $action, array $required): ?string
{
    if (!in_array($action, STATTIC_RUNTIME_SPACE_SCOPED_LOCK_ACTIONS, true)) {
        return null;
    }
    if (isset($required['space_id']) && is_string($required['space_id']) && $required['space_id'] !== '') {
        return $required['space_id'];
    }
    return null;
}

function _stattic_runtime_create_version(string $privateRoot, string $spaceId, array $claims): void
{
    _stattic_runtime_cleanup_stale_uploads($privateRoot);
    $body = _stattic_json_body();
    $versionId = isset($body['version_id']) && is_string($body['version_id'])
        ? _stattic_runtime_id($body['version_id'], 'version_id')
        : _stattic_runtime_new_id('dep');
    if (is_dir(_spacefast_version_root($privateRoot, $spaceId, $versionId))) {
        _stattic_json_response(409, ['error' => ['code' => 'version_already_committed', 'message' => 'Version already exists.']]);
    }
    $files = _stattic_runtime_manifest_files($body['files'] ?? []);
    $retainedFiles = _stattic_runtime_manifest_files($body['retained_files'] ?? []);
    $reusableVersionId = isset($body['reusable_version_id']) && is_string($body['reusable_version_id'])
        ? _stattic_runtime_id($body['reusable_version_id'], 'reusable_version_id')
        : null;
    if ($retainedFiles !== [] && $reusableVersionId === null) {
        _stattic_json_response(422, ['error' => ['code' => 'reusable_version_required', 'message' => 'Retained files require a reusable version.']]);
    }
    $sessionMode = isset($body['session_mode']) && is_string($body['session_mode']) ? $body['session_mode'] : 'declared';
    if (!in_array($sessionMode, ['declared', 'open'], true)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_session_mode', 'message' => 'session_mode must be "declared" or "open".']]);
    }
    if ($sessionMode === 'open' && ($files !== [] || $retainedFiles !== [] || $reusableVersionId !== null)) {
        _stattic_json_response(422, ['error' => ['code' => 'open_session_manifest_not_allowed', 'message' => 'Open upload sessions do not declare a file manifest.']]);
    }
    // Open-session plan-policy caps live in session state, never in the upload JWT.
    $sessionCaps = [];
    foreach (['max_total_bytes', 'max_file_count'] as $cap) {
        if (!array_key_exists($cap, $body)) {
            continue;
        }
        if ($sessionMode !== 'open' || !is_int($body[$cap]) || $body[$cap] <= 0) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_session_cap', 'message' => 'Session caps are positive integers and apply only to open sessions.']]);
        }
        $sessionCaps[$cap] = $body[$cap];
    }
    $conventionFiles = _stattic_runtime_management_convention_files($body['convention_files'] ?? null);
    $uploadId = _stattic_runtime_new_id('upl');
    $createdAt = gmdate('c');
    // Session state stores the full runtime truth (spec "Upload Contract"): runtime
    // instance id, manifest hash, and expiry ride alongside the declared files so
    // resume can be answered from this state alone.
    $expiresAt = isset($body['expires_at']) && is_string($body['expires_at']) && strtotime($body['expires_at']) !== false
        ? gmdate('c', (int) strtotime($body['expires_at']))
        : gmdate('c', time() + 86400);
    $manifestHash = isset($body['manifest_hash']) && is_string($body['manifest_hash']) ? $body['manifest_hash'] : null;
    $uploadRoot = $privateRoot . '/runtime/uploads/' . $uploadId;
    _stattic_runtime_mkdir($uploadRoot . '/files');
    _stattic_runtime_write_json_atomic($uploadRoot . '/session.json', [
        'upload_id' => $uploadId,
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'runtime_instance_id' => is_string($claims['runtime_instance_id'] ?? null) ? $claims['runtime_instance_id'] : null,
        'session_mode' => $sessionMode,
        ...$sessionCaps,
        'created_at' => $createdAt,
        'expires_at' => $expiresAt,
        'manifest_hash' => $manifestHash,
        'files' => $files,
        'retained_files' => $retainedFiles,
        'reusable_version_id' => $reusableVersionId,
        'metadata' => is_array($body['metadata'] ?? null) ? $body['metadata'] : [],
        'convention_files' => $conventionFiles,
    ]);

    _stattic_runtime_record_management_event($privateRoot, $claims, [
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
        'session_mode' => $sessionMode,
        'created_at' => $createdAt,
    ]);
}

function _stattic_runtime_upload_session_not_found(): never
{
    _stattic_json_response(404, ['error' => [
        'code' => 'version_upload_not_found',
        'message' => 'Version upload session not found: sessions are deleted after finalize and expire (default 24h). Create a new version (POST /spaces/{spaceId}/versions) to obtain a fresh upload_id.',
    ]]);
    exit;
}

// Runtime-truth upload session state (spec "Upload Contract" resume): which declared
// files are committed, which are still missing, and which chunked uploads have staged
// parts. The control plane's resume endpoint consumes this instead of guessing from
// its own pending-upload state.
function _stattic_runtime_get_upload_session(string $privateRoot, string $spaceId, string $versionId): void
{
    $session = null;
    $uploadId = null;
    foreach (glob($privateRoot . '/runtime/uploads/*/session.json') ?: [] as $sessionPath) {
        $candidate = _stattic_runtime_read_json($sessionPath);
        if (is_array($candidate) && ($candidate['space_id'] ?? null) === $spaceId && ($candidate['version_id'] ?? null) === $versionId) {
            $session = $candidate;
            $uploadId = basename(dirname((string) $sessionPath));
            break;
        }
    }
    if (!is_array($session) || !is_string($uploadId)) {
        _stattic_runtime_upload_session_not_found();
    }
    $uploaded = _stattic_runtime_uploaded_files($privateRoot, $uploadId);
    $files = [];
    $pending = [];
    if (_stattic_runtime_upload_session_mode($session) === 'open') {
        foreach ($uploaded as $path => $entry) {
            $files[] = ['path' => $path, 'size' => (int) ($entry['size'] ?? 0), 'uploaded' => true];
        }
    } else {
        foreach ((is_array($session['files'] ?? null) ? $session['files'] : []) as $declared) {
            if (!is_array($declared) || !is_string($declared['path'] ?? null)) {
                continue;
            }
            $entry = [
                'path' => $declared['path'],
                'size' => (int) ($declared['size'] ?? 0),
                'uploaded' => isset($uploaded[$declared['path']]),
            ];
            if (is_string($declared['sha256'] ?? null)) {
                $entry['sha256'] = $declared['sha256'];
            }
            $files[] = $entry;
            if (!$entry['uploaded']) {
                $pending[] = $declared['path'];
            }
        }
    }
    $chunks = [];
    foreach (glob($privateRoot . '/runtime/uploads/' . $uploadId . '/parts/*', GLOB_ONLYDIR) ?: [] as $partRoot) {
        $pathRecord = _stattic_runtime_read_json($partRoot . '/path.json');
        $partPath = is_array($pathRecord) && is_string($pathRecord['path'] ?? null) ? $pathRecord['path'] : null;
        if ($partPath === null) {
            continue;
        }
        $partNumbers = [];
        foreach (glob($partRoot . '/*.part') ?: [] as $part) {
            $partNumbers[] = (int) basename((string) $part, '.part');
        }
        sort($partNumbers, SORT_NUMERIC);
        $chunks[$partPath] = $partNumbers;
    }
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'upload_id' => $uploadId,
        'session_mode' => _stattic_runtime_upload_session_mode($session),
        'created_at' => is_string($session['created_at'] ?? null) ? $session['created_at'] : null,
        'expires_at' => is_string($session['expires_at'] ?? null) ? $session['expires_at'] : null,
        'manifest_hash' => is_string($session['manifest_hash'] ?? null) ? $session['manifest_hash'] : null,
        'files' => $files,
        'pending_paths' => $pending,
        'chunks' => _stattic_runtime_json_object($chunks),
    ]);
}

function _stattic_runtime_finalize_version(string $privateRoot, string $spaceId, string $versionId, array $claims): void
{
    $body = _stattic_json_body();
    $bodyConventionFiles = _stattic_runtime_management_convention_files($body['convention_files'] ?? null);
    $zeroMode = _stattic_runtime_zero_mode($body['zero_mode'] ?? null);
    $uploadId = isset($body['upload_id']) && is_string($body['upload_id'])
        ? _stattic_runtime_id($body['upload_id'], 'upload_id')
        : '';
    $sessionPath = $privateRoot . '/runtime/uploads/' . $uploadId . '/session.json';
    $session = $uploadId !== '' ? _stattic_runtime_read_json($sessionPath) : null;
    if (!is_array($session) || ($session['space_id'] ?? null) !== $spaceId || ($session['version_id'] ?? null) !== $versionId) {
        _stattic_runtime_finalize_idempotent_ready_response($privateRoot, $spaceId, $versionId, $body, $claims);
        _stattic_runtime_upload_session_not_found();
    }

    // Rust owns the whole structural build — file commit, transforms,
    // conventions, Zero compile, artifacts, and validation. PHP keeps only
    // activation, event, and response orchestration.
    {
        $finalized = _stattic_runtime_finalize_with_rust($privateRoot, $spaceId, $versionId, $uploadId, $session, $body);
        $versionRoot = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId;
        $zeroFinalize = is_array($body['zero'] ?? null) ? $body['zero'] : null;
        if ($zeroFinalize !== null) {
            _stattic_runtime_write_zero_config_artifact($versionRoot, $zeroFinalize);
        }
        if ($zeroMode !== 'active') {
            _stattic_runtime_deactivate_imported_zero_dispatch($versionRoot);
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
            $activation = _stattic_runtime_activation_with_finalized_access($body['activate'], $finalized);
            $routeName = _stattic_runtime_activation_route_name($activation);
            $pointer = _stattic_runtime_write_route_pointer($privateRoot, $spaceId, $routeName, $versionId, $activation, $claims, true);
            $changedPaths = is_array($pointer['changed_paths'] ?? null) ? $pointer['changed_paths'] : [];
            $finalizedEvent['route_name'] = $routeName;
            if ($changedPaths !== []) {
                $finalizedEvent['changed_paths'] = $changedPaths;
            }
            if (is_string($activation['previous_version_id'] ?? null) && $activation['previous_version_id'] !== '') {
                $finalizedEvent['previous_version_id'] = $activation['previous_version_id'];
            }
        }
        _stattic_runtime_record_management_event($privateRoot, $claims, $finalizedEvent);
        _stattic_runtime_rm_recursive($privateRoot . '/runtime/uploads/' . $uploadId);
        _stattic_runtime_finalize_ready_response(
            $spaceId,
            $versionId,
            $versionRoot,
            is_array($finalized['manifest'] ?? null) ? $finalized['manifest'] : null
        );
    }
}

// Native finalize only: when finalize and activation share one runtime
// request, the Rust-generated file-lane Basic Auth rules and verifier hashes
// must enter the same unified policy write as the route pointer
// (config.policy/config.secrets -> policy.json/policy-secrets.json). Authored
// rules stay first so an explicit allow carve-out wins before a broader
// generated challenge; a generated rule id replaces a stale caller copy of
// that same rule — the Rust-produced rule + hash are authoritative.
function _stattic_runtime_activation_with_finalized_access(array $activation, array $finalized): array
{
    $generatedRules = is_array($finalized['accessRules'] ?? null)
        ? array_values($finalized['accessRules'])
        : [];
    $generatedSecrets = is_array($finalized['accessSecrets'] ?? null)
        ? $finalized['accessSecrets']
        : [];
    if ($generatedRules === [] && $generatedSecrets === []) {
        return $activation;
    }

    $config = is_array($activation['config'] ?? null) ? $activation['config'] : [];
    $policy = is_array($config['policy'] ?? null) ? $config['policy'] : [];
    $existingRules = is_array($policy['rules'] ?? null) ? array_values($policy['rules']) : [];
    $generatedIds = [];
    foreach ($generatedRules as $rule) {
        if (is_array($rule) && is_string($rule['id'] ?? null) && $rule['id'] !== '') {
            $generatedIds[$rule['id']] = true;
        }
    }
    if ($generatedIds !== []) {
        $existingRules = array_values(array_filter(
            $existingRules,
            static fn (mixed $rule): bool => !is_array($rule)
                || !is_string($rule['id'] ?? null)
                || !isset($generatedIds[$rule['id']])
        ));
    }
    if ($generatedRules !== []) {
        $policy['rules'] = array_merge($existingRules, $generatedRules);
        $config['policy'] = $policy;
    }
    if ($generatedSecrets !== []) {
        $existingSecrets = is_array($config['secrets'] ?? null) ? $config['secrets'] : [];
        $config['secrets'] = array_replace($existingSecrets, $generatedSecrets);
    }
    $activation['config'] = $config;
    return $activation;
}

function _stattic_runtime_activation_route_name(array $activation): string
{
    return isset($activation['route_name']) && is_string($activation['route_name'])
        ? _stattic_runtime_id($activation['route_name'], 'route_name')
        : 'production';
}

// $manifest is the manifest the caller just committed into metadata.json — pass
// it to skip reading the (largest) artifact straight back off disk. Callers
// without it (the idempotent retry) recover it from the stored metadata.
function _stattic_runtime_finalize_ready_response(string $spaceId, string $versionId, string $versionRoot, ?array $manifest = null): never
{
    $metadata = _stattic_runtime_read_json($versionRoot . '/metadata.json');
    if ($manifest === null) {
        $manifest = is_array($metadata) && is_array($metadata['manifest'] ?? null)
            ? $metadata['manifest']
            : (
                is_array($metadata) && is_array($metadata['files'] ?? null)
                    ? _stattic_runtime_finalize_manifest($metadata['files'])
                    : []
            );
    }
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'status' => 'ready',
        'zero_endpoint_count' => _stattic_runtime_zero_endpoint_count($versionRoot),
        'access_rules' => is_array($metadata) && is_array($metadata['accessRules'] ?? null)
            ? array_values($metadata['accessRules'])
            : [],
        'access_secrets' => is_array($metadata) && is_array($metadata['accessSecrets'] ?? null)
            ? _stattic_runtime_json_object($metadata['accessSecrets'])
            : _stattic_runtime_json_object([]),
        'manifest' => $manifest,
    ]);
}

function _stattic_runtime_finalize_manifest(array $filesByPath): array
{
    $manifest = [];
    foreach ($filesByPath as $path => $file) {
        if (!is_string($path) || !is_array($file)) {
            continue;
        }
        $manifest[] = [
            'path' => $path,
            'size' => max(0, (int) ($file['size'] ?? 0)),
            'sha256' => is_string($file['sha256'] ?? null) ? $file['sha256'] : '',
            'contentType' => is_string($file['mime'] ?? null)
                ? $file['mime']
                : 'application/octet-stream',
        ];
    }
    usort($manifest, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    return $manifest;
}

function _stattic_runtime_finalize_idempotent_ready_response(string $privateRoot, string $spaceId, string $versionId, array $body = [], array $claims = []): void
{
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $versionId);
    $metadata = _stattic_runtime_read_json($versionRoot . '/metadata.json');
    if (
        !is_array($metadata)
        || ($metadata['spaceId'] ?? null) !== $spaceId
        || ($metadata['versionId'] ?? null) !== $versionId
        || !is_file($versionRoot . '/serving.php')
    ) {
        return;
    }

    // A retried finalize+activate must still converge the route pointer: the
    // first attempt may have died after finalizing artifacts (upload session
    // already removed) but before the pointer write. The conditional write
    // no-ops when the pointer already landed with the same config_digest and
    // hostname intent, so a pure duplicate retry journals nothing.
    if (isset($body['activate']) && is_array($body['activate'])) {
        $activation = _stattic_runtime_activation_with_finalized_access($body['activate'], $metadata);
        _stattic_runtime_write_route_pointer($privateRoot, $spaceId, _stattic_runtime_activation_route_name($activation), $versionId, $activation, $claims, true);
    }

    _stattic_runtime_finalize_ready_response($spaceId, $versionId, $versionRoot);
}

function _stattic_runtime_zero_endpoint_count(string $versionRoot): int
{
    $index = _stattic_runtime_read_json($versionRoot . '/zero/endpoints-index.json');
    return is_array($index) && is_array($index['endpoints'] ?? null) ? count($index['endpoints']) : 0;
}

// Recovers the bytes of a retained file whose reusable-version hardlink is
// gone (the version was demoted and its tree unlinked). Resolution order:
// the space's own blob store (Tier A demote before local blob GC), then the
// cold bucket via the reusable version's shard locator. Returns a readable
// path inside private storage (the blob-store file) or null when the bytes
// genuinely cannot be recovered — the caller then 409s exactly as before.
// The returned blob path stays alive only because the caller (finalize,
// space-scoped dispatch) holds spaces/{spaceId}/write.lock — the local blob
// GC's per-blob barrier — until it hardlinks the blob into the version tree.
function _stattic_runtime_retained_file_fallback_source(string $privateRoot, string $spaceId, string $reusableVersionId, string $path, array $file): ?string
{
    $shardPath = _spacefast_version_root($privateRoot, $spaceId, $reusableVersionId)
        . '/file-shards/' . substr(hash('sha256', $path), 0, 2) . '.php';
    $loaded = is_file($shardPath) ? @include $shardPath : null;
    $meta = is_array($loaded) && is_array($loaded['files'] ?? null) && is_array($loaded['files'][$path] ?? null)
        ? $loaded['files'][$path]
        : null;
    $shardSha = is_array($meta) && is_string($meta['sha256'] ?? null) ? strtolower($meta['sha256']) : '';
    $declaredSha = is_string($file['sha256'] ?? null) ? strtolower($file['sha256']) : '';
    $sha = $declaredSha !== '' ? $declaredSha : $shardSha;
    if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
        return null;
    }
    // Shard metadata is immutable after finalize; a declared sha that
    // contradicts it means the dedupe request is stale — let the caller 409.
    if ($declaredSha !== '' && $shardSha !== '' && !hash_equals($shardSha, $declaredSha)) {
        return null;
    }
    if (_stattic_runtime_blob_has($privateRoot, $spaceId, $sha)) {
        return _stattic_runtime_blob_path($privateRoot, $spaceId, $sha);
    }
    $remote = is_array($meta) && is_array($meta['remote'] ?? null) ? $meta['remote'] : null;
    $bucketId = is_array($remote) && is_string($remote['bucket'] ?? null) ? $remote['bucket'] : '';
    if ($bucketId === '' || _stattic_s3_bucket_row($bucketId) === null) {
        return null;
    }
    $dest = $privateRoot . '/runtime/blob-staging/retained-' . bin2hex(random_bytes(12));
    _stattic_runtime_mkdir(dirname($dest));
    // Key derived from the sha (per-space CAS keyspace), never trusted from
    // the shard verbatim; the streamed GET verifies the sha as it downloads.
    $results = _stattic_s3_multi_get([[
        'id' => $sha,
        'bucket' => $bucketId,
        'key' => _stattic_transfer_blob_key($spaceId, $sha),
        'dest_path' => $dest,
        'sha256' => $sha,
    ]], 1);
    if ((($results[$sha]['ok'] ?? false) !== true) || !is_file($dest)) {
        @unlink($dest);
        return null;
    }
    // Land it in the blob store (verifies the sha again, dedupes) so repeated
    // retained paths with the same content fetch once.
    _stattic_runtime_blob_put($privateRoot, $spaceId, $dest, $sha);
    return _stattic_runtime_blob_has($privateRoot, $spaceId, $sha)
        ? _stattic_runtime_blob_path($privateRoot, $spaceId, $sha)
        : null;
}

// Commits one source file into a version's files tree: verify/hash, store in
// the per-space blob CAS, hardlink into the version tree, mime detection
// (declared contentType wins), and the file metadata.
//
// Lock contract with the local blob GC: this blob_has -> blob_put ->
// blob_link sequence is only race-safe because the caller (finalize_version,
// dispatched space-scoped) holds THIS space's spaces/{spaceId}/write.lock
// throughout, and _stattic_tier_local_blob_gc_run takes that same per-space
// lock (non-blocking) around each stat/unlink of this space's blobs. Do not
// call this outside the owning space's write lock.
function _stattic_runtime_commit_version_file(string $privateRoot, string $spaceId, string $source, string $versionFilesRoot, string $path, array $file, string $hashFailCode, string $hashFailMessage, array $hashFailDetails): array
{
    _stattic_runtime_assert_private_path($source);
    if (!is_file($source)) {
        _stattic_json_response(409, ['error' => ['code' => $hashFailCode, 'message' => $hashFailMessage, 'details' => $hashFailDetails]]);
    }
    // The declared sha is shape-checked before the file is read so a malformed
    // manifest entry costs no hashing pass; the bytes are then hashed exactly
    // once and that single hash is both the CAS key and the integrity gate.
    $declared = is_string($file['sha256'] ?? null) ? strtolower($file['sha256']) : null;
    if ($declared !== null && preg_match('/^[a-f0-9]{64}$/', $declared) !== 1) {
        _stattic_json_response(409, ['error' => ['code' => $hashFailCode, 'message' => $hashFailMessage, 'details' => $hashFailDetails]]);
    }
    $actualSha = hash_file('sha256', $source);
    if (!is_string($actualSha)) {
        _stattic_json_response(409, ['error' => ['code' => $hashFailCode, 'message' => $hashFailMessage, 'details' => $hashFailDetails]]);
    }
    $sha256 = strtolower($actualSha);
    if ($declared !== null && !hash_equals($declared, $sha256)) {
        _stattic_json_response(409, ['error' => ['code' => $hashFailCode, 'message' => $hashFailMessage, 'details' => $hashFailDetails]]);
    }

    $target = $versionFilesRoot . '/' . $path;
    if (!_stattic_runtime_blob_has($privateRoot, $spaceId, $sha256)) {
        $staging = $privateRoot . '/runtime/blob-staging/' . bin2hex(random_bytes(12));
        _stattic_runtime_mkdir(dirname($staging));
        _stattic_runtime_copy_private_file($source, $staging);
        _stattic_runtime_blob_put($privateRoot, $spaceId, $staging, $sha256);
    }
    _stattic_runtime_mkdir(dirname($target));
    _stattic_runtime_blob_link($privateRoot, $spaceId, $sha256, $target);
    $mime = _stattic_runtime_detect_file_mime(
        $path,
        $source,
        is_string($file['contentType'] ?? null) ? $file['contentType'] : null
    );
    return _stattic_runtime_build_file_meta($path, (int) $file['size'], $mime, $sha256);
}

// Template substitution output (internal-docs/platform.md "Variable rules"):
// the finalize compiler resolves {{ vars.NAME }} into declared template files
// and ships the substituted contents here. The served bytes are replaced; the
// pre-substitution original moves to `files-original/` so retained-file
// dedup (declared sha = upload identity) still verifies for later versions.
function _stattic_runtime_management_convention_files(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    if (array_key_exists('routes', $value)) {
        _stattic_json_response(422, ['error' => ['code' => 'routes_input_retired', 'message' => 'Runtime reads .stattic/routes.json from staged deploy files. Management convention_files may only contain redirects and headers.']]);
    }
    $conventionFiles = [];
    foreach (['redirects', 'headers'] as $key) {
        if (is_string($value[$key] ?? null)) {
            $conventionFiles[$key] = $value[$key];
        }
    }
    return $conventionFiles;
}

function _stattic_runtime_cleanup_stale_uploads(string $privateRoot): void
{
    $uploadsRoot = $privateRoot . '/runtime/uploads';
    if (!is_dir($uploadsRoot)) {
        return;
    }
    // Uploads are per-SITE, so on a shared site every publish would otherwise
    // read every space's open session. Session TTL has no other enforcement
    // point, but reclaiming an expired session directory up to an hour late is
    // within the same best-effort contract.
    if (!_spacefast_marker_throttle($uploadsRoot . '/.last-cleanup', 3600)) {
        return;
    }
    $deadline = time() - 86400;
    foreach (glob($uploadsRoot . '/*/session.json') ?: [] as $sessionPath) {
        if (!is_string($sessionPath)) {
            continue;
        }
        $session = _stattic_runtime_read_json($sessionPath);
        $expiresAt = is_array($session) && is_string($session['expires_at'] ?? null)
            ? strtotime($session['expires_at'])
            : false;
        if ($expiresAt !== false) {
            if ($expiresAt < time()) {
                _stattic_runtime_rm_recursive(dirname($sessionPath));
            }
            continue;
        }
        $createdAt = is_array($session) && is_string($session['created_at'] ?? null)
            ? strtotime($session['created_at'])
            : false;
        if ($createdAt === false || $createdAt < $deadline) {
            _stattic_runtime_rm_recursive(dirname($sessionPath));
        }
    }
}

// Per-space route-pointer/generation state for the authenticated state endpoint:
// the control plane's reconciliation sweep compares this against its own
// expectations (space liveVersionId) and repairs targeted mismatches.
function _stattic_runtime_state_summary(string $privateRoot): array
{
    $current = is_file($privateRoot . '/routes/current.php')
        ? @include $privateRoot . '/routes/current.php'
        : null;
    $generation = is_array($current) && is_string($current['generation'] ?? null)
        ? $current['generation']
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
        $tombstones = _stattic_runtime_read_json($spaceRoot . '/tombstones.json');
        $spaces[] = [
            'space_id' => basename($spaceRoot),
            'routes' => (object) $routes,
            'tombstone_count' => is_array($tombstones) && is_array($tombstones['hostnames'] ?? null)
                ? count($tombstones['hostnames'])
                : 0,
        ];
    }
    return ['routes_generation' => $generation, 'spaces' => $spaces];
}

// Generation-state handshake (spec "Cache Management"): the control plane's
// reconciliation sweep compares these route pointers against its expectations
// and repairs targeted mismatches after callback gaps.
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

// Callback-journal retention (spec "Cache Management"): delivered callbacks
// are kept long enough that any budget-deferred purge still has its event to
// replay from — the deferral bound is the reconcile sweep interval (minutes),
// so 7 days is safely beyond the longest possible delay window.
const STATTIC_RUNTIME_CALLBACK_RETENTION_SECONDS = 7 * 86400;

// Undeliverable callbacks must not poison the journal: each pending file
// retries on every drain, so an unreachable callback origin made one drain
// O(pending × connect timeout) — e2e checkpoint 4 found 483 never-deliverable
// callbacks turning every drain into minutes of blocking I/O while operation
// chains timed out behind it. Two bounds keep drain O(reachable work):
// callbacks past the attempt cap are dropped, and the FIRST failed delivery to
// an origin marks it dead for the remainder of that drain pass (remaining
// events for it stay pending, untouched, with no connect wait).
const STATTIC_RUNTIME_CALLBACK_MAX_ATTEMPTS = 10;

// Cap on events handed back in one drain response (pull fallback below):
// bounds the response body; the next drain returns the rest.
const STATTIC_RUNTIME_CALLBACK_RETURN_MAX = 100;

// Core delivery loop, split out so the job runner's bulk-lane self-drain
// (admin/jobs.php, §22) can call the SAME mechanism proactively instead of
// only when a control-plane /events/drain request arrives — no new delivery
// path, just a new caller with no claims/journal-summary side effects of its
// own (the HTTP route below owns those). $allowPullFallback must be false for
// the self-drain caller: the pull fallback hands unreachable-origin events
// back in the HTTP response for the control plane to take over delivery of —
// a self-drain tick has no caller to hand them to, so treat a push failure
// there like the return-cap-exceeded case (stays pending/, retried on a
// later drain) instead of moving it to delivered/ and losing it.
function _stattic_runtime_drain_callback_events_core(string $privateRoot, bool $allowPullFallback = true): array
{
    $pendingRoot = $privateRoot . '/runtime/callbacks/pending';
    $deliveredRoot = $privateRoot . '/runtime/callbacks/delivered';
    _stattic_runtime_mkdir($pendingRoot);
    _stattic_runtime_mkdir($deliveredRoot);

    $retentionDeadline = time() - STATTIC_RUNTIME_CALLBACK_RETENTION_SECONDS;
    foreach (glob($deliveredRoot . '/*.json') ?: [] as $deliveredPath) {
        $mtime = @filemtime($deliveredPath);
        if (is_int($mtime) && $mtime < $retentionDeadline) {
            @unlink($deliveredPath);
        }
    }

    $delivered = 0;
    $failed = 0;
    $expired = 0;
    $deadUrls = [];
    $returnedEvents = [];
    foreach (glob($pendingRoot . '/*.json') ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        _stattic_runtime_assert_private_path($path);
        $callback = _stattic_runtime_read_json($path);
        if (!is_array($callback)) {
            @unlink($path);
            continue;
        }
        $url = isset($callback['callback_url']) && is_string($callback['callback_url'])
            ? $callback['callback_url']
            : '';
        $token = isset($callback['callback_token']) && is_string($callback['callback_token'])
            ? $callback['callback_token']
            : '';
        $event = is_array($callback['event'] ?? null) ? $callback['event'] : [];
        if ($url === '' || $token === '' || $event === []) {
            @unlink($path);
            continue;
        }
        if (max(0, (int) ($callback['attempts'] ?? 0)) >= STATTIC_RUNTIME_CALLBACK_MAX_ATTEMPTS) {
            @unlink($path);
            $expired += 1;
            continue;
        }

        if (!isset($deadUrls[$url])) {
            $ok = _stattic_runtime_send_callback_event($url, $token, $event);
            if ($ok) {
                $callback['status'] = 'delivered';
                $callback['delivered_at'] = gmdate('c');
                $target = $deliveredRoot . '/' . basename($path);
                _stattic_runtime_write_json_atomic($target, $callback);
                @unlink($path);
                $delivered += 1;
                continue;
            }
            $deadUrls[$url] = true;
        }

        // Pull fallback (same graceful-degradation pattern as the SSH
        // management dispatch): the drain CALLER is the control plane itself,
        // so events whose push origin is unreachable are handed back in the
        // drain response instead of rotting in the journal. Replays are no-ops
        // on (event_id, hostname); the reconcile sweep backstops a lost
        // response.
        if ($allowPullFallback && count($returnedEvents) < STATTIC_RUNTIME_CALLBACK_RETURN_MAX) {
            $returnedEvents[] = [
                'operation_id' => isset($callback['operation_id']) && is_string($callback['operation_id'])
                    ? $callback['operation_id']
                    : '',
                'event' => $event,
            ];
            $callback['status'] = 'returned';
            $callback['returned_at'] = gmdate('c');
            $target = $deliveredRoot . '/' . basename($path);
            _stattic_runtime_write_json_atomic($target, $callback);
            @unlink($path);
            continue;
        }

        $callback['attempts'] = max(0, (int) ($callback['attempts'] ?? 0)) + 1;
        $callback['last_failed_at'] = gmdate('c');
        _stattic_runtime_write_json_atomic($path, $callback);
        $failed += 1;
    }

    return [
        'delivered_count' => $delivered,
        'failed_count' => $failed,
        'expired_count' => $expired,
        'returned_count' => count($returnedEvents),
        'events' => $returnedEvents,
    ];
}

function _stattic_runtime_drain_callback_events(string $privateRoot, array $claims): void
{
    $result = _stattic_runtime_drain_callback_events_core($privateRoot);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'runtime_callbacks_drained',
        'delivered_count' => $result['delivered_count'],
        'failed_count' => $result['failed_count'],
        'expired_count' => $result['expired_count'],
        'returned_count' => $result['returned_count'],
    ]);
    _stattic_json_response(200, $result);
}

// Cap on events handed back by one access-event pull; the cursor returns the
// rest on the next call.
const STATTIC_RUNTIME_ACCESS_EVENTS_RETURN_MAX = 1000;
const STATTIC_RUNTIME_ACCESS_EVENTS_RETURN_DEFAULT = 500;

// Access-event journal pull (access-plan X-37 / §5.6b): the cloud reads the
// runtime-local NDJSON journal written by the serve-path enforcer
// (runtime/access-rules.php) through this management action — the runtime
// never pushes. Cursor-advance contract (the non-destructive sibling of the
// callback drain's pull fallback): the caller sends the {file, offset} it
// stopped at and receives everything appended since, across day-file
// rotations; day files age out via the writer's retention prune, so no
// truncate call exists. Replays are safe — reading never mutates the journal.
//
//   GET /access-events?file={YYYY-MM-DD.ndjson}&offset={int}&limit={int}
//     (no file => start at the oldest day file, offset 0)
//   200 {"events": [accessEventSchema...], "cursor": {"file", "offset"},
//        "done": bool, "dropped": int}
//
// `cursor` is the position after the last returned event (feed it back
// verbatim); a cursor pointing at a pruned file resumes at the next existing
// day file. `done` is false only when this response was truncated by `limit`.
// `dropped` totals the writer's `.dropped` sidecars (events skipped past the
// per-file byte cap) across the retained window.
function _stattic_runtime_read_access_events(string $privateRoot): void
{
    $dir = $privateRoot . '/' . SPACEFAST_ACCESS_EVENTS_DIR;

    $cursorFile = null;
    if (isset($_GET['file'])) {
        $cursorFile = is_string($_GET['file']) ? trim($_GET['file']) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}\.ndjson$/', $cursorFile) !== 1) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_access_event_cursor', 'message' => 'Cursor file must be a day-file name (YYYY-MM-DD.ndjson).']]);
        }
    }
    $offsetRaw = $_GET['offset'] ?? '0';
    if (!is_string($offsetRaw) || ($offsetRaw !== '' && !ctype_digit($offsetRaw))) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_access_event_cursor', 'message' => 'Cursor offset must be a non-negative integer.']]);
    }
    $offset = (int) $offsetRaw;
    $limitRaw = $_GET['limit'] ?? null;
    $limit = is_string($limitRaw) && ctype_digit($limitRaw) && (int) $limitRaw > 0
        ? min((int) $limitRaw, STATTIC_RUNTIME_ACCESS_EVENTS_RETURN_MAX)
        : STATTIC_RUNTIME_ACCESS_EVENTS_RETURN_DEFAULT;

    $files = [];
    $dropped = 0;
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}\.ndjson$/', (string) $entry) === 1) {
            $files[] = (string) $entry;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}\.ndjson\.dropped$/', (string) $entry) === 1) {
            $dropped += (int) trim((string) @file_get_contents($dir . '/' . $entry));
        }
    }
    sort($files);

    // Resolve the start position: the cursor's file when it still exists, else
    // the next existing day file (offset 0); no cursor starts at the oldest.
    $startIndex = 0;
    if ($cursorFile !== null) {
        $startIndex = count($files);
        foreach ($files as $index => $name) {
            if ($name >= $cursorFile) {
                $startIndex = $index;
                if ($name !== $cursorFile) {
                    $offset = 0;
                }
                break;
            }
        }
    }

    $events = [];
    $positionFile = $cursorFile;
    $positionOffset = $offset;
    $hitLimit = false;
    for ($index = $startIndex; $index < count($files); $index += 1) {
        $name = $files[$index];
        $readFrom = $index === $startIndex ? $offset : 0;
        $positionFile = $name;
        $positionOffset = $readFrom;
        $handle = @fopen($dir . '/' . $name, 'rb');
        if ($handle === false) {
            continue;
        }
        if ($readFrom > 0 && @fseek($handle, $readFrom) !== 0) {
            fclose($handle);
            continue;
        }
        while (count($events) < $limit) {
            $lineStart = ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            if (!str_ends_with($line, "\n")) {
                // In-flight tail of a LOCK_EX append: stop BEFORE it so the
                // cursor re-reads the completed line next pull.
                fseek($handle, is_int($lineStart) ? $lineStart : 0);
                break;
            }
            $positionOffset = (int) ftell($handle);
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        fclose($handle);
        if (count($events) >= $limit) {
            $hitLimit = true;
            break;
        }
    }

    _stattic_json_response(200, [
        'events' => $events,
        'cursor' => $positionFile === null ? null : ['file' => $positionFile, 'offset' => $positionOffset],
        'done' => !$hitLimit,
        'dropped' => $dropped,
    ]);
}

function _stattic_runtime_send_callback_event(string $url, string $token, array $event): bool
{
    $status = _spacefast_post_callback_event($url, $token, $event, 5);
    return $status >= 200 && $status < 300;
}

function _stattic_runtime_write_zero_config_artifact(string $versionRoot, array $zero): void
{
    _stattic_runtime_mkdir($versionRoot . '/zero');
    _stattic_runtime_write_json_atomic($versionRoot . '/zero/config.json', _stattic_runtime_zero_config_artifact($zero));
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
    if (array_key_exists('migrations', $zero)) {
        $artifact['migrations'] = $zero['migrations'];
    }
    return $artifact;
}

function _stattic_runtime_zero_control_lookup_actions(): array
{
    $actions = [];
    foreach (SPACEFAST_ZERO_CONTROL_ROUTES as $path => $route) {
        $actions[$path] = _stattic_runtime_zero_control_lookup_action($route['operation'], $route['methods']);
    }
    return $actions;
}

function _stattic_runtime_zero_control_lookup_action(string $operation, array $methods): array
{
    return [
        'action' => 'invoke_zero',
        'operation' => $operation,
        'methods' => $methods,
    ];
}

function _stattic_runtime_assert_static_mount_routes_scope(array $body, array $claims): void
{
    $mountRoutes = $body['static_mount_routes'] ?? [];
    if (!is_array($mountRoutes) || $mountRoutes === []) {
        return;
    }
    $encodedMountRoutes = json_encode(
        $mountRoutes,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $claimedDigest = $claims['static_mount_routes_sha256'] ?? null;
    if (
        !is_string($encodedMountRoutes)
        || !is_string($claimedDigest)
        || !hash_equals(hash('sha256', $encodedMountRoutes), $claimedDigest)
    ) {
        _stattic_json_response(403, ['error' => ['code' => 'runtime_scope_forbidden', 'message' => 'Runtime token is not scoped to these static mount routes.']]);
    }
}

function _stattic_runtime_put_route(string $privateRoot, string $spaceId, string $routeName, array $claims): void
{
    $body = _stattic_json_body();
    $versionId = isset($body['version_id']) && is_string($body['version_id'])
        ? _stattic_runtime_id($body['version_id'], 'version_id')
        : '';
    if ($versionId === '' || !is_file(_spacefast_version_root($privateRoot, $spaceId, $versionId) . '/serving.php')) {
        _stattic_json_response(404, ['error' => ['code' => 'version_not_found', 'message' => 'Version not found.']]);
    }
    if (array_key_exists('routes', $body)) {
        _stattic_json_response(422, ['error' => ['code' => 'routes_input_retired', 'message' => 'Runtime compiles routes from hostname intent. Send production_hostnames and version_hostnames.']]);
    }
    _stattic_runtime_assert_static_mount_routes_scope($body, $claims);
    $storeIntent = array_key_exists('production_hostnames', $body)
        || array_key_exists('version_hostnames', $body)
        || array_key_exists('proxy_host_routes', $body)
        || array_key_exists('static_mount_routes', $body);
    $pointer = _stattic_runtime_write_route_pointer($privateRoot, $spaceId, $routeName, $versionId, $body, $claims, $storeIntent);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'route_name' => $routeName,
        'version_id' => $versionId,
        ...($pointer['unchanged'] ? ['unchanged' => true] : []),
    ]);
}

function _stattic_runtime_put_hostname_intent(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    if (array_key_exists('routes', $body)) {
        _stattic_json_response(422, ['error' => ['code' => 'routes_input_retired', 'message' => 'Runtime compiles routes from hostname intent. Send production_hostnames and version_hostnames.']]);
    }
    _stattic_runtime_assert_static_mount_routes_scope($body, $claims);
    $routes = _stattic_runtime_routes_from_hostname_intent('production', $body);
    _stattic_runtime_store_hostname_intent($privateRoot, $spaceId, $routes, $claims);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_json_response(200, ['space_id' => $spaceId, 'route_count' => count($routes)]);
}

// One route-pointer flip (route PUT and finalize+activate alike): write the
// pointer, store the unified access policy and hostname intent, journal the
// route_updated event, and rebuild the route index. Changed-path purge
// contract (spec "Cache Management"): the diff rides the journal so the
// control plane purges changed request paths, never blanket when a diff
// exists. Returns ['changed_paths' => string[], 'unchanged' => bool].
//
// Conditional write: when the request carries `config_digest` and the stored
// route already has the same version_id + config_digest + hostname intent,
// the whole write is skipped — no policy store, no journal event (so no edge
// purge), no index rebuild. Re-pushes from reconcile sweeps and claim/plan
// syncs used to journal a host-wide purge on every touch; now an unchanged
// push costs nothing.
function _stattic_runtime_write_route_pointer(string $privateRoot, string $spaceId, string $routeName, string $versionId, array $body, array $claims, bool $storeIntent): array
{
    $configDigest = is_string($body['config_digest'] ?? null) && $body['config_digest'] !== ''
        ? $body['config_digest']
        : null;
    // Compiled once for both consumers below (the unchanged-check and the
    // store). Guarded on $storeIntent because compiling also validates the
    // intent body, and a route write that carries no intent must not start
    // rejecting bodies it never inspected.
    $intentRoutes = $storeIntent ? _stattic_runtime_routes_from_hostname_intent($routeName, $body) : [];
    if (
        $configDigest !== null
        && !array_key_exists('changed_paths', $body)
        && _stattic_runtime_route_pointer_unchanged($privateRoot, $spaceId, $routeName, $versionId, $configDigest)
        && (!$storeIntent || !_stattic_runtime_hostname_intent_changed($privateRoot, $spaceId, $intentRoutes))
    ) {
        return ['changed_paths' => [], 'unchanged' => true];
    }
    _stattic_runtime_write_route(
        $privateRoot,
        $spaceId,
        $routeName,
        $versionId,
        _stattic_runtime_route_config($body['config'] ?? null),
        $configDigest
    );
    _stattic_runtime_store_unified_access_from_config($privateRoot, $spaceId, $body['config'] ?? null);
    if ($storeIntent) {
        _stattic_runtime_store_hostname_intent($privateRoot, $spaceId, $intentRoutes, $claims);
    }
    $changedPathsKnown = false;
    $changedPaths = _stattic_runtime_changed_path_list($body['changed_paths'] ?? null, $changedPathsKnown);
    $routeEvent = [
        'event' => 'route_updated',
        'space_id' => $spaceId,
        'route_name' => $routeName,
        'version_id' => $versionId,
        'hostnames' => _stattic_runtime_affected_intent_hostnames($privateRoot, $spaceId, $routeName),
        'changed_paths' => $changedPaths,
        'changed_paths_known' => $changedPathsKnown,
    ];
    if (is_string($body['previous_version_id'] ?? null) && $body['previous_version_id'] !== '') {
        $routeEvent['previous_version_id'] = $body['previous_version_id'];
    }
    _stattic_runtime_record_management_event($privateRoot, $claims, $routeEvent);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    return ['changed_paths' => $changedPaths, 'unchanged' => false];
}

// True when the stored route pointer already matches version + config digest.
function _stattic_runtime_route_pointer_unchanged(string $privateRoot, string $spaceId, string $routeName, string $versionId, string $configDigest): bool
{
    $stored = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/routes/' . $routeName . '.json');
    return is_array($stored)
        && ($stored['version_id'] ?? null) === $versionId
        && ($stored['config_digest'] ?? null) === $configDigest;
}

// True when the request's hostname intent differs from the stored intent
// (normalized route-by-route). Order-sensitive: a differing order just means
// a normal write, never a wrongly skipped one.
function _stattic_runtime_hostname_intent_changed(string $privateRoot, string $spaceId, array $incomingRoutes): bool
{
    $stored = _stattic_runtime_read_json(_spacefast_space_root($privateRoot, $spaceId) . '/hostname-intent.json');
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
    $routesRoot = _spacefast_space_root($privateRoot, $spaceId) . '/routes';
    _stattic_runtime_mkdir($routesRoot);
    _stattic_runtime_write_json_atomic($routesRoot . '/' . $routeName . '.json', [
        'space_id' => $spaceId,
        'route_name' => $routeName,
        'version_id' => $versionId,
        'config' => $config,
        ...($configDigest !== null ? ['config_digest' => $configDigest] : []),
        'updated_at' => gmdate('c'),
    ]);
}

// THE unified policy lane (firewall ⊂ access): the runtime enforcer
// (runtime/access-rules.php) reads serving['policy'] = { rules: RuntimeRule[] }.
// Stored per space; an explicit null clears it, absence leaves it untouched.
// The unified Rule has no version literal — its shape is { match, effect,
// auth? }.
function _stattic_runtime_store_unified_policy(string $privateRoot, string $spaceId, mixed $raw): void
{
    $path = _spacefast_space_root($privateRoot, $spaceId) . '/policy.json';
    if ($raw === null) {
        _stattic_runtime_rm_recursive($path);
        return;
    }
    _stattic_runtime_write_json_atomic($path, [
        'space_id' => $spaceId,
        'policy' => _stattic_runtime_unified_policy($raw),
        'updated_at' => gmdate('c'),
    ]);
}

// Serving-secret map (name -> value) the unified policy's password rules resolve
// by `auth.password.ref` = "secret:<name>". Stored per space beside policy.json;
// an explicit null clears it, absence leaves it untouched. Values are stored
// verifier hashes / shared secrets, never plaintext a visitor types.
function _stattic_runtime_store_policy_secrets(string $privateRoot, string $spaceId, mixed $raw): void
{
    $path = _spacefast_space_root($privateRoot, $spaceId) . '/policy-secrets.json';
    if ($raw === null) {
        _stattic_runtime_rm_recursive($path);
        return;
    }
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_policy', 'message' => 'Policy secrets must be an object.']]);
    }
    $secrets = [];
    foreach ($raw as $name => $value) {
        if (!is_string($name) || $name === '' || !is_string($value) || $value === '') {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_policy', 'message' => 'Policy secret entries must be non-empty name/value strings.']]);
        }
        $secrets[$name] = $value;
    }
    _stattic_runtime_write_json_atomic($path, [
        'space_id' => $spaceId,
        'secrets' => $secrets,
        'updated_at' => gmdate('c'),
    ]);
}

// Serve-time plan entitlements (proxy-routes.md gating; ARCHITECTURE decision:
// local lookup only, fail closed): stored per space beside policy.json, read
// fresh by admin/generate.php on every route compile so
// runtime/redirects.php's $serving['entitlements'] always reflects the LATEST
// synced plan — a `planGated` proxy rule activates the moment this doc says
// so, no republish. An explicit null clears the stored doc (falls back to
// fail-closed defaults); absence leaves it untouched.
function _stattic_runtime_store_entitlements(string $privateRoot, string $spaceId, mixed $raw): void
{
    $path = _spacefast_space_root($privateRoot, $spaceId) . '/entitlements.json';
    if ($raw === null) {
        _stattic_runtime_rm_recursive($path);
        return;
    }
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_entitlements', 'message' => 'Entitlements must be an object.']]);
    }
    _stattic_runtime_write_json_atomic($path, [
        'space_id' => $spaceId,
        'entitlements' => [
            'externalProxy' => !empty($raw['externalProxy']),
        ],
        'updated_at' => gmdate('c'),
    ]);
}

// THE writer for a space's revocation tombstones: the atomic file write and the
// per-request memo invalidation must always happen together, and both writers
// below hold the space's write lock while calling this.
function _stattic_runtime_store_revocation_records(string $privateRoot, string $spaceId, array $grants, array $subs, int $now): void
{
    _stattic_runtime_write_json_atomic(_spacefast_revocations_path($privateRoot, $spaceId), [
        'grants' => _stattic_runtime_json_object($grants),
        'subs' => _stattic_runtime_json_object($subs),
        'updatedAt' => $now,
    ]);
    _spacefast_forget_revocation_state($privateRoot, $spaceId);
}

// Durable revocation SET (access-plan revocation hardening): the control
// plane's Postgres projection of every currently-revoked link:/invite:/svc:
// grant, ridden on the SAME route-pointer config channel as `policy`/
// `secrets` (config.revocations, an array of grant ids). REPLACES the
// `grants` bucket of revocations.json wholesale on every runtime.sync — the
// backstop that converges the tombstone store even when the instant
// best-effort revoke_grant/unrevoke_grant call (below) was dropped. `subs`
// (JWT-subject revocations — a management-only primitive with no
// control-plane projection today) is left untouched. Invalid/malformed
// entries are silently dropped, mirroring `_spacefast_normalize_revocation_records`.
//
// Grace window (never-fail-open): `config.revocations` is a snapshot the
// control plane read from Postgres BEFORE this request was sent, and two
// syncs for the same space can land out of order (independent operations —
// e.g. a version-publish route push racing a sharing revoke — with no
// ordering guarantee between their HTTP requests). A snapshot taken before a
// revoke's Postgres commit can arrive here AFTER the instant best-effort
// revoke_grant call already tombstoned that grant; a blind wholesale replace
// would then erase the newer tombstone and the grant would work again until
// the next sync. So a currently-stored grant absent from `$raw` is only
// dropped once it has survived past the grace window — long enough that any
// snapshot racing a concurrent revoke has resolved (worst case a single
// route-config HTTP attempt: RUNTIME_REQUEST_TIMEOUT_MS = 120s on the
// control-plane side). Recently-touched absentees are carried over instead,
// and the very next sync (racy or not) resolves them for good either way.
const STATTIC_REVOCATIONS_REPLACE_GRACE_SECONDS = 600;

function _stattic_runtime_store_revocations_replace(string $privateRoot, string $spaceId, mixed $raw): void
{
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_revocations', 'message' => 'config.revocations must be an array of grant ids.']]);
    }
    $now = time();
    $grants = [];
    foreach ($raw as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $grant = trim($entry);
        if (!_spacefast_revocation_grant_valid($grant)) {
            continue;
        }
        $grants[$grant] = $now;
    }
    // Lock invariant: EVERY writer of spaces/{spaceId}/revocations.json holds
    // that space's per-space write lock. Today every caller (revoke_grant/
    // unrevoke_grant, update_route, finalize+activate incl. its idempotent
    // retry) already holds it via the dispatch loop, making this nested
    // acquire a re-entrant no-op — but it stays as the invariant's local
    // enforcement point: a future SITE-locked caller (an import variant, a
    // transfer install) without it could interleave its load-merge-write with
    // a concurrent revoke_grant holding only the space lock and silently drop
    // the fresh tombstone (the grace window can't save a tombstone written
    // AFTER this function's load). Ordering is always site -> space and the
    // per-space handlers never take the site lock, so no deadlock cycle exists.
    _stattic_runtime_with_space_write_lock($privateRoot, $spaceId, static function () use ($privateRoot, $spaceId, $grants, $now): void {
        $current = _spacefast_load_revocation_records($privateRoot, $spaceId);
        $graceCutoff = $now - STATTIC_REVOCATIONS_REPLACE_GRACE_SECONDS;
        foreach ($current['grants'] as $grant => $ts) {
            if (isset($grants[$grant])) {
                continue;
            }
            if ($ts > $graceCutoff) {
                $grants[$grant] = $ts;
            }
        }
        _stattic_runtime_store_revocation_records($privateRoot, $spaceId, $grants, $current['subs'], $now);
    });
}

function _stattic_runtime_put_tombstones(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $hostnames = _stattic_runtime_hostname_list($body['hostnames'] ?? []);
    $mode = isset($body['mode']) && is_string($body['mode']) && in_array($body['mode'], ['replace', 'add', 'remove'], true)
        ? $body['mode']
        : 'replace';
    // Reason-differentiated tombstone metadata (C10): the control plane sends why
    // the space was retired so the served tombstone page differs by disabled
    // reason/category (generic-unavailable vs plain-404 for CSAM vs 451 for
    // DMCA/copyright vs a neutral suspended page for a suspended tenant). Both are optional
    // and ignored on `remove`; absence preserves the generic tombstone.
    $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;
    $category = isset($body['category']) && is_string($body['category']) ? $body['category'] : null;
    $tombstoneCount = _stattic_runtime_store_space_tombstones($privateRoot, $spaceId, $hostnames, $mode, $reason, $category);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_tombstones_updated',
        'space_id' => $spaceId,
        'tombstone_count' => $tombstoneCount,
        'hostnames' => $hostnames,
    ]);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_json_response(200, ['space_id' => $spaceId, 'tombstone_count' => $tombstoneCount]);
}

// Retention/pruning policy push (plan §8/§26 A4, wave-5 contract B1): the
// control plane computes the prunable version ids (V1: versions already
// deleted in the DB whose trees may still hold inodes on disk, plus explicit
// superadmin-pruned ids) and pushes them here as the policy artifact. The
// engine only STORES the policy — deletion happens on the bulk housekeeping
// tick (_stattic_runtime_job_housekeeping_prune_versions), which re-checks
// every id against live route pointers before any rm. Pushing an empty list
// clears the policy.
function _stattic_runtime_put_retention_policy(string $privateRoot, string $spaceId, array $claims): void
{
    $body = _stattic_json_body();
    $raw = $body['prunable_version_ids'] ?? null;
    if (!is_array($raw)) {
        _stattic_json_response(422, ['error' => ['code' => 'runtime_retention_policy_invalid', 'message' => 'prunable_version_ids must be an array of version ids.']]);
    }
    $versionIds = [];
    foreach ($raw as $versionId) {
        if (!is_string($versionId)) {
            _stattic_json_response(422, ['error' => ['code' => 'runtime_retention_policy_invalid', 'message' => 'prunable_version_ids must be an array of version ids.']]);
        }
        $versionIds[_stattic_runtime_id($versionId, 'version_id')] = true;
    }
    _stattic_runtime_write_json_atomic(_spacefast_space_root($privateRoot, $spaceId) . '/retention-policy.json', [
        'space_id' => $spaceId,
        'prunable_version_ids' => array_keys($versionIds),
        'updated_at' => gmdate('c'),
    ]);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_retention_policy_updated',
        'space_id' => $spaceId,
        'prunable_count' => count($versionIds),
    ]);
    _stattic_json_response(200, ['space_id' => $spaceId, 'prunable_count' => count($versionIds)]);
}

function _stattic_runtime_update_access_revocations(string $privateRoot, string $spaceId, array $claims, bool $revoke): void
{
    $body = _stattic_json_body();
    $hasGrant = array_key_exists('grant', $body);
    $hasSub = array_key_exists('sub', $body);
    if ($hasGrant === $hasSub) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_revocation_target', 'message' => 'Exactly one of grant or sub is required.']]);
    }

    $kind = $hasGrant ? 'grants' : 'subs';
    $field = $hasGrant ? 'grant' : 'sub';
    $target = $body[$field] ?? null;
    if (!is_string($target)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_revocation_target', 'message' => $field . ' must be a string.']]);
    }
    $target = trim($target);
    if ($kind === 'grants' && !_spacefast_revocation_grant_valid($target)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_revocation_grant', 'message' => 'grant must be a link:, invite:, or svc: row id.']]);
    }
    if ($kind === 'subs' && !_spacefast_revocation_sub_valid($target)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_revocation_sub', 'message' => 'sub is invalid.']]);
    }

    $now = time();
    // A tombstone can outlive the fixed 30-day window: service JWTs remain
    // valid for years, and subject revocations have no durable replacement
    // projection. Only explicit unrevoke or the authoritative grant snapshot
    // may remove records; elapsed time alone cannot prove a bearer expired.
    $records = _spacefast_load_revocation_records($privateRoot, $spaceId);

    if ($revoke) {
        $records[$kind][$target] = $now;
    } else {
        unset($records[$kind][$target]);
    }

    _stattic_runtime_store_revocation_records($privateRoot, $spaceId, $records['grants'], $records['subs'], $now);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => $revoke ? 'access_revocation_added' : 'access_revocation_removed',
        'space_id' => $spaceId,
        'target_type' => $field,
        $field => $target,
    ]);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'target_type' => $field,
        $field => $target,
        'revoked' => $revoke,
    ]);
}

function _stattic_runtime_delete_space(string $privateRoot, string $spaceId, array $claims): void
{
    $spaceRoot = _spacefast_space_root($privateRoot, $spaceId);
    // Every hostname the space served or tombstoned: the purge set the delete
    // event carries to the control plane.
    $hostnames = [];
    $intent = _stattic_runtime_read_json($spaceRoot . '/hostname-intent.json');
    if (is_array($intent) && is_array($intent['routes'] ?? null)) {
        foreach ($intent['routes'] as $route) {
            if (is_array($route) && is_string($route['hostname'] ?? null)) {
                $hostnames[$route['hostname']] = true;
            }
        }
    }
    $tombstones = _stattic_runtime_read_json($spaceRoot . '/tombstones.json');
    if (is_array($tombstones) && is_array($tombstones['hostnames'] ?? null)) {
        foreach ($tombstones['hostnames'] as $hostname) {
            if (is_string($hostname)) {
                $hostnames[$hostname] = true;
            }
        }
    }
    _stattic_runtime_rm_recursive($spaceRoot);
    // Tombstones survive space deletion: deletion ordering compiles tombstones
    // BEFORE the delete so retired hostnames keep serving the tombstone page
    // after the content is gone (spec "Space" deletion ordering; e2e
    // checkpoint 2: the recursive rm used to take tombstones.json with it,
    // degrading retired hostnames to the generic undeployed 503).
    if (is_array($tombstones) && is_array($tombstones['hostnames'] ?? null) && $tombstones['hostnames'] !== []) {
        _stattic_runtime_mkdir($spaceRoot);
        _stattic_runtime_write_json_atomic($spaceRoot . '/tombstones.json', $tombstones);
    }
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_deleted',
        'space_id' => $spaceId,
        'hostnames' => array_keys($hostnames),
    ]);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_json_response(200, ['space_id' => $spaceId, 'status' => 'deleted']);
}

function _stattic_runtime_delete_version(string $privateRoot, string $spaceId, string $versionId, array $claims): void
{
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $versionId);
    if (!is_dir($versionRoot)) {
        _stattic_json_response(404, ['error' => ['code' => 'version_not_found', 'message' => 'Version not found.']]);
    }
    // Shard truth read BEFORE the rm: the shas THIS version holds remote
    // locators for. The control plane decrements remote-blob refcounts for
    // exactly this set (never the whole manifest — a hot version deleted
    // while a sibling's demoted blob shares a sha must not zero the sibling's
    // refcount).
    $remoteShas = [];
    foreach (glob($versionRoot . '/file-shards/*.php') ?: [] as $shardPath) {
        if (!is_string($shardPath)) {
            continue;
        }
        // Lenient include (not _stattic_transfer_file_entries_from_shard's
        // strict reader): a corrupt shard here just contributes no shas rather
        // than fatally blocking the delete route with a job-runner exception.
        $loaded = @include $shardPath;
        $files = is_array($loaded) && is_array($loaded['files'] ?? null) ? $loaded['files'] : [];
        foreach (_stattic_transfer_shard_remote_shas($files) as $sha => $bucket) {
            $remoteShas[$sha] = true;
        }
    }
    $remoteShas = array_keys($remoteShas);
    sort($remoteShas, SORT_STRING);
    _stattic_runtime_rm_recursive($versionRoot);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'version_deleted',
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'hostnames' => _stattic_runtime_affected_intent_hostnames($privateRoot, $spaceId, null, $versionId),
        'remote_shas' => $remoteShas,
    ]);
    _stattic_runtime_update_route_index($privateRoot, $spaceId);
    _stattic_json_response(200, ['space_id' => $spaceId, 'version_id' => $versionId, 'status' => 'deleted']);
}

function _stattic_runtime_repair_space(string $privateRoot, string $spaceId, array $claims): void
{
    if (!is_dir(_spacefast_space_root($privateRoot, $spaceId))) {
        _stattic_json_response(404, ['error' => ['code' => 'space_not_found', 'message' => 'Space not found.']]);
    }
    _stattic_runtime_rebuild_route_index($privateRoot);
    _stattic_runtime_rm_recursive($privateRoot . '/runtime/repair-state.json');
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_repaired',
        'space_id' => $spaceId,
    ]);
    _stattic_json_response(200, ['space_id' => $spaceId, 'status' => 'repaired']);
}

// Route-pointer config is pure serving policy. Space passwords are NOT a separate
// config channel — they ride inside the unified policy.rules as a password
// challenge rule (one access model); the policy itself is stored per space, not
// in the pointer file.
function _stattic_runtime_route_config(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $config = [];
    if (array_key_exists('anonymous_expires_at', $raw)) {
        $config['anonymous_expires_at'] = is_string($raw['anonymous_expires_at']) && $raw['anonymous_expires_at'] !== ''
            ? $raw['anonymous_expires_at']
            : null;
    }
    if (array_key_exists('content_types', $raw)) {
        // Generic serve-time content-type allowlist (exact types or `prefix/*`
        // wildcards). The engine enforces whatever list rides the config —
        // WHY a space is restricted is control-plane policy. Explicit null (or
        // a malformed doc) clears it.
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
    return $config;
}

// THE unified access policy rides inside route config per the runtime contract:
// `policy` ({ rules }) and the `secrets` its password rules resolve. An explicit
// null clears the stored value, absence leaves it untouched. Travels on the same
// host-entry config (finalize+activate and route-update alike).
function _stattic_runtime_store_unified_access_from_config(string $privateRoot, string $spaceId, mixed $config): void
{
    if (is_array($config) && array_key_exists('policy', $config)) {
        _stattic_runtime_store_unified_policy($privateRoot, $spaceId, $config['policy']);
    }
    if (is_array($config) && array_key_exists('secrets', $config)) {
        _stattic_runtime_store_policy_secrets($privateRoot, $spaceId, $config['secrets']);
    }
    if (is_array($config) && array_key_exists('revocations', $config)) {
        _stattic_runtime_store_revocations_replace($privateRoot, $spaceId, $config['revocations']);
    }
    if (is_array($config) && array_key_exists('entitlements', $config)) {
        _stattic_runtime_store_entitlements($privateRoot, $spaceId, $config['entitlements']);
    }
}

function _stattic_runtime_manifest_files(mixed $files): array
{
    if (!is_array($files)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_files', 'message' => 'Version files must be an array.']]);
    }
    $normalized = [];
    $seenPaths = [];
    foreach ($files as $file) {
        if (!is_array($file) || !is_string($file['path'] ?? null) || !isset($file['size'])) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_file', 'message' => 'Each version file requires path and size.']]);
        }
        $entry = [
            'path' => _stattic_runtime_file_path($file['path']),
            'size' => max(0, (int) $file['size']),
        ];
        _stattic_runtime_assert_static_upload_path($entry['path']);
        // Entries byte-identical after canonicalization (including NFC/NFD pairs)
        // are duplicate-path errors (spec canonical path form).
        if (isset($seenPaths[$entry['path']])) {
            _stattic_json_response(422, ['error' => ['code' => 'manifest_duplicate_path', 'message' => 'Version manifest declares the same canonical path twice.', 'details' => ['path' => $entry['path']]]]);
        }
        $seenPaths[$entry['path']] = true;
        if (is_string($file['sha256'] ?? null)) {
            $entry['sha256'] = $file['sha256'];
        }
        if (is_string($file['contentType'] ?? null)) {
            $entry['contentType'] = $file['contentType'];
        }
        $normalized[] = $entry;
    }
    return $normalized;
}
