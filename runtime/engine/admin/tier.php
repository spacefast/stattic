<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/transfer.php';

const STATTIC_TIER_PROMOTE_STREAMS = 4;
const STATTIC_TIER_REPORT_CHUNK = 2000;

// Default is a few seconds, not zero (§17): a reader that resolved the
// pre-rewrite shard array has NO remote locator to fall back to, so the
// grace window must cover lookup→fopen on its own — open FDs survive the
// unlink after that (probe-confirmed). Tests pin the knob to 0.
const STATTIC_TIER_DEFAULT_GRACE_SECONDS = 5;

function _stattic_tier_grace_seconds(): int
{
    // min=0: tests pin this to 0 (see above) — _stattic_config_int's default
    // min=1 floor would silently force a 1s grace and break that.
    return _stattic_config_int('SPACEFAST_TIER_DEMOTE_GRACE_SECONDS', STATTIC_TIER_DEFAULT_GRACE_SECONDS, 0);
}

function _stattic_tier_remote_for(string $spaceId, string $sha, string $bucketId, string $enc = 'identity'): array
{
    return [
        'bucket' => $bucketId,
        'key' => _stattic_transfer_blob_key($spaceId, $sha),
        'enc' => $enc,
    ];
}

function _stattic_tier_write_shard(string $shardPath, array $files): void
{
    _stattic_runtime_assert_private_path($shardPath);
    $loaded = @include $shardPath;
    if (!is_array($loaded) || !is_array($loaded['files'] ?? null)) {
        throw new StatticJobFatal('tier_source_changed');
    }
    $loaded['files'] = $files;
    _stattic_runtime_write_php_atomic($shardPath, $loaded);
}

// One demotable/promotable unit: a top-level shard entry OR an embedded
// precompressed representation. identity/.br/.gz are independent entries with
// their own shas and locators (contract §26) — the embedded copy inside the
// base entry must flip local/remote itself, never by cross-shard inference
// (the sidecar's own top-level entry usually hashes into a DIFFERENT shard).
function _stattic_tier_shard_representations(array $files): array
{
    $reps = [];
    foreach ($files as $path => $meta) {
        if (!is_string($path) || !is_array($meta)) {
            continue;
        }
        $reps[] = ['kind' => 'entry', 'path' => $path, 'encoding' => null, 'meta' => $meta];
        foreach (is_array($meta['compressed'] ?? null) ? $meta['compressed'] : [] as $encoding => $compressed) {
            if (is_array($compressed)) {
                $reps[] = ['kind' => 'compressed', 'path' => $path, 'encoding' => (string) $encoding, 'meta' => $compressed];
            }
        }
    }
    return $reps;
}

function _stattic_tier_store_representation(array $files, array $rep, array $meta): array
{
    if ($rep['kind'] === 'entry') {
        $files[$rep['path']] = $meta;
        return $files;
    }
    if (is_array($files[$rep['path']] ?? null) && is_array($files[$rep['path']]['compressed'] ?? null)) {
        $files[$rep['path']]['compressed'][$rep['encoding']] = $meta;
    }
    return $files;
}

function _stattic_tier_representation_enc(array $rep): string
{
    if ($rep['kind'] !== 'compressed') {
        return 'identity';
    }
    return $rep['encoding'] === 'gzip' ? 'gz' : (string) $rep['encoding'];
}

// Unlinks + emits any pending demote groups whose grace expired. Contract A2
// order: PUT → verify → shard rewrite → grace → unlink → callback — the
// callback is LAST so the control plane only counts refcounts/archivedBytes
// for shards whose hardlinks were actually released.
function _stattic_tier_demote_flush_groups(string $privateRoot, array $job, string $spaceId, string $versionId, string $versionRoot, array $groups, array $stats): array
{
    $now = time();
    $remaining = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        if ($now < (int) ($group['grace_until'] ?? 0)) {
            $remaining[] = $group;
            continue;
        }
        foreach (is_array($group['unlink'] ?? null) ? $group['unlink'] : [] as $diskPath) {
            if (!is_string($diskPath)) {
                continue;
            }
            $target = $versionRoot . '/files/' . _stattic_transfer_safe_disk_path($diskPath);
            _stattic_runtime_assert_private_path($target);
            if (is_file($target)) {
                @unlink($target);
            }
        }
        $bytesMoved = (int) ($group['bytes_moved'] ?? 0);
        $blobs = is_array($group['blobs'] ?? null) ? array_values($group['blobs']) : [];
        $stats['bytesMoved'] += $bytesMoved;
        $stats['blobCount'] += count($blobs);
        _stattic_runtime_job_emit_callback($privateRoot, $job, [
            'event' => 'space.tier.demoted',
            'spaceId' => $spaceId,
            'versionId' => $versionId,
            'shard' => (string) ($group['shard'] ?? ''),
            'bytesMoved' => $bytesMoved,
            'blobs' => $blobs,
        ]);
    }
    return ['groups' => $remaining, 'stats' => $stats];
}

