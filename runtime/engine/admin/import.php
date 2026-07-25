<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/native-process.php';

// Import status responses never echo zero_rebind: the caller-supplied target
// Zero bindings can carry secrets (DATABASE_URL and friends) that belong on
// disk in the rebound zero/config.json, not in every status poll.
function _stattic_runtime_space_import_status_response(string $privateRoot, array $status): array
{
    $response = _stattic_runtime_space_archive_status_response($privateRoot, $status);
    unset($response['zero_rebind']);
    return $response;
}

function _stattic_runtime_start_space_import(string $privateRoot, string $spaceId, array $claims): void
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    if (!class_exists('ZipArchive')) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_zip_unavailable', 'message' => 'Runtime ZIP support is unavailable.']]);
    }
    [$versionIdMap, $zeroMode, $installAccessPolicy, $zeroRebind] = _stattic_runtime_import_start_body(false, 'start', true);
    $importId = _stattic_runtime_new_id('sim');
    $jobRoot = _stattic_runtime_space_import_root($privateRoot, $importId);
    if (is_dir($jobRoot)) {
        _stattic_json_response(409, ['error' => ['code' => 'space_import_exists', 'message' => 'Space import already exists.']]);
    }
    _stattic_runtime_mkdir($jobRoot);
    $status = [
        'type' => 'space_import',
        'import_id' => $importId,
        'space_id' => $spaceId,
        'status' => 'waiting_for_archive',
        'cursor' => 0,
        'processed_files' => 0,
        'total_files' => 0,
        'version_ids' => [],
        'version_id_map' => $versionIdMap,
        // Parsed by _stattic_runtime_zero_mode. Set here (export/url-sourced
        // imports, where the control plane runs the gate before ever calling
        // the runtime) or via the map call (upload-sourced imports); absent
        // either, install stays activating.
        'zero_mode' => $zeroMode,
        // Importing versions and replacing the target's authorization policy
        // are separate privileges. The caller must make that decision before
        // archive bytes are attached; absent legacy state remains fail-closed.
        'install_access_policy' => $installAccessPolicy,
        // Optional target Zero bindings, keyed by TARGET version id: an
        // imported zero/config.json that carried source-space bindings is
        // never installed verbatim; the rebind either clears it or replaces
        // it with these caller-supplied target bindings.
        'zero_rebind' => $zeroRebind,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    _stattic_runtime_write_json_atomic($jobRoot . '/status.json', $status);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_import_started',
        'space_id' => $spaceId,
        'import_id' => $importId,
    ]);
    _stattic_json_response(201, _stattic_runtime_space_import_status_response($privateRoot, $status));
}

// Request-body validation shared by import start and import map: the body may
// carry version_id_map (and, on start/map, zero_mode, zero_rebind, and an
// explicit access-policy decision), and
// when present version_id_map must be an object. _stattic_json_body() reads
// php://input, which is not safe to read twice in one request, so start/map
// parse their whole body through this ONE call and get every field back.
function _stattic_runtime_import_start_body(bool $required, string $context, bool $allowPolicyDecision = false): array
{
    $body = _stattic_json_body();
    $allowedKeys = ['version_id_map', 'zero_mode', 'zero_rebind'];
    if ($allowPolicyDecision) {
        $allowedKeys[] = 'install_access_policy';
    }
    foreach (array_keys($body) as $key) {
        if (!in_array($key, $allowedKeys, true)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_import_request', 'message' => 'Import ' . $context . ' accepts only ' . implode(' and ', $allowedKeys) . '.']]);
        }
    }
    $versionIdMap = [];
    if (array_key_exists('version_id_map', $body)) {
        if (!is_array($body['version_id_map'])) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_import_request', 'message' => 'version_id_map must be an object.']]);
        }
        $versionIdMap = _stattic_runtime_normalize_version_id_map($body['version_id_map']);
    } elseif ($required) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_import_request', 'message' => 'version_id_map must be an object.']]);
    }
    $zeroMode = _stattic_runtime_zero_mode($body['zero_mode'] ?? null);
    if ($allowPolicyDecision && array_key_exists('install_access_policy', $body) && !is_bool($body['install_access_policy'])) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_import_request', 'message' => 'install_access_policy must be a boolean.']]);
    }
    $installAccessPolicy = ($body['install_access_policy'] ?? false) === true;
    $zeroRebind = [];
    if (array_key_exists('zero_rebind', $body)) {
        if (!is_array($body['zero_rebind'])) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_import_request', 'message' => 'zero_rebind must be an object keyed by target version id.']]);
        }
        foreach ($body['zero_rebind'] as $targetVersionId => $targetZero) {
            if (!is_string($targetVersionId) || !is_array($targetZero)) {
                _stattic_json_response(422, ['error' => ['code' => 'invalid_import_request', 'message' => 'zero_rebind must contain target Zero objects keyed by target version id.']]);
            }
            $zeroRebind[_stattic_runtime_id($targetVersionId, 'version_id')] = $targetZero;
        }
    }
    return [$versionIdMap, $zeroMode, $installAccessPolicy, $zeroRebind];
}

function _stattic_runtime_upload_space_import_archive(string $privateRoot, string $importId, array $claims): void
{
    $importId = _stattic_runtime_id($importId, 'import_id');
    $lockRoot = $privateRoot . '/runtime/space-import-locks';
    _stattic_runtime_mkdir($lockRoot);
    _stattic_runtime_acquire_write_lock($lockRoot . '/' . $importId . '.lock', static function () use ($privateRoot, $importId, $claims): void {
        _stattic_runtime_upload_space_import_archive_locked($privateRoot, $importId, $claims);
    });
}

function _stattic_runtime_upload_space_import_archive_locked(string $privateRoot, string $importId, array $claims): void
{
    [$importId, $jobRoot, $status, $spaceId] = _stattic_runtime_load_space_archive_job($privateRoot, 'space-imports', $importId);
    if (($status['status'] ?? null) !== 'waiting_for_archive') {
        _stattic_json_response(409, ['error' => ['code' => 'space_import_archive_locked', 'message' => 'Space import archive is already attached.']]);
    }
    $target = $jobRoot . '/archive.zip';
    _stattic_runtime_assert_private_path($target);
    _stattic_runtime_reject_import_archive_too_large_from_headers();
    $input = fopen('php://input', 'rb');
    $output = fopen($target . '.tmp', 'wb');
    if ($input === false || $output === false) {
        _stattic_json_response(500, ['error' => ['code' => 'space_import_upload_failed', 'message' => 'Space import archive could not be written.']]);
    }
    $written = stream_copy_to_stream($input, $output, STATTIC_IMPORT_ARCHIVE_MAX_BYTES + 1);
    fclose($input);
    fclose($output);
    if ($written === false || $written > STATTIC_IMPORT_ARCHIVE_MAX_BYTES || filesize($target . '.tmp') > STATTIC_IMPORT_ARCHIVE_MAX_BYTES) {
        @unlink($target . '.tmp');
        _stattic_json_response(413, ['error' => ['code' => 'space_import_archive_too_large', 'message' => 'Space import archive exceeds the runtime import limit.']]);
    }
    if (!@rename($target . '.tmp', $target)) {
        @unlink($target . '.tmp');
        _stattic_json_response(500, ['error' => ['code' => 'space_import_upload_failed', 'message' => 'Space import archive could not be committed.']]);
    }
    $status = _stattic_runtime_prepare_space_import_archive($privateRoot, $status);
    _stattic_runtime_write_json_atomic($jobRoot . '/status.json', $status);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_import_archive_uploaded',
        'space_id' => $spaceId,
        'import_id' => $importId,
        'file_count' => (int) ($status['total_files'] ?? 0),
    ]);
    _stattic_json_response(200, _stattic_runtime_space_import_status_response($privateRoot, $status));
}

