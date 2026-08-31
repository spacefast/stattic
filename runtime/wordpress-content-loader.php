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

    // ContentModelReleases are per Space. One wp.cloud site hosts many Spaces, so a
    // request without an exact Space scope (wp-cron and similar) loads no
    // content model at all. The release is data: the kernel projects it into native
    // WordPress. No generated plugin is executed here.
    $spaceId = $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null;
    if (
        !is_string($spaceId)
        || strlen($spaceId) > 128
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $spaceId) !== 1
    ) {
        return;
    }
    $contentModelRoot = $installRoot . '/storage/spaces/' . $spaceId . '/content-model';
    $contentModelPointer = @file_get_contents($contentModelRoot . '/active-release', false, null, 0, 96);
    $contentModelRevision = is_string($contentModelPointer) ? trim($contentModelPointer) : '';
    if (preg_match('/^sha256:[a-f0-9]{64}$/', $contentModelRevision) === 1) {
        $directory = substr($contentModelRevision, strlen('sha256:'));
        $contentModelRelease = realpath($contentModelRoot . '/releases/' . $directory);
        $releasesReal = realpath($contentModelRoot . '/releases');
        if (
            is_string($contentModelRelease)
            && is_string($releasesReal)
            && str_starts_with($contentModelRelease, $releasesReal . '/')
        ) {
            $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $contentModelRelease;
            $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $contentModelRevision;
        }
    }
})();
