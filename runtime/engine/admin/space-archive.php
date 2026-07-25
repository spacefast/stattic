<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/storage.php';

// `spacefast_export_v1` is the only archive layout: exports produce it and
// imports accept nothing else.
const STATTIC_EXPORT_FORMAT = 'spacefast_export_v1';
const STATTIC_EXPORT_ROOT_DESCRIPTOR = STATTIC_EXPORT_FORMAT . '/spacefast.json';
const STATTIC_EXPORT_SPACE_ACCESS_POLICY_ENTRY = STATTIC_EXPORT_FORMAT . '/space/access-policy.json';
const STATTIC_EXPORT_JOB_CHUNK_SIZE = 500;
const STATTIC_IMPORT_ARCHIVE_MAX_BYTES = 536870912;
const STATTIC_IMPORT_ENTRY_MAX_BYTES = 268435456;
const STATTIC_IMPORT_TOTAL_UNCOMPRESSED_MAX_BYTES = 2147483648;
const STATTIC_IMPORT_MAX_FILES = 100000;
// A generated archive may contain every materialized file plus its root
// descriptor and optional space policy. Every other central-directory record,
// including explicit directories, consumes the same finite intake budget.
const STATTIC_IMPORT_MAX_ARCHIVE_ENTRIES = STATTIC_IMPORT_MAX_FILES + 2;
const STATTIC_IMPORT_PHP_ARTIFACT_MAX_BYTES = 2097152;
const STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES = 2097152;
const STATTIC_IMPORT_MAX_COMPRESSION_RATIO = 200;

// On disk the per-version manifest is metadata.json; spacefast_export_v1 archives name it
// manifest.json (spec: Runtime export artifact shape).
function _stattic_runtime_archive_entry_from_disk(string $relativePath): string
{
    return preg_match('#^[^/]+/metadata\.json$#', $relativePath) === 1
        ? substr($relativePath, 0, -strlen('metadata.json')) . 'manifest.json'
        : $relativePath;
}

function _stattic_runtime_disk_path_from_archive_entry(string $relativePath): string
{
    return preg_match('#^[^/]+/manifest\.json$#', $relativePath) === 1
        ? substr($relativePath, 0, -strlen('manifest.json')) . 'metadata.json'
        : $relativePath;
}

// Locate the root descriptor of an import archive:
// `spacefast_export_v1/spacefast.json`, the only accepted layout.
function _stattic_runtime_import_root_descriptor(ZipArchive $zip): ?array
{
    $entry = STATTIC_EXPORT_ROOT_DESCRIPTOR;
    $index = $zip->locateName($entry);
    if (!is_int($index)) {
        return null;
    }
    $stat = $zip->statIndex($index);
    $entrySize = is_array($stat) ? (int) ($stat['size'] ?? -1) : -1;
    if ($entrySize < 0 || $entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import descriptor exceeds the runtime import limit.', 'details' => ['path' => $entry]]]);
    }
    // Bound decompression even when corrupt ZIP metadata disagrees with the
    // actual stream, then require the bytes to match the declared size.
    $raw = $zip->getFromIndex($index, $entrySize + 1);
    if (!is_string($raw) || strlen($raw) !== $entrySize) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import descriptor could not be read.', 'details' => ['path' => $entry]]]);
    }
    return ['format' => STATTIC_EXPORT_FORMAT, 'entry' => $entry, 'raw' => $raw];
}

// Handle calls are top-level (spec route table): the job id alone identifies the
// job; the owning space comes from the job's status record.
function _stattic_runtime_load_space_archive_job(string $privateRoot, string $kind, string $id): array
{
    $id = _stattic_runtime_id($id, $kind === 'space-imports' ? 'import_id' : 'export_id');
    $jobRoot = $kind === 'space-imports'
        ? _stattic_runtime_space_import_root($privateRoot, $id)
        : _stattic_runtime_space_export_root($privateRoot, $id);
    $status = _stattic_runtime_read_json($jobRoot . '/status.json');
    $code = $kind === 'space-imports' ? 'space_import_not_found' : 'space_export_not_found';
    if (!is_array($status)) {
        _stattic_json_response(404, ['error' => ['code' => $code, 'message' => 'Export/import job not found.']]);
    }
    if (!is_string($status['space_id'] ?? null) || !_stattic_runtime_id_valid($status['space_id'])) {
        $noun = $kind === 'space-imports' ? 'import' : 'export';
        _stattic_json_response(404, ['error' => ['code' => $code, 'message' => 'Space ' . $noun . ' not found.']]);
    }
    $spaceId = _stattic_runtime_id((string) $status['space_id'], 'space_id');
    return [$id, $jobRoot, $status, $spaceId];
}

