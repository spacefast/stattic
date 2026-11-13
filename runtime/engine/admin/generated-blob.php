<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/upload.php';

// Files the control plane builds from a version's own source, such as the
// Functions worker it compiles at finalize. The publisher never saw their
// bytes, so they cannot be declared when the upload session is created: the
// control plane writes them to the space CAS here, then names them in the
// finalize body's `generated_files`, which adds them to the session manifest.
//
// Only the Functions bundle path is accepted, so this lane can never place a
// file a publisher could have uploaded themselves.
const STATTIC_RUNTIME_GENERATED_BLOB_MAX_BYTES = 25165824; // FUNCTIONS_BUNDLE_MAX_BYTES
const STATTIC_RUNTIME_GENERATED_BLOB_PIN_TTL_SECONDS = 3600;
const STATTIC_RUNTIME_GENERATED_FILES_MAX = 4;

function _stattic_runtime_generated_blob_pin_id(string $sha): string
{
    return 'generated-' . $sha;
}

function _stattic_runtime_generated_blob_put(string $privateRoot, string $spaceId, array $claims): void
{
    $sha = strtolower(trim(is_string($_GET['sha256'] ?? null) ? $_GET['sha256'] : ''));
    if (!_stattic_is_sha256_hex($sha)) {
        _stattic_problem_response(422, 'invalid_blob_sha', 'Blob sha256 is invalid.');
    }
    _stattic_runtime_upload_admit($privateRoot, $spaceId);
    $staged = _stattic_runtime_blob_stage_stream(
        $privateRoot,
        _stattic_request_body_stream(),
        STATTIC_RUNTIME_GENERATED_BLOB_MAX_BYTES,
    );
    if (($staged['ok'] ?? false) !== true) {
        if (($staged['reason'] ?? null) === 'too_large') {
            _stattic_problem_response(413, 'generated_blob_too_large', 'Generated blob exceeds ' . STATTIC_RUNTIME_GENERATED_BLOB_MAX_BYTES . ' bytes.');
        }
        _stattic_problem_response(500, 'upload_write_failed', 'Generated blob bytes could not be staged.');
    }
    $tmpPath = (string) $staged['tmp_path'];
    $size = (int) $staged['size'];
    $actualSha = strtolower((string) $staged['sha256']);
    if (!hash_equals($sha, $actualSha)) {
        unlink($tmpPath);
        _stattic_problem_response(422, 'upload_hash_mismatch', 'Blob bytes do not match the sha256 parameter.', ['details' => ['declared_sha256' => $sha, 'received_sha256' => $actualSha]]);
    }
    // Pin before commit, so the retention pass can never collect the object
    // between this write and the finalize that adopts it.
    _stattic_record_store_put(
        _stattic_runtime_publish_pins_store($privateRoot, $spaceId),
        _stattic_runtime_generated_blob_pin_id($sha),
        ['shas' => [$sha], 'expires_at' => gmdate('c', time() + STATTIC_RUNTIME_GENERATED_BLOB_PIN_TTL_SECONDS)],
    );
    _stattic_runtime_blob_commit_verified($privateRoot, $spaceId, $tmpPath, $sha);
    _stattic_json_response(200, ['sha256' => $sha, 'size' => $size]);
}

/**
 * Validates the finalize body's `generated_files` against the space CAS.
 *
 * @return array<string,array{path:string,sha256:string,size:int,contentType?:string}> by sha
 */
function _stattic_runtime_generated_files(string $privateRoot, string $spaceId, mixed $input): array
{
    if ($input === null) {
        return [];
    }
    if (!is_array($input) || !array_is_list($input) || count($input) > STATTIC_RUNTIME_GENERATED_FILES_MAX) {
        _stattic_problem_response(422, 'generated_files_invalid', 'generated_files must be a list of at most ' . STATTIC_RUNTIME_GENERATED_FILES_MAX . ' files.');
    }
    $files = [];
    foreach ($input as $file) {
        $path = is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : '';
        $sha = is_array($file) && is_string($file['sha256'] ?? null) ? strtolower(trim($file['sha256'])) : '';
        $size = is_array($file) ? ($file['size'] ?? null) : null;
        if (!_stattic_is_sha256_hex($sha) || !is_int($size) || $size < 0
            || $path !== SPACEFAST_UPLOAD_PUBLISHABLE_BUNDLE_PREFIX . $sha . '/bundle.json') {
            _stattic_problem_response(422, 'generated_files_invalid', 'A generated file must be the Functions bundle named by its sha256.', ['details' => ['path' => $path]]);
        }
        if (_stattic_runtime_path_size(_stattic_runtime_blob_path($privateRoot, $spaceId, $sha)) !== $size) {
            _stattic_problem_response(409, 'generated_file_missing', 'A generated file is not in the space store at its declared size; write it again before finalizing.', ['details' => ['path' => $path, 'sha256' => $sha]]);
        }
        $entry = ['path' => $path, 'sha256' => $sha, 'size' => $size];
        if (is_string($file['contentType'] ?? null)) {
            $entry['contentType'] = $file['contentType'];
        }
        $files[$sha] = $entry;
    }
    return $files;
}

/**
 * Adds generated files to this finalize attempt's session as declared and
 * accepted, as if the publisher had uploaded them. Only the in-memory session
 * changes: a failed attempt leaves the stored session as the publisher made
 * it, so a retry that builds a different bundle never carries the old one.
 */
function _stattic_runtime_session_with_generated_files(array $session, array $generated): array
{
    if ($generated === []) {
        return $session;
    }
    $paths = array_column($generated, 'path');
    $manifest = array_values(array_filter(
        is_array($session['manifest'] ?? null) ? $session['manifest'] : [],
        static fn (mixed $entry): bool => !is_array($entry) || !in_array($entry['path'] ?? null, $paths, true),
    ));
    $accepted = (array) ($session['accepted'] ?? []);
    foreach ($generated as $sha => $entry) {
        $manifest[] = $entry;
        $accepted[$sha] = $entry['size'];
    }
    ksort($accepted, SORT_STRING);
    $session['manifest'] = $manifest;
    $session['accepted'] = $accepted;
    return $session;
}
