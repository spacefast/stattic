<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/auth.php';

// Read-only file-fetch surface for the control-plane scan pipeline (C2). The
// external scanner pulls authoritative post-finalize metadata and raw
// committed bytes using a control-plane-signed JWT (aud
// stattic-runtime-file-fetch) that pins the space, version, and exact read
// operation. The bytes are streamed verbatim — NEVER through PHP — so a
// scanned file can never execute on this surface.
//
// Hard size cap: scanning is for static deploy assets; a single committed file
// over this bound is refused rather than streamed.
const STATTIC_RUNTIME_FILE_FETCH_MAX_BYTES = 256 * 1024 * 1024;
// Keep both the runtime PHP request and the globally single-concurrency scan
// worker below a material memory ceiling. Pathological max-count/max-path
// versions remain published but scan coverage stays unknown and retryable.
const STATTIC_RUNTIME_SCAN_MANIFEST_MAX_BYTES = 64 * 1024 * 1024;

// Routing lives in the unified admin dispatcher (admin/api.php): its
// file_fetch-lane rows declare the route shapes and scope resolvers and
// lazily require this module — the JWT profile and streaming primitives —
// when one matches.

// Verifies the control-plane-signed file-fetch JWT and asserts every pinned
// scope claim matches the request. Unlike the management surface this aud is
// READ-ONLY and MULTI-USE within its (short) TTL: the jti single-use replay
// cache is intentionally bypassed because an external scanner legitimately
// refetches the same bytes (retries, range follow-ups). The replay control for
// this aud is the short TTL plus the space/version/path|hash pinning, not jti —
// and replay protection for every OTHER aud (management) stays untouched.
function _stattic_runtime_require_file_fetch_jwt(string $privateRoot, array $required, bool $bearerOnly = false): array
{
    $token = $bearerOnly
        ? (_stattic_runtime_bearer_token_from_request() ?? '')
        : _stattic_runtime_file_fetch_token_from_request();
    $claims = _stattic_runtime_verify_jwt($privateRoot, $token, 'stattic-runtime-file-fetch');
    foreach ($required as $key => $value) {
        if (!isset($claims[$key]) || !is_string($claims[$key]) || !hash_equals($value, $claims[$key])) {
            _stattic_json_response(403, ['error' => ['code' => 'runtime_file_fetch_scope_forbidden', 'message' => 'File-fetch token scope does not match this request (claim: ' . $key . ').']]);
        }
    }
    return $claims;
}

function _stattic_runtime_assert_file_fetch_claims_absent(array $claims, array $keys): void
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $claims)) {
            _stattic_json_response(403, ['error' => ['code' => 'runtime_file_fetch_scope_forbidden', 'message' => 'File-fetch token carries claims outside this request profile (claim: ' . $key . ').']]);
        }
    }
}

function _stattic_runtime_file_fetch_token_from_request(): string
{
    // Authorization: Bearer header takes precedence over ?access= query param.
    $bearer = _stattic_runtime_bearer_token_from_request();
    if ($bearer !== null) {
        return $bearer;
    }
    $queryToken = $_GET['access'] ?? '';
    return is_string($queryToken) ? trim($queryToken) : '';
}

/**
 * Appends one manifest entry while keeping a projected serialized-byte total, and
 * reports false the moment that total would exceed the scanner response limit —
 * so the caller can answer 413 without ever materializing an oversized manifest.
 * The +1 accounts for the comma the encoder puts between array elements.
 */
function _stattic_runtime_scan_manifest_append(array &$files, int &$projected, array $entry): bool
{
    $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }
    $projected += strlen($encoded) + ($files === [] ? 0 : 1);
    if ($projected > STATTIC_RUNTIME_SCAN_MANIFEST_MAX_BYTES) {
        return false;
    }
    $files[] = $entry;
    return true;
}

