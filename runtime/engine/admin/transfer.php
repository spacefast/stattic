<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/s3.php';

const STATTIC_TRANSFER_BUNDLE_CHUNK_B64_LIMIT = 1500000;
const STATTIC_TRANSFER_INSTALL_STREAMS = 4;
const STATTIC_TRANSFER_PULL_RETRIES = 2;

// The space-level serving-state artifacts that travel with a transfer: listed
// by the bundle route, installed by commit, and (minus the 'routes' directory)
// the file allowlist for bundle-store paths. One list — a new artifact added
// here rides all three legs.
const STATTIC_TRANSFER_SERVING_STATE_ENTRIES = ['routes', 'hostname-intent.json', 'tombstones.json', 'policy.json', 'policy-secrets.json'];

function _stattic_transfer_staging_root(string $privateRoot, string $spaceId): string
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    return $privateRoot . '/transfer-staging/' . $spaceId;
}

function _stattic_transfer_blob_lease_path(string $privateRoot, string $spaceId, string $sha): string
{
    $key = _stattic_runtime_blob_key($sha);
    return _stattic_transfer_staging_root($privateRoot, $spaceId) . '/blob-leases/' . basename($key);
}

function _stattic_transfer_lease_blob(string $privateRoot, string $spaceId, string $sha): bool
{
    $sha = basename(_stattic_runtime_blob_key($sha));
    $lease = _stattic_transfer_blob_lease_path($privateRoot, $spaceId, $sha);
    _stattic_runtime_assert_private_path($lease);
    if (!_stattic_runtime_blob_has($privateRoot, $spaceId, $sha)) {
        // Hardlinks are the common case and keep the CAS nlink > 1. When the
        // filesystem only supports the blob-link copy fallback, the lease is
        // still a durable byte source: restore CAS before the stage step uses
        // it instead of forcing a second remote download.
        if (!is_file($lease)) {
            return false;
        }
        $leasedSha = @hash_file('sha256', $lease);
        if (!is_string($leasedSha) || !hash_equals(strtolower($sha), strtolower($leasedSha))) {
            @unlink($lease);
            return false;
        }
        $restore = _stattic_transfer_staging_root($privateRoot, $spaceId)
            . '/pull/restore-' . $sha . '-' . bin2hex(random_bytes(6));
        _stattic_runtime_mkdir(dirname($restore));
        _stattic_runtime_assert_private_path($restore);
        if (!@copy($lease, $restore)) {
            @unlink($restore);
            return false;
        }
        _stattic_runtime_blob_put($privateRoot, $spaceId, $restore, $sha);
    }
    _stattic_runtime_blob_link($privateRoot, $spaceId, $sha, $lease);
    return is_file($lease);
}

function _stattic_transfer_blob_key(string $spaceId, string $sha): string
{
    // _stattic_runtime_blob_key validates the sha (422s on malformed input)
    // AND returns the exact 'aa/sha' shape this needs — reuse its return value
    // instead of re-deriving the same substr from scratch.
    return 'spaces/' . _stattic_runtime_id($spaceId, 'space_id') . '/blobs/' . _stattic_runtime_blob_key($sha);
}

function _stattic_transfer_bundle_path(string $path): string
{
    $path = _stattic_runtime_file_path($path);
    if (
        !str_starts_with($path, 'versions/')
        && !str_starts_with($path, 'routes/')
        && !in_array($path, array_diff(STATTIC_TRANSFER_SERVING_STATE_ENTRIES, ['routes']), true)
    ) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_transfer_bundle_path', 'message' => 'Transfer bundle path is invalid.']]);
    }
    return $path;
}

function _stattic_transfer_safe_disk_path(string $path): string
{
    $path = _stattic_runtime_file_path($path);
    // Transfers move ALREADY-COMMITTED version files, and version file maps
    // legitimately carry runtime-generated artifacts under
    // `__spacefast_generated/` (e.g. theme.css) that the USER upload
    // reservation on `__spacefast*`/`__stattic*` namespaces rejects. Apply the
    // reservation to the path inside the generated namespace instead so moves
    // of spaces with generated files keep working.
    $policyPath = str_starts_with($path, '__spacefast_generated/')
        ? substr($path, strlen('__spacefast_generated/'))
        : $path;
    _stattic_runtime_assert_static_upload_path($policyPath);
    return $path;
}

