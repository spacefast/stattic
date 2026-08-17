<?php
declare(strict_types=1);

// Promote-on-read (contracts §8): a stat miss on a CAS blob resolves here, and
// either the bytes are local when this returns or the caller renders 503.
//
// Loaded LAZILY from serve-fast.php — nothing here may be reachable from the
// local hot path. That laziness is what makes bootstrap-config safe to require:
// bucket credentials live in Atomic_Persistent_Data, so the miss branch pays a
// decrypt the hot path must never pay (§6, §11).
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/s3.php';
require_once __DIR__ . '/../shared/admission.php';

// Small on purpose: a cold space under a traffic spike sheds most of the spike
// instead of holding every worker on the same bucket.
const STATTIC_TIER_PROMOTE_LANE = 'tier_promote';
const STATTIC_TIER_PROMOTE_CONCURRENCY = 2;

// A promoted blob is a cache fill, not an upload: the ceiling exists so a
// corrupt or hostile object cannot fill the disk before the digest check gets
// to reject it.
const STATTIC_TIER_PROMOTE_MAX_BYTES = 1073741824; // 1 GiB

function _stattic_tier_promote_admit(string $privateRoot, string $spaceId): callable|false
{
    $lane = STATTIC_TIER_PROMOTE_LANE . ':' . _stattic_admission_sanitize_key($spaceId);
    return _stattic_admission_counter_acquire(
        $privateRoot,
        'spacefast:adm:' . $lane,
        $privateRoot . '/runtime/admission/' . str_replace(':', '-', $lane) . '.json',
        _stattic_config_int('SPACEFAST_TIER_PROMOTE_CONC_PER_SPACE', STATTIC_TIER_PROMOTE_CONCURRENCY),
        _stattic_admission_stale_seconds(),
    );
}

// Post-response and non-blocking: no visitor waits on the journal lock.
function _stattic_tier_journal(string $privateRoot, array $entry): void
{
    _stattic_defer(static function () use ($privateRoot, $entry): void {
        _stattic_runtime_append_journal($privateRoot, $entry, false);
    });
}

/**
 * The pinned seam serve-fast.php calls. Returns the local CAS path once the
 * bytes are on disk, or null when the caller must render 503 — shed, bucket
 * failure, or a digest that did not match: an object that fails verification is
 * dropped, never installed and never served.
 *
 * No lock: the CAS is content-addressed and the install is a rename, so two
 * workers promoting the same sha race to the same bytes and both win.
 */
