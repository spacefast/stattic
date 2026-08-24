<?php
declare(strict_types=1);

// The bulk storage lane: demote, blob GC, version retention. The CAS is the
// only byte store. Demote alone sends a live blob to S3, and only when asked
// (§18: the runtime holds no tiering policy).
//
// No file time on a BLOB is ever read as a clock: its mtime is content-derived
// and IS the accel-lane validator. Every clock here is a record we wrote. The
// declarations carry publish time, the demote sidecars release time,
// gc/marks.json candidacy time.
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/s3.php';

const STATTIC_TIER_DEMOTE_CHUNK = 200;
const STATTIC_TIER_GC_DELETE_BATCH = 200;
const STATTIC_TIER_PUT_STREAMS = 4;

function _stattic_tier_gc_grace_seconds(): int
{
    // min=0: tests and ops pin this to 0 to collect on the spot.
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS', 3600, 0);
}

// The floor the undeclared/garbage lane keeps when the grace knob above is
// pinned to 0. It covers the finalizer's install->declare window, where "no
// declaration names this blob" means "not yet" rather than "never".
function _stattic_tier_gc_undeclared_min_grace_seconds(): int
{
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_UNDECLARED_MIN_GRACE_SECONDS', 300, 0);
}

function _stattic_tier_gc_scan_interval_seconds(): int
{
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_SCAN_INTERVAL_SECONDS', 3600, 0);
}

// A declaration's own timestamp, for the shas it names; its file mtime is the
// fallback (a record file is not a blob, so the validator rule does not apply).
function _stattic_tier_record_time(mixed $decoded, string $path): int
{
    if (is_array($decoded)) {
        foreach (['generatedAt', 'created_at', 'createdAt', 'updated_at', 'updatedAt'] as $field) {
            $at = _stattic_record_store_timestamp($decoded[$field] ?? null);
            if ($at !== null) {
                return $at;
            }
        }
    }
    $mtime = filemtime($path);
    return $mtime === false ? 0 : (int) $mtime;
}

// Every 64-hex string anywhere in a decoded declaration. Shape-agnostic rather
// than a list of key paths: the finalizer owns the declarations that name
// blobs, and a GC edited in lockstep with them eventually deletes live bytes.
// Over-inclusion is free.
function _stattic_tier_collect_shas(mixed $value, array &$shas, int $at, int $depth = 0): void
{
    if (is_string($value)) {
        if (_stattic_is_sha256_hex($value)) {
            $shas[$value] = max($shas[$value] ?? 0, $at);
        }
        return;
    }
    if (!is_array($value) || $depth > 12) {
        return;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && _stattic_is_sha256_hex($key)) {
            $shas[$key] = max($shas[$key] ?? 0, $at);
        }
        _stattic_tier_collect_shas($child, $shas, $at, $depth + 1);
    }
}

/** @return int|null the declaration's timestamp, or null when it is unreadable. */
function _stattic_tier_collect_shas_from_json(string $path, array &$shas): ?int
{
    $decoded = _stattic_runtime_read_json($path);
    if (!is_array($decoded)) {
        return null;
    }
    $at = _stattic_tier_record_time($decoded, $path);
    _stattic_tier_collect_shas($decoded, $shas, $at);
    return $at;
}

// The response tables name blobs no other declaration has to mention. Reading
// their BYTES, never including them, keeps the live set independent of whether
// a compiler change mirrored a ref into metadata.json.
function _stattic_tier_collect_shas_from_artifact(string $path, array &$shas, int $at): bool
{
    error_clear_last();
    $source = file_get_contents($path, false, null, 0, 4194304);
    if (!is_string($source)) {
        _sf_runtime_log_read_failure('blob_gc_artifact_read_failed', $path);
        return false;
    }
    if (preg_match_all('/[a-f0-9]{64}/', $source, $matches) < 1) {
        return true;
    }
    foreach ($matches[0] as $sha) {
        $shas[$sha] = max($shas[$sha] ?? 0, $at);
    }
    return true;
}

