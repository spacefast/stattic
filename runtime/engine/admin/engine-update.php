<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/native-process.php';

// POST /engine/update — the one runtime self-update path. The control plane
// sends the target revision plus the artifact URL and checksums over the
// authenticated management lane; this route runs the resident installer
// synchronously and relays its receipt. Bytes come from the URL, trust comes
// from the JWT-carried checksums. The installer's own single-flight lock
// serializes overlapping converges.

// The control plane's client waits 330s; finishing under that keeps a slow
// converge distinguishable from a dead one.
const STATTIC_ENGINE_UPDATE_TIMEOUT_SECONDS = 300;

function _stattic_engine_release_layout_active(string $privateRoot): bool
{
    $installRoot = realpath(dirname($privateRoot));
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    $releaseReal = is_string($releaseRoot) ? realpath($releaseRoot) : false;
    return is_string($installRoot)
        && is_string($releaseReal)
        && str_starts_with($releaseReal, $installRoot . '/releases/');
}

function _stattic_engine_update_route(string $privateRoot, array $_claims): void
{
    $body = _stattic_json_body();
    $revision = $body['revision'] ?? null;
    $zipUrl = $body['zip_url'] ?? null;
    $md5 = $body['md5'] ?? null;
    $nativeSha256 = $body['native_sha256'] ?? null;
    if (
        !is_string($revision) || trim($revision) === '' || strlen($revision) > 191
        || !is_string($zipUrl) || !str_starts_with($zipUrl, 'https://')
        || !is_string($md5) || preg_match('/^[a-fA-F0-9]{32}$/', $md5) !== 1
        || ($nativeSha256 !== null && (!is_string($nativeSha256) || preg_match('/^[a-fA-F0-9]{64}$/', $nativeSha256) !== 1))
    ) {
        _stattic_problem_response(422, 'runtime_engine_update_invalid', 'revision, an https zip_url and md5 are required.');
    }
    $revision = trim($revision);

    if ($revision === SPACEFAST_RUNTIME_ENGINE_REVISION && _stattic_engine_release_layout_active($privateRoot)) {
        _stattic_json_response(200, [
            'status' => 'converged',
            'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
            'layout' => 'release',
        ]);
    }

    // $privateRoot is <publicRoot>/.stattic/storage on every deployed box.
    $installer = dirname($privateRoot, 2) . '/__spacefast/engine-update.php';
    if (!is_file($installer)) {
        _stattic_problem_response(503, 'runtime_engine_installer_missing', 'The resident installer is not present on this site.');
    }

    $env = [
        'PATH' => is_string(getenv('PATH')) && getenv('PATH') !== '' ? getenv('PATH') : '/usr/local/bin:/usr/bin:/bin',
        'SPACEFAST_RUNTIME_ENGINE_MD5' => strtolower($md5),
        'SPACEFAST_RUNTIME_ENGINE_REVISION' => $revision,
    ];
    if (is_string($nativeSha256)) {
        $env['SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256'] = strtolower($nativeSha256);
    }
    // `-d auto_prepend_file=` strips the provider bootstrap, same as the SSH
    // bootstrap invocation.
    $command = [_stattic_runtime_php_cli_binary(), '-d', 'auto_prepend_file=', '-d', 'opcache.enable_cli=0', $installer, $zipUrl];
    $run = _stattic_runtime_run_subprocess($command, $env, null, dirname($installer, 2), STATTIC_ENGINE_UPDATE_TIMEOUT_SECONDS * 1000, 262144, 262144);

    if ($run['timedOut']) {
        // 424, not 502: the control-plane client blind-retries gateway-class
        // statuses, and a deterministic installer failure must not be re-run
        // three times on its dime.
        _stattic_problem_response(424, 'runtime_engine_update_timeout', 'The installer did not finish in time.');
    }
    $receipt = json_decode($run['stdout'], true);
    if ($run['exitCode'] !== 0 || !is_array($receipt) || !is_string($receipt['status'] ?? null)) {
        // fail() puts the machine-readable cause on stderr; surface it so the
        // control plane can classify without shelling into the box.
        $stderr = trim($run['stderr']);
        $error = $stderr !== ''
            ? strtok($stderr, "\n")
            : ($run['spawned'] ? 'runtime_engine_update_failed' : 'runtime_engine_update_spawn_failed');
        _stattic_problem_response(424, 'runtime_engine_update_failed', 'The installer did not converge.', [
            'details' => ['error' => substr($error, 0, 512), 'exit_code' => $run['exitCode']],
        ]);
    }
    if ($receipt['status'] === 'busy') {
        _stattic_problem_response(409, 'runtime_engine_update_busy', 'Another engine converge is already running.');
    }
    _stattic_json_response(200, $receipt);
}
