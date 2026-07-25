<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/admission.php';

// Resolves the lookup action for a request path and applies the terminal gates
// (configured-index fallback, terminal 404, method gate, redirect emit, and the
// non-GET/HEAD 405 for non-invoke_zero actions). Returns [$lookup, $lookupAction].
function _stattic_resolve_gated_lookup_action(string $versionRoot, array $version, string $requestPath, string $requestMethod, bool $privateCache, ?string $mountPrefix = null): array
{
    // Serve-time lookup applies the same decode-once+NFC transform as upload intake
    // (spec canonical path form) so NFC and NFD request forms resolve identically.
    $lookup = ltrim(_stattic_nfc_path(rawurldecode($requestPath)), '/');
    $lookupAction = _stattic_resolve_request_lookup_action($versionRoot, $version, $lookup, $requestMethod);
    if (
        $lookupAction === null
        || (
            is_array($lookupAction)
            && ($lookupAction['action'] ?? null) === 'not_found'
            && trim($lookup, '/') === ''
        )
    ) {
        $lookupAction = _stattic_resolve_configured_index_action($version, $lookup, str_ends_with($requestPath, '/'));
    }
    if (
        is_array($lookupAction)
        && ($lookupAction['action'] ?? null) === 'not_found'
        && trim($lookup, '/') !== ''
        && _stattic_lookup_not_found_is_terminal($lookup)
    ) {
        _stattic_send_not_found_action($lookupAction, $privateCache);
    }

    if (!_stattic_action_allows_method($lookupAction, $requestMethod)) {
        _stattic_render_method_not_allowed_lazy();
    }
    if (is_array($lookupAction) && ($lookupAction['action'] ?? null) === 'redirect') {
        _stattic_send_lookup_redirect($lookupAction, $privateCache, $mountPrefix);
    }
    if (
        !is_array($lookupAction)
        || !in_array($lookupAction['action'] ?? null, ['invoke_zero', 'zero_activating'], true)
    ) {
        if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
            _stattic_render_method_not_allowed_lazy();
        }
    }

    return [$lookup, $lookupAction];
}

function _stattic_add_vary_headers(array $headers, array $values): array
{
    $name = 'Vary';
    foreach (array_keys($headers) as $headerName) {
        if (strtolower((string) $headerName) === 'vary') {
            $name = (string) $headerName;
            break;
        }
    }
    $vary = array_filter(array_map('trim', explode(',', (string) ($headers[$name] ?? ''))));
    if (in_array('*', $vary, true)) {
        return $headers;
    }
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '' && !in_array(strtolower($value), array_map('strtolower', $vary), true)) {
            $vary[] = $value;
        }
    }
    $headers[$name] = implode(', ', $vary);
    return $headers;
}

function _stattic_serve_page_font(string $requestPath, string $requestMethod): void
{
    $file = _stattic_page_font_filename($requestPath);
    if ($file === null) {
        return;
    }
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        header('Cache-Control: no-store');
        exit;
    }
    $font = @file_get_contents(__DIR__ . '/../shared/fonts/' . $file);
    if (!is_string($font)) {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }
    http_response_code(200);
    header('Content-Type: font/woff2');
    header('Content-Length: ' . strlen($font));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    if ($requestMethod === 'GET') {
        echo $font;
    }
    exit;
}