/**
 * Every sha this space must still be able to answer, mapped to the timestamp of
 * the newest declaration that names it: retained versions' `metadata.json` and
 * response tables, the live overlay, space storage, publish sessions in
 * flight.
 *
 * Null means one live declaration was unreadable, so this space cannot be
 * reasoned about and the caller touches nothing in it.
 *
 * @return array{shas:array<string,int>,version_ids:list<string>}|null
 */
function _stattic_tier_space_live_set(string $privateRoot, string $spaceId): ?array
{
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $shas = [];
    $versionIds = [];
    $versionRoots = _stattic_runtime_directory_entries($spaceRoot . '/versions');
    if ($versionRoots === null) {
        return null;
    }
    foreach ($versionRoots as $versionRoot) {
        if (!is_dir($versionRoot)) {
            continue;
        }
        // The tables were published with their version, so they share its clock.
        $publishedAt = _stattic_tier_collect_shas_from_json($versionRoot . '/metadata.json', $shas);
        if ($publishedAt === null) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'blob_gc_declaration_gap',
                'space_id' => $spaceId,
                'version_id' => basename($versionRoot),
                'reason' => 'version_metadata_unreadable',
            ]);
            return null;
        }
        $versionEntries = _stattic_runtime_directory_entries($versionRoot);
        if ($versionEntries === null) {
            return null;
        }
        foreach ($versionEntries as $table) {
            if (fnmatch('responses-*.php', basename($table)) && !_stattic_tier_collect_shas_from_artifact($table, $shas, $publishedAt)) {
                return null;
            }
        }
        $versionId = basename($versionRoot);
        if (_stattic_runtime_id_valid($versionId) && str_starts_with($versionId, 'ver_')) {
            $versionIds[$versionId] = true;
        }
    }
    // The live overlay names blobs no version declares: a tag/SDK body too large
    // to sit inline in the opcached artifact lives in the CAS with only the
    // overlay pointing at it.
    $spacePointer = _stattic_runtime_read_json($spaceRoot . '/space.json');
    if ($spacePointer === false) {
        return null;
    }
    $overlayName = is_array($spacePointer) && is_string($spacePointer['overlay'] ?? null)
        ? $spacePointer['overlay']
        : null;
    if ($overlayName !== null && strpos($overlayName, '..') === false) {
        $overlayPath = $spaceRoot . '/' . $overlayName;
        if (!_stattic_tier_collect_shas_from_artifact(
            $overlayPath,
            $shas,
            _stattic_tier_record_time(null, $overlayPath)
        )) {
            return null;
        }
    }
    foreach ([$spaceRoot . '/uploads', $spaceRoot . '/publish-sessions'] as $declarationsRoot) {
        $declarationEntries = _stattic_runtime_directory_entries($declarationsRoot);
        if ($declarationEntries === null) {
            return null;
        }
        foreach ($declarationEntries as $path) {
            if (is_file($path) && str_ends_with($path, '.json') && _stattic_tier_collect_shas_from_json($path, $shas) === null) {
                return null;
            }
            if (!is_dir($path)) {
                continue;
            }
            $nestedEntries = _stattic_runtime_directory_entries($path);
            if ($nestedEntries === null) {
                return null;
            }
            foreach ($nestedEntries as $nestedPath) {
                if (is_file($nestedPath) && str_ends_with($nestedPath, '.json') && _stattic_tier_collect_shas_from_json($nestedPath, $shas) === null) {
                    return null;
                }
            }
        }
    }
    ksort($versionIds);
    return ['shas' => $shas, 'version_ids' => array_keys($versionIds)];
}

/** @return array<string,int>|null sha => publish timestamp */
function _stattic_tier_space_live_shas(string $privateRoot, string $spaceId): ?array
{
    $live = _stattic_tier_space_live_set($privateRoot, $spaceId);
    return $live === null ? null : $live['shas'];
}

/**
 * Null means a pin file exists that this process cannot read: the space's
 * deletions are then skipped entirely rather than guessed at, because an
 * unreadable pin is indistinguishable from one protecting everything.
 *
 * @return array<string,true>|null
 */