function _stattic_transfer_version_ids(mixed $raw): array
{
    if (!is_array($raw) || $raw === []) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_version_ids', 'message' => 'version_ids must be a non-empty array.']]);
    }
    $versionIds = [];
    foreach ($raw as $versionId) {
        if (!is_string($versionId)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_version_ids', 'message' => 'version_ids must contain only strings.']]);
        }
        $versionIds[] = _stattic_runtime_id($versionId, 'version_id');
    }
    return array_values(array_unique($versionIds));
}

function _stattic_transfer_bucket_row(array $payload): array
{
    $bucket = is_array($payload['bucket'] ?? null) ? $payload['bucket'] : [];
    $bucketId = is_string($bucket['id'] ?? null) ? trim($bucket['id']) : '';
    $row = _stattic_s3_bucket_row($bucketId);
    if ($row === null) {
        throw new StatticJobFatal('storage_bucket_unavailable');
    }
    return $row;
}

function _stattic_transfer_shard_refs(string $privateRoot, string $spaceId, array $versionIds): array
{
    $refs = [];
    foreach (array_values($versionIds) as $versionIndex => $versionId) {
        $shardRoot = _spacefast_version_root($privateRoot, $spaceId, $versionId) . '/file-shards';
        _stattic_runtime_assert_private_path($shardRoot);
        if (!is_dir($shardRoot)) {
            throw new StatticJobFatal('transfer_source_changed');
        }
        $shards = [];
        foreach (glob($shardRoot . '/*.php') ?: [] as $path) {
            if (!is_string($path)) {
                continue;
            }
            $shard = basename($path, '.php');
            if (preg_match('/^[a-f0-9]{2}$/', $shard) === 1) {
                $shards[] = ['v' => $versionIndex, 'version_id' => $versionId, 's' => $shard, 'path' => $path];
            }
        }
        usort($shards, static fn (array $a, array $b): int => strcmp($a['s'], $b['s']));
        array_push($refs, ...$shards);
    }
    return $refs;
}

function _stattic_transfer_cursor_index(array $refs, array $cursor): int
{
    if (!isset($cursor['v'], $cursor['s'])) {
        return 0;
    }
    $cursorV = (int) $cursor['v'];
    $cursorS = (string) $cursor['s'];
    foreach ($refs as $index => $ref) {
        if ((int) $ref['v'] === $cursorV && (string) $ref['s'] === $cursorS) {
            return $index;
        }
    }
    return count($refs);
}

function _stattic_transfer_next_cursor(array $refs, int $nextIndex): array
{
    if (!isset($refs[$nextIndex])) {
        return ['complete' => true];
    }
    return ['v' => (int) $refs[$nextIndex]['v'], 's' => (string) $refs[$nextIndex]['s']];
}

function _stattic_transfer_file_entries_from_shard(string $path): array
{
    _stattic_runtime_assert_private_path($path);
    $loaded = @include $path;
    if (!is_array($loaded) || !is_array($loaded['files'] ?? null)) {
        throw new StatticJobFatal('transfer_source_changed');
    }
    return $loaded['files'];
}

function _stattic_transfer_meta_sha(array $meta): ?string
{
    $sha = is_string($meta['sha256'] ?? null) ? strtolower($meta['sha256']) : '';
    return preg_match('/^[a-f0-9]{64}$/', $sha) === 1 ? $sha : null;
}

// Extracts [sha => bucket] pairs for every representation (the base shard
// entry plus its compressed variants) in an already-decoded shard `files`
// array that carries a remote locator. Shared by delete-version's remote-sha
// collection (a flat set for ONE version, read leniently before the rm) and
// blob_report's per-shard sweep (bucket-grouped across ALL of a space's
// versions, via the strict _stattic_transfer_file_entries_from_shard reader)
// — same representation walk, different aggregation, so each caller keeps its
// own shard-loading strategy.
function _stattic_transfer_shard_remote_shas(array $files): array
{
    $result = [];
    foreach ($files as $meta) {
        if (!is_array($meta)) {
            continue;
        }
        foreach ([$meta, ...array_values(is_array($meta['compressed'] ?? null) ? $meta['compressed'] : [])] as $candidate) {
            if (!is_array($candidate) || !is_array($candidate['remote'] ?? null)) {
                continue;
            }
            $sha = _stattic_transfer_meta_sha($candidate);
            if ($sha !== null) {
                $result[$sha] = (string) ($candidate['remote']['bucket'] ?? '');
            }
        }
    }
    return $result;
}

function _stattic_transfer_remote_matches(array $meta, string $bucketId, string $key): bool
{
    $remote = is_array($meta['remote'] ?? null) ? $meta['remote'] : null;
    return $remote !== null && ($remote['bucket'] ?? null) === $bucketId && ($remote['key'] ?? null) === $key;
}

