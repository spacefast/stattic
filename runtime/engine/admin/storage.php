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

/**
 * The owner's own upload lane: raw bytes in, one storage object out.
 *
 * The visitor lane (runtime/storage.php) admits a browser session and charges an
 * anonymous budget. Neither applies here — the control plane has already
 * authorized the space, and it names the person on whose behalf it is acting in
 * the signed `storage_uploader_id` claim, so a caller cannot invent an uploader.
 * The content policy IS shared: whatever a visitor may not store, an owner may
 * not store either.
 *
 * The route table gives this row the per-space write lock, so the commit below
 * must not take it again.
 */
function _stattic_storage_object_create(string $privateRoot, string $spaceId, array $claims): void
{
    if (!is_dir(_stattic_space_root($privateRoot, $spaceId))) {
        _stattic_problem_response(404, 'storage_unavailable', 'Storage is unavailable for this space.');
    }
    $staged = _stattic_storage_stage_upload($privateRoot);
    if (($staged['ok'] ?? false) !== true) {
        if (($staged['reason'] ?? null) === 'too_large') {
            _stattic_problem_response(413, 'storage_file_too_large', 'Storage uploads are limited to 5 MiB.');
        }
        if (($staged['reason'] ?? null) === 'empty') {
            _stattic_problem_response(400, 'storage_empty_file', 'Storage uploads cannot be empty.');
        }
        _stattic_problem_response(503, 'storage_unavailable', 'Storage could not persist this object.');
    }
    $tmpPath = is_string($staged['tmp_path'] ?? null) ? $staged['tmp_path'] : '';
    $contentType = is_string($_SERVER['CONTENT_TYPE'] ?? null)
        ? trim(substr($_SERVER['CONTENT_TYPE'], 0, 255))
        : '';
    if ($contentType === '') {
        $contentType = 'application/octet-stream';
    }
    if (_stattic_storage_content_blocked($contentType, is_string($staged['prefix'] ?? null) ? $staged['prefix'] : '')) {
        unlink($tmpPath);
        _stattic_problem_response(415, 'storage_content_blocked', 'Executable and active web content cannot be uploaded.');
    }

    $uploaderId = is_string($claims['storage_uploader_id'] ?? null)
        ? trim($claims['storage_uploader_id'])
        : '';
    if ($uploaderId === '' || strlen($uploaderId) > 255) {
        // The claim is the control plane's to set; a request without a usable
        // one still records who it was on behalf of at the coarsest true level.
        $uploaderId = 'owner:' . $spaceId;
    }
    $id = bin2hex(random_bytes(16));
    $record = [
        'contentType' => $contentType,
        'createdAt' => gmdate('c'),
        'filename' => _stattic_uploads_request_filename(),
        'sha256' => is_string($staged['sha256'] ?? null) ? $staged['sha256'] : '',
        'size' => is_int($staged['size'] ?? null) ? $staged['size'] : 0,
        'uploaderId' => $uploaderId,
    ];
    // Normalized BEFORE the commit: a record this reader would refuse must never
    // reach the store, where it would read as an absent object over live bytes.
    $normalized = _stattic_uploads_record($record);
    if ($normalized === null) {
        unlink($tmpPath);
        _stattic_problem_response(503, 'storage_unavailable', 'Storage could not persist this object.');
    }
    // The read key composes the URL in the response, and minting it can fail.
    // Resolve it BEFORE the commit so that failure refuses the upload instead
    // of storing an object the caller was told did not happen.
    $readKey = _stattic_storage_read_key($privateRoot);
    _stattic_storage_commit_record($privateRoot, $spaceId, $id, $tmpPath, $record);

    // The same projection the list answers with, so one object shape crosses
    // this boundary whether it was just created or read back later.
    _stattic_json_response(201, _stattic_uploads_owner_object($id, $normalized, $readKey));
}

function _stattic_storage_read_key_get(string $privateRoot): void
{
    _stattic_json_response(200, ['key' => _stattic_storage_read_key($privateRoot)]);
}

// The emergency lever: mint a new runtime-wide read key and purge every
// hostname on the box, so no edge copy keeps serving under an old-key URL.
// Every URL handed out before this answers 404 afterwards; clients sync by
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
    require_once __DIR__ . '/../shared/purge.php';
    $hostnames = [];
    foreach ($spaceRoots as $spaceRoot) {
        foreach (_stattic_runtime_space_sweep_hostnames($spaceRoot) as $hostname) {
            $hostnames[$hostname] = true;
        }
    }
    $purge = ['status' => 'ok', 'mode' => 'none'];
    if ($hostnames !== []) {
        $purge = _stattic_runtime_purge_now($privateRoot, [
            'hostnames' => array_keys($hostnames),
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
        _stattic_runtime_purge_space_hosts_now($privateRoot, $spaceId, 'storage_object_deleted');
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
        // Omitted, never null: an object stored before names existed, or
        // uploaded without one, simply has no filename.
        ...($record['filename'] === null ? [] : ['filename' => $record['filename']]),
        'size' => $record['size'],
        'uploaderId' => $record['uploaderId'],
        'sha256' => $record['sha256'],
        // Path-relative and fresh at response time; the caller absolutizes
        // against whichever of the space's hostnames it is presenting.
        'url' => STATTIC_UPLOADS_PUBLIC_URL_PREFIX . $id . '?k=' . $readKey,
    ];
}