function _stattic_tier_space_pinned_shas(string $privateRoot, string $spaceId, int $now): ?array
{
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $pinned = [];
    $pinEntries = _stattic_runtime_directory_entries($spaceRoot . '/pins');
    if ($pinEntries === null) {
        return null;
    }
    foreach ($pinEntries as $path) {
        if (!is_file($path) || !str_ends_with($path, '.json')) {
            continue;
        }
        $pin = _stattic_runtime_read_json($path);
        if (!is_array($pin)) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'blob_gc_pin_unreadable',
                'space_id' => $spaceId,
                'pin' => basename($path),
            ]);
            return null;
        }
        // Unparseable (or absent) expiry is treated as EXPIRED, never as
        // "forever": a pin suppresses collection, so dropping one we cannot
        // reason about is the failure that costs nothing.
        $expiresAt = _stattic_record_store_timestamp($pin['expires_at'] ?? null);
        if ($expiresAt === null || $expiresAt < $now) {
            unlink($path);
            continue;
        }
        foreach (is_array($pin['shas'] ?? null) ? $pin['shas'] : [] as $sha) {
            if (is_string($sha) && _stattic_is_sha256_hex($sha)) {
                $pinned[$sha] = true;
            }
        }
    }
    return $pinned;
}

// The CAS of a real space holds hundreds of thousands of blobs (431k files in
// one measured production space), so nothing here may materialize one array
// entry per blob of the space: that map exhausts the 512MB request limit. Scans
// stream one 2-hex prefix directory (1/256 of the CAS) at a time.

function _stattic_tier_space_blobs_root(string $privateRoot, string $spaceId): string
{
    $blobsRoot = _stattic_space_root($privateRoot, $spaceId) . '/blobs';
    _stattic_runtime_assert_private_path($blobsRoot);
    return $blobsRoot;
}

/**
 * The 2-hex prefix directories present in one space's CAS, ascending.
 *
 * @return list<string>|null null when the CAS root exists but is unreadable.
 */
function _stattic_tier_space_blob_prefixes(string $privateRoot, string $spaceId): ?array
{
    $blobsRoot = _stattic_tier_space_blobs_root($privateRoot, $spaceId);
    $entries = _stattic_runtime_directory_entries($blobsRoot);
    if ($entries === null) {
        return null;
    }
    $prefixes = [];
    foreach ($entries as $path) {
        $entry = basename($path);
        if (preg_match('/^[a-f0-9]{2}$/', $entry) === 1 && is_dir($path)) {
            $prefixes[] = $entry;
        }
    }
    return $prefixes;
}

/**
 * One prefix directory of a space's CAS, as {sha => ['size', 'demoted_at']}.
 * `size` is null when the body is gone but its demote mark survives: the
 * tombstone saying these bytes are in S3 and only a promote brings them back.
 * Only the size is kept, never the full stat record, because this map exists
 * once per prefix and its callers need nothing else per blob.
 *
 * @return array<string, array{size: int|null, demoted_at: int|null}>|null
 */
function _stattic_tier_prefix_blobs(string $blobsRoot, string $prefix): ?array
{
    $entries = scandir($blobsRoot . '/' . $prefix);
    if (!is_array($entries)) {
        return null;
    }
    $blobs = [];
    foreach ($entries as $entry) {
        $isMark = str_ends_with($entry, STATTIC_BLOB_DEMOTE_MARK_SUFFIX);
        $sha = $isMark ? substr($entry, 0, -strlen(STATTIC_BLOB_DEMOTE_MARK_SUFFIX)) : $entry;
        if (!_stattic_is_sha256_hex($sha) || !str_starts_with($sha, $prefix)) {
            continue;
        }
        $path = $blobsRoot . '/' . $prefix . '/' . $sha;
        $blobs[$sha] ??= ['size' => null, 'demoted_at' => null];
        if ($isMark) {
            $markedAt = filemtime($path . STATTIC_BLOB_DEMOTE_MARK_SUFFIX);
            $blobs[$sha]['demoted_at'] = $markedAt === false ? 0 : (int) $markedAt;
            continue;
        }
        $stat = stat($path);
        if (is_array($stat) && (((int) ($stat['mode'] ?? 0)) & 0170000) === 0100000) {
            $blobs[$sha]['size'] = (int) ($stat['size'] ?? 0);
        }
    }
    return $blobs;
}