function _stattic_runtime_job_step_tier_demote(string $privateRoot, array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $spaceId = _stattic_runtime_id((string) ($payload['space_id'] ?? ''), 'space_id');
    $versionId = _stattic_runtime_id((string) ($payload['version_id'] ?? ''), 'version_id');
    $bucketRow = _stattic_transfer_bucket_row($payload);
    $bucketId = (string) $bucketRow['id'];
    $refs = _stattic_transfer_shard_refs($privateRoot, $spaceId, [$versionId]);
    $cursor = is_array($job['cursor'] ?? null) ? $job['cursor'] : [];
    $index = !empty($cursor['drain']) ? count($refs) : _stattic_transfer_cursor_index($refs, $cursor);
    $stats = is_array($cursor['stats'] ?? null) ? $cursor['stats'] : ['bytesMoved' => 0, 'blobCount' => 0];
    $pendingGroups = is_array($cursor['pending_groups'] ?? null) ? $cursor['pending_groups'] : [];
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $versionId);

    $flushed = _stattic_tier_demote_flush_groups($privateRoot, $job, $spaceId, $versionId, $versionRoot, $pendingGroups, $stats);
    $pendingGroups = $flushed['groups'];
    $stats = $flushed['stats'];

    if (!isset($refs[$index])) {
        if ($pendingGroups === []) {
            return ['done' => true, 'cursor' => ['complete' => true, 'stats' => $stats], 'progress' => ['done' => count($refs), 'total' => count($refs)], 'result' => $stats];
        }
        // Grace pipeline drain: every shard is rewritten; only the trailing
        // grace window(s) remain. Yield so the tick loop doesn't spin hot.
        return [
            'done' => false,
            'yield' => true,
            'cursor' => ['drain' => true, 'stats' => $stats, 'pending_groups' => $pendingGroups],
            'progress' => ['done' => count($refs), 'total' => count($refs)],
            'result' => null,
        ];
    }

    $ref = $refs[$index];
    $files = _stattic_transfer_file_entries_from_shard($ref['path']);
    $changedShard = false;
    $unlinkPaths = [];
    $blobsBySha = [];
    $bytesMoved = 0;

    // Pass 1: classify every representation and collect the ones that need a
    // fresh PUT, WITHOUT calling _stattic_s3_multi_put yet — one batched call
    // per shard below instead of a single-item multi_put per representation.
    $toFlip = [];
    $pendingPuts = [];
    foreach (_stattic_tier_shard_representations($files) as $rep) {
        $meta = $rep['meta'];
        $sha = _stattic_transfer_meta_sha($meta);
        if ($sha === null) {
            continue;
        }
        $size = (int) ($meta['size'] ?? 0);
        $diskPath = (string) ($meta['disk_path'] ?? $rep['path']);
        $source = $versionRoot . '/files/' . _stattic_transfer_safe_disk_path($diskPath);
        $hasFile = is_file($source);
        $key = _stattic_transfer_blob_key($spaceId, $sha);
        if (($meta['local'] ?? true) === false) {
            // §17 reconcile-on-retry: a crash between the shard rewrite and the
            // cursor persist leaves local:false entries whose hardlinks were
            // never unlinked — and never emitted, because the callback is the
            // LAST step. Re-queue them for unlink + accounting.
            if ($hasFile && is_array($meta['remote'] ?? null)) {
                if (!isset($unlinkPaths[$diskPath])) {
                    $unlinkPaths[$diskPath] = true;
                    $bytesMoved += $size;
                }
                $blobsBySha[$sha] ??= ['sha' => $sha, 'bucket' => (string) ($meta['remote']['bucket'] ?? $bucketId), 'size' => $size, 'enc' => (string) ($meta['remote']['enc'] ?? _stattic_tier_representation_enc($rep))];
            }
            continue;
        }
        // tier_class already encodes the size floor (_stattic_runtime_tier_class
        // in shared/storage.php stamps 'small' below STATTIC_TIER_MIN_DEMOTE_BYTES),
        // so gating on tier_class alone is sufficient — no separate size re-check.
        if (($meta['tier_class'] ?? null) !== 'eligible') {
            continue;
        }
        $needsVerify = false;
        if ($hasFile) {
            if (!_stattic_transfer_remote_matches($meta, $bucketId, $key)) {
                $needsVerify = true;
                if (!_stattic_s3_exists($bucketRow, $key, 'put')) {
                    _stattic_transfer_assert_source_matches_sha($bucketRow, $source, $sha);
                    $pendingPuts[] = ['sha' => $sha, 'key' => $key, 'source' => $source, 'mime' => (string) ($meta['mime'] ?? 'application/octet-stream')];
                }
            }
        } elseif (!_stattic_s3_exists($bucketRow, $key, 'put')) {
            // No local bytes and no remote object (e.g. this representation's
            // file already left under another shard's entry, but the upload
            // never happened): nothing safe to flip.
            continue;
        }
        // Matches the pre-batching call site exactly: byte accounting uses
        // meta['size'] defaulting to 0, verify uses meta['size'] defaulting to
        // the actual on-disk filesize — two different fallbacks, preserved as-is.
        $verifySize = $hasFile ? (int) ($meta['size'] ?? filesize($source)) : $size;
        $toFlip[] = ['rep' => $rep, 'meta' => $meta, 'sha' => $sha, 'size' => $size, 'verifySize' => $verifySize, 'diskPath' => $diskPath, 'source' => $source, 'key' => $key, 'hasFile' => $hasFile, 'needsVerify' => $needsVerify];
    }

    if ($pendingPuts !== []) {
        $put = _stattic_transfer_multi_put_blobs($bucketId, $pendingPuts);
        foreach ($pendingPuts as $item) {
            if (($put[$item['sha']]['ok'] ?? false) !== true) {
                throw new StatticJobRetry('tier_demote_put_failed');
            }
        }
    }

    // Pass 2: verify (every hasFile+!remote_matches representation, whether or
    // not it needed a fresh PUT this round) and flip local storage metadata.
    foreach ($toFlip as $decision) {
        $rep = $decision['rep'];
        $meta = $decision['meta'];
        $sha = $decision['sha'];
        $size = $decision['size'];
        $diskPath = $decision['diskPath'];
        $hasFile = $decision['hasFile'];
        if ($decision['needsVerify'] && !_stattic_transfer_verify_put($bucketRow, $decision['key'], $decision['source'], $decision['verifySize'])) {
            throw new StatticJobRetry('tier_demote_verify_failed');
        }
        $enc = _stattic_tier_representation_enc($rep);
        $meta['remote'] = _stattic_tier_remote_for($spaceId, $sha, $bucketId, $enc);
        $meta['local'] = false;
        $files = _stattic_tier_store_representation($files, $rep, $meta);
        $changedShard = true;
        if ($hasFile) {
            if (!isset($unlinkPaths[$diskPath])) {
                $unlinkPaths[$diskPath] = true;
                $bytesMoved += $size;
            }
            $blobsBySha[$sha] ??= ['sha' => $sha, 'bucket' => $bucketId, 'size' => $size, 'enc' => $enc];
        }
    }

    if ($changedShard) {
        _stattic_tier_write_shard($ref['path'], $files);
        _stattic_runtime_append_journal($privateRoot, ['event' => 'tier_demote_shard_rewritten', 'space_id' => $spaceId, 'version_id' => $versionId, 'shard' => (string) $ref['s'], 'bytes_moved' => $bytesMoved]);
    }
    if ($unlinkPaths !== []) {
        $pendingGroups[] = [
            'shard' => (string) $ref['s'],
            'grace_until' => time() + _stattic_tier_grace_seconds(),
            'unlink' => array_keys($unlinkPaths),
            'bytes_moved' => $bytesMoved,
            'blobs' => array_values($blobsBySha),
        ];
    }

    $nextIndex = $index + 1;
    $nextCursor = _stattic_transfer_next_cursor($refs, $nextIndex);
    $cursorOut = !empty($nextCursor['complete']) ? ['drain' => true] : $nextCursor;
    $cursorOut['stats'] = $stats;
    $cursorOut['pending_groups'] = $pendingGroups;
    return [
        'done' => false,
        'cursor' => $cursorOut,
        'progress' => ['done' => min($nextIndex, count($refs)), 'total' => count($refs)],
        'result' => null,
    ];
}

