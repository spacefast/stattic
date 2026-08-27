<?php
declare(strict_types=1);

// The visitor serve path (contracts §6, §15, §16): pointer -> host shard ->
// overlay -> access -> version root -> response table -> entry -> send.
//
// PHP never answers a conditional request and never serves a range: the platform
// delivers neither If-None-Match/If-Modified-Since nor Range to the origin, and
// the edge answers conditionals off its own HIT (§16, C19). ETag is still
// EMITTED per entry as the edge's validator. This lane sends no Last-Modified;
// on the accel lane nginx derives its own from the content-derived mtime
// stamped on the placed file.

require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/server-file.php';
require_once __DIR__ . '/../shared/cache-policy.php';

// The visitor lane's entry, called by init.php and cli-invoke.php.
function _sf_serve_fast(
    string $privateRoot,
    string $requestMethod,
    string $requestUri,
    string $requestPath,
    string $requestHost
): never {
    _sf_load_generated_config($privateRoot);
    _stattic_serve_request($privateRoot, $requestMethod, $requestUri, $requestPath, $requestHost);
    exit;
}

function _sf_load_generated_config(string $privateRoot): void
{
    static $attempted = false;
    if ($attempted) {
        return;
    }
    $attempted = true;
    $path = $privateRoot . '/config.generated.php';
    if (is_file($path)) {
        require_once $path;
    }
}

function _sf_promote_blob(string $privateRoot, string $spaceId, string $blobRelativePath): string
{
    require_once __DIR__ . '/tier.php';
    $localPath = _stattic_tier_promote_blob($privateRoot, $spaceId, basename($blobRelativePath));
    if (!is_string($localPath) || $localPath === '') {
        require_once __DIR__ . '/../shared/errors.php';
        _stattic_render_tier_fetch_unavailable(5);
        exit;
    }
    return $localPath;
}

const STATTIC_STATIC_STREAM_ADMISSION_BYTES = 262144;
const STATTIC_PRIVATE_FILE_ALIAS_SUFFIX = ';sf-private';

function _stattic_private_file_alias_source(string $path): string|false|null
{
    if (!str_contains($path, STATTIC_PRIVATE_FILE_ALIAS_SUFFIX)) {
        return null;
    }
    if (!str_ends_with($path, STATTIC_PRIVATE_FILE_ALIAS_SUFFIX)) {
        return false;
    }
    $source = substr($path, 0, -strlen(STATTIC_PRIVATE_FILE_ALIAS_SUFFIX));
    $canonical = _stattic_canonical_request_path($source);
    if (
        $canonical === null
        || $canonical !== $source
        || str_starts_with($source, '/__stattic/')
        || str_starts_with($source, '/__spacefast/')
        || $source === '/storage'
        || str_starts_with($source, '/storage/')
    ) {
        return false;
    }
    return $source;
}