function _stattic_runtime_get_space_import(string $privateRoot, string $importId): void
{
    [, , $status] = _stattic_runtime_load_space_archive_job($privateRoot, 'space-imports', $importId);
    _stattic_json_response(200, _stattic_runtime_space_import_status_response($privateRoot, $status));
}

// Returns the uploaded import archive. Upload-sourced control-plane imports
// PUT bytes straight here from the client; the control-plane worker pulls
// them back for validation before supplying the version-id map and stepping.
function _stattic_runtime_download_space_import_archive(string $privateRoot, string $importId): void
{
    [$importId, $jobRoot, $status] = _stattic_runtime_load_space_archive_job($privateRoot, 'space-imports', $importId);
    if (($status['status'] ?? null) === 'waiting_for_archive') {
        _stattic_json_response(409, ['error' => ['code' => 'space_import_archive_missing', 'message' => 'Space import archive has not been uploaded.']]);
    }
    $archivePath = $jobRoot . '/archive.zip';
    if (!is_file($archivePath)) {
        _stattic_json_response(404, ['error' => ['code' => 'space_import_archive_missing', 'message' => 'Space import archive is missing.']]);
    }
    _stattic_runtime_send_zip_attachment($archivePath, $importId . '.zip');
}

// Deferred version-id mapping: when the archive is uploaded by a client the
// control plane has not read it yet, so the freshly minted target ids arrive
// here after upload and strictly before the first install step.
function _stattic_runtime_map_space_import(string $privateRoot, string $importId, array $claims): void
{
    [$importId, $jobRoot, $status, $spaceId] = _stattic_runtime_load_space_archive_job($privateRoot, 'space-imports', $importId);
    $existingMap = is_array($status['version_id_map'] ?? null) ? $status['version_id_map'] : [];
    if (($status['status'] ?? null) !== 'pending' || (int) ($status['cursor'] ?? 0) !== 0 || (int) ($status['processed_files'] ?? 0) !== 0 || count($existingMap) > 0) {
        _stattic_json_response(409, ['error' => ['code' => 'space_import_map_locked', 'message' => 'The version-id map can only be set once, after archive upload and before install steps begin.']]);
    }
    [$versionIdMap, $zeroMode, , $zeroRebind] = _stattic_runtime_import_start_body(true, 'map');
    // With no prior map, prepare recorded the source version ids verbatim;
    // remap them to the supplied target ids.
    $versionIds = [];
    foreach (is_array($status['version_ids'] ?? null) ? $status['version_ids'] : [] as $sourceVersionId) {
        $sourceVersionId = _stattic_runtime_id((string) $sourceVersionId, 'version_id');
        $versionIds[] = is_string($versionIdMap[$sourceVersionId] ?? null) ? $versionIdMap[$sourceVersionId] : $sourceVersionId;
    }
    $status['version_id_map'] = $versionIdMap;
    $status['version_ids'] = array_values(array_unique($versionIds));
    // Upload-sourced imports (bytes PUT by the client before the control
    // plane is involved) get their first CP-controlled request here; carry
    // the same fail-safe zero_mode transport the start call offers
    // export/url-sourced imports.
    $status['zero_mode'] = $zeroMode;
    $status['zero_rebind'] = $zeroRebind;
    $status['updated_at'] = gmdate('c');
    _stattic_runtime_write_json_atomic($jobRoot . '/status.json', $status);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_import_mapped',
        'space_id' => $spaceId,
        'import_id' => $importId,
        'version_ids' => $status['version_ids'],
    ]);
    _stattic_json_response(200, _stattic_runtime_space_import_status_response($privateRoot, $status));
}

function _stattic_runtime_step_space_import(string $privateRoot, string $importId, array $claims): void
{
    [$importId, $jobRoot, $status, $spaceId] = _stattic_runtime_load_space_archive_job($privateRoot, 'space-imports', $importId);
    if (($status['status'] ?? null) === 'complete') {
        _stattic_json_response(200, _stattic_runtime_space_import_status_response($privateRoot, $status));
    }
    if (($status['status'] ?? null) === 'waiting_for_archive') {
        _stattic_json_response(409, ['error' => ['code' => 'space_import_archive_missing', 'message' => 'Space import archive has not been uploaded.']]);
    }

    $archivePath = $jobRoot . '/archive.zip';
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        _stattic_json_response(500, ['error' => ['code' => 'space_import_archive_failed', 'message' => 'Space import archive could not be opened.']]);
    }
    $cursor = max(0, (int) ($status['cursor'] ?? 0));
    $processed = max(0, (int) ($status['processed_files'] ?? 0));
    $processedAtStart = $processed;
    $stagingRoot = $jobRoot . '/staging';
    _stattic_runtime_mkdir($stagingRoot . '/versions');
    $limit = $zip->numFiles;
    $nextCursor = $cursor;
    $versionIds = is_array($status['version_ids'] ?? null) ? $status['version_ids'] : [];
    $versionIdMap = is_array($status['version_id_map'] ?? null) ? $status['version_id_map'] : [];
    for ($index = $cursor; $index < $limit && ($processed - $processedAtStart) < STATTIC_EXPORT_JOB_CHUNK_SIZE; $index += 1) {
        $nextCursor = $index + 1;
        $name = $zip->getNameIndex($index);
        if (!is_string($name)) {
            continue;
        }
        $relativePath = _stattic_runtime_export_version_relative_path($name);
        if ($relativePath === null) {
            continue;
        }
        $entrySize = _stattic_runtime_import_entry_size($zip, $index);
        $artifactKind = _stattic_runtime_validate_import_entry($relativePath, $entrySize);
        if ($artifactKind !== null) {
            _stattic_runtime_validate_import_entry_php($zip, $index, $relativePath, $artifactKind);
        }
        $sourceVersionId = _stattic_runtime_import_entry_version_id($relativePath);
        if (!array_key_exists($sourceVersionId, $versionIdMap) && !in_array($sourceVersionId, $versionIds, true)) {
            $zip->close();
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive contains a version not listed in manifest.', 'details' => ['version_id' => $sourceVersionId]]]);
        }
        $versionId = _stattic_runtime_mapped_version_id($sourceVersionId, $versionIdMap);
        if (!in_array($versionId, $versionIds, true)) {
            $versionIds[] = $versionId;
        }
        $source = $zip->getStreamIndex($index);
        if (!is_resource($source)) {
            $zip->close();
            _stattic_json_response(500, ['error' => ['code' => 'space_import_entry_failed', 'message' => 'Space import entry could not be read.', 'details' => ['path' => $relativePath]]]);
        }
        $diskRelativePath = _stattic_runtime_disk_path_from_archive_entry($relativePath);
        $slash = strpos($diskRelativePath, '/');
        $targetRelativePath = $versionId . substr($diskRelativePath, $slash);
        $target = $stagingRoot . '/versions/' . $targetRelativePath;
        _stattic_runtime_mkdir(dirname($target));
        _stattic_runtime_assert_private_path($target);
        $output = fopen($target . '.tmp', 'wb');
        if ($output === false) {
            fclose($source);
            $zip->close();
            _stattic_json_response(500, ['error' => ['code' => 'space_import_entry_failed', 'message' => 'Space import entry could not be written.', 'details' => ['path' => $relativePath]]]);
        }
        $written = stream_copy_to_stream($source, $output, $entrySize + 1);
        fclose($source);
        fclose($output);
        if ($written === false || $written !== $entrySize || filesize($target . '.tmp') !== $entrySize) {
            @unlink($target . '.tmp');
            $zip->close();
            _stattic_json_response(422, ['error' => ['code' => 'space_import_entry_invalid', 'message' => 'Space import entry size does not match archive metadata.', 'details' => ['path' => $relativePath]]]);
        }
        rename($target . '.tmp', $target);
        $processed += 1;
    }
    $zip->close();

    $status['cursor'] = min($limit, $nextCursor);
    $status['processed_files'] = $processed;
    $status['updated_at'] = gmdate('c');
    if ((int) $status['cursor'] >= $limit) {
        _stattic_runtime_deep_validate_staged_space_import($stagingRoot . '/versions');
        _stattic_runtime_validate_staged_import_versions($stagingRoot . '/versions', $versionIds);
        $zeroMode = _stattic_runtime_zero_mode($status['zero_mode'] ?? null);
        $zeroRebind = is_array($status['zero_rebind'] ?? null) ? $status['zero_rebind'] : [];
        _stattic_runtime_install_imported_versions($privateRoot, $spaceId, $versionIds, $stagingRoot . '/versions', $zeroMode, $zeroRebind);
        if (($status['install_access_policy'] ?? false) === true) {
            _stattic_runtime_install_imported_space_access_policy($privateRoot, $spaceId, $jobRoot);
        }
        $status['status'] = 'complete';
        $status['completed_at'] = gmdate('c');
        _stattic_runtime_record_management_event($privateRoot, $claims, [
            'event' => 'space_import_completed',
            'space_id' => $spaceId,
            'import_id' => $importId,
            'file_count' => $processed,
            'version_ids' => $versionIds,
        ]);
    } else {
        $status['status'] = 'running';
    }
    _stattic_runtime_write_json_atomic($jobRoot . '/status.json', $status);
    _stattic_json_response(200, _stattic_runtime_space_import_status_response($privateRoot, $status));
}