// The scan manifest projects only scanner inputs. It never returns serving
// headers, routing rules, access state, or other version metadata.
function _stattic_runtime_scan_manifest_response(string $privateRoot, string $spaceId, string $versionId, ?string $variantRoute, string $method): void
{
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $versionId);
    $files = [];
    // The budget is enforced while entries are appended, not after the whole
    // manifest is encoded: a version with enough files exhausts PHP's memory
    // limit building the array long before it can answer 413. The running total
    // starts at the envelope's own serialized length.
    $projected = strlen((string) json_encode([
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'files' => [],
    ], JSON_UNESCAPED_SLASHES));
    $overBudget = false;
    foreach (_stattic_runtime_served_base_files($versionRoot) as $path => $meta) {
        if (!_stattic_runtime_scan_manifest_append($files, $projected, _stattic_runtime_scan_manifest_entry($path, $meta, null))) {
            $overBudget = true;
            break;
        }
    }
    if (!$overBudget && $variantRoute !== null) {
        $variantFiles = _stattic_runtime_template_variant_files($versionRoot, $variantRoute);
        ksort($variantFiles, SORT_STRING);
        foreach ($variantFiles as $path => $meta) {
            if (!_stattic_runtime_scan_manifest_append($files, $projected, _stattic_runtime_scan_manifest_entry($path, $meta, $variantRoute))) {
                $overBudget = true;
                break;
            }
        }
    }
    if ($overBudget) {
        _stattic_json_response(413, ['error' => ['code' => 'runtime_scan_manifest_too_large', 'message' => 'Scan manifest exceeds the background scanner response limit.']]);
    }
    $payload = [
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'files' => $files,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'Scan manifest could not be encoded.']]);
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8', false);
    header('Content-Length: ' . strlen($encoded), false);
    header('Cache-Control: no-store', false);
    if ($method !== 'HEAD') {
        echo $encoded;
    }
    exit;
}

// Load the same file-shard authority used by runtime serving. This deliberately
// does not trust metadata.json: imported archives retain that JSON as inert
// provenance, while validated serving + shard artifacts decide which bytes can
// actually be returned. Duplicate or mis-sharded entries fail closed so a
// crafted archive cannot hide served bytes behind a clean provenance subset.
function _stattic_runtime_served_base_files(string $versionRoot): array
{
    $servingPath = $versionRoot . '/serving.php';
    if (!is_file($servingPath)) {
        _stattic_json_response(404, ['error' => ['code' => 'runtime_scan_manifest_not_found', 'message' => 'Finalized version metadata was not found.']]);
    }
    $serving = @include $servingPath;
    if (!is_array($serving) || !_stattic_runtime_artifact_metadata_valid_lazy($serving) || empty($serving['file_shards'])) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'Serving metadata is incomplete.']]);
    }

    $publicFiles = [];
    foreach ($serving['public_files'] ?? [] as $path) {
        if (!is_string($path) || $path === '' || isset($publicFiles[$path])) {
            _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'Serving metadata contains an invalid public file set.']]);
        }
        $publicFiles[$path] = true;
    }

    $files = [];
    foreach (glob($versionRoot . '/file-shards/*.php') ?: [] as $shardPath) {
        $shard = basename((string) $shardPath, '.php');
        if (preg_match('/^[a-f0-9]{2}$/', $shard) !== 1) {
            _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'File metadata shard name is invalid.']]);
        }
        $artifact = @include $shardPath;
        if (!is_array($artifact) || !_stattic_runtime_artifact_metadata_valid_lazy($artifact) || !is_array($artifact['files'] ?? null)) {
            _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'File metadata shard is incomplete.']]);
        }
        foreach ($artifact['files'] as $path => $meta) {
            if (!is_string($path) || !is_array($meta) || substr(hash('sha256', $path), 0, 2) !== $shard) {
                _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'File metadata shard entries are inconsistent.']]);
            }
            if (!isset($publicFiles[$path])) {
                continue;
            }
            if (array_key_exists($path, $files)) {
                _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'File metadata shard entries are duplicated.']]);
            }
            $canonical = _stattic_runtime_file_path($path);
            if ($canonical !== $path) {
                _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'File metadata shard path is not canonical.']]);
            }
            $files[$path] = $meta;
        }
    }
    return $files;
}

