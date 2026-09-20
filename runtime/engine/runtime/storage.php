<?php
declare(strict_types=1);

// Per-space storage: a record store at spaces/<s>/uploads/objects/<id>.json,
// bodies in the shared CAS. The record is the authority. Deleting it stops
// access even when an edge copy names the same bytes. A public URL works only
// with the runtime-wide read key (`?k=`), whose rotation invalidates every
// handed-out URL at once.
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/pointers.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/storage-policy.generated.php';
require_once __DIR__ . '/../shared/cache-policy.php';
require_once __DIR__ . '/../shared/errors.php';

// A promote failure is a bucket round trip away from succeeding, never a
// permanent verdict.
const STATTIC_UPLOADS_PROMOTE_RETRY_AFTER_SECONDS = 5;

const STATTIC_UPLOADS_ID_PATTERN = '/^[a-f0-9]{32}$/';

// A stored type a browser could execute as a page rides `Content-Disposition:
// attachment` with the record's exact declared type, never a sniffable one.
// SVG and XML are here even though ingest admits them: browsers treat them as
// script-bearing documents, which the ingest-aimed executable-content denylist
// does not cover.
const STATTIC_UPLOADS_ACTIVE_CONTENT_TYPES = [
    'application/xhtml+xml',
    'application/xml',
    'image/svg+xml',
    'text/html',
    'text/xml',
];

function _stattic_uploads_root(string $privateRoot, string $spaceId): string
{
    return _stattic_space_root($privateRoot, _stattic_runtime_id($spaceId, 'space_id')) . '/uploads';
}

function _stattic_uploads_store(string $privateRoot, string $spaceId): array
{
    return _stattic_record_store(_stattic_uploads_root($privateRoot, $spaceId) . '/objects');
}

function _stattic_uploads_id_valid(string $id): bool
{
    return preg_match(STATTIC_UPLOADS_ID_PATTERN, $id) === 1;
}

/**
 * The uploader's own name for an object, or null.
 *
 * Presentation only: the id is the identity, the name is what a person reads in
 * a list. It is never a path — only the last segment survives — because a
 * record field that could carry `../` would eventually be joined to something.
 * Absent, unreadable, or hostile all answer null, which is why every legacy
 * record written before this field existed still validates.
 */
function _stattic_uploads_filename(mixed $filename): ?string
{
    if (!is_string($filename)) {
        return null;
    }
    $lastSeparator = max(strrpos($filename, '/'), strrpos($filename, '\\'));
    $basename = trim($lastSeparator === false ? $filename : substr($filename, $lastSeparator + 1));
    if (
        $basename === '' || $basename === '.' || $basename === '..'
        || strlen($basename) > 255
        || preg_match('/[\x00-\x1f\x7f]/', $basename) === 1
    ) {
        return null;
    }
    return $basename;
}

// Every reader goes through this: an unreadable or half-written record reads as
// absent, so it never serves bytes.
function _stattic_uploads_record(mixed $record): ?array
{
    if (!is_array($record)) {
        return null;
    }
    $contentType = $record['contentType'] ?? null;
    $createdAt = $record['createdAt'] ?? null;
    $size = $record['size'] ?? null;
    $sha256 = $record['sha256'] ?? null;
    $uploaderId = $record['uploaderId'] ?? null;
    if (
        !is_string($contentType) || $contentType === '' || strlen($contentType) > 255
        || !is_string($createdAt) || strtotime($createdAt) === false
        || !is_int($size) || $size < 0
        || !is_string($sha256) || !_stattic_is_sha256_hex(strtolower($sha256))
        || !is_string($uploaderId) || $uploaderId === '' || strlen($uploaderId) > 255
    ) {
        return null;
    }
    return [
        'contentType' => $contentType,
        'createdAt' => $createdAt,
        'email' => is_string($record['email'] ?? null) ? $record['email'] : null,
        // Optional, and null on every record written before the field existed.
        'filename' => _stattic_uploads_filename($record['filename'] ?? null),
        'sha256' => strtolower($sha256),
        'size' => $size,
        'uploaderId' => $uploaderId,
    ];
}

/**
 * The filename this request declared, from `Content-Disposition`.
 *
 * The upload lanes take raw bytes, not multipart, so the header is the only
 * place a name can ride. `filename*` (RFC 5987) wins when present because that
 * is the form a browser uses for anything non-ASCII; a percent sequence that
 * does not decode is discarded rather than guessed at.
 */