function _stattic_runtime_prepare_space_import_archive(string $privateRoot, array $status): array
{
    $importId = (string) ($status['import_id'] ?? '');
    $archivePath = _stattic_runtime_space_import_root($privateRoot, $importId) . '/archive.zip';
    if (!is_file($archivePath) || filesize($archivePath) > STATTIC_IMPORT_ARCHIVE_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_archive_too_large', 'message' => 'Space import archive exceeds the runtime import limit.']]);
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive is not a readable ZIP file.']]);
    }
    [$totalFiles, $totalUncompressedBytes] = _stattic_runtime_validate_import_archive_limits($archivePath, $zip);
    $descriptor = _stattic_runtime_import_root_descriptor($zip);
    $manifest = $descriptor !== null ? json_decode($descriptor['raw'], true) : null;
    // The descriptor's declared format id must match the only accepted layout.
    if ($descriptor === null || !is_array($manifest) || ($manifest['format'] ?? null) !== $descriptor['format']) {
        $zip->close();
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive is not a Spacefast export.']]);
    }
    $versionIds = [];
    if (!is_array($manifest['versions'] ?? null)) {
        $zip->close();
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive manifest is missing versions.']]);
    }
    $versionIdMap = is_array($status['version_id_map'] ?? null) ? $status['version_id_map'] : [];
    foreach ($manifest['versions'] as $versionId) {
        if (!is_string($versionId)) {
            $zip->close();
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive manifest contains an invalid version.']]);
        }
        $sourceVersionId = _stattic_runtime_id($versionId, 'version_id');
        $versionIds[] = _stattic_runtime_mapped_version_id($sourceVersionId, $versionIdMap);
    }
    $versionIds = array_values(array_unique($versionIds));
    for ($index = 0; $index < $zip->numFiles; $index += 1) {
        $name = $zip->getNameIndex($index);
        if (!is_string($name)) {
            continue;
        }
        if ($name === $descriptor['entry'] || str_ends_with($name, '/')) {
            continue;
        }
        if ($name === STATTIC_EXPORT_SPACE_ACCESS_POLICY_ENTRY) {
            _stattic_runtime_stage_import_space_access_policy(_stattic_runtime_space_import_root($privateRoot, $importId), $zip, $index);
            continue;
        }
        $relativePath = _stattic_runtime_export_version_relative_path($name);
        if ($relativePath === null) {
            $zip->close();
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive contains an unexpected entry.', 'details' => ['path' => $name]]]);
        }
        $entrySize = _stattic_runtime_import_entry_size($zip, $index);
        $artifactKind = _stattic_runtime_validate_import_entry($relativePath, $entrySize);
        if ($artifactKind !== null) {
            _stattic_runtime_validate_import_entry_php($zip, $index, $relativePath, $artifactKind);
        }
        $sourceVersionId = _stattic_runtime_import_entry_version_id($relativePath);
        $targetVersionId = _stattic_runtime_mapped_version_id($sourceVersionId, $versionIdMap);
        if (!in_array($targetVersionId, $versionIds, true)) {
            $zip->close();
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive contains a version not listed in manifest.', 'details' => ['version_id' => $sourceVersionId]]]);
        }
    }
    $zip->close();
    $status['status'] = 'pending';
    $status['cursor'] = 0;
    $status['processed_files'] = 0;
    $status['total_files'] = $totalFiles;
    $status['total_uncompressed_bytes'] = $totalUncompressedBytes;
    $status['version_ids'] = $versionIds;
    $status['updated_at'] = gmdate('c');
    return $status;
}

// Space-level explicit config travels with the archive: the unified access
// policy and serving-secret map are validated at intake, staged next to the job,
// and installed for the target space at completion.
function _stattic_runtime_stage_import_space_access_policy(string $jobRoot, ZipArchive $zip, int $index): void
{
    $entrySize = _stattic_runtime_import_entry_size($zip, $index);
    if ($entrySize < 0 || $entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import access policy exceeds the runtime import limit.']]);
    }
    $raw = $zip->getFromIndex($index, STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES + 1);
    if (is_string($raw) && strlen($raw) > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import access policy exceeds the runtime import limit.']]);
    }
    if (!is_string($raw) || strlen($raw) !== $entrySize) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import access policy could not be read.']]);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !array_key_exists('policy', $decoded)) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import access policy is malformed.']]);
    }
    $staged = ['policy' => _stattic_runtime_unified_policy($decoded['policy'])];
    if (array_key_exists('secrets', $decoded)) {
        if (!is_array($decoded['secrets'])) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import policy secrets are malformed.']]);
        }
        $secrets = [];
        foreach ($decoded['secrets'] as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value) || $value === '') {
                _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import policy secrets are malformed.']]);
            }
            $secrets[$name] = $value;
        }
        $staged['secrets'] = $secrets;
    }
    _stattic_runtime_write_json_atomic($jobRoot . '/space-access-policy.json', $staged);
}

function _stattic_runtime_install_imported_space_access_policy(string $privateRoot, string $spaceId, string $jobRoot): void
{
    $staged = _stattic_runtime_read_json($jobRoot . '/space-access-policy.json');
    if (is_array($staged) && array_key_exists('policy', $staged)) {
        _stattic_runtime_store_unified_policy($privateRoot, $spaceId, $staged['policy']);
        if (array_key_exists('secrets', $staged)) {
            _stattic_runtime_store_policy_secrets($privateRoot, $spaceId, $staged['secrets']);
        }
    }
}