function _stattic_serve_request(string $privateRoot, string $requestMethod, string $requestUri, string $requestPath, string $requestHost): void
{
    // Trusted-header contract (access-plan X-36): strip edge-owned inbound
    // headers (geo, forwarded proto/for/IP, Spacefast-Access-*) unless the
    // deployment is marked trusted. Runs first so every downstream read (country
    // rules, Secure detection, CIDR firewall, identity forwarding) sees only
    // edge-set values.
    _spacefast_strip_untrusted_edge_headers();

    // Visitor lane: deferred telemetry/housekeeping runs after the response is
    // flushed to the client (fastcgi_finish_request under FPM). The management
    // and upload lanes never opt in — their fatal-envelope shutdown handlers
    // must still be able to write a response.
    _spacefast_flush_response_before_deferred(true);

    _spacefast_access_private_root($privateRoot);

    $originalRequestUri = $requestUri;
    $originalRequestPath = $requestPath;
    _stattic_serve_page_font($requestPath, $requestMethod);
    if (_stattic_is_management_host($requestHost)) {
        _stattic_render_platform_action(_stattic_runtime_api_not_found_action());
    }

    $serving = _stattic_load_lazy_serving_config($privateRoot, $requestHost, $requestPath, $requestMethod);
    if (!is_array($serving)) {
        _stattic_render_platform_action(_stattic_undeployed_action());
    }
    $GLOBALS['SPACEFAST_PAGE_SERVING'] = $serving;

    // First-party access surfaces + the share-URL trade (access-plan §3.2). All
    // render/redirect and exit; they never fall through to serving.
    if (str_starts_with($requestPath, SPACEFAST_ACCESS_ROUTE_PREFIX)) {
        require_once __DIR__ . '/access-rules.php';
        if ($requestPath === SPACEFAST_ACCESS_LOGOUT_PATH) {
            _spacefast_access_handle_logout($requestHost);
        }
        if ($requestPath === SPACEFAST_ACCESS_ME_PATH) {
            _spacefast_access_handle_me($serving, $requestHost);
        }
        if ($requestPath === SPACEFAST_ACCESS_TOKEN_PATH) {
            _spacefast_access_handle_token($serving, $requestHost);
        }
        if ($requestPath === SPACEFAST_ACCESS_LOGIN_PATH) {
            _spacefast_access_handle_login($serving, $requestHost);
        }
        _stattic_send_not_found_action(_stattic_platform_error_action('access-route-not-found', 404, "Not found.\n"));
    }
    if (
        in_array($requestMethod, ['GET', 'HEAD'], true)
        && isset($_GET[SPACEFAST_SHARE_QUERY_NAME])
        && is_string($_GET[SPACEFAST_SHARE_QUERY_NAME])
        && !empty($serving['policy']['rules'])
    ) {
        require_once __DIR__ . '/access-rules.php';
        _spacefast_access_handle_share($serving, $requestHost, $originalRequestUri);
    }

    _stattic_send_response_headers($serving['response_headers'] ?? []);
    $versionId = $serving['version_id'] ?? null;
    $activeSpaceId = (string) ($serving['space_id'] ?? '');
    $tagPreviewToken = _stattic_spacefast_tag_preview_token($requestMethod);

    if ($requestPath === STATTIC_SPACEFAST_SDK_PATH) {
        require_once __DIR__ . '/../shared/bootstrap-config.php';
        require_once __DIR__ . '/spacefast-sdk.php';
        _stattic_serve_spacefast_sdk($serving, $requestHost, $requestMethod);
    }

    $admissionAcquired = false;
    $runtimeProbe = $originalRequestPath === '/__stattic_probe';
    // Every visitor-facing publish response crosses the unified access boundary
    // before any terminal lookup or redirect can exit. The runtime health probe
    // is a control route: broad visitor policies must not make a healthy runtime
    // look unavailable to activation/reconciliation. Contract A1 still applies
    // to every evaluated request: acquire admission before a matching rule
    // (including bcrypt).
    $privateCache = $runtimeProbe
        ? false
        : _stattic_enforce_access_path(
            $privateRoot,
            $serving,
            $requestHost,
            $originalRequestPath,
            $originalRequestUri,
            $admissionAcquired,
            false
        );
    $accessEvaluatedPath = $originalRequestPath;

    if (is_array($serving['platform_action'] ?? null)) {
        if (!_stattic_action_allows_method($serving['platform_action'], $requestMethod)) {
            _stattic_render_method_not_allowed_lazy();
        }
        _stattic_render_platform_action($serving['platform_action'], $privateCache);
    }

    // Channel-variant serving context (spec "Per-channel values"): template
    // variants overlay only when the host serves through a named route pointer
    // — immutable version hosts always serve finalize-resolved bytes.
    $variantRoute = is_string($serving['route_name'] ?? null) && empty($serving['immutable'])
        ? $serving['route_name']
        : null;

    $mountPrefix = null;
    $routeStatus = 200;
    $conditionalRewrite = false;
    $apexRedirectsApplied = false;
    // Shared redirect-result propagation: the static-mount apex pre-pass and
    // the main redirect pass below funnel every _stattic_apply_redirects result
    // through this one closure so path/status/query propagation cannot drift.
    $applyRedirectResult = function (array $rules, array $ruleVersion, ?string $ruleMountPrefix) use (
        &$serving,
        &$requestPath,
        &$requestUri,
        &$routeStatus,
        &$conditionalRewrite,
        &$privateCache,
        $requestHost
    ): void {
        $routeResult = _stattic_apply_redirects(
            $rules,
            $serving,
            $ruleVersion,
            $requestHost,
            $requestPath,
            $privateCache,
            $routeStatus,
            $ruleMountPrefix
        );
        $requestPath = $routeResult['path'];
        $routeStatus = $routeResult['status'];
        // A condition-matched rewrite (cookie / country / language / agent)
        // serves per-visitor content at a shared URL; the edge cannot key on
        // any of those, so the response must never enter a shared cache.
        $conditionalRewrite = $conditionalRewrite || !empty($routeResult['conditional']);
        if (array_key_exists('query', $routeResult)) {
            $_SERVER['QUERY_STRING'] = (string) $routeResult['query'];
            parse_str((string) $routeResult['query'], $_GET);
            $requestUri = $requestPath . ((string) $routeResult['query'] !== '' ? '?' . (string) $routeResult['query'] : '');
        }
    };
    // Access stickiness (see the security invariant note before the header-rules
    // block): every step that mutates $requestPath re-runs access enforcement on
    // the new path. $serving is captured by reference because host-route
    // mounting adds version manifests to it between call sites.
    $reenforceAccessIfPathChanged = function () use (
        &$requestPath,
        &$accessEvaluatedPath,
        &$privateCache,
        &$admissionAcquired,
        &$serving,
        $privateRoot,
        $requestHost,
        $originalRequestUri
    ): void {
        if ($requestPath !== $accessEvaluatedPath) {
            $privateCache = _stattic_enforce_access_path(
                $privateRoot,
                $serving,
                $requestHost,
                $requestPath,
                $originalRequestUri,
                $admissionAcquired,
                $privateCache
            );
            $accessEvaluatedPath = $requestPath;
        }
    };
    $hostRoute = is_array($serving['matched_host_route'] ?? null) ? $serving['matched_host_route'] : null;
    $routesForHost = is_array($serving['host_routes'] ?? null) ? $serving['host_routes'] : [];
    $hasStaticMountRoute = false;
    foreach ($routesForHost as $candidateRoute) {
        $candidateAction = is_array($candidateRoute) && is_array($candidateRoute['route_action'] ?? null)
            ? $candidateRoute['route_action']
            : null;
        if (is_array($candidateAction) && !empty($candidateAction['static_mount'])) {
            $hasStaticMountRoute = true;
            break;
        }
    }
    $apexVersion = is_string($versionId) && isset($serving['versions'][$versionId]) && is_array($serving['versions'][$versionId])
        ? $serving['versions'][$versionId]
        : null;
    if (
        $hasStaticMountRoute
        && is_array($apexVersion)
        && !empty($apexVersion['redirect_artifact'])
        && _stattic_path_concern_applies($apexVersion, 'redirects', $requestPath)
    ) {
        require_once __DIR__ . '/redirects.php';
        $apexVersionRoot = _stattic_version_root($privateRoot, $activeSpaceId, $versionId);
        $apexRedirectRules = _stattic_load_ordered_rule_artifact($apexVersionRoot, 'redirects', 'redirect-artifact-missing', 'Runtime redirect artifact is missing.');
        if (!empty($apexRedirectRules['exact']) || !empty($apexRedirectRules['pattern'])) {
            $applyRedirectResult($apexRedirectRules, $apexVersion, null);
        }
        $apexRedirectsApplied = true;
        $hostRoute = _stattic_lazy_match_host_route($routesForHost, $requestPath, $requestMethod);
    }
    $reenforceAccessIfPathChanged();
    $privateCache = $privateCache || $conditionalRewrite;
    if (is_array($hostRoute)) {
        $hostRouteAction = _stattic_matched_host_route_action($hostRoute);
        $hostRouteActionKind = $hostRouteAction['action'] ?? null;
        if (!_stattic_action_allows_method($hostRouteAction, $requestMethod)) {
            _stattic_render_method_not_allowed_lazy();
        }
        if ($hostRouteActionKind === 'redirect') {
            _stattic_send_route_redirect($hostRouteAction, $hostRoute['_remainder'] ?? '/', $privateCache);
        }

        if ($hostRouteActionKind === 'proxy') {
            $hostRouteAction['_remainder'] = $hostRoute['_remainder'] ?? '/';
            _stattic_enforce_access_for_proxy($serving, $requestHost, $originalRequestPath, $originalRequestUri);
            require_once __DIR__ . '/proxy.php';
            _stattic_proxy_request($hostRouteAction, $hostRouteAction['_remainder']);
        }

        if ($hostRouteActionKind === 'robots_txt') {
            _stattic_render_platform_action($hostRouteAction, $privateCache);
        }

        if (in_array(($hostRouteAction['action'] ?? null), ['platform_error', 'tombstone'], true)) {
            _stattic_render_platform_action($hostRouteAction, $privateCache);
        }

        if ($hostRouteActionKind === 'serve' && is_string($hostRouteAction['version_id'] ?? null)) {
            // A host-route serve action targets an explicit version, not the
            // route pointer — channel variants never apply to it.
            $variantRoute = null;
            $versionId = $hostRouteAction['version_id'];
            if (is_string($hostRouteAction['space_id'] ?? null) && $hostRouteAction['space_id'] !== '') {
                $activeSpaceId = $hostRouteAction['space_id'];
            }
            if (!empty($hostRouteAction['static_mount'])) {
                $mountPrefix = is_string($hostRouteAction['mount_prefix'] ?? null)
                    ? rtrim($hostRouteAction['mount_prefix'], '/')
                    : null;
                if ($mountPrefix !== null && $requestPath === $mountPrefix) {
                    _stattic_emit_redirect_response($mountPrefix . '/', 308, null, $privateCache);
                }
            }
            $requestPath = _stattic_join_request_path((string) ($hostRouteAction['target_prefix'] ?? '/'), $hostRoute['_remainder'] ?? '/');
            if (
                !isset($serving['versions'][$versionId])
                && _spacefast_id_valid($activeSpaceId)
                && _spacefast_id_valid($versionId)
            ) {
                $mountedVersion = _stattic_load_version_manifest($privateRoot, $activeSpaceId, $versionId);
                if (is_array($mountedVersion)) {
                    $serving['versions'][$versionId] = $mountedVersion;
                }
            }
        }
    }

    // A host-route serve action can remap the visitor path before lookup. Gate
    // that effective path before its lookup action gets a chance to exit.
    $reenforceAccessIfPathChanged();

    $version = null;
    if (is_string($versionId) && isset($serving['versions'][$versionId]) && is_array($serving['versions'][$versionId])) {
        $version = $serving['versions'][$versionId];
    }

    if (!is_array($version)) {
        if ($mountPrefix !== null) {
            _stattic_render_platform_action(_stattic_version_pending_action(), $privateCache);
        }
        _stattic_render_runtime_invariant_error_lazy('selected-version-metadata-missing', 'Runtime selected version metadata is missing.');
    }

    $versionRoot = _stattic_version_root($privateRoot, $activeSpaceId, $versionId);
    $claimBanner = _stattic_claim_banner_context($serving, $requestMethod);
    header('X-Spacefast-Runtime: 1', false);
    header('X-Spacefast-Version: ' . $versionId, false);

    if ($requestPath === '/__stattic_probe') {
        http_response_code(204);
        header('cache-control: no-store, no-cache, must-revalidate');
        exit;
    }

    $servedOriginalRequestPath = $requestPath;
    [$lookup, $lookupAction] = _stattic_resolve_gated_lookup_action($versionRoot, $version, $requestPath, $requestMethod, $privateCache, $mountPrefix);

    $redirectRules = ['exact' => [], 'pattern' => []];
    $hasExactRedirectRuleForOriginalRequest = false;
    if (
        (!$apexRedirectsApplied || $mountPrefix !== null)
        && !empty($version['redirect_artifact'])
        && _stattic_path_concern_applies($version, 'redirects', $requestPath)
    ) {
        require_once __DIR__ . '/redirects.php';
        $redirectRules = _stattic_load_ordered_rule_artifact($versionRoot, 'redirects', 'redirect-artifact-missing', 'Runtime redirect artifact is missing.');
        $hasExactRedirectRuleForOriginalRequest = _stattic_redirect_exact_rule_exists($redirectRules, $servedOriginalRequestPath);
    }
    if (!empty($redirectRules['exact']) || !empty($redirectRules['pattern'])) {
        $applyRedirectResult($redirectRules, $version, $mountPrefix);
    } elseif (!$apexRedirectsApplied) {
        $routeStatus = 200;
    }
    // Rewrites can select a different protected target. Re-evaluate before the
    // target lookup is resolved because that lookup may itself terminate with a
    // redirect or 404. Protection is sticky across every evaluated path.
    $reenforceAccessIfPathChanged();
    $privateCache = $privateCache || $conditionalRewrite;
    if ($requestPath !== $servedOriginalRequestPath) {
        [$lookup, $lookupAction] = _stattic_resolve_gated_lookup_action(
            $versionRoot,
            $version,
            $requestPath,
            $requestMethod,
            $privateCache,
            $mountPrefix
        );
    }
    // Security invariant: access/auth/header rules MUST be evaluated on the path
    // actually served — the post-rewrite $requestPath — not the original request
    // path. Redirects/rewrites above (~line 130) can remap a PUBLIC source path to
    // a PROTECTED target; matching these concerns on $originalRequestPath would
    // serve the target's bytes while skipping the target's access rules. The
    // return-to URL ($originalRequestUri) is intentionally kept as the visitor's
    // requested URL so a post-credential redirect lands them back where they were.
    // The legacy _headers basic-auth enforcement lane is gone (access-plan §2 /
    // headers.php delete): basic-auth now compiles to file-lane password-acquire
    // rules (transport "basic") enforced through the ONE access model below. The
    // `_headers` header-op lane (non-auth) stays.
    $headerRules = ['exact' => [], 'pattern' => []];
    $hasHeaderArtifact = !empty($version['header_artifact']);
    $needsHeaders = $hasHeaderArtifact && _stattic_path_concern_applies($version, 'headers', $requestPath);
    if ($needsHeaders) {
        require_once __DIR__ . '/headers.php';
        // Only the `headers` section is read: basic-auth compiles to file-lane
        // unified access rules enforced by the ONE access model (access-rules.php),
        // never a header-artifact lane. Artifacts finalized before the auth lane
        // was deleted still carry an `auth` key on disk — unknown keys are simply
        // ignored here, so those versions keep loading and serving unchanged.
        $headerRules = _stattic_load_ordered_rule_artifact($versionRoot, 'headers', 'header-artifact-missing', 'Runtime header artifact is missing.', 'headers');
    }
    $headers = (!empty($headerRules['exact']) || !empty($headerRules['pattern']))
        ? _stattic_collect_response_headers($headerRules, $requestHost, $requestPath)
        : [];
    // X-Robots-Tag is platform-managed on noindex-classed hosts; _headers cannot
    // override it there. The platform value was already sent with the host headers.
    if (isset($serving['response_headers']['X-Robots-Tag'])) {
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'x-robots-tag') {
                unset($headers[$name]);
            }
        }
    }

    if (is_array($lookupAction) && ($lookupAction['action'] ?? null) === 'invoke_zero') {
        if (!$admissionAcquired) {
            _stattic_admission_acquire_or_shed($privateRoot, $serving, 'zero');
            _stattic_admission_test_hold_if_requested();
        }
        require_once __DIR__ . '/zero.php';
        _stattic_invoke_zero($lookupAction, $versionRoot, $version, $serving, $requestHost, $requestPath, $requestUri, $requestMethod, $headers);
    }
    if (is_array($lookupAction) && ($lookupAction['action'] ?? null) === 'zero_activating') {
        require_once __DIR__ . '/zero.php';
        _stattic_zero_send_activating_response($requestMethod);
    }

    $resolved = _stattic_file_path_from_lookup_action($lookupAction);
    $versionServingConfig = is_array($version['serving_config'] ?? null) ? $version['serving_config'] : [];
    $pagePointers = is_array($versionServingConfig['pages'] ?? null) ? $versionServingConfig['pages'] : [];
    $previewArtifact = is_array($pagePointers['previews'] ?? null) ? ($pagePointers['previews'][$requestPath] ?? null) : null;
    if ($resolved !== null && is_string($previewArtifact)) {
        $headers = _stattic_add_vary_headers($headers, ['Accept', 'Sec-Fetch-Mode']);
    }
    if ($resolved === null && str_ends_with($requestPath, '/') && ($versionServingConfig['listing'] ?? false) === true) {
        $route = is_array(($pagePointers['routes'] ?? [])[$requestPath] ?? null) ? $pagePointers['routes'][$requestPath] : [];
        $artifact = $route['index'] ?? null;
        if (is_string($artifact)) {
            _stattic_load_platform_pages();
            _stattic_serve_page('index', [
                'status' => 200,
                'headers' => _stattic_cache_policy_headers($privateCache, STATTIC_DEFAULT_EDGE_CACHE_CONTROL),
                'message' => 'Directory listing',
                'customizable' => true,
                'serving' => $serving,
                'artifact' => $artifact,
                'request_path' => $requestPath,
            ]);
        }
    }
    if (
        $resolved !== null
        && ($_GET['spacefast_raw'] ?? null) !== '1'
        && ($versionServingConfig['viewer'] ?? false) === true
        && is_string($previewArtifact)
        && (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html')
            || strtolower((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')) === 'navigate')
    ) {
        _stattic_load_platform_pages();
        _stattic_serve_page('preview', [
            'status' => 200,
            'headers' => _stattic_cache_policy_headers($privateCache, STATTIC_DEFAULT_EDGE_CACHE_CONTROL),
            'message' => 'File preview',
            'customizable' => true,
            'serving' => $serving,
            'artifact' => $previewArtifact,
            'request_path' => $requestPath,
        ]);
    }
    // W7.2 Trailing-slash canonicalization for directory indexes (serving
    // correctness): when a slashless request (`/foo`) resolves to a directory
    // index file (`foo/index.html`) the runtime historically served those bytes
    // at the bare path, which breaks documents using relative links/assets (they
    // resolve against `/` instead of `/foo/`). Emit a 308 (method-preserving,
    // permanent) redirect to the slash-terminated form so the canonical directory
    // URL serves the index and relative references resolve correctly. Guarded to
    // fire ONLY when: the served path equals the originally-requested path (so
    // internal rewrites and host-route prefixes never get their target exposed),
    // the request has no trailing slash, the path is non-root, the matched action
    // is an actual 200 file, and the resolved file is the single-segment index
    // BELOW the requested path (`<lookup>/<index>`) rather than a literal file at
    // the bare path (e.g. an extensionless `foo`). The query string is preserved
    // by _stattic_emit_redirect_response. Requests already ending in `/`, literal
    // files, and non-index matches behave exactly as before.
    if (
        $resolved !== null
        && is_array($lookupAction)
        && ($lookupAction['action'] ?? null) === 'file'
        && (int) ($lookupAction['status'] ?? 0) === 200
        && $requestPath === $servedOriginalRequestPath
        && !$hasExactRedirectRuleForOriginalRequest
        && !str_ends_with($requestPath, '/')
    ) {
        $trimmedLookup = trim($lookup, '/');
        // Only the literal `<lookup>/index.html` directory index is canonicalized —
        // the form every generated directory index (including generated listing
        // pages) uses. This deliberately leaves untouched literal extensionless files
        // (`foo`), sites configured with a custom index filename (which keep serving
        // at the bare path, the prior behavior), and any non-index target a
        // php_manifest `serve_static` route maps to (e.g. `/docs` -> `docs/page.html`),
        // all of which must serve their bytes rather than redirect. The exact-path
        // equality distinguishes a true directory index from those cases.
        if ($trimmedLookup !== '' && $resolved === $trimmedLookup . '/index.html') {
            _stattic_emit_redirect_response(
                _stattic_mount_local_location($requestPath . '/', $mountPrefix),
                308,
                null,
                $privateCache
            );
        }
    }
    if ($resolved !== null) {
        _stattic_serve_file($versionRoot, $resolved, $version, $lookupAction, $headers, $routeStatus, $claimBanner, $tagPreviewToken, $variantRoute, $privateCache, !empty($serving['immutable']), _stattic_serving_content_type_policy($serving));
    }
    // W7.1 Clean URLs (extensionless `.html`) (serving correctness): many SSGs emit
    // flat `*.html` files (Hugo `uglyURLs`, Jekyll, hand-written sites) with no
    // directory-index form, so an extensionless request (`/about`) historically
    // 404'd even though `about.html` was published. When the request matched no
    // file, has no file extension (so binary/asset requests are excluded) and is
    // non-root, serve `<lookup>.html` as a 200 if it exists. Only a genuine 200
    // file action is honored — a private/not_found or redirect entry at the
    // `.html` path is ignored — and a missing `.html` simply falls through to the
    // unchanged fallback/404 chain. Access/headers were already enforced on the
    // requested URL above, exactly as they are for directory-index resolution.
    // The whole step is gated on the `cleanUrls` knob: explicit config wins,
    // and the default is ON unless a 200-status SPA fallback is configured —
    // an SPA owns its extensionless routes (spec invariant: no implicit
    // extension guessing on SPA sites), so `/page` falls through to the
    // fallback there instead of resolving `page.html`.
    if (
        $resolved === null
        && $lookup !== ''
        && pathinfo($lookup, PATHINFO_EXTENSION) === ''
        && _stattic_serving_clean_urls_enabled($version)
        // Never let a clean URL resurrect a reserved/terminal path: `/_headers`,
        // `/zero/...`, `.well-known/spacefast-runtime` (and its legacy spelling), dotfiles, and config files all
        // resolve to a terminal 404/403 and must NOT be served via a `<path>.html`
        // alias (e.g. a published `_headers.html`). The terminal check runs further
        // below for the fall-through chain; gate clean URLs on it here too.
        && !_stattic_lookup_not_found_is_terminal($lookup)
    ) {
        $cleanUrlAction = _stattic_resolve_lookup_action($version, $lookup . '.html');
        if (
            is_array($cleanUrlAction)
            && ($cleanUrlAction['action'] ?? null) === 'file'
            && (int) ($cleanUrlAction['status'] ?? 0) === 200
            && _stattic_action_allows_method($cleanUrlAction, $requestMethod)
        ) {
            $cleanUrlPath = _stattic_file_path_from_lookup_action($cleanUrlAction);
            // The resolved file MUST be the flat `<lookup>.html` file itself. A
            // literal directory named `<lookup>.html/` with an `index.html` inside
            // also keys the lookup map at `<lookup>.html` (its directory-index form),
            // so without this exact-path check `/foo` could wrongly serve
            // `foo.html/index.html` even though no flat `foo.html` file exists.
            if ($cleanUrlPath === $lookup . '.html') {
                // Security: a clean URL is an alias that returns `<lookup>.html`'s
                // bytes, so it must be no weaker a door than requesting that file
                // directly. The requested path's own access/auth was already enforced
                // above on `/foo`; here we ADDITIONALLY re-enforce the served file's
                // unified access policy and Basic-Auth on its `/foo.html` URL so exact
                // access/auth rules targeting `/foo.html` cannot be bypassed by asking
                // for `/foo`. This only adds gates — the enforce helpers render the
                // challenge and exit on denial. Response `_headers` intentionally keep
                // matching the request URL `/foo` (identical to how a directory index
                // `foo/index.html` served at `/foo` matches headers on the request
                // path, not the disk file), so removal/override semantics stay faithful
                // to the requested URL.
                $cleanServedPath = '/' . $cleanUrlPath;
                $privateCache = _stattic_enforce_access($serving, $requestHost, $cleanServedPath, $originalRequestUri) || $privateCache;
                // Carry $routeStatus (not the file action's own 200) so a notFound/404
                // rewrite targeting an extensionless path serves `<lookup>.html` with
                // the same 404 the resolved-file path above would apply — clean URLs
                // must not launder a 404 into a 200.
                _stattic_serve_file($versionRoot, $cleanUrlPath, $version, $cleanUrlAction, $headers, $routeStatus, $claimBanner, $tagPreviewToken, $variantRoute, $privateCache, !empty($serving['immutable']), _stattic_serving_content_type_policy($serving));
            }
        }
    }
    if (_stattic_lookup_not_found_is_terminal($lookup)) {
        _stattic_send_not_found_action(_stattic_resolve_not_found_action($version), $privateCache);
    }

    $fallbackAction = _stattic_resolve_fallback_action($version);
    // W7.3 SPA-asset 404 (serving correctness): a status-200 SPA index fallback is
    // an application-route fallback, NOT a catch-all asset server. A request that
    // carries a known binary/static file extension (e.g. .pdf .zip .csv .png .js
    // .css .map .wasm) and did not resolve to a real file must surface a 404 rather
    // than the SPA shell — otherwise missing assets silently return HTML with a 200,
    // corrupting downloads and masking broken references. Drop the SPA fallback for
    // such requests so they continue to the nearest-404 / platform-404 chain below.
    // Only the 200 SPA shape is affected: 404-status fallbacks already produce a
    // correct status, and non-SPA sites have no fallback. HTML-document-style
    // requests (extensionless app routes, dotted client routes like `/v1.2.3`,
    // `.html`/`.htm`) are NOT on the asset denylist and keep falling through to the
    // SPA index unchanged.
    if (
        is_array($fallbackAction)
        && ($fallbackAction['action'] ?? null) === 'fallback'
        && (int) ($fallbackAction['status'] ?? 0) === 200
        && _stattic_lookup_is_known_asset_extension($lookup)
    ) {
        $fallbackAction = null;
    }
    if (!_stattic_action_allows_method($fallbackAction, $requestMethod)) {
        _stattic_render_method_not_allowed_lazy();
    }
    $fallback = _stattic_file_path_from_lookup_action($fallbackAction);
    if ($fallback !== null) {
        _stattic_serve_file($versionRoot, $fallback, $version, $fallbackAction, $headers, _stattic_status_from_lookup_action($fallbackAction), $claimBanner, $tagPreviewToken, $variantRoute, $privateCache, !empty($serving['immutable']), _stattic_serving_content_type_policy($serving));
    }

    $nearest404Action = _stattic_find_nearest_404_action($version, $lookup);
    if (!_stattic_action_allows_method($nearest404Action, $requestMethod)) {
        _stattic_render_method_not_allowed_lazy();
    }
    $nearest404 = _stattic_file_path_from_lookup_action($nearest404Action);
    if ($nearest404 !== null) {
        _stattic_serve_file($versionRoot, $nearest404, $version, $nearest404Action, $headers, _stattic_status_from_lookup_action($nearest404Action), null, null, $variantRoute, $privateCache, !empty($serving['immutable']), _stattic_serving_content_type_policy($serving));
    }

    _stattic_send_not_found_action(_stattic_resolve_not_found_action($version), $privateCache);
}

// Resolves a tombstone page_id to the served {page_id, status, body}. The
// page_id is the source of truth (the route index resolved it from the disabled
// reason/category); page_id is mandatory — a missing page_id is a validation
// error and must not silently degrade to a generic page.
function _stattic_runtime_tombstone_page_variant(string $pageId): array
{
    $variant = SPACEFAST_TOMBSTONE_VARIANTS[$pageId] ?? null;
    if ($variant === null) {
        // Unknown page_id variant: treat as a validation error (500).
        return ['page_id' => 'error', 'status' => 500, 'body' => "Internal Server Error\n"];
    }
    return ['page_id' => $variant['page_id'], 'status' => $variant['status'], 'body' => $variant['body']];
}

function _stattic_render_platform_action(array $action, bool $privateCache = false): void
{
    $actionKind = $action['action'] ?? null;
    if ($actionKind === 'tombstone') {
        // Reason-differentiated tombstones (C10): the route index resolved the
        // disabled reason/category into a page_id; serving picks the body and
        // status from it. CSAM is a plain 404 (no signal), DMCA/copyright 451,
        // suspended tenant state 402. page_id is mandatory; a missing page_id
        // is a validation error.
        $pageId = $action['page_id'] ?? null;
        if (!is_string($pageId)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Internal Server Error\n";
            return;
        }
        $variant = _stattic_runtime_tombstone_page_variant($pageId);
        _stattic_render_platform_page_lazy($variant['page_id'], $variant['status'], [
            'Cache-Control' => (string) ($action['cache_control'] ?? STATTIC_DEFAULT_EDGE_CACHE_CONTROL),
        ], $variant['body']);
    }
    if ($actionKind === 'platform_error') {
        $headers = [
            'Cache-Control' => (string) ($action['cache_control'] ?? STATTIC_CACHE_CONTROL_NO_STORE),
        ];
        if (is_array($action['response_headers'] ?? null)) {
            foreach ($action['response_headers'] as $name => $value) {
                if (is_string($name) && is_string($value) && $name !== '' && $value !== '') {
                    $headers[$name] = $value;
                }
            }
        }
        _stattic_render_platform_page_lazy((string) ($action['page_id'] ?? 'runtime-invariant-error'), (int) ($action['status'] ?? 503), $headers, (string) ($action['message'] ?? "Runtime platform error.\n"));
    }
    if ($actionKind === 'robots_txt') {
        http_response_code(200);
        header('Content-Type: ' . (string) ($action['content_type'] ?? 'text/plain; charset=utf-8'), false);
        _stattic_send_cache_policy_headers(
            $privateCache,
            (string) ($action['cache_control'] ?? 'public, max-age=0, s-maxage=300, must-revalidate')
        );
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo (string) ($action['body'] ?? "User-agent: *\nDisallow: /\n");
        }
        exit;
    }

    _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime platform action metadata is malformed.');
}

function _stattic_runtime_api_not_found_action(): array
{
    return _stattic_platform_error_action('runtime-api-not-found', 404, "Not found.\n");
}

function _stattic_undeployed_action(): array
{
    return _stattic_platform_error_action('undeployed', 503, "This site has not been deployed yet.\n");
}

function _stattic_method_not_allowed_action(): array
{
    return _stattic_platform_error_action('method-not-allowed', 405, "Method Not Allowed\n", 'no-store', ['Allow' => 'GET, HEAD']);
}

function _stattic_send_response_headers(mixed $headers): void
{
    if (!is_array($headers)) {
        return;
    }
    foreach ($headers as $name => $value) {
        if (is_string($name) && is_string($value)) {
            header($name . ': ' . $value, false);
        }
    }
}

function _stattic_matched_host_route_action(array $route): array
{
    $action = $route['route_action'] ?? null;
    if (!is_array($action) || !is_string($action['action'] ?? null)) {
        _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime host route action metadata is missing.');
    }

    if (in_array($action['action'], ['serve', 'redirect', 'proxy', 'robots_txt', 'platform_error', 'tombstone'], true)) {
        return $action;
    }
    _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime host route action is unknown.');
}

// Shared redirect-emission tail for both the host-route and lookup redirect
// paths: append the current query string, emit the CDN cache policy then the
// Location, and exit. Callers differ only in how they derive $target.
function _stattic_emit_redirect_response(string $target, int $status, ?string $cacheControl, bool $privateCache = false): never
{
    $target = _stattic_append_current_query_to_url($target);
    _stattic_send_cache_policy_headers($privateCache, $cacheControl ?? STATTIC_DEFAULT_EDGE_CACHE_CONTROL);
    header('Location: ' . $target, true, $status);
    exit;
}

function _stattic_send_route_redirect(array $action, string $remainder, bool $privateCache = false): void
{
    $destination = (string) $action['destination'];
    $target = _stattic_append_path_to_url($destination, $remainder);
    _stattic_emit_redirect_response(
        $target,
        (int) ($action['status'] ?? 302),
        isset($action['cache_control']) ? (string) $action['cache_control'] : null,
        $privateCache
    );
}

function _stattic_send_lookup_redirect(array $action, bool $privateCache = false, ?string $mountPrefix = null): void
{
    _stattic_emit_redirect_response(
        _stattic_mount_local_location((string) $action['destination'], $mountPrefix),
        (int) ($action['status'] ?? 302),
        isset($action['cache_control']) ? (string) $action['cache_control'] : null,
        $privateCache
    );
}

function _stattic_mount_local_location(string $target, ?string $mountPrefix): string
{
    if ($mountPrefix === null || $mountPrefix === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
        return $target;
    }
    if ($target === '/') {
        return $mountPrefix . '/';
    }
    return $mountPrefix . $target;
}

function _stattic_append_query_before_fragment(string $target, string $query): string
{
    $parts = explode('#', $target, 2);
    $base = $parts[0];
    $fragment = $parts[1] ?? null;
    return $base . '?' . $query . ($fragment !== null ? '#' . $fragment : '');
}

function _stattic_append_current_query_to_url(string $target): string
{
    $requestQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($requestQuery !== '' && !str_contains($target, '?')) {
        $target = _stattic_append_query_before_fragment($target, $requestQuery);
    }

    return $target;
}

// Responses outside the static-file header planner carry the sticky access
// verdict explicitly. Pin every cache-addressed header when private: a
// CDN-specific policy must not retain an earlier public value and outrank the
// standard Cache-Control field.
function _stattic_cache_policy_headers(bool $privateCache, string $publicCacheControl): array
{
    if (!$privateCache) {
        return ['Cache-Control' => $publicCacheControl];
    }

    return [
        'Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        'CDN-Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        'Surrogate-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
    ];
}

function _stattic_send_cache_policy_headers(bool $privateCache, string $publicCacheControl): void
{
    foreach (_stattic_cache_policy_headers($privateCache, $publicCacheControl) as $name => $value) {
        header($name . ': ' . $value, true);
    }
}

function _stattic_send_not_found_action(array $action, bool $privateCache = false): void
{
    _stattic_load_platform_pages();
    $serving = is_array($GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null) ? $GLOBALS['SPACEFAST_PAGE_SERVING'] : [];
    _stattic_serve_page('404', [
        'status' => 404,
        'headers' => _stattic_cache_policy_headers(
            $privateCache,
            (string) ($action['cache_control'] ?? STATTIC_DEFAULT_EDGE_CACHE_CONTROL)
        ),
        'message' => 'Not Found',
        'code' => 'not_found',
        'customizable' => $serving !== [],
        'serving' => $serving,
        'request_path' => _stattic_runtime_request_path(),
    ]);
}

function _stattic_action_allows_method(?array $action, string $requestMethod): bool
{
    if (!is_array($action)) {
        return true;
    }
    if (!array_key_exists('methods', $action)) {
        return true;
    }

    return is_array($action['methods']) && in_array($requestMethod, $action['methods'], true);
}

function _stattic_lookup_not_found_is_terminal(string $lookup): bool
{
    $path = trim(strtolower($lookup), '/');
    if ($path === '' || $path === '.well-known/spacefast-runtime' || $path === '.well-known/stattic-runtime' || $path === 'zero' || str_starts_with($path, 'zero/')) {
        return true;
    }

    if (isset(SPACEFAST_PRIVATE_COMPILE_FILES[$path]) || isset(SPACEFAST_PRIVATE_CONFIG_FILES[$path])) {
        return true;
    }

    if (_stattic_path_has_hidden_segment($path)) {
        return true;
    }
    return _stattic_lookup_is_static_asset_path($path);
}

function _stattic_lookup_is_static_asset_path(string $path): bool
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === '') {
        return false;
    }

    return isset([
        'avif' => true,
        'bmp' => true,
        'br' => true,
        'css' => true,
        'eot' => true,
        'gif' => true,
        'gz' => true,
        'ico' => true,
        'jpeg' => true,
        'jpg' => true,
        'js' => true,
        'json' => true,
        'map' => true,
        'mjs' => true,
        'mp3' => true,
        'mp4' => true,
        'ogg' => true,
        'otf' => true,
        'png' => true,
        'svg' => true,
        'ttf' => true,
        'wasm' => true,
        'webm' => true,
        'webmanifest' => true,
        'webp' => true,
        'woff' => true,
        'woff2' => true,
        'xml' => true,
    ][$extension]);
}