function _stattic_private_file_alias_not_found(): never
{
    _stattic_send_response_headers(['cache-control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE]);
    http_response_code(404);
    exit;
}

function _stattic_private_file_alias_redirect(string $sourcePath): never
{
    $query = _stattic_strip_access_query_token((string) ($_SERVER['QUERY_STRING'] ?? ''));
    $location = $sourcePath . STATTIC_PRIVATE_FILE_ALIAS_SUFFIX
        . ($query === '' ? '' : '?' . $query);
    _stattic_send_response_headers([
        'cache-control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        'referrer-policy' => 'no-referrer',
    ]);
    header('Location: ' . $location, true, 307);
    exit;
}

// A terminal host action normally needs no Space bytes. Partner presentation is
// the narrow exception: when the retained Space pointer and overlay are still
// readable, expose only the already-compiled serving projection to the page
// renderer. Failure stays on the platform fallback and never weakens the action.
function _stattic_prime_platform_page_serving(string $privateRoot, array $hostEntry, array $action): void
{
    if (($action['page_id'] ?? null) === 'tombstone-csam') {
        return;
    }
    $spaceId = is_string($action['space_id'] ?? null) ? $action['space_id'] : '';
    if ($spaceId === '') {
        return;
    }
    $spaceRead = _sf_pointer_read('platform-page-space:' . $spaceId, $privateRoot . '/spaces/' . $spaceId . '/space.json');
    if (!is_array($spaceRead['value'] ?? null)) {
        return;
    }
    $overlay = _stattic_v4_overlay($privateRoot, $spaceId, $spaceRead['value']);
    if (!is_array($overlay)) {
        return;
    }
    $versionId = _stattic_v4_version_for_host($hostEntry, $overlay);
    if (!is_string($versionId) || $versionId === '') {
        return;
    }
    $GLOBALS['SPACEFAST_PAGE_SERVING'] = _stattic_v4_legacy_serving(
        $spaceId,
        $versionId,
        $hostEntry,
        $overlay
    );
}

function _stattic_serve_request(string $privateRoot, string $requestMethod, string $requestUri, string $requestPath, string $requestHost): void
{
    _stattic_visitor_lane_begin($privateRoot);

    $originalRequestUri = $requestUri;
    $originalRequestPath = $requestPath;

    // Authorized by its own control-plane-signed token and resolves no route, so
    // it answers before route, host and overlay resolution: a first publish has
    // no routes/current.json yet, and this gate is how finalize reads the
    // accepted source bytes that create that pointer.
    if (str_starts_with($originalRequestPath, '/__stattic/blob/')) {
        require_once __DIR__ . '/../entrypoints/blob.php';
        _stattic_blob_gate_serve($privateRoot, $requestMethod, $originalRequestPath);
    }

    // ---- route pointer + host shard -------------------------------------
    // `unavailable` is a failed read of an EXISTING pointer, never `undeployed`:
    // that page is a deployment claim, and a transient I/O failure must not
    // impersonate one (the "Waiting for launch" incident).
    $routesRead = _sf_pointer_read('routes', $privateRoot . '/routes/current.json');
    if ($routesRead['kind'] === 'unavailable') {
        _stattic_render_runtime_unavailable_lazy('route_pointer_unreadable');
    }
    $routes = $routesRead['value'];
    if (!is_array($routes) || !isset($routes['gen'])) {
        _stattic_render_platform_action(_stattic_undeployed_action());
    }
    _stattic_v4_assert_schema($routes['schema'] ?? null, 'route pointer');

    // Proves the production pointer itself, so it terminates before visitor
    // access and redirects can remap the request.
    if ((_stattic_control_path_row($originalRequestPath)['handler'] ?? null) === 'probe') {
        http_response_code(204);
        header('cache-control: no-store, no-cache, must-revalidate');
        exit;
    }

    $host = _stattic_v4_host_lookup($privateRoot, $routes, $requestHost);
    if ($host === false) {
        _stattic_render_runtime_unavailable_lazy('route_shard_unreadable');
    }
    if ($host === null) {
        _stattic_render_platform_action(_stattic_undeployed_action());
    }
    $hostEntry = $host['entry'];
    $hostRoutes = $host['routes'];
    $spaceId = is_string($hostEntry['space_id'] ?? null) ? $hostEntry['space_id'] : '';

    // A host whose entry is a terminal platform answer carries no Space bytes
    // and must stay reachable after the Space route is gone.
    $hostAction = is_array($hostEntry['route_action'] ?? null) ? $hostEntry['route_action'] : null;
    if (is_array($hostAction) && in_array(($hostAction['action'] ?? null), ['tombstone', 'platform_error'], true)) {
        if (!_stattic_action_allows_method($hostAction, $requestMethod)) {
            _stattic_render_method_not_allowed_lazy();
        }
        _stattic_prime_platform_page_serving($privateRoot, $hostEntry, $hostAction);
        _stattic_render_platform_action($hostAction);
    }

    // ---- space pointer + overlay ----------------------------------------
    $space = null;
    if ($spaceId !== '') {
        $spaceRead = _sf_pointer_read('space:' . $spaceId, $privateRoot . '/spaces/' . $spaceId . '/space.json');
        if ($spaceRead['kind'] === 'unavailable') {
            _stattic_render_runtime_unavailable_lazy('space_pointer_unreadable');
        }
        $space = $spaceRead['value'];
    }
    $overlay = _stattic_v4_overlay($privateRoot, $spaceId, $space);
    if ($overlay === false) {
        _stattic_render_runtime_unavailable_lazy('space_overlay_unreadable');
    }
    // Fail closed: an absent/invalid overlay, or one fenced mid-mutation toward
    // a stronger state, is a denial, never an open space. A failed READ of an
    // existing overlay took the unavailable exit above.
    if ($overlay === null || ($overlay['fence'] ?? null) === 'exposure') {
        _stattic_v4_render_forbidden('denied', 'access_denied', 'Forbidden');
    }
    $open = ($overlay['open'] ?? null) === true;

    // A protected provider-extension asset changes its final client URL before
    // X-Accel. The alias is reserved, resolves the original path, and re-runs
    // the full access check on every request.
    $privateFileAliasSource = _stattic_private_file_alias_source($requestPath);
    if ($privateFileAliasSource === false) {
        _stattic_private_file_alias_not_found();
    }
    $privateFileAlias = is_string($privateFileAliasSource);
    if ($privateFileAlias) {
        $requestPath = $privateFileAliasSource;
        $originalRequestPath = $privateFileAliasSource;
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $requestUri = $requestPath . ($query === '' ? '' : '?' . $query);
        $originalRequestUri = $requestUri;
    }

    $versionId = _stattic_v4_version_for_host($hostEntry, $overlay);
    $serving = _stattic_v4_legacy_serving($spaceId, $versionId, $hostEntry, $overlay);
    $GLOBALS['SPACEFAST_PAGE_SERVING'] = $serving;

    // Must stay before route response headers are emitted: these exchange
    // responses own their headers.
    _stattic_dispatch_control_path($serving, $requestPath, $requestHost, $privateRoot);

    if (!empty($hostEntry['noindex'])) {
        header('X-Robots-Tag: noindex, nofollow', false);
    }
    $tagPreviewToken = _stattic_spacefast_tag_preview_token($requestMethod);

    // The Allow union of every lane that declined this request for its METHOD
    // alone (a static entry, a Zero pattern, a Functions route at other verbs).
    // A non-empty union at the end of the ladder is a 405 naming it, never a
    // fall-through to the 404 tail: the path demonstrably exists.
    $GLOBALS['SPACEFAST_METHOD_DECLINED_ALLOW'] = [];
    $functionsMachineRoute = str_starts_with($originalRequestPath, '/__spacefast/functions/');
    $storageRequest = $requestPath === '/storage' || str_starts_with($requestPath, '/storage/');

    // ---- host-level routes (mounts, sub-path redirects and proxies) ------
    $matchedRoute = _stattic_v4_match_host_route($hostRoutes, $requestPath, $requestMethod);
    if (is_array($matchedRoute)) {
        $action = _stattic_matched_host_route_action($matchedRoute);
        if (!_stattic_action_allows_method($action, $requestMethod)) {
            _stattic_render_method_not_allowed_lazy();
        }
        $kind = $action['action'] ?? null;
        if ($kind === 'redirect') {
            _stattic_send_route_redirect($action, (string) ($matchedRoute['_remainder'] ?? '/'), !$open);
        }
        if ($kind === 'robots_txt' || $kind === 'platform_error' || $kind === 'tombstone') {
            _stattic_render_platform_action($action, !$open);
        }
        if ($kind === 'proxy') {
            _stattic_enforce_access_for_proxy($serving, $requestHost, $originalRequestPath, $originalRequestUri);
            require_once __DIR__ . '/proxy.php';
            _stattic_proxy_request($action, (string) ($matchedRoute['_remainder'] ?? '/'), $serving);
        }
        if ($kind === 'serve') {
            // Targets an explicit version through a mount prefix.
            if (is_string($action['version_id'] ?? null) && $action['version_id'] !== '') {
                $versionId = $action['version_id'];
            }
            $targetPrefix = _stattic_canonical_request_path((string) ($action['target_prefix'] ?? '/'));
            if ($targetPrefix === null) {
                _stattic_v4_render_forbidden('invalid-path', 'invalid_path', "Access path is invalid.\n");
            }
            $requestPath = _stattic_join_request_path($targetPrefix, (string) ($matchedRoute['_remainder'] ?? '/'));
        }
    }

    if ($versionId === null) {
        _stattic_render_platform_action(_stattic_version_pending_action());
    }
    $versionDir = _stattic_version_root($privateRoot, $spaceId, $versionId);
    // zero.php / functions-*.php address the version through `<version>/files`
    // and reach their sidecars with dirname(). The v4 compiler writes no file
    // tree, so this is a naming convention, not a directory.
    $versionRoot = _stattic_version_files_root($privateRoot, $spaceId, $versionId);
    // The first point at which a version is the answer, so the first at which the
    // response may name one: naming it above leaks which version a host points at.
    _stattic_emit_runtime_identity($versionId);

    // ---- non-content lanes that must never resolve as content -----------
    if ($storageRequest) {
        require_once __DIR__ . '/storage.php';
        _stattic_storage_apply_access_token();
        // A Functions binding carries its version-scoped machine grant rather
        // than a visitor session. Authenticate it here, then use the same
        // storage handler as a capsule request.
        if (_stattic_storage_function_request_authorized($privateRoot, $serving, $requestMethod)) {
            _stattic_dispatch_storage(
                $privateRoot,
                $serving,
                $requestHost,
                $requestPath,
                $requestMethod
            );
        }
    }
    if (str_starts_with($requestPath, '/' . STATTIC_FUNCTIONS_BUNDLE_PREFIX)) {
        require_once __DIR__ . '/functions-artifacts.php';
        _stattic_artifact_serve($privateRoot, $spaceId, $versionId, $requestPath, $requestMethod);
    }
    if ($requestPath === '/' . STATTIC_FUNCTIONS_RELAY_PATH || $requestPath === '/' . STATTIC_FUNCTIONS_LOGS_PATH) {
        require_once __DIR__ . '/functions-relay.php';
        if ($requestPath === '/' . STATTIC_FUNCTIONS_RELAY_PATH) {
            _stattic_functions_relay_serve($privateRoot, $spaceId, $requestMethod);
        }
        _stattic_functions_logs_serve($privateRoot, $spaceId, $requestMethod);
    }
    // Machine purge intake, on the purge credential the control plane minted
    // into this version's functions/config.json. Like the relay and log lanes
    // above, its own token authorizes it and it resolves no route, so it answers
    // before token redemption, access and content resolution.
    if ($requestPath === '/' . STATTIC_FUNCTIONS_PURGE_PATH) {
        require_once __DIR__ . '/functions-purge.php';
        _stattic_functions_purge_serve($privateRoot, $spaceId, $versionRoot, $requestMethod);
    }

    // `?__=` is redeemed HERE, before the response table is read, and the request
    // continues to the page it named. Zero visible hops: the identity this
    // installs is what the enforcement below sees. The token declares its own
    // lane, see _stattic_access_query_token_classify.
    if (!$functionsMachineRoute && _stattic_access_query_token_present()) {
        require_once __DIR__ . '/access-rules.php';
        _stattic_access_apply_query_token(
            $serving,
            $requestHost,
            _stattic_spacefast_sdk_access_path($serving, $requestHost, $originalRequestPath)
        );
    }

    // ---- version root + response table ----------------------------------
    $root = _stattic_v4_version_root_artifact(
        $versionDir,
        is_string($hostEntry['route_name'] ?? null) ? $hostEntry['route_name'] : null
    );
    if ($root === null) {
        _stattic_render_platform_action(_stattic_version_pending_action());
    }

    // ---- dynamic residue rules ------------------------------------------
    // Rule ordering is the entry's to declare: an entry marked rules-first can be
    // preempted by a pattern redirect that sorts ahead of it, so its rules run
    // before it answers. An entry without the flag has already won first-match,
    // and a miss runs the rules because a rewrite may still find bytes.
    $rulesEntry = _stattic_v4_entry($versionDir, $root, STATTIC_RUNTIME_RESPONSE_KEY_RULES);
    $entry = _stattic_v4_entry($versionDir, $root, $requestPath);
    $conditionalRewrite = false;
    $conditionalCandidate = false;
    $conditionalVary = [];
    $routeStatus = 200;
    $rulesFirst = $entry === null
        || !empty($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_RULES_FIRST]);
    $redirectRules = _stattic_v4_rule_section($rulesEntry, 'redirects');
    // The rules stage runs ahead of the control ladder below: a rewrite mutates
    // the path that ladder compares, and a redirect terminates here before the
    // ladder runs at all. So path reservation is honoured HERE too, not only at
    // the front door, from the one authority: the control table in context.php.
    // At serve time rather than at publish, because a route reserved by a later
    // engine release must hold for versions published before it existed. BOTH
    // paths, because a private-file alias and a mounted route each rewrite
    // $requestPath and the ladder below reads one or the other per handler.
    $reservedRoute = _stattic_path_is_reserved($requestPath)
        || _stattic_path_is_reserved($originalRequestPath);
    if ($rulesFirst && $redirectRules !== null && !$reservedRoute) {
        require_once __DIR__ . '/redirects.php';
        $result = _stattic_apply_redirects(
            $redirectRules,
            $serving,
            // Already resolved above; reuse it instead of re-hashing the same
            // table key per matching rule.
            static fn (string $candidate): bool => $candidate === $requestPath
                ? $entry !== null
                : _stattic_v4_entry($versionDir, $root, $candidate) !== null,
            $requestHost,
            $requestPath,
            $requestMethod,
            200
        );
        $next = (string) $result['path'];
        if ($next !== $requestPath) {
            $canonical = _stattic_canonical_request_path($next);
            if ($canonical === null) {
                _stattic_v4_render_forbidden('invalid-path', 'invalid_path', "Access path is invalid.\n");
            }
            $requestPath = $canonical;
            $entry = _stattic_v4_entry($versionDir, $root, $requestPath);
        }
        $routeStatus = (int) $result['status'];
        $conditionalRewrite = !empty($result['conditional']);
        $conditionalCandidate = !empty($result['conditional_candidate']);
        $conditionalVary = is_array($result['conditional_vary'] ?? null)
            ? $result['conditional_vary']
            : [];
        if (array_key_exists('query', $result)) {
            $_SERVER['QUERY_STRING'] = (string) $result['query'];
            parse_str((string) $result['query'], $_GET);
            $requestUri = $requestPath . ((string) $result['query'] !== '' ? '?' . (string) $result['query'] : '');
        }
    }

    // ---- access ----------------------------------------------------------
    // The ONE protected-space enforcement call on the serve path; an open Space
    // loads no access code at all. BOTH paths are passed: a rewrite must not
    // launder a URL whose own Grants are narrower than the effective path's.
    if (!$open) {
        require_once __DIR__ . '/access-rules.php';
        // The SDK is a subresource of the page that embedded it and owns no
        // scope, so no page Grant lists it. The authority comes from the
        // session (_stattic_spacefast_sdk_access_path).
        $accessPath = $requestPath === STATTIC_SPACEFAST_SDK_PATH
            && $originalRequestPath === STATTIC_SPACEFAST_SDK_PATH
            ? _stattic_spacefast_sdk_access_path($serving, $requestHost, $requestPath)
            : $requestPath;
        _stattic_access_enforce_v4(
            $requestHost,
            $accessPath,
            $accessPath === $requestPath ? $originalRequestPath : $accessPath
        );
    }
    // The enforcement verdict, not the overlay flag: a Space that HAS grants but
    // admits this URL anonymously and unconditionally is still URL-stable and
    // keeps its shared-cache policy. Token presence, valid or not, pins the
    // response out of every shared cache: the edge keys on host+path+query,
    // ignores Vary, and looks up before PHP runs.
    $privateCache = _stattic_access_private_cache_flag()
        || $conditionalRewrite
        || _stattic_access_query_token_present();
    if ($privateFileAlias && (!$privateCache || !in_array($requestMethod, ['GET', 'HEAD'], true))) {
        _stattic_private_file_alias_not_found();
    }

    if ($storageRequest) {
        _stattic_dispatch_storage(
            $privateRoot,
            $serving,
            $requestHost,
            $requestPath,
            $requestMethod
        );
    }

    // An entry-stage control path that dispatches HERE, after the access check,
    // because the bytes belong to the Space: a protected Space must challenge
    // for them like any other content.
    if (_stattic_control_path_entry_handler($originalRequestPath) === 'uploads_object') {
        require_once __DIR__ . '/storage.php';
        _stattic_uploads_serve($privateRoot, $spaceId, $originalRequestPath);
        exit;
    }

    // The SDK varies by Space and version: content, not public infrastructure.
    if ($requestPath === STATTIC_SPACEFAST_SDK_PATH) {
        require_once __DIR__ . '/../shared/bootstrap-config.php';
        require_once __DIR__ . '/spacefast-sdk.php';
        _stattic_serve_spacefast_sdk($privateRoot, $serving, $requestHost, $requestMethod, $privateCache);
    }

    // Same reasoning for the Collab frame shell, and the URL the VISITOR asked
    // for: the review link is reserved, so no publisher rewrite may claim it.
    if ($originalRequestPath === SPACEFAST_COLLAB_FRAME_PATH) {
        require_once __DIR__ . '/collab-frame.php';
        _stattic_serve_collab_frame($serving, $requestHost, $requestMethod, $privateCache);
    }

    // A Space that published its own review room answers it here, from the
    // finalized document. The `_pages/collab.html` SOURCE stays private like
    // every page template. No pointer means no room: the request falls through
    // to the private-path 404, not a platform page.
    if ($originalRequestPath === SPACEFAST_COLLAB_PAGE_PATH
        && in_array($requestMethod, ['GET', 'HEAD'], true)) {
        $collabPages = is_array($serving['pages'] ?? null) ? $serving['pages'] : [];
        if (is_string($collabPages['collab'] ?? null)) {
            _stattic_v4_serve_artifact_page(
                'collab',
                $collabPages['collab'],
                $originalRequestPath,
                $serving,
                $privateCache
            );
        }
    }

    // ---- entry resolution ------------------------------------------------
    $sendContext = [
        'private_root' => $privateRoot,
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'version_dir' => $versionDir,
        'version_root' => $versionRoot,
        'root' => $root,
        'host' => $requestHost,
        'host_entry' => $hostEntry,
        'method' => $requestMethod,
        'serving' => $serving,
        'private_cache' => $privateCache,
        'request_varying' => $conditionalRewrite || $conditionalCandidate,
        'request_vary_headers' => $conditionalVary,
        'tag_preview' => $tagPreviewToken,
        'content_type_policy' => _stattic_serving_content_type_policy($serving),
        'open' => $open,
        'header_rules' => _stattic_v4_rule_section($rulesEntry, 'headers'),
        'route_status' => $routeStatus,
        'private_file_alias' => $privateFileAlias,
        'request_uri' => $requestUri,
        // The URL the VISITOR asked for, never the rewritten one: the provider's
        // asset rewrite (C21/D146) keys on it, and a mount or a residue rewrite
        // moves the entry's key away from it.
        'client_path' => $originalRequestPath,
    ];

    // `?spacefast_view=1` selects the finalized preview document for this
    // committed path. The pointer is version-scoped in the Space overlay and
    // the artifact reader binds it back to that same version root.
    $previewArtifact = _stattic_v4_preview_artifact($serving, $requestPath, $requestMethod);
    if ($previewArtifact !== null) {
        _stattic_v4_serve_artifact_page(
            'preview',
            $previewArtifact,
            $requestPath,
            $serving,
            $privateCache
        );
    }

    // The platform's deny-all robots.txt outranks a published file at that path
    // only on a host kept out of indexes; an indexable host resolves /robots.txt
    // through the table and down the 404 ladder like any other path.
    if ($requestPath === '/robots.txt' && !empty($hostEntry['noindex'])) {
        $robots = _stattic_v4_entry($versionDir, $root, STATTIC_RUNTIME_RESPONSE_KEY_ROBOTS);
        if (is_array($robots)) {
            _stattic_v4_send_entry($sendContext, $robots, $requestPath);
        }
    }

    if (is_array($entry)) {
        // A draft/preview session must not be answered from an extracted file:
        // when the request carries one of the version's declared bypass cookies
        // AND the worker claims this path, the file yields to the pattern lane
        // below. The normal response at the same URL must also opt OUT of
        // wp.cloud's shared cache, which resolves host+path+query before PHP and
        // ignores Cookie/Vary: one cached published response would make this
        // request-time bypass unreachable.
        //
        // A content-addressed entry (compiled `imm` class) is carved out of both
        // sides: its bytes are identical in and out of a draft session, so it
        // keeps serving from disk and keeps its immutable year, which a
        // whole-site middleware claim would cost every hashed chunk.
        $immutableEntry = ($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_CACHE_CLASS] ?? null)
            === STATTIC_RUNTIME_CACHE_CLASS_IMMUTABLE;
        $bypassCapable = !$immutableEntry && _stattic_v4_functions_static_bypass_capable(
            $versionRoot,
            $requestPath,
            $requestMethod,
        );
        $cookieHeader = $_SERVER['HTTP_COOKIE'] ?? '';
        $bypass = false;
        if ($bypassCapable && is_string($cookieHeader) && $cookieHeader !== '') {
            require_once __DIR__ . '/functions-dispatch.php';
            $bypass = _stattic_functions_bypass_requested($versionRoot, $requestPath, $requestMethod);
        }
        if (!$bypass) {
            $entryContext = $sendContext;
            if ($bypassCapable) {
                // The URL varies by a cookie the provider cannot put in its
                // cache key. Reuse the response boundary's existing no-store
                // disposition rather than relying on a response-time Vary.
                $entryContext['request_varying'] = true;
            }
            _stattic_v4_send_entry($entryContext, $entry, $requestPath);
        }
    }

    // Pattern-matched Zero endpoints and Function routes cannot be table keys;
    // their exact forms already were, so a committed file still wins.
    _stattic_v4_dispatch_pattern_routes($sendContext, $requestPath, $requestMethod, $requestUri);

    // Every lane that could claim this method has had its chance. A request some
    // lane skipped FOR ITS METHOD alone ends here as the union 405: continuing
    // into the SPA/404 tail would misreport an existing path as absent.
    _stattic_render_method_declined_405_if_any();

    $lookup = ltrim($requestPath, '/');
    // A 200 SPA index is an application-route fallback, not a catch-all asset
    // server.
    if (!_stattic_lookup_not_found_is_terminal($lookup) && !_stattic_lookup_is_known_asset_extension($lookup)) {
        $spa = _stattic_v4_entry($versionDir, $root, STATTIC_RUNTIME_RESPONSE_KEY_SPA);
        if (is_array($spa)) {
            _stattic_v4_send_entry($sendContext, $spa, $requestPath);
        }
    }

    // A publisher error document, never the requested page, so no tag preview.
    $sendContext['tag_preview'] = null;
    $notFound = _stattic_v4_nearest_not_found($versionDir, $root, $requestPath);
    if (is_array($notFound)) {
        _stattic_v4_send_entry($sendContext, $notFound, $requestPath);
    }

    _stattic_send_platform_404($privateCache, $requestPath, $conditionalRewrite || $conditionalCandidate);
}

