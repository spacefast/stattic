<?php
declare(strict_types=1);

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
        $pointer = @file_get_contents($installRoot . '/active-release', false, null, 0, 256);
        $target = is_string($pointer) ? trim($pointer) : '';
        if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1) {
            // One release of tolerance for boxes that received the PHP pointer
            // before the selector returned to an uncached data file.
            $phpPointer = @file_get_contents($installRoot . '/active-release.php', false, null, 0, 256);
            $target = is_string($phpPointer)
                && preg_match("#^<\\?php return '([^']*)';$#", trim($phpPointer), $match) === 1
                ? $match[1]
                : '';
        }
        if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1) {
            // The old installer lands this payload in the legacy tree before
            // refreshing its resident copy. Keep that first pass serving; the
            // control plane immediately runs the new installer a second time.
            $installReal = realpath($installRoot);
            $legacyEngine = realpath($installRoot . '/engine');
            if (
                !is_string($installReal)
                || $legacyEngine !== $installReal . '/engine'
                || !is_file($legacyEngine . '/shared/context.php')
            ) {
                exit(1);
            }
            $releaseRoot = $installReal;
            $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] = $releaseRoot;
        } else {
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
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
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
