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
    $commerce = ($claims['action'] ?? null) === 'storage_upload_commerce';
    $public = $commerce || ($claims['action'] ?? null) === 'storage_upload_private' ? false : _stattic_uploads_request_public();
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
        'public' => $public,
        ...($commerce ? ['protection' => 'commerce'] : []),
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
    if ($commerce) {
        // Reuse only an already-protected immutable record. Ordinary/public uploads keep their own identities.
        $id = substr(hash('sha256', "spacefast-commerce-v1\0" . $spaceId . "\0" . $normalized['sha256'] . "\0" . $normalized['contentType'] . "\0" . ($normalized['filename'] ?? '')), 0, 32);
        $existing = _stattic_uploads_get($privateRoot, $spaceId, $id);
        if ($existing !== null) {
            if ($existing['public'] || $existing['protection'] !== 'commerce'
                || $existing['sha256'] !== $normalized['sha256'] || $existing['size'] !== $normalized['size']
                || $existing['filename'] !== $normalized['filename'] || $existing['contentType'] !== $normalized['contentType']) {
                unlink($tmpPath);
                _stattic_problem_response(503, 'storage_unavailable', 'Protected storage identity does not match the uploaded object.');
            }
            // Keep original provenance. Committing the verified upload also heals a missing local CAS body.
            $record = $existing;
            $normalized = $existing;
        }
    }
    // The read key composes the URL in the response, and minting it can fail.
    // Resolve it BEFORE the commit so that failure refuses the upload instead
    // of storing an object the caller was told did not happen.
    $readKey = _stattic_storage_read_key($privateRoot);
    // An authorized management upload can be the Space's first write: paid
    // assets arrive before its first Version. The API's per-Space write lock
    // serializes this initialization with other management mutations. Visitor
    // uploads still require an existing, serving Space.
    _stattic_runtime_mkdir(_stattic_space_root($privateRoot, $spaceId));
    _stattic_storage_commit_record($privateRoot, $spaceId, $id, $tmpPath, $record);

    // The same projection the list answers with, so one object shape crosses
    // this boundary whether it was just created or read back later.
    _stattic_json_response(201, _stattic_uploads_owner_object($id, $normalized, $readKey));
}

// Paid assets outlive upload records and deployment metadata. Recovery can issue a
// new seven-day grant later, so these storage retention roots do not age out.
function _stattic_storage_commerce_retention_path(string $privateRoot, string $spaceId, string $objectId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/commerce-assets/' . $objectId . '.json';
}

function _stattic_storage_commerce_retained_record(string $privateRoot, string $spaceId, string $objectId): ?array
{
    $retained = _stattic_runtime_read_json(_stattic_storage_commerce_retention_path($privateRoot, $spaceId, $objectId));
    if (!is_array($retained) || ($retained['schema_version'] ?? null) !== 1 || ($retained['object_id'] ?? null) !== $objectId) return null;
    $record = _stattic_uploads_record($retained['object'] ?? null);
    return $record !== null && !$record['public'] && $record['protection'] === 'commerce' ? $record : null;
}

function _stattic_storage_has_commerce_retention(string $privateRoot, string $spaceId): ?bool
{
    $entries = _stattic_runtime_directory_entries(_stattic_space_root($privateRoot, $spaceId) . '/commerce-assets');
    if ($entries === null) return null;
    foreach ($entries as $entry) {
        if (str_ends_with($entry, '.json')) return true;
    }
    return false;
}

function _stattic_storage_commerce_retention_state(string $privateRoot, string $spaceId, array $claims): never
{
    $retained = _stattic_storage_has_commerce_retention($privateRoot, $spaceId);
    if ($retained === null) {
        _stattic_problem_response(503, 'storage_unavailable', 'Paid asset retention could not be read.');
    }
    _stattic_json_response(200, ['retained' => $retained]);
}