function _stattic_runtime_scan_manifest_entry(mixed $path, mixed $meta, ?string $variantRoute): array
{
    if (
        !is_string($path)
        || !is_array($meta)
        || ($meta['disk_path'] ?? null) !== $path
        || !isset($meta['size'])
        || !is_int($meta['size'])
        || $meta['size'] < 0
        || !is_string($meta['sha256'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/', strtolower($meta['sha256'])) !== 1
    ) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'Finalized file metadata is incomplete.']]);
    }
    $entry = [
        'path' => _stattic_runtime_file_path($path),
        'size' => $meta['size'],
        'sha256' => strtolower($meta['sha256']),
    ];
    if (is_string($meta['mime'] ?? null) && $meta['mime'] !== '') {
        $entry['content_type'] = $meta['mime'];
    }
    if ($variantRoute !== null) {
        $entry['variant_route'] = $variantRoute;
    }
    return $entry;
}

function _stattic_runtime_template_variant_files(string $versionRoot, string $variantRoute): array
{
    $artifactPath = $versionRoot . '/template-variants.php';
    if (!is_file($artifactPath)) {
        return [];
    }
    $artifact = @include $artifactPath;
    if (!is_array($artifact) || !_stattic_runtime_artifact_metadata_valid_lazy($artifact) || !is_array($artifact['routes'] ?? null)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'Template variant metadata is incomplete.']]);
    }
    $files = $artifact['routes'][$variantRoute] ?? [];
    if (!is_array($files)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_scan_manifest_invalid', 'message' => 'Template variant metadata is incomplete.']]);
    }
    return $files;
}

// Resolves the committed file whose bytes hash to $sha256 by reading the
// finalized serving shards or the explicitly selected route variant. Avoids
// rehashing every file on disk for a hash-addressed fetch.
function _stattic_runtime_version_location_for_hash(string $privateRoot, string $spaceId, string $versionId, string $sha256, ?string $variantRoute): ?array
{
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $versionId);
    if ($variantRoute !== null) {
        $files = _stattic_runtime_template_variant_files($versionRoot, $variantRoute);
    } else {
        $files = _stattic_runtime_served_base_files($versionRoot);
    }
    foreach ($files as $path => $meta) {
        if (is_string($path) && is_array($meta) && is_string($meta['sha256'] ?? null) && hash_equals($sha256, strtolower($meta['sha256']))) {
            return [
                'path' => _stattic_runtime_file_path($path),
                'variant_route' => $variantRoute,
            ];
        }
    }
    return null;
}

function _stattic_runtime_stream_version_file(string $privateRoot, string $spaceId, string $versionId, string $filePath, string $method, ?string $variantRoute = null): void
{
    $versionRoot = _spacefast_version_root($privateRoot, $spaceId, $versionId);
    $versionFilesRoot = $variantRoute === null
        ? $versionRoot . '/files'
        : $versionRoot . '/files-variants/' . $variantRoute;
    $target = $versionFilesRoot . '/' . $filePath;
    // Confine the resolved path to the version's files root before any read.
    _stattic_runtime_assert_private_path($target);
    $realRoot = realpath($versionFilesRoot);
    $realTarget = realpath($target);
    if (!is_string($realRoot) || !is_string($realTarget) || !_stattic_runtime_path_is_inside($realTarget, $realRoot) || !is_file($realTarget)) {
        _stattic_json_response(404, ['error' => ['code' => 'runtime_file_fetch_not_found', 'message' => 'File not found in this version.']]);
    }
    $size = filesize($realTarget);
    if ($size === false || $size > STATTIC_RUNTIME_FILE_FETCH_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'runtime_file_fetch_too_large', 'message' => 'File exceeds the runtime file-fetch size limit.']]);
    }

    http_response_code(200);
    // Raw bytes only: a fixed application/octet-stream type guarantees the
    // scanner receives the file verbatim and no served Content-Type can coax
    // execution or interpretation anywhere downstream.
    header('Content-Type: application/octet-stream', false);
    header('Content-Length: ' . $size, false);
    header('Cache-Control: no-store', false);
    header('X-Content-Type-Options: nosniff', false);
    if ($method === 'HEAD') {
        exit;
    }
    $handle = fopen($realTarget, 'rb');
    if ($handle === false) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_file_fetch_open_failed', 'message' => 'File could not be opened for reading.']]);
    }
    fpassthru($handle);
    fclose($handle);
    exit;
}