// SPA-fallback asset denylist (W7.3): extends the terminal static-asset set above
// with the common downloadable/binary/document extensions that are NOT HTML
// documents (archives, PDF/office docs, media, data dumps, installers). A missing
// path carrying one of these extensions must 404 rather than receive the SPA index
// shell. This is intentionally a denylist (not "any extension present") so dotted
// client-side routes such as `/users/jane.doe` or `/v1.2.3` stay SPA-eligible. The
// already-terminal static extensions are folded in via the reuse below so the two
// sets cannot drift.
function _stattic_lookup_is_known_asset_extension(string $path): bool
{
    if (_stattic_lookup_is_static_asset_path($path)) {
        return true;
    }
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === '') {
        return false;
    }

    return isset([
        // Documents
        'pdf' => true, 'csv' => true, 'rtf' => true, 'txt' => true,
        'doc' => true, 'docx' => true, 'xls' => true, 'xlsx' => true,
        'ppt' => true, 'pptx' => true, 'odt' => true, 'ods' => true,
        'odp' => true, 'epub' => true,
        // Archives
        'zip' => true, 'tar' => true, 'tgz' => true, 'rar' => true,
        '7z' => true, 'bz2' => true, 'xz' => true, 'zst' => true,
        // Media beyond the precompressed/static image+a/v set
        'wav' => true, 'flac' => true, 'aac' => true, 'm4a' => true,
        'm4v' => true, 'mov' => true, 'avi' => true, 'mkv' => true,
        'weba' => true, 'oga' => true, 'ogv' => true, 'opus' => true,
        'wmv' => true, 'flv' => true, 'mpg' => true, 'mpeg' => true,
        'm3u8' => true, 'tif' => true, 'tiff' => true, 'heic' => true,
        'heif' => true, 'jxl' => true,
        // Data / config dumps
        'yaml' => true, 'yml' => true, 'toml' => true, 'sql' => true,
        'ndjson' => true, 'jsonl' => true, 'geojson' => true,
        'ics' => true, 'vcf' => true,
        // Installers / binaries
        'exe' => true, 'dmg' => true, 'pkg' => true, 'deb' => true,
        'rpm' => true, 'apk' => true, 'msi' => true, 'iso' => true,
        'bin' => true, 'appimage' => true,
    ][$extension]);
}