function _stattic_tier_gc_marks_path(string $privateRoot, string $spaceId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/gc/marks.json';
}

/**
 * Pass 2. Every deletion in $deletions is `[sha, path, drop_mark, size]`,
 * applied in batches under one short per-space write lock each, so the
 * O(all blobs) scan above never holds the lock a publish needs. Nothing is
 * journaled from inside the lock; the caller emits one record for the pass.
 *
 * @return array{collected: list<string>, evicted: list<string>, bytes: int, complete: bool}
 */
function _stattic_tier_gc_apply_deletions(string $privateRoot, string $spaceId, array $deletions): array
{
    $collected = [];
    $evicted = [];
    $bytes = 0;
    $complete = true;
    foreach (array_chunk($deletions, STATTIC_TIER_GC_DELETE_BATCH) as $batch) {
        $applied = _stattic_space_write_lock_with(
            $privateRoot,
            $spaceId,
            STATTIC_LOCK_TRY,
            null,
            static function () use ($batch, &$collected, &$evicted, &$bytes): bool {
                foreach ($batch as $deletion) {
                    [$sha, $path, $dropMark, $size] = $deletion;
                    if (is_file($path) && !unlink($path)) {
                        continue;
                    }
                    // A garbage blob leaves with its mark; an evicted one keeps
                    // it, because the mark is the evidence that S3 already holds
                    // these bytes.
                    if ($dropMark) {
                        $demoteMark = _stattic_storage_blob_demote_mark_path($path);
                        if (is_file($demoteMark)) {
                            unlink($demoteMark);
                        }
                        $collected[] = $sha;
                    } else {
                        $evicted[] = $sha;
                    }
                    $bytes += $size;
                }
                return true;
            },
        );
        if ($applied !== true) {
            // Contended: try-once by contract, so this batch retries next scan.
            $complete = false;
        }
    }
    return ['collected' => $collected, 'evicted' => $evicted, 'bytes' => $bytes, 'complete' => $complete];
}

/**
 * One space's full GC pass, streamed one CAS prefix at a time. Deleting is a
 * two-tick decision: a blob is removed only after it was ALREADY a candidate
 * (in gc/marks.json) one grace period ago, so a publish that declares a blob
 * between two ticks cannot lose it to an earlier scan.
 *
 * @return array{complete: bool, deleted: int, bytes: int}
 */