function _stattic_runtime_reject_import_archive_too_large_from_headers(): void
{
    $length = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (is_string($length) && preg_match('/^\d+$/', $length) === 1 && (int) $length > STATTIC_IMPORT_ARCHIVE_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_archive_too_large', 'message' => 'Space import archive exceeds the runtime import limit.']]);
    }
}

// Declared uncompressed size of a ZIP entry, or -1 when the archive omits it.
function _stattic_runtime_import_entry_size(ZipArchive $zip, int $index): int
{
    $stat = $zip->statIndex($index);
    return is_array($stat) ? (int) ($stat['size'] ?? -1) : -1;
}

// Bound every central-directory record, then measure the entries step can
// materialize and enforce the archive-wide expansion floors.
function _stattic_runtime_validate_import_archive_limits(string $archivePath, ZipArchive $zip): array
{
    $archiveSize = is_file($archivePath) ? filesize($archivePath) : false;
    if (!is_int($archiveSize) || $archiveSize > STATTIC_IMPORT_ARCHIVE_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_archive_too_large', 'message' => 'Space import archive exceeds the runtime import limit.']]);
    }
    // Cap the central directory before any per-entry name/stat/path work. The
    // materialized-file counter below intentionally excludes descriptors,
    // policy, and directories because it is also the public progress count;
    // this separate floor counts every record, including entries later rejected
    // as unexpected.
    $archiveEntries = $zip->numFiles;
    if (!is_int($archiveEntries) || $archiveEntries < 0 || $archiveEntries > STATTIC_IMPORT_MAX_ARCHIVE_ENTRIES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_archive_too_large', 'message' => 'Space import archive contains too many entries.']]);
    }
    $totalFiles = 0;
    $totalUncompressedBytes = 0;
    $boundedJsonEntries = [
        STATTIC_EXPORT_ROOT_DESCRIPTOR => true,
        STATTIC_EXPORT_SPACE_ACCESS_POLICY_ENTRY => true,
    ];
    for ($index = 0; $index < $archiveEntries; $index += 1) {
        $name = $zip->getNameIndex($index);
        if (!is_string($name) || str_ends_with($name, '/')) {
            continue;
        }
        $entrySize = _stattic_runtime_import_entry_size($zip, $index);
        if (isset($boundedJsonEntries[$name])) {
            if ($entrySize < 0 || $entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
                _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import JSON artifact exceeds the runtime import limit.', 'details' => ['path' => $name]]]);
            }
            // Descriptors and policy are not materialized by step, so they do
            // not change total_files/cursor semantics. Their expansion still
            // counts once toward the aggregate and compression-ratio floors.
            $totalUncompressedBytes += $entrySize;
            if ($totalUncompressedBytes > STATTIC_IMPORT_TOTAL_UNCOMPRESSED_MAX_BYTES) {
                _stattic_json_response(413, ['error' => ['code' => 'space_import_archive_too_large', 'message' => 'Space import archive expands beyond the runtime import limit.']]);
            }
            continue;
        }
        $relativePath = _stattic_runtime_export_version_relative_path($name);
        if ($relativePath === null) {
            continue;
        }
        _stattic_runtime_validate_import_entry($relativePath, $entrySize);
        $totalFiles += 1;
        $totalUncompressedBytes += $entrySize;
        if ($totalFiles > STATTIC_IMPORT_MAX_FILES || $totalUncompressedBytes > STATTIC_IMPORT_TOTAL_UNCOMPRESSED_MAX_BYTES) {
            _stattic_json_response(413, ['error' => ['code' => 'space_import_archive_too_large', 'message' => 'Space import archive expands beyond the runtime import limit.']]);
        }
    }
    // Zip-bomb guard: declared expansion must stay within a sane ratio of the archive bytes.
    if ($totalUncompressedBytes > STATTIC_IMPORT_MAX_COMPRESSION_RATIO * max(1, $archiveSize)) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_compression_ratio_exceeded', 'message' => 'Space import archive expands beyond the runtime compression-ratio limit.']]);
    }
    return [$totalFiles, $totalUncompressedBytes];
}

function _stattic_runtime_normalize_version_id_map(array $rawMap): array
{
    $versionIdMap = [];
    foreach ($rawMap as $sourceVersionId => $targetVersionId) {
        if (!is_string($sourceVersionId) || !is_string($targetVersionId)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_import_request', 'message' => 'version_id_map keys and values must be strings.']]);
        }
        $versionIdMap[_stattic_runtime_id($sourceVersionId, 'version_id')] = _stattic_runtime_id($targetVersionId, 'version_id');
    }
    return $versionIdMap;
}

// Source version id mapped onto its target via the (possibly empty) version-id
// map; unmapped ids pass through unchanged.
function _stattic_runtime_mapped_version_id(string $sourceVersionId, array $versionIdMap): string
{
    return is_string($versionIdMap[$sourceVersionId] ?? null)
        ? _stattic_runtime_id($versionIdMap[$sourceVersionId], 'version_id')
        : $sourceVersionId;
}

// Cheap structural checks shared by prepare and step: entry-size caps, the
// version-path shape, and the artifact-kind allowlist. Returns the PHP artifact
// kind for PHP artifacts, or null for raw byte trees and the inert manifest.
function _stattic_runtime_validate_import_entry(string $relativePath, int $entrySize): ?string
{
    if ($entrySize < 0 || $entrySize > STATTIC_IMPORT_ENTRY_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import entry exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
    }
    $slash = strpos($relativePath, '/');
    $pathWithinVersion = $slash === false ? '' : substr($relativePath, $slash + 1);
    if ($pathWithinVersion === '') {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive contains an invalid version path.']]);
    }
    // Raw byte trees: served files, pre-substitution template originals,
    // per-route channel-variant template files, and compiled Zero program
    // bytecode/metadata (§9 — opaque data the runtime never evals; it
    // only ever dispatches through the lookup actions in serving.php /
    // php-manifest.php / zero/routes.php, which the gate below neutralizes
    // to the "activating" stub unless the target is proven dedicated).
    if (
        str_starts_with($pathWithinVersion, 'files/')
        || str_starts_with($pathWithinVersion, 'files-original/')
        || str_starts_with($pathWithinVersion, 'files-variants/')
    ) {
        return null;
    }
    // Endpoint descriptors, source, and bytecode are admitted only through
    // the canonical endpoint-artifact path predicate. The staged-version
    // validator then verifies that this is exactly the set referenced by the
    // trusted compiled routes and checks both program hashes before install.
    if (_stattic_runtime_zero_private_artifact_path_valid($pathWithinVersion, '.json')) {
        if ($entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
            _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import Zero endpoint artifact exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
        }
        return 'zero_json';
    }
    if (
        _stattic_runtime_zero_private_artifact_path_valid($pathWithinVersion, '.source.js')
        || _stattic_runtime_zero_private_artifact_path_valid($pathWithinVersion, '.bytecode')
    ) {
        return null;
    }
    // Run descriptors, source, and bytecode are admitted only through the
    // canonical run-artifact path predicate. The staged-version validator then
    // verifies that this is exactly the set referenced by runs-index.json and
    // checks both program hashes before installation.
    if (_stattic_runtime_zero_run_artifact_valid($pathWithinVersion)) {
        if (str_ends_with($pathWithinVersion, '.json')) {
            if ($entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
                _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import Zero run artifact exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
            }
            return 'zero_json';
        }
        return null;
    }
    if (_stattic_runtime_zero_run_artifact_valid($pathWithinVersion)) {
        if (str_ends_with($pathWithinVersion, '.json')) {
            if ($entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
                _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import Zero run artifact exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
            }
            return 'zero_json';
        }
        return null;
    }
    // The per-version manifest (metadata.json on disk) is inert JSON provenance,
    // not executable artifact data.
    if ($pathWithinVersion === 'manifest.json') {
        if ($entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
            _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import version manifest exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
        }
        return null;
    }
    // Zero's own inert JSON side-artifacts (endpoint index, config, migrations,
    // run index): validated as JSON below, not as a "safe PHP" array literal.
    if (in_array($pathWithinVersion, [
        'active.json',
        'debug.json',
        'zero/routes.json',
        'zero/endpoints-index.json',
        'zero/config.json',
        'zero/migrations.json',
        'zero/runs-index.json',
    ], true)) {
        if ($entrySize > STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES) {
            _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import Zero artifact exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
        }
        return 'zero_json';
    }
    $artifactKind = _stattic_runtime_import_php_artifact_kind($pathWithinVersion);
    if (!$artifactKind) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive contains an unexpected version artifact.', 'details' => ['path' => $relativePath]]]);
    }
    if ($entrySize > STATTIC_IMPORT_PHP_ARTIFACT_MAX_BYTES) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import PHP artifact exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
    }
    return $artifactKind;
}