function _stattic_resolve_request_lookup_action(string $versionRoot, array $version, string $lookup, string $requestMethod): ?array
{
    if (!empty($version['php_manifest'])) {
        require_once __DIR__ . '/php-manifest.php';
        $phpManifest = _stattic_load_php_manifest_artifact($versionRoot);
        if (is_array($phpManifest)) {
            $manifestResult = _stattic_php_manifest_lookup_result($phpManifest, $lookup, $requestMethod);
            if (!empty($manifestResult['method_not_allowed'])) {
                _stattic_render_method_not_allowed_lazy();
            }
            $manifestAction = $manifestResult['action'] ?? null;
            if (is_array($manifestAction)) {
                return $manifestAction;
            }
        }
    }

    // A PHP-runner finalize can carry exact endpoints only in zero/routes.php.
    // Resolve that method-aware exact bucket before the normal file lookup so a
    // committed file at the same path cannot steal endpoint or activating-stub
    // precedence when php-manifest.php is unavailable.
    if (!empty($version['zero_routes'])) {
        require_once __DIR__ . '/zero-routes.php';
        $exactZeroResult = _stattic_resolve_zero_route_action($versionRoot, $version, $lookup, $requestMethod, true);
        if (!empty($exactZeroResult['method_not_allowed'])) {
            _stattic_render_method_not_allowed_lazy();
        }
        $exactZeroAction = $exactZeroResult['action'] ?? null;
        if (is_array($exactZeroAction)) {
            return $exactZeroAction;
        }
    }

    $lookupAction = _stattic_resolve_lookup_action($version, $lookup);
    if (is_array($lookupAction) || empty($version['zero_routes'])) {
        return $lookupAction;
    }

    require_once __DIR__ . '/zero-routes.php';
    $zeroRouteResult = _stattic_resolve_zero_route_action($versionRoot, $version, $lookup, $requestMethod);
    if (!empty($zeroRouteResult['method_not_allowed'])) {
        _stattic_render_method_not_allowed_lazy();
    }
    $zeroAction = $zeroRouteResult['action'] ?? null;
    if ($zeroAction !== null && !is_array($zeroAction)) {
        _stattic_render_runtime_invariant_error_lazy('zero-route-metadata-missing', 'Runtime Zero route metadata is malformed.');
    }

    return $zeroAction;
}