// ---- version root + response tables ---------------------------------------

function _stattic_v4_version_root_artifact(string $versionDir, ?string $routeName = null): ?array
{
    // A finalized version is immutable. Cache only its tiny mutable pointer;
    // the content-addressed PHP artifact remains OPcache's responsibility.
    // The name only labels failure log lines, so it need not hash the path.
    $pointerRead = _sf_pointer_read('version-root:' . basename($versionDir), $versionDir . '/root.json');
    if ($pointerRead['kind'] === 'unavailable') {
        // Terminal here, not a null return: null means version-pending, whose
        // s-maxage=30 would park this failure in the shared edge cache.
        _stattic_render_runtime_unavailable_lazy('version_root_unreadable');
    }
    $pointer = $pointerRead['value'];
    if (!is_array($pointer) || !is_string($pointer['root'] ?? null)) {
        return null;
    }
    $root = _stattic_v4_include_artifact($versionDir . '/' . $pointer['root']);
    if ($root === false) {
        _stattic_render_runtime_unavailable_lazy('version_root_unreadable');
    }
    if (!is_array($root) || !is_array($root['tables'] ?? null)) {
        return null;
    }
    // The ROOT is the only thing the serve path validates; entries are validated
    // once, at finalize.
    _stattic_v4_assert_schema($root['schema'] ?? null, 'version root');
    $routeTables = is_array($root['route_tables'] ?? null) ? $root['route_tables'] : [];
    if ($routeName !== null && is_array($routeTables[$routeName] ?? null)) {
        $root['tables'] = $routeTables[$routeName];
    }
    return $root;
}