function _stattic_runtime_space_archive_status_response(string $privateRoot, array $status): array
{
    $response = $status;
    if (($status['type'] ?? null) === 'space_export' && is_string($status['export_id'] ?? null)) {
        $archivePath = _stattic_runtime_space_export_root($privateRoot, $status['export_id']) . '/archive.zip';
        if (is_file($archivePath)) {
            $response['archive_size'] = filesize($archivePath) ?: 0;
        }
    }
    return $response;
}

function _stattic_runtime_export_version_relative_path(string $archivePath): ?string
{
    $prefix = STATTIC_EXPORT_FORMAT . '/versions/';
    if (!str_starts_with($archivePath, $prefix) || str_ends_with($archivePath, '/')) {
        return null;
    }
    $relativePath = substr($archivePath, strlen($prefix));
    if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\') || str_contains('/' . $relativePath . '/', '/../') || str_starts_with($relativePath, '../')) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive contains an invalid path.']]);
    }
    return $relativePath;
}

function _stattic_runtime_export_version_file_list(string $versionsRoot, array $versionIds): array
{
    $files = [];
    foreach ($versionIds as $versionId) {
        $versionRoot = $versionsRoot . '/' . _stattic_runtime_id($versionId, 'version_id');
        _stattic_runtime_assert_private_path($versionRoot);
        if (!is_dir($versionRoot)) {
            _stattic_json_response(404, ['error' => ['code' => 'version_not_found', 'message' => 'Version not found.', 'details' => ['version_id' => $versionId]]]);
        }
        foreach (_stattic_runtime_walk_private_files($versionRoot) as $realPath) {
            $relativePath = _stattic_runtime_relative_to($versionRoot, $realPath);
            if ($relativePath !== '') {
                $files[] = $versionId . '/' . $relativePath;
            }
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function _stattic_runtime_export_space_version_ids(string $privateRoot, string $spaceId, array $body): array
{
    $versionsRoot = _spacefast_space_root($privateRoot, $spaceId) . '/versions';
    if (array_key_exists('version_ids', $body)) {
        if (!is_array($body['version_ids']) || count($body['version_ids']) === 0) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_version_ids', 'message' => 'version_ids must be a non-empty array when provided.']]);
        }
        $versionIds = [];
        foreach ($body['version_ids'] as $versionId) {
            if (!is_string($versionId)) {
                _stattic_json_response(422, ['error' => ['code' => 'invalid_version_ids', 'message' => 'version_ids must contain only strings.']]);
            }
            $versionIds[] = _stattic_runtime_id($versionId, 'version_id');
        }
        return array_values(array_unique($versionIds));
    }

    if (!is_dir($versionsRoot)) {
        return [];
    }
    _stattic_runtime_assert_private_path($versionsRoot);
    $versionIds = [];
    foreach (scandir($versionsRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($versionsRoot . '/' . $entry)) {
            $versionIds[] = _stattic_runtime_id($entry, 'version_id');
        }
    }
    sort($versionIds, SORT_STRING);
    return $versionIds;
}

function _stattic_runtime_space_export_root(string $privateRoot, string $exportId): string
{
    return $privateRoot . '/runtime/space-exports/' . _stattic_runtime_id($exportId, 'export_id');
}

function _stattic_runtime_space_import_root(string $privateRoot, string $importId): string
{
    return $privateRoot . '/runtime/space-imports/' . _stattic_runtime_id($importId, 'import_id');
}

function _stattic_runtime_send_zip_attachment(string $archivePath, string $downloadName): void
{
    _stattic_runtime_assert_private_path($archivePath);
    http_response_code(200);
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string) filesize($archivePath));
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($archivePath);
    exit;
}