function _stattic_resolve_configured_index_action(array $version, string $lookup, bool $isDirectoryRequest): ?array
{
    $trimmed = trim($lookup, '/');
    if ($trimmed !== '' && !$isDirectoryRequest) {
        return null;
    }
    if ($trimmed !== '' && _stattic_lookup_not_found_is_terminal($lookup)) {
        return null;
    }
    $servingConfig = $version['serving_config'] ?? null;
    if (!is_array($servingConfig)) {
        _stattic_render_runtime_invariant_error_lazy('lookup-metadata-missing', 'Runtime serving config metadata is missing.');
    }
    $index = $servingConfig['index'] ?? null;
    if ($index === false) {
        // Artifacts finalized before intake normalized `index: false` away
        // still carry the literal false; it always meant the default.
        $index = 'index.html';
    }
    if (!is_string($index) || $index === '' || str_contains($index, '/') || str_contains($index, '\\')) {
        return null;
    }

    $candidate = $trimmed === '' ? $index : $trimmed . '/' . $index;
    $action = _stattic_resolve_lookup_action($version, $candidate);
    if (
        is_array($action)
        && ($action['action'] ?? null) === 'file'
        && (int) ($action['status'] ?? 0) === 200
        && _stattic_file_path_from_lookup_action($action) === $candidate
    ) {
        return $action;
    }

    return null;
}

function _stattic_path_concern_applies(array $version, string $concern, string $requestPath): bool
{
    $concerns = $version['concerns'] ?? null;
    if (!is_array($concerns) || !is_array($concerns[$concern] ?? null)) {
        _stattic_render_runtime_invariant_error_lazy('concern-metadata-missing', 'Runtime concern metadata is missing.');
    }

    $section = $concerns[$concern];
    if (!empty($section['pattern'])) {
        return true;
    }

    $exact = $section['exact'] ?? null;
    if (!is_array($exact)) {
        _stattic_render_runtime_invariant_error_lazy('concern-metadata-missing', 'Runtime concern metadata is missing.');
    }

    return isset($exact[$requestPath]);
}

function _stattic_load_platform_pages(): void
{
    require_once __DIR__ . '/../shared/errors.php';
}

function _stattic_render_platform_page_lazy(string $pageId, int $status, array $headers = [], string $fallback = ''): void
{
    _stattic_load_platform_pages();
    _stattic_render_platform_page($pageId, $status, $headers, $fallback);
}

function _stattic_render_method_not_allowed_lazy(): void
{
    _stattic_render_platform_action(_stattic_method_not_allowed_action());
}

function _stattic_render_runtime_invariant_error_lazy(string $code, string $message): void
{
    _stattic_load_platform_pages();
    _stattic_render_runtime_invariant_error($code, $message);
}

function _stattic_join_request_path(string $prefix, string $remainder): string
{
    $normalizedPrefix = $prefix === '' ? '/' : $prefix;
    $normalizedPrefix = '/' . trim($normalizedPrefix, '/');
    if ($normalizedPrefix === '//') {
        $normalizedPrefix = '/';
    }

    $normalizedRemainder = $remainder === '/' ? '' : ltrim($remainder, '/');
    if ($normalizedPrefix === '/') {
        return $normalizedRemainder === '' ? '/' : '/' . $normalizedRemainder;
    }

    return $normalizedRemainder === '' ? $normalizedPrefix : $normalizedPrefix . '/' . $normalizedRemainder;
}

function _stattic_append_path_to_url(string $baseUrl, string $remainder): string
{
    $parts = parse_url($baseUrl);
    if (!is_array($parts)) {
        return $baseUrl;
    }

    $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
    $authority = $parts['host'] ?? '';
    if (isset($parts['port'])) {
        $authority .= ':' . $parts['port'];
    }
    if (isset($parts['user'])) {
        $userinfo = $parts['user'];
        if (isset($parts['pass'])) {
            $userinfo .= ':' . $parts['pass'];
        }
        $authority = $userinfo . '@' . $authority;
    }

    $basePath = $parts['path'] ?? '/';
    $path = _stattic_join_request_path($basePath, $remainder);
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $authority . $path . $query . $fragment;
}

// All access control (space passwords included — they compile into THE unified
// `policy.rules` as a password challenge rule) is enforced through the one
// access model (packages/common/src/contracts/access.ts). The compiler delivers
// a single `policy` document of unified Rules the runtime evaluates
// first-match-wins; an absent/empty policy means no access control.
function _stattic_enforce_access(array $serving, string $requestHost, string $requestPath, string $requestUri): bool
{
    // Request marker read by _stattic_enforce_access_for_proxy: normal publish
    // requests cross the terminal-routing boundary before any proxy dispatch.
    $GLOBALS['SPACEFAST_ACCESS_ENFORCED'] = true;
    if (!empty($serving['policy']['rules'])) {
        require_once __DIR__ . '/access-rules.php';
        $pathProtected = _stattic_enforce_unified_access_rules($serving, $requestHost, $requestPath, $requestUri);
        if ($pathProtected) {
            // Read by the proxy cache relay (runtime/proxy.php): a proxied
            // response on a non-public path must never enter a shared cache.
            // Sticky for the request — protection on any evaluated path
            // (original or rewritten) pins it.
            _spacefast_access_private_cache_flag(true);
        }
        return $pathProtected;
    }
    return false;
}

function _stattic_enforce_access_path(
    string $privateRoot,
    array $serving,
    string $requestHost,
    string $requestPath,
    string $requestUri,
    bool &$admissionAcquired,
    bool $privateCache
): bool {
    if (
        !$admissionAcquired
        && _stattic_request_needs_admission_for_access($serving, $requestHost, $requestPath)
    ) {
        _stattic_admission_acquire_or_shed($privateRoot, $serving, 'access_rule');
        $admissionAcquired = true;
        _stattic_admission_test_hold_if_requested();
    }

    return _stattic_enforce_access($serving, $requestHost, $requestPath, $requestUri) || $privateCache;
}


function _stattic_request_needs_admission_for_access(array $serving, string $requestHost, string $requestPath): bool
{
    $policy = is_array($serving['policy'] ?? null) ? $serving['policy'] : [];
    $rules = is_array($policy['rules'] ?? null) ? $policy['rules'] : [];
    if ($rules === [] || _stattic_runtime_request_method() === 'OPTIONS') {
        return false;
    }
    require_once __DIR__ . '/access-rules.php';
    $context = _spacefast_access_context($serving, $requestHost, $requestPath);
    // Delegates the actual "does any live rule match" scan to access-rules.php
    // (_stattic_access_rules_match_request) so this admission pre-check can
    // never drift from the enforcer's own rule-matching semantics.
    return _stattic_access_rules_match_request($rules, $context, time());
}

// Proxy dispatches normally inherit the unified access decision made at the
// terminal-routing boundary. Keep this fallback for callers outside the main
// serve pipeline: a challenge/deny exits here, while a verified-token allow
// populates the §3.2 identity-forwarding seam consumed by runtime/proxy.php.
function _stattic_enforce_access_for_proxy(array $serving, string $requestHost, string $requestPath, string $requestUri): void
{
    if (!empty($GLOBALS['SPACEFAST_ACCESS_ENFORCED'])) {
        return;
    }
    _stattic_enforce_access($serving, $requestHost, $requestPath, $requestUri);
}