function _stattic_v4_preview_artifact(array $serving, string $requestPath, string $requestMethod): ?string
{
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)
        || ($_GET[STATTIC_PAGE_PREVIEW_QUERY_NAME] ?? null) !== '1') {
        return null;
    }
    $pages = is_array($serving['pages'] ?? null) ? $serving['pages'] : [];
    $previews = is_array($pages['previews'] ?? null) ? $pages['previews'] : [];
    $artifact = $previews[$requestPath] ?? null;
    return is_string($artifact) && preg_match('/^[a-z0-9-]{1,240}$/', $artifact) === 1
        ? $artifact
        : null;
}

// The reserved pages the Space publishes but the table never routes: the
// finalized document answers the URL directly, with this response's own
// privacy deciding whether the edge may hold it.
function _stattic_v4_serve_artifact_page(
    string $pageId,
    string $artifact,
    string $requestPath,
    array $serving,
    bool $privateCache
): never {
    require_once __DIR__ . '/../shared/errors.php';
    _stattic_serve_page($pageId, [
        'status' => 200,
        'customizable' => true,
        'artifact' => $artifact,
        'request_path' => $requestPath,
        'serving' => $serving,
        'private' => $privateCache,
        'headers' => [
            'Cache-Control' => $privateCache
                ? STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE
                : STATTIC_DEFAULT_EDGE_CACHE_CONTROL,
        ],
    ]);
}

// A single table by default; sharded by sha256(key)[0:2] once the one file
// would exceed 512 KiB. The key hashed is the exact table key, `\0`-prefixed
// reserved keys included.
function _stattic_v4_entry(string $versionDir, array $root, string $key): ?array
{
    $tables = $root['tables'];
    $file = $tables['*'] ?? ($tables[substr(hash('sha256', $key), 0, 2)] ?? null);
    if (!is_string($file)) {
        return null;
    }
    $table = _stattic_v4_include_artifact($versionDir . '/' . $file);
    if ($table === false) {
        // Terminal: a null return means "no entry", which the ladder resolves to
        // a platform 404 with s-maxage=600, ten edge-cached minutes of 404 on a
        // live page off one failed include.
        _stattic_render_runtime_unavailable_lazy('response_table_unreadable');
    }
    if (!is_array($table)) {
        return null;
    }
    $entry = $table[$key] ?? null;
    return is_array($entry) ? $entry : null;
}

// The directory in a `\0404:<dir>` key is the published path with `404.html`
// stripped and every slash trimmed, `docs` or `docs/api`, so the probe carries
// NO leading slash, and the root's chain member is the bare `\0404` key.
function _stattic_v4_nearest_not_found(string $versionDir, array $root, string $requestPath): ?array
{
    $directory = trim(str_ends_with($requestPath, '/') ? $requestPath : dirname($requestPath), '/');
    while ($directory !== '') {
        $entry = _stattic_v4_entry($versionDir, $root, STATTIC_RUNTIME_RESPONSE_KEY_NOT_FOUND_PREFIX . $directory);
        if (is_array($entry)) {
            return $entry;
        }
        $slash = strrpos($directory, '/');
        $directory = $slash === false ? '' : substr($directory, 0, $slash);
    }
    return _stattic_v4_entry($versionDir, $root, STATTIC_RUNTIME_RESPONSE_KEY_NOT_FOUND);
}

// ---- sending an entry ------------------------------------------------------

// A static entry at a worker-claimed path can become a draft response when one
// of the version's declared bypass cookies is present. This answers the
// cacheability question WITHOUT a cookie on this request: the normal response
// must never warm a shared edge cache that would hide a later cookie from PHP.
function _stattic_v4_functions_static_bypass_capable(string $versionRoot, string $requestPath, string $requestMethod): bool
{
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        return false;
    }
    $configRead = _stattic_functions_config_read($versionRoot);
    if ($configRead['kind'] === 'absent') {
        // Verified absence is the only state that proves this version has no
        // Functions cookie-bypass policy.
        return false;
    }
    if ($configRead['kind'] !== 'present') {
        // Unavailable/malformed configuration is NOT proof of no bypass. Keep
        // the ordinary response out of shared cache so a later healthy request
        // can recover the exact route/cookie answer without a stale edge HIT.
        return true;
    }
    // `present` already proved `artifact` is an array with its required fields.
    $artifact = $configRead['value']['artifact'];
    if (!array_key_exists('bypassCookies', $artifact) || !is_array($artifact['bypassCookies'])) {
        // Stricter than the dispatch lane on purpose: a config that does not
        // state its bypass policy cannot prove the response is invariant.
        return true;
    }
    if ($artifact['bypassCookies'] === []) {
        return false;
    }
    require_once __DIR__ . '/functions-dispatch.php';
    $routesRead = _stattic_try_load_functions_routes_artifact($versionRoot);
    if ($routesRead['kind'] === 'unavailable') {
        // The path table decides whether this static URL can vary. Failure is
        // not proof of no bypass, but the committed asset still owns this lane:
        // serve it no-store rather than replacing it with an invariant response
        // from the later Functions route lane.
        return true;
    }
    if ($routesRead['kind'] === 'absent') {
        return false;
    }
    $route = _stattic_resolve_functions_route_action(
        $versionRoot,
        ltrim($requestPath, '/'),
        $requestMethod,
    );
    return is_array($route) && ($route['action'] ?? null) === 'dispatch_functions';
}

