<?php
declare(strict_types=1);

// The blob token gate (contracts §7): GET /__stattic/blob/<jwt>.
//
// EVERY rejection — bad method, malformed or expired token, wrong audience,
// unknown record, sha not in the manifest, blob not in the CAS — answers the
// same bare 404, on purpose: the token is the only thing that distinguishes a
// prober from the control plane, so a rejection must never say which check
// failed or whether a Space, version, record or blob exists.
//
// The ONE named refusal is the path lane's `version_file_not_found`, and only
// after a control-plane signature, audience, expiry and scope all passed: the
// control plane mints path links blind (it no longer holds a file list to check
// them against), so the holder of a valid capability for {space, version, path}
// has already been told that triple exists and must be able to tell "wrong
// path" from "runtime broken". Nothing that failed BEFORE that point is ever
// distinguished.
//
// Replay: the jti is deliberately NOT consumed. A download link is handed to a
// person and a browser retries, resumes and re-requests it; single use would
// break the product feature. The bound is the TTL plus record resolution.

require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/response.php';
// This control path is not the public serving hot lane. Load provider-backed
// config here so the gate can read the locally provisioned JWKS used to verify
// its capability; ordinary page and asset requests still avoid that decrypt.
require_once __DIR__ . '/../shared/bootstrap-config.php';

const STATTIC_BLOB_GATE_PATH_PREFIX = '/__stattic/blob/';

/**
 * The dashboard-origin CORS grant, on EVERY gate answer including the refusals:
 * a `fetch()` that cannot read a 404 or a 503 sees an opaque network error and
 * cannot tell the two apart. Pinned, never reflected — see
 * `_stattic_dashboard_origin()`.
 *
 * @return array<string,string>
 */
function _stattic_blob_gate_cors_headers(): array
{
    $origin = _stattic_dashboard_origin();
    return $origin === '' ? [] : ['Access-Control-Allow-Origin' => $origin];
}

/**
 * wp.cloud's internal X-Accel location does not preserve arbitrary FastCGI
 * response headers. That is fine for a download or a media subresource, but a
 * dashboard `fetch()` must receive the pinned CORS header above or the browser
 * hides an otherwise successful response. Keep only that exact browser read on
 * the PHP streaming fallback; scanners and ordinary downloads retain Nginx's
 * Range/HEAD offload.
 */
function _stattic_blob_gate_dashboard_fetch(): bool
{
    $configured = _stattic_dashboard_origin();
    $requested = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
        ? _stattic_absolute_url_origin($_SERVER['HTTP_ORIGIN'])
        : null;
    return $configured !== '' && is_string($requested) && hash_equals($configured, $requested);
}

function _stattic_blob_gate_not_found(): never
{
    http_response_code(404);
    header('Cache-Control: private, no-store', true);
    header('Content-Type: text/plain; charset=utf-8', true);
    header('Referrer-Policy: no-referrer', true);
    foreach (_stattic_blob_gate_cors_headers() as $name => $value) {
        header($name . ': ' . $value, true);
    }
    echo "Not found.\n";
    exit;
}

function _stattic_blob_gate_token(string $requestPath): ?string
{
    if (!str_starts_with($requestPath, STATTIC_BLOB_GATE_PATH_PREFIX)) {
        return null;
    }
    $token = rawurldecode(substr($requestPath, strlen(STATTIC_BLOB_GATE_PATH_PREFIX)));
    // One segment, JWT shape only: nothing here may become a path.
    return preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token) === 1
        ? $token
        : null;
}

/**
 * sha256 => content type for the lane this token addresses: the version's own
 * objects, or one channel's variant overrides when `$routeName` is given. The
 * whole map is built and cached once per (space, version) in
 * `_stattic_runtime_version_blob_shas()` — a 100k-file scan pulls one blob per
 * request, and re-walking the catalog each time would make the scan quadratic.
 *
 * @return array<string,string>|null null when the version has no readable
 *         catalog, or overrides nothing for the named channel.
 */
function _stattic_blob_gate_version_manifest(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    ?string $routeName = null
): ?array
{
    $lanes = _stattic_runtime_version_blob_shas($privateRoot, $spaceId, $versionId);
    if ($lanes === null) {
        return null;
    }
    $lane = $lanes[$routeName ?? STATTIC_RUNTIME_VERSION_BLOB_BASE_LANE] ?? null;
    return is_array($lane) ? $lane : null;
}

/**
 * The claim's resolver, answering the blob this token may read and the type it
 * carries. The record lane goes through the storage record reader, so an
 * unreadable, half-written or deleted record is indistinguishable from an absent
 * one — all three deny. The path lane goes through the shared version-file
 * resolver, the same one the management list route reads.
 *
 * @return array{sha: string, mime: string}|null
 */
