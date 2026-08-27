<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/upload-policy.php';

const STATTIC_RUNTIME_STATIC_ZIP_MAX_COMPRESSED_BYTES = 134217728; // 128 MiB
const STATTIC_RUNTIME_STATIC_ZIP_MAX_EXPANDED_BYTES = 2147483648; // 2 GiB
const STATTIC_RUNTIME_STATIC_ZIP_MAX_COMPRESSION_RATIO = 100;
const STATTIC_RUNTIME_STATIC_ZIP_PIN_TTL_SECONDS = 600;
const STATTIC_RUNTIME_STATIC_ZIP_SNIFF_BYTES = 512;

function _stattic_runtime_static_zip_problem(string $code, string $message, array $details = []): never
{
    _stattic_problem_response(
        str_contains($code, 'too_large') || str_contains($code, 'exceeded') ? 413 : 422,
        $code,
        $message,
        $details === [] ? [] : ['details' => $details],
    );
}

function _stattic_runtime_static_zip_path(string $input): ?string
{
    if (preg_match('//u', $input) !== 1 || preg_match('/%(?![A-Fa-f0-9]{2})/', $input) === 1) {
        return null;
    }
    $decoded = rawurldecode($input);
    if (preg_match('//u', $decoded) !== 1) {
        return null;
    }
    $decoded = _stattic_nfc_path($decoded);
    if (str_contains($decoded, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1) {
        return null;
    }
    $segments = [];
    foreach (explode('/', ltrim($decoded, '/')) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null;
        }
        $segments[] = $segment;
    }
    $path = implode('/', $segments);
    return $path === '' ? null : $path;
}

function _stattic_runtime_static_zip_visible_path(string $path): bool
{
    $first = explode('/', $path, 2)[0] ?? '';
    return $first !== '__MACOSX' && $first !== '.DS_Store';
}

/** @param list<array{index: int, path: string, size: int}> $entries */
function _stattic_runtime_static_zip_server_bundle_path(array $entries): ?string
{
    $paths = array_column($entries, 'path');
    if (in_array('.open-next/worker.js', $paths, true)) {
        return '.open-next/worker.js';
    }
    if (!in_array('worker.js', $paths, true)) {
        return null;
    }
    foreach ($paths as $path) {
        if (str_starts_with($path, 'server-functions/')) {
            return 'worker.js';
        }
    }
    return null;
}

function _stattic_runtime_static_zip_entry_kind(ZipArchive $archive, int $index, string $name): string
{
    $opsys = 0;
    $attributes = 0;
    if ($archive->getExternalAttributesIndex($index, $opsys, $attributes, ZipArchive::FL_UNCHANGED)) {
        $unixType = ($attributes >> 16) & 0xf000;
        if ($opsys === ZipArchive::OPSYS_UNIX && $unixType !== 0) {
            if ($unixType === 0x4000) {
                return 'directory';
            }
            return $unixType === 0x8000 ? 'file' : 'unsupported';
        }
        if (($attributes & 0x10) !== 0) {
            return 'directory';
        }
    }
    return str_ends_with($name, '/') ? 'directory' : 'file';
}

/** @return array{max_files: int, max_file_bytes: int, max_total_bytes: int} */
function _stattic_runtime_static_zip_caps(array $claims): array
{
    $caps = is_array($claims['static_zip_caps'] ?? null) ? $claims['static_zip_caps'] : null;
    $limits = [
        'max_files' => STATTIC_RUNTIME_MANIFEST_MAX_FILES,
        'max_file_bytes' => STATTIC_RUNTIME_MANIFEST_MAX_FILE_BYTES,
        'max_total_bytes' => STATTIC_RUNTIME_STATIC_ZIP_MAX_EXPANDED_BYTES,
    ];
    if ($caps === null) {
        _stattic_problem_response(403, 'static_zip_caps_required', 'Static zip ingest requires signed publish caps.');
    }
    $resolved = [];
    foreach ($limits as $field => $ceiling) {
        $value = $caps[$field] ?? null;
        if (!is_int($value) || $value < 1 || $value > $ceiling) {
            _stattic_problem_response(403, 'static_zip_caps_invalid', 'Static zip ingest publish caps are invalid.');
        }
        $resolved[$field] = $value;
    }
    return $resolved;
}