function _stattic_uploads_request_filename(): ?string
{
    $header = $_SERVER['HTTP_CONTENT_DISPOSITION'] ?? null;
    if (!is_string($header) || $header === '' || strlen($header) > 1024) {
        return null;
    }
    if (preg_match("/filename\*\s*=\s*UTF-8''([^;]+)/i", $header, $extended) === 1) {
        $decoded = rawurldecode(trim($extended[1]));
        if (mb_check_encoding($decoded, 'UTF-8')) {
            return _stattic_uploads_filename($decoded);
        }
    }
    if (preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $header, $quoted) === 1) {
        return _stattic_uploads_filename(stripslashes($quoted[1]));
    }
    if (preg_match('/filename\s*=\s*([^;\s]+)/i', $header, $token) === 1) {
        return _stattic_uploads_filename($token[1]);
    }
    return null;
}

// The one revocable secret behind every public object URL. Lazily minted under
// the site write lock, and rotation takes that same lock, so a mint cannot race
// a rotation. Rotation swaps the pointer, purges the edge, and makes every
// previously handed-out URL answer 404.
function _stattic_storage_read_key(string $privateRoot): string
{
    $key = _stattic_lazy_minted_secret($privateRoot, 'storage-read-key', 16);
    if ($key === null) {
        _stattic_problem_refused(503, 'storage_unavailable', 'Storage is temporarily unavailable.');
    }
    return $key;
}

// The only spelling of an object's URL: origin + public prefix + id + read key.
function _stattic_uploads_public_url(string $privateRoot, string $origin, string $id): string
{
    return $origin . STATTIC_UPLOADS_PUBLIC_URL_PREFIX . $id
        . '?k=' . _stattic_storage_read_key($privateRoot);
}

function _stattic_uploads_get(string $privateRoot, string $spaceId, string $id): ?array
{
    if (!_stattic_uploads_id_valid($id)) {
        return null;
    }
    return _stattic_uploads_record(
        _stattic_record_store_get(_stattic_uploads_store($privateRoot, $spaceId), $id)
    );
}

function _stattic_uploads_active_content(string $contentType): bool
{
    $declared = strtolower(trim(explode(';', $contentType, 2)[0]));
    return in_array($declared, STATTIC_UPLOADS_ACTIVE_CONTENT_TYPES, true)
        || _stattic_storage_declared_type_blocked($contentType);
}

// The same validator nginx derives on the accel lane, so a PHP-served and an
// nginx-served copy answer identically.
function _stattic_uploads_etag(array $record): string
{
    return '"' . dechex(_stattic_content_mtime($record['sha256'])) . '-' . dechex($record['size']) . '"';
}

// Uploads stay on the PHP lane: nosniff, the CSP, ETag and Last-Modified do not
// survive X-Accel-Redirect, and those are what keep a stored file from being
// sniffed into an active document.
function _stattic_uploads_headers(array $record, bool $publicCache): array
{
    $headers = [
        'Content-Type' => $record['contentType'],
        'ETag' => _stattic_uploads_etag($record),
        'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', _stattic_content_mtime($record['sha256'])),
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "sandbox; default-src 'none'",
        // The object id is a 128-bit random name for immutable bytes, so the
        // URL caches forever; a delete purges the edge instead of relying on
        // revalidation. A protected space, or a share-token fetch whose URL is
        // itself the secret, keeps the private cache class.
        'Cache-Control' => $publicCache
            ? 'public, max-age=31536000, immutable'
            : 'private, max-age=31536000, immutable',
    ];
    if (_stattic_uploads_active_content($record['contentType'])) {
        $headers['Content-Disposition'] = 'attachment';
    }
    return $headers;
}

// The visitor lane. A missing record, a wrong key and an absent key answer
// identically, so the lane leaks nothing about which ids exist.
function _stattic_uploads_serve(string $privateRoot, string $spaceId, string $requestPath): void
{
    $requestMethod = _stattic_runtime_request_method();
    if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
        _stattic_render_platform_page(
            'method-not-allowed',
            405,
            ['Allow' => 'GET, HEAD', 'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE],
            "This upload can only be read.\n"
        );
        exit;
    }
    // This lane re-matches the prefix the dispatcher matched: a helper that
    // trusts its caller's routing is one refactor away from the wrong record.
    if (!str_starts_with($requestPath, STATTIC_UPLOADS_PUBLIC_URL_PREFIX)) {
        _stattic_render_not_found();
        exit;
    }
    $id = rawurldecode(substr($requestPath, strlen(STATTIC_UPLOADS_PUBLIC_URL_PREFIX)));
    $record = _stattic_uploads_get($privateRoot, $spaceId, $id);
    // The record is the gate: deleted record, deleted object.
    if ($record === null) {
        _stattic_render_not_found();
        exit;
    }
    $presentedKey = $_GET['k'] ?? null;
    if (!is_string($presentedKey) || !hash_equals(_stattic_storage_read_key($privateRoot), $presentedKey)) {
        _stattic_render_not_found();
        exit;
    }
    _stattic_uploads_send(
        $privateRoot,
        $spaceId,
        $record,
        $requestMethod,
        !_stattic_access_private_cache_flag()
    );
}

