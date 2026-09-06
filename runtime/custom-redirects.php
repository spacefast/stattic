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
    $publicationUnavailable = static function (): never {
        http_response_code(503);
        header('Retry-After: 1', true);
        header('Content-Type: application/problem+json', true);
        echo json_encode([
            'type' => 'https://spacefast.com/problems/runtime_engine_update_busy',
            'title' => 'Runtime engine update busy',
            'status' => 503,
            'code' => 'runtime_engine_update_busy',
        ]);
        exit;
    };
    $publicationLockPath = $installRoot . '/publication.lock';
    if (is_link($installRoot) || is_link($publicationLockPath)) {
        $publicationUnavailable();
    }
    $publicationLock = fopen($publicationLockPath, 'ce');
    if (!is_resource($publicationLock) || !flock($publicationLock, LOCK_SH | LOCK_NB)) {
        $publicationUnavailable();
    }
    $GLOBALS['SPACEFAST_RUNTIME_PUBLICATION_LOCK'] = $publicationLock;
    $installTransaction = $installRoot . '/install-transaction.json';
    if (file_exists($installTransaction) || is_link($installTransaction)) {
        $publicationUnavailable();
    }
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
    // Which pass this is, for the one lane that cannot run inside the
    // provider's auto_prepend: WordPress. /scripts/env.php requires THIS copy
    // before it defines DB_NAME/DB_USER/DB_HOST, WP_CONTENT_DIR,
    // WP_CACHE_KEY_SALT and the rest of the WordPress environment, so a boot
    // here reaches wpdb with an empty database tuple, dies in dead_db(), and
    // the provider's own header callback reports 502. The provider always runs
    // a main script after this pass. The engine's front controller resumes the
    // deferred route directly; the provider's WordPress controller resumes it
    // through the content loader at wp_loaded.
    $GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'] = $script === 'custom-redirects.php'
        && is_file($publicRoot . '/index.php');
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
        // Which requests ARE the editor lane is decided by
        // _stattic_content_admin_request_path and asked, never restated here:
        // this file carried its own copy, and a gate whose request set
        // disagrees with the shared one is a gate with a hole on whichever
        // side is narrower. shared/content-admin.php is dependency-free
        // function declarations that read no environment, so requiring it to
        // ask still leaves $bindDatabasePassword() the first thing any engine
        // code that could observe DB_PASSWORD sees.
        $isContentAdminPath = false;
        $restFrontController = null;
        if (
            !isset($entrypointPaths[$path])
            && !$isFpmReadinessProbe
            && is_file($releaseRoot . '/engine/shared/content-admin.php')
        ) {
            require_once $releaseRoot . '/engine/shared/content-admin.php';
            $isContentAdminPath = _stattic_content_admin_request_path(
                $path,
                is_array($_GET) ? $_GET : []
            );
            // WordPress routes /wp-json through the document root's index.php,
            // and this engine installs its own copy of THIS file over that
            // (engine-manifest.json aliases index.php), so the REST lane cannot
            // reach WordPress by returning — it has to name WordPress's front
            // controller. Resolved once, here, because its absence is also the
            // answer to whether this Space has a REST API at all: a Space with
            // no WordPress does not, so /wp-json is an ordinary URL it does not
            // publish and the visitor lane answers it with the Space's own 404.
            // Claiming it would make every static Space pretend to have an
            // editor behind it.
            if (
                $isContentAdminPath
                && _stattic_content_rest_request_path($path, is_array($_GET) ? $_GET : [])
            ) {
                // WordPress core is not the document root on a managed box: the
                // provider keeps it under `__wp__/` and links only wp-load.php
                // into the root the engine installs into. A front controller
                // named beside THAT root is a file which never exists, so the
                // REST lane declined every request and /wp-json answered with
                // the Space's own 404. Resolve it beside the wp-load.php the
                // page lane already boots, which is that link's target wherever
                // the provider chooses to keep core.
                $wpLoad = dirname($installRoot) . '/wp-load.php';
                $wpRoot = is_file($wpLoad) ? dirname(realpath($wpLoad) ?: $wpLoad) : null;
                $frontController = $wpRoot === null ? null : $wpRoot . '/wp-blog-header.php';
                $restFrontController = $frontController !== null && is_file($frontController)
                    ? $frontController
                    : null;
                $isContentAdminPath = $restFrontController !== null;
            }
        }
        if ($isContentAdminPath) {
            $bindDatabasePassword();
            require_once $releaseRoot . '/engine/shared/bootstrap-config.php';
            require_once $releaseRoot . '/engine/shared/cache-policy.php';
            require_once $releaseRoot . '/engine/shared/context.php';
            require_once $releaseRoot . '/engine/shared/storage.php';
            // The route pointer + host entry the visitor lane reads. The hold
            // verdict is decided from those alone, before any space overlay,
            // so this lane needs no generated config to reach it.
            require_once $releaseRoot . '/engine/shared/artifacts.php';
            require_once $releaseRoot . '/engine/shared/content-access.php';
            $isContentAdminRequest = true;
            $privateRoot = $installRoot . '/storage';
            $host = _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''));
            // The platform's answer outranks the session. A tombstoned or
            // held host gets the platform's page from every visitor; leaving
            // it editable for the rest of a session TTL would let an editor
            // keep writing into a Space the platform has taken over.
            $hold = _stattic_content_admin_platform_hold($privateRoot, $host);
            if ($hold === 'tombstone') {
                _stattic_problem_response(
                    404,
                    'content_admin_space_not_found',
                    'No editable Space is active for this host.'
                );
            }
            if ($hold !== null) {
                _stattic_problem_response(
                    503,
                    'content_admin_space_unavailable',
                    'This Space is not editable right now.'
                );
            }
            $cookieName = _stattic_content_admin_cookie_name();
            $token = is_string($_COOKIE[$cookieName] ?? null)
                ? $_COOKIE[$cookieName]
                : '';
            $session = _stattic_content_admin_verify_session($privateRoot, $token, $host);
            if ($session !== null) {
                $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = $session['user_id'];
                $GLOBALS['SPACEFAST_CONTENT_ADMIN_SESSION_EXPIRES_AT'] = $session['expires_at'];
                $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $session['principal'];
                $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $session['wordpress_role'];
                _stattic_content_admin_enter_wordpress(
                    $privateRoot,
                    $session['space_id'],
                    $session['frame_origin'],
                    $session['access']
                );
            } elseif ($restFrontController === null) {
                // Not the REST lane, so /wp-admin: the editor's own HTML surface,
                // with exactly one door — the session its launch minted.
                _stattic_problem_response(
                    401,
                    'content_admin_session_invalid',
                    'The content editor session is invalid or expired.'
                );
            } else {
                // THE WP API door. WordPress's REST API is reached as the
                // principal a Space credential resolves to, and the decision to
                // let this request through is made by the SAME access engine
                // that serves the Space's pages — asked here, never restated.
                // A protected Space refuses a credential-less REST call exactly
                // as it refuses a page, and no lane here can be wider than the
                // page-serving policy because there is no second policy.
                _stattic_visitor_lane_begin($privateRoot);
                require_once $releaseRoot . '/engine/runtime/serve.php';
                require_once $releaseRoot . '/engine/runtime/access-rules.php';
                _sf_load_generated_config($privateRoot);
                // The path the access engine sees. The query spelling
                // (`/?rest_route=`) addresses the same resource as
                // `/wp-json/...` and must be enforced against the same path, or
                // a Grant that scopes `/wp-json` binds only the pretty spelling.
                $accessPath = _stattic_content_rest_access_path(
                    $path,
                    is_array($_GET) ? $_GET : []
                );
                $target = _stattic_content_access_target($privateRoot, $host);
                if ($target['kind'] === 'unavailable') {
                    _stattic_problem_response(
                        503,
                        'content_admin_space_unavailable',
                        'This Space is not editable right now.'
                    );
                }
                if ($target['kind'] !== 'present') {
                    _stattic_problem_response(
                        404,
                        'content_admin_space_not_found',
                        'No editable Space is active for this host.'
                    );
                }
                $GLOBALS['SPACEFAST_PAGE_SERVING'] = $target['serving'];
                // An open Space skips enforcement for the anonymous answer, but a
                // presented credential is still a credential: an unusable one
                // must deny here rather than fall through to WordPress's public
                // answer, so a machine caller never has its failure hidden.
                if (
                    !$target['open']
                    || _stattic_platform_bearer_token_from_request() !== null
                ) {
                    // Denials terminate inside, with the same answer this path
                    // would give a browser asking for the page.
                    _stattic_access_enforce_v4($host, $accessPath, $accessPath);
                }
                // Admitted. Who WordPress runs as is a separate question from
                // whether the request may proceed, and null is a normal answer:
                // WordPress then serves REST unauthenticated, which on a public
                // Space is exactly what it would do on its own.
                $principal = _stattic_access_wordpress_principal(
                    $target['serving'],
                    $host,
                    $accessPath
                );
                if ($principal !== null) {
                    $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $principal;
                    $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $principal['wordpress_role'];
                }
                _stattic_content_admin_enter_wordpress(
                    $privateRoot,
                    $target['space_id'],
                    null,
                    ['surface' => 'wordpress']
                );
                $GLOBALS['SPACEFAST_CONTENT_PINNED_MODEL_REVISION'] = _stattic_content_version_model_revision(
                    $privateRoot,
                    $target['space_id'],
                    $target['version_id']
                );
            }
            // Both REST doors end here. /wp-admin needs none of it: those are
            // real WordPress scripts, and returning is exactly how they run.
            if ($restFrontController !== null) {
                // Editor sessions follow their active model. Public REST follows
                // the served version, so an unpublished candidate cannot change
                // its collection privacy policy.
                $restSpaceId = (string) ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? '');
                $contentModelRevision = $GLOBALS['SPACEFAST_CONTENT_PINNED_MODEL_REVISION'] ?? null;
                if ($session !== null && $restSpaceId !== '') {
                    $contentModelRevision = _stattic_private_tree_read_pointer(
                        $privateRoot . '/spaces/' . $restSpaceId . '/content-model/active-release',
                        128
                    );
                }
                if (
                    !is_string($contentModelRevision)
                    || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $contentModelRevision) !== 1
                ) {
                    _stattic_problem_response(
                        404,
                        'content_admin_space_not_found',
                        'No editable Space is active for this host.'
                    );
                }
                // The gate admitted this request; whether WordPress answers REST
                // at all is the gate's decision, not the resolved role's. The
                // kernel's rest_authentication_errors filter reads this marker so
                // an anonymous request the gate already admitted (a public Space)
                // gets WordPress's own unauthenticated answer instead of a 404
                // the gate never intended. The role still projects capabilities.
                $GLOBALS['SPACEFAST_CONTENT_REST_ADMITTED'] = true;
                // REST renders no theme, and WordPress answers on parse_request
                // long before one would load.
                if (!defined('WP_USE_THEMES')) {
                    define('WP_USE_THEMES', false);
                }
                // The page lane's reason, on the API door. This pass is the
                // provider's auto_prepend, and /scripts/env.php defines
                // DB_NAME/DB_USER/DB_HOST and WP_CONTENT_DIR only AFTER it
                // returns; WordPress booted here reaches wpdb with an empty
                // database tuple and dies in dead_db(). The document root's
                // front controller runs next with the environment complete, and
                // everything this gate decided — the Space scope, the resolved
                // principal, the admission marker — is in $GLOBALS, which that
                // pass shares. Declining here is how the request gets there.
                if (!empty($GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'])) {
                    $GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REST_FRONT_CONTROLLER'] = $restFrontController;
                    return;
                }
                require $restFrontController;
                exit;
            }
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

    // The API door's half of the hand-off below. Nothing is re-entered: the gate
    // ran to completion in the auto_prepend pass and left its verdict in
    // $GLOBALS, so all that remains is the WordPress boot it declined to make.
    $deferredRest = $GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REST_FRONT_CONTROLLER'] ?? null;
    if (is_string($deferredRest)) {
        unset($GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REST_FRONT_CONTROLLER']);
        require $deferredRest;
        exit;
    }

    // A request the auto_prepend pass handed here so WordPress could boot with
    // the environment /scripts/env.php finishes AFTER that pass. The lane and
    // everything it loaded are already in this same process, so only the lane
    // itself repeats — re-requiring init.php would redeclare its functions.
    $deferred = $GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST'] ?? null;
    if (is_array($deferred) && function_exists('_sf_serve_fast')) {
        _stattic_wordpress_page_resume_deferred_request();
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