function _stattic_runtime_job_step_tier_promote(string $privateRoot, array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $spaceId = _stattic_runtime_id((string) ($payload['space_id'] ?? ''), 'space_id');
    $versionIds = isset($payload['version_ids']) ? _stattic_transfer_version_ids($payload['version_ids']) : [_stattic_runtime_id((string) ($payload['version_id'] ?? ''), 'version_id')];
    $refs = _stattic_transfer_shard_refs($privateRoot, $spaceId, $versionIds);
    $cursor = is_array($job['cursor'] ?? null) ? $job['cursor'] : [];
    $index = _stattic_transfer_cursor_index($refs, $cursor);
    $stats = is_array($cursor['stats'] ?? null) ? $cursor['stats'] : ['bytesPromoted' => 0, 'blobCount' => 0];
    if (!isset($refs[$index])) {
        return ['done' => true, 'cursor' => ['complete' => true, 'stats' => $stats], 'progress' => ['done' => count($refs), 'total' => count($refs)], 'result' => $stats];
    }
    $ref = $refs[$index];
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $ref['version_id']);
    $files = _stattic_transfer_file_entries_from_shard($ref['path']);
    // Pass 1: every remotely stored representation is a promote candidate;
    // carry those decisions into the link pass below (like the demote stepper's
    // $toFlip) instead of re-filtering the representations there. The batch is
    // the deduplicated subset whose bytes are not local yet.
    $candidates = [];
    $batch = [];
    foreach (_stattic_tier_shard_representations($files) as $rep) {
        $meta = $rep['meta'];
        if (($meta['local'] ?? true) !== false || !is_array($meta['remote'] ?? null)) {
            continue;
        }
        $sha = _stattic_transfer_meta_sha($meta);
        if ($sha === null) {
            continue;
        }
        $candidates[] = ['rep' => $rep, 'meta' => $meta, 'sha' => $sha];
        if (isset($batch[$sha]) || _stattic_runtime_blob_has($privateRoot, $spaceId, $sha)) {
            continue;
        }
        $batch[$sha] = ['id' => $sha, 'bucket' => (string) $meta['remote']['bucket'], 'key' => (string) $meta['remote']['key'], 'dest_path' => $privateRoot . '/runtime/tier-promote/' . $spaceId . '/' . $sha, 'sha256' => $sha];
    }
    if ($batch !== []) {
        $batch = array_values($batch);
        foreach ($batch as $item) {
            _stattic_runtime_mkdir(dirname((string) $item['dest_path']));
        }
        $results = _stattic_s3_multi_get($batch, STATTIC_TIER_PROMOTE_STREAMS);
        foreach ($batch as $item) {
            $result = $results[$item['id']] ?? null;
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                throw new StatticJobRetry('tier_promote_get_failed');
            }
            _stattic_runtime_blob_put($privateRoot, $spaceId, (string) $item['dest_path'], (string) $item['sha256']);
        }
    }
    $changedShard = false;
    $shardBytes = 0;
    $shardBlobs = 0;
    $linkedPaths = [];
    // Pass 2: link every candidate whose bytes are local now — reps whose
    // multi-get never landed are still skipped.
    foreach ($candidates as $candidate) {
        $rep = $candidate['rep'];
        $meta = $candidate['meta'];
        $sha = $candidate['sha'];
        if (!_stattic_runtime_blob_has($privateRoot, $spaceId, $sha)) {
            continue;
        }
        $diskPath = (string) ($meta['disk_path'] ?? $rep['path']);
        $target = $versionRoot . '/files/' . _stattic_transfer_safe_disk_path($diskPath);
        $created = !is_file($target);
        _stattic_runtime_blob_link($privateRoot, $spaceId, $sha, $target);
        $meta['local'] = true;
        $files = _stattic_tier_store_representation($files, $rep, $meta);
        $changedShard = true;
        if ($created && !isset($linkedPaths[$diskPath])) {
            $linkedPaths[$diskPath] = true;
            $shardBytes += (int) ($meta['size'] ?? 0);
            $shardBlobs += 1;
        }
    }
    if ($changedShard) {
        _stattic_tier_write_shard($ref['path'], $files);
    }
    $stats['bytesPromoted'] += $shardBytes;
    $stats['blobCount'] += $shardBlobs;
    if ($shardBlobs > 0) {
        // Per-shard DELTA, not the cumulative running total: the control plane
        // subtracts bytesPromoted from versions.archivedBytes once per event,
        // so a cumulative figure would over-subtract on multi-shard promotes.
        _stattic_runtime_job_emit_callback($privateRoot, $job, [
            'event' => 'space.tier.promoted',
            'spaceId' => $spaceId,
            'versionIds' => $versionIds,
            'shard' => (string) $ref['s'],
            'bytesPromoted' => $shardBytes,
            'blobCount' => $shardBlobs,
        ]);
    }
    $nextIndex = $index + 1;
    $nextCursor = _stattic_transfer_next_cursor($refs, $nextIndex);
    if (!empty($nextCursor['complete'])) {
        return ['done' => true, 'cursor' => ['complete' => true, 'stats' => $stats], 'progress' => ['done' => count($refs), 'total' => count($refs)], 'result' => $stats];
    }
    return ['done' => false, 'cursor' => $nextCursor + ['stats' => $stats], 'progress' => ['done' => min($nextIndex, count($refs)), 'total' => count($refs)], 'result' => null];
}