// Exits on every path that produces a response. It RETURNS only when the entry
// was an action that declined to handle the request, so the caller's ladder
// continues to the 404 tail.
function _stattic_v4_send_entry(array $context, array $entry, string $requestPath): void
{
    $action = is_array($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_ACTION] ?? null)
        ? $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_ACTION]
        : null;
    if ($action !== null) {
        _stattic_v4_dispatch_action($context, $action, $entry, $requestPath);
        return;
    }

    $method = (string) $context['method'];
    $status = is_int($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_STATUS] ?? null)
        ? $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_STATUS]
        : 200;
    if ($status === 200 && (int) $context['route_status'] >= 400) {
        // A notFound rewrite must not launder its 404 into a 200.
        $status = (int) $context['route_status'];
    }
    $headers = _stattic_v4_entry_headers($context, $entry);

    // A compiled redirect: the ordered lane never runs for it, so its two
    // guarantees are reproduced here. Every visitor method is admitted, and the
    // request's query rides across unless the destination states one.
    $isRedirect = $status >= 300 && $status < 400 && isset($headers['location']);
    if ($isRedirect) {
        if (!in_array($method, STATTIC_VISITOR_METHODS, true)) {
            _stattic_render_method_not_allowed_lazy(STATTIC_VISITOR_METHODS);
        }
        $headers['location'] = _stattic_append_current_query_to_url($headers['location']);
    } elseif (!in_array($method, ['GET', 'HEAD'], true)) {
        // A static entry serves representations and owns no verbs beyond them,
        // so a non-GET/HEAD request is not its to TERMINATE: it records
        // {GET, HEAD} into the Allow union and declines back to the ladder,
        // where a method-aware Zero pattern or Functions route may still claim
        // the method. If nothing claims it, the ladder renders the accumulated
        // 405, never the 404 tail, because this path exists. OPTIONS is not
        // special-cased anywhere here, so unclaimed it ends at the same
        // 405-with-Allow it always received, with no synthesized 204/Allow
        // probe answer.
        _stattic_method_decline(['GET', 'HEAD']);
        return;
    }

    $noStore = _stattic_cache_policy_no_store_flags();
    $privateCache = (bool) $context['private_cache'] || $noStore;
    $requestVarying = (bool) $context['request_varying'];
    $tagPreviewToken = $context['tag_preview'];

    // Redirects carry no representation body or Content-Type. The allowlist
    // governs published bytes, so applying it here would block compiled
    // canonical redirects (for example `/docs` -> `/docs/`) as opaque files.
    if (!$isRedirect) {
        $policy = _stattic_v4_content_type_policy_check($context, $headers);
        if ($policy !== null) {
            _stattic_render_content_type_blocked($policy);
        }
    }

    // Only cache-control is composed here, from the entry's class and whether the
    // response ended up shared-cacheable. An entry with no cache class shipped an
    // explicit policy, honored as-is unless this response is private, where a
    // public policy would be a disclosure.
    $cacheClass = is_string($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_CACHE_CLASS] ?? null)
        ? $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_CACHE_CLASS]
        : null;
    if ($cacheClass !== null) {
        $headers['cache-control'] = _sf_cache_control($cacheClass, !$privateCache);
    } elseif ($privateCache) {
        $headers['cache-control'] = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
    }
    // Synthetic and request-varying responses occupy URLs no purge can
    // enumerate, so shared caches must age them out fast or not store them.
    if ($requestVarying) {
        $headers['cache-control'] = STATTIC_CACHE_CONTROL_NO_STORE;
    } elseif ($status >= 400 && !$privateCache) {
        $headers['cache-control'] = STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
    }
    if ($noStore) {
        $headers['cache-control'] = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
    }
    $headers = _stattic_v4_platform_response_headers($context, $headers, $privateCache);

    // The validator is EMITTED, never answered against: the edge holds the copy
    // and answers If-None-Match off its own HIT.
    //
    // `et` is compiled UNQUOTED for both validator kinds (sha256 on the PHP lane,
    // nginx's `<hexmtime>-<hexsize>` on the accel lane) and quoted exactly once,
    // here. Splitting that ownership is what produced `""<sha>""`.
    $etag = is_string($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_ETAG] ?? null)
        ? $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_ETAG]
        : '';
    if ($etag !== '' && $status === 200) {
        $headers['etag'] = '"' . $etag . '"';
    }

    $blobSha = is_string($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_BLOB] ?? null)
        ? $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_BLOB]
        : null;
    $rawLength = $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_LENGTH] ?? null;
    // Fail closed rather than serve a cacheable 200 with an empty body: without
    // a usable length every downstream lane silently sends zero bytes.
    if ($blobSha !== null && (!is_int($rawLength) || $rawLength < 0)) {
        _stattic_render_runtime_invariant_error_lazy('response-entry-length-missing', 'Runtime response entry has no body length.');
    }
    $length = is_int($rawLength) ? $rawLength : 0;
    $lane = (int) ($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_LANE] ?? STATTIC_RUNTIME_RESPONSE_LANE_PHP);
    // Only an access verdict can authorize the reserved alias. A token merely
    // appearing on an otherwise-public URL still makes that response private,
    // but it does not create an alias another request may reuse.
    $privateProviderAsset = _stattic_access_private_cache_flag()
        && !_stattic_access_query_token_present()
        && (
            !empty($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_ALLOWLISTED_EXT])
            || _stattic_v4_client_url_is_provider_asset((string) ($context['client_path'] ?? ''))
        );
    $privateFileAlias = !empty($context['private_file_alias']);
    if ($privateFileAlias && (!$privateProviderAsset || $lane !== STATTIC_RUNTIME_RESPONSE_LANE_ACCEL)) {
        _stattic_private_file_alias_not_found();
    }

    // wp.cloud forces recognized-extension client URLs into a public asset
    // policy. A protected accel-capable entry first redirects to the reserved
    // extension-safe alias; the target rechecks access and then keeps X-Accel.
    // Entries whose compiled headers require PHP remain on PHP on both URLs.
    if ($privateFileAlias) {
        $headers['cache-control'] = STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
    } elseif ($privateProviderAsset && $lane === STATTIC_RUNTIME_RESPONSE_LANE_ACCEL) {
        _stattic_private_file_alias_redirect((string) ($context['client_path'] ?? ''));
    } elseif ($privateProviderAsset) {
        $lane = STATTIC_RUNTIME_RESPONSE_LANE_PHP;
    }
    $blobRelativePath = $blobSha === null ? null : _stattic_blob_relative_key((string) $context['space_id'], $blobSha);

    if ($blobRelativePath === null) {
        _stattic_send_response_headers($headers);
        http_response_code($status);
        exit;
    }
    $absolutePath = (string) $context['private_root'] . '/' . $blobRelativePath;

    // Needs the complete body, so it precedes every offload below.
    if (
        $tagPreviewToken !== null
        && $status === 200
        && str_starts_with(strtolower($headers['content-type'] ?? ''), 'text/html')
        && $length <= 2097152
    ) {
        $body = _stattic_v4_read_blob($context, $absolutePath, $blobRelativePath, $length);
        $body = _stattic_apply_spacefast_sdk_preview_to_html($body, $tagPreviewToken);
        unset($headers['etag']);
        $headers['cache-control'] = $privateCache
            ? STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE
            : STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
        $headers['content-length'] = (string) strlen($body);
        _stattic_send_response_headers($headers);
        http_response_code(200);
        if ($method === 'GET') {
            echo $body;
        }
        exit;
    }

    if ($lane === STATTIC_RUNTIME_RESPONSE_LANE_ACCEL && $status === 200) {
        _stattic_v4_ensure_blob($context, $absolutePath, $blobRelativePath);
        _stattic_send_server_file(
            $absolutePath,
            (string) $context['private_root'],
            $headers,
            $status
        );
    }

    if ($method === 'HEAD') {
        $headers['content-length'] = (string) $length;
        _stattic_send_response_headers($headers);
        http_response_code($status);
        exit;
    }

    _stattic_acquire_static_stream_admission($context, $length);
    $stream = _stattic_v4_open_blob($context, $absolutePath, $blobRelativePath);
    $headers['content-length'] = (string) $length;
    _stattic_send_response_headers($headers);
    http_response_code($status);
    _stattic_stream_file($stream, $length);
    fclose($stream);
    exit;
}

// The platform's own header ownership over one table response: the
// private-content boundary, then the two headers a publisher may never decide.
function _stattic_v4_platform_response_headers(array $context, array $headers, bool $privateCache): array
{
    _stattic_platform_vary_headers(
        is_array($context['request_vary_headers'] ?? null)
            ? $context['request_vary_headers']
            : []
    );
    if ($privateCache) {
        _stattic_platform_vary_headers(['Cookie']);
        $headers = array_change_key_case(
            _stattic_private_content_response_headers($headers),
            CASE_LOWER
        );
        _stattic_clear_private_content_response_headers();
    }
    $headers['x-content-type-options'] = 'nosniff';
    if (!empty($context['host_entry']['noindex'])) {
        $headers['x-robots-tag'] = 'noindex, nofollow';
    }
    return $headers;
}

// C21/D146: whether the provider's asset rewrite would claim this CLIENT URL.
// The allowlist is the generated one the compiler flags `xa` from, so both sides
// read one authority.
function _stattic_v4_client_url_is_provider_asset(string $clientPath): bool
{
    $slash = strrpos($clientPath, '/');
    $name = $slash === false ? $clientPath : substr($clientPath, $slash + 1);
    $dot = strrpos($name, '.');
    if ($dot === false) {
        return false;
    }
    return in_array(strtolower(substr($name, $dot + 1)), STATTIC_RUNTIME_PROVIDER_ASSET_EXTENSIONS, true);
}

function _stattic_v4_content_type_policy_check(array $context, array $headers): ?array
{
    $policy = $context['content_type_policy'];
    if (!is_array($policy)) {
        return null;
    }
    $contentType = $headers['content-type'] ?? '';
    return _stattic_content_type_allowlist_permits($policy, $contentType) ? null : $policy;
}

// _sf_promote_blob renders the tier-unavailable page and exits when the fetch
// fails or is shed, so a return here means the bytes are local.
function _stattic_v4_ensure_blob(array $context, string $absolutePath, string $blobRelativePath): void
{
    if (is_file($absolutePath)) {
        return;
    }
    _sf_promote_blob((string) $context['private_root'], (string) $context['space_id'], $blobRelativePath);
}

/** @return resource */
function _stattic_v4_open_blob(array $context, string $absolutePath, string $blobRelativePath)
{
    $stream = fopen($absolutePath, 'rb');
    if ($stream === false) {
        _sf_promote_blob((string) $context['private_root'], (string) $context['space_id'], $blobRelativePath);
        $stream = fopen($absolutePath, 'rb');
    }
    if ($stream === false) {
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime response entry points to a missing blob.');
    }
    return $stream;
}

function _stattic_v4_read_blob(array $context, string $absolutePath, string $blobRelativePath, int $length): string
{
    $stream = _stattic_v4_open_blob($context, $absolutePath, $blobRelativePath);
    $body = stream_get_contents($stream, $length);
    fclose($stream);
    if (!is_string($body)) {
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime response blob could not be read.');
    }
    return $body;
}

// ---- action entries --------------------------------------------------------

