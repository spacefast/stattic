<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';

// An unavailable native binary is never a signal to run a PHP fallback: callers
// fail closed.

function _stattic_runtime_native_binary(): string
{
    $releaseRoot = _stattic_config_value('SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT');
    if ($releaseRoot !== '') {
        return rtrim($releaseRoot, '/') . '/bin/stattic-runtime';
    }
    $configured = _stattic_config_value('SPACEFAST_RUNTIME_BIN');
    if ($configured !== '') {
        return $configured;
    }
    return dirname(__DIR__, 2) . '/bin/stattic-runtime';
}

// The CLI php for subprocess work. Under FPM, PHP_BINARY is the fpm binary and
// a bare 'php' never resolves: proc_open's array form execs directly (no shell,
// and with an env array no PATH search at all), so the command must be an
// absolute path. The CLI binary ships beside this build in PHP_BINDIR.
function _stattic_runtime_php_cli_binary(): string
{
    $configured = _stattic_config_value('SPACEFAST_RUNTIME_PHP_CLI_BIN');
    if ($configured !== '') {
        return $configured;
    }
    if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
        return PHP_BINARY;
    }
    $sibling = PHP_BINDIR . '/php';
    return is_file($sibling) ? $sibling : 'php';
}

// Starts a child whose lifetime is independent of the request. All standard
// streams are bound to /dev/null before proc_open, so PHP can release its
// process resource immediately without retaining a pipe that keeps the FPM
// request open. The child owns its own durable input/output contract.
function _stattic_runtime_start_detached_subprocess(
    array $cmd,
    ?array $env = null,
    ?string $cwd = null
): bool {
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'a'],
        2 => ['file', '/dev/null', 'a'],
    ];
    $process = @proc_open($cmd, $descriptors, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return false;
    }
    // Deliberately do not proc_close(): it waits for the child and would put
    // the provider purge back on the request's critical path.
    unset($process);
    return true;
}

// All three pipes must be pumped together through stream_select: a blocking
// fwrite stalls forever once stdin exceeds the ~64KB pipe buffer, and draining
// stdout to EOF first stalls forever once the child fills stderr. Both are
// reachable here.
//
// spawned === false means proc_open itself failed (exitCode is meaningless).
// Null $timeoutMs / $stdoutMaxBytes / $stderrMaxBytes means unbounded.
//
// @return array{spawned:bool,exitCode:int,stdout:string,stderr:string,timedOut:bool}
function _stattic_runtime_run_subprocess(
    array $cmd,
    ?array $env,
    ?string $stdin = null,
    ?string $cwd = null,
    ?int $timeoutMs = null,
    ?int $stdoutMaxBytes = null,
    ?int $stderrMaxBytes = null
): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($cmd, $descriptors, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['spawned' => false, 'exitCode' => -1, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }

    $input = $stdin ?? '';
    $inputLength = strlen($input);
    $written = 0;
    $stdinPipe = $pipes[0];
    if ($inputLength === 0) {
        fclose($stdinPipe);
        $stdinPipe = null;
    } else {
        stream_set_blocking($stdinPipe, false);
    }

    $outputPipes = [1 => $pipes[1], 2 => $pipes[2]];
    stream_set_blocking($outputPipes[1], false);
    stream_set_blocking($outputPipes[2], false);
    $buffers = [1 => '', 2 => ''];
    $deadline = $timeoutMs === null ? null : microtime(true) + ($timeoutMs / 1000);
    $timedOut = false;

    // Watch the pipes for EOF rather than polling proc_get_status(): that call
    // reaps the child on some PHP/Linux builds, and proc_close() then loses the
    // real exit code.
    while ($stdinPipe !== null || $outputPipes !== []) {
        $read = array_values($outputPipes);
        $write = $stdinPipe !== null ? [$stdinPipe] : [];
        $except = null;
        if (@stream_select($read, $write, $except, 0, 200000) === false) {
            break;
        }

        if ($write !== [] && $stdinPipe !== null) {
            $chunk = substr($input, $written, 65536);
            $sent = @fwrite($stdinPipe, $chunk);
            if ($sent === false) {
                fclose($stdinPipe);
                $stdinPipe = null;
            } else {
                $written += $sent;
                if ($written >= $inputLength) {
                    fclose($stdinPipe);
                    $stdinPipe = null;
                }
            }
        }

        foreach ($read as $ready) {
            $index = array_search($ready, $outputPipes, true);
            if ($index === false) {
                continue;
            }
            $chunk = fread($ready, 65536);
            if (is_string($chunk) && $chunk !== '') {
                $maxBytes = $index === 1 ? $stdoutMaxBytes : $stderrMaxBytes;
                // Chunks after a capped buffer fills are dropped outright:
                // re-truncating would copy the whole cap per 64KB read.
                if ($maxBytes === null) {
                    $buffers[$index] .= $chunk;
                } elseif (strlen($buffers[$index]) < $maxBytes) {
                    $buffers[$index] = substr($buffers[$index] . $chunk, 0, $maxBytes);
                }
                continue;
            }
            if (feof($ready)) {
                fclose($ready);
                unset($outputPipes[$index]);
            }
        }

        // While output is still open, a passed deadline is a timeout here (a
        // child holding its pipes open past the bound). A child that closes both
        // pipes without exiting drops out of this loop instead and is bounded by
        // the reap below, which enforces the same deadline independent of pipes.
        if ($deadline !== null && $outputPipes !== [] && microtime(true) >= $deadline) {
            $timedOut = true;
            break;
        }
    }

    foreach ($outputPipes as $index => $pipe) {
        $remaining = stream_get_contents($pipe);
        if (is_string($remaining) && $remaining !== '') {
            $maxBytes = $index === 1 ? $stdoutMaxBytes : $stderrMaxBytes;
            if ($maxBytes === null) {
                $buffers[$index] .= $remaining;
            } elseif (strlen($buffers[$index]) < $maxBytes) {
                $buffers[$index] = substr($buffers[$index] . $remaining, 0, $maxBytes);
            }
        }
        fclose($pipe);
    }
    if ($stdinPipe !== null) {
        fclose($stdinPipe);
    }

    // Reap enforces the deadline independent of pipe state and may itself flip
    // $timedOut (a child that closed its pipes but kept running), so resolve the
    // exit code before reading the flag into the result.
    $exitCode = _stattic_reap_native_process($process, $timedOut, $deadline);

    return [
        'spawned' => true,
        'exitCode' => $exitCode,
        'stdout' => $buffers[1],
        'stderr' => $buffers[2],
        'timedOut' => $timedOut,
    ];
}