function _stattic_tier_space_blob_gc(string $privateRoot, string $spaceId, int $now, int $grace): array
{
    $skipped = ['complete' => false, 'deleted' => 0, 'bytes' => 0];
    $prefixes = _stattic_tier_space_blob_prefixes($privateRoot, $spaceId);
    if ($prefixes === null) {
        return $skipped;
    }
    $live = _stattic_tier_space_live_shas($privateRoot, $spaceId);
    if ($live === null) {
        return $skipped;
    }
    $pinned = _stattic_tier_space_pinned_shas($privateRoot, $spaceId, $now);
    if ($pinned === null) {
        return $skipped;
    }

    $blobsRoot = _stattic_tier_space_blobs_root($privateRoot, $spaceId);
    $marksPath = _stattic_tier_gc_marks_path($privateRoot, $spaceId);
    $stored = _stattic_runtime_read_json($marksPath);
    if ($stored === false) {
        return $skipped;
    }
    $storedMarks = is_array($stored) ? $stored : [];
    $deadline = $now - max(0, $grace);
    // With grace pinned to 0 both passes run inside one tick, so the two-pass
    // rule closes to nothing and the GC would delete bytes a publish is mid-way
    // through committing. The undeclared lane keeps a floor of its own.
    $undeclaredDeadline = $now - max(0, $grace, _stattic_tier_gc_undeclared_min_grace_seconds());

    $marks = [];
    $collected = [];
    $evicted = [];
    $bytes = 0;
    $complete = true;
    foreach ($prefixes as $prefix) {
        $blobs = _stattic_tier_prefix_blobs($blobsRoot, $prefix);
        if ($blobs === null) {
            // An unreadable prefix makes the space unreasonable-about, same as
            // an unreadable declaration: touch nothing more, persist nothing.
            return $skipped;
        }
        $deletions = [];
        foreach ($blobs as $sha => $blob) {
            // Candidacy first: every present non-live sha carries a mark, its
            // stored first-seen time if it already had one, $now otherwise. A
            // stored mark whose blob became live again or left the disk is
            // dropped by construction, never re-added.
            $firstSeen = null;
            if (!isset($live[$sha])) {
                $storedSeen = $storedMarks[$sha] ?? null;
                $firstSeen = is_int($storedSeen) ? $storedSeen : $now;
                $marks[$sha] = $firstSeen;
            }
            if (isset($pinned[$sha])) {
                continue;
            }
            $path = $blobsRoot . '/' . $prefix . '/' . $sha;
            if ($blob['size'] === null) {
                // Body gone, only the demote sidecar left. For a LIVE sha that
                // tombstone is the evidence S3 holds these bytes; for one
                // nothing declares any more it is residue.
                if ($firstSeen !== null && $firstSeen <= $undeclaredDeadline) {
                    $deletions[] = [$sha, $path, true, 0];
                }
                continue;
            }
            if (isset($live[$sha])) {
                // Live but demoted: the bytes are in S3. Body goes, mark stays.
                if ($blob['demoted_at'] !== null && $blob['demoted_at'] <= $deadline) {
                    $deletions[] = [$sha, $path, false, $blob['size']];
                }
                continue;
            }
            // Garbage: nothing declares it, so the mark clock is the only
            // clock. The two-pass rule plus the undeclared floor is the
            // protection. A hot PREVIEW blob is not spared; the next request
            // regenerates it.
            if ($firstSeen <= $undeclaredDeadline) {
                $deletions[] = [$sha, $path, true, $blob['size']];
            }
        }
        if ($deletions === []) {
            continue;
        }
        $applied = _stattic_tier_gc_apply_deletions($privateRoot, $spaceId, $deletions);
        $collected = [...$collected, ...$applied['collected']];
        $evicted = [...$evicted, ...$applied['evicted']];
        $bytes += $applied['bytes'];
        $complete = $complete && $applied['complete'];
    }

    $deleted = [...$collected, ...$evicted];
    if ($deleted !== []) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'local_blob_gc',
            'space_id' => $spaceId,
            'bytes' => $bytes,
            'collected' => $collected,
            'evicted' => $evicted,
        ]);
    }
    // Only shas this pass removed lose their mark: a batch the lock refused
    // keeps its elapsed grace, so contention delays a deletion by one scan
    // instead of restarting its clock.
    foreach ($deleted as $sha) {
        unset($marks[$sha]);
    }
    if ($marks !== $storedMarks && !($marks === [] && !is_array($stored))) {
        _stattic_runtime_write_json_atomic($marksPath, $marks);
    }
    return ['complete' => $complete, 'deleted' => count($deleted), 'bytes' => $bytes];
}

function _stattic_tier_space_ids(string $privateRoot): array|false
{
    _stattic_runtime_assert_private_path($privateRoot . '/spaces');
    $roots = _stattic_runtime_space_roots($privateRoot);
    if ($roots === null) {
        return false;
    }
    $spaceIds = [];
    foreach ($roots as $path) {
        $entry = basename($path);
        if (_stattic_runtime_id_valid($entry)) {
            $spaceIds[] = $entry;
        }
    }
    return $spaceIds;
}

function _stattic_tier_local_blob_gc_run(string $privateRoot, int $now, int $grace, int $scanInterval, ?callable $clock = null): void
{
    $marker = $privateRoot . '/runtime/blob-gc.marker';
    _stattic_runtime_assert_private_path($marker);
    _stattic_runtime_mkdir_soft(dirname($marker));

    _stattic_sweep_throttled(
        $marker,
        // Grace zero means "collect on every call" (test/ops pin).
        $grace === 0 ? 0 : max(0, $scanInterval),
        static function () use ($privateRoot, $now, $grace): bool {
            $spaceIds = _stattic_tier_space_ids($privateRoot);
            if (!is_array($spaceIds)) {
                return false;
            }
            $complete = true;
            foreach ($spaceIds as $spaceId) {
                if (!_stattic_tier_space_blob_gc($privateRoot, $spaceId, $now, $grace)['complete']) {
                    $complete = false;
                }
            }
            return $complete;
        },
        STATTIC_SWEEP_ADVANCE_ON_COMPLETE,
        $now,
        $clock,
    );
}

