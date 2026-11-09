<?php
declare(strict_types=1);

// The WordPress half of the engine: what the thin loader, installed as the
// mu-plugin wp-content/mu-plugins/spacefast-content.php, hands to from
// whichever engine it chose. A served request arrives with the engine the
// prepend already chose; a CLI or cron load chose it in the mu-plugin the same
// way. $loader is the hand-off described in engine/loader/request.php.

return static function (array $loader): void {
    $installRoot = $loader['public_root'] . '/.stattic';
    $releaseReal = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    if (!is_string($releaseReal)) {
        $releaseReal = $loader['engine_root'];
        $GLOBALS['SPACEFAST_RUNTIME_NATIVE_BINARY'] = $loader['native_binary'];
        $GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE'] = $loader['engine_source'];
        $GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE_REASON'] = $loader['engine_source_reason'];
        $GLOBALS['SPACEFAST_RUNTIME_CHROOT_REVISION'] = $loader['chroot_revision'];
        $GLOBALS['SPACEFAST_RUNTIME_LOADER_VERSION'] = $loader['loader_version'];
        $GLOBALS['SPACEFAST_RUNTIME_INSTALL_ROOT'] = $loader['install_root'];
        $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] = $releaseReal;
    }

    $wordpressRest = $releaseReal . '/engine/shared/wordpress-rest.php';
    if (is_file($wordpressRest)) {
        require_once $wordpressRest;
        if (function_exists('_stattic_wordpress_rest_install_hooks')) {
            _stattic_wordpress_rest_install_hooks();
        }
    }

    $nativeProcess = $releaseReal . '/engine/shared/native-process.php';
    if (is_file($nativeProcess)) {
        require_once $nativeProcess;
    }
    $zeroHost = $releaseReal . '/engine/shared/zero-v2.php';
    if (is_file($zeroHost)) require_once $zeroHost;
    if (isset($GLOBALS['SPACEFAST_ZERO_V2_ROOT'])) {
        require_once $releaseReal . '/engine/shared/zero-v2-scope.php';
        _stattic_zero_v2_bind_wordpress();
        add_action('plugins_loaded', '_stattic_zero_v2_mount', 1);
        // Space Users on v2 is the version's zero-identity on the Space's tables;
        // the mount loads it, then installs the same host hooks.
        require_once $releaseReal . '/engine/wordpress/space-users.php';
        // Only the content entrypoint's Users management lane acts for a
        // dashboard principal; no other v2 request projects a Space role.
        if (($GLOBALS['SPACEFAST_ZERO_V2_USERS_MANAGEMENT'] ?? false) === true) {
            require_once $releaseReal . '/engine/shared/wordpress-scope.php';
            require_once $releaseReal . '/engine/wordpress/content-principals.php';
            require_once $releaseReal . '/engine/wordpress/content-users.php';
        }
        return;
    }
    $kernel = $releaseReal . '/engine/wordpress/content-kernel.php';
    if (is_file($kernel)) {
        require $kernel;
    }

    // ContentModelReleases are per Space. One wp.cloud site hosts many Spaces, so a
    // request without an exact Space scope (wp-cron and similar) loads no
    // content model at all. The release is data: the kernel projects it into native
    // WordPress. No generated plugin is executed here. The kernel owns the scope
    // check, and without a kernel nothing below has a reader.
    $spaceId = function_exists('spacefast_content_space_id') ? spacefast_content_space_id() : '';
    if ($spaceId === '') {
        return;
    }
    // One guard, not two: the hook installer ships with the content kernel
    // (wordpress/space-users.php), so an identity tree without a loaded kernel
    // must load neither. Two independent checks fataled mu-plugin loading on
    // `spacefast_space_users_install_hooks()` for that release shape.
    $identity = $releaseReal . '/wordpress/spacefast-identity/spacefast-identity.php';
    if (is_file($identity) && function_exists('spacefast_space_users_install_hooks')) {
        spacefast_space_users_install_hooks();
        require_once $identity;
    }
    $contentModelRoot = $installRoot . '/storage/spaces/' . $spaceId . '/content-model';
    $pinnedRevision = $GLOBALS['SPACEFAST_CONTENT_PINNED_MODEL_REVISION'] ?? null;
    $contentModelPointer = is_string($pinnedRevision)
        ? $pinnedRevision
        : (is_file($contentModelRoot . '/active-release')
            ? file_get_contents($contentModelRoot . '/active-release', false, null, 0, 96)
            : false);
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

    // The provider may run its own WordPress front controller after auto_prepend.
    // Resume the admitted canonical route once core and plugins have fully loaded.
    if (is_array($GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST'] ?? null)
        && function_exists('_stattic_wordpress_page_resume_deferred_request')) {
        add_action('wp_loaded', '_stattic_wordpress_page_resume_deferred_request', PHP_INT_MAX);
    }
};