// The one byte-sending path for both lanes (visitor /__stattic/u/ and the
// /storage surface). It answers no conditional and serves no range: the platform
// delivers neither to this origin.
function _stattic_uploads_send(
    string $privateRoot,
    string $spaceId,
    array $record,
    string $requestMethod,
    bool $publicCache
): never
{
    // §16: the edge stores only on the explicit opt-in from the Cache-Control
    // composed above. The keyed public URL may be held, since deletes and key
    // rotations purge it; the authenticated keyless URL never is. The platform
    // policy applies inside _stattic_send_response_headers.
    $headers = _stattic_uploads_headers($record, $publicCache);
    $size = $record['size'];
    $blobPath = _stattic_runtime_blob_path($privateRoot, $spaceId, $record['sha256']);
    if (!is_file($blobPath)) {
        // Promote-on-read: the bytes land in the CAS first, then serve locally.
        // There is no S3-to-visitor stream.
        require_once __DIR__ . '/tier.php';
        $promoted = _stattic_tier_promote_blob($privateRoot, $spaceId, $record['sha256']);
        if ($promoted === null) {
            _stattic_render_tier_fetch_unavailable(STATTIC_UPLOADS_PROMOTE_RETRY_AFTER_SECONDS);
            exit;
        }
        $blobPath = $promoted;
    }

    // HEAD advertises what the matching GET would send, without opening the file.
    if ($requestMethod === 'HEAD') {
        _stattic_send_response_headers($headers);
        header('Content-Length: ' . $size);
        http_response_code(200);
        exit;
    }
    $stream = fopen($blobPath, 'rb');
    if ($stream === false) {
        _stattic_render_platform_page(
            'runtime-unavailable',
            503,
            ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE],
            "Stored object bytes are unavailable.\n"
        );
        exit;
    }
    _stattic_send_response_headers($headers);
    header('Content-Length: ' . $size);
    http_response_code(200);
    // Chunked, never fpassthru: a 128 MB object would otherwise buffer whole
    // into the worker before its first byte left.
    _stattic_stream_file($stream, $size);
    fclose($stream);
    exit;
}

// The shared `/storage` HTTP surface (POST /storage,
// GET|HEAD|DELETE /storage/<id>): one writer into the store above, and the
// authenticated read lane that needs no read key.

function _stattic_storage_handle(
    string $privateRoot,
    array $serving,
    string $requestHost,
    string $requestPath,
    string $requestMethod
): void {
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    if ($spaceId === '') {
        _stattic_problem_refused(503, 'storage_unavailable', 'Storage is unavailable for this space.');
    }
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $auth = _stattic_storage_auth_context($serving, $requestHost);
    $developmentGuest = _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1';
    $authenticated = ($auth['isAuthenticated'] ?? false) === true;
    // An anonymous commenter is admitted only where Comments is. The host
    // session carries the server-owned pseudonym, so the session is the grant
    // and storage needs no token machinery of its own.
    $anonymousId = is_string($auth['anonymousId'] ?? null) ? $auth['anonymousId'] : null;
    $anonymousCommenter = !$authenticated
        && $anonymousId !== null
        && _stattic_storage_comments_enabled($serving);
    $uploaderId = match (true) {
        $authenticated && is_string($auth['userId'] ?? null) => $auth['userId'],
        $anonymousCommenter => 'anon:' . $anonymousId,
        default => 'guest:local',
    };
    $admitted = $authenticated || $anonymousCommenter || $developmentGuest;

    if ($requestPath === '/storage') {
        if ($requestMethod !== 'POST') {
            _stattic_method_not_allowed('POST');
        }
        if (!$admitted) {
            _stattic_problem_refused(401, 'storage_auth_required', 'Sign in before uploading objects.');
        }
        _stattic_uploads_upload(
            $privateRoot,
            $spaceId,
            $requestHost,
            $uploaderId,
            $anonymousCommenter,
            $auth
        );
    }

    $id = rawurldecode(substr($requestPath, strlen('/storage/')));
    if (!_stattic_uploads_id_valid($id)) {
        _stattic_problem_refused(404, 'storage_object_not_found', 'Storage object not found.');
    }
    if (!$admitted) {
        _stattic_problem_refused(401, 'storage_auth_required', 'Sign in before reading objects.');
    }
    if ($requestMethod === 'DELETE') {
        $record = _stattic_uploads_get($privateRoot, $spaceId, $id);
        if ($record === null) {
            _stattic_uploads_deleted_response();
        }
        if ($record['uploaderId'] !== $uploaderId) {
            _stattic_problem_refused(403, 'storage_delete_forbidden', 'Only the uploader can delete this object.');
        }
        _stattic_uploads_delete($privateRoot, $spaceId, $id);
    }
    $record = _stattic_uploads_get($privateRoot, $spaceId, $id);
    if ($record === null) {
        _stattic_problem_refused(404, 'storage_object_not_found', 'Storage object not found.');
    }
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD, DELETE');
    }
    _stattic_uploads_send(
        $privateRoot,
        $spaceId,
        $record,
        $requestMethod,
        false
    );
}

