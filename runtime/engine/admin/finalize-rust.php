<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/native-process.php';

// Ownership boundary: Rust owns every immutable version byte and artifact —
// this file validates only the result envelope, never the artifacts themselves.

function _stattic_runtime_native_session_payload(array $session): array
{
    // A session reloaded from disk decodes `accepted: {}` to an empty PHP
    // array, which re-encodes as `[]` — the finalizer requires an object. The
    // lazy path normalizes on write (upload.php); this is the choke point for
    // every path, including a zero-upload retain-all created by create_version.
    $session['accepted'] = _stattic_runtime_json_object(
        is_array($session['accepted'] ?? null) ? $session['accepted'] : []
    );
    return $session;
}

function _stattic_runtime_native_body_payload(array $body): array
{
    // json_decode(..., true) collapses every empty JSON object into a PHP
    // array, which json_encode writes back as `[]`. Rust intentionally models
    // these fields as maps, so preserve their wire identity at the one PHP ->
    // native boundary. Values are already resolved by the control plane; this
    // changes only container shape, never variable contents.
    $scopes = $body['variable_scopes'] ?? null;
    if (is_array($scopes)) {
        foreach ($scopes as $scopeIndex => $scope) {
            if (!is_array($scope)) {
                continue;
            }
            $values = is_array($scope['values'] ?? null) ? $scope['values'] : [];
            foreach ($values as $name => $variable) {
                if (!is_array($variable) || !array_key_exists('channelValues', $variable)) {
                    continue;
                }
                $variable['channelValues'] = _stattic_runtime_json_object(
                    is_array($variable['channelValues']) ? $variable['channelValues'] : []
                );
                $values[$name] = $variable;
            }
            $scope['values'] = _stattic_runtime_json_object($values);
            $scopes[$scopeIndex] = $scope;
        }
        $body['variable_scopes'] = $scopes;
    }
    if (array_key_exists('system_variables', $body)) {
        $body['system_variables'] = _stattic_runtime_json_object(
            is_array($body['system_variables']) ? $body['system_variables'] : []
        );
    }
    return $body;
}

// Storage boundary for Rust: every retained byte must sit in the per-space CAS
// (`spaces/<spaceId>/blobs/<aa>/<sha>`) before the finalizer starts — Rust reads
// retained files from the CAS only and never touches the network.
//
// This runs under the space lock (finalize_version's row), which is what makes
// reading the reusable version's catalog safe against a concurrent finalize or
// GC sweep of that space.
function _stattic_runtime_prepare_retained_blobs(string $privateRoot, string $spaceId, array $session): array
{
    $entries = is_array($session['retained_files'] ?? null) ? $session['retained_files'] : [];
    $reusableVersionId = is_string($session['reusable_version_id'] ?? null)
        ? _stattic_runtime_id($session['reusable_version_id'], 'reusable_version_id')
        : null;
    // Re-validated here, not trusted: a session record written before this
    // engine version carries no mode, and reading that as retain-nothing would
    // drop files silently. It fails loudly instead.
    $retention = _stattic_runtime_retention_mode($session['retention'] ?? null, $reusableVersionId, $entries);
    // "Retain everything from version X" carries no list and gets none: Rust
    // materializes the path set from that version's catalog inside the same pass
    // that stages it, so a version of any size costs one catalog read here
    // instead of a per-path loop and a multi-megabyte envelope.
    if ($retention === 'all' || $entries === []) {
        return $session;
    }
    // A caller-supplied list still needs the reusable version to be readable:
    // without its catalog there is no telling a path that moved from a version
    // that is gone, and the recovery branch below reads the same document.
    if (_stattic_runtime_version_catalog($privateRoot, $spaceId, $reusableVersionId) === null) {
        _stattic_problem_response(
            409,
            'runtime_file_catalog_invalid',
            'The reusable version has no readable file catalog.',
            ['details' => ['version_id' => $reusableVersionId]],
        );
    }
    $missing = static function (string $path) use ($reusableVersionId): never {
        _stattic_problem_response(409, 'version_reusable_file_missing', 'A retained file was missing from the reusable version.', ['details' => ['path' => $path, 'version_id' => $reusableVersionId]]);
    };
    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $path = _stattic_runtime_file_path((string) ($entry['path'] ?? ''));
        _stattic_runtime_assert_static_upload_path($path);
        $hasDeclaredSha = array_key_exists('sha256', $entry);
        $sha = is_string($entry['sha256'] ?? null) ? strtolower(trim($entry['sha256'])) : '';
        if ($hasDeclaredSha && preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            _stattic_problem_response(422, 'invalid_blob_sha', 'Retained file sha256 is invalid.', ['details' => ['path' => $path]]);
        }
        if ($sha === '') {
            // A ready manifest may carry no digest; the reusable version's own
            // catalog is authoritative, so recover the source identity from
            // there rather than blocking a settings-only re-finalize forever.
            $source = _stattic_runtime_resolve_version_file($privateRoot, $spaceId, $reusableVersionId, $path, 'source');
            if ($source === null) {
                $missing($path);
            }
            $sha = $source['sha'];
            $entries[$index]['sha256'] = $sha;
        }
        // v4 keeps retained bytes in the CAS and nowhere else: a version root
        // has no file tree to re-materialize them from.
        if (!_stattic_runtime_blob_has($privateRoot, $spaceId, $sha)) {
            $missing($path);
        }
    }
    $session['retained_files'] = $entries;
    return $session;
}