function _stattic_runtime_job_step_blob_report(string $privateRoot, array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $spaceId = _stattic_runtime_id((string) ($payload['space_id'] ?? ''), 'space_id');
    $bucketId = is_string($payload['bucket_id'] ?? null) ? _stattic_runtime_id($payload['bucket_id'], 'bucket_id') : '';
    $shas = [];
    foreach (glob(_spacefast_space_root($privateRoot, $spaceId) . '/versions/*/file-shards/*.php') ?: [] as $shardPath) {
        if (!is_string($shardPath)) {
            continue;
        }
        $shardFiles = _stattic_transfer_file_entries_from_shard($shardPath);
        foreach (_stattic_transfer_shard_remote_shas($shardFiles) as $sha => $remoteBucket) {
            if ($bucketId === '' || $bucketId === $remoteBucket) {
                $shas[$remoteBucket][$sha] = true;
            }
        }
    }
    $generatedAt = gmdate('c');
    foreach ($shas as $remoteBucket => $set) {
        $list = array_keys($set);
        sort($list, SORT_STRING);
        foreach (array_chunk($list, STATTIC_TIER_REPORT_CHUNK) as $chunk) {
            _stattic_runtime_job_emit_callback($privateRoot, $job, [
                'event' => 'space.blob.report',
                'spaceId' => $spaceId,
                'bucketId' => $remoteBucket,
                'shas' => array_values($chunk),
                'generatedAt' => $generatedAt,
            ]);
        }
    }
    return ['done' => true, 'cursor' => ['complete' => true], 'progress' => ['done' => 1, 'total' => 1], 'result' => ['bucket_count' => count($shas), 'generatedAt' => $generatedAt]];
}