function _stattic_v4_dispatch_action(array $context, array $action, array $entry, string $requestPath): void
{
    $type = is_string($action['t'] ?? null) ? $action['t'] : '';
    $serving = is_array($context['serving']) ? $context['serving'] : [];
    // The action's methods are enforced HERE, before any dispatch, or not at
    // all: every lane below hands the request to a module that has no idea which
    // methods this table key declared. A `404` action declares nothing servable,
    // so it is not method-gated.
    if ($type !== STATTIC_RUNTIME_RESPONSE_ACTION_NOT_FOUND) {
        $methods = is_array($action['methods'] ?? null) ? $action['methods'] : null;
        // A listing is a compiled page carrying no `methods` of its own.
        if ($methods === null && $type === STATTIC_RUNTIME_RESPONSE_ACTION_LISTING) {
            $methods = ['GET', 'HEAD'];
        }
        if ($methods !== null && !in_array((string) $context['method'], $methods, true)) {
            $allow = array_values(array_filter($methods, 'is_string'));
            _stattic_render_method_not_allowed_lazy($allow === [] ? ['GET', 'HEAD'] : $allow);
        }
    }
    if ($type === STATTIC_RUNTIME_RESPONSE_ACTION_ZERO || $type === STATTIC_RUNTIME_RESPONSE_ACTION_FUNCTION) {
        require_once __DIR__ . '/../shared/admission.php';
        _stattic_admission_acquire_once((string) $context['private_root'], $serving, 'zero');
        require_once __DIR__ . '/zero.php';
        _stattic_invoke_zero(
            $action,
            (string) $context['version_root'],
            $serving,
            (string) $context['host'],
            $requestPath,
            (string) $context['request_uri'],
            (string) $context['method']
        );
    }
    if ($type === STATTIC_RUNTIME_RESPONSE_ACTION_PHP) {
        // A committed functions/<route>.php, executed in this worker AFTER the
        // in-process hardening prelude. The bridge owns the whole lifecycle
        // (admission, identity, jail, include, send) and never returns.
        require_once __DIR__ . '/php-functions.php';
        _stattic_php_functions_serve($context, $action, $requestPath);
    }
    if ($type === STATTIC_RUNTIME_RESPONSE_ACTION_PROXY) {
        _stattic_enforce_access_for_proxy($serving, (string) $context['host'], $requestPath, (string) $context['request_uri']);
        require_once __DIR__ . '/proxy.php';
        _stattic_proxy_request(['action' => 'proxy'] + $action, '/', $serving);
    }
    if ($type === STATTIC_RUNTIME_RESPONSE_ACTION_LISTING) {
        _stattic_v4_serve_listing($context, $action, $entry, $requestPath);
    }
    if ($type === STATTIC_RUNTIME_RESPONSE_ACTION_NOT_FOUND) {
        return;
    }
    _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime response action metadata is unknown.');
}

// A protected listing is joined per request from row fragments, so a visitor
// only ever sees the rows they may see.
function _stattic_v4_serve_listing(array $context, array $action, array $entry, string $requestPath): never
{
    $shell = is_string($action['shell'] ?? null)
        ? _stattic_v4_blob_contents($context, $action['shell'])
        : null;
    if ($shell === null) {
        _stattic_render_runtime_invariant_error_lazy('file-missing', 'Runtime listing shell is missing.');
    }
    $open = (bool) $context['open'];
    $rows = '';
    foreach (is_array($action['rows'] ?? null) ? $action['rows'] : [] as $row) {
        if (!is_array($row) || !is_string($row[0] ?? null) || !is_string($row[1] ?? null)) {
            continue;
        }
        // Rows are keyed by their committed path so this check can be made.
        if (!$open && _stattic_v4_listing_row_visible($context, $row[0]) !== true) {
            continue;
        }
        $fragment = _stattic_v4_blob_contents($context, $row[1]);
        if (is_string($fragment)) {
            $rows .= $fragment;
        }
    }

    // The entry's compiled header set, with this version's `_headers` rules
    // applied over it exactly as a compiled body gets them, ships with the
    // joined body through the same platform boundary the entry lane applies.
    $headers = _stattic_v4_platform_response_headers(
        $context,
        _stattic_v4_entry_headers($context, $entry),
        (bool) $context['private_cache']
    );
    $policy = _stattic_cache_policy([
        'private' => (bool) $context['private_cache'],
        'public' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL,
    ]);
    $headers = array_merge(
        $headers,
        array_change_key_case(_stattic_cache_policy_header_map($policy), CASE_LOWER)
    );
    $headers['content-type'] = 'text/html; charset=utf-8';
    $body = str_replace(STATTIC_RUNTIME_LISTING_ROWS_MARKER, $rows, $shell);
    $headers['content-length'] = (string) strlen($body);
    _stattic_send_response_headers($headers);
    http_response_code(200);
    if ((string) $context['method'] !== 'HEAD') {
        echo $body;
    }
    exit;
}

// Probe-only: a row the visitor cannot reach is omitted, never challenged for.
function _stattic_v4_listing_row_visible(array $context, string $path): bool
{
    require_once __DIR__ . '/access-rules.php';
    $admitted = _stattic_enforce_scoped_admission(
        is_array($context['serving']) ? $context['serving'] : [],
        (string) $context['host'],
        '/' . ltrim($path, '/'),
        true,
        false
    );
    return $admitted !== null;
}

function _stattic_v4_blob_contents(array $context, string $sha): ?string
{
    $relative = _stattic_blob_relative_key((string) $context['space_id'], $sha);
    if ($relative === null) {
        return null;
    }
    $absolute = (string) $context['private_root'] . '/' . $relative;
    $body = file_get_contents($absolute);
    if (is_string($body)) {
        return $body;
    }
    _sf_promote_blob((string) $context['private_root'], (string) $context['space_id'], $relative);
    $body = file_get_contents($absolute);
    return is_string($body) ? $body : null;
}

// The "\0rules" entry carries both sections under the entry's ACTION key, the
// same slot every other non-file entry uses, with `redirects`/`headers`
// sections: the only shape the compiler emits.
function _stattic_v4_rule_section(?array $rulesEntry, string $section): ?array
{
    $rules = $rulesEntry[STATTIC_RUNTIME_RESPONSE_ENTRY_ACTION][$section] ?? null;
    if (!is_array($rules) || (empty($rules['exact']) && empty($rules['pattern']))) {
        return null;
    }
    return [
        'exact' => $rules['exact'] ?? [],
        'pattern' => $rules['pattern'] ?? [],
    ];
}

// The complete header set for one table response: the entry's compiled `h` with
// the version's `_headers` rules applied over it. The rules match against the
// CLIENT path, because a mount prefix or a residue rewrite moves the table key
// that answers away from the URL the visitor asked for.
function _stattic_v4_entry_headers(array $context, array $entry): array
{
    $headers = [];
    foreach (is_array($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_HEADERS] ?? null) ? $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_HEADERS] : [] as $name => $value) {
        if (is_string($name) && is_scalar($value)) {
            $headers[strtolower($name)] = (string) $value;
        }
    }
    $forcedDownloadContentType = strtolower((string) ($headers['content-disposition'] ?? '')) === 'attachment'
        ? ($headers['content-type'] ?? null)
        : null;
    [$ruleHeaders, $removed] = _stattic_v4_rule_headers($context);
    // Removals run first and survive collection, so a rule can delete a header
    // the compiler put in `h` and not only one an earlier rule set.
    foreach (array_keys($removed) as $name) {
        unset($headers[(string) $name]);
    }
    foreach ($ruleHeaders as $name => $value) {
        $headers[$name] = $value;
    }
    // PHP-like tenant bytes are always inert downloads. Publisher rules may
    // customize ordinary response metadata, but cannot turn executable-looking
    // uploads back into browser-rendered content or remove the attachment gate.
    if (is_string($forcedDownloadContentType) && $forcedDownloadContentType !== '') {
        $headers['content-type'] = $forcedDownloadContentType;
        $headers['content-disposition'] = 'attachment';
    }
    return $headers;
}

/**
 * The version's `_headers` rules, evaluated once per request against the
 * client path and the request host.
 *
 * @return array{0: array<string, string>, 1: array<string, mixed>} [set, removed]
 */
function _stattic_v4_rule_headers(array $context): array
{
    static $memo = null;
    if (is_array($memo)) {
        return $memo;
    }
    $rules = $context['header_rules'] ?? null;
    if (!is_array($rules)) {
        return $memo = [[], []];
    }
    require_once __DIR__ . '/headers.php';
    $clientPath = is_string($context['client_path'] ?? null) && $context['client_path'] !== ''
        ? (string) $context['client_path']
        : '/';
    [$headers, $removed] = _stattic_collect_response_headers($rules, (string) $context['host'], $clientPath);
    $lowered = [];
    foreach ($headers as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $lowered[strtolower($name)] = $value;
        }
    }
    return $memo = [$lowered, $removed];
}

// Pattern routes (`/api/:id`) cannot be table keys, so their artifacts are
// consulted directly. Both probes are file-existence gated.
function _stattic_v4_dispatch_pattern_routes(array $context, string $requestPath, string $requestMethod, string $requestUri): void
{
    $versionDir = (string) $context['version_dir'];
    $versionRoot = (string) $context['version_root'];
    $lookup = ltrim($requestPath, '/');

    // The gate is the pattern artifact, not `zero/config.json`: the latter only
    // exists when the finalize body also carried a `zero` block.
    if (is_file($versionDir . '/zero/routes.php')) {
        require_once __DIR__ . '/zero-routes.php';
        $result = _stattic_resolve_zero_route_action($versionRoot, $lookup, $requestMethod);
        if (!empty($result['method_not_allowed'])) {
            // Zero's resolver reports no per-route Allow, so the recorded set is
            // the default its terminal 405 always advertised. Recording instead
            // of terminating lets a Functions route below still claim the
            // method; the caller renders the union when nothing does.
            _stattic_method_decline(['GET', 'HEAD']);
        }
        if (is_array($result['action'] ?? null)) {
            require_once __DIR__ . '/../shared/admission.php';
            _stattic_admission_acquire_once((string) $context['private_root'], $context['serving'], 'zero');
            require_once __DIR__ . '/zero.php';
            _stattic_invoke_zero(
                $result['action'],
                $versionRoot,
                $context['serving'],
                (string) $context['host'],
                $requestPath,
                $requestUri,
                $requestMethod
            );
        }
    }

    if (_stattic_version_has_functions($versionRoot)) {
        require_once __DIR__ . '/functions-dispatch.php';
        $functionsRoute = _stattic_resolve_functions_route_action($versionRoot, $lookup, $requestMethod);
        // A path the table claims at other methods ends at a 405, never a fall
        // through to the SPA index or a 404: the route exists, the verb does
        // not. The router's Allow joins the union, which the caller renders once
        // every lane has had its chance.
        if (is_array($functionsRoute) && !empty($functionsRoute['method_not_allowed'])) {
            $allow = array_values(array_filter($functionsRoute['allow'] ?? [], 'is_string'));
            _stattic_method_decline($allow === [] ? ['GET', 'HEAD'] : $allow);
        }
        if (is_array($functionsRoute) && ($functionsRoute['action'] ?? null) === 'dispatch_functions') {
            // The same slot the exact-route Functions action takes in
            // _stattic_v4_dispatch_action: a dispatch holds this PHP-FPM worker
            // for up to 30s and its relay calls re-enter the same pool, so this
            // lane pays the same uncacheable-concurrency admission the Zero
            // branch above does.
            _stattic_admission_acquire_once((string) $context['private_root'], $context['serving'], 'zero');
            _stattic_functions_dispatch(
                $versionRoot,
                (string) $context['space_id'],
                (string) $context['version_id'],
                $requestPath,
                $requestMethod,
                (string) $context['host'],
                (bool) $context['private_cache'],
                is_array($context['serving']) ? $context['serving'] : []
            );
        }
    }
}

