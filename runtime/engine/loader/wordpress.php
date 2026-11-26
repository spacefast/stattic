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

    // Core's own cron call can only reach the stock /wp-cron.php, which no box
    // serves, and it takes the site's cron lock first. A SpacePress binding that
    // can sign the call turns it back on (shared/spacepress.php).
    if (function_exists('remove_action')) {
        remove_action('init', 'wp_cron');
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
    // 1. SpacePress core lane (1 Space = 1 Atomic instance, native $table_prefix).
    // Loads zero files from `engine/deprecated/`.
    if (isset($GLOBALS['SPACEFAST_SPACEPRESS_ROOT'])) {
        require_once $releaseReal . '/engine/shared/spacepress.php';
        _stattic_spacepress_bind_wordpress();
        // Install the Spacefast must-use plugin before the app mounts so its
        // hooks come before any of the app's own: the sign-in provider, the
        // lent Space role, the capability ceiling, and credential refusals.
        require_once $releaseReal . '/engine/wordpress/typedpress.php';
        spacefast_tp_install();
        _stattic_spacepress_mount();
        if (is_array($GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST'] ?? null)) {
            require_once $releaseReal . '/engine/shared/wordpress-scope.php';
            if (function_exists('add_action') && function_exists('_stattic_wordpress_page_resume_deferred_request')) {
                add_action('wp_loaded', '_stattic_wordpress_page_resume_deferred_request', PHP_INT_MAX);
            }
        }
        return;
    }

    // 2. Legacy Zero v2 lane (quarantined in `engine/deprecated/zero-v2/`).
    if (isset($GLOBALS['SPACEFAST_ZERO_V2_ROOT'])) {
        require_once $releaseReal . '/engine/deprecated/zero-v2/zero-v2.php';
        require_once $releaseReal . '/engine/deprecated/zero-v2/zero-v2-scope.php';
        _stattic_zero_v2_bind_wordpress();
        add_action('plugins_loaded', '_stattic_zero_v2_mount', 1);
        // Space Users on v2 is the version's zero-identity on the Space's tables;
        // the mount loads it, then installs the same host hooks.
        require_once $releaseReal . '/engine/wordpress/space-users.php';
        // Only the content entrypoint's Users management lane acts for a
        // dashboard principal on a v2 build.
        if (($GLOBALS['SPACEFAST_ZERO_V2_USERS_MANAGEMENT'] ?? false) === true) {
            require_once $releaseReal . '/engine/shared/wordpress-scope.php';
            require_once $releaseReal . '/engine/deprecated/content/content-principals.php';
            require_once $releaseReal . '/engine/deprecated/content/content-users.php';
        }
        return;
    }

    // 3. Legacy `content.managed` lane (quarantined in `engine/deprecated/content/`).
    // Only loaded when the request carries an explicit Space scope with an active
    // legacy content model or legacy content/Space-Users entry markers.
    $rawSpaceId = $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null;
    $spaceId = is_string($rawSpaceId)
        && strlen($rawSpaceId) >= 1
        && strlen($rawSpaceId) <= 128
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $rawSpaceId) === 1
        ? $rawSpaceId
        : '';

    $contentModelRoot = $spaceId !== '' ? $installRoot . '/storage/spaces/' . $spaceId . '/content-model' : '';
    $pinnedRevision = $GLOBALS['SPACEFAST_CONTENT_PINNED_MODEL_REVISION'] ?? null;
    $hasLegacyContent = $spaceId !== '' && (
        is_string($pinnedRevision)
        || is_file($contentModelRoot . '/active-release')
        || isset($GLOBALS['SPACEFAST_CONTENT_ADMIN_ACCESS'])
        || isset($GLOBALS['SPACEFAST_CONTENT_SYSTEM_OPERATION'])
        || !empty($GLOBALS['SPACEFAST_CONTENT_PUBLIC_PAGE_REQUEST'])
        || array_key_exists('SPACEFAST_SPACE_USERS_PROVIDER_CONFIGURATION', $GLOBALS)
        || (($GLOBALS['SPACEFAST_PAGE_SERVING']['users']['enabled'] ?? false) === true)
        || is_file($installRoot . '/storage/spaces/' . $spaceId . '/wordpress-routes.json')
        || is_file($installRoot . '/storage/spaces/' . $spaceId . '/content-redirects.json')
    );

    if ($hasLegacyContent) {
        $kernel = $releaseReal . '/engine/deprecated/content/content-kernel.php';
        if (!is_file($kernel)) {
            $kernel = $releaseReal . '/engine/wordpress/content-kernel.php';
        }
        if (is_file($kernel)) {
            require $kernel;
        }
        $identity = $releaseReal . '/wordpress/spacefast-identity/spacefast-identity.php';
        if (is_file($identity) && function_exists('spacefast_space_users_install_hooks')) {
            spacefast_space_users_install_hooks();
            require_once $identity;
        }
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
    }

    // The provider may run its own WordPress front controller after auto_prepend.
    // Resume the admitted canonical route once core and plugins have fully loaded.
    if (is_array($GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST'] ?? null)) {
        require_once $releaseReal . '/engine/shared/wordpress-scope.php';
        if (function_exists('add_action') && function_exists('_stattic_wordpress_page_resume_deferred_request')) {
            add_action('wp_loaded', '_stattic_wordpress_page_resume_deferred_request', PHP_INT_MAX);
        }
    }
};