// Whether Comments is on for THIS surface (live vs preview). The
// anonymous-commenter upload lane borrows it as its admission predicate.
function _stattic_storage_comments_enabled(array $serving): bool
{
    require_once __DIR__ . '/spacefast-sdk.php';
    return _stattic_comments_enabled_for_surface($serving);
}

function _stattic_uploads_upload(
    string $privateRoot,
    string $spaceId,
    string $requestHost,
    string $uploaderId,
    bool $anonymousUploader,
    array $auth
): never
{
    $staged = _stattic_storage_stage_upload($privateRoot);
    if (($staged['ok'] ?? false) !== true) {
        if (($staged['reason'] ?? null) === 'too_large') {
            _stattic_problem_refused(413, 'storage_file_too_large', 'Storage uploads are limited to 5 MiB.');
        }
        if (($staged['reason'] ?? null) === 'empty') {
            _stattic_problem_refused(400, 'storage_empty_file', 'Storage uploads cannot be empty.');
        }
        _stattic_problem_refused(503, 'storage_unavailable', 'Storage could not persist this object.');
    }
    $size = is_int($staged['size'] ?? null) ? $staged['size'] : 0;
    $tmpPath = is_string($staged['tmp_path'] ?? null) ? $staged['tmp_path'] : '';
    $sha256 = is_string($staged['sha256'] ?? null) ? $staged['sha256'] : '';
    $prefix = is_string($staged['prefix'] ?? null) ? $staged['prefix'] : '';
    $contentType = is_string($_SERVER['CONTENT_TYPE'] ?? null)
        ? trim(substr($_SERVER['CONTENT_TYPE'], 0, 255))
        : 'application/octet-stream';
    if ($contentType === '') {
        $contentType = 'application/octet-stream';
    }
    if (_stattic_storage_content_blocked($contentType, $prefix)) {
        unlink($tmpPath);
        _stattic_problem_refused(415, 'storage_content_blocked', 'Executable and active web content cannot be uploaded.');
    }
    $filename = _stattic_uploads_request_filename();

    $committed = _stattic_space_write_lock_with(
        $privateRoot,
        $spaceId,
        STATTIC_LOCK_WAIT,
        static function () use ($tmpPath): never {
            unlink($tmpPath);
            _stattic_problem_refused(503, 'storage_unavailable', 'Storage is temporarily unavailable.');
        },
        static function () use (
            $privateRoot,
            $spaceId,
            $size,
            $tmpPath,
            $sha256,
            $contentType,
            $filename,
            $uploaderId,
            $anonymousUploader,
            $auth
        ): array {
            // Re-check under the lock: delete_space may have removed the tree
            // after serving config loaded, and an in-flight upload must not
            // recreate a deleted space.
            if (!is_dir(_stattic_space_root($privateRoot, $spaceId))) {
                return ['status' => 'space_missing'];
            }
            if ($anonymousUploader && !_stattic_uploads_anon_budget_admit($privateRoot, $spaceId, $size)) {
                return ['status' => 'anon_quota'];
            }
            $id = bin2hex(random_bytes(16));
            _stattic_storage_commit_record($privateRoot, $spaceId, $id, $tmpPath, [
                'contentType' => $contentType,
                'createdAt' => gmdate('c'),
                'email' => is_string($auth['email'] ?? null) ? $auth['email'] : null,
                'filename' => $filename,
                'sha256' => $sha256,
                'size' => $size,
                'uploaderId' => $uploaderId,
            ]);
            return ['status' => 'committed', 'id' => $id];
        },
    );
    // Every failure answers after the lock is released, never under it.
    if ($committed['status'] === 'anon_quota') {
        unlink($tmpPath);
        _stattic_problem_response(
            429,
            'storage_quota_exceeded',
            'The daily anonymous upload budget for this space is used up.',
            [],
            ['Cache-Control' => 'no-store', 'Retry-After' => '3600']
        );
    }
    if ($committed['status'] !== 'committed') {
        unlink($tmpPath);
        _stattic_problem_refused(503, 'storage_unavailable', 'Storage is unavailable for this space.');
    }
    $id = $committed['id'];

    _stattic_json_response(201, [
        'id' => $id,
        'contentType' => $contentType,
        // Omitted, never null: the field is absent on an object that was
        // uploaded without a name.
        ...($filename === null ? [] : ['filename' => $filename]),
        'size' => $size,
        // Composed from the current read key at response time, stable until it
        // rotates, whatever route the upload arrived on.
        'url' => _stattic_uploads_public_url(
            $privateRoot,
            'https://' . $requestHost,
            $id
        ),
    ]);
}