// The read-back verify below (and the server-side 400 on 'server_verified'
// endpoints) only proves the bucket object matches WHATEVER local bytes we
// handed to _stattic_s3_multi_put — on an 'unverified' endpoint that self-
// validates post-record local bit-rot: corrupt local bytes upload cleanly
// under the recorded (still-correct) sha and read back as matching, because
// both sides of the read-back trace to the same corrupt file. Guard the
// actual invariant — local bytes must hash to the sha they're being PUT
// under — BEFORE the network PUT, so we never poison the shared CAS
// keyspace with bytes under the wrong key (other spaces/versions dedupe
// against this exact key via HEAD-skip and would inherit the corruption).
// Fatal, not retry: retrying resends the same corrupt local bytes.
function _stattic_transfer_assert_source_matches_sha(array $bucketRow, string $sourcePath, string $expectedSha): void
{
    if (($bucketRow['integrity'] ?? 'unverified') === 'server_verified') {
        // Server-side hash verification on PUT (400 on mismatch) is the guard.
        return;
    }
    $actual = hash_file('sha256', $sourcePath);
    if (!is_string($actual) || !hash_equals($expectedSha, $actual)) {
        throw new StatticJobFatal('transfer_source_corrupted');
    }
}

function _stattic_transfer_verify_put(array $bucketRow, string $key, string $sourcePath, int $size): bool
{
    if (($bucketRow['integrity'] ?? 'unverified') === 'server_verified') {
        return true;
    }
    if ($size === 0) {
        // Zero-byte blobs (.nojekyll-style placeholders): any ranged read of a
        // 0-byte object is unsatisfiable (416), so the ranged read-back below
        // would report a good upload as corrupt forever. The PUT already
        // signed the real (empty) payload hash; a HEAD confirming the stored
        // object is 0 bytes is the strongest read-back available.
        $head = _stattic_s3_head($bucketRow, $key);
        return $head['ok'] && (int) ($head['headers']['content-length'] ?? -1) === 0;
    }
    $headLen = min(65536, $size);
    $head = _stattic_s3_get($bucketRow, $key, ['range' => 'bytes=0-' . max(0, $headLen - 1)]);
    if (!$head['ok'] || strlen((string) $head['body']) !== $headLen) {
        return false;
    }
    $localHead = file_get_contents($sourcePath, false, null, 0, $headLen);
    if (!is_string($localHead) || !hash_equals($localHead, (string) $head['body'])) {
        return false;
    }
    if ($size <= $headLen) {
        return true;
    }
    $tailLen = min(65536, $size);
    $start = max(0, $size - $tailLen);
    $tail = _stattic_s3_get($bucketRow, $key, ['range' => 'bytes=' . $start . '-' . ($size - 1)]);
    if (!$tail['ok'] || strlen((string) $tail['body']) !== $tailLen) {
        return false;
    }
    $localTail = file_get_contents($sourcePath, false, null, $start, $tailLen);
    return is_string($localTail) && hash_equals($localTail, (string) $tail['body']);
}

// Maps pending upload items ({sha, key, source, mime}) into the
// _stattic_s3_multi_put wire shape and runs one batched call at the shared
// install-stream concurrency, returning the per-item results keyed by sha.
// Shared by the push and demote steppers — each caller keeps its OWN failure
// handling (push read-back-verifies then throws transfer_push_failed, demote
// throws tier_demote_put_failed).
function _stattic_transfer_multi_put_blobs(string $bucketId, array $items): array
{
    return _stattic_s3_multi_put(
        array_map(
            static fn (array $item): array => [
                'id' => $item['sha'],
                'bucket' => $bucketId,
                'key' => $item['key'],
                'source_path' => $item['source'],
                'sha256' => $item['sha'],
                'content_type' => $item['mime'],
            ],
            array_values($items),
        ),
        STATTIC_TRANSFER_INSTALL_STREAMS,
    );
}