function _stattic_tier_gc_grace_seconds(): int
{
    // min=0: tests pin this to 0 too (see _stattic_tier_grace_seconds above).
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS', 3600, 0);
}

function _stattic_tier_gc_scan_interval_seconds(): int
{
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_SCAN_INTERVAL_SECONDS', 3600, 0);
}

function _stattic_tier_local_blob_paths(string $privateRoot): array|false
{
    $spacesRoot = $privateRoot . '/spaces';
    _stattic_runtime_assert_private_path($spacesRoot);
    if (!is_dir($spacesRoot)) {
        return file_exists($spacesRoot) || is_link($spacesRoot) ? false : [];
    }

    $spaceEntries = @scandir($spacesRoot);
    if (!is_array($spaceEntries)) {
        return false;
    }
    $blobPaths = [];
    foreach ($spaceEntries as $spaceEntry) {
        if ($spaceEntry === '.' || $spaceEntry === '..') {
            continue;
        }
        $spaceRoot = $spacesRoot . '/' . $spaceEntry;
        _stattic_runtime_assert_private_path($spaceRoot);
        if (!is_dir($spaceRoot)) {
            continue;
        }
        $spaceChildren = @scandir($spaceRoot);
        if (!is_array($spaceChildren)) {
            return false;
        }
        if (!in_array('blobs', $spaceChildren, true)) {
            continue;
        }
        $blobsRoot = $spaceRoot . '/blobs';
        _stattic_runtime_assert_private_path($blobsRoot);
        if (!is_dir($blobsRoot)) {
            return false;
        }
        $prefixEntries = @scandir($blobsRoot);
        if (!is_array($prefixEntries)) {
            return false;
        }
        foreach ($prefixEntries as $prefixEntry) {
            if (preg_match('/^[a-f0-9]{2}$/', $prefixEntry) !== 1) {
                continue;
            }
            $prefixRoot = $blobsRoot . '/' . $prefixEntry;
            _stattic_runtime_assert_private_path($prefixRoot);
            if (!is_dir($prefixRoot)) {
                continue;
            }
            $blobEntries = @scandir($prefixRoot);
            if (!is_array($blobEntries)) {
                return false;
            }
            foreach ($blobEntries as $blobEntry) {
                if ($blobEntry === '.' || $blobEntry === '..') {
                    continue;
                }
                $blobPaths[] = $prefixRoot . '/' . $blobEntry;
            }
        }
    }
    return $blobPaths;
}