function _stattic_blob_gate_resolve(string $privateRoot, array $claims): ?array
{
    $spaceId = (string) $claims['space_id'];
    $recordId = is_string($claims['record'] ?? null) ? $claims['record'] : null;
    if ($recordId !== null) {
        require_once __DIR__ . '/../runtime/storage.php';
        $sha256 = strtolower((string) $claims['sha256']);
        $record = _stattic_uploads_get($privateRoot, $spaceId, $recordId);
        // The record's sha is authoritative: a token may not point a live
        // record at somebody else's bytes.
        if ($record === null || !hash_equals($record['sha256'], $sha256)) {
            return null;
        }
        return ['sha' => $sha256, 'mime' => $record['contentType']];
    }
    $uploadId = is_string($claims['upload'] ?? null) ? $claims['upload'] : null;
    if ($uploadId !== null) {
        // The version being published has no catalog yet, so its open publish
        // session is what says these bytes belong to this Space.
        return _stattic_runtime_publish_session_blob(
            $privateRoot,
            $spaceId,
            $uploadId,
            strtolower((string) $claims['sha256'])
        );
    }
    $versionId = (string) ($claims['version_id'] ?? '');
    if (!_stattic_id_valid($versionId)) {
        return null;
    }
    if (is_string($claims['path'] ?? null)) {
        $resolved = _stattic_runtime_resolve_version_file(
            $privateRoot,
            $spaceId,
            $versionId,
            $claims['path'],
            (string) ($claims['view'] ?? '')
        );
        // Named, not the uniform 404: the capability was valid, the path was not
        // in this view. See the file header.
        if ($resolved === null) {
            _stattic_runtime_version_file_not_found(_stattic_blob_gate_cors_headers());
        }
        return ['sha' => $resolved['sha'], 'mime' => $resolved['mime']];
    }
    $sha256 = strtolower((string) $claims['sha256']);
    $routeName = is_string($claims['route_name'] ?? null) ? $claims['route_name'] : null;
    $manifest = _stattic_blob_gate_version_manifest(
        $privateRoot,
        $spaceId,
        $versionId,
        $routeName
    );
    return is_array($manifest) && is_string($manifest[$sha256] ?? null)
        ? ['sha' => $sha256, 'mime' => $manifest[$sha256]]
        : null;
}

function _stattic_blob_gate_serve(string $privateRoot, string $requestMethod, string $requestPath): never
{
    if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
        _stattic_blob_gate_not_found();
    }
    $token = _stattic_blob_gate_token($requestPath);
    if ($token === null) {
        _stattic_blob_gate_not_found();
    }
    $claims = _stattic_runtime_blob_gate_claims($privateRoot, $token);
    if ($claims === null) {
        _stattic_blob_gate_not_found();
    }
    $spaceId = (string) $claims['space_id'];
    if (!_stattic_id_valid($spaceId)) {
        _stattic_blob_gate_not_found();
    }
    $resolved = _stattic_blob_gate_resolve($privateRoot, $claims);
    if ($resolved === null) {
        _stattic_blob_gate_not_found();
    }
    $sha256 = $resolved['sha'];
    $contentType = $resolved['mime'];

    $blobPath = _stattic_runtime_blob_path($privateRoot, $spaceId, $sha256);
    if (!is_file($blobPath)) {
        // Promote-on-read through the storage seam, required lazily so a local
        // hit never parses the tier module.
        $tier = __DIR__ . '/../runtime/tier.php';
        if (is_file($tier)) {
            require_once $tier;
        }
        $promoted = function_exists('_stattic_tier_promote_blob')
            ? _stattic_tier_promote_blob($privateRoot, $spaceId, $sha256)
            : null;
        if (!is_string($promoted) || !is_file($promoted)) {
            // Never a 404: authorization succeeded and the object exists, the
            // bytes are momentarily unreachable. A scan that read this as "file
            // gone" would report a version's files as deleted.
            http_response_code(503);
            header('Cache-Control: private, no-store', true);
            header('Retry-After: 5', true);
            header('Content-Type: text/plain; charset=utf-8', true);
            foreach (_stattic_blob_gate_cors_headers() as $name => $value) {
                header($name . ': ' . $value, true);
            }
            echo "Blob bytes are unavailable.\n";
            exit;
        }
        $blobPath = $promoted;
    }

    $size = filesize($blobPath);
    $headers = [
        'Content-Type' => $contentType,
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        // On every response, not a type list: this is a download and scan gate,
        // never a rendering surface, so nothing served through it may run as a
        // page on the Space's own origin. Unlike X-Content-Type-Options, this
        // header survives X-Accel-Redirect, which is why it carries the safety.
        'Content-Disposition' => 'attachment',
        'ETag' => '"' . $sha256 . '"',
        // Shared by both delivery lanes below. wp.cloud drops this header at
        // its internal X-Accel location, so an exact dashboard-origin fetch
        // deliberately stays on the PHP body lane instead.
        ..._stattic_blob_gate_cors_headers(),
    ];

    if ($requestMethod === 'HEAD') {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if ($size !== false) {
            header('Content-Length: ' . $size, true);
        }
        http_response_code(200);
        exit;
    }

    require_once __DIR__ . '/../shared/server-file.php';
    if (!_stattic_blob_gate_dashboard_fetch()) {
        _stattic_send_server_file($blobPath, $privateRoot, $headers);
    }

    $stream = fopen($blobPath, 'rb');
    if ($stream === false) {
        _stattic_blob_gate_not_found();
    }
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value, true);
    }
    if ($size !== false) {
        header('Content-Length: ' . $size, true);
    }
    http_response_code(200);
    // Chunked, never fpassthru: these are whole version blobs, the largest
    // bodies the runtime sends, and fpassthru buffers all of it into the worker.
    // An unstattable blob streams to EOF rather than not at all.
    _stattic_stream_file($stream, $size === false ? PHP_INT_MAX : $size);
    fclose($stream);
    exit;
}