function _stattic_runtime_job_step_space_transfer_push(string $privateRoot, array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $spaceId = _stattic_runtime_id((string) ($payload['space_id'] ?? ''), 'space_id');
    $versionIds = _stattic_transfer_version_ids($payload['version_ids'] ?? null);
    $bucketRow = _stattic_transfer_bucket_row($payload);
    $bucketId = (string) $bucketRow['id'];
    $refs = _stattic_transfer_shard_refs($privateRoot, $spaceId, $versionIds);
    $cursor = is_array($job['cursor'] ?? null) ? $job['cursor'] : [];
    $index = _stattic_transfer_cursor_index($refs, $cursor);
    $stats = is_array($cursor['stats'] ?? null) ? $cursor['stats'] : [
        'blob_count' => 0,
        'bytes_pushed' => 0,
        'skipped_existing' => 0,
        'versions' => [],
    ];

    if (!isset($refs[$index])) {
        return [
            'done' => true,
            'cursor' => ['complete' => true, 'stats' => $stats],
            'progress' => ['done' => count($refs), 'total' => count($refs)],
            'result' => _stattic_transfer_push_result($versionIds, $stats),
        ];
    }

    $ref = $refs[$index];
    $files = _stattic_transfer_file_entries_from_shard($ref['path']);
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $ref['version_id']);
    $versionStats = $stats['versions'][$ref['version_id']] ?? ['version_id' => $ref['version_id'], 'file_count' => 0, 'total_bytes' => 0];

    // Pass 1: classify every entry (already-remote / missing-source / already-in-bucket
    // / needs upload) without touching the network for the PUT itself yet — this
    // mirrors the pull/promote steppers' own two-pass shape (collect a batch, then
    // one _stattic_s3_multi_put/_multi_get call) instead of one PUT+read-back-verify
    // round trip per file, serialized.
    $pending = [];
    foreach ($files as $path => $meta) {
        if (!is_string($path) || !is_array($meta)) {
            continue;
        }
        $sha = _stattic_transfer_meta_sha($meta);
        if ($sha === null) {
            continue;
        }
        $versionStats['file_count'] += 1;
        $versionStats['total_bytes'] += (int) ($meta['size'] ?? 0);
        $key = _stattic_transfer_blob_key($spaceId, $sha);
        if (_stattic_transfer_remote_matches($meta, $bucketId, $key)) {
            continue;
        }
        $diskPath = _stattic_transfer_safe_disk_path((string) ($meta['disk_path'] ?? $path));
        $source = $versionRoot . '/files/' . $diskPath;
        _stattic_runtime_assert_private_path($source);
        if (!is_file($source)) {
            throw new StatticJobFatal('transfer_source_changed');
        }
        if (_stattic_s3_exists($bucketRow, $key, 'put')) {
            $stats['skipped_existing'] += 1;
            continue;
        }
        _stattic_transfer_assert_source_matches_sha($bucketRow, $source, $sha);
        $pending[$sha] = [
            'sha' => $sha,
            'key' => $key,
            'source' => $source,
            'size' => (int) ($meta['size'] ?? filesize($source)),
            'mime' => (string) ($meta['mime'] ?? 'application/octet-stream'),
        ];
    }
    $stats['versions'][$ref['version_id']] = $versionStats;

    if ($pending !== []) {
        $put = _stattic_transfer_multi_put_blobs($bucketId, $pending);
        foreach ($pending as $sha => $item) {
            $result = $put[$sha] ?? null;
            if (!is_array($result) || ($result['ok'] ?? false) !== true || !_stattic_transfer_verify_put($bucketRow, $item['key'], $item['source'], $item['size'])) {
                throw new StatticJobRetry('transfer_push_failed');
            }
            $stats['blob_count'] += 1;
            $stats['bytes_pushed'] += $item['size'];
        }
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'transfer_push_shard',
        'space_id' => $spaceId,
        'version_id' => $ref['version_id'],
        'shard' => $ref['s'],
    ]);

    $nextIndex = $index + 1;
    $nextCursor = _stattic_transfer_next_cursor($refs, $nextIndex);
    $nextCursor['stats'] = $stats;
    $done = !empty($nextCursor['complete']);
    return [
        'done' => $done,
        'cursor' => $nextCursor,
        'progress' => ['done' => min($nextIndex, count($refs)), 'total' => count($refs)],
        'result' => $done ? _stattic_transfer_push_result($versionIds, $stats) : null,
    ];
}

function _stattic_transfer_push_result(array $versionIds, array $stats): array
{
    $versions = [];
    foreach ($versionIds as $versionId) {
        $versions[] = $stats['versions'][$versionId] ?? ['version_id' => $versionId, 'file_count' => 0, 'total_bytes' => 0];
    }
    return [
        'blob_count' => (int) ($stats['blob_count'] ?? 0),
        'bytes_pushed' => (int) ($stats['bytes_pushed'] ?? 0),
        'skipped_existing' => (int) ($stats['skipped_existing'] ?? 0),
        'versions' => $versions,
    ];
}

