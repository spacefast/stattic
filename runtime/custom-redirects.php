<?php
declare(strict_types=1);

// The provider owns the error-log destination; the runtime owns whether PHP
// reports to it. Keep diagnostics out of visitor responses without reducing
// severity or disabling the provider's log pipeline.
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
    // A CLI process with no request to serve is a tool loading WordPress, the
    // purge worker or wp-cli, not a visitor. Classifying it as a request for
    // '/' would serve the space and exit() the tool mid-run; that is how engine
    // purge kicks sat queued forever. Simulated-request drivers (FPM always,
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

    $bindDatabasePassword = static function (): void {
        if (!defined('DB_PASSWORD')) {
            $spacefastDbPassword = getenv('DB_PASSWORD');
            if (is_string($spacefastDbPassword)) {
                define('DB_PASSWORD', $spacefastDbPassword);
                if (PHP_SAPI !== 'cli') {
                    unset($_SERVER['DB_PASSWORD'], $_ENV['DB_PASSWORD']);
                    putenv('DB_PASSWORD');
                }
            }
            unset($spacefastDbPassword);
        }
    };

    $script = basename(__FILE__);
    if ($script === 'custom-redirects.php') {
        // This copy is the provider's auto_prepend for every request, and each
        // entrypoint below runs its own script right after, so the visitor
        // engine must not load in front of them. This is the request's one
        // classification; prepend.php trusts it.
        // BEGIN GENERATED runtime entrypoints — DO NOT EDIT
        // Source: runtime/engine-manifest.json (aliases under __spacefast/).
        // Regenerate: bun run check:runtime-entrypoints -- --write
        $entrypointPaths = [
            '/__spacefast/api.php' => true,
            '/__spacefast/content-admin.php' => true,
            '/__spacefast/content.php' => true,
            '/__spacefast/health.php' => true,
            '/__spacefast/upload.php' => true,
        ];
        // END GENERATED runtime entrypoints
        $path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        $isContentMediaRequest = str_starts_with(
            $path,
            '/__spacefast/content-media/'
        );
        $isFpmReadinessProbe = preg_match(
            '#^/__spacefast/php-fpm-readiness-[a-f0-9]{32}\.php$#',
            $path
        ) === 1;
        $isContentAdminRequest = false;
        $isContentAdminPath = $path === '/wp-admin'
            || str_starts_with($path, '/wp-admin/')
            || $path === '/wp-json'
            || str_starts_with($path, '/wp-json/')
            || ($path === '/' && isset($_GET['rest_route']));
        $isWordPressCorePhp = preg_match(
            '#^/(?:wp-[^/]+\.php|wp-(?:includes|content)/.+\.php)(?:/|$)#',
            $path
        ) === 1;
        $isWordPressCron = $path === '/wp-cron.php' || str_starts_with($path, '/wp-cron.php/');
        $isBlockedWordPressEntrypoint = ($isWordPressCorePhp && !$isWordPressCron)
            || $path === '/xmlrpc.php'
            || str_starts_with($path, '/xmlrpc.php/');
        if ($isBlockedWordPressEntrypoint) {
            http_response_code(404);
            header('Cache-Control: private, no-store', true);
            header('Content-Type: text/plain; charset=UTF-8', true);
            echo 'Not Found';
            exit;
        }
        if (
            $isContentMediaRequest
            && is_file($releaseRoot . '/engine/entrypoints/content-media.php')
        ) {
            require $releaseRoot . '/engine/entrypoints/content-media.php';
            exit;
        }
        if (
            !isset($entrypointPaths[$path])
            && !$isFpmReadinessProbe
            && $isContentAdminPath
            && is_file($releaseRoot . '/engine/shared/content-admin.php')
        ) {
            require_once $releaseRoot . '/engine/shared/bootstrap-config.php';
            require_once $releaseRoot . '/engine/shared/context.php';
            require_once $releaseRoot . '/engine/shared/storage.php';
            require_once $releaseRoot . '/engine/shared/content-admin.php';
            $isContentAdminRequest = true;
            $dashboardOrigin = _stattic_dashboard_origin();
            header(
                "Content-Security-Policy: frame-ancestors 'self'"
                    . ($dashboardOrigin === '' ? '' : ' ' . $dashboardOrigin),
                true
            );
            header('Cache-Control: private, no-store', true);
            $host = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $cookieName = _stattic_content_admin_cookie_name();
            $token = is_string($_COOKIE[$cookieName] ?? null)
                ? $_COOKIE[$cookieName]
                : '';
            $session = _stattic_content_admin_verify_session(
                $installRoot . '/storage',
                $token,
                $host
            );
            if ($session === null) {
                _stattic_problem_response(
                    401,
                    'content_admin_session_invalid',
                    'The content editor session is invalid or expired.'
                );
            }
            $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = $session['user_id'];
            $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $session['space_id'];
            $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $installRoot . '/storage';
            $GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] = $session['frame_origin'];
            if (!defined('DISALLOW_FILE_EDIT')) {
                define('DISALLOW_FILE_EDIT', true);
            }
            if (!defined('DISALLOW_FILE_MODS')) {
                define('DISALLOW_FILE_MODS', true);
            }
            if (!defined('AUTOMATIC_UPDATER_DISABLED')) {
                define('AUTOMATIC_UPDATER_DISABLED', true);
            }
            if (!defined('WP_AUTO_UPDATE_CORE')) {
                define('WP_AUTO_UPDATE_CORE', false);
            }
            $bindDatabasePassword();
        }
        if (
            !isset($entrypointPaths[$path])
            && !$isFpmReadinessProbe
            && !$isContentAdminRequest
            && is_file($releaseRoot . '/engine/shared/context.php')
        ) {
            // The visitor lane. DB_PASSWORD moves from the process environment
            // into a constant before any engine code runs, so tenant-adjacent
            // code paths never see it in $_SERVER/$_ENV/getenv.
            $bindDatabasePassword();
            require $releaseRoot . '/engine/init.php';
        }
        return;
    }

    if (in_array($script, ['content-admin.php', 'content.php'], true)) {
        $bindDatabasePassword();
    }
    $entrypoint = match ($script) {
        'api.php' => 'entrypoints/management.php',
        'content-admin.php' => 'entrypoints/content-admin.php',
        'content.php' => 'entrypoints/content.php',
        'health.php' => 'entrypoints/health.php',
        'upload.php' => 'entrypoints/upload.php',
        default => 'init.php',
    };
    require $releaseRoot . '/engine/' . $entrypoint;
})();
