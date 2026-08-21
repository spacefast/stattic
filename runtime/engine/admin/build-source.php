<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/response.php';

const STATTIC_BUILD_SOURCE_MAX_BYTES = 536870912;

function _stattic_build_source_path(string $privateRoot, string $spaceId, string $buildId): string
{
    $spaceId = _stattic_runtime_id($spaceId, 'space_id');
    $buildId = _stattic_runtime_id($buildId, 'build_id');
    $path = _stattic_space_root($privateRoot, $spaceId) . '/build-sources/' . $buildId . '/source.tgz';
    _stattic_runtime_assert_private_path($path);
    return $path;
}

function _stattic_build_source_put(
    string $privateRoot,
    string $spaceId,
    string $buildId,
    array $_claims
): void {
    $target = _stattic_build_source_path($privateRoot, $spaceId, $buildId);
    $staged = _stattic_runtime_blob_stage_stream(
        $privateRoot,
        _stattic_request_body_stream(),
        STATTIC_BUILD_SOURCE_MAX_BYTES
    );
    if (($staged['ok'] ?? false) !== true) {
        if (($staged['reason'] ?? null) === 'too_large') {
            _stattic_problem_response(413, 'build_source_too_large', 'Build source exceeds the remote upload limit.');
        }
        _stattic_problem_response(500, 'build_source_write_failed', 'Build source bytes could not be staged.');
    }
    $size = (int) ($staged['size'] ?? 0);
    $tmpPath = is_string($staged['tmp_path'] ?? null) ? $staged['tmp_path'] : '';
    $sha256 = is_string($staged['sha256'] ?? null) ? strtolower($staged['sha256']) : '';
    if ($size === 0) {
        unlink($tmpPath);
        _stattic_problem_response(400, 'build_source_empty', 'Build source cannot be empty.');
    }
    _stattic_runtime_mkdir(dirname($target));
    $pending = $target . '.tmp-' . bin2hex(random_bytes(6));
    if (!rename($tmpPath, $pending)) {
        _stattic_runtime_copy_private_file($tmpPath, $pending);
        unlink($tmpPath);
    }
    if (!rename($pending, $target)) {
        unlink($pending);
        _stattic_problem_response(500, 'build_source_commit_failed', 'Build source could not be committed.');
    }
    _stattic_json_response(200, ['sha256' => $sha256, 'size' => $size]);
}

function _stattic_build_source_get(
    string $privateRoot,
    string $spaceId,
    string $buildId,
    array $_claims
): void {
    $path = _stattic_build_source_path($privateRoot, $spaceId, $buildId);
    $size = filesize($path);
    if (!is_file($path) || !is_int($size)) {
        _stattic_problem_response(404, 'build_source_not_found', 'Build source was not found.');
    }
    $sha256 = hash_file('sha256', $path);
    if (!is_string($sha256)) {
        _stattic_problem_response(503, 'build_source_unavailable', 'Build source bytes are unavailable.');
    }
    _stattic_json_response(200, ['sha256' => strtolower($sha256), 'size' => $size]);
}

function _stattic_build_source_read(
    string $privateRoot,
    string $spaceId,
    string $buildId,
    array $_claims
): void {
    $path = _stattic_build_source_path($privateRoot, $spaceId, $buildId);
    $size = filesize($path);
    $stream = is_file($path) ? fopen($path, 'rb') : false;
    if (!is_int($size) || $stream === false) {
        _stattic_problem_response(404, 'build_source_not_found', 'Build source was not found.');
    }
    _stattic_runtime_version_source_send($stream, $size, 'application/gzip');
}

function _stattic_build_source_delete(
    string $privateRoot,
    string $spaceId,
    string $buildId,
    array $_claims
): void {
    $path = _stattic_build_source_path($privateRoot, $spaceId, $buildId);
    $deleted = is_file($path);
    _stattic_runtime_rm_recursive(dirname($path));
    _stattic_json_response(200, ['build_id' => $buildId, 'deleted' => $deleted]);
}