// A handle can already have staged entries when a site upgrades engines. Walk
// that resumable state through the same non-executing source decoder before
// any validator can include a staged artifact.
function _stattic_runtime_deep_validate_staged_space_import(string $stagingVersionsRoot): void
{
    if (!is_dir($stagingVersionsRoot)) {
        return;
    }
    $realRoot = realpath($stagingVersionsRoot);
    if (!is_string($realRoot)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_path_unresolved', 'message' => 'Staged space import path could not be resolved.']]);
    }
    _stattic_runtime_assert_private_path($realRoot);
    $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        $relativePath = _stattic_runtime_relative_to($normalizedRoot, $file->getPathname());
        if ($file->isLink()) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Staged space import contains an unsupported filesystem link.', 'details' => ['path' => $relativePath]]]);
        }
        if ($file->isDir()) {
            continue;
        }
        if (!$file->isFile()) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Staged space import contains an unsupported filesystem entry.', 'details' => ['path' => $relativePath]]]);
        }
        $realPath = $file->getRealPath();
        $normalizedRealPath = is_string($realPath) ? str_replace('\\', '/', $realPath) : '';
        if (!is_string($realPath) || !_stattic_runtime_path_is_inside($normalizedRealPath, $normalizedRoot)) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Staged space import escaped its version root.', 'details' => ['path' => $relativePath]]]);
        }
        _stattic_runtime_assert_private_path($realPath);
        if (str_ends_with($relativePath, '.tmp')) {
            continue;
        }
        $relativePath = _stattic_runtime_archive_entry_from_disk($relativePath);
        $entrySize = filesize($realPath);
        $artifactKind = _stattic_runtime_validate_import_entry(
            $relativePath,
            is_int($entrySize) ? $entrySize : -1
        );
        if ($artifactKind === null) {
            continue;
        }
        $source = file_get_contents($realPath);
        if (!is_string($source)) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_entry_invalid', 'message' => 'Staged space import artifact could not be read.', 'details' => ['path' => $relativePath]]]);
        }
        _stattic_runtime_validate_import_entry_php_source($source, $relativePath, $artifactKind);
    }
}

// Deep inert-code scan of a PHP or Zero JSON artifact's bytes. Prepare runs it
// at upload time, and step repeats it immediately before staging archive bytes.
function _stattic_runtime_validate_import_entry_php(ZipArchive $zip, int $index, string $relativePath, string $artifactKind): void
{
    $maxBytes = $artifactKind === 'zero_json'
        ? STATTIC_IMPORT_JSON_ARTIFACT_MAX_BYTES
        : STATTIC_IMPORT_PHP_ARTIFACT_MAX_BYTES;
    $source = $zip->getFromIndex($index, $maxBytes + 1);
    if (is_string($source) && strlen($source) > $maxBytes) {
        _stattic_json_response(413, ['error' => ['code' => 'space_import_entry_too_large', 'message' => 'Space import artifact exceeds the runtime import limit.', 'details' => ['path' => $relativePath]]]);
    }
    if (!is_string($source) || strlen($source) !== _stattic_runtime_import_entry_size($zip, $index)) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_entry_invalid', 'message' => 'Space import PHP artifact could not be read.', 'details' => ['path' => $relativePath]]]);
    }
    _stattic_runtime_validate_import_entry_php_source($source, $relativePath, $artifactKind);
}

function _stattic_runtime_validate_import_entry_php_source(string $source, string $relativePath, string $artifactKind): void
{
    if ($artifactKind === 'zero_json') {
        if (!is_array(json_decode($source, true))) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import Zero artifact is not valid JSON.', 'details' => ['path' => $relativePath]]]);
        }
        return;
    }
    if ($artifactKind === 'php_manifest') {
        _stattic_runtime_decode_safe_import_php_manifest($source, $relativePath);
    } elseif ($artifactKind === 'active') {
        $value = _stattic_runtime_decode_safe_import_php_value($source, $relativePath);
        if (($value['format'] ?? null) !== 'stattic.runtime.active.v1') {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Space import active artifact has invalid metadata.', 'details' => ['path' => $relativePath]]]);
        }
    } else {
        _stattic_runtime_decode_safe_import_php($source, $relativePath);
    }
}

function _stattic_runtime_import_php_artifact_kind(string $pathWithinVersion): ?string
{
    if ($pathWithinVersion === 'serving.php') {
        return 'serving';
    }
    if ($pathWithinVersion === 'headers.php') {
        return 'headers';
    }
    if ($pathWithinVersion === 'redirects.php') {
        return 'redirects';
    }
    if ($pathWithinVersion === 'php-manifest.php') {
        return 'php_manifest';
    }
    if ($pathWithinVersion === 'template-variants.php') {
        return 'template_variants';
    }
    if ($pathWithinVersion === 'active.php') {
        return 'active';
    }
    if ($pathWithinVersion === 'zero/routes.php') {
        return 'zero_routes';
    }
    if (in_array($pathWithinVersion, ['zero/migrations.php', 'zero/endpoints-index.php', 'zero/runs-index.php'], true)) {
        return 'zero_artifact';
    }
    if (preg_match('/^file-shards\/[a-f0-9]{2}\.php$/', $pathWithinVersion) === 1) {
        return 'file_shard';
    }
    return null;
}

function _stattic_runtime_decode_safe_import_php(string $source, string $relativePath): array
{
    $value = _stattic_runtime_decode_safe_import_php_value($source, $relativePath);
    if (!_stattic_runtime_artifact_metadata_valid_lazy($value)) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Space import PHP artifact has invalid runtime metadata.', 'details' => ['path' => $relativePath]]]);
    }
    return $value;
}

function _stattic_runtime_decode_safe_import_php_manifest(string $source, string $relativePath): array
{
    $value = _stattic_runtime_decode_safe_import_php_value($source, $relativePath);
    if (!is_array($value) || ($value['format'] ?? null) !== 'stattic.php.manifest.v1' || !is_array($value['routes'] ?? null)) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Space import PHP manifest has invalid metadata.', 'details' => ['path' => $relativePath]]]);
    }
    return $value;
}

