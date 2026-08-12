<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/response.php';

// POST /engine/update — the one runtime self-update path. The control plane
// sends the target revision plus the artifact URL and checksums over the
// authenticated management lane; this route runs the resident installer
// synchronously and relays its receipt. Bytes come from the URL, trust comes
// from the JWT-carried checksums. The installer's own single-flight lock
// serializes overlapping converges.

// The control plane's client waits 330s; finishing under that keeps a slow
// converge distinguishable from a dead one.
const STATTIC_ENGINE_UPDATE_TIMEOUT_SECONDS = 300;

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

    // The running module knows its own identity: a converged box answers
    // without spawning anything.
    if ($revision === SPACEFAST_RUNTIME_ENGINE_REVISION) {
        _stattic_json_response(200, ['status' => 'converged', 'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION]);
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
    $result = _stattic_engine_update_exec($installer, $zipUrl, $env);

    if ($result['timed_out']) {
        // 424, not 502: the control-plane client blind-retries gateway-class
        // statuses, and a deterministic installer failure must not be re-run
        // three times on its dime.
        _stattic_problem_response(424, 'runtime_engine_update_timeout', 'The installer did not finish in time.');
    }
    $receipt = json_decode($result['stdout'], true);
    if ($result['code'] !== 0 || !is_array($receipt) || !is_string($receipt['status'] ?? null)) {
        // fail() puts the machine-readable cause on stderr; surface it so the
        // control plane can classify without shelling into the box.
        $error = trim($result['stderr']) !== '' ? strtok(trim($result['stderr']), "\n") : 'runtime_engine_update_failed';
        _stattic_problem_response(424, 'runtime_engine_update_failed', 'The installer did not converge.', [
            'details' => ['error' => substr($error, 0, 512), 'exit_code' => $result['code']],
        ]);
    }
    if ($receipt['status'] === 'busy') {
        _stattic_problem_response(409, 'runtime_engine_update_busy', 'Another engine converge is already running.');
    }
    _stattic_json_response(200, $receipt);
}

/**
 * @param array<string,string> $env
 * @return array{code:int,stdout:string,stderr:string,timed_out:bool}
 */
function _stattic_engine_update_exec(string $installer, string $zipUrl, array $env): array
{
    // Under php-fpm PHP_BINARY is the fpm binary; the CLI lives on PATH there.
    $php = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : 'php';
    // `-d auto_prepend_file=` strips the provider bootstrap, same as the SSH
    // bootstrap invocation.
    $command = [$php, '-d', 'auto_prepend_file=', '-d', 'opcache.enable_cli=0', $installer, $zipUrl];
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($installer, 2), $env, ['bypass_shell' => true]);
    if (!is_resource($process) || count($pipes) !== 3) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'runtime_engine_update_spawn_failed', 'timed_out' => false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $exitCode = -1;
    $deadline = microtime(true) + STATTIC_ENGINE_UPDATE_TIMEOUT_SECONDS;
    do {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (strlen($stdout) > 262144 || strlen($stderr) > 262144) {
            $timedOut = true;
            @proc_terminate($process, 9);
            break;
        }
        $status = proc_get_status($process);
        if (!is_array($status)) {
            break;
        }
        if (!($status['running'] ?? false)) {
            if (is_int($status['exitcode'] ?? null)) {
                $exitCode = $status['exitcode'];
            }
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            @proc_terminate($process, 9);
            break;
        }
        usleep(50000);
    } while (true);
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($closedCode >= 0 && $exitCode === -1) {
        $exitCode = $closedCode;
    }
    return ['code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
}