// A timed-out child is killed and polled: proc_close on a still-running child
// waits unbounded. When a deadline is set, the wait for exit is bounded here
// too — a child that closed stdout/stderr without exiting leaves the pipe loop
// with $terminate false, and a bare proc_close on it would block forever while
// the caller holds the space write lock. $terminate is by-reference so a bound
// hit escalates it to a timeout for the caller's result. A null deadline is the
// unbounded lane and keeps the original bare proc_close.
function _stattic_reap_native_process($process, bool &$terminate, ?float $deadline = null): int
{
    if (!$terminate && $deadline !== null) {
        // proc_get_status reaps the child on some builds, so keep its exitcode
        // and prefer it when proc_close then reports -1.
        $polledExit = -1;
        while (true) {
            $status = proc_get_status($process);
            if (!is_array($status)) {
                return proc_close($process);
            }
            if (!($status['running'] ?? false)) {
                if (is_int($status['exitcode'] ?? null) && $status['exitcode'] >= 0) {
                    $polledExit = $status['exitcode'];
                }
                $closedCode = proc_close($process);
                return $closedCode >= 0 ? $closedCode : $polledExit;
            }
            if (microtime(true) >= $deadline) {
                $terminate = true;
                break;
            }
            usleep(10000);
        }
    }

    if (!$terminate) {
        return proc_close($process);
    }

    @proc_terminate($process, 9);
    $deadline = microtime(true) + 0.5;
    $exitCode = -1;
    do {
        $status = proc_get_status($process);
        if (!is_array($status)) {
            return $exitCode;
        }
        if (!($status['running'] ?? false)) {
            if (is_int($status['exitcode'] ?? null)) {
                $exitCode = $status['exitcode'];
            }
            $closedCode = proc_close($process);
            return $closedCode >= 0 ? $closedCode : $exitCode;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    $closedCode = proc_close($process);
    return $closedCode >= 0 ? $closedCode : $exitCode;
}