function _stattic_transfer_bundle_files(string $privateRoot, string $spaceId, array $versionIds, bool $includeServingState = true): array
{
    $spaceRoot = _spacefast_space_root($privateRoot, $spaceId);
    if (!is_dir($spaceRoot)) {
        _stattic_json_response(404, ['error' => ['code' => 'space_not_found', 'message' => 'Space not found.']]);
    }
    $files = [];
    foreach ($versionIds as $versionId) {
        $versionRoot = $spaceRoot . '/versions/' . $versionId;
        if (!is_dir($versionRoot)) {
            _stattic_json_response(404, ['error' => ['code' => 'version_not_found', 'message' => 'Version not found.', 'details' => ['version_id' => $versionId]]]);
        }
        foreach (_stattic_runtime_walk_private_files($versionRoot) as $real) {
            $rel = _stattic_runtime_relative_to($versionRoot, $real);
            if ($rel === '' || str_starts_with($rel, 'files/')) {
                continue;
            }
            $files[] = ['path' => 'versions/' . $versionId . '/' . $rel, 'source' => $real];
        }
    }
    foreach ($includeServingState ? STATTIC_TRANSFER_SERVING_STATE_ENTRIES : [] as $entry) {
        $source = $spaceRoot . '/' . $entry;
        if (is_dir($source)) {
            // This walk previously skipped the shared per-entry private-path
            // assert the version-root walk above already had — now both go
            // through the same asserted walker.
            foreach (_stattic_runtime_walk_private_files($source) as $real) {
                $rel = _stattic_runtime_relative_to($source, $real);
                $files[] = ['path' => $entry . '/' . $rel, 'source' => $real];
            }
        } elseif (is_file($source)) {
            $files[] = ['path' => $entry, 'source' => $source];
        }
    }
    usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
    return $files;
}

function _stattic_runtime_transfer_bundle_route(string $privateRoot): void
{
    $body = _stattic_json_body();
    $spaceId = _stattic_runtime_id((string) ($body['space_id'] ?? ''), 'space_id');
    if (($body['store'] ?? false) === true) {
        $root = _stattic_transfer_staging_root($privateRoot, $spaceId) . '/bundle';
        foreach (is_array($body['files'] ?? null) ? $body['files'] : [] as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['content_b64'] ?? null)) {
                _stattic_json_response(422, ['error' => ['code' => 'invalid_transfer_bundle', 'message' => 'Bundle files are malformed.']]);
            }
            $path = _stattic_transfer_bundle_path($file['path']);
            $content = base64_decode($file['content_b64'], true);
            if ($content === false) {
                _stattic_json_response(422, ['error' => ['code' => 'invalid_transfer_bundle', 'message' => 'Bundle content is not base64.']]);
            }
            _stattic_runtime_write_private_string($root . '/' . $path, $content);
        }
        if (($body['done'] ?? false) === true) {
            _stattic_runtime_write_json_atomic(_stattic_transfer_staging_root($privateRoot, $spaceId) . '/bundle.json', ['done' => true, 'updated_at' => gmdate('c')]);
        }
        _stattic_json_response(200, ['stored' => true]);
    }

    $versionIds = _stattic_transfer_version_ids($body['version_ids'] ?? null);
    $cursor = isset($body['cursor']) && is_string($body['cursor']) && $body['cursor'] !== '' ? max(0, (int) $body['cursor']) : 0;
    $files = _stattic_transfer_bundle_files(
        $privateRoot,
        $spaceId,
        $versionIds,
        ($body['include_serving_state'] ?? true) === true
    );
    $chunk = [];
    $bytes = 0;
    $index = $cursor;
    for (; $index < count($files); $index += 1) {
        $content = file_get_contents($files[$index]['source']);
        if (!is_string($content)) {
            continue;
        }
        $encoded = base64_encode($content);
        if ($chunk !== [] && $bytes + strlen($encoded) > STATTIC_TRANSFER_BUNDLE_CHUNK_B64_LIMIT) {
            break;
        }
        $chunk[] = ['path' => $files[$index]['path'], 'content_b64' => $encoded];
        $bytes += strlen($encoded);
    }
    _stattic_json_response(200, [
        'chunk' => ['files' => $chunk],
        'next_cursor' => $index < count($files) ? (string) $index : null,
    ]);
}