function _stattic_runtime_job_housekeeping_local_blob_gc(string $privateRoot, array $claims = []): void
{
    $lockPath = $privateRoot . '/runtime/blob-gc.lock';
    _stattic_runtime_mkdir(dirname($lockPath));
    _stattic_lock_with(
        $lockPath,
        STATTIC_LOCK_TRY,
        null,
        static function () use ($privateRoot): void {
            _stattic_tier_local_blob_gc_run(
                $privateRoot,
                time(),
                _stattic_tier_gc_grace_seconds(),
                _stattic_tier_gc_scan_interval_seconds(),
            );
        },
    );
}

/**
 * Uploads local blobs to their derived keys, true only when every one landed
 * AND verified.
 *
 * A SigV4 PUT signs `x-amz-content-sha256` with the object's REAL digest and the
 * key derives from that same digest, so a 2xx from a `server_verified` bucket
 * proves the bytes are at that key. `unverified` buckets make no such promise
 * and pay one HEAD plus a content-length compare.
 *
 * @param array<string,string> $blobs sha => local path
 */
function _stattic_tier_upload_blobs(string $privateRoot, string $spaceId, array $blobs): bool
{
    $bucketId = _stattic_s3_default_bucket_id();
    $bucketRow = $bucketId === null ? null : _stattic_s3_bucket_row($bucketId);
    if ($bucketRow === null) {
        return false;
    }
    $items = [];
    $sizes = [];
    foreach ($blobs as $sha => $path) {
        $key = _stattic_blob_relative_key($spaceId, (string) $sha);
        if ($key === null || !is_file($path)) {
            return false;
        }
        $sizes[$sha] = (int) filesize($path);
        $items[] = [
            'id' => (string) $sha,
            'bucket' => $bucketId,
            'key' => $key,
            'source_path' => $path,
            'sha256' => (string) $sha,
        ];
    }
    if ($items === []) {
        return true;
    }

    $results = _stattic_s3_multi_put($items, STATTIC_TIER_PUT_STREAMS);
    $serverVerified = ($bucketRow['integrity'] ?? 'unverified') === 'server_verified';
    foreach ($items as $item) {
        $sha = (string) $item['id'];
        if (($results[$sha]['ok'] ?? false) !== true) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'tier_demote_put_failed',
                'space_id' => $spaceId,
                'sha256' => $sha,
                'status' => (int) ($results[$sha]['status'] ?? 0),
                'error' => $results[$sha]['error'] ?? null,
            ]);
            return false;
        }
        if (!$serverVerified && _stattic_s3_blob_head($spaceId, $sha, $bucketId) !== $sizes[$sha]) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'tier_demote_verify_failed',
                'space_id' => $spaceId,
                'sha256' => $sha,
            ]);
            return false;
        }
    }
    return true;
}

/**
 * The one demote operation. Payload:
 *   space_id  required
 *   shas      optional list; absent means every not-yet-demoted local blob of
 *             the space
 *
 * A successful pass uploads and verifies, marks the remote copy, then unlinks
 * the local body under the space write lock. The next read promotes it into the
 * CAS again. Only LIVE blobs are demoted: a preview's CAS name is a DERIVED key
 * rather than its content hash, so a demoted preview could never be promoted
 * back.
 *
 * Known gap: a blob later collected as garbage leaves its S3 object behind.
 * Per-blob reclamation is a bucket lifecycle rule's job; the whole-space case
 * is reclaimed at delete (_stattic_tier_reclaim_space_bucket_objects).
 */