// The one 403 terminal: `$code` stays a caller's word, because the page id
// would rewrite it and change the JSON representation.
function _stattic_v4_render_forbidden(string $pageId, string $code, string $message): never
{
    require_once __DIR__ . '/../shared/errors.php';
    _stattic_serve_page($pageId, [
        'status' => 403,
        'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE],
        'message' => $message,
        'code' => $code,
    ]);
    exit;
}

// The entry-stage rows of the control-path table, walked in table order. Only
// paths the front door admitted reach here.
function _stattic_dispatch_control_path(array $serving, string $requestPath, string $requestHost, string $privateRoot): void
{
    $handler = _stattic_control_path_entry_handler($requestPath);
    // uploads_object dispatches further down, after the access check: its bytes
    // are Space content.
    if ($handler === null || $handler === 'uploads_object') {
        return;
    }
    require_once __DIR__ . '/access-rules.php';
    if ($handler === 'comments_exchange') {
        require_once __DIR__ . '/spacefast-sdk.php';
    }
    match ($handler) {
        'access_callback' => _stattic_access_handle_callback($serving, $requestHost, $privateRoot),
        'access_client_script' => _stattic_access_handle_client_script(),
        'access_logout' => _stattic_access_handle_logout($requestHost),
        'access_password' => _stattic_access_handle_password($serving, $requestHost, $privateRoot),
        'access_email' => _stattic_access_handle_email_verification($serving, $requestHost, $privateRoot),
        'access_request' => _stattic_access_handle_request_invite($serving, $requestHost, $privateRoot),
        'comments_exchange' => _stattic_comments_handle_exchange($privateRoot, $serving, $requestHost, $requestPath),
        'access_link_entry' => _stattic_access_handle_link_entry($serving, $requestPath, $requestHost),
    };
}

// An unknown page_id is a validation error, never a silent degrade.
function _stattic_runtime_tombstone_page_variant(string $pageId): ?array
{
    $variant = STATTIC_TOMBSTONE_VARIANTS[$pageId] ?? null;
    return $variant === null ? null : [
        'page_id' => $variant['template_id'],
        'status' => $variant['status'],
        'body' => $variant['body'],
        'cache_control' => $variant['cache_control'],
    ];
}

function _stattic_render_platform_action(array $action, bool $privateCache = false): never
{
    $actionKind = $action['action'] ?? null;
    if ($actionKind === 'tombstone') {
        // Reason-differentiated tombstones: CSAM matches the undeployed 503 (no
        // signal), DMCA/copyright 451, suspended tenant 402.
        $pageId = $action['page_id'] ?? null;
        $variant = is_string($pageId) ? _stattic_runtime_tombstone_page_variant($pageId) : null;
        // Fail closed: a tombstone that cannot name its variant must never fall
        // back through to the Space's own bytes.
        if ($variant === null) {
            _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime tombstone page id is missing.');
        }
        // The variant's policy is the floor: the CSAM variant is no-store, and
        // an unvalidated action must not talk the edge into holding it.
        $policy = _stattic_cache_policy([
            'private' => $privateCache,
            'public' => (string) ($action['cache_control'] ?? $variant['cache_control']),
        ]);
        _stattic_render_platform_page_lazy(
            $variant['page_id'],
            $variant['status'],
            ['Cache-Control' => $policy['cache_control']],
            $variant['body'],
            $policy['private']
        );
    }
    if ($actionKind === 'platform_error') {
        $policy = _stattic_cache_policy([
            'private' => $privateCache,
            'public' => (string) ($action['cache_control'] ?? STATTIC_CACHE_CONTROL_NO_STORE),
        ]);
        $headers = ['Cache-Control' => $policy['cache_control']];
        if (is_array($action['response_headers'] ?? null)) {
            foreach ($action['response_headers'] as $name => $value) {
                if (is_string($name) && is_string($value) && $name !== '' && $value !== '') {
                    $headers[$name] = $value;
                }
            }
        }
        _stattic_render_platform_page_lazy(
            (string) ($action['page_id'] ?? 'runtime-invariant-error'),
            (int) ($action['status'] ?? 503),
            $headers,
            (string) ($action['message'] ?? "Runtime platform error.\n"),
            $policy['private']
        );
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
    return _stattic_platform_error_action('undeployed', 503, "This space hasn't been published yet.\n");
}

function _stattic_method_not_allowed_action(array $allowedMethods = ['GET', 'HEAD']): array
{
    return _stattic_platform_error_action('method-not-allowed', 405, "Method Not Allowed\n", 'no-store', ['Allow' => implode(', ', $allowedMethods)]);
}

function _stattic_matched_host_route_action(array $route): array
{
    $action = $route['route_action'] ?? null;
    if (
        is_array($action)
        && in_array(($action['action'] ?? null), ['serve', 'redirect', 'proxy', 'robots_txt', 'platform_error', 'tombstone'], true)
    ) {
        return $action;
    }
    _stattic_render_runtime_invariant_error_lazy('route-action-metadata-missing', 'Runtime host route action metadata is missing.');
}

function _stattic_v4_match_host_route(array $routes, string $requestPath, string $requestMethod): ?array
{
    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }
        $method = is_string($route['method'] ?? null) ? strtoupper($route['method']) : null;
        if ($method !== null && $method !== $requestMethod) {
            continue;
        }
        $remainder = _stattic_v4_match_path_prefix((string) ($route['location'] ?? '/'), $requestPath);
        if ($remainder === null) {
            continue;
        }
        $route['_remainder'] = $remainder;
        return $route;
    }
    return null;
}

function _stattic_v4_match_path_prefix(string $prefix, string $requestPath): ?string
{
    $normalized = rtrim($prefix, '/');
    if ($normalized === '' || $normalized === '/') {
        return $requestPath;
    }
    if ($requestPath === $normalized) {
        return '/';
    }
    if (str_starts_with($requestPath, $normalized . '/')) {
        return substr($requestPath, strlen($normalized));
    }
    return null;
}

function _stattic_send_route_redirect(array $action, string $remainder, bool $privateCache = false): never
{
    _stattic_send_cache_policy_headers($privateCache, STATTIC_DEFAULT_EDGE_CACHE_CONTROL);
    header(
        'Location: ' . _stattic_append_current_query_to_url(
            _stattic_append_path_to_url((string) $action['destination'], $remainder)
        ),
        true,
        (int) ($action['status'] ?? 302)
    );
    exit;
}

function _stattic_send_platform_404(bool $privateCache, string $requestPath, bool $requestVarying): never
{
    // Structural backstop: the SPA fallback and publisher-404 entries above are
    // static entries that decline non-GET/HEAD into the union, so the union must
    // win again here. A skipped-for-method path never reports 404, whichever
    // rung the ladder ran out on.
    _stattic_render_method_declined_405_if_any();
    require_once __DIR__ . '/../shared/errors.php';
    $serving = is_array($GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null) ? $GLOBALS['SPACEFAST_PAGE_SERVING'] : [];
    // Intermediaries can disagree about decoding a residual dot segment further,
    // so its miss must never become a shared 404.
    $policy = _stattic_cache_policy([
        'private' => $privateCache || $requestVarying,
        'no_store' => $requestVarying,
        'public' => _stattic_path_has_residual_dot_segment($requestPath)
            ? STATTIC_CACHE_CONTROL_NO_STORE
            : STATTIC_DEFAULT_EDGE_CACHE_CONTROL,
    ]);
    _stattic_serve_page('404', [
        'status' => 404,
        'headers' => ['Cache-Control' => $policy['cache_control']],
        'private' => $policy['private'],
        'message' => 'Not Found',
        'code' => 'not_found',
        'customizable' => $serving !== [],
        'serving' => $serving,
        'request_path' => _stattic_runtime_request_path(),
    ]);
    exit;
}

function _stattic_action_allows_method(?array $action, string $requestMethod): bool
{
    if (!is_array($action) || !array_key_exists('methods', $action)) {
        return true;
    }
    return is_array($action['methods']) && in_array($requestMethod, $action['methods'], true);
}

