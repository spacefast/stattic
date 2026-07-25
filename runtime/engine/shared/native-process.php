<?php
declare(strict_types=1);

// Process plumbing for the native Rust runtime compiler
// (`stattic-runtime-compiler`): binary resolution and child reaping. Callers
// own the fail-closed policy — an unavailable binary is never a signal to run
// a PHP fallback.

/**
 * Resolve the one native runtime-compiler executable. Exactly two sources:
 * the installed immutable release root wins, then the explicit binary
 * override (local development and behavioral tests), then the binary shipped
 * beside this engine. Callers still fail closed when it is unavailable.
 */
function _stattic_runtime_native_compiler_binary(): string
{
    $releaseRoot = getenv('SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT');
    if (is_string($releaseRoot) && trim($releaseRoot) !== '') {
        return rtrim(trim($releaseRoot), '/') . '/bin/stattic-runtime-compiler';
    }
    $configured = getenv('SPACEFAST_RUNTIME_FINALIZER_BIN');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }
    return dirname(__DIR__, 2) . '/bin/stattic-runtime-compiler';
}

/**
 * Close a completed native child, or kill and reap a timed-out child without
 * entering an unbounded proc_close wait while it is still running.
 */
function _stattic_reap_native_process($process, bool $terminate): int
{
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