function _stattic_transfer_bundle_version_roots(string $privateRoot, string $spaceId, array $versionIds): array
{
    $bundleRoot = _stattic_transfer_staging_root($privateRoot, $spaceId) . '/bundle';
    $roots = [];
    foreach ($versionIds as $versionId) {
        $root = $bundleRoot . '/versions/' . $versionId;
        _stattic_runtime_assert_private_path($root);
        if (!is_dir($root)) {
            throw new StatticJobFatal('transfer_bundle_missing');
        }
        $roots[$versionId] = $root;
    }
    return $roots;
}

// Plan §7 "a move is metadata-only" for a shard entry the source already
// demoted: its bytes live in the space's per-space bucket keyspace, which
// travels with the space regardless of which box serves it, so an entry the
// source already flipped to `local:false` needs no pull here — only entries
// still `local` (or missing that flag entirely, i.e. never demoted) do.
function _stattic_transfer_needed_blobs(string $privateRoot, string $spaceId, array $versionIds): array
{
    $needed = [];
    foreach (_stattic_transfer_bundle_version_roots($privateRoot, $spaceId, $versionIds) as $versionId => $root) {
        foreach (glob($root . '/file-shards/*.php') ?: [] as $shardPath) {
            if (!is_string($shardPath)) {
                continue;
            }
            foreach (_stattic_transfer_file_entries_from_shard($shardPath) as $path => $meta) {
                if (!is_string($path) || !is_array($meta) || ($meta['local'] ?? true) === false) {
                    continue;
                }
                $sha = _stattic_transfer_meta_sha($meta);
                if ($sha !== null) {
                    $needed[$sha] = ['sha' => $sha, 'size' => (int) ($meta['size'] ?? 0)];
                }
            }
        }
    }
    ksort($needed, SORT_STRING);
    return array_values($needed);
}

function _stattic_runtime_job_step_space_transfer_install(string $privateRoot, array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $spaceId = _stattic_runtime_id((string) ($payload['space_id'] ?? ''), 'space_id');
    $versionIds = _stattic_transfer_version_ids($payload['version_ids'] ?? null);
    $bucketRow = _stattic_transfer_bucket_row($payload);
    $bucketId = (string) $bucketRow['id'];
    $cursor = is_array($job['cursor'] ?? null) ? $job['cursor'] : [];
    $phase = is_string($cursor['phase'] ?? null) ? $cursor['phase'] : 'pull';
    $stats = is_array($cursor['stats'] ?? null) ? $cursor['stats'] : ['blobs_pulled' => 0, 'bytes_pulled' => 0];

    if ($phase === 'pull') {
        $needed = _stattic_transfer_needed_blobs($privateRoot, $spaceId, $versionIds);
        $index = max(0, (int) ($cursor['i'] ?? 0));
        $batch = [];
        for ($i = $index; $i < count($needed) && count($batch) < STATTIC_TRANSFER_INSTALL_STREAMS; $i += 1) {
            $sha = $needed[$i]['sha'];
            if (_stattic_transfer_lease_blob($privateRoot, $spaceId, $sha)) {
                $index += 1;
                continue;
            }
            $dest = _stattic_transfer_staging_root($privateRoot, $spaceId) . '/pull/' . $sha;
            _stattic_runtime_mkdir(dirname($dest));
            $batch[] = ['id' => $sha, 'bucket' => $bucketId, 'key' => _stattic_transfer_blob_key($spaceId, $sha), 'dest_path' => $dest, 'sha256' => $sha, 'size' => $needed[$i]['size']];
        }
        if ($batch !== []) {
            $attempt = 0;
            $results = [];
            do {
                // Accumulate across attempts (latest attempt wins per sha):
                // a retry re-fetches ONLY the failed subset, so overwriting
                // $results wholesale would drop the earlier successes — their
                // blobs would never be blob_put and the cursor would skip them.
                $results = _stattic_s3_multi_get($batch, STATTIC_TRANSFER_INSTALL_STREAMS) + $results;
                $failed = array_values(array_filter($batch, static fn (array $item): bool => !is_array($results[$item['id']] ?? null) || ($results[$item['id']]['ok'] ?? false) !== true));
                $batch = $failed;
                $attempt += 1;
            } while ($batch !== [] && $attempt <= STATTIC_TRANSFER_PULL_RETRIES);
            if ($batch !== []) {
                throw new StatticJobFatal('transfer_verify_failed');
            }
            foreach ($results as $sha => $result) {
                if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                    continue;
                }
                $downloaded = _stattic_transfer_staging_root($privateRoot, $spaceId) . '/pull/' . $sha;
                _stattic_runtime_blob_put($privateRoot, $spaceId, $downloaded, (string) $sha);
                if (!_stattic_transfer_lease_blob($privateRoot, $spaceId, (string) $sha)) {
                    throw new StatticJobFatal('transfer_verify_failed');
                }
                $stats['blobs_pulled'] += 1;
                $stats['bytes_pulled'] += (int) ($result['bytes'] ?? 0);
                $index += 1;
            }
        }
        if ($index < count($needed)) {
            return ['done' => false, 'cursor' => ['phase' => 'pull', 'i' => $index, 'stats' => $stats], 'progress' => ['done' => $index, 'total' => count($needed)], 'result' => null];
        }
        return ['done' => false, 'cursor' => ['phase' => 'stage', 'stats' => $stats], 'progress' => ['done' => count($needed), 'total' => count($needed)], 'result' => null];
    }

    _stattic_transfer_stage_versions($privateRoot, $spaceId, $versionIds, is_array($payload['expected'] ?? null) ? $payload['expected'] : []);
    // Keep blob-leases until commit/abort removes the whole transaction root.
    // A worker can die after staging but before its completed job record is
    // durable; the retry replaces the staged tree, so on copy-fallback
    // filesystems the lease is the only source that can restore a GCed CAS.
    _stattic_runtime_write_json_atomic(_stattic_transfer_staging_root($privateRoot, $spaceId) . '/install.json', ['staged' => true, 'version_ids' => $versionIds, 'updated_at' => gmdate('c')]);
    _stattic_runtime_append_journal($privateRoot, ['event' => 'transfer_install_staged', 'space_id' => $spaceId, 'version_ids' => $versionIds]);
    return ['done' => true, 'cursor' => ['phase' => 'complete', 'stats' => $stats], 'progress' => ['done' => 1, 'total' => 1], 'result' => ['staged' => true, 'blobs_pulled' => (int) $stats['blobs_pulled'], 'bytes_pulled' => (int) $stats['bytes_pulled']]];
}