function _stattic_tier_gc_marker_timestamp(mixed $marker): ?int
{
    $raw = is_array($marker) && is_string($marker['generatedAt'] ?? null)
        ? $marker['generatedAt']
        : '';
    if ($raw === '' || preg_match('/[\x00-\x1f\x7f]/', $raw) !== 0) {
        return null;
    }
    try {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $raw);
    } catch (ValueError) {
        return null;
    }
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $parsed === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $parsed->getOffset() !== 0
        || $parsed->format('Y-m-d\TH:i:sP') !== $raw
    ) {
        return null;
    }
    return $parsed->getTimestamp();
}

function _stattic_tier_local_blob_gc_run(
    string $privateRoot,
    int $now,
    int $grace,
    int $scanInterval,
    ?callable $globPaths = null,
    ?callable $statPath = null,
    ?callable $unlinkPath = null,
    ?callable $clock = null,
): void {
    $marker = $privateRoot . '/runtime/blob-gc.json';
    _stattic_runtime_assert_private_path($marker);
    $last = is_file($marker) ? _stattic_runtime_read_json($marker) : null;
    $generatedAt = _stattic_tier_gc_marker_timestamp($last);
    // Grace zero is the long-standing test/ops pin for "collect on every
    // call". Otherwise a completed scan suppresses the O(all blobs) walk for
    // one independent cadence window. A future marker (including wall-clock
    // rollback) is never trusted to suppress reclamation.
    $effectiveInterval = $grace === 0 ? 0 : max(0, $scanInterval);
    if (
        $effectiveInterval > 0
        && $generatedAt !== null
        && $generatedAt <= $now
        && $generatedAt > $now - $effectiveInterval
    ) {
        return;
    }

    $globPaths ??= static fn (string $_pattern): array|false => _stattic_tier_local_blob_paths($privateRoot);
    $statPath ??= static fn (string $path): array|false => @stat($path);
    $unlinkPath ??= static fn (string $path): bool => @unlink($path);
    $clock ??= static fn (): int => time();
    $blobPaths = $globPaths($privateRoot . '/spaces/*/blobs/[a-f0-9][a-f0-9]/*');
    if (!is_array($blobPaths)) {
        return;
    }

    $deadline = $now - max(0, $grace);
    $scanComplete = true;
    $spacesPrefix = $privateRoot . '/spaces/';
    // One lazily opened lock handle per owning space, kept open for the whole
    // scan so consecutive blobs of one space reuse a single fd.
    $spaceLocks = [];
    try {
        foreach ($blobPaths as $blobPath) {
            if (!is_string($blobPath)) {
                continue;
            }
            _stattic_runtime_assert_private_path($blobPath);
            // The blob CAS is per space (spaces/{spaceId}/blobs/...), and its
            // management-lane writers — finalize_version's
            // _stattic_runtime_commit_version_file / retained-file fallback,
            // and delete_version's hardlink teardown (which drives nlink back
            // to 1) — hold that space's spaces/{spaceId}/write.lock, NOT the
            // site-wide runtime/write.lock (see
            // _stattic_runtime_space_write_lock_scope in management.php). So
            // the per-blob exclusion barrier is the OWNING space's lock,
            // derived from the blob path: it excludes exactly that space's
            // in-flight blob_has -> blob_put -> blob_link window without
            // blocking any other space or any management write for the full
            // O(all blobs) scan. Non-blocking skip on contention, retried
            // next scan. Bulk-job blob writers are serialized with this hook
            // by the bulk lane lock.
            $segments = str_starts_with($blobPath, $spacesPrefix)
                ? explode('/', substr($blobPath, strlen($spacesPrefix)), 3)
                : [];
            $spaceId = count($segments) === 3 && $segments[1] === 'blobs' ? $segments[0] : '';
            if ($spaceId === '') {
                $scanComplete = false;
                continue;
            }
            if (!array_key_exists($spaceId, $spaceLocks)) {
                $spaceLockPath = $spacesPrefix . $spaceId . '/write.lock';
                _stattic_runtime_assert_private_path($spaceLockPath);
                $spaceLocks[$spaceId] = @fopen($spaceLockPath, 'c');
            }
            $spaceLock = $spaceLocks[$spaceId];
            if ($spaceLock === false || !@flock($spaceLock, LOCK_EX | LOCK_NB)) {
                $scanComplete = false;
                continue;
            }
            try {
                $stat = $statPath($blobPath);
                if (
                    !is_array($stat)
                    || !array_key_exists('mode', $stat)
                    || !array_key_exists('nlink', $stat)
                    || !array_key_exists('mtime', $stat)
                    || !array_key_exists('size', $stat)
                ) {
                    $scanComplete = false;
                    continue;
                }
                if (
                    (((int) $stat['mode']) & 0170000) !== 0100000
                    || (int) $stat['nlink'] !== 1
                    || (int) $stat['mtime'] > $deadline
                ) {
                    continue;
                }
                $bytes = (int) $stat['size'];
                $relativeBlobPath = _stattic_runtime_relative_private_path($privateRoot, $blobPath);
                if (!$unlinkPath($blobPath)) {
                    // This also covers a concurrent disappearance between stat and
                    // unlink: it is not this process's successful deletion, so do not
                    // journal it or advance the marker. The next tick observes the
                    // absence and completes the retry without inventing an event.
                    $scanComplete = false;
                    continue;
                }
                _stattic_runtime_append_journal($privateRoot, [
                    'event' => 'local_blob_gc',
                    'blob' => $relativeBlobPath,
                    'bytes' => $bytes,
                ]);
            } finally {
                @flock($spaceLock, LOCK_UN);
            }
        }
    } finally {
        foreach ($spaceLocks as $spaceLock) {
            if ($spaceLock !== false) {
                @fclose($spaceLock);
            }
        }
    }
    if (!$scanComplete) {
        return;
    }

    _stattic_runtime_write_json_atomic($marker, ['generatedAt' => gmdate('c', max($now, (int) $clock()))]);
}

