<?php
declare(strict_types=1);

// The request half of the engine: what the thin loader (runtime/custom-redirects.php,
// installed at the provider's auto_prepend, index.php and every
// /__spacefast/*.php entrypoint) hands each pass to, from whichever engine it
// chose. It changes with the engine, so a site never carries a copy of it.
//
// $loader is the hand-off, contract version 1 (`loader_version`):
//   script                the loader copy's own file name (custom-redirects.php,
//                         index.php, api.php, ...), which says which pass this is
//   public_root           the site's document root
//   install_root          realpath of the site's .stattic
//   engine_root           the engine this body belongs to: a chroot revision
//                         directory or a site release
//   native_binary, engine_source, engine_source_reason, chroot_revision
//                         what the loader chose, and why, for health
// A pass after the first finds SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT already
// set and runs the same engine.

return static function (array $loader): void {
    $publicRoot = $loader['public_root'];
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
    $runRestFrontController = static function (string $controllerPath, bool $useThemes): never {
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', $useThemes);
        }
        $isolationStream = function_exists('_stattic_document_isolation_stream_begin');
        if ($isolationStream) {
            _stattic_document_isolation_stream_begin();
        }
        require $controllerPath;
        if (function_exists('_stattic_enforce_document_isolation')) {
            _stattic_enforce_document_isolation();
        }
        if ($isolationStream) {
            ob_end_flush();
        }
        exit;
    };

    // Pass 1 acquires publication.lock and pins SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT.
    // Pass 2 (index.php or /__spacefast/*.php after auto_prepend) keeps pass 1's
    // lock handle and engine untouched instead of releasing/re-acquiring mid-request.
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    $hasPass1Lock = is_resource($GLOBALS['SPACEFAST_RUNTIME_PUBLICATION_LOCK'] ?? null);
    if (!is_string($releaseRoot) || !$hasPass1Lock) {
        $publicationLockPath = $installRoot . '/publication.lock';
        if (is_link($installRoot) || is_link($publicationLockPath)) {
            $publicationUnavailable();
        }
        // Only installation creates this lock; retained-evidence reads never do.
        $publicationLock = @fopen($publicationLockPath, 're');
        if (!is_resource($publicationLock)) {
            $publicationUnavailable();
        }
        $requestPath = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        $managementRequest = in_array($requestPath, [
            '/__spacefast/api.php',
            '/__spacefast/upload.php',
            '/__spacefast/health.php',
        ], true);
        // Staging runs beside the active release. Visitors only wait for the
        // final publication transaction; management keeps its retryable receipt.
        $publicationDeadline = hrtime(true) + 5_000_000_000;
        while (!flock($publicationLock, LOCK_SH | LOCK_NB)) {
            if ($managementRequest || hrtime(true) >= $publicationDeadline) {
                $publicationUnavailable();
            }
            usleep(10000);
        }
        $GLOBALS['SPACEFAST_RUNTIME_PUBLICATION_LOCK'] = $publicationLock;
        $installTransaction = $installRoot . '/install-transaction.json';
        $committedTarget = null;
        if (file_exists($installTransaction) || is_link($installTransaction)) {
            $transaction = !is_link($installTransaction) && is_file($installTransaction)
                ? json_decode((string) @file_get_contents($installTransaction, false, null, 0, 16384), true)
                : null;
            if ($transaction === null) {
                clearstatcache(true, $installTransaction);
            }
            if ($transaction === null && !file_exists($installTransaction) && !is_link($installTransaction)) {
                // Completed cleanup unlinked the journal between the two reads.
            } elseif (($transaction['phase'] ?? null) !== 'committed'
                || ($transaction['format'] ?? null) !== 'spacefast.runtime.install-transaction.v2'
                || !is_string($transaction['release'] ?? null)) {
                $publicationUnavailable();
            } else {
                $committedTarget = $transaction['release'];
            }
        }
        $pointerPath = $installRoot . '/active-release';
        $pointer = is_file($pointerPath)
            ? file_get_contents($pointerPath, false, null, 0, 256)
            : false;
        $target = is_string($pointer) ? trim($pointer) : '';
        if ($committedTarget !== null && $committedTarget !== $target) {
            $publicationUnavailable();
        }

        if (!is_string($releaseRoot)) {
            $releaseRoot = $loader['engine_root'];
            if ($loader['engine_source'] === 'site') {
                if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1) {
                    exit(1);
                }
                $releaseReal = realpath($installRoot . '/' . $target);
                if (!is_string($releaseReal) || !str_starts_with($releaseReal, $loader['install_root'] . '/releases/')) {
                    exit(1);
                }
                if ($releaseReal !== $releaseRoot) {
                    // The loader read the pointer before this lock, and the
                    // installer published another release in between. Release
                    // this handle and run that release's own body so it takes a
                    // fresh lock cleanly.
                    $body = $releaseReal . '/engine/loader/request.php';
                    if (!empty($loader['republished']) || !is_file($body)) {
                        $publicationUnavailable();
                    }
                    unset($GLOBALS['SPACEFAST_RUNTIME_PUBLICATION_LOCK']);
                    flock($publicationLock, LOCK_UN);
                    fclose($publicationLock);
                    (require $body)([
                        'engine_root' => $releaseReal,
                        'native_binary' => $releaseReal . '/bin/stattic-runtime',
                        'republished' => true,
                    ] + $loader);
                    return;
                }
            }
            $GLOBALS['SPACEFAST_RUNTIME_NATIVE_BINARY'] = $loader['native_binary'];
            $GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE'] = $loader['engine_source'];
            $GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE_REASON'] = $loader['engine_source_reason'];
            $GLOBALS['SPACEFAST_RUNTIME_CHROOT_REVISION'] = $loader['chroot_revision'];
            $GLOBALS['SPACEFAST_RUNTIME_LOADER_VERSION'] = $loader['loader_version'];
            // Named, not derived: a chroot engine lives outside this install root.
            $GLOBALS['SPACEFAST_RUNTIME_INSTALL_ROOT'] = $loader['install_root'];
            $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] = $releaseRoot;
        }
    }

    // Install the header dispatcher before WordPress or plugins can register
    // deferred headers.
    $response = $releaseRoot . '/engine/shared/response.php';
    if (is_file($response)) {
        require_once $response;
    }

    $script = $loader['script'];
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
            '/__spacefast/mail.php' => true,
            '/__spacefast/preview-cron.php' => true,
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
        // nginx picks the script from the decoded, slash-merged, dot-resolved
        // URI, so `/wp-admin//includes/file.php` or `%69ncludes` still runs
        // the include. Classify that same spelling; an unresolvable `..` never
        // reaches a script, so it cannot name one here either.
        $scriptPath = [];
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($scriptPath);
                continue;
            }
            $scriptPath[] = $segment;
        }
        $scriptPath = '/' . implode('/', $scriptPath);
        $isWordPressCorePhp = preg_match(
            '#^/(?:wp-[^/]+\.php|wp-(?:includes|content)/.+\.php|wp-admin/includes/.+\.php)(?:/|$)#',
            $scriptPath
        ) === 1;
        $isWordPressCron = $scriptPath === '/wp-cron.php' || str_starts_with($scriptPath, '/wp-cron.php/');
        $isBlockedWordPressEntrypoint = ($isWordPressCorePhp && !$isWordPressCron && $scriptPath !== '/wp-login.php')
            || $scriptPath === '/xmlrpc.php'
            || str_starts_with($scriptPath, '/xmlrpc.php/');
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
        $isZeroAdminPath = $path === '/zero-admin' || str_starts_with($path, '/zero-admin/');
        $restFrontController = null;
        if (
            !isset($entrypointPaths[$path])
            && !$isFpmReadinessProbe
            && is_file($releaseRoot . '/engine/shared/content-admin.php')
        ) {
            require_once $releaseRoot . '/engine/shared/content-admin.php';
            $restSelectors = (is_array($_GET) ? $_GET : []) + (is_array($_POST) ? $_POST : []);
            $isContentAdminPath = _stattic_content_admin_request_path(
                $path,
                $restSelectors
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
                && (_stattic_content_rest_request_path($path, $restSelectors) || $isZeroAdminPath)
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
                $frontController = $wpRoot === null ? null : $wpRoot . (
                    $isZeroAdminPath ? '/wp-admin/admin.php' : '/wp-blog-header.php'
                );
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
            $host = _stattic_normalize_host_authority((string) ($_SERVER['HTTP_HOST'] ?? ''));
            // Resolve the host's content-access target ONCE per request and
            // reuse it for hold detection, deployment route claim checks,
            // anonymous REST access enforcement, and Zero v2 selection.
            $accessTarget = _stattic_content_access_target($privateRoot, $host);
            // The platform's answer outranks the session. A tombstoned or
            // held host gets the platform's page from every visitor; leaving
            // it editable for the rest of a session TTL would let an editor
            // keep writing into a Space the platform has taken over.
            $hold = _stattic_content_admin_platform_hold($privateRoot, $host, $accessTarget);
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
            $restAccessPath = _stattic_content_rest_access_path($path, $restSelectors);
            $deploymentPath = $path === '/' ? $restAccessPath : $path;
            $isRestRequest = _stattic_content_rest_request_path($path, $restSelectors);
            $wordpressRest = $releaseRoot . '/engine/shared/wordpress-rest.php';
            if (is_file($wordpressRest)) require_once $wordpressRest;
            $hasRestBoundary = function_exists('_stattic_wordpress_rest_origin_allowed');
            $hasRestCookieBoundary = function_exists('_stattic_wordpress_rest_cookie_allowed');
            // Defer a platform credential to verification below; its presence never admits a cookie.
            $bearerPresented = _stattic_platform_bearer_token_from_request() !== null;
            $platformCredentialPresented = $isRestRequest && $bearerPresented;
            $identityRestRequest = $isRestRequest && function_exists('_stattic_wordpress_identity_rest_route') && _stattic_wordpress_identity_rest_route(
                substr($restAccessPath, strlen('/wp-json'))
            );
            $restOriginAllowed = !$isRestRequest || !$hasRestBoundary || _stattic_wordpress_rest_origin_allowed(
                $host,
                (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            );
            // Identity's registered app origins can only be checked after its
            // plugin and scoped database load. Never project ambient WordPress
            // credentials while deferring that check to the REST auth hook.
            if (!$restOriginAllowed && !$identityRestRequest && !$platformCredentialPresented && $_COOKIE !== []) {
                _stattic_problem_response(403, 'rest_origin_denied', 'Use this Space from its own origin.');
            }
            if (_stattic_content_deployment_claims_path($privateRoot, $host, $deploymentPath, strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $accessTarget)) {
                require $releaseRoot . '/engine/init.php';
                return;
            }
            $cookieName = _stattic_content_admin_cookie_name();
            $token = is_string($_COOKIE[$cookieName] ?? null)
                ? $_COOKIE[$cookieName]
                : '';
            $restCookieAllowed = !$isRestRequest || ($hasRestCookieBoundary
                ? _stattic_wordpress_rest_cookie_allowed($host) : $restOriginAllowed && !$platformCredentialPresented);
            $session = $restCookieAllowed ? _stattic_content_admin_verify_session($privateRoot, $token, $host) : null;
            if ($session !== null) {
                $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] = $session['user_id'];
                $GLOBALS['SPACEFAST_CONTENT_ADMIN_SESSION_EXPIRES_AT'] = $session['expires_at'];
                $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $session['principal'];
                $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $session['wordpress_role'];
                _stattic_content_admin_enter_wordpress(
                    $privateRoot,
                    $session['space_id'],
                    $session['frame_origin'],
                    $session['access'],
                    $session['public_origin']
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
                if ($accessTarget['kind'] === 'unavailable') {
                    _stattic_problem_response(
                        503,
                        'content_admin_space_unavailable',
                        'This Space is not editable right now.'
                    );
                }
                if ($accessTarget['kind'] === 'denied') {
                    _stattic_problem_response(
                        403,
                        'access_denied',
                        'This page is private.'
                    );
                }
                if ($accessTarget['kind'] !== 'present') {
                    _stattic_problem_response(
                        404,
                        'content_admin_space_not_found',
                        'No editable Space is active for this host.'
                    );
                }
                $GLOBALS['SPACEFAST_PAGE_SERVING'] = $accessTarget['serving'];
                // An open Space skips enforcement for the anonymous answer, but a
                // presented credential is still a credential: an unusable one
                // must deny here rather than fall through to WordPress's public
                // answer, so a machine caller never has its failure hidden.
                if (!$accessTarget['open'] || $bearerPresented) {
                    // Denials terminate inside, with the same answer this path
                    // would give a browser asking for the page.
                    _stattic_access_enforce_v4($host, $restAccessPath, $restAccessPath, $isRestRequest);
                }
                if (!$restOriginAllowed && $platformCredentialPresented
                    && (!function_exists('_stattic_current_session_identity')
                        || _stattic_current_session_identity($accessTarget['serving'], $host) === null)) {
                    _stattic_problem_response(403, 'rest_origin_denied', 'Use this Space from its own origin.');
                }
                // Admitted. Who WordPress runs as is a separate question from
                // whether the request may proceed, and null is a normal answer:
                // WordPress then serves REST unauthenticated, which on a public
                // Space is exactly what it would do on its own.
                // A native WordPress request (admin-ajax.php, wp-admin) that
                // changes something acts as the person only from this exact
                // origin, the rule the REST door applies: a same-site page on
                // another origin can send the Space's Lax cookie, never its
                // Origin. Its own nonce checks still run.
                $nativeUnsafeForeign = !$isRestRequest
                    && !in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD', 'OPTIONS'], true)
                    && function_exists('_stattic_access_same_origin_post')
                    && !_stattic_access_same_origin_post($host);
                $principal = (!$restCookieAllowed && !$platformCredentialPresented) || $nativeUnsafeForeign
                    ? null : _stattic_access_wordpress_principal(
                        $accessTarget['serving'],
                        $host,
                        $restAccessPath
                    );
                if ($principal !== null) {
                    $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $principal;
                    $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $principal['wordpress_role'];
                }
                // A SpacePress version is SpacePress before its Space's first
                // promote too, while the route still names the previous runtime.
                if (in_array($accessTarget['serving']['zero_generation'] ?? null, ['v2', 'tp_v1', 'spacepress'], true)
                    || _stattic_content_target_is_spacepress($privateRoot, $accessTarget)) {
                    // The Space role the session holds, for the Spacefast
                    // SpacePress plugin to lend on this request; the same
                    // resolver the page lane runs (shared/spacepress.php).
                    if ($principal !== null && !$nativeUnsafeForeign) {
                        require_once $releaseRoot . '/engine/shared/spacepress.php';
                        _stattic_spacepress_resolve_visitor($accessTarget['serving'], $host, $restAccessPath, strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
                    }
                    // A response that needed a Grant or runs as a platform
                    // principal leaves PHP private and out of the edge cache,
                    // whatever the app's own handler sets.
                    if (($principal !== null || !$accessTarget['open']) && function_exists('_stattic_authenticated_response')) {
                        _stattic_authenticated_response(true);
                    }
                }
                _stattic_content_admin_enter_wordpress(
                    $privateRoot,
                    $accessTarget['space_id'],
                    null,
                    ['surface' => 'wordpress']
                );
                $GLOBALS['SPACEFAST_CONTENT_PINNED_MODEL_REVISION'] = _stattic_content_version_model_revision(
                    $privateRoot,
                    $accessTarget['space_id'],
                    $accessTarget['version_id']
                );
            }
            if ($accessTarget['kind'] === 'present') {
                require_once $releaseRoot . '/engine/shared/context.php';
                $targetVersionRoot = function_exists('_stattic_version_root')
                    ? _stattic_version_root($privateRoot, $accessTarget['space_id'], $accessTarget['version_id'])
                    : $privateRoot . '/spaces/' . $accessTarget['space_id'] . '/versions/' . $accessTarget['version_id'];
                $targetGeneration = is_string($accessTarget['serving']['zero_generation'] ?? null)
                    ? $accessTarget['serving']['zero_generation']
                    : 'none';
                $hasSpacepress = is_file($targetVersionRoot . '/.spacepress-compiled/manifest.json');
                $hasZeroCompiled = is_file($targetVersionRoot . '/.zero-compiled/manifest.json');
                $selectedSpacepress = false;
                if ($hasSpacepress || $hasZeroCompiled) {
                    $spacepressHost = $releaseRoot . '/engine/shared/spacepress.php';
                    if (is_file($spacepressHost)) {
                        require_once $spacepressHost;
                        if (_stattic_spacepress_version_manifest($targetVersionRoot) !== null) {
                            if (in_array($targetGeneration, ['legacy_0_8', 'v2'], true)) {
                                _stattic_problem_response(409, 'zero_generation_locked', 'This version belongs to a different runtime.');
                            }
                            if (_stattic_spacepress_select($privateRoot, $accessTarget['space_id'], $accessTarget['version_id'], $accessTarget['serving'])) {
                                $GLOBALS['SPACEFAST_PAGE_SERVING'] ??= $accessTarget['serving'];
                                $selectedSpacepress = true;
                            }
                        }
                    }
                }
                if (
                    !$selectedSpacepress
                    && ($hasZeroCompiled || in_array($targetGeneration, ['legacy_0_8', 'v2'], true)
                        || is_file($targetVersionRoot . '/zero/config.json'))
                ) {
                    $zeroHost = $releaseRoot . '/engine/deprecated/zero-v2/zero-v2.php';
                    if (is_file($zeroHost)) {
                        require_once $zeroHost;
                        _stattic_zero_generation_serve($targetVersionRoot, $accessTarget['serving']);
                        // A content-admin session has no page serving yet; the binding's
                        // Users settings come from the same route entry.
                        if (_stattic_zero_v2_select($privateRoot, $accessTarget['space_id'], $accessTarget['version_id'], $accessTarget['serving'])) {
                            $GLOBALS['SPACEFAST_PAGE_SERVING'] ??= $accessTarget['serving'];
                        }
                    }
                }
            }
            // Both REST doors end here. /wp-admin needs none of it: those are
            // real WordPress scripts, and returning is exactly how they run.
            if ($restFrontController !== null) {
                if ($isZeroAdminPath) {
                    $_GET['page'] = 'zero';
                    $_REQUEST['page'] = 'zero';
                    $_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
                }
                // REST renders no theme, and WordPress answers on parse_request
                // long before one would load. /zero-admin is the exception: the
                // dashboard renders on template_redirect, which core fires only
                // when themes are on.
                // The page lane's reason, on the API door. This pass is the
                // provider's auto_prepend, and /scripts/env.php defines
                // DB_NAME/DB_USER/DB_HOST and WP_CONTENT_DIR only AFTER it
                // returns; WordPress booted here reaches wpdb with an empty
                // database tuple and dies in dead_db(). The document root's
                // front controller runs next with the environment complete, and
                // everything this gate decided — the Space scope and the
                // resolved principal — is in $GLOBALS, which that pass shares.
                // Declining here is how the request gets there.
                if (!empty($GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'])) {
                    $GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REST_FRONT_CONTROLLER'] = [
                        'path' => $restFrontController,
                        'use_themes' => $isZeroAdminPath,
                    ];
                    return;
                }
                $runRestFrontController($restFrontController, $isZeroAdminPath);
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
    if (is_array($deferredRest)) {
        unset($GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REST_FRONT_CONTROLLER']);
        $runRestFrontController($deferredRest['path'], $deferredRest['use_themes']);
    }

    // A request the auto_prepend pass handed here so WordPress could boot with
    // the environment /scripts/env.php finishes AFTER that pass. The lane and
    // everything it loaded are already in this same process, so only the lane
    // itself repeats — re-requiring init.php would redeclare its functions.
    $deferred = $GLOBALS['SPACEFAST_RUNTIME_DEFERRED_REQUEST'] ?? null;
    if (is_array($deferred) && function_exists('_sf_serve_fast')) {
        require_once $releaseRoot . '/engine/shared/wordpress-scope.php';
        _stattic_wordpress_page_resume_deferred_request();
    }

    if (in_array($script, ['content-admin.php', 'content.php', 'preview-cron.php'], true)) {
        $bindDatabasePassword();
    }
    $entrypoint = match ($script) {
        'api.php' => 'entrypoints/management.php',
        // The editor door keeps its served path, so a box takes signed
        // launches as soon as its engine does, before its site files change.
        'content-admin.php' => 'entrypoints/sp-admin.php',
        'content.php' => 'entrypoints/content.php',
        'health.php' => 'entrypoints/health.php',
        'mail.php' => 'entrypoints/mail.php',
        'preview-cron.php' => 'entrypoints/preview-cron.php',
        'upload.php' => 'entrypoints/upload.php',
        default => 'init.php',
    };
    require $releaseRoot . '/engine/' . $entrypoint;
};