function _stattic_transfer_stage_versions(string $privateRoot, string $spaceId, array $versionIds, array $expected): void
{
    $bundleRoot = _stattic_transfer_staging_root($privateRoot, $spaceId) . '/bundle';
    $stageRoot = _stattic_transfer_staging_root($privateRoot, $spaceId) . '/versions';
    $expectedByVersion = [];
    foreach ($expected as $row) {
        if (is_array($row) && is_string($row['version_id'] ?? null)) {
            $expectedByVersion[$row['version_id']] = ['file_count' => (int) ($row['file_count'] ?? -1), 'total_bytes' => (int) ($row['total_bytes'] ?? -1)];
        }
    }
    foreach ($versionIds as $versionId) {
        $sourceRoot = $bundleRoot . '/versions/' . $versionId;
        $targetRoot = $stageRoot . '/' . $versionId;
        _stattic_runtime_rm_recursive($targetRoot);
        _stattic_transfer_copy_tree($sourceRoot, $targetRoot);
        $filesByPath = [];
        foreach (glob($targetRoot . '/file-shards/*.php') ?: [] as $shardPath) {
            if (!is_string($shardPath)) {
                continue;
            }
            foreach (_stattic_transfer_file_entries_from_shard($shardPath) as $path => $meta) {
                if (!is_string($path) || !is_array($meta)) {
                    continue;
                }
                $sha = _stattic_transfer_meta_sha($meta);
                if ($sha === null) {
                    continue;
                }
                if (($meta['local'] ?? true) === false) {
                    // Cold-space move: this entry's bytes were never pulled
                    // (needed_blobs skips already-demoted entries) and must
                    // NOT be linked into files/ — that would silently
                    // re-hydrate a demoted file on the new box, undoing the
                    // source's tiering. The locator metadata alone carries
                    // over; serve.php's remote fallback covers it exactly as
                    // it did on the source.
                    $filesByPath[$path] = $meta;
                    continue;
                }
                if (!_stattic_transfer_lease_blob($privateRoot, $spaceId, $sha)) {
                    // A shard entry whose blob never reached the space store is
                    // a broken stage. Dead-letter the job (the CP reverts the
                    // move) instead of letting blob_link's 404 json-response
                    // abort the whole tick and strand the job as claimed.
                    throw new StatticJobFatal('transfer_verify_failed');
                }
                $diskPath = _stattic_transfer_safe_disk_path((string) ($meta['disk_path'] ?? $path));
                _stattic_runtime_blob_link($privateRoot, $spaceId, $sha, $targetRoot . '/files/' . $diskPath);
                $filesByPath[$path] = $meta;
            }
        }
        $count = count($filesByPath);
        $bytes = array_sum(array_map(static fn (array $meta): int => (int) ($meta['size'] ?? 0), $filesByPath));
        if (isset($expectedByVersion[$versionId]) && ($expectedByVersion[$versionId]['file_count'] !== $count || $expectedByVersion[$versionId]['total_bytes'] !== $bytes)) {
            throw new StatticJobFatal('transfer_verify_failed');
        }
        _stattic_runtime_validate_version_artifacts($targetRoot, $filesByPath);
    }
}