function _stattic_storage_retain_commerce(string $privateRoot, string $spaceId, string $objectId, array $claims): never
{
    $input = _stattic_json_body(1024);
    if (!is_array($input) || count($input) !== 1 || !is_string($input['sha256'] ?? null) || !_stattic_is_sha256_hex($input['sha256'])) {
        _stattic_problem_response(422, 'validation_error', 'An exact protected asset hash is required.');
    }
    $record = _stattic_storage_commerce_retained_record($privateRoot, $spaceId, $objectId)
        ?? _stattic_uploads_get($privateRoot, $spaceId, $objectId);
    if ($record === null || $record['public'] || $record['protection'] !== 'commerce' || $record['sha256'] !== $input['sha256']) {
        _stattic_problem_response(404, 'storage_object_not_found', 'Protected storage object not found.');
    }
    $path = _stattic_storage_commerce_retention_path($privateRoot, $spaceId, $objectId);
    if (file_exists($path)) {
        $existing = _stattic_storage_commerce_retained_record($privateRoot, $spaceId, $objectId);
        if ($existing === null || $existing !== $record) {
            _stattic_problem_response(503, 'storage_unavailable', 'Paid asset retention needs repair.');
        }
    } else {
        _stattic_runtime_write_json_atomic($path, ['schema_version' => 1, 'object_id' => $objectId, 'object' => $record, 'created_at' => gmdate('c')]);
    }
    _stattic_json_response(200, ['id' => $objectId, 'sha256' => $record['sha256'], 'retained' => true]);
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
    require_once __DIR__ . '/../shared/purge.php';
    _stattic_runtime_mkdir($privateRoot . '/runtime');
    $lockPath = $privateRoot . '/runtime/storage-read-key.lock';
    _stattic_lock_with($lockPath, STATTIC_LOCK_WAIT,
        static fn () => throw new RuntimeException('storage_rotation_busy'),
        static function () use ($privateRoot, $lockPath): void {
            $hostnames = _stattic_runtime_all_space_sweep_hostnames($privateRoot);
            // Refuse before rotating when a deployed runtime has lost its purge credentials.
            if (_stattic_runtime_require_edge_purge_endpoint() !== null) {
                _stattic_runtime_purge_enqueue($privateRoot, $hostnames, 'storage_read_key_rotated', [$lockPath]);
            }
            $key = bin2hex(random_bytes(16));
            $rotatedAt = gmdate('c');
            _sf_json_write($privateRoot . '/runtime/storage-read-key.json', [
                'key' => $key,
                'rotated_at' => $rotatedAt,
            ]);
            $purge = _stattic_runtime_purge_now($privateRoot, [
                'hostnames' => $hostnames,
                'reason' => 'storage_read_key_rotated',
            ]);
            _stattic_json_response(200, ['key' => $key, 'rotatedAt' => $rotatedAt, 'purge' => $purge]);
        },
    );
}

function _stattic_storage_object_delete(string $privateRoot, string $spaceId, string $objectId): void
{
    if (!_stattic_uploads_id_valid($objectId)) {
        _stattic_problem_response(404, 'storage_object_not_found', 'Storage object not found.');
    }
    if (!is_dir(_stattic_space_root($privateRoot, $spaceId))) {
        _stattic_problem_response(404, 'storage_unavailable', 'Storage is unavailable for this space.');
    }
    $record = _stattic_uploads_get($privateRoot, $spaceId, $objectId);
    if ($record !== null && $record['protection'] === 'commerce') {
        _stattic_problem_response(409, 'storage_object_retained', 'Purchased assets cannot be deleted.');
    }
    // The route already holds the per-space write lock, so the delete must not
    // take it a second time.
    $deleted = _stattic_uploads_delete_record($privateRoot, $spaceId, $objectId, false, $purgeHostnames);
    if ($deleted && $purgeHostnames !== []) {
        // Same revocation as the visitor lane's delete: the keyed public URL
        // opts into the edge, so the record's removal must drop the shared
        // copy too. The delete already persisted the obligation; this only
        // delivers those hostnames, after the response on FPM.
        $drain = static fn (): bool => _stattic_runtime_purge_drain($privateRoot, microtime(true) + 20, null, $purgeHostnames);
        if (function_exists('fastcgi_finish_request')) {
            _stattic_flush_response_before_deferred(true);
            _stattic_defer($drain);
        } else {
            $drain();
        }
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
        'public' => $record['public'],
        ...($record['protection'] === null ? [] : ['protection' => $record['protection']]),
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
        'url' => $record['public']
            ? STATTIC_UPLOADS_PUBLIC_URL_PREFIX . $id . '?k=' . $readKey
            : '/storage/' . $id,
    ];
}

function _stattic_storage_object_read(string $privateRoot, string $spaceId, string $objectId, array $claims): never
{
    $commerceRead = ($claims['action'] ?? null) === 'storage_read_commerce';
    $record = $commerceRead
        ? _stattic_storage_commerce_retained_record($privateRoot, $spaceId, $objectId) ?? _stattic_uploads_get($privateRoot, $spaceId, $objectId)
        : _stattic_uploads_get($privateRoot, $spaceId, $objectId);
    if ($record === null
        || ($record['protection'] === 'commerce' && !$commerceRead)
        || ($commerceRead && $record['protection'] !== 'commerce')) {
        _stattic_problem_response(404, 'storage_object_not_found', 'Storage object not found.');
    }
    if ($commerceRead) {
        // Signed management only: the control plane verifies immutable receipts before catalog staging.
        header('X-Spacefast-Storage-Protection: commerce');
        header('X-Spacefast-Storage-SHA256: ' . $record['sha256']);
        if ($record['filename'] !== null) {
            header('X-Spacefast-Storage-Filename: ' . rawurlencode($record['filename']));
        }
    }
    if ($record['filename'] !== null) {
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($record['filename']));
    }
    _stattic_uploads_send($privateRoot, $spaceId, $record, $_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'HEAD' : 'GET', false, ($claims['action'] ?? null) === 'storage_read_commerce');
}
