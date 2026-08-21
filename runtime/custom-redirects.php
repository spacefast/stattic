<?php
declare(strict_types=1);

// The provider owns the error-log destination. The runtime owns whether PHP
// reports to it. Keep diagnostics out of visitor responses, but never reduce
// the reported severity set or disable the provider's log pipeline.
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
    error_log('Spacefast runtime requires PHP 8.5; running PHP ' . PHP_VERSION);
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    exit(1);
}

(static function (): void {
    // A CLI process with no request to serve is a tool loading WordPress — the
    // purge worker, wp-cli — not a visitor. Classifying it as a request for '/'
    // would serve the space and exit() the tool mid-run (which is how engine
    // purge kicks sat queued forever). Simulated-request drivers (FPM always,
    // the test harness by contract) carry REQUEST_METHOD and still classify.
    if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
        return;
    }
    $publicRoot = basename(__DIR__) === '__spacefast' ? dirname(__DIR__) : __DIR__;
    $installRoot = $publicRoot . '/.stattic';
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    if (!is_string($releaseRoot)) {
        $pointerPath = $installRoot . '/active-release';
        $pointer = is_file($pointerPath)
            ? file_get_contents($pointerPath, false, null, 0, 256)
            : false;
        $target = is_string($pointer) ? trim($pointer) : '';
        if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1) {
            exit(1);
        }
        $installReal = realpath($installRoot);
        $releaseReal = realpath($installRoot . '/' . $target);
        if (
            !is_string($installReal)
            || !is_string($releaseReal)
            || !str_starts_with($releaseReal, $installReal . '/releases/')
        ) {
            exit(1);
        }
        $releaseRoot = $releaseReal;
        $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] = $releaseRoot;
    }

    $script = basename(__FILE__);
    if ($script === 'custom-redirects.php') {
        // This copy is the provider's auto_prepend for every request, and each
        // entrypoint below runs its own script right after — so the visitor
        // engine must not be loaded in front of them. This is the request's one
        // classification: prepend.php trusts the decision made here.
        // BEGIN GENERATED runtime entrypoints — DO NOT EDIT
        // Source: runtime/engine-manifest.json (aliases under __spacefast/).
        // Regenerate: bun run check:runtime-entrypoints -- --write
        $entrypointPaths = [
            '/__spacefast/api.php' => true,
            '/__spacefast/health.php' => true,
            '/__spacefast/upload.php' => true,
        ];
        // END GENERATED runtime entrypoints
        $path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        if (!isset($entrypointPaths[$path])) {
            require $releaseRoot . '/engine/entrypoints/prepend.php';
        }
        return;
    }

    $entrypoint = match ($script) {
        'api.php' => 'entrypoints/management.php',
        'health.php' => 'entrypoints/health.php',
        'upload.php' => 'entrypoints/upload.php',
        default => 'init.php',
    };
    require $releaseRoot . '/engine/' . $entrypoint;
})();