// Reserved and asset-looking paths never fall through to the SPA fallback.
//
// `/` is deliberately NOT reserved: it is the first application route a shell
// owns. This branch is only reached when nothing answered `/` already, so a
// version with a real index is unaffected. A version whose homepage IS the
// configured 200 fallback (no inferable root index) would otherwise 404 at its
// own front door.
function _stattic_lookup_not_found_is_terminal(string $lookup): bool
{
    $path = trim(strtolower($lookup), '/');
    if (
        $path === '.well-known/spacefast-runtime'
        || $path === '.well-known/stattic-runtime'
        || $path === 'zero'
        || str_starts_with($path, 'zero/')
    ) {
        return true;
    }
    if (isset(STATTIC_PRIVATE_COMPILE_FILES[$path]) || isset(STATTIC_PRIVATE_CONFIG_FILES[$path])) {
        return true;
    }
    return _stattic_path_has_hidden_segment($path);
}

// Deliberately a denylist, not "any extension present", so dotted client-side
// routes (`/users/jane.doe`, `/v1.2.3`) stay SPA-eligible.
const STATTIC_LOOKUP_ASSET_EXTENSIONS = [
    'avif' => true, 'bmp' => true, 'br' => true, 'css' => true, 'eot' => true,
    'gif' => true, 'gz' => true, 'ico' => true, 'jpeg' => true, 'jpg' => true,
    'js' => true, 'json' => true, 'map' => true, 'mjs' => true, 'mp3' => true,
    'mp4' => true, 'ogg' => true, 'otf' => true, 'png' => true, 'svg' => true,
    'ttf' => true, 'wasm' => true, 'webm' => true, 'webmanifest' => true,
    'webp' => true, 'woff' => true, 'woff2' => true, 'xml' => true,
    'pdf' => true, 'csv' => true, 'rtf' => true, 'txt' => true,
    'doc' => true, 'docx' => true, 'xls' => true, 'xlsx' => true,
    'ppt' => true, 'pptx' => true, 'odt' => true, 'ods' => true,
    'odp' => true, 'epub' => true,
    'zip' => true, 'tar' => true, 'tgz' => true, 'rar' => true,
    '7z' => true, 'bz2' => true, 'xz' => true, 'zst' => true,
    'wav' => true, 'flac' => true, 'aac' => true, 'm4a' => true,
    'm4v' => true, 'mov' => true, 'avi' => true, 'mkv' => true,
    'weba' => true, 'oga' => true, 'ogv' => true, 'opus' => true,
    'wmv' => true, 'flv' => true, 'mpg' => true, 'mpeg' => true,
    'm3u8' => true, 'tif' => true, 'tiff' => true, 'heic' => true,
    'heif' => true, 'jxl' => true,
    'yaml' => true, 'yml' => true, 'toml' => true, 'sql' => true,
    'ndjson' => true, 'jsonl' => true, 'geojson' => true,
    'ics' => true, 'vcf' => true,
    'exe' => true, 'dmg' => true, 'pkg' => true, 'deb' => true,
    'rpm' => true, 'apk' => true, 'msi' => true, 'iso' => true,
    'bin' => true, 'appimage' => true,
];

function _stattic_lookup_is_known_asset_extension(string $path): bool
{
    return isset(STATTIC_LOOKUP_ASSET_EXTENSIONS[strtolower((string) pathinfo($path, PATHINFO_EXTENSION))]);
}

function _stattic_render_platform_page_lazy(string $pageId, int $status, array $headers = [], string $fallback = '', bool $private = false): void
{
    require_once __DIR__ . '/../shared/errors.php';
    _stattic_render_platform_page($pageId, $status, $headers, $fallback, $private);
}

function _stattic_render_method_not_allowed_lazy(array $allowedMethods = ['GET', 'HEAD']): void
{
    _stattic_render_platform_action(_stattic_method_not_allowed_action($allowedMethods));
}

// A lane's contribution to the request's Allow union: "this path is mine at
// THESE methods, but not at the one asked". Recording instead of terminating is
// what lets a later lane (a Functions route behind a static file) claim the
// method the earlier one refused.
function _stattic_method_decline(array $allowedMethods): void
{
    $union = is_array($GLOBALS['SPACEFAST_METHOD_DECLINED_ALLOW'] ?? null)
        ? $GLOBALS['SPACEFAST_METHOD_DECLINED_ALLOW']
        : [];
    foreach ($allowedMethods as $method) {
        if (is_string($method) && $method !== '') {
            $union[$method] = true;
        }
    }
    $GLOBALS['SPACEFAST_METHOD_DECLINED_ALLOW'] = $union;
}

// The accumulated 405, rendered only when some lane declined for its method.
// Ordered by the visitor-method table so the emitted Allow is stable no matter
// which lane recorded first.
function _stattic_render_method_declined_405_if_any(): void
{
    $union = $GLOBALS['SPACEFAST_METHOD_DECLINED_ALLOW'] ?? null;
    if (!is_array($union) || $union === []) {
        return;
    }
    $allow = array_values(array_intersect(STATTIC_VISITOR_METHODS, array_keys($union)));
    foreach (array_keys($union) as $method) {
        if (!in_array($method, $allow, true)) {
            $allow[] = (string) $method;
        }
    }
    _stattic_render_method_not_allowed_lazy($allow === [] ? ['GET', 'HEAD'] : $allow);
}

// The session supplies the authority; the Referer only selects which scoped page
// Grant to evaluate. Never authorize on Referer alone.
function _stattic_spacefast_sdk_access_path(array $serving, string $requestHost, string $requestPath): string
{
    if (
        $requestPath !== STATTIC_SPACEFAST_SDK_PATH
        || strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''))) !== 'script'
    ) {
        return $requestPath;
    }
    require_once __DIR__ . '/access-rules.php';
    if (_stattic_visitor_cookie_from_request() === '') {
        return $requestPath;
    }
    if (!is_array(_stattic_verify_cookie_identity($serving, $requestHost))) {
        return $requestPath;
    }
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $refererHost = $referer === '' ? null : parse_url($referer, PHP_URL_HOST);
    $refererPath = $referer === '' ? null : parse_url($referer, PHP_URL_PATH);
    if (
        !is_string($refererHost)
        || !is_string($refererPath)
        || $refererPath === ''
        || _stattic_canonicalize_host($refererHost) !== _stattic_canonicalize_host($requestHost)
    ) {
        return $requestPath;
    }
    return $refererPath;
}

// Space-scoped and unversioned: every app tier reaches this same handler, and a
// publish or rollback only changes the code calling it, never its records.
function _stattic_dispatch_storage(
    string $privateRoot,
    array $serving,
    string $requestHost,
    string $requestPath,
    string $requestMethod
): void {
    require_once __DIR__ . '/../shared/admission.php';
    _stattic_admission_acquire_once($privateRoot, $serving, 'storage');
    require_once __DIR__ . '/storage.php';
    _stattic_storage_handle(
        $privateRoot,
        $serving,
        $requestHost,
        $requestPath,
        $requestMethod
    );
}

// Fallback for callers outside the main serve pipeline; a verified-token allow
// populates the §3.2 identity-forwarding seam consumed by runtime/proxy.php.
function _stattic_enforce_access_for_proxy(array $serving, string $requestHost, string $requestPath, string $requestUri): void
{
    if (!empty($GLOBALS['SPACEFAST_ACCESS_ENFORCED'])) {
        return;
    }
    require_once __DIR__ . '/access-rules.php';
    $GLOBALS['SPACEFAST_ACCESS_ENFORCED'] = true;
    if (_stattic_enforce_scoped_admission($serving, $requestHost, $requestPath, false, false)) {
        _stattic_access_private_cache_flag(true);
    }
}

// Matching runs against the compiled Content-Type. Metadata is the single
// serve-time truth (nosniff), so bytes can never smuggle past the allowlist.
function _stattic_serving_content_type_policy(array $serving): ?array
{
    $policy = $serving['content_types'] ?? null;
    return is_array($policy) && is_array($policy['allowed'] ?? null) ? $policy : null;
}

function _stattic_content_type_allowlist_permits(array $policy, string $contentType): bool
{
    $normalized = strtolower(trim(explode(';', $contentType, 2)[0]));
    return array_any($policy['allowed'], static function (mixed $pattern) use ($normalized): bool {
        if (!is_string($pattern) || $pattern === '') {
            return false;
        }
        $pattern = strtolower($pattern);
        return str_ends_with($pattern, '/*')
            ? str_starts_with($normalized, substr($pattern, 0, -1))
            : $normalized === $pattern;
    });
}

function _stattic_render_content_type_blocked(array $policy): never
{
    $message = is_string($policy['blocked_message'] ?? null) && $policy['blocked_message'] !== ''
        ? $policy['blocked_message']
        : 'This file type is not served on this space.';
    // no-store: the policy clears on the next overlay swap, so it must never
    // outlive it in a shared cache.
    _stattic_response_send(403, $message . "\n", 'text/plain; charset=utf-8', [
        'Cache-Control' => 'no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
    ]);
}

function _stattic_spacefast_tag_preview_token(string $requestMethod): ?string
{
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        return null;
    }
    $raw = $_GET[STATTIC_TAG_PREVIEW_QUERY_NAME] ?? null;
    return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
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

function _stattic_acquire_static_stream_admission(array $context, int $bytes): void
{
    if ($bytes < STATTIC_STATIC_STREAM_ADMISSION_BYTES) {
        return;
    }
    require_once __DIR__ . '/../shared/admission.php';
    _stattic_admission_acquire_once(
        (string) $context['private_root'],
        is_array($context['serving']) ? $context['serving'] : [],
        'static_stream'
    );
}