function _stattic_runtime_job_step_tier_demote(string $privateRoot, array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $spaceId = _stattic_runtime_id((string) ($payload['space_id'] ?? ''), 'space_id');
    $prefixes = _stattic_tier_space_blob_prefixes($privateRoot, $spaceId);
    if ($prefixes === null) {
        throw new StatticJobRetry('tier_demote_scan_failed');
    }
    $liveSet = _stattic_tier_space_live_set($privateRoot, $spaceId);
    if ($liveSet === null) {
        throw new StatticJobRetry('tier_demote_live_set_unreadable');
    }
    $live = $liveSet['shas'];

    $requested = null;
    if (isset($payload['shas']) && is_array($payload['shas'])) {
        $requested = [];
        foreach ($payload['shas'] as $sha) {
            if (is_string($sha) && _stattic_is_sha256_hex(strtolower($sha))) {
                $requested[strtolower($sha)] = true;
            }
        }
    }
    // No index cursor: marking a blob REMOVES it from the target set, so each
    // step takes the next N unmarked blobs. The scan streams one prefix at a
    // time and holds at most one chunk of targets; blobs past the chunk are
    // only counted, so a huge CAS costs directory walks, not memory.
    $blobsRoot = _stattic_tier_space_blobs_root($privateRoot, $spaceId);
    $chunk = [];
    $remaining = 0;
    foreach ($prefixes as $prefix) {
        $blobs = _stattic_tier_prefix_blobs($blobsRoot, $prefix);
        if ($blobs === null) {
            throw new StatticJobRetry('tier_demote_scan_failed');
        }
        foreach ($blobs as $sha => $blob) {
            if ($blob['size'] === null || $blob['demoted_at'] !== null || !isset($live[$sha])) {
                continue;
            }
            if ($requested !== null && !isset($requested[$sha])) {
                continue;
            }
            if (count($chunk) < STATTIC_TIER_DEMOTE_CHUNK) {
                $chunk[$sha] = ['path' => $blobsRoot . '/' . $prefix . '/' . $sha, 'size' => $blob['size']];
            } else {
                $remaining += 1;
            }
        }
    }

    $cursor = is_array($job['cursor'] ?? null) ? $job['cursor'] : [];
    $stats = is_array($cursor['stats'] ?? null) ? $cursor['stats'] : ['bytesMoved' => 0, 'blobCount' => 0];
    $done = (int) $stats['blobCount'];
    $total = $done + count($chunk) + $remaining;

    if ($chunk === []) {
        // A DRAINED management event, not a diagnostic: the control plane's
        // archivedBytes rollup runs off this terminal record, and the drain
        // only delivers entries carrying an event_id.
        _stattic_runtime_record_management_event(
            $privateRoot,
            is_array($job['payload']['_claims'] ?? null) ? $job['payload']['_claims'] : [],
            [
                'event' => 'space.tier.demoted',
                'space_id' => $spaceId,
                // The callback may be delivered after another publish. Only
                // versions present in this final successful scan are proven to
                // have had their complete declaration set considered here.
                'version_ids' => $requested === null ? $liveSet['version_ids'] : [],
                'bytesMoved' => (int) $stats['bytesMoved'],
                'blobCount' => (int) $stats['blobCount'],
            ]
        );
        return [
            'done' => true,
            'cursor' => ['complete' => true, 'stats' => $stats],
            'progress' => ['done' => $total, 'total' => $total],
            'result' => $stats,
        ];
    }

    $upload = [];
    foreach ($chunk as $sha => $target) {
        $upload[$sha] = $target['path'];
    }
    if (!_stattic_tier_upload_blobs($privateRoot, $spaceId, $upload)) {
        throw new StatticJobRetry('tier_demote_put_failed');
    }
    $released = _stattic_space_write_lock_with(
        $privateRoot,
        $spaceId,
        STATTIC_LOCK_WAIT,
        null,
        static function () use ($chunk): array {
            $released = [];
            foreach ($chunk as $sha => $target) {
                $path = $target['path'];
                _stattic_storage_blob_demote_mark($path, ['reason' => 'demote', 'bytes' => $target['size']]);
                if (is_file($path) && !unlink($path)) {
                    // Leave it eligible for a retry. A mark beside a body says
                    // this job already released it, so keeping the mark here
                    // would strand bytes on disk after a transient unlink.
                    unlink(_stattic_storage_blob_demote_mark_path($path));
                    throw new StatticJobRetry('tier_demote_unlink_failed');
                }
                $released[$sha] = $target['size'];
            }
            return $released;
        },
    );
    if (!is_array($released)) {
        throw new StatticJobRetry('tier_demote_lock_unavailable');
    }
    foreach ($released as $size) {
        $stats['bytesMoved'] += $size;
        $stats['blobCount'] += 1;
    }

    return [
        'done' => false,
        'cursor' => ['stats' => $stats],
        'progress' => ['done' => $done + count($chunk), 'total' => $total],
        'result' => null,
    ];
}