function _stattic_tier_promote_blob(string $privateRoot, string $spaceId, string $sha256): ?string
{
    if (!_stattic_tiering_enabled()) {
        return null;
    }
    $sha256 = strtolower(trim($sha256));
    // Checked before _stattic_runtime_blob_path(), which renders a 422 problem
    // document — the wrong emitter entirely for a visitor request.
    if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !_stattic_runtime_id_valid($spaceId)) {
        _stattic_tier_journal($privateRoot, [
            'event' => 'tier_promote_failed',
            'space_id' => $spaceId,
            'sha256' => $sha256,
            'reason' => 'invalid_blob_reference',
        ]);
        return null;
    }

    $localPath = _stattic_runtime_blob_path($privateRoot, $spaceId, $sha256);
    if (is_file($localPath)) {
        return $localPath;
    }

    $bucketId = _stattic_s3_default_bucket_id();
    $locator = $bucketId === null ? null : _stattic_s3_blob_locator($bucketId, $spaceId, $sha256);
    if ($locator === null) {
        _stattic_tier_journal($privateRoot, [
            'event' => 'tier_promote_failed',
            'space_id' => $spaceId,
            'sha256' => $sha256,
            'reason' => 'storage_bucket_unavailable',
        ]);
        return null;
    }

    $release = _stattic_tier_promote_admit($privateRoot, $spaceId);
    if ($release === false) {
        _stattic_tier_journal($privateRoot, [
            'event' => 'tier_promote_shed',
            'space_id' => $spaceId,
            'sha256' => $sha256,
        ]);
        return null;
    }

    try {
        $fetched = _stattic_tier_promote_fetch($privateRoot, $locator);
    } finally {
        if (is_callable($release)) {
            $release();
        }
    }

    if (!is_string($fetched['tmp_path'] ?? null)) {
        _stattic_tier_journal($privateRoot, [
            'event' => 'tier_promote_failed',
            'space_id' => $spaceId,
            'sha256' => $sha256,
            'status' => (int) ($fetched['status'] ?? 0),
            'reason' => (string) ($fetched['reason'] ?? 's3_get_failed'),
        ]);
        return null;
    }

    // The digest is computed WHILE downloading, so a mismatch costs one unlink
    // and never a second pass over the bytes.
    if (!hash_equals($sha256, (string) ($fetched['sha256'] ?? ''))) {
        @unlink($fetched['tmp_path']);
        _stattic_tier_journal($privateRoot, [
            'event' => 'tier_promote_failed',
            'space_id' => $spaceId,
            'sha256' => $sha256,
            'actual_sha256' => (string) ($fetched['sha256'] ?? ''),
            'reason' => 'blob_sha_mismatch',
        ]);
        return null;
    }

    // Stamps the content-derived mtime before the commit rename, so the
    // promoted inode answers with exactly the ETag the compiler recorded.
    _stattic_runtime_blob_commit_verified($privateRoot, $spaceId, $fetched['tmp_path'], $sha256);
    if (!is_file($localPath)) {
        _stattic_tier_journal($privateRoot, [
            'event' => 'tier_promote_failed',
            'space_id' => $spaceId,
            'sha256' => $sha256,
            'reason' => 'blob_install_failed',
        ]);
        return null;
    }
    _stattic_tier_journal($privateRoot, [
        'event' => 'tier_promoted',
        'space_id' => $spaceId,
        'sha256' => $sha256,
        'bytes' => (int) ($fetched['size'] ?? 0),
    ]);
    return $localPath;
}

/**
 * One signed streamed GET into a staged temp file, hashed as it arrives.
 *
 * @return array{tmp_path?: string, sha256?: string, size?: int, status?: int, reason?: string}
 */
function _stattic_tier_promote_fetch(string $privateRoot, array $locator): array
{
    $stagingRoot = $privateRoot . '/runtime/blob-staging';
    if (!_stattic_runtime_mkdir_soft($stagingRoot)) {
        return ['reason' => 'staging_unavailable'];
    }
    $tmpPath = $stagingRoot . '/promote-' . bin2hex(random_bytes(12)) . '.tmp';
    $sink = _stattic_runtime_stream_sink_open($tmpPath, STATTIC_TIER_PROMOTE_MAX_BYTES, 0, 'wb');
    if ($sink === false) {
        return ['reason' => 'staging_unavailable'];
    }

    $status = 0;
    $result = _stattic_s3_stream_get(
        $locator,
        null,
        static function (int $bucketStatus, array $bucketHeaders) use (&$status): void {
            $status = $bucketStatus;
        },
        static fn (string $chunk): bool => _stattic_runtime_stream_sink_write($sink, $chunk) !== false,
    );

    if ($result['error'] !== null || $status < 200 || $status >= 300) {
        _stattic_runtime_stream_sink_abort($sink, 's3_get_failed');
        return [
            'status' => $status !== 0 ? $status : (int) $result['status'],
            'reason' => $result['error'] ?? ('s3_status_' . $status),
        ];
    }

    $staged = _stattic_runtime_stream_sink_finish($sink);
    if (($staged['ok'] ?? false) !== true) {
        return ['status' => $status, 'reason' => (string) ($staged['reason'] ?? 'staging_write_failed')];
    }
    return [
        'tmp_path' => (string) $staged['tmp_path'],
        'sha256' => (string) $staged['sha256'],
        'size' => (int) $staged['size'],
        'status' => $status,
    ];
}