// Anonymous uploads share one per-space rolling daily byte budget. Callers hold
// the space write lock, so read-bump-write here is atomic with the commit.
function _stattic_uploads_anon_budget_admit(string $privateRoot, string $spaceId, int $size): bool
{
    $path = _stattic_uploads_root($privateRoot, $spaceId) . '/anon-usage.json';
    $day = gmdate('Y-m-d');
    $raw = file_get_contents($path);
    $usage = is_string($raw) ? json_decode($raw, true) : null;
    $bytes = is_array($usage) && ($usage['day'] ?? null) === $day && is_int($usage['bytes'] ?? null)
        ? $usage['bytes']
        : 0;
    if ($bytes + $size > SPACEFAST_STORAGE_ANON_DAILY_BYTES) {
        return false;
    }
    _sf_json_write($path, ['day' => $day, 'bytes' => $bytes + $size]);
    return true;
}

/**
 * The one streamed intake path for visitor uploads and space transfers.
 * Byte limits, hashing and prefix capture live here; callers translate the
 * reason set into their own problem response.
 */
function _stattic_storage_stage_upload(string $privateRoot): array
{
    $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (
        is_string($declaredLength)
        && preg_match('/^[0-9]+$/', $declaredLength) === 1
        && (int) $declaredLength > SPACEFAST_STORAGE_OBJECT_MAX_FILE_BYTES
    ) {
        return ['ok' => false, 'reason' => 'too_large'];
    }
    $staged = _stattic_runtime_blob_stage_stream(
        $privateRoot,
        _stattic_request_body_stream(),
        SPACEFAST_STORAGE_OBJECT_MAX_FILE_BYTES,
        SPACEFAST_STORAGE_CONTENT_SNIFF_BYTES
    );
    if (($staged['ok'] ?? false) !== true) {
        return $staged;
    }
    if (($staged['size'] ?? 0) === 0) {
        unlink(is_string($staged['tmp_path'] ?? null) ? $staged['tmp_path'] : '');
        return ['ok' => false, 'reason' => 'empty'];
    }
    return $staged;
}

// Callers hold the space write lock. The record lands after the CAS commit, so
// a crash can leave only an unreferenced blob (GC-safe), never a record whose
// bytes were not installed.
function _stattic_storage_commit_record(
    string $privateRoot,
    string $spaceId,
    string $id,
    string $tmpPath,
    array $record
): void {
    $sha256 = is_string($record['sha256'] ?? null) ? $record['sha256'] : '';
    _stattic_runtime_blob_commit_verified($privateRoot, $spaceId, $tmpPath, $sha256);
    _stattic_record_store_put(
        _stattic_uploads_store($privateRoot, $spaceId),
        $id,
        array_filter($record, static fn($value) => $value !== null)
    );
}

function _stattic_uploads_delete(string $privateRoot, string $spaceId, string $id): never
{
    // The keyed public URL opts into the edge, so removing the record must also
    // revoke the shared copy: the whole-host purge stops the edge serving it to
    // NEW viewers. Browsers that already fetched it keep their year. A repeat
    // delete finds no record and spends no purge.
    if (_stattic_uploads_delete_record($privateRoot, $spaceId, $id, true)) {
        require_once __DIR__ . '/../shared/purge.php';
        _stattic_runtime_purge_space_hosts_now($privateRoot, $spaceId, 'storage_object_deleted');
    }
    _stattic_uploads_deleted_response();
}