/**
 * Deleting a space deletes its bucket bytes too. Without this the blobs prefix
 * outlives the space forever: the keys derive from (space, sha) and nothing
 * else records them, so once the space tree is gone nothing can list them.
 *
 * Best-effort and post-response by contract: a space delete must not fail, or
 * even wait, on a bucket. The journal record is the operator's evidence, and an
 * incomplete pass names how much it left behind.
 */
function _stattic_tier_reclaim_space_bucket_objects(string $privateRoot, string $spaceId): void
{
    $bucketId = _stattic_s3_default_bucket_id();
    if ($bucketId === null || !_stattic_runtime_id_valid($spaceId)) {
        return;
    }
    $reclaimed = _stattic_s3_delete_prefix($bucketId, 'spaces/' . $spaceId . '/blobs/');
    if ($reclaimed['deleted'] === 0 && $reclaimed['complete']) {
        return;
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'space_bucket_objects_reclaimed',
        'space_id' => $spaceId,
        'bucket' => $bucketId,
        'deleted' => $reclaimed['deleted'],
        'complete' => $reclaimed['complete'],
    ]);
}

function _stattic_tier_space_disk_usage(string $spaceRoot): array
{
    // Hardlink dedupe over a million-file space: int-keyed dev/ino maps, never
    // one "dev:ino" string per file. Those strings are a memory hazard at CAS
    // scale.
    $seen = [];
    $bytes = 0;
    $inodes = 0;
    if (!is_dir($spaceRoot)) {
        return ['bytes' => 0, 'inodes' => 0];
    }
    foreach (_stattic_runtime_walk_private_files($spaceRoot) as $real) {
        $stat = stat($real);
        if (!is_array($stat)) {
            continue;
        }
        $dev = (int) ($stat['dev'] ?? 0);
        $ino = (int) ($stat['ino'] ?? 0);
        if ($ino !== 0) {
            if (isset($seen[$dev][$ino])) {
                continue;
            }
            $seen[$dev][$ino] = true;
        }
        $bytes += (int) ($stat['size'] ?? 0);
        $inodes += 1;
    }
    return ['bytes' => $bytes, 'inodes' => $inodes];
}

const STATTIC_TIER_DISK_REPORT_INTERVAL_SECONDS = 21600;

function _stattic_runtime_job_housekeeping_disk_report(string $privateRoot, array $claims = []): void
{
    $now = time();
    // null = unenumerable this tick; report nothing rather than "no spaces".
    foreach (_stattic_runtime_space_roots($privateRoot) ?? [] as $spaceRoot) {
        _stattic_runtime_assert_private_path($spaceRoot);
        $spaceId = basename($spaceRoot);
        _stattic_sweep_throttled(
            $spaceRoot . '/disk-report.marker',
            STATTIC_TIER_DISK_REPORT_INTERVAL_SECONDS,
            static function () use ($privateRoot, $spaceRoot, $spaceId, $claims, $now): void {
                $usage = _stattic_tier_space_disk_usage($spaceRoot);
                _stattic_runtime_record_management_event($privateRoot, $claims, [
                    'event' => 'space.disk.report',
                    'spaceId' => $spaceId,
                    'bytes' => $usage['bytes'],
                    'inodes' => $usage['inodes'],
                    'generatedAt' => gmdate('c', $now),
                ]);
            },
            STATTIC_SWEEP_ADVANCE_ALWAYS,
            $now,
        );
    }
}
