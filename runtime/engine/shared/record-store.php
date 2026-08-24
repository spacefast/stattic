<?php
declare(strict_types=1);

require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/storage.php';

// THE id-keyed JSON record store: one directory of `{id}.json` records. Do not
// open-code glob/read/write/expire loops over such a directory. Open a store.
//
// `retention` says when sweep() may drop a record:
//   mtime_seconds  expires this long after its mtime
//   field/field_seconds  a record field carrying its own expiry
//   fallback_field/fallback_seconds  used when `field` is absent or unparseable;
//     an unreadable record then expires
//   statuses       only these record statuses ever expire
//   throttle_seconds/marker  sweep cadence
function _stattic_record_store(string $root, array $descriptor = []): array
{
    return $descriptor + [
        'root' => rtrim($root, '/'),
        'retention' => null,
    ];
}

// For lanes whose emptiness is itself an answer: materializes the directory so
// the store is inspectable before it has ever held a record.
function _stattic_record_store_ensure(array $store): void
{
    _stattic_runtime_mkdir($store['root']);
}

function _stattic_record_store_path(array $store, string $id): string
{
    $path = $store['root'] . '/' . $id . '.json';
    _stattic_runtime_assert_private_path($path);
    return $path;
}

/** @return list<string> ids in ascending id order. */
function _stattic_record_store_ids(array $store): array
{
    $ids = [];
    foreach (glob($store['root'] . '/*.json') ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        $ids[] = basename($path, '.json');
    }
    sort($ids, SORT_STRING);
    return $ids;
}

function _stattic_record_store_get(array $store, string $id): ?array
{
    $record = _stattic_runtime_read_json(_stattic_record_store_path($store, $id));
    return is_array($record) ? $record : null;
}

/** @return array<string,array> readable records keyed by id. */
function _stattic_record_store_records(array $store): array
{
    $records = [];
    foreach (_stattic_record_store_ids($store) as $id) {
        $record = _stattic_record_store_get($store, $id);
        if ($record !== null) {
            $records[$id] = $record;
        }
    }
    return $records;
}

function _stattic_record_store_put(array $store, string $id, array $record): void
{
    _stattic_runtime_write_json_atomic(_stattic_record_store_path($store, $id), $record);
}

// An exclusive create is the only atomic "first writer wins" the filesystem
// offers, so a claim never goes through the tmp+rename primitive. $expiresAt is
// stamped as the mtime, which is what a retention `mtime_seconds` of 0 reads.
// False means unclaimed: already present, OR unwritable. Callers that must tell
// those apart check the on-disk evidence.
function _stattic_record_store_claim(array $store, string $id, array $record, int $expiresAt): bool
{
    $path = _stattic_record_store_path($store, $id);
    $handle = fopen($path, 'x');
    if ($handle === false) {
        return false;
    }
    $payload = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
    $written = fwrite($handle, $payload);
    fclose($handle);
    // A short write (disk full) leaves an empty marker that reads as a valid
    // claim while recording no exp, so a jti guard would answer "ok" and drop
    // the id forever. Removing the stub sends the caller down its "no marker on
    // disk" retryable path.
    if ($written !== strlen($payload)) {
        unlink($path);
        return false;
    }
    touch($path, $expiresAt);
    return true;
}

function _stattic_record_store_delete(array $store, string $id): void
{
    // Fail closed on an empty id: it would address the store root itself, which
    // the private-path assert cannot catch (the root is inside private storage).
    if ($id === '') {
        return;
    }
    unlink(_stattic_record_store_path($store, $id));
}

// Mutate-under-lock: the critical section receives the record as it stands
// (null when absent or unreadable) and persists whatever it decides through
// put/delete on this or any store keyed by the same id.
function _stattic_record_store_mutate(array $store, string $id, callable $critical): mixed
{
    _stattic_runtime_mkdir($store['root']);
    return _stattic_lock_with(
        _stattic_lock_stripe_path($store['root'], $id),
        STATTIC_LOCK_WAIT,
        null,
        static fn (): mixed => $critical(_stattic_record_store_get($store, $id)),
    );
}

function _stattic_record_store_sweep(array $store, ?int $now = null): int
{
    $retention = $store['retention'];
    if ($retention === null || !is_dir($store['root'])) {
        return 0;
    }
    $now ??= time();
    if (
        isset($retention['throttle_seconds'])
        && !_stattic_marker_throttle($retention['marker'], (int) $retention['throttle_seconds'], $now)
    ) {
        return 0;
    }
    $swept = 0;
    foreach (_stattic_record_store_ids($store) as $id) {
        $expiresAt = _stattic_record_store_expires_at($store, $id, $retention);
        if ($expiresAt !== null && $expiresAt < $now) {
            _stattic_record_store_delete($store, $id);
            $swept += 1;
        }
    }
    return $swept;
}

// Null means never dropped: a record whose status is out of scope, or one this
// process cannot classify at all.
function _stattic_record_store_expires_at(array $store, string $id, array $retention): ?int
{
    $record = isset($retention['field']) || isset($retention['statuses'])
        ? _stattic_record_store_get($store, $id)
        : null;
    if (isset($retention['statuses']) && !in_array($record['status'] ?? null, $retention['statuses'], true)) {
        return null;
    }
    $expiresAt = null;
    if (isset($retention['mtime_seconds'])) {
        $mtime = filemtime(_stattic_record_store_path($store, $id));
        if ($mtime === false) {
            return null;
        }
        $expiresAt = $mtime + (int) $retention['mtime_seconds'];
    }
    if (!isset($retention['field'])) {
        return $expiresAt;
    }
    $fieldAt = _stattic_record_store_timestamp($record[$retention['field']] ?? null);
    if ($fieldAt !== null) {
        $fieldAt += (int) ($retention['field_seconds'] ?? 0);
    } elseif (isset($retention['fallback_field'])) {
        $fallbackAt = _stattic_record_store_timestamp($record[$retention['fallback_field']] ?? null);
        $fieldAt = $fallbackAt === null ? 0 : $fallbackAt + (int) ($retention['fallback_seconds'] ?? 0);
    }
    if ($fieldAt === null) {
        return $expiresAt;
    }
    return $expiresAt === null ? $fieldAt : max($expiresAt, $fieldAt);
}

function _stattic_record_store_timestamp(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (int) $value;
    }
    $parsed = strtotime($value);
    return $parsed === false ? null : $parsed;
}