function _stattic_runtime_decode_safe_import_php_value(string $source, string $relativePath): array
{
    $prefix = "<?php\nreturn ";
    $trimmed = rtrim($source);
    if (!str_starts_with($source, $prefix) || !str_ends_with($trimmed, ';')) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Space import PHP artifact is not generated data.', 'details' => ['path' => $relativePath]]]);
    }
    $expression = substr($trimmed, strlen($prefix), -1);
    _stattic_runtime_assert_safe_import_php_expression($expression, $relativePath);
    $value = eval('return ' . $expression . ';');
    if (!is_array($value)) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Space import PHP artifact has invalid runtime metadata.', 'details' => ['path' => $relativePath]]]);
    }
    return $value;
}

function _stattic_runtime_assert_safe_import_php_expression(string $expression, string $relativePath): void
{
    foreach (token_get_all('<?php ' . $expression) as $token) {
        if (is_string($token)) {
            if (!in_array($token, ['(', ')', '[', ']', ',', '-', '+'], true)) {
                _stattic_runtime_reject_import_php_token($relativePath);
            }
            continue;
        }
        $id = $token[0];
        if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_ARROW, T_ARRAY], true)) {
            continue;
        }
        if ($id === T_STRING && in_array(strtolower($token[1]), ['true', 'false', 'null'], true)) {
            continue;
        }
        _stattic_runtime_reject_import_php_token($relativePath);
    }
}

function _stattic_runtime_reject_import_php_token(string $relativePath): void
{
    _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Space import PHP artifact contains executable code.', 'details' => ['path' => $relativePath]]]);
}

function _stattic_runtime_validate_staged_import_versions(string $stagingVersionsRoot, array $versionIds): void
{
    foreach ($versionIds as $versionId) {
        $versionId = _stattic_runtime_id((string) $versionId, 'version_id');
        $versionRoot = $stagingVersionsRoot . '/' . $versionId;
        _stattic_runtime_assert_private_path($versionRoot);
        $filesByPath = _stattic_runtime_imported_file_metadata($versionRoot);
        _stattic_runtime_validate_imported_local_bytes($versionRoot, $filesByPath);
        _stattic_runtime_validate_version_artifacts($versionRoot, $filesByPath);
    }
}

// Import-only integrity gate. Normal finalize has already hashed bytes while
// committing them and must stay off this extra O(total version bytes) path;
// imported PHP shards, however, are archive input and cannot be trusted as a
// content-addressed cache key until their local bytes are re-derived here.
function _stattic_runtime_validate_imported_local_bytes(string $versionRoot, array $filesByPath): void
{
    foreach ($filesByPath as $path => $meta) {
        if (!is_string($path) || !is_array($meta) || !is_string($meta['disk_path'] ?? null)) {
            _stattic_runtime_reject_imported_byte_mismatch('base', is_string($path) ? $path : null);
        }
        $canonicalPath = _stattic_runtime_imported_canonical_file_path($path, $meta, 'base');
        $file = _stattic_runtime_confined_imported_file(
            $versionRoot,
            'files',
            $canonicalPath,
            'base'
        );
        // A portable export always materializes remotely tiered bytes back
        // into the archive. Accepting a locator-only base entry here would
        // let tenant-authored shard metadata name bytes this runtime never
        // verified (and cannot serve without the source tenant's bucket).
        _stattic_runtime_assert_imported_file_bytes($file, $meta, 'base', $canonicalPath);
    }

    $variantsPath = $versionRoot . '/template-variants.php';
    if (!is_file($variantsPath)) {
        return;
    }
    $variants = @include $variantsPath;
    if (!is_array($variants) || !is_array($variants['routes'] ?? null)) {
        _stattic_runtime_reject_imported_byte_mismatch('variant', null);
    }
    foreach ($variants['routes'] as $route => $files) {
        if (
            !is_string($route)
            || trim($route) !== $route
            || !_stattic_runtime_id_valid($route)
            || !is_array($files)
        ) {
            _stattic_runtime_reject_imported_byte_mismatch('variant', null);
        }
        $canonicalRoute = _stattic_runtime_id($route, 'route_name');
        foreach ($files as $path => $meta) {
            if (!is_string($path) || !is_array($meta)) {
                _stattic_runtime_reject_imported_byte_mismatch('variant', is_string($path) ? $path : null);
            }
            $canonicalPath = _stattic_runtime_imported_canonical_file_path($path, $meta, 'variant');
            $variantFile = _stattic_runtime_confined_imported_file(
                $versionRoot,
                'files-variants/' . $canonicalRoute,
                $canonicalPath,
                'variant'
            );
            _stattic_runtime_assert_imported_file_bytes(
                $variantFile,
                $meta,
                'variant',
                $canonicalPath
            );
        }
    }
}

function _stattic_runtime_imported_canonical_file_path(string $path, array $meta, string $kind): string
{
    $canonicalPath = _stattic_nfc_path($path);
    if (
        $canonicalPath !== $path
        || trim($canonicalPath) !== $canonicalPath
        || str_contains($canonicalPath, '\\')
        || str_contains($canonicalPath, '//')
        || ltrim($canonicalPath, '/') !== $canonicalPath
        || $canonicalPath === ''
        || str_contains($canonicalPath, "\0")
        || str_contains('/' . $canonicalPath . '/', '/../')
        || str_starts_with($canonicalPath, '../')
        || ($meta['disk_path'] ?? null) !== $canonicalPath
    ) {
        _stattic_runtime_reject_imported_byte_mismatch($kind, $path);
    }
    // Reuse the runtime canonicalizer after the non-throwing checks above
    // establish that attacker-authored archive data is already canonical.
    return _stattic_runtime_file_path($canonicalPath);
}

function _stattic_runtime_confined_imported_file(
    string $versionRoot,
    string $relativeRoot,
    string $canonicalPath,
    string $kind
): string {
    $root = $versionRoot . '/' . $relativeRoot;
    $file = $root . '/' . $canonicalPath;
    $normalizedRoot = _stattic_runtime_normalize_absolute_path($root);
    $normalizedFile = _stattic_runtime_normalize_absolute_path($file);
    $realVersionRoot = realpath($versionRoot);
    $realRoot = realpath($root);
    $realFile = realpath($file);
    if (
        !is_string($normalizedRoot)
        || !is_string($normalizedFile)
        || !_stattic_runtime_path_is_inside($normalizedFile, $normalizedRoot)
        || !is_string($realVersionRoot)
        || !is_string($realRoot)
        || !is_string($realFile)
        || !_stattic_runtime_path_is_inside($realRoot, $realVersionRoot)
        || !_stattic_runtime_path_is_inside($realFile, $realRoot)
    ) {
        _stattic_runtime_reject_imported_byte_mismatch($kind, $canonicalPath);
    }
    return $realFile;
}

function _stattic_runtime_assert_imported_file_bytes(string $file, array $meta, string $kind, string $path): void
{
    $expectedSize = $meta['size'] ?? null;
    $expectedSha256 = $meta['sha256'] ?? null;
    $actualSize = is_file($file) ? filesize($file) : false;
    $actualSha256 = is_file($file) ? hash_file('sha256', $file) : false;
    if (
        !is_int($expectedSize)
        || !is_string($expectedSha256)
        || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1
        || $actualSize === false
        || $actualSize !== $expectedSize
        || !is_string($actualSha256)
        || !hash_equals($expectedSha256, $actualSha256)
    ) {
        _stattic_runtime_reject_imported_byte_mismatch($kind, $path);
    }
}

function _stattic_runtime_reject_imported_byte_mismatch(string $kind, ?string $path): never
{
    _stattic_json_response(422, [
        'error' => [
            'code' => 'space_import_archive_invalid',
            'message' => 'Imported file bytes do not match their content metadata.',
            'details' => ['kind' => $kind, 'path' => $path],
        ],
    ]);
}