function _stattic_runtime_finalize_with_rust(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    string $uploadId,
    array $session,
    array $body
): array {
    $binary = _stattic_runtime_native_binary();
    if ($binary === '' || !is_file($binary) || !is_executable($binary)) {
        _stattic_problem_response(503, 'finalize_unavailable', 'The native runtime finalizer is not installed.');
    }
    if (!function_exists('proc_open')) {
        _stattic_problem_response(
            503,
            'finalize_unavailable',
            'The runtime cannot start the native finalizer process.',
        );
    }
    $canonicalPrivateRoot = realpath($privateRoot);
    if (!is_string($canonicalPrivateRoot) || $canonicalPrivateRoot === '') {
        _stattic_problem_response(
            500,
            'runtime_finalizer_storage_invalid',
            'The runtime private storage root is unavailable.',
        );
    }
    $versionRoot = _stattic_version_root($canonicalPrivateRoot, $spaceId, $versionId);

    $serving = is_array($body['serving'] ?? null) ? $body['serving'] : [];
    $zeroEndpointsInput = $serving['zero_endpoints'] ?? [];
    if (!is_array($zeroEndpointsInput)) {
        _stattic_problem_response(422, 'zero_endpoints_invalid', 'Zero endpoints must be an array.');
    }
    $zeroRunsInput = $serving['zero_runs'] ?? [];
    if (!is_array($zeroRunsInput)) {
        _stattic_problem_response(422, 'zero_runs_invalid', 'Zero run handlers must be an array.');
    }
    $session = _stattic_runtime_prepare_retained_blobs($canonicalPrivateRoot, $spaceId, $session);

    $input = [
        'format' => 'stattic.runtime.finalize.input.v2',
        // v2 is filesystem-rooted: `versionRoot` is the PRIVATE STORAGE ROOT,
        // not a version directory.
        'versionRoot' => $canonicalPrivateRoot,
        'spaceId' => $spaceId,
        'versionId' => $versionId,
        'generatedAt' => gmdate('c'),
        'session' => _stattic_runtime_json_object(_stattic_runtime_native_session_payload($session)),
        'body' => _stattic_runtime_json_object(_stattic_runtime_native_body_payload($body)),
        'zeroEndpoints' => _stattic_runtime_zero_compiler_entries($zeroEndpointsInput, 'endpoint_id', 'endpointId'),
        'zeroRuns' => _stattic_runtime_zero_compiler_entries($zeroRunsInput, 'run_id', 'runId'),
    ];
    if ($uploadId !== '') {
        $input['uploadId'] = $uploadId;
    }

    $incomingRoot = $canonicalPrivateRoot . '/runtime/finalizer-inputs';
    _stattic_runtime_mkdir($incomingRoot);
    $inputPath = $incomingRoot . '/' . ($uploadId !== '' ? $uploadId : 'refinalize') . '-' . bin2hex(random_bytes(6)) . '.json';
    $outputPath = $inputPath . '.output.json';
    _stattic_runtime_write_json_atomic($inputPath, $input);

    // Move an existing immutable version aside BEFORE the binary starts, so an
    // interruption anywhere in the native run leaves a recoverable
    // `.rust-previous`; Rust owns the recovery semantics from there.
    if (is_dir($versionRoot)) {
        $backup = dirname($versionRoot) . '/.' . $versionId . '.rust-previous';
        if (is_dir($backup)) {
            _stattic_runtime_rm_recursive($backup);
        }
        if (!rename($versionRoot, $backup)) {
            unlink($inputPath);
            _stattic_problem_response(
                500,
                'runtime_finalizer_storage_invalid',
                'The previous immutable version could not be staged for replacement.',
            );
        }
    }

    $command = [$binary, 'finalize', '--input', $inputPath, '--output', $outputPath];
    $result = _stattic_runtime_run_subprocess($command, null, null, $canonicalPrivateRoot, 310000, 8 * 1024 * 1024, 64 * 1024);
    if (!$result['spawned']) {
        _stattic_runtime_restore_interrupted_version($versionRoot);
        unlink($inputPath);
        unlink($outputPath);
        _stattic_problem_response(503, 'finalize_unavailable', 'The native runtime finalizer could not be started.');
    }
    $exitCode = $result['exitCode'];
    $stderr = $result['stderr'];
    $timedOut = $result['timedOut'];
    unlink($inputPath);
    if ($timedOut) {
        _stattic_runtime_restore_interrupted_version($versionRoot);
        unlink($outputPath);
        _stattic_problem_response(
            503,
            'runtime_finalizer_timeout',
            'The native runtime finalizer exceeded its execution deadline.',
        );
    }
    if ($exitCode !== 0) {
        $structuredError = _stattic_runtime_read_finalizer_output($outputPath);
        _stattic_runtime_restore_interrupted_version($versionRoot);
        unlink($outputPath);
        $safeReason = is_string($stderr)
            ? preg_replace('/[^A-Za-z0-9_.:\/ -]+/', '', substr(trim($stderr), 0, 500))
            : '';
        $structuredValid = is_array($structuredError)
            && ($structuredError['format'] ?? null) === 'stattic.runtime.finalize.error.v2'
            && is_string($structuredError['code'] ?? null)
            && preg_match('/^[a-z][a-z0-9_]+$/', $structuredError['code']) === 1
            && is_string($structuredError['message'] ?? null)
            && is_array($structuredError['details'] ?? null);
        $rustCode = $structuredValid ? $structuredError['code'] : 'runtime_finalizer_failed';
        $conflictCodes = [
            'version_upload_incomplete',
            'version_reusable_file_missing',
            'version_existing_mismatch',
            // The state of a version this publish reuses, not of the request:
            // same 409 the PHP-side check answered with before the retained set
            // was materialized natively.
            'runtime_file_catalog_invalid',
        ];
        $status = in_array($rustCode, $conflictCodes, true) ? 409 : 422;
        error_log('spacefast finalizer failed code=' . $rustCode . ' exit=' . $exitCode . ' reason=' . $safeReason);
        $errorDetails = $structuredValid ? $structuredError['details'] : ['exit_code' => $exitCode];
        _stattic_problem_response(
            $status,
            $rustCode,
            $structuredValid ? $structuredError['message'] : 'The native runtime finalizer rejected the version.',
            ['details' => _stattic_runtime_json_object($errorDetails)],
        );
    }
    $output = _stattic_runtime_read_finalizer_output($outputPath);
    unlink($outputPath);
    // Envelope only — Rust self-validates every immutable artifact it wrote.
    if (
        !is_array($output)
        || ($output['format'] ?? null) !== 'stattic.runtime.finalize.output.v2'
        || ($output['spaceId'] ?? null) !== $spaceId
        || ($output['versionId'] ?? null) !== $versionId
        || !is_int($output['fileCount'] ?? null)
        || !is_int($output['zeroEndpointCount'] ?? null)
    ) {
        _stattic_runtime_restore_interrupted_version($versionRoot);
        _stattic_problem_response(
            500,
            'runtime_finalizer_output_invalid',
            'The native runtime finalizer returned an invalid result.',
        );
    }
    return $output;
}

