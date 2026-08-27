<?php
/**
 * Plugin Name: Spacefast Content Loader
 * Description: Loads the active Spacefast content kernel release.
 * Version: 1
 */
declare(strict_types=1);

(static function (): void {
    $publicRoot = dirname(__DIR__, 2);
    $installRoot = $publicRoot . '/.stattic';
    $pointer = @file_get_contents($installRoot . '/active-release', false, null, 0, 256);
    $target = is_string($pointer) ? trim($pointer) : '';
    if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1) {
        return;
    }

    $installReal = realpath($installRoot);
    $releaseReal = realpath($installRoot . '/' . $target);
    if (
        !is_string($installReal)
        || !is_string($releaseReal)
        || !str_starts_with($releaseReal, $installReal . '/releases/')
    ) {
        return;
    }
    $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] = $releaseReal;

    $nativeProcess = $releaseReal . '/engine/shared/native-process.php';
    if (is_file($nativeProcess)) {
        require_once $nativeProcess;
    }
    $kernel = $releaseReal . '/engine/wordpress/content-kernel.php';
    if (is_file($kernel)) {
        require $kernel;
    }

    $runner = $releaseReal . '/bin/stattic-runtime';
    if (!defined('PAYLOADWP_RUNNER') && is_file($runner) && is_executable($runner)) {
        define('PAYLOADWP_RUNNER', $runner);
    }
    // Compiled content releases are per Space (content-kernel.php
    // spacefast_content_release_root). One wp.cloud site hosts many Spaces, so
    // resolving this box-wide would hand every Space whichever one compiled
    // last. Every entrypoint that boots WordPress sets the Space before
    // requiring wp-load, so it is already here; a request that has none (wp-cron
    // and friends) loads no compiled release at all, which is the safe answer.
    $spaceId = $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null;
    if (
        !is_string($spaceId)
        || strlen($spaceId) > 128
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $spaceId) !== 1
    ) {
        return;
    }
    $contentRoot = $installRoot . '/storage/spaces/' . $spaceId . '/content';
    $contentPointer = @file_get_contents($contentRoot . '/active-release', false, null, 0, 128);
    $contentRevision = is_string($contentPointer) ? trim($contentPointer) : '';
    if (preg_match('/^[a-f0-9]{64}$/', $contentRevision) === 1) {
        $contentRelease = realpath($contentRoot . '/releases/' . $contentRevision);
        $releasesReal = realpath($contentRoot . '/releases');
        if (
            is_string($contentRelease)
            && is_string($releasesReal)
            && str_starts_with($contentRelease, $releasesReal . '/')
        ) {
            $GLOBALS['SPACEFAST_CONTENT_COMPILED_RELEASE_ROOT'] = $contentRelease;
            $compiledPlugin = $contentRelease . '/payloadwp.php';
            if (is_file($compiledPlugin)) {
                require $compiledPlugin;
            }
        }
    }
})();