/** @return array{entries: list<array{index: int, path: string, size: int}>, expanded_bytes: int} */
function _stattic_runtime_static_zip_preflight(ZipArchive $archive, array $caps): array
{
    if ($archive->numFiles > STATTIC_RUNTIME_MANIFEST_MAX_FILES) {
        _stattic_runtime_static_zip_problem(
            'publish_archive_file_count_exceeded',
            'Inline publish archives support up to ' . STATTIC_RUNTIME_MANIFEST_MAX_FILES . ' entries.',
            ['file_count' => $archive->numFiles, 'limit' => STATTIC_RUNTIME_MANIFEST_MAX_FILES],
        );
    }

    $allPaths = [];
    $visible = [];
    $expandedBytes = 0;
    $compressedBytes = 0;
    $fileCount = 0;
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
        if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
            _stattic_runtime_static_zip_problem('invalid_publish_archive', 'Publish zip archive contains unreadable entry metadata.');
        }
        $name = $stat['name'];
        $kind = _stattic_runtime_static_zip_entry_kind($archive, $index, $name);
        if ($kind === 'directory') {
            if ((int) ($stat['size'] ?? 0) !== 0) {
                _stattic_runtime_static_zip_problem('invalid_publish_archive', 'Publish zip archive contains a directory with file bytes.');
            }
            continue;
        }
        if ($kind !== 'file') {
            _stattic_runtime_static_zip_problem(
                'publish_archive_entry_unsupported',
                'Publish zip archive contains a link or another unsupported entry type.',
                ['path' => $name],
            );
        }
        $fileCount++;
        if ($fileCount > $caps['max_files']) {
            _stattic_runtime_static_zip_problem(
                'publish_archive_file_count_exceeded',
                'Publish archive contains more files than this plan permits.',
                ['file_count' => $fileCount, 'limit' => $caps['max_files']],
            );
        }
        if ((int) ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
            _stattic_runtime_static_zip_problem(
                'publish_archive_entry_unsupported',
                'Publish zip archive contains an encrypted entry.',
                ['path' => $name],
            );
        }
        $path = _stattic_runtime_static_zip_path($name);
        if ($path === null) {
            _stattic_runtime_static_zip_problem(
                'invalid_publish_path',
                'Publish archive contains an unsafe file path.',
                ['path' => $name],
            );
        }
        _stattic_runtime_assert_static_upload_path($path);
        if (strlen($path) > STATTIC_RUNTIME_MANIFEST_MAX_PATH_BYTES) {
            _stattic_runtime_static_zip_problem(
                'manifest_path_too_long',
                'Publish archive path exceeds the runtime manifest limit.',
                ['path' => $path, 'bytes' => strlen($path), 'limit' => STATTIC_RUNTIME_MANIFEST_MAX_PATH_BYTES],
            );
        }
        if (isset($allPaths[$path])) {
            _stattic_runtime_static_zip_problem(
                'manifest_duplicate_path',
                'Publish archive contains the same canonical path twice.',
                ['path' => $path],
            );
        }
        $allPaths[$path] = true;
        $size = (int) ($stat['size'] ?? -1);
        $compressedSize = (int) ($stat['comp_size'] ?? -1);
        if ($size < 0 || $compressedSize < 0) {
            _stattic_runtime_static_zip_problem('invalid_publish_archive', 'Publish zip archive contains invalid entry sizes.');
        }
        if ($size > $caps['max_file_bytes']) {
            _stattic_runtime_static_zip_problem(
                'manifest_file_too_large',
                'Publish archive contains a file larger than this plan permits.',
                ['path' => $path, 'size' => $size, 'limit' => $caps['max_file_bytes']],
            );
        }
        $expandedBytes += $size;
        $compressedBytes += $compressedSize;
        if ($expandedBytes > $caps['max_total_bytes']) {
            _stattic_runtime_static_zip_problem(
                'publish_archive_expanded_size_exceeded',
                'Publish archive expands beyond this plan limit.',
                ['total_bytes' => $expandedBytes, 'limit' => $caps['max_total_bytes']],
            );
        }
        if (_stattic_runtime_static_zip_visible_path($path)) {
            $visible[] = ['index' => $index, 'path' => $path, 'size' => $size];
        }
    }

    if ($expandedBytes > 0 && ($compressedBytes <= 0 || $expandedBytes / $compressedBytes > STATTIC_RUNTIME_STATIC_ZIP_MAX_COMPRESSION_RATIO)) {
        _stattic_runtime_static_zip_problem(
            'publish_archive_compression_ratio_exceeded',
            'Publish archive compression ratio is too high.',
            [
                'compressed_bytes' => $compressedBytes,
                'total_bytes' => $expandedBytes,
                'limit' => STATTIC_RUNTIME_STATIC_ZIP_MAX_COMPRESSION_RATIO,
            ],
        );
    }

    $firstSegments = [];
    $allWrapped = $visible !== [];
    foreach ($visible as $entry) {
        $parts = explode('/', $entry['path'], 2);
        $firstSegments[$parts[0]] = true;
        $allWrapped = $allWrapped && count($parts) === 2;
    }
    $wrapper = count($firstSegments) === 1 && $allWrapped ? (array_key_first($firstSegments) ?: null) : null;
    $normalized = [];
    foreach ($visible as $entry) {
        $path = $wrapper === null ? $entry['path'] : substr($entry['path'], strlen($wrapper) + 1);
        if (_stattic_runtime_static_zip_visible_path($path)) {
            $normalized[] = ['index' => $entry['index'], 'path' => $path, 'size' => $entry['size']];
        }
    }
    if (!in_array('index.html', array_column($normalized, 'path'), true)) {
        _stattic_runtime_static_zip_problem(
            'invalid_publish_archive',
            'Static publish zip archive must contain index.html at its normalized root.',
        );
    }
    $serverBundlePath = _stattic_runtime_static_zip_server_bundle_path($normalized);
    if ($serverBundlePath !== null) {
        _stattic_runtime_static_zip_problem(
            'build_output_contains_server_bundle',
            'The static archive contains a compiled server bundle.',
            ['path' => $serverBundlePath],
        );
    }
    return ['entries' => $normalized, 'expanded_bytes' => $expandedBytes];
}

