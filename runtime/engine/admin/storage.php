<?php
declare(strict_types=1);

// Owner surface for the per-space storage system. Every rule about records and
// bodies lives in runtime/storage.php.
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/storage-policy.generated.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../runtime/storage.php';

const STATTIC_UPLOADS_OWNER_PAGE_DEFAULT = 50;
const STATTIC_UPLOADS_OWNER_PAGE_MAX = 100;

function _stattic_storage_list(string $privateRoot, string $spaceId): void
{
    if (!is_dir(_stattic_space_root($privateRoot, $spaceId))) {
        _stattic_problem_response(404, 'storage_unavailable', 'Storage is unavailable for this space.');
    }

    $limit = _stattic_uploads_owner_limit();
    $after = _stattic_uploads_owner_cursor();
    $store = _stattic_uploads_store($privateRoot, $spaceId);
    $readKey = _stattic_storage_read_key($privateRoot);

    // Ids come back in ascending order, which is the cursor's ordering.
    $objects = [];
    $hasMore = false;
    foreach (_stattic_record_store_ids($store) as $id) {
        if (!_stattic_uploads_id_valid($id)) {
            continue;
        }
        if ($after !== null && strcmp($id, $after) <= 0) {
            continue;
        }
        $record = _stattic_uploads_record(_stattic_record_store_get($store, $id));
        if ($record === null) {
            continue;
        }
        if (count($objects) >= $limit) {
            $hasMore = true;
            break;
        }
        $objects[] = _stattic_uploads_owner_object($id, $record, $readKey);
    }

    $lastObject = array_last($objects);
    $lastId = is_array($lastObject) && is_string($lastObject['id'] ?? null) ? $lastObject['id'] : null;

    _stattic_json_response(200, [
        'objects' => $objects,
        'cursor' => $hasMore && $lastId !== null
            ? _stattic_uploads_owner_encode_cursor($lastId)
            : null,
        'hasMore' => $hasMore,
    ]);
}

function _stattic_storage_read_key_get(string $privateRoot): void
{
    _stattic_json_response(200, ['key' => _stattic_storage_read_key($privateRoot)]);
}

// The emergency lever: mint a new runtime-wide read key and purge every
// hostname on the box, so no edge copy keeps serving under an old-key URL.
// Every URL handed out before this answers 404 afterwards; clients converge by
// re-reading served config.
function _stattic_storage_read_key_rotate(string $privateRoot): void
{
    $key = bin2hex(random_bytes(16));
    $rotatedAt = gmdate('c');
    _sf_json_write($privateRoot . '/runtime/storage-read-key.json', [
        'key' => $key,
        'rotated_at' => $rotatedAt,
    ]);

    // An unenumerable space tree must fail the rotation loudly: completing it
    // while purging nothing would leave every old-key URL live in the edge with
    // the rotated key already active.
    $spaceRoots = _stattic_runtime_space_roots($privateRoot);
    if ($spaceRoots === null) {
        _stattic_problem_response(503, 'storage_rotation_purge_unavailable', 'Space enumeration failed; the rotation purge could not be planned.');
    }
    $hostnames = [];
    foreach ($spaceRoots as $spaceRoot) {
        foreach (_stattic_runtime_space_event_hostnames($spaceRoot) as $hostname) {
            $hostnames[$hostname] = true;
        }
    }
    $purge = ['status' => 'ok', 'mode' => 'none'];
    if ($hostnames !== []) {
        require_once __DIR__ . '/../shared/purge.php';
        // Domain-scope purge (empty paths). Known residual: the provider bridge
        // purges secondary hostnames at '/' only — pre-existing, applies to
        // every space mutation, documented rather than engineered around.
        $purge = _stattic_runtime_purge_now($privateRoot, [
            'hostnames' => array_keys($hostnames),
            'paths' => [],
            'reason' => 'storage_read_key_rotated',
        ]);
    }
    _stattic_json_response(200, ['key' => $key, 'rotatedAt' => $rotatedAt, 'purge' => $purge]);
}

function _stattic_storage_object_delete(string $privateRoot, string $spaceId, string $objectId): void
{
    if (!_stattic_uploads_id_valid($objectId)) {
        _stattic_problem_response(404, 'storage_object_not_found', 'Storage object not found.');
    }
    if (!is_dir(_stattic_space_root($privateRoot, $spaceId))) {
        _stattic_problem_response(404, 'storage_unavailable', 'Storage is unavailable for this space.');
    }
    // The route already holds the per-space write lock, so the delete must not
    // take it a second time.
    $deleted = _stattic_uploads_delete_record($privateRoot, $spaceId, $objectId, false);
    if ($deleted) {
        // Same revocation as the visitor lane's delete: the keyed public URL
        // opts into the edge, so the record's removal must drop the shared
        // copy too. Deferred past the response on FPM (purge_now).
        require_once __DIR__ . '/../shared/purge.php';
        _stattic_runtime_purge_space_paths_now(
            $privateRoot,
            $spaceId,
            [STATTIC_UPLOADS_PUBLIC_URL_PREFIX . $objectId],
            'storage_object_deleted',
        );
    }
    _stattic_json_response(200, ['id' => $objectId, 'deleted' => $deleted]);
}

function _stattic_uploads_owner_limit(): int
{
    return _stattic_query_limit(STATTIC_UPLOADS_OWNER_PAGE_DEFAULT, STATTIC_UPLOADS_OWNER_PAGE_MAX, 'Limit must be between 1 and 100.');
}

function _stattic_uploads_owner_cursor(): ?string
{
    $payload = _stattic_query_cursor_payload(2048, '_stattic_uploads_owner_bad_cursor');
    if ($payload === null) {
        return null;
    }
    if (
        ($payload['v'] ?? null) !== 3
        || !is_string($payload['after'] ?? null)
        || !_stattic_uploads_id_valid($payload['after'])
    ) {
        _stattic_uploads_owner_bad_cursor();
    }
    return $payload['after'];
}

function _stattic_uploads_owner_encode_cursor(string $after): string
{
    return _stattic_query_cursor_encode(['v' => 3, 'after' => $after]);
}

function _stattic_uploads_owner_bad_cursor(): never
{
    _stattic_problem_response(422, 'validation_error', 'Storage cursor is invalid for this query.');
}

function _stattic_uploads_owner_object(string $id, array $record, string $readKey): array
{
    return [
        'id' => $id,
        'contentType' => $record['contentType'],
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($record['createdAt'])),
        'size' => $record['size'],
        'uploaderId' => $record['uploaderId'],
        'sha256' => $record['sha256'],
        // Path-relative and fresh at response time; the caller absolutizes
        // against whichever of the space's hostnames it is presenting.
        'url' => STATTIC_UPLOADS_PUBLIC_URL_PREFIX . $id . '?k=' . $readKey,
    ];
}