function _stattic_runtime_job_housekeeping_local_blob_gc(string $privateRoot, array $claims = []): void
{
    $lockPath = $privateRoot . '/runtime/blob-gc.lock';
    _stattic_runtime_mkdir(dirname($lockPath));
    _stattic_runtime_assert_private_path($lockPath);
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        return;
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        return;
    }
    try {
        // If a successful scan starts at least once per interval, reclamation
        // starts within age grace + scan interval (2h with both 1h defaults),
        // plus tick scheduling jitter, this scan's duration, and any interval
        // spent retrying a filesystem failure.
        _stattic_tier_local_blob_gc_run(
            $privateRoot,
            time(),
            _stattic_tier_gc_grace_seconds(),
            _stattic_tier_gc_scan_interval_seconds(),
        );
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function _stattic_tier_space_disk_usage(string $spaceRoot): array
{
    $seen = [];
    $bytes = 0;
    $inodes = 0;
    if (!is_dir($spaceRoot)) {
        return ['bytes' => 0, 'inodes' => 0];
    }
    foreach (_stattic_runtime_walk_private_files($spaceRoot) as $real) {
        $stat = @stat($real);
        if (!is_array($stat)) {
            continue;
        }
        $key = (string) ($stat['dev'] ?? '0') . ':' . (string) ($stat['ino'] ?? $real);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $bytes += (int) ($stat['size'] ?? 0);
        $inodes += 1;
    }
    return ['bytes' => $bytes, 'inodes' => $inodes];
}

function _stattic_runtime_job_housekeeping_disk_report(string $privateRoot, array $claims = []): void
{
    $now = time();
    $staleAfter = $now - 21600;
    foreach (glob($privateRoot . '/spaces/*', GLOB_ONLYDIR) ?: [] as $spaceRoot) {
        if (!is_string($spaceRoot) || !is_dir($spaceRoot)) {
            continue;
        }
        _stattic_runtime_assert_private_path($spaceRoot);
        $spaceId = basename($spaceRoot);
        $marker = $spaceRoot . '/disk-report.json';
        $last = is_file($marker) ? _stattic_runtime_read_json($marker) : null;
        if (is_array($last) && strtotime((string) ($last['generatedAt'] ?? '')) !== false && strtotime((string) $last['generatedAt']) > $staleAfter) {
            continue;
        }
        $usage = _stattic_tier_space_disk_usage($spaceRoot);
        $event = [
            'event' => 'space.disk.report',
            'spaceId' => $spaceId,
            'bytes' => $usage['bytes'],
            'inodes' => $usage['inodes'],
            'generatedAt' => gmdate('c', $now),
        ];
        _stattic_runtime_write_json_atomic($marker, $event);
        // The tick's verified claims carry the callback scope (cron watchdog +
        // CP-driven ticks both attach one), so the report is a REAL pending
        // callback, not a journal-only note the control plane can never see.
        _stattic_runtime_record_management_event($privateRoot, $claims, $event);
    }
}

// Drains the serve-path tier-event spool (runtime/tier/pending-events/) into
// real pending callbacks. The public serve path has no callback claims of its
// own, so it spools tier_fetch_failed locally; the bulk tick — whose verified
// JWT carries the callback scope — is the producer for the control plane's
// auto-pause canary (contract B1). Without callback claims the spool is kept
// (bounded at write time) and TTL-pruned so it can't grow forever.
function _stattic_runtime_job_housekeeping_tier_events(string $privateRoot, array $claims = []): void
{
    $paths = glob($privateRoot . '/runtime/tier/pending-events/*.json') ?: [];
    if ($paths === []) {
        return;
    }
    $hasCallback = is_string($claims['callback_token'] ?? null) && trim($claims['callback_token']) !== '';
    $ttlDeadline = time() - 7 * 86400;
    foreach ($paths as $path) {
        if (!is_string($path)) {
            continue;
        }
        _stattic_runtime_assert_private_path($path);
        if (!$hasCallback) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $ttlDeadline) {
                @unlink($path);
            }
            continue;
        }
        $event = _stattic_runtime_read_json($path);
        if (is_array($event) && is_string($event['event'] ?? null) && $event['event'] !== '') {
            _stattic_runtime_record_management_event($privateRoot, $claims, $event);
        }
        @unlink($path);
    }
}