// Expiry-rescue countdown banner (spec "Version retention"): visitors holding the
// claim-link viewer session see a countdown on unclaimed anonymous spaces. The session
// is marked by the spacefast_claim_view query parameter (the claim page links with it)
// and persisted in the short-lived spacefast_claim_view cookie (access-plan §3.3);
// banner responses are never edge-cached.
function _stattic_claim_banner_context(array $serving, string $requestMethod): ?array
{
    $expiresAt = $serving['anonymous_expires_at'] ?? null;
    if (!is_string($expiresAt) || $expiresAt === '' || $requestMethod !== 'GET') {
        return null;
    }
    $hasCookie = isset($_COOKIE[SPACEFAST_CLAIM_VIEW_COOKIE]) && $_COOKIE[SPACEFAST_CLAIM_VIEW_COOKIE] === '1';
    $hasParam = isset($_GET[SPACEFAST_CLAIM_VIEW_COOKIE]) && (string) $_GET[SPACEFAST_CLAIM_VIEW_COOKIE] === '1';
    if (!$hasCookie && !$hasParam) {
        return null;
    }
    $expiresEpoch = strtotime($expiresAt);
    if ($expiresEpoch === false) {
        return null;
    }
    if (!$hasCookie) {
        _spacefast_set_cookie(SPACEFAST_CLAIM_VIEW_COOKIE, '1', 3600);
    }
    return ['expires_epoch' => $expiresEpoch];
}

// Generic serve-time content-type allowlist (route-config `content_types`):
// the engine enforces whatever list the control plane pushed for this space —
// exact types or `prefix/*` wildcards — with no knowledge of why the space is
// restricted. Absent policy (the default) means no restriction. Matching runs
// against the STORED Content-Type: metadata is the single serve-time truth
// (X-Content-Type-Options: nosniff), so an opaque octet-stream can never
// smuggle past the list by way of its bytes.
function _stattic_serving_content_type_policy(array $serving): ?array
{
    $policy = $serving['content_types'] ?? null;
    return is_array($policy) && is_array($policy['allowed'] ?? null) ? $policy : null;
}

function _stattic_content_type_allowlist_permits(array $policy, string $contentType): bool
{
    $normalized = strtolower(trim(explode(';', $contentType, 2)[0]));
    foreach ($policy['allowed'] as $pattern) {
        if (!is_string($pattern) || $pattern === '') {
            continue;
        }
        $pattern = strtolower($pattern);
        if (str_ends_with($pattern, '/*')) {
            // "text/*" permits every type under the "text/" prefix.
            if (str_starts_with($normalized, substr($pattern, 0, -1))) {
                return true;
            }
        } elseif ($normalized === $pattern) {
            return true;
        }
    }
    return false;
}

function _stattic_render_content_type_blocked(array $policy): void
{
    // no-store: the policy clears on the next route-config push (e.g. when an
    // anonymous space is claimed), so this response must never outlive it in
    // a shared cache.
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $message = is_string($policy['blocked_message'] ?? null) && $policy['blocked_message'] !== ''
        ? $policy['blocked_message']
        : 'This file type is not served on this site.';
    echo $message . "\n";
    exit;
}

function _stattic_spacefast_tag_preview_token(string $requestMethod): ?string
{
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        return null;
    }
    if (isset($_GET[SPACEFAST_TAG_PREVIEW_QUERY_NAME]) && is_string($_GET[SPACEFAST_TAG_PREVIEW_QUERY_NAME]) && trim($_GET[SPACEFAST_TAG_PREVIEW_QUERY_NAME]) !== '') {
        return trim($_GET[SPACEFAST_TAG_PREVIEW_QUERY_NAME]);
    }
    return null;
}

function _stattic_apply_spacefast_sdk_preview_to_html(string $body, string $token): string
{
    $path = preg_quote(STATTIC_SPACEFAST_SDK_PATH, '~');
    return (string) preg_replace_callback(
        '~(<script\b[^>]*\bsrc=)(["\'])([^"\']*' . $path . '(?:\?[^"\']*)?)(\2[^>]*>)~i',
        static function (array $matches) use ($token): string {
            return $matches[1] . $matches[2] . _stattic_url_with_query_param($matches[3], 'preview', $token) . $matches[4];
        },
        $body
    );
}

function _stattic_url_with_query_param(string $url, string $name, string $value): string
{
    $fragment = '';
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
        $fragment = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . rawurlencode($name) . '=' . rawurlencode($value) . $fragment;
}

function _stattic_claim_banner_html(int $expiresEpoch): string
{
    return '<div id="stattic-claim-banner" data-expires="' . $expiresEpoch . '" style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;background:#1d2327;color:#fff;font:14px/1.4 system-ui,sans-serif;padding:10px 16px;text-align:center">'
        . 'This site has not been claimed yet. It expires in <span id="stattic-claim-countdown">…</span> — open your claim link to keep it.'
        . '</div>'
        . '<script>(function(){var el=document.getElementById("stattic-claim-countdown");var until=Number(document.getElementById("stattic-claim-banner").getAttribute("data-expires"))*1000;function tick(){var ms=until-Date.now();if(ms<=0){el.textContent="moments";return;}var h=Math.floor(ms/3600000);var m=Math.floor(ms%3600000/60000);el.textContent=h>0?h+"h "+m+"m":m+"m";setTimeout(tick,30000);}tick();})();</script>';
}

// Template variant overlay (spec "Per-channel values"): when the matched host
// serves through a named route and the version compiled a variant of this
// template path for that route, the variant's bytes and metadata replace the
// base file. Returns null when no variant applies; a flagged-but-missing
// artifact is an invariant break like any other artifact.
function _stattic_template_variant_meta(string $versionRoot, array $version, string $variantRoute, string $path): ?array
{
    if (empty($version['template_variants'])) {
        return null;
    }
    static $artifacts = [];
    $artifactPath = dirname($versionRoot) . '/template-variants.php';
    if (!array_key_exists($artifactPath, $artifacts)) {
        $loaded = @include $artifactPath;
        if (!is_array($loaded) || !_stattic_runtime_artifact_metadata_valid_lazy($loaded) || !is_array($loaded['routes'] ?? null)) {
            _stattic_render_runtime_invariant_error_lazy('template-variants-artifact-missing', 'Runtime template variant artifact is missing.');
        }
        $artifacts[$artifactPath] = $loaded;
    }
    $meta = $artifacts[$artifactPath]['routes'][$variantRoute][$path] ?? null;
    return is_array($meta) ? $meta : null;
}

function _stattic_tier_remote_locator(array $meta): ?array
{
    $remote = $meta['remote'] ?? null;
    if (!is_array($remote) || !is_string($remote['bucket'] ?? null) || !is_string($remote['key'] ?? null)) {
        return null;
    }
    return $remote;
}

function _stattic_tier_parse_single_range(?string $rangeHeader, int $size): ?array
{
    if ($rangeHeader === null || trim($rangeHeader) === '' || str_contains($rangeHeader, ',')) {
        return null;
    }
    if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches) !== 1) {
        return null;
    }
    $rawStart = $matches[1];
    $rawEnd = $matches[2];
    if ($rawStart === '' && $rawEnd === '') {
        return null;
    }
    if ($rawStart === '') {
        $suffix = (int) $rawEnd;
        if ($suffix <= 0 || $size <= 0) {
            return ['unsatisfiable' => true];
        }
        $start = max(0, $size - $suffix);
        $end = $size - 1;
    } else {
        $start = (int) $rawStart;
        $end = $rawEnd === '' ? $size - 1 : min((int) $rawEnd, $size - 1);
    }
    if ($start >= $size || $start > $end || $size < 0) {
        return ['unsatisfiable' => true];
    }
    return ['start' => $start, 'end' => $end, 'header' => 'bytes=' . $start . '-' . $end];
}

function _stattic_tier_send_416(array $headers, int $size): void
{
    _stattic_send_file_headers($headers);
    header('Content-Range: bytes */' . $size);
    header('Accept-Ranges: bytes');
    header('Content-Length: 0');
    http_response_code(416);
    exit;
}

// Remote (tiered) serving entrypoints. The heavy machinery — SigV4 client,
// per-bucket breaker, in-flight cap, bucket-manifest config — lives in
// tier.php and loads LAZILY here so the local hot path never parses it
// (same convention as the proxy.php include below).
function _stattic_serve_remote_stream(string $privateRoot, array $servedMeta, array $headers, int $status, int $size, ?array $range, string $spaceId, string $path): void
{
    require_once __DIR__ . '/tier.php';
    _stattic_tier_stream_remote($privateRoot, $servedMeta, $headers, $status, $size, $range, $spaceId, $path);
}

function _stattic_serve_remote_body(string $privateRoot, array $servedMeta, int $size): string
{
    require_once __DIR__ . '/tier.php';
    return _stattic_tier_read_remote_body($privateRoot, $servedMeta, $size);
}