function _stattic_runtime_static_zip_sniff(string $prefix): string
{
    if (str_starts_with($prefix, "\x89PNG\x0d\x0a\x1a\x0a")) return 'png';
    if (str_starts_with($prefix, "\xff\xd8\xff")) return 'jpeg';
    if (str_starts_with($prefix, 'GIF87a') || str_starts_with($prefix, 'GIF89a')) return 'gif';
    if (str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WEBP') return 'webp';
    if (substr($prefix, 4, 8) === 'ftypavif' || substr($prefix, 4, 8) === 'ftypavis') return 'avif';
    if (str_starts_with($prefix, "\x00\x00\x01\x00")) return 'ico';
    if (str_starts_with($prefix, '%PDF-')) return 'pdf';
    if (str_starts_with($prefix, "\x1f\x8b\x08")) return 'gzip';
    if (str_starts_with($prefix, 'wOFF')) return 'woff';
    if (str_starts_with($prefix, 'wOF2')) return 'woff2';
    if (str_starts_with($prefix, "\x00\x01\x00\x00") || str_starts_with($prefix, 'true')) return 'ttf';
    if (str_starts_with($prefix, 'OTTO')) return 'otf';
    if ($prefix === '') return 'text';
    for ($offset = 0; $offset < strlen($prefix); $offset++) {
        $byte = ord($prefix[$offset]);
        if ($byte === 0 || $byte < 0x08 || ($byte > 0x0d && $byte < 0x20)) {
            return 'binary';
        }
    }
    return 'text';
}

/** @param list<string> $shas */
function _stattic_runtime_static_zip_write_pin(string $privateRoot, string $spaceId, array $claims, array $shas): string
{
    $operationId = is_string($claims['operation_id'] ?? null) ? $claims['operation_id'] : '';
    $pinId = 'archive-' . substr(hash('sha256', $operationId), 0, 32);
    $expiresAt = gmdate('c', time() + STATTIC_RUNTIME_STATIC_ZIP_PIN_TTL_SECONDS);
    $shas = array_values(array_unique($shas));
    sort($shas, SORT_STRING);
    _stattic_record_store_put(
        _stattic_runtime_publish_pins_store($privateRoot, $spaceId),
        $pinId,
        ['shas' => $shas, 'expires_at' => $expiresAt],
    );
    return $expiresAt;
}

function _stattic_runtime_static_zip_ingest(
    string $privateRoot,
    string $spaceId,
    array $claims,
): void {
    if (!class_exists('ZipArchive')) {
        _stattic_problem_response(503, 'runtime_zip_extension_unavailable', 'Runtime zip support is unavailable.');
    }
    set_time_limit(150);
    $caps = _stattic_runtime_static_zip_caps($claims);
    _stattic_runtime_upload_admit($privateRoot, $spaceId);
    $stagedArchive = _stattic_runtime_blob_stage_stream(
        $privateRoot,
        _stattic_request_body_stream(),
        STATTIC_RUNTIME_STATIC_ZIP_MAX_COMPRESSED_BYTES,
    );
    if (($stagedArchive['ok'] ?? false) !== true) {
        if (($stagedArchive['reason'] ?? null) === 'too_large') {
            _stattic_runtime_static_zip_problem(
                'publish_archive_too_large',
                'Inline publish archives must be 128 MiB or smaller.',
                ['limit' => STATTIC_RUNTIME_STATIC_ZIP_MAX_COMPRESSED_BYTES],
            );
        }
        _stattic_problem_response(500, 'publish_archive_write_failed', 'Publish archive bytes could not be staged.');
    }
    $archivePath = (string) $stagedArchive['tmp_path'];
    register_shutdown_function(static function () use ($archivePath): void {
        if (is_file($archivePath)) unlink($archivePath);
    });
    if ((int) ($stagedArchive['size'] ?? 0) === 0) {
        _stattic_runtime_static_zip_problem('invalid_publish_archive', 'Publish zip archive cannot be empty.');
    }

    $archive = new ZipArchive();
    if ($archive->open($archivePath, ZipArchive::RDONLY) !== true) {
        _stattic_runtime_static_zip_problem('invalid_publish_archive', 'Publish archive must be a valid zip archive.');
    }
    $preflight = _stattic_runtime_static_zip_preflight($archive, $caps);
    $manifest = [];
    $shas = [];
    foreach ($preflight['entries'] as $entry) {
        $stream = $archive->getStreamIndex($entry['index'], ZipArchive::FL_UNCHANGED);
        if (!is_resource($stream)) {
            _stattic_runtime_static_zip_problem(
                'invalid_publish_archive',
                'Publish zip archive entry could not be read.',
                ['path' => $entry['path']],
            );
        }
        $staged = _stattic_runtime_blob_stage_stream(
            $privateRoot,
            $stream,
            $entry['size'],
            STATTIC_RUNTIME_STATIC_ZIP_SNIFF_BYTES,
        );
        if (($staged['ok'] ?? false) !== true || (int) ($staged['size'] ?? -1) !== $entry['size']) {
            if (is_string($staged['tmp_path'] ?? null) && is_file($staged['tmp_path'])) unlink($staged['tmp_path']);
            _stattic_runtime_static_zip_problem(
                'invalid_publish_archive',
                'Publish zip archive entry size does not match its directory metadata.',
                ['path' => $entry['path']],
            );
        }
        $sha = strtolower((string) $staged['sha256']);
        _stattic_runtime_blob_commit_verified($privateRoot, $spaceId, (string) $staged['tmp_path'], $sha);
        $manifest[] = [
            'path' => $entry['path'],
            'size' => $entry['size'],
            'sha256' => $sha,
            'sniff' => _stattic_runtime_static_zip_sniff((string) ($staged['prefix'] ?? '')),
        ];
        $shas[] = $sha;
    }
    $archive->close();
    unlink($archivePath);
    $pinExpiresAt = _stattic_runtime_static_zip_write_pin($privateRoot, $spaceId, $claims, $shas);
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'files' => $manifest,
        'pin_expires_at' => $pinExpiresAt,
    ]);
}