function _stattic_tier_live_route_versions(string $privateRoot, string $spaceId): array
{
    $live = [];
    foreach (glob(_spacefast_space_root($privateRoot, $spaceId) . '/routes/*.json') ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        $pointer = _stattic_runtime_read_json($path);
        if (is_array($pointer) && is_string($pointer['version_id'] ?? null)) {
            $live[$pointer['version_id']] = true;
        }
    }
    return $live;
}

function _stattic_tier_prune_policy(string $spaceRoot): array
{
    $path = $spaceRoot . '/retention-policy.json';
    if (!is_file($path)) {
        return [];
    }
    $policy = _stattic_runtime_read_json($path);
    $ids = is_array($policy) ? ($policy['prunable_version_ids'] ?? null) : null;
    return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
}

function _stattic_runtime_job_housekeeping_prune_versions(string $privateRoot, array $claims = []): void
{
    foreach (glob($privateRoot . '/spaces/*', GLOB_ONLYDIR) ?: [] as $spaceRoot) {
        if (!is_string($spaceRoot) || !is_dir($spaceRoot)) {
            continue;
        }
        $spaceId = basename($spaceRoot);
        $prunable = _stattic_tier_prune_policy($spaceRoot);
        if ($prunable === []) {
            continue;
        }
        $live = _stattic_tier_live_route_versions($privateRoot, $spaceId);
        $freedBytes = 0;
        $freedInodes = 0;
        $deleted = [];
        $refused = [];
        foreach ($prunable as $versionIdRaw) {
            $versionId = _stattic_runtime_id($versionIdRaw, 'version_id');
            if (isset($live[$versionId])) {
                $refused[] = $versionId;
                continue;
            }
            $versionRoot = $spaceRoot . '/versions/' . $versionId;
            if (!is_dir($versionRoot)) {
                continue;
            }
            $usage = _stattic_tier_space_disk_usage($versionRoot);
            $freedBytes += $usage['bytes'];
            $freedInodes += $usage['inodes'];
            _stattic_runtime_rm_recursive($versionRoot);
            $deleted[] = $versionId;
        }
        if ($deleted !== [] || $refused !== []) {
            _stattic_runtime_record_management_event($privateRoot, $claims, [
                'event' => 'space.versions.pruned',
                'spaceId' => $spaceId,
                'versionIds' => $deleted,
                'refusedVersionIds' => $refused,
                'freedBytes' => $freedBytes,
                'freedInodes' => $freedInodes,
            ]);
        }
    }
}