function _stattic_runtime_imported_file_metadata(string $versionRoot): array
{
    $filesByPath = [];
    foreach (glob($versionRoot . '/file-shards/*.php') ?: [] as $path) {
        $relative = _stattic_runtime_relative_to($versionRoot, (string) $path);
        $artifact = _stattic_runtime_decode_safe_import_php((string) file_get_contents((string) $path), $relative);
        if (!is_array($artifact['files'] ?? null)) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Imported file metadata shard is malformed.', 'details' => ['path' => $relative]]]);
        }
        foreach ($artifact['files'] as $filePath => $meta) {
            if (!is_string($filePath) || !is_array($meta)) {
                _stattic_json_response(422, ['error' => ['code' => 'space_import_php_artifact_invalid', 'message' => 'Imported file metadata shard is malformed.', 'details' => ['path' => $relative]]]);
            }
            $filesByPath[$filePath] = $meta;
        }
    }
    return $filesByPath;
}

function _stattic_runtime_import_entry_version_id(string $relativePath): string
{
    $slash = strpos($relativePath, '/');
    if ($slash === false || $slash === 0) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Space import archive contains an invalid version path.']]);
    }
    return _stattic_runtime_id(substr($relativePath, 0, $slash), 'version_id');
}

// $zeroMode mirrors finalize's own fail-safe (management.php: any value other
// than the literal 'active' is treated as 'activating') — plan §9: a Zero
// version imported onto a space that hasn't PROVEN dedicated, active
// isolation must never dispatch live execution, including during the
// publish->move window. Zero-bearing versions additionally go through the
// native import rebind before publication (their compiled executable graph is
// recompiled for THIS space, never installed verbatim); the deactivation scan
// below then neutralizes the rebuilt dispatch on any non-active target before
// it ever becomes reachable.
function _stattic_runtime_install_imported_versions(string $privateRoot, string $spaceId, array $versionIds, string $stagingVersionsRoot, string $zeroMode = 'activating', array $zeroRebind = []): void
{
    $spaceRoot = _spacefast_space_root($privateRoot, $spaceId);
    $versionsRoot = $spaceRoot . '/versions';
    foreach ($versionIds as $versionId) {
        $target = $versionsRoot . '/' . _stattic_runtime_id((string) $versionId, 'version_id');
        if (is_dir($target)) {
            _stattic_json_response(409, ['error' => ['code' => 'version_already_committed', 'message' => 'Imported version already exists.', 'details' => ['version_id' => $versionId]]]);
        }
    }

    // Phase 1: rebind every staged Zero-bearing version while it is still
    // unreachable. The archive carries the SOURCE space's compiled executable
    // graph verbatim; the native rebind recompiles the finalized sources for
    // this target space, deletes the old graph, and writes the rebuilt one. A
    // later version's rebind failure must not publish an earlier version.
    // Versions without Zero artifacts never invoke the binary.
    $rebound = [];
    foreach ($versionIds as $versionId) {
        $versionId = _stattic_runtime_id((string) $versionId, 'version_id');
        $source = $stagingVersionsRoot . '/' . $versionId;
        if (!is_dir($source)) {
            continue;
        }
        $targetZero = is_array($zeroRebind[$versionId] ?? null) ? $zeroRebind[$versionId] : null;
        $rebound[$versionId] = _stattic_runtime_rebind_imported_version($privateRoot, $spaceId, $versionId, $source, $zeroMode, $targetZero);
    }

    _stattic_runtime_mkdir($versionsRoot);
    foreach ($versionIds as $versionId) {
        $versionId = _stattic_runtime_id((string) $versionId, 'version_id');
        $source = $stagingVersionsRoot . '/' . $versionId;
        $target = $versionsRoot . '/' . $versionId;
        _stattic_runtime_assert_private_path($source);
        _stattic_runtime_assert_private_path($target);
        if (is_dir($source)) {
            rename($source, $target);
        } else {
            _stattic_runtime_mkdir($target);
        }
        // Fail closed: neutralize tenant invoke_zero dispatch on any non-active
        // (shared) target UNCONDITIONALLY. The endpoints-index is tenant-supplied
        // archive data and can under-report (missing/empty) while serving.php,
        // php-manifest.php, or zero/routes.php still carry live invoke_zero — so
        // the scan must not be gated on it. The scan is a no-op when there is
        // nothing to neutralize.
        if ($zeroMode !== 'active') {
            _stattic_runtime_deactivate_imported_zero_dispatch($target);
        }
        // The rebind regenerated zero/migrations.json from the recompiled
        // graph; apply it against this runtime's database exactly like the
        // finalize lanes do (no-op when the rebuilt graph has no migrations).
        if ($rebound[$versionId] ?? false) {
            _stattic_runtime_apply_zero_migrations($target);
        }
    }
}

// Invokes the native runtime compiler's `--rebind-import` lane for one staged
// imported version. Returns false without invoking the binary when the version
// carries no Zero artifacts (no zero/ tree AND no tenant invoke_zero dispatch
// in serving.php / php-manifest.php). When Zero artifacts ARE present the
// rebind is unconditional: importing another space's compiled executable graph
// verbatim is exactly the hole this closes, so a missing binary fails the
// import closed (503) — never a signal to install the archive graph as-is.
function _stattic_runtime_rebind_imported_version(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    string $versionRoot,
    string $zeroMode,
    ?array $targetZero
): bool
{
    $servingPath = $versionRoot . '/serving.php';
    $phpManifestPath = $versionRoot . '/php-manifest.php';
    $hasZeroTree = is_dir($versionRoot . '/zero');
    if (!$hasZeroTree && (!is_file($servingPath) || !is_file($phpManifestPath))) {
        // Nothing executable to rebind; artifact presence is owned by the
        // staged-import validators that already ran.
        return false;
    }
    if (!is_file($servingPath) || !is_file($phpManifestPath)) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Imported serving artifacts are missing.', 'details' => ['version_id' => $versionId]]]);
    }
    $servingSource = file_get_contents($servingPath);
    $manifestSource = file_get_contents($phpManifestPath);
    if (!is_string($servingSource) || !is_string($manifestSource)) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Imported serving artifacts could not be read.', 'details' => ['version_id' => $versionId]]]);
    }
    $serving = _stattic_runtime_decode_safe_import_php($servingSource, $versionId . '/serving.php');
    $phpManifest = _stattic_runtime_decode_safe_import_php_manifest($manifestSource, $versionId . '/php-manifest.php');
    if (!$hasZeroTree && !_stattic_runtime_import_artifacts_carry_zero_dispatch($serving, $phpManifest)) {
        return false;
    }

    $zeroRoutesPath = $versionRoot . '/zero/routes.php';
    $zeroRoutes = null;
    if (is_file($zeroRoutesPath)) {
        $zeroRoutesSource = file_get_contents($zeroRoutesPath);
        if (!is_string($zeroRoutesSource)) {
            _stattic_json_response(422, ['error' => ['code' => 'space_import_archive_invalid', 'message' => 'Imported Zero routes could not be read.', 'details' => ['version_id' => $versionId]]]);
        }
        $zeroRoutes = _stattic_runtime_decode_safe_import_php($zeroRoutesSource, $versionId . '/zero/routes.php');
    }

    $binary = _stattic_runtime_native_compiler_binary();
    if ($binary === '' || !is_file($binary) || !is_executable($binary) || !function_exists('proc_open')) {
        _stattic_json_response(503, ['error' => ['code' => 'runtime_finalizer_unavailable', 'message' => 'The native runtime compiler is unavailable for import.']]);
    }

    $nonce = bin2hex(random_bytes(6));
    $inputPath = dirname($versionRoot) . '/.import-rebind-' . $versionId . '-' . $nonce . '.json';
    $outputPath = $inputPath . '.output.json';
    _stattic_runtime_write_json_atomic($inputPath, [
        'format' => 'spacefast.finalizer.import-rebind.input.v1',
        'versionRoot' => $versionRoot,
        'targetSpaceId' => $spaceId,
        'targetVersionId' => $versionId,
        'zeroMode' => $zeroMode === 'active' ? 'active' : 'activating',
        'targetZero' => $targetZero,
        'artifacts' => [
            'serving' => $serving,
            'phpManifest' => $phpManifest,
            'zeroRoutes' => $zeroRoutes,
        ],
    ]);
    $result = _stattic_runtime_run_finalizer_process(
        [$binary, '--rebind-import', '--input', $inputPath, '--output', $outputPath],
        $privateRoot,
        30000
    );
    @unlink($inputPath);
    if ($result === null || $result[3] === true || $result[0] !== 0) {
        @unlink($outputPath);
        _stattic_json_response(422, ['error' => ['code' => 'space_import_rebind_failed', 'message' => 'Imported runtime artifacts could not be safely rebound.', 'details' => ['version_id' => $versionId]]]);
    }
    $output = json_decode((string) @file_get_contents($outputPath), true);
    @unlink($outputPath);
    if (
        !is_array($output)
        || ($output['format'] ?? null) !== 'spacefast.finalizer.import-rebind.output.v1'
        || ($output['targetSpaceId'] ?? null) !== $spaceId
        || ($output['targetVersionId'] ?? null) !== $versionId
    ) {
        _stattic_json_response(422, ['error' => ['code' => 'space_import_rebind_failed', 'message' => 'Imported runtime artifact rebind returned an invalid result.', 'details' => ['version_id' => $versionId]]]);
    }
    if (($output['targetBindingsRequired'] ?? null) === true) {
        _stattic_json_response(422, [
            'error' => [
                'code' => 'space_import_zero_target_bindings_required',
                'message' => 'Imported Zero environment, auth, and realtime bindings must be configured for the target space before activation.',
                'details' => ['version_id' => $versionId],
            ],
        ]);
    }
    return true;
}