function _stattic_serve_file(string $versionRoot, string $path, array $version, array $lookupAction, array $responseHeaders = [], int $status = 200, ?array $claimBanner = null, ?string $tagPreviewToken = null, ?string $variantRoute = null, bool $privateCache = false, bool $immutableHost = false, ?array $contentTypePolicy = null): void
{
    $variantMeta = $variantRoute !== null
        ? _stattic_template_variant_meta($versionRoot, $version, $variantRoute, $path)
        : null;
    $diskRoot = is_array($variantMeta)
        ? dirname($versionRoot) . '/files-variants/' . $variantRoute
        : $versionRoot;
    $meta = is_array($variantMeta)
        ? $variantMeta
        : _stattic_load_file_metadata($versionRoot, $path, $lookupAction, $version);
    if (
        !is_string($meta['disk_path'] ?? null) ||
        $meta['disk_path'] !== $path ||
        !isset($meta['size']) ||
        !is_array($meta['headers'] ?? null) ||
        !is_string($meta['headers']['Content-Type'] ?? null) ||
        !is_string($meta['headers']['ETag'] ?? null) ||
        !is_string($meta['headers']['Cache-Control'] ?? null) ||
        !is_string($meta['headers']['Last-Modified'] ?? null)
    ) {
        _stattic_render_runtime_invariant_error_lazy('file-metadata-missing', 'Runtime file metadata is incomplete.');
    }
    if ($contentTypePolicy !== null && !_stattic_content_type_allowlist_permits($contentTypePolicy, (string) $meta['headers']['Content-Type'])) {
        _stattic_render_content_type_blocked($contentTypePolicy);
    }
    $requestMethod = _stattic_runtime_request_method();
    $rangeHeader = $requestMethod === 'GET' && is_string($_SERVER['HTTP_RANGE'] ?? null)
        ? (string) $_SERVER['HTTP_RANGE']
        : null;
    if (
        $rangeHeader !== null
        && is_string($_SERVER['HTTP_IF_RANGE'] ?? null)
        && !_stattic_if_range_matches(
            (string) $_SERVER['HTTP_IF_RANGE'],
            (string) $meta['headers']['ETag'],
            (string) $meta['headers']['Last-Modified']
        )
    ) {
        $rangeHeader = null;
    }
    $selectedMeta = _stattic_select_precompressed_file_meta(
        $meta,
        $requestMethod,
        $status,
        $claimBanner,
        $privateCache,
        !empty($lookupAction['forced_download_or_text']) || !empty($meta['forced_download_or_text']),
        $rangeHeader !== null
    );
    if ($selectedMeta === false) {
        _stattic_render_not_acceptable($requestMethod);
    }
    $servedMeta = is_array($selectedMeta) ? $selectedMeta : $meta;
    $size = (int) $servedMeta['size'];
    $diskPath = $diskRoot . '/' . (string) $servedMeta['disk_path'];
    // The realpath'd private root is only needed by the remote-tier and
    // fopen-failure branches below, every one of which exits — derive it there
    // instead of paying the realpath() on every local file serve.
    $spaceId = (string) ($version['space_id'] ?? '');

    $headers = $servedMeta['headers'];
    if ($status >= 400) {
        $headers['Cache-Control'] = STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
    }
    if ($immutableHost && $status === 200 && !isset(SPACEFAST_NON_IMMUTABLE_EXTENSIONS[_stattic_policy_extension($path)])) {
        // Version-pinned hosts embed the version id in the hostname, so a URL's
        // bytes can never change out from under a cache — every non-HTML file is
        // safely browser-immutable regardless of its path or filename. User
        // `_headers` rules (below) and the private-cache/HTML-transform
        // downgrades (later) still win over this default.
        $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
    }
    foreach ($responseHeaders as $name => $value) {
        if (strtolower((string) $name) === 'vary') {
            $headers = _stattic_add_vary_headers($headers, explode(',', (string) $value));
            continue;
        }
        $headers[$name] = $value;
    }
    if ($privateCache) {
        // Edge-cache discipline (access-plan X-21): an allowed response on a
        // path covered by a non-public rule must never sit in a shared cache.
        $headers['Cache-Control'] = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
    }
    // A fallback file can answer an unbounded set of request URLs, and an
    // error response can occupy a URL that a later deploy creates. Neither
    // class has an enumerable purge surface, so shared caches must age them
    // out quickly. Explicit private/no-store/no-cache rules remain stricter.
    $boundedSyntheticResponse = $status >= 400 || ($lookupAction['action'] ?? null) === 'fallback';
    $cacheControl = $headers['Cache-Control'] ?? null;
    if (
        $boundedSyntheticResponse
        && is_string($cacheControl)
        && _stattic_cache_control_allows_shared_store($cacheControl)
    ) {
        $headers['Cache-Control'] = STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
    }
    $headers = _stattic_file_response_shared_cache_headers(
        $headers,
        $boundedSyntheticResponse ? 60 : 31536000
    );
    $headers['X-Content-Type-Options'] = 'nosniff';
    if (!empty($lookupAction['forced_download_or_text'])) {
        $headers['Content-Type'] = (string) $meta['headers']['Content-Type'];
    }
    $etag = (string) $headers['ETag'];
    $lastModified = (string) $headers['Last-Modified'];

    // Conditional requests (RFC 7232). Dynamic HTML transforms (claim banner /
    // tag preview) rewrite the body per-request, so they must never validate
    // against (or land in) any cache — their conditional handling stays disabled
    // exactly as before. Only GET/HEAD reach this point (other methods were
    // gated to 405 upstream), so a 304 is only ever produced for safe methods.
    $conditionalEligible = $claimBanner === null && $tagPreviewToken === null;
    if ($conditionalEligible && isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
        // If-None-Match is present: it takes precedence over If-Modified-Since,
        // which RFC 7232 §3.3 requires be ignored in that case. A match — weak
        // comparison over the (possibly comma-separated) client tag list, or the
        // `*` any-representation form — yields a 304 carrying the same cache
        // headers and validators; a miss falls through to the normal 200 without
        // consulting If-Modified-Since (so a changed ETag is never masked by a
        // still-matching date).
        if (_stattic_if_none_match_matches((string) $_SERVER['HTTP_IF_NONE_MATCH'], $etag)) {
            _stattic_send_file_headers($headers);
            http_response_code(304);
            exit;
        }
    } elseif ($conditionalEligible && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        // No If-None-Match: honor If-Modified-Since. The exact-echo of our
        // Last-Modified is the fast path; a client date at or after the file's
        // Last-Modified also validates (date comparison), and any unparseable
        // date falls through to a normal 200.
        if (_stattic_if_modified_since_allows_304((string) $_SERVER['HTTP_IF_MODIFIED_SINCE'], $lastModified)) {
            _stattic_send_file_headers($headers);
            http_response_code(304);
            exit;
        }
    }

    // Dynamic HTML overlays are request-time transforms and skip range
    // handling. Claim countdowns are cookie-specific and private; tag previews
    // are token-query-keyed and can be shared briefly at the edge.
    if (
        (is_array($claimBanner) || $tagPreviewToken !== null)
        && $status === 200
        && str_starts_with(strtolower((string) $headers['Content-Type']), 'text/html')
        && $size <= 2097152
    ) {
        unset($headers['ETag'], $headers['Last-Modified']);
        $headers['Cache-Control'] = $privateCache
            ? STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE
            : (is_array($claimBanner) ? STATTIC_CACHE_CONTROL_NO_STORE : STATTIC_DEFAULT_EDGE_CACHE_CONTROL);
        if ($requestMethod === 'HEAD') {
            // HEAD must stay metadata-only (claim banner is GET-only, gated
            // in _stattic_claim_banner_context — only the tag-preview-token
            // path reaches HEAD here). Answer from the already-known shard
            // size/headers WITHOUT fetching the body first: fetching it only
            // to discard it dialed the bucket for a tiered file even though
            // HEAD never sends a body (confirmed finding F6).
            _stattic_send_file_headers($headers);
            header('Content-Length: ' . $size);
            http_response_code(200);
            exit;
        }
        $body = ($servedMeta['local'] ?? true) !== false
            ? _stattic_read_file_body($diskPath, $size, _stattic_tier_remote_locator($servedMeta) !== null ? $servedMeta : null, $versionRoot)
            : _stattic_serve_remote_body(_stattic_runtime_real_private_root($versionRoot), $servedMeta, $size);
        if (is_array($claimBanner)) {
            $banner = _stattic_claim_banner_html((int) $claimBanner['expires_epoch']);
            $closing = strripos($body, '</body>');
            $body = $closing === false ? $body . $banner : substr_replace($body, $banner, $closing, 0);
        }
        if ($tagPreviewToken !== null) {
            $body = _stattic_apply_spacefast_sdk_preview_to_html($body, $tagPreviewToken);
        }
        _stattic_send_file_headers($headers);
        header('Content-Length: ' . strlen($body));
        http_response_code(200);
        echo $body;
        exit;
    }

    if ($requestMethod === 'HEAD') {
        _stattic_send_file_headers($headers);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $size);
        http_response_code($status);
        exit;
    }

    $range = $requestMethod === 'GET' ? _stattic_tier_parse_single_range($rangeHeader, $size) : null;
    if (is_array($range) && !empty($range['unsatisfiable'])) {
        _stattic_tier_send_416($headers, $size);
    }

    // Streaming tail, shared by the range (206) and full-body (200) GET paths:
    // stream the file in 64KB chunks instead of buffering the whole object into
    // a PHP string first. Buffering a large object (video/zip/pdf) allocated the
    // entire file in the FPM worker — risking memory_limit/OOM and turning TTFB
    // into time-to-last-byte. Content-Length is the known metadata size
    // (identical to what the HEAD branch advertises), so the streamed byte count
    // matches. The in-place HTML-transform branch above keeps buffering because
    // it genuinely needs the mutated string.
    if (($servedMeta['local'] ?? true) === false) {
        _stattic_serve_remote_stream(_stattic_runtime_real_private_root($versionRoot), $servedMeta, $headers, $status, $size, $range, $spaceId, $path);
    }
    $stream = fopen($diskPath, 'rb');
    if ($stream === false) {
        if (_stattic_tier_remote_locator($servedMeta) !== null) {
            _stattic_serve_remote_stream(_stattic_runtime_real_private_root($versionRoot), $servedMeta, $headers, $status, $size, $range, $spaceId, $path);
        }
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime file metadata points to a missing file.');
    }
    _stattic_send_file_headers($headers);
    if (is_array($range)) {
        $start = (int) $range['start'];
        $end = (int) $range['end'];
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        http_response_code(206);
        fseek($stream, $start);
        _stattic_stream_file($stream, $end - $start + 1);
    } else {
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $size);
        http_response_code($status);
        _stattic_stream_file($stream, $size);
    }
    fclose($stream);
    exit;
}

function _stattic_read_file_body(string $diskPath, int $size, ?array $remoteMeta = null, ?string $versionRoot = null): string
{
    $stream = fopen($diskPath, 'rb');
    if ($stream === false) {
        if ($remoteMeta !== null && $versionRoot !== null) {
            return _stattic_serve_remote_body(_stattic_runtime_real_private_root($versionRoot), $remoteMeta, $size);
        }
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime file metadata points to a missing file.');
    }
    $body = stream_get_contents($stream, $size);
    fclose($stream);
    if (!is_string($body)) {
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime file bytes could not be read.');
    }
    return $body;
}

function _stattic_select_precompressed_file_meta(array $meta, string $requestMethod, int $status, ?array $claimBanner, bool $privateCache, bool $forcedDownloadOrText, bool $rangeRequested): array|false|null
{
    $compressionEligible = !(
        $status !== 200
        || $claimBanner !== null
        || $privateCache
        || $forcedDownloadOrText
        || !in_array($requestMethod, ['GET', 'HEAD'], true)
        || $rangeRequested
        || !is_array($meta['compressed'] ?? null)
    );

    $acceptEncoding = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    $representations = [];
    if ($compressionEligible) {
        foreach (['br', 'gzip'] as $priority => $encoding) {
        $candidate = $meta['compressed'][$encoding] ?? null;
        if (
            is_array($candidate)
            && is_string($candidate['disk_path'] ?? null)
            && isset($candidate['size'])
            && is_array($candidate['headers'] ?? null)
        ) {
                $representations[] = [
                    'quality' => _stattic_content_encoding_quality($acceptEncoding, $encoding),
                    'priority' => $priority,
                    'meta' => $candidate,
                ];
            }
        }
    }
    $representations[] = [
        'quality' => _stattic_content_encoding_quality($acceptEncoding, 'identity'),
        'priority' => 2,
        'meta' => null,
    ];
    usort($representations, static function (array $left, array $right): int {
        $quality = $right['quality'] <=> $left['quality'];
        return $quality !== 0 ? $quality : $left['priority'] <=> $right['priority'];
    });
    $selected = $representations[0] ?? null;
    if (!is_array($selected) || (float) $selected['quality'] <= 0.0) {
        return false;
    }
    return is_array($selected['meta'] ?? null) ? $selected['meta'] : null;
}