// Returns whether a record was there to delete. The CAS body is NOT unlinked:
// the blob may back another record or a version, and the GC's live set, which
// reads these records, is what releases it.
function _stattic_uploads_delete_record(
    string $privateRoot,
    string $spaceId,
    string $id,
    bool $lock
): bool {
    $delete = static function () use ($privateRoot, $spaceId, $id): bool {
        $store = _stattic_uploads_store($privateRoot, $spaceId);
        $existing = _stattic_uploads_record(_stattic_record_store_get($store, $id));
        _stattic_record_store_delete($store, $id);
        return $existing !== null;
    };
    if (!$lock) {
        return $delete();
    }
    return (bool) _stattic_space_write_lock_with(
        $privateRoot,
        $spaceId,
        STATTIC_LOCK_WAIT,
        static function (): never {
            _stattic_problem_refused(503, 'storage_unavailable', 'Storage is temporarily unavailable.');
        },
        $delete,
    );
}

function _stattic_storage_auth_context(array $serving, string $requestHost): array
{
    $functionAuth = $GLOBALS['SPACEFAST_STORAGE_FUNCTION_AUTH'] ?? null;
    if (is_array($functionAuth)) {
        return $functionAuth;
    }
    require_once __DIR__ . '/access-rules.php';
    _stattic_storage_apply_access_token();
    $verified = _stattic_current_session_identity($serving, $requestHost);
    $principal = _stattic_access_identity_principal($verified);
    if (!_stattic_access_principal_is_identified($principal)) {
        // Not identified, but possibly still a session: the comments pseudonym
        // rides the same record, and the upload lane admits on it, never on
        // anything the page claims.
        $comments = _stattic_access_identity_comments($verified);
        return [
            'isAuthenticated' => false,
            'userId' => null,
            'anonymousId' => is_array($comments) ? $comments['anonymousId'] : null,
        ];
    }
    return [
        'isAuthenticated' => true,
        'userId' => $principal,
        'email' => is_string($verified['claims']['email'] ?? null)
            ? $verified['claims']['email']
            : null,
    ];
}

// The browser client carries the same signed session in `sf_token` that the
// access layer reads from its cookie. Install it into the cookie slot before
// grant evaluation so storage has no second permission model: the ordinary
// path/target/constraint decision owns admission.
function _stattic_storage_apply_access_token(): void
{
    require_once __DIR__ . '/access-rules.php';
    $cookieName = _stattic_session_cookie_name();
    if (is_string($_COOKIE[$cookieName] ?? null) && $_COOKIE[$cookieName] !== '') {
        return;
    }
    $token = $_GET['sf_token'] ?? null;
    if (
        is_string($token)
        && strlen($token) <= 4096
        && preg_match('/[\x00-\x20\x7f]/', $token) !== 1
    ) {
        $_COOKIE[$cookieName] = $token;
    }
}

function _stattic_storage_function_auth(
    string $privateRoot,
    array $serving,
    string $requestMethod
): ?array {
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    if ($spaceId === '') {
        return null;
    }
    require_once __DIR__ . '/functions-relay.php';
    $claims = _stattic_functions_relay_claims(
        $privateRoot,
        $spaceId,
        _stattic_functions_relay_bearer()
    );
    if ($claims === null) {
        return null;
    }
    $required = in_array($requestMethod, ['GET', 'HEAD'], true)
        ? 'storage.read'
        : 'storage.write';
    if (!in_array($required, _stattic_functions_relay_grant(
        $claims,
        ['storage.read', 'storage.write']
    ), true)) {
        return null;
    }
    return [
        'isAuthenticated' => true,
        // Stable across versions: storage is not rolled back with the version
        // that created an object.
        'userId' => 'function:' . $spaceId,
        'email' => null,
    ];
}

function _stattic_storage_function_request_authorized(
    string $privateRoot,
    array $serving,
    string $requestMethod
): bool {
    $auth = _stattic_storage_function_auth($privateRoot, $serving, $requestMethod);
    if ($auth === null) {
        return false;
    }
    $GLOBALS['SPACEFAST_STORAGE_FUNCTION_AUTH'] = $auth;
    return true;
}


function _stattic_uploads_deleted_response(): never
{
    _stattic_response_send(204, '', '', ['Cache-Control' => 'no-store']);
}