function _stattic_transfer_copy_tree(string $source, string $target): void
{
    _stattic_runtime_assert_private_path($source);
    if (!is_dir($source)) {
        throw new StatticJobFatal('transfer_bundle_missing');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        $real = $file->getRealPath();
        if (!is_string($real)) {
            continue;
        }
        // Same per-entry escape guard as the other four private-root walks
        // (space-archive export, the two transfer-bundle listings): a symlink
        // inside the source tree resolving outside private storage is
        // rejected before it can be read.
        _stattic_runtime_assert_private_path($real);
        $rel = _stattic_runtime_relative_to($source, $real);
        $dest = $target . '/' . _stattic_runtime_file_path($rel);
        if ($file->isDir()) {
            _stattic_runtime_mkdir($dest);
        } elseif ($file->isFile()) {
            _stattic_runtime_copy_private_file($real, $dest);
        }
    }
}

function _stattic_runtime_transfer_commit_route(string $privateRoot): void
{
    $body = _stattic_json_body();
    $spaceId = _stattic_runtime_id((string) ($body['space_id'] ?? ''), 'space_id');
    $versionIds = _stattic_transfer_version_ids($body['version_ids'] ?? null);
    $liveVersionId = is_string($body['live_version_id'] ?? null) && $body['live_version_id'] !== '' ? _stattic_runtime_id($body['live_version_id'], 'version_id') : null;
    $installServingState = ($body['install_serving_state'] ?? true) === true;
    $staging = _stattic_transfer_staging_root($privateRoot, $spaceId);
    $spaceRoot = _spacefast_space_root($privateRoot, $spaceId);
    _stattic_runtime_mkdir($spaceRoot);

    foreach ($versionIds as $versionId) {
        $staged = $staging . '/versions/' . $versionId;
        $final = $spaceRoot . '/versions/' . $versionId;
        if (is_dir($final)) {
            _stattic_runtime_rm_recursive($staged);
            continue;
        }
        if (!is_dir($staged)) {
            _stattic_json_response(409, ['error' => ['code' => 'transfer_not_staged', 'message' => 'Transfer version is not staged.']]);
        }
        _stattic_runtime_mkdir(dirname($final));
        _stattic_runtime_assert_private_path($final);
        rename($staged, $final);
    }
    foreach ($installServingState ? STATTIC_TRANSFER_SERVING_STATE_ENTRIES : [] as $entry) {
        $source = $staging . '/bundle/' . $entry;
        if (file_exists($source)) {
            _stattic_runtime_rm_recursive($spaceRoot . '/' . $entry);
            if (is_dir($source)) {
                _stattic_transfer_copy_tree($source, $spaceRoot . '/' . $entry);
            } else {
                _stattic_runtime_copy_private_file($source, $spaceRoot . '/' . $entry);
            }
        }
    }
    if ($installServingState && $liveVersionId !== null && !is_file($spaceRoot . '/routes/production.json') && is_dir($spaceRoot . '/versions/' . $liveVersionId)) {
        _stattic_runtime_write_route($privateRoot, $spaceId, 'production', $liveVersionId, []);
    }
    if ($installServingState) {
        _stattic_runtime_update_route_index($privateRoot, $spaceId);
    }
    _stattic_runtime_rm_recursive($staging);
    _stattic_json_response(200, ['installed' => true]);
}

function _stattic_runtime_transfer_abort_route(string $privateRoot): void
{
    $body = _stattic_json_body();
    $spaceId = _stattic_runtime_id((string) ($body['space_id'] ?? ''), 'space_id');
    _stattic_runtime_rm_recursive(_stattic_transfer_staging_root($privateRoot, $spaceId));
    // Abort cancels queued/running transfer work only; completed jobs stay in
    // the queue dir until housekeeping prunes them.
    _stattic_runtime_job_cancel_space_jobs($privateRoot, $spaceId, ['space_transfer_push', 'space_transfer_install'], 'transfer_aborted', time());
    _stattic_json_response(200, ['aborted' => true]);
}