// A `.rust-previous` sibling means a replacement was in flight; the failed run's
// leftovers are quarantined so serving never sees a half-written tree.
function _stattic_runtime_restore_interrupted_version(string $versionRoot): void
{
    $backup = dirname($versionRoot) . '/.' . basename($versionRoot) . '.rust-previous';
    if (!is_dir($backup)) {
        return;
    }
    $failed = dirname($versionRoot) . '/.' . basename($versionRoot) . '.rust-failed-' . bin2hex(random_bytes(4));
    if (is_dir($versionRoot) && !rename($versionRoot, $failed)) {
        error_log('spacefast finalizer could not quarantine the failed replacement');
        return;
    }
    if (!rename($backup, $versionRoot)) {
        if (is_dir($failed)) {
            rename($failed, $versionRoot);
        }
        error_log('spacefast finalizer could not restore the previous immutable version');
        return;
    }
    if (is_dir($failed)) {
        _stattic_runtime_rm_recursive($failed);
    }
}

function _stattic_runtime_read_finalizer_output(string $path): ?array
{
    $size = is_file($path) ? filesize($path) : false;
    if (!is_int($size) || $size < 2 || $size > 128 * 1024 * 1024) {
        return null;
    }
    $decoded = _stattic_runtime_read_json($path);
    return is_array($decoded) ? $decoded : null;
}