function _stattic_content_encoding_quality(string $header, string $encoding): float
{
    if (trim($header) === '') {
        return $encoding === 'identity' ? 1.0 : 0.0;
    }

    $wildcardQuality = null;
    $qualities = [];
    foreach (explode(',', strtolower($header)) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $segments = array_map('trim', explode(';', $part));
        $token = array_shift($segments);
        $quality = 1.0;
        foreach ($segments as $segment) {
            if (preg_match('/^q\\s*=\\s*(0(?:\\.\\d{0,3})?|1(?:\\.0{0,3})?)$/', $segment, $qualityMatch) === 1) {
                $parsed = (float) $qualityMatch[1];
                $quality = max(0.0, min(1.0, $parsed));
            } elseif (preg_match('/^q\\s*=/i', $segment) === 1) {
                $quality = 0.0;
            }
        }
        if ($token === '*') {
            $wildcardQuality = $quality;
        } elseif (is_string($token) && $token !== '') {
            $qualities[$token] = $quality;
        }
    }
    if (isset($qualities[$encoding])) {
        return (float) $qualities[$encoding];
    }
    if ($encoding === 'identity') {
        return $wildcardQuality === 0.0 ? 0.0 : 1.0;
    }
    return $wildcardQuality !== null ? (float) $wildcardQuality : 0.0;
}

function _stattic_if_range_matches(string $ifRange, string $etag, string $lastModified): bool
{
    $ifRange = trim($ifRange);
    if ($ifRange === '' || str_starts_with($ifRange, 'W/')) {
        return false;
    }
    if (str_starts_with($ifRange, '"')) {
        return !str_starts_with(trim($etag), 'W/') && hash_equals(trim($etag), $ifRange);
    }
    $requestTime = strtotime($ifRange);
    $modifiedTime = strtotime($lastModified);
    return $requestTime !== false && $modifiedTime !== false && $requestTime >= $modifiedTime;
}

function _stattic_render_not_acceptable(string $requestMethod): never
{
    $body = "Not Acceptable\n";
    http_response_code(406);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Vary: Accept-Encoding');
    header('Content-Length: ' . strlen($body));
    if ($requestMethod !== 'HEAD') {
        echo $body;
    }
    exit;
}

function _stattic_send_file_headers(array $headers): void
{
    // Cache-Control is the canonical file-response policy. The derived CDN
    // headers in stored metadata describe the untransformed artifact, but
    // request-time claim/preview transforms can tighten Cache-Control after
    // metadata load. Emit all three from the final canonical value so stale
    // derived entries can never re-open shared caching for a private response.
    $canonicalCacheControl = $headers['Cache-Control'] ?? null;
    if (is_string($canonicalCacheControl)) {
        header_remove('Cache-Control');
        header_remove('cache-control');
        header_remove('CDN-Cache-Control');
        header_remove('Surrogate-Control');
        header_remove('Pragma');
        header_remove('Expires');
        header('cache-control: ' . $canonicalCacheControl, true);
        header('CDN-Cache-Control: ' . $canonicalCacheControl, true);
        header('Surrogate-Control: ' . $canonicalCacheControl, true);
    }
    foreach ($headers as $name => $value) {
        $normalizedName = strtolower((string) $name);
        if (
            is_string($canonicalCacheControl)
            && in_array($normalizedName, ['cache-control', 'cdn-cache-control', 'surrogate-control'], true)
        ) {
            continue;
        }
        header_remove($name);
        header($name . ': ' . $value);
    }
}

// Strips the optional weak-validator marker (`W/`) from an ETag, returning its
// opaque quoted tag. If-None-Match uses the weak comparison function (RFC 7232
// §3.2), so `W/"x"` and `"x"` compare equal — this normalization is how.
function _stattic_etag_opaque_tag(string $etag): string
{
    $etag = trim($etag);
    if (strlen($etag) >= 2 && ($etag[0] === 'W' || $etag[0] === 'w') && $etag[1] === '/') {
        $etag = trim(substr($etag, 2));
    }
    return $etag;
}

// If-None-Match evaluation (RFC 7232 §3.2): true when the client's header lists
// a tag matching this representation under weak comparison, or is the `*`
// any-representation form. The header may be a comma-separated list; each member
// may carry a `W/` marker. `$etag` is the strong content-hash tag this response
// already carries.
function _stattic_if_none_match_matches(string $header, string $etag): bool
{
    $header = trim($header);
    if ($header === '') {
        return false;
    }
    if ($header === '*') {
        return true;
    }
    $target = _stattic_etag_opaque_tag($etag);
    if ($target === '') {
        return false;
    }
    foreach (explode(',', $header) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        if (_stattic_etag_opaque_tag($candidate) === $target) {
            return true;
        }
    }
    return false;
}

// If-Modified-Since evaluation (RFC 7232 §3.3): true when the resource has not
// been modified since the client's date. The byte-exact echo of our
// Last-Modified is the fast path (and preserves prior behavior); otherwise both
// values are parsed as strict HTTP-dates and a 304 is allowed when the
// resource's Last-Modified is at or before the client's date. RFC 7232 requires
// an If-Modified-Since that "is not a valid HTTP-date" be ignored, so a value
// that is not one of the three HTTP-date productions (never a relative string
// like `tomorrow`) yields false and the response falls through to a normal 200.
function _stattic_if_modified_since_allows_304(string $ifModifiedSince, string $lastModified): bool
{
    $ifModifiedSince = trim($ifModifiedSince);
    $lastModified = trim($lastModified);
    if ($ifModifiedSince === '' || $lastModified === '') {
        return false;
    }
    if ($ifModifiedSince === $lastModified) {
        return true;
    }
    $since = _stattic_parse_http_date($ifModifiedSince);
    $modified = _stattic_parse_http_date($lastModified);
    if ($since === null || $modified === null) {
        return false;
    }
    return $modified <= $since;
}

// Parses an HTTP-date (RFC 7231 §7.1.1.1) to a UTC epoch, or null when the value
// is not one of the three permitted productions — IMF-fixdate (what we emit),
// the obsolete RFC 850 form, or the obsolete asctime form. Unlike strtotime()
// this rejects relative/free-form strings and trailing garbage, and never
// consults the server clock, so an invalid conditional header cannot manufacture
// a spurious 304.
function _stattic_parse_http_date(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // Collapse the single-digit-day double space of the asctime form so its day
    // token parses; harmless for the other two (single-space) productions.
    $normalized = (string) preg_replace('/\s+/', ' ', $value);
    $utc = new \DateTimeZone('UTC');
    foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $normalized, $utc);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $parsed instanceof \DateTimeImmutable
            && (!is_array($errors) || ((int) ($errors['warning_count'] ?? 0) === 0 && (int) ($errors['error_count'] ?? 0) === 0))
            // Round-trip guard: createFromFormat silently coerces a mismatched
            // weekday to a *different* date (e.g. `Fri, 06 Nov 1994` → the next
            // Friday, `11 Nov 1994`) with no warnings, which would let a
            // malformed date masquerade as a later valid one and manufacture a
            // 304. Reformatting through the same format and requiring an exact
            // match rejects any input whose components do not form a
            // self-consistent, canonically-cased HTTP-date.
            && $parsed->format($format) === $normalized
        ) {
            return $parsed->getTimestamp();
        }
    }
    return null;
}

function _stattic_file_response_shared_cache_headers(array $headers, int $sharedMaxAgeSeconds = 31536000): array
{
    $cacheControl = $headers['Cache-Control'] ?? null;
    if (!is_string($cacheControl)) {
        return $headers;
    }
    if (_stattic_cache_control_allows_shared_store($cacheControl)) {
        $cacheControl = _stattic_cache_control_with_s_maxage($cacheControl, $sharedMaxAgeSeconds);
        if (!_stattic_cache_control_has_directive($cacheControl, 'immutable')) {
            // stale-while-revalidate (W7): this branch is exactly the
            // revalidatable class. Immutable files get an explicit shared TTL
            // above too, but never need a stale grace because their bytes do
            // not change at the same URL.
            $cacheControl = _stattic_cache_control_with_stale_while_revalidate($cacheControl, STATTIC_STALE_WHILE_REVALIDATE_SECONDS);
        }
        $headers['Cache-Control'] = $cacheControl;
    }

    $headers['CDN-Cache-Control'] = $cacheControl;
    $headers['Surrogate-Control'] = $cacheControl;
    return $headers;
}

// Appends a `stale-while-revalidate` directive, preserving any value a custom
// `_headers` rule already set (idempotent) and never emitting a non-positive
// window.
function _stattic_cache_control_with_stale_while_revalidate(string $cacheControl, int $seconds): string
{
    if ($seconds <= 0 || _stattic_cache_control_has_directive($cacheControl, 'stale-while-revalidate')) {
        return $cacheControl;
    }
    return $cacheControl . ', stale-while-revalidate=' . $seconds;
}

function _stattic_cache_control_allows_shared_store(string $cacheControl): bool
{
    return !_stattic_cache_control_has_directive($cacheControl, 'no-store')
        && !_stattic_cache_control_has_directive($cacheControl, 'no-cache')
        && !_stattic_cache_control_has_directive($cacheControl, 'private');
}

function _stattic_cache_control_has_directive(string $cacheControl, string $directive): bool
{
    $wanted = strtolower($directive);
    foreach (explode(',', $cacheControl) as $part) {
        $name = strtolower(trim(explode('=', trim($part), 2)[0] ?? ''));
        if ($name === $wanted) {
            return true;
        }
    }
    return false;
}

function _stattic_cache_control_with_s_maxage(string $cacheControl, int $seconds): string
{
    $out = [];
    $inserted = false;
    foreach (explode(',', $cacheControl) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $name = strtolower(trim(explode('=', $part, 2)[0] ?? ''));
        if ($name === 's-maxage') {
            if (!$inserted) {
                $out[] = 's-maxage=' . $seconds;
                $inserted = true;
            }
            continue;
        }
        $out[] = $part;
        if ($name === 'max-age' && !$inserted) {
            $out[] = 's-maxage=' . $seconds;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $out[] = 's-maxage=' . $seconds;
    }
    return implode(', ', $out);
}

function _stattic_stream_file($stream, int $bytesToSend): void
{
    $remaining = $bytesToSend;
    while ($remaining > 0 && !feof($stream)) {
        $chunk = fread($stream, min(65536, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }

        $remaining -= strlen($chunk);
        echo $chunk;
    }
}
