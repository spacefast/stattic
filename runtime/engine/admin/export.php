<?php
declare(strict_types=1);

function _stattic_runtime_start_space_export(string $privateRoot, string $spaceId, array $claims): void
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $spaceRoot = _spacefast_space_root($privateRoot, $spaceId);
    if (!is_dir($spaceRoot)) {
        _stattic_json_response(404, ['error' => ['code' => 'space_not_found', 'message' => 'Space not found.']]);
    }
    if (!class_exists('ZipArchive')) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_zip_unavailable', 'message' => 'Runtime ZIP support is unavailable.']]);
    }

    $body = _stattic_json_body();
    foreach (array_keys($body) as $key) {
        if (!in_array($key, ['version_ids', 'include_files', 'include_explicit_config'], true)) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_export_request', 'message' => 'Export accepts only version_ids, include_files, and include_explicit_config.']]);
        }
    }
    $includeFiles = _stattic_runtime_export_boolean_option($body, 'include_files');
    $includeExplicitConfig = _stattic_runtime_export_boolean_option($body, 'include_explicit_config');
    $exportId = _stattic_runtime_new_id('sex');
    $jobRoot = _stattic_runtime_space_export_root($privateRoot, $exportId);
    if (is_dir($jobRoot)) {
        _stattic_json_response(409, ['error' => ['code' => 'space_export_exists', 'message' => 'Space export already exists.']]);
    }

    $versionIds = _stattic_runtime_export_space_version_ids($privateRoot, $spaceId, $body);
    $files = _stattic_runtime_export_version_file_list($spaceRoot . '/versions', $versionIds);
    // §7/§8 "user exports always contain real bytes": a demoted (tiered)
    // served file has no on-disk path, so the plain directory walk above
    // silently omits it. Fold in every shard-recorded remote-only
    // representation under the SAME relative-path convention the on-disk
    // walk uses, so the step below has one uniform file list and a
    // side-table of where to recover bytes that aren't local.
    $remoteEntries = _stattic_runtime_export_remote_file_entries($spaceRoot . '/versions', $versionIds);
    if ($remoteEntries !== []) {
        $files = array_values(array_unique([...$files, ...array_keys($remoteEntries)]));
        sort($files, SORT_STRING);
    }
    if (!$includeFiles) {
        // Honest includeFiles=false (spec SpaceExportRequest): a config-only
        // archive keeps only the per-version manifests. It is explicitly not
        // self-servable and importers reject it.
        $files = array_values(array_filter($files, static function ($relativePath): bool {
            return is_string($relativePath) && preg_match('#^[^/]+/metadata\.json$#', $relativePath) === 1;
        }));
        $remoteEntries = [];
    }
    _stattic_runtime_mkdir($jobRoot);
    _stattic_runtime_write_json_atomic($jobRoot . '/files.json', ['files' => $files]);
    if ($remoteEntries !== []) {
        _stattic_runtime_write_json_atomic($jobRoot . '/remote-files.json', ['entries' => $remoteEntries]);
    }
    $manifest = [
        'format' => STATTIC_EXPORT_FORMAT,
        'runtime_schema' => STATTIC_RUNTIME_SCHEMA,
        'runtime_engine_version' => SPACEFAST_RUNTIME_ENGINE_VERSION,
        'source_space_id' => $spaceId,
        'export_id' => $exportId,
        'created_at' => gmdate('c'),
        'versions' => $versionIds,
        // What the archive carries, so importers can reject config-only
        // archives instead of materializing unservable versions.
        'includes' => ['files' => $includeFiles, 'explicit_config' => $includeExplicitConfig],
    ];
    $archivePath = $jobRoot . '/archive.zip';
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        _stattic_json_response(500, ['error' => ['code' => 'space_export_archive_failed', 'message' => 'Space export archive could not be created.']]);
    }
    $zip->addFromString(STATTIC_EXPORT_ROOT_DESCRIPTOR, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    if ($includeExplicitConfig) {
        $policy = _stattic_runtime_read_json($spaceRoot . '/policy.json');
        $secrets = _stattic_runtime_read_json($spaceRoot . '/policy-secrets.json');
        $policyDoc = is_array($policy['policy'] ?? null) ? $policy['policy'] : null;
        $secretMap = is_array($secrets['secrets'] ?? null) ? $secrets['secrets'] : null;
        if ($policyDoc !== null || $secretMap !== null) {
            $zip->addFromString(STATTIC_EXPORT_SPACE_ACCESS_POLICY_ENTRY, json_encode([
                'policy' => $policyDoc ?? ['rules' => []],
                'secrets' => $secretMap ?? new stdClass(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
    $zip->close();
    _stattic_runtime_assert_private_path($archivePath);

    $status = [
        'type' => 'space_export',
        'export_id' => $exportId,
        'space_id' => $spaceId,
        'status' => count($files) === 0 ? 'complete' : 'pending',
        'cursor' => 0,
        'processed_files' => 0,
        'total_files' => count($files),
        'version_ids' => $versionIds,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    if ($status['status'] === 'complete') {
        $status['completed_at'] = gmdate('c');
        $status['archive_sha256'] = _stattic_runtime_export_archive_sha256($archivePath);
    }
    _stattic_runtime_write_json_atomic($jobRoot . '/status.json', $status);
    _stattic_runtime_record_management_event($privateRoot, $claims, [
        'event' => 'space_export_started',
        'space_id' => $spaceId,
        'export_id' => $exportId,
        'file_count' => count($files),
        'version_ids' => $versionIds,
    ]);
    _stattic_json_response(201, _stattic_runtime_space_archive_status_response($privateRoot, $status));
}

function _stattic_runtime_export_boolean_option(array $body, string $key): bool
{
    if (!array_key_exists($key, $body)) {
        return true;
    }
    if (!is_bool($body[$key])) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_export_request', 'message' => $key . ' must be a boolean.']]);
    }
    return $body[$key];
}

function _stattic_runtime_export_archive_sha256(string $archivePath): string
{
    $sha256 = is_file($archivePath) ? hash_file('sha256', $archivePath) : false;
    if (!is_string($sha256)) {
        _stattic_json_response(500, ['error' => ['code' => 'space_export_archive_failed', 'message' => 'Space export archive hash could not be computed.']]);
    }
    return $sha256;
}

function _stattic_runtime_step_space_export(string $privateRoot, string $exportId, array $claims): void
{
    [$exportId, $jobRoot, $status, $spaceId] = _stattic_runtime_load_space_archive_job($privateRoot, 'space-exports', $exportId);
    if (($status['status'] ?? null) === 'complete') {
        _stattic_json_response(200, _stattic_runtime_space_archive_status_response($privateRoot, $status));
    }
    if (!is_dir(_spacefast_space_root($privateRoot, $spaceId))) {
        _stattic_json_response(404, ['error' => ['code' => 'space_not_found', 'message' => 'Space not found.']]);
    }

    $filesRecord = _stattic_runtime_read_json($jobRoot . '/files.json');
    $files = is_array($filesRecord) && is_array($filesRecord['files'] ?? null) ? $filesRecord['files'] : [];
    $remoteRecord = _stattic_runtime_read_json($jobRoot . '/remote-files.json');
    $remoteEntries = is_array($remoteRecord) && is_array($remoteRecord['entries'] ?? null) ? $remoteRecord['entries'] : [];
    $cursor = max(0, (int) ($status['cursor'] ?? 0));
    $limit = min(count($files), $cursor + STATTIC_EXPORT_JOB_CHUNK_SIZE);

    // Pass 1: resolve every entry in this chunk to a real readable path.
    // Local bytes are used as-is; a demoted (remote-only) entry is pulled
    // back from cold storage into a scratch file first — §7/§8 "user exports
    // always contain real bytes", never a stub or a silent omission.
    $sources = [];
    $tempPaths = [];
    $fetchBatch = [];
    for ($index = $cursor; $index < $limit; $index += 1) {
        $relativePath = is_string($files[$index] ?? null) ? $files[$index] : '';
        $source = _spacefast_version_root($privateRoot, $spaceId, $relativePath);
        _stattic_runtime_assert_private_path($source);
        if (is_file($source)) {
            $sources[$index] = $source;
            continue;
        }
        $remote = $remoteEntries[$relativePath] ?? null;
        if (
            !is_array($remote)
            || !is_string($remote['bucket'] ?? null)
            || !is_string($remote['key'] ?? null)
            || !is_string($remote['sha256'] ?? null)
        ) {
            foreach ($tempPaths as $tempPath) {
                @unlink($tempPath);
            }
            _stattic_json_response(409, ['error' => ['code' => 'space_export_source_changed', 'message' => 'Space changed while export was running.', 'details' => ['path' => $relativePath]]]);
        }
        $dest = $jobRoot . '/fetch-' . bin2hex(random_bytes(12));
        $fetchBatch[$index] = [
            'id' => (string) $index,
            'bucket' => $remote['bucket'],
            'key' => $remote['key'],
            'dest_path' => $dest,
            'sha256' => $remote['sha256'],
        ];
    }
    if ($fetchBatch !== []) {
        $results = _stattic_s3_multi_get(array_values($fetchBatch));
        foreach ($fetchBatch as $index => $item) {
            $result = $results[$item['id']] ?? null;
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                @unlink($item['dest_path']);
                foreach ($tempPaths as $tempPath) {
                    @unlink($tempPath);
                }
                _stattic_json_response(502, ['error' => ['code' => 'space_export_remote_fetch_failed', 'message' => 'Space export could not recover a demoted file from cold storage.', 'details' => ['path' => $files[$index] ?? null]]]);
            }
            $sources[$index] = $item['dest_path'];
            $tempPaths[] = $item['dest_path'];
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($jobRoot . '/archive.zip', ZipArchive::CREATE) !== true) {
        foreach ($tempPaths as $tempPath) {
            @unlink($tempPath);
        }
        _stattic_json_response(500, ['error' => ['code' => 'space_export_archive_failed', 'message' => 'Space export archive could not be opened.']]);
    }
    for ($index = $cursor; $index < $limit; $index += 1) {
        $relativePath = is_string($files[$index] ?? null) ? $files[$index] : '';
        $zip->addFile($sources[$index], STATTIC_EXPORT_FORMAT . '/versions/' . _stattic_runtime_archive_entry_from_disk($relativePath));
    }
    $zip->close();
    // addFile() only registers the entry; close() is where ZipArchive reads
    // the bytes off disk, so the scratch fetches are safe to remove now.
    foreach ($tempPaths as $tempPath) {
        @unlink($tempPath);
    }

    $status['cursor'] = $limit;
    $status['processed_files'] = $limit;
    $status['updated_at'] = gmdate('c');
    if ($limit >= count($files)) {
        $status['status'] = 'complete';
        $status['completed_at'] = gmdate('c');
        // Computed exactly once at completion so the control plane can expose
        // SpaceExport.archive.sha256 for download verification.
        $status['archive_sha256'] = _stattic_runtime_export_archive_sha256($jobRoot . '/archive.zip');
        _stattic_runtime_record_management_event($privateRoot, $claims, [
            'event' => 'space_export_completed',
            'space_id' => $spaceId,
            'export_id' => $exportId,
            'file_count' => count($files),
        ]);
    } else {
        $status['status'] = 'running';
    }
    _stattic_runtime_write_json_atomic($jobRoot . '/status.json', $status);
    _stattic_json_response(200, _stattic_runtime_space_archive_status_response($privateRoot, $status));
}

function _stattic_runtime_download_space_export(string $privateRoot, string $exportId): void
{
    [$exportId, $jobRoot, $status, $spaceId] = _stattic_runtime_load_space_archive_job($privateRoot, 'space-exports', $exportId);
    if (($status['status'] ?? null) !== 'complete') {
        _stattic_json_response(409, ['error' => ['code' => 'space_export_not_complete', 'message' => 'Space export is not complete.']]);
    }
    $archivePath = $jobRoot . '/archive.zip';
    if (!is_file($archivePath)) {
        _stattic_json_response(404, ['error' => ['code' => 'space_export_archive_missing', 'message' => 'Space export archive is missing.']]);
    }
    _stattic_runtime_send_zip_attachment($archivePath, $spaceId . '-' . $exportId . '.zip');
}

// Demoted (tiered) served files have no on-disk path, so the plain directory
// walk in _stattic_runtime_export_version_file_list silently misses them —
// this is the other half of the fix (§7/§8 "user exports always contain real
// bytes"). Reads each version's file-shards directly (the same representation
// walk tier.php's demote/promote steps use) and returns every local:false
// entry keyed by the SAME "$versionId/files/$diskPath" convention the on-disk
// walk produces, so the export step can recover bytes for exactly those keys.
function _stattic_runtime_export_remote_file_entries(string $versionsRoot, array $versionIds): array
{
    $entries = [];
    foreach ($versionIds as $versionId) {
        $versionId = _stattic_runtime_id((string) $versionId, 'version_id');
        $shardRoot = $versionsRoot . '/' . $versionId . '/file-shards';
        _stattic_runtime_assert_private_path($shardRoot);
        if (!is_dir($shardRoot)) {
            continue;
        }
        foreach (glob($shardRoot . '/*.php') ?: [] as $shardPath) {
            if (!is_string($shardPath) || preg_match('/^[a-f0-9]{2}\.php$/', basename($shardPath)) !== 1) {
                continue;
            }
            $loaded = @include $shardPath;
            $shardFiles = is_array($loaded) && is_array($loaded['files'] ?? null) ? $loaded['files'] : [];
            foreach (_stattic_tier_shard_representations($shardFiles) as $rep) {
                $meta = $rep['meta'];
                if (($meta['local'] ?? true) !== false) {
                    continue;
                }
                $remote = is_array($meta['remote'] ?? null) ? $meta['remote'] : null;
                $sha = _stattic_transfer_meta_sha($meta);
                if ($sha === null || $remote === null || !is_string($remote['bucket'] ?? null) || !is_string($remote['key'] ?? null)) {
                    continue;
                }
                $diskPath = (string) ($meta['disk_path'] ?? $rep['path']);
                $relativePath = $versionId . '/files/' . _stattic_transfer_safe_disk_path($diskPath);
                $entries[$relativePath] = [
                    'bucket' => (string) $remote['bucket'],
                    'key' => (string) $remote['key'],
                    'sha256' => $sha,
                ];
            }
        }
    }
    return $entries;
}