// Detection half of the rebind gate: even when the zero/ tree is absent, an
// archive whose serving.php lookup or php-manifest.php routes still carry
// tenant invoke_zero dispatch is Zero-bearing (a truncated or malicious export
// can under-ship the tree while keeping live dispatch) and must go through the
// rebind, which strips dispatch that has no recompiled program behind it.
function _stattic_runtime_import_artifacts_carry_zero_dispatch(array $serving, array $phpManifest): bool
{
    foreach (is_array($serving['lookup'] ?? null) ? $serving['lookup'] : [] as $action) {
        if (_stattic_runtime_import_zero_action_is_tenant_endpoint($action)) {
            return true;
        }
    }
    foreach (is_array($phpManifest['routes'] ?? null) ? $phpManifest['routes'] : [] as $route) {
        if (_stattic_runtime_import_zero_action_is_tenant_endpoint($route)) {
            return true;
        }
    }
    return false;
}

// A tenant-authored Zero dispatch leaf — Zero's own platform control routes
// (config/run/auth_start/auth_sign_out/realtime_events, added via
// _stattic_runtime_zero_control_lookup_actions() outside the zero_mode gate
// at finalize too — management.php ~628-637) carry an 'operation' string
// instead of an endpoint_id/zero_artifact pair and are never gated: they are
// Zero's own bootstrapping, not tenant code, on par with the rest of the
// runtime's platform-authored PHP.
function _stattic_runtime_import_zero_action_is_tenant_endpoint(mixed $action): bool
{
    return is_array($action)
        && ($action['action'] ?? null) === 'invoke_zero'
        && !is_string($action['operation'] ?? null);
}

// Tenant endpoint dispatches and the platform-authored run control route can
// execute tenant bytecode. The other control routes only serve platform state
// and remain available while a version is activating.
function _stattic_runtime_import_zero_action_executes_tenant_code(mixed $action, bool $hasZeroRuns): bool
{
    return is_array($action)
        && ($action['action'] ?? null) === 'invoke_zero'
        && (
            !is_string($action['operation'] ?? null)
            || ($hasZeroRuns && ($action['operation'] ?? null) === 'run')
        );
}

// Three artifact files can carry a compiled Zero dispatch decision for a
// version, and serve.php/php-manifest.php each expect the "not live yet"
// state in a DIFFERENT shape — this mirrors generate.php's own finalize
// conventions exactly (same helpers, same shapes) rather than inventing a
// parallel encoding:
//   - serving.php's exact-match 'lookup' map: the entry stays present with
//     action rewritten to 'zero_activating' (serve.php dispatches on this
//     value directly) — _stattic_runtime_zero_activating_action().
//   - php-manifest.php's endpoint routes: _stattic_resolve_request_lookup_action
//     checks these FIRST, so retain them with their supported 'activating'
//     marker. This preserves Zero precedence when an exact endpoint shadows a
//     normal lookup entry. Run-control routes are still dropped so the rewritten
//     serving.php control lookup supplies its activating response.
//   - zero/routes.php's pattern buckets: entries carry an 'activating'
//     boolean flag rather than swapping a field —
//     _stattic_runtime_mark_zero_routes_activating().
function _stattic_runtime_deactivate_imported_zero_dispatch(string $versionRoot): void
{
    $hasZeroRuns = is_file($versionRoot . '/zero/runs-index.json');
    $servingPath = $versionRoot . '/serving.php';
    if (is_file($servingPath)) {
        $serving = @include $servingPath;
        if (is_array($serving) && is_array($serving['lookup'] ?? null)) {
            foreach ($serving['lookup'] as $path => $action) {
                if (_stattic_runtime_import_zero_action_executes_tenant_code($action, $hasZeroRuns)) {
                    $serving['lookup'][$path] = _stattic_runtime_zero_activating_action($action);
                }
            }
            _stattic_runtime_write_php_atomic($servingPath, $serving);
        }
    }

    $phpManifestPath = $versionRoot . '/php-manifest.php';
    if (is_file($phpManifestPath)) {
        $manifest = @include $phpManifestPath;
        if (is_array($manifest) && is_array($manifest['routes'] ?? null)) {
            $routes = [];
            foreach ($manifest['routes'] as $route) {
                if (!_stattic_runtime_import_zero_action_executes_tenant_code($route, $hasZeroRuns)) {
                    $routes[] = $route;
                    continue;
                }
                if (is_array($route) && !is_string($route['operation'] ?? null)) {
                    $routes[] = [...$route, 'activating' => true];
                }
            }
            $manifest['routes'] = $routes;
            _stattic_runtime_write_php_atomic($phpManifestPath, $manifest);
        }
    }

    $zeroRoutesPath = $versionRoot . '/zero/routes.php';
    if (is_file($zeroRoutesPath)) {
        $routes = @include $zeroRoutesPath;
        if (is_array($routes)) {
            $marked = _stattic_runtime_mark_zero_routes_activating([
                'exact' => is_array($routes['exact'] ?? null) ? $routes['exact'] : [],
                'by_first_segment' => is_array($routes['by_first_segment'] ?? null) ? $routes['by_first_segment'] : [],
                'fallback' => is_array($routes['fallback'] ?? null) ? $routes['fallback'] : [],
            ]);
            _stattic_runtime_write_php_atomic($zeroRoutesPath, [...$routes, ...$marked]);
        }
    }
}
