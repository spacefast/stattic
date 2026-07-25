<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/native-process.php';

// Finalize-time ownership boundary: PHP authenticates the management request,
// hydrates retained bytes into the per-space CAS, invokes the native Rust
// finalizer (`stattic-runtime-compiler finalize` with a
// stattic.runtime.finalize.input.v2 file), then performs the
// activation/route-pointer/event side effects. Rust owns every immutable
// version byte and artifact — this file validates only the result envelope,
// never the artifacts themselves.

function _stattic_runtime_native_session_payload(array $session, array $body): array
{
    // The precompiled body is authoritative. Do not copy stale raw convention
    // text from resumable upload state into the native input file: historical
    // sessions may contain plaintext Basic-Auth credentials that Rust neither
    // needs nor persists for this path.
    if (($body['convention_files_precompiled'] ?? null) === true) {
        unset($session['convention_files']);
    }
    return $session;
}

// Storage boundary for Rust: every retained byte must sit in the per-space
// content-addressed store (`spaces/<spaceId>/blobs/<aa>/<sha>`) before the
// finalizer starts — Rust reads retained files from the CAS only and never
// touches the network. Resolution order mirrors the PHP finalize path: the
// reusable version's original bytes, its current tree, then the blob-store /
// cold-bucket fallback.
function _stattic_runtime_prepare_retained_blobs(string $privateRoot, string $spaceId, array $session): void
{
    $entries = is_array($session['retained_files'] ?? null) ? $session['retained_files'] : [];
    if ($entries === []) {
        return;
    }
    $reusableVersionId = is_string($session['reusable_version_id'] ?? null)
        ? _stattic_runtime_id($session['reusable_version_id'], 'reusable_version_id')
        : '';
    if ($reusableVersionId === '') {
        _stattic_json_response(422, ['error' => ['code' => 'reusable_version_required', 'message' => 'Retained files require a reusable version.']]);
    }
    $reusableRoot = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $reusableVersionId;
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $path = _stattic_runtime_file_path((string) ($entry['path'] ?? ''));
        _stattic_runtime_assert_static_upload_path($path);
        $sha = strtolower(trim((string) ($entry['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            _stattic_json_response(422, ['error' => ['code' => 'invalid_blob_sha', 'message' => 'Retained file sha256 is invalid.', 'details' => ['path' => $path]]]);
        }
        if (_stattic_runtime_blob_has($privateRoot, $spaceId, $sha)) {
            continue;
        }
        // Template-substituted versions keep their pre-substitution bytes in
        // `files-original/`; prefer them so the declared sha (the upload
        // identity) verifies against original bytes.
        $local = null;
        foreach ([$reusableRoot . '/files-original/' . $path, $reusableRoot . '/files/' . $path] as $candidate) {
            if (is_file($candidate)) {
                $local = $candidate;
                break;
            }
        }
        if ($local !== null) {
            $staging = $privateRoot . '/runtime/blob-staging/retained-' . bin2hex(random_bytes(12));
            _stattic_runtime_copy_private_file($local, $staging);
            _stattic_runtime_blob_put($privateRoot, $spaceId, $staging, $sha);
            continue;
        }
        // Tier-aware dedupe: a demoted reusable version has no version-tree
        // hardlink — re-materialize from the blob store or the cold bucket
        // (the fallback lands recovered bytes in the CAS itself).
        if (_stattic_runtime_retained_file_fallback_source($privateRoot, $spaceId, $reusableVersionId, $path, $entry) === null) {
            _stattic_json_response(409, ['error' => ['code' => 'version_reusable_file_missing', 'message' => 'A retained file was missing from the reusable version.', 'details' => ['path' => $path, 'version_id' => $reusableVersionId]]]);
        }
    }
}

function _stattic_runtime_finalize_with_rust(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    string $uploadId,
    array $session,
    array $body
): array {
    $binary = _stattic_runtime_native_compiler_binary();
    // Fail before retained-byte hydration or input staging. Native Rust is the
    // sole finalizer in this mode; an unavailable process is never a signal to
    // execute the PHP implementation or partially prepare a replacement.
    if ($binary === '' || !is_file($binary) || !is_executable($binary)) {
        _stattic_json_response(503, [
            'error' => [
                'code' => 'finalize_unavailable',
                'message' => 'The native runtime finalizer is not installed.',
            ],
        ]);
    }
    if (!function_exists('proc_open')) {
        _stattic_json_response(503, [
            'error' => [
                'code' => 'finalize_unavailable',
                'message' => 'The runtime cannot start the native finalizer process.',
            ],
        ]);
    }
    $canonicalPrivateRoot = realpath($privateRoot);
    if (!is_string($canonicalPrivateRoot) || $canonicalPrivateRoot === '') {
        _stattic_json_response(500, [
            'error' => [
                'code' => 'runtime_finalizer_storage_invalid',
                'message' => 'The runtime private storage root is unavailable.',
            ],
        ]);
    }
    $versionRoot = $canonicalPrivateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId;

    $serving = is_array($body['serving'] ?? null) ? $body['serving'] : [];
    $zeroEndpointsInput = $serving['zero_endpoints'] ?? [];
    if (!is_array($zeroEndpointsInput)) {
        _stattic_json_response(422, ['error' => ['code' => 'zero_endpoints_invalid', 'message' => 'Zero endpoints must be an array.']]);
    }
    $zeroRunsInput = $serving['zero_runs'] ?? [];
    if (!is_array($zeroRunsInput)) {
        _stattic_json_response(422, ['error' => ['code' => 'zero_runs_invalid', 'message' => 'Zero run handlers must be an array.']]);
    }
    // Ready stamp for the immutable public-access window: the control plane's
    // value wins, an existing stamp survives re-finalize (read BEFORE the
    // replacement move-aside), and a first finalize stamps once.
    $existingMetadata = _stattic_runtime_read_json($versionRoot . '/metadata.json');
    $existingReadyAt = is_array($existingMetadata) && is_int($existingMetadata['readyAt'] ?? null)
        ? $existingMetadata['readyAt']
        : null;
    $bodyReadyAt = is_int($body['ready_at'] ?? null) && $body['ready_at'] > 0
        ? $body['ready_at']
        : null;
    $readyAt = $bodyReadyAt ?? $existingReadyAt ?? time();

    _stattic_runtime_prepare_retained_blobs($canonicalPrivateRoot, $spaceId, $session);

    $input = [
        'format' => 'stattic.runtime.finalize.input.v2',
        // v2 is filesystem-rooted: versionRoot is the PRIVATE STORAGE ROOT
        // (the directory holding spaces/<spaceId>/... and runtime/uploads/...).
        'versionRoot' => $canonicalPrivateRoot,
        'spaceId' => $spaceId,
        'versionId' => $versionId,
        'generatedAt' => gmdate('c'),
        'readyAt' => $readyAt,
        'session' => _stattic_runtime_json_object(_stattic_runtime_native_session_payload($session, $body)),
        'body' => _stattic_runtime_json_object($body),
        'zeroEndpoints' => _stattic_runtime_zero_compiler_entries($zeroEndpointsInput, 'endpoint_id', 'endpointId'),
        'zeroRuns' => _stattic_runtime_zero_compiler_entries($zeroRunsInput, 'run_id', 'runId'),
    ];
    if ($uploadId !== '') {
        $input['uploadId'] = $uploadId;
    }
    if (is_string($body['previous_pack'] ?? null) && $body['previous_pack'] !== '') {
        $input['previousPack'] = $body['previous_pack'];
    }

    $incomingRoot = $canonicalPrivateRoot . '/runtime/finalizer-inputs';
    _stattic_runtime_mkdir($incomingRoot);
    $inputPath = $incomingRoot . '/' . ($uploadId !== '' ? $uploadId : 'refinalize') . '-' . bin2hex(random_bytes(6)) . '.json';
    $outputPath = $inputPath . '.output.json';
    $manifestOutputPath = $outputPath . '.manifest.json';
    _stattic_runtime_write_json_atomic($inputPath, $input);

    // Replacement backup: an existing immutable version moves aside before the
    // binary starts, so an interruption anywhere in the native run leaves a
    // recoverable `.rust-previous`. Rust owns the recovery semantics from
    // there — it restores the backup when the version dir is missing and
    // answers idempotently (or rejects mismatched input) for the restored dir.
    if (is_dir($versionRoot)) {
        $backup = dirname($versionRoot) . '/.' . $versionId . '.rust-previous';
        if (is_dir($backup)) {
            _stattic_runtime_rm_recursive($backup);
        }
        if (!@rename($versionRoot, $backup)) {
            @unlink($inputPath);
            _stattic_json_response(500, [
                'error' => [
                    'code' => 'runtime_finalizer_storage_invalid',
                    'message' => 'The previous immutable version could not be staged for replacement.',
                ],
            ]);
        }
    }

    $command = [$binary, 'finalize', '--input', $inputPath, '--output', $outputPath];
    $result = _stattic_runtime_run_finalizer_process($command, $canonicalPrivateRoot, 310000);
    if ($result === null) {
        _stattic_runtime_restore_interrupted_version($versionRoot);
        @unlink($inputPath);
        @unlink($outputPath);
        @unlink($manifestOutputPath);
        _stattic_json_response(503, [
            'error' => [
                'code' => 'finalize_unavailable',
                'message' => 'The native runtime finalizer could not be started.',
            ],
        ]);
    }
    [$exitCode, , $stderr, $timedOut] = $result;
    @unlink($inputPath);
    if ($timedOut) {
        _stattic_runtime_restore_interrupted_version($versionRoot);
        @unlink($outputPath);
        @unlink($manifestOutputPath);
        _stattic_json_response(503, [
            'error' => [
                'code' => 'runtime_finalizer_timeout',
                'message' => 'The native runtime finalizer exceeded its execution deadline.',
            ],
        ]);
    }
    if ($exitCode !== 0) {
        $structuredError = _stattic_runtime_read_finalizer_output($outputPath);
        _stattic_runtime_restore_interrupted_version($versionRoot);
        @unlink($outputPath);
        @unlink($manifestOutputPath);
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
            'version_file_hash_mismatch',
            'version_file_size_mismatch',
            'version_reusable_file_missing',
            'version_existing_mismatch',
        ];
        $status = in_array($rustCode, $conflictCodes, true) ? 409 : 422;
        error_log('spacefast finalizer failed code=' . $rustCode . ' exit=' . $exitCode . ' reason=' . $safeReason);
        $errorDetails = $structuredValid ? $structuredError['details'] : ['exit_code' => $exitCode];
        _stattic_json_response($status, [
            'error' => [
                'code' => $rustCode,
                'message' => $structuredValid ? $structuredError['message'] : 'The native runtime finalizer rejected the version.',
                'details' => _stattic_runtime_json_object($errorDetails),
            ],
        ]);
    }
    $output = _stattic_runtime_read_finalizer_output($outputPath);
    @unlink($outputPath);
    // Envelope validation only: format, identity, and the access artifact
    // container shapes. Rust self-validates every immutable artifact it wrote;
    // re-validating them here would duplicate the authority.
    if (
        !is_array($output)
        || ($output['format'] ?? null) !== 'stattic.runtime.finalize.output.v2'
        || ($output['spaceId'] ?? null) !== $spaceId
        || ($output['versionId'] ?? null) !== $versionId
        || !is_int($output['fileCount'] ?? null)
        || !is_int($output['zeroEndpointCount'] ?? null)
        || !is_array($output['accessRules'] ?? null)
        || !is_array($output['accessSecrets'] ?? null)
    ) {
        _stattic_runtime_restore_interrupted_version($versionRoot);
        @unlink($manifestOutputPath);
        _stattic_json_response(500, [
            'error' => [
                'code' => 'runtime_finalizer_output_invalid',
                'message' => 'The native runtime finalizer returned an invalid result.',
            ],
        ]);
    }
    // Open-session manifests spill to the exact side-car path derived above so
    // the bounded result envelope stays small. Carry that Rust-derived truth
    // through to the control plane; it persists the manifest for additive
    // publish, archive, scanning, and file accounting.
    $sessionMode = _stattic_runtime_upload_session_mode($session);
    if ($sessionMode === 'open') {
        $manifest = ($output['manifestPath'] ?? null) === $manifestOutputPath
            ? _stattic_runtime_read_finalizer_output($manifestOutputPath)
            : null;
        $manifestValid = is_array($manifest);
        foreach ($manifest ?? [] as $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['path'] ?? null)
                || !is_int($entry['size'] ?? null)
                || $entry['size'] < 0
                || !is_string($entry['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $entry['sha256']) !== 1
                || (isset($entry['contentType']) && !is_string($entry['contentType']))
            ) {
                $manifestValid = false;
                break;
            }
        }
        if (!$manifestValid) {
            _stattic_runtime_restore_interrupted_version($versionRoot);
            @unlink($manifestOutputPath);
            _stattic_json_response(500, [
                'error' => [
                    'code' => 'runtime_finalizer_output_invalid',
                    'message' => 'The native runtime finalizer returned an invalid open-session manifest.',
                ],
            ]);
        }
        $output['manifest'] = array_values($manifest);
    }
    @unlink($manifestOutputPath);
    unset($output['manifestPath']);
    return $output;
}

// A `.rust-previous` sibling means a replacement was in flight. Put the
// previous immutable version back and quarantine whatever the failed run left
// behind so serving never sees a half-written tree.
function _stattic_runtime_restore_interrupted_version(string $versionRoot): void
{
    $backup = dirname($versionRoot) . '/.' . basename($versionRoot) . '.rust-previous';
    if (!is_dir($backup)) {
        return;
    }
    $failed = dirname($versionRoot) . '/.' . basename($versionRoot) . '.rust-failed-' . bin2hex(random_bytes(4));
    if (is_dir($versionRoot) && !@rename($versionRoot, $failed)) {
        error_log('spacefast finalizer could not quarantine the failed replacement');
        return;
    }
    if (!@rename($backup, $versionRoot)) {
        if (is_dir($failed)) {
            @rename($failed, $versionRoot);
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
    $json = file_get_contents($path);
    if (!is_string($json)) {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Run the native finalizer without a shell, drain both output pipes
 * concurrently, and enforce a wall-clock ceiling. Output is bounded because
 * only one JSON result is valid.
 *
 * @return array{int,string,string,bool}|null
 */
function _stattic_runtime_run_finalizer_process(array $command, string $cwd, int $timeoutMs): ?array
{
    $pipes = [];
    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return null;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $timedOut = false;
    $exitCode = -1;
    while (true) {
        $read = [];
        if (!feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $read[] = $pipes[2];
        }
        if ($read !== []) {
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 65536);
                if (!is_string($chunk) || $chunk === '') {
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout = substr($stdout . $chunk, 0, 8 * 1024 * 1024);
                } else {
                    $stderr = substr($stderr . $chunk, 0, 64 * 1024);
                }
            }
        }
        // EOF on both child pipes is the portable completion signal. Calling
        // proc_get_status() here reaps the child on some PHP/Linux builds and
        // makes the later proc_close() lose the real exit code.
        if (feof($pipes[1]) && feof($pipes[2])) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            @proc_terminate($process, 9);
            break;
        }
    }
    foreach ([$pipes[1], $pipes[2]] as $pipe) {
        $remaining = stream_get_contents($pipe);
        if (is_string($remaining) && $remaining !== '') {
            if ($pipe === $pipes[1]) {
                $stdout = substr($stdout . $remaining, 0, 8 * 1024 * 1024);
            } else {
                $stderr = substr($stderr . $remaining, 0, 64 * 1024);
            }
        }
        fclose($pipe);
    }
    $closed = _stattic_reap_native_process($process, $timedOut);
    if (is_int($closed)) {
        $exitCode = $closed;
    }
    return [$exitCode, $stdout, $stderr, $timedOut];
}
