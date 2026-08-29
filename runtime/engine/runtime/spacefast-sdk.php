<?php
declare(strict_types=1);

// This public route runs outside the paths that materialize WP.Cloud's Atomic
// persistent data, so the bootstrap config is loaded here explicitly.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/response.php';

function _stattic_serve_spacefast_sdk(
    string $privateRoot,
    array $serving,
    string $requestHost,
    string $requestMethod,
    bool $privateCache
): void
{
    if (!$privateCache) {
        _stattic_send_spacefast_sdk_cors_headers();
    }
    if ($requestMethod === 'OPTIONS') {
        http_response_code(204);
        _stattic_send_cache_policy_headers($privateCache, STATTIC_CACHE_CONTROL_NO_STORE);
        exit;
    }
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        // JavaScript, not a problem document: this URL is loaded by a <script>
        // tag, and a JSON body there is a parse error instead of a refusal.
        _stattic_method_not_allowed('GET, HEAD, OPTIONS', [
            'body' => "window.Spacefast=window.Spacefast||{error:'method_not_allowed'};",
            'media_type' => 'application/javascript; charset=utf-8',
        ]);
    }

    $previewToken = _stattic_spacefast_sdk_preview_token();
    $body = _stattic_spacefast_sdk_bootstrap($privateRoot, $serving, $requestHost, $previewToken);
    $revision = _stattic_spacefast_sdk_revision($body);
    $etag = '"' . $revision . '"';

    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8', false);
    // A versioned SDK URL (?v=<engine/content token>, baked in by the control
    // plane) is content-addressed: the token changes whenever the body would,
    // so this response may pin immutably. The unversioned URL and every preview
    // stay short-lived and revalidated, so an engine revision is never frozen
    // into a visitor's cache. `_stattic_send_cache_policy_headers` still
    // downgrades private responses whatever this value is.
    $publicCacheControl = ($previewToken === null && _stattic_spacefast_sdk_versioned_request())
        ? 'public, max-age=31536000, immutable'
        : STATTIC_DEFAULT_EDGE_CACHE_CONTROL;
    _stattic_send_cache_policy_headers($privateCache, $publicCacheControl);
    header('ETag: ' . $etag, false);
    header('X-Spacefast-Sdk-Revision: ' . $revision, false);
    // No conditional branch (§15 D122): the platform never delivers
    // If-None-Match to the origin, and the edge answers conditionals off its
    // own HIT with the ETag above. A 304 written here could only fire for a
    // hand-rolled request that bypassed the edge.
    if ($requestMethod !== 'HEAD') {
        echo $body;
    }
    exit;
}

function _stattic_comments_request_origin(string $requestHost): ?string
{
    $host = _stattic_canonicalize_host($requestHost);
    if ($host === '' || preg_match('/[\\x00-\\x20\\x7f\\/\\\\]/', $host) === 1) {
        return null;
    }
    // The edge terminates TLS, so PHP may never see https: only the explicit
    // dev/test flag selects http (same contract as _stattic_cookies_secure).
    return (_stattic_cookies_secure() ? 'https' : 'http') . '://' . $host;
}

function _stattic_comments_render_json(int $status, array $body): never
{
    _stattic_json_response(
        $status,
        $body,
        $status >= 400 ? STATTIC_PROBLEM_MEDIA_TYPE : 'application/json',
        _stattic_private_content_response_headers([
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]),
    );
}

// There is no second cookie: the unified host session carries the Comments
// identity, and the anonymous id stays server-owned. The page never names it.
function _stattic_comments_visitor_identity(
    array $serving,
    string $requestHost,
    ?array $sessionIdentity
): array {
    $fromSession = _stattic_access_identity_comments($sessionIdentity);
    if ($fromSession !== null) {
        return $fromSession;
    }
    // No pseudonym yet. Writing one also settles the session id: a stateless
    // session mints its own on that write, a recorded one already has it.
    $remembered = _stattic_access_session_remember(
        $serving,
        $requestHost,
        $sessionIdentity,
        ['anonymousId' => _stattic_collab_mint_anonymous_id()]
    );
    $visitor = $remembered === null
        ? null
        : _stattic_access_identity_comments(['sessionRecord' => $remembered]);
    if ($visitor === null) {
        _stattic_render_json_unauthenticated('comments_unavailable');
    }
    return $visitor;
}

function _stattic_comments_handle_exchange(
    string $privateRoot,
    array $serving,
    string $requestHost,
    string $requestPath
): void {
    // Required here rather than trusting the dispatcher: a missing include must
    // not turn a Comments boot into a fatal instead of a typed refusal.
    require_once __DIR__ . '/access-rules.php';
    if (_stattic_runtime_request_method() !== 'POST') {
        _stattic_method_not_allowed('POST');
    }
    if (!_stattic_access_same_origin_post($requestHost)) {
        _stattic_render_json_unauthenticated('comments_origin_invalid');
    }
    // 8 KiB leaves headroom for the principal/authorities envelope added before
    // re-encoding against the control plane's 16 KiB payload cap.
    $raw = _stattic_request_body_contents();
    $input = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true) : null;
    $pagePath = is_array($input) && is_string($input['pagePath'] ?? null)
        ? _stattic_scope_path($input['pagePath'])
        : null;
    // 1024 matches the control plane's accessScopePathSchema ceiling.
    if ($pagePath === null || strlen($pagePath) > 1024) {
        _stattic_render_json_unauthenticated('comments_path_invalid');
    }
    _stattic_admission_acquire_access_lane($privateRoot, $serving);
    if (_stattic_enforce_scoped_admission($serving, $requestHost, $pagePath, true) === null) {
        _stattic_render_json_unauthenticated('comments_denied');
    }
    // The boot copy of this configuration is embedded in the SDK bootstrap, so
    // this lane is the background revalidate that syncs a toggle flipped
    // since the page's sdk.js response was generated. Only the lanes below it,
    // which MINT something, reach the control plane: a ticket is auth, and auth
    // is not this host's to issue.
    if ($requestPath === STATTIC_COMMENTS_CONFIG_PATH) {
        _stattic_comments_render_json(200, [
            'data' => _stattic_comments_local_config($privateRoot, $serving, $requestHost),
        ]);
    }

    $isTicket = $requestPath === STATTIC_COMMENTS_TICKET_PATH;
    $isVersionUrls = $requestPath === STATTIC_COMMENTS_VERSION_URLS_PATH;
    // Both spellings identify the same mint: the config response advertises the
    // canonical `/__zero` path, while frozen capsule clients baked the legacy
    // one. Missing either sends the request down the Comments ticket lane and
    // mints a ticket Cast will not accept.
    $isZeroRealtimeTicket = $requestPath === STATTIC_ZERO_REALTIME_TICKET_PATH
        || $requestPath === STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH;
    $exchange = _stattic_access_page_exchange($serving);
    $exchangeKey = match (true) {
        $isZeroRealtimeTicket => 'zeroRealtimeTicketUrl',
        $isVersionUrls => 'commentsVersionUrlsUrl',
        default => 'commentsTicketUrl',
    };
    $exchangeUrl = is_array($exchange) ? (string) ($exchange[$exchangeKey] ?? '') : '';
    $origin = _stattic_comments_request_origin($requestHost);
    if ($exchangeUrl === '' || $origin === null) {
        _stattic_render_json_unauthenticated('comments_unavailable');
    }
    $identity = _stattic_verify_cookie_identity($serving, $requestHost);
    $authorities = [];
    // A missing sessionVersion degrades to a guest ticket rather than failing
    // control-plane validation with an impossible negative version.
    $sessionVersion = _stattic_session_version($serving);
    if ($sessionVersion >= 0 && is_array($identity) && is_array($identity['authorityEntries'] ?? null)) {
        foreach ($identity['authorityEntries'] as $entry) {
            $authorities[] = [
                'authorityReference' => $entry['reference'],
                'authorityGeneration' => $entry['generation'],
                'sessionVersion' => $sessionVersion,
                'emailVerifiedUntil' => $entry['emailVerifiedUntil'] ?? null,
            ];
        }
    }
    $visitor = $isZeroRealtimeTicket
        ? null
        : _stattic_comments_visitor_identity($serving, $requestHost, $identity);
    $payload = $isZeroRealtimeTicket ? [
        'origin' => $origin,
        'pagePath' => $pagePath,
        'versionId' => is_string($serving['version_id'] ?? null) ? $serving['version_id'] : '',
    ] : [
        'origin' => $origin,
        'pagePath' => $pagePath,
        'visitorSessionId' => $visitor['sessionId'],
        // The mint derives actorKind from the principal alone, never from the
        // authorities below, which say only what the session may do.
        'principal' => _stattic_access_identity_principal($identity),
        ...(!empty($serving['immutable']) && is_string($serving['version_id'] ?? null)
            ? ['versionId' => $serving['version_id']]
            : []),
        'authorities' => $authorities,
    ];
    if ($isVersionUrls) {
        $ids = [];
        foreach (is_array($input) && is_array($input['ids'] ?? null) ? $input['ids'] : [] as $id) {
            if (is_string($id) && $id !== '' && strlen($id) <= 160) {
                $ids[$id] = true;
            }
            if (count($ids) >= STATTIC_COMMENTS_VERSION_URLS_MAX_IDS) {
                break;
            }
        }
        if ($ids === []) {
            _stattic_render_json_unauthenticated('comments_versions_invalid');
        }
        $payload['ids'] = array_keys($ids);
    }
    if ($isTicket) {
        $browserIdentity = is_array($input) && is_array($input['identity'] ?? null)
            ? $input['identity']
            : null;
        $name = is_array($browserIdentity) && is_string($browserIdentity['name'] ?? null)
            ? trim($browserIdentity['name'])
            : '';
        // 640 bytes is the widest UTF-8 encoding of the control plane's 160
        // character cap; exact character-length enforcement stays schema-side.
        if (
            $name === ''
            || strlen($name) > 640
            || !is_bool($browserIdentity['namedByUser'] ?? null)
        ) {
            _stattic_render_json_unauthenticated('comments_identity_invalid');
        }
        // The name is the visitor's claim. The anonymous subject comes from the
        // session record, never from the page.
        $payload['identity'] = [
            'anonymousId' => $visitor['anonymousId'],
            'name' => $name,
            'namedByUser' => $browserIdentity['namedByUser'],
        ];
        // Whether this Space's overlay watches the Space feed is serving
        // configuration, written at publish time. The mint signs the second
        // ticket from the decision it already reached, so this says which rooms
        // the session needs, never what it may do in them.
        $overlay = _stattic_comments_local_config($privateRoot, $serving, $requestHost);
        $payload['notices'] = ($overlay['features']['notices'] ?? null) === true;
    }
    $context = _stattic_access_context($serving, $requestHost, $pagePath);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $result = is_string($encoded)
        ? _stattic_access_exchange_post(
            $exchangeUrl,
            ['payload' => $encoded],
            _stattic_access_exchange_headers($exchange, $context, 'application/json')
        )
        : null;
    if ($result === null || !is_array($result['body'] ?? null)) {
        $unavailableCode = $isZeroRealtimeTicket
            ? 'zero_realtime_ticket_unavailable'
            : 'comments_exchange_unavailable';
        _stattic_comments_render_json(502, _stattic_problem_document(
            502,
            $unavailableCode,
            $isZeroRealtimeTicket
                ? 'Zero realtime could not reach Spacefast. Try again.'
                : 'Comments could not reach Spacefast. Try again.'
        ));
    }
    _stattic_comments_render_json($result['status'], $result['body']);
}

function _stattic_spacefast_sdk_bootstrap(
    string $privateRoot,
    array $serving,
    string $requestHost,
    ?string $previewToken = null
): string {
    // An OPEN Space reaches this lane without the serve path having loaded any
    // access code (D34), so this route requires access-rules.php itself rather
    // than fataling on a public Space.
    require_once __DIR__ . '/access-rules.php';
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $preview = _stattic_spacefast_preview_surface($serving);
    // The whole OverlayConfig inline, so the boot path asks this host nothing.
    // Everything in it is space-level except the room key. One cacheable script
    // URL serves every page of the Space, so the SDK derives the room key from
    // location.pathname.
    $overlay = _stattic_comments_local_config($privateRoot, $serving, $requestHost);
    $collabBase = _stattic_spacefast_sdk_base_url($sdkConfig);
    $descriptor = _stattic_access_page_descriptor($serving);
    $exchange = _stattic_access_page_exchange($serving);
    $pageHost = _stattic_normalize_hostname($requestHost);
    // A machine-local Cast origin under a public control plane came from a
    // deployment wired against a developer's machine. No visitor can reach it,
    // so refuse to inject it.
    $brokerHost = is_array($exchange) && is_string($exchange['commentsTicketUrl'] ?? null)
        ? parse_url($exchange['commentsTicketUrl'], PHP_URL_HOST)
        : null;
    $commentsAvailable = $overlay['enabled'] === true
        && $collabBase !== null
        && !(
            _stattic_spacefast_sdk_host_is_local(parse_url((string) $collabBase, PHP_URL_HOST))
            && !_stattic_spacefast_sdk_host_is_local($brokerHost)
        )
        && is_array($exchange)
        && is_string($exchange['commentsTicketUrl'] ?? null);
    // Preview tag artifacts stay capability-selected at the control plane (the
    // preview token names a session, not a release); the production release is
    // one module of this same response.
    $embeddedTagBody = $previewToken === null
        ? _stattic_spacefast_sdk_tag_body($privateRoot, $serving)
        : '';
    // Where the orb expands (collab-public-api §1). A Space that published a
    // review room gets its own; every other gets the platform frame. The
    // pointer's absence IS the signal, resolved here so the browser never has
    // to know a Space can have a layout.
    $collabPages = is_array($serving['pages'] ?? null) ? $serving['pages'] : [];
    $manifest = [
        'version' => 4,
        'environment' => $preview ? 'preview' : 'production',
        'host' => $pageHost,
        'spaceId' => is_string($serving['space_id'] ?? null) ? $serving['space_id'] : null,
        'versionId' => is_string($serving['version_id'] ?? null) ? $serving['version_id'] : null,
        'apiBase' => _stattic_spacefast_sdk_api_base_url($sdkConfig),
        // Null while the Space is unclaimed: there is no account to continue with.
        'accountUrl' => is_array($descriptor) && is_string($descriptor['accountUrl'] ?? null)
            ? $descriptor['accountUrl']
            : null,
        'layout' => is_string($collabPages['collab'] ?? null)
            ? SPACEFAST_COLLAB_PAGE_PATH
            : SPACEFAST_COLLAB_FRAME_PATH,
        // Absent unless Comments are actually available for this surface. The
        // SDK refuses a manifest without it, so this key is the whole gate.
        ...($commentsAvailable ? ['config' => $overlay] : []),
    ];
    // Comments off for this surface costs the page zero Comments bytes: no
    // config, no placeholder orb, no module loader, not even a disabled copy.
    $collab = !$commentsAvailable ? '' : (
        'if(root.collabLoader)return;' .
        _stattic_spacefast_sdk_placeholder_orb($overlay) .
        'var o=document.createElement("script");' .
        'o.async=true;o.type="module";' .
        'o.src=' . json_encode(rtrim((string) $collabBase, '/') . '/sdk/v1/collab.js', JSON_UNESCAPED_SLASHES) . ';' .
        // The placeholder orb stands in for an arriving overlay. When the
        // module never arrives, remove it: a disc that pulses forever is a
        // worse lie than no orb.
        'o.onerror=function(){var e=new Error("Spacefast Comments module failed to load");console.error(e);var b=document.getElementById("sf-collab-boot-orb");if(b)b.remove();window.dispatchEvent(new CustomEvent("spacefast:collab-error",{detail:{stage:"module",message:e.message}}));};' .
        'root.collabLoader=o;document.head.appendChild(o);'
    );
    $loader = '(function(){' .
        'var manifest=' . json_encode($manifest, JSON_UNESCAPED_SLASHES) . ';' .
        'var previewToken=' . json_encode($previewToken, JSON_UNESCAPED_SLASHES) . ';' .
        'var root=window.Spacefast=window.Spacefast||{};' .
        'root.manifest=manifest;' .
        'if(previewToken&&manifest.apiBase&&manifest.spaceId&&!root.tagLoader){' .
        'var tagUrl=new URL(manifest.apiBase.replace(/\\/+$/,"")+"/v1/spaces/"+encodeURIComponent(manifest.spaceId)+"/tags/sdk.js");' .
        'tagUrl.searchParams.set("host",manifest.host||location.host);' .
        'if(previewToken)tagUrl.searchParams.set("preview",previewToken);' .
        'var t=document.createElement("script");' .
        't.async=true;t.src=tagUrl.toString();t.dataset.spacefastSdk="v1";' .
        'root.tagLoader=t;document.head.appendChild(t);' .
        '}' .
        $collab .
        '})();';
    return $loader . ($embeddedTagBody !== '' ? "\n" . $embeddedTagBody : '');
}

/**
 * The orb, painted before a single Cast byte is fetched.
 *
 * A static disc in the placement the visitor last dragged it to, wearing the
 * Space accent and the `connecting` pulse, the same visuals the real orb boots
 * into (theme/stylesheet.ts `.sf-orb`). It has no behaviour: the SDK removes it
 * when the real overlay mounts (shell/mount.ts). Placement mirrors the store's
 * `restoreOrbPlacement` + `orbDockStyle`; a rejected stored value falls back to
 * the default bottom-right dock.
 *
 * Framed documents paint nothing: inside the Collab frame or anyone else's
 * iframe the orb is suppressed for the whole page life (collab-frame-plan §2),
 * so painting one here would only flash it. The SDK still boots there to run
 * the frame handshake.
 */
function _stattic_spacefast_sdk_placeholder_orb(array $overlay): string
{
    $accent = $overlay['theme']['accent'] ?? null;
    return 'if(window.self===window.top)try{' .
        'var pr=JSON.parse(localStorage.getItem("spacefast:collab:orb-corner"))||{};' .
        'var pe=/^(left|right|top|bottom)$/.test(pr.edge)?pr.edge:"right";' .
        'var pa=pr.along>=0&&pr.along<=1?pr.along:1;' .
        'var pi=pr.inset>=0&&isFinite(pr.inset)?pr.inset:16;' .
        'var pv=pe=="left"||pe=="right";' .
        'var ps=pv?innerHeight:innerWidth;' .
        'var pc=Math.min(Math.max(pa*ps-22,16),Math.max(16,ps-60));' .
        'var b=document.createElement("div");b.id="sf-collab-boot-orb";' .
        'b.style.cssText="position:fixed;z-index:2147483000;width:44px;height:44px;border-radius:999px;' .
        'pointer-events:none;color-scheme:light dark;display:grid;place-items:center;' .
        'background:light-dark(rgba(255,255,255,.72),rgba(24,24,28,.72));' .
        '-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);' .
        'box-shadow:0 2px 8px rgba(0,0,0,.12),inset 0 0 0 1px light-dark(rgba(0,0,0,.08),rgba(255,255,255,.1));"+' .
        'pe+":"+pi+"px;"+(pv?"top:":"left:")+pc+"px";' .
        'b.innerHTML=\'<style>@keyframes sf-boot-pulse{0%,100%{opacity:1}50%{opacity:.35}}</style>\'+' .
        '\'<i style="width:10px;height:10px;border-radius:999px;background:\'+' .
        json_encode(is_string($accent) ? $accent : '#ff603d', JSON_UNESCAPED_SLASHES) .
        '+\';animation:sf-boot-pulse 1.6s ease-in-out infinite"></i>\';' .
        '(document.body||document.documentElement).appendChild(b);' .
        '}catch(e){}';
}

// The SDK manifest contract requires an apiBase (packages' readManifest
// refuses a null one), so a deployment without SPACEFAST_API_BASE_URL, a
// self-host or local harness, derives it from the Cast origin.
function _stattic_spacefast_sdk_api_base_url(array $sdkConfig): ?string
{
    $base = rtrim(_stattic_config_value('SPACEFAST_API_BASE_URL'), '/');
    if ($base !== '' && filter_var($base, FILTER_VALIDATE_URL)) {
        return $base;
    }

    $castBase = _stattic_spacefast_sdk_base_url($sdkConfig);
    $derived = is_string($castBase)
        ? preg_replace('~^(https?://)cast(?=\.)~i', '$1api', $castBase)
        : null;
    return is_string($derived) && $derived !== $castBase && filter_var($derived, FILTER_VALIDATE_URL)
        ? rtrim($derived, '/')
        : null;
}

function _stattic_spacefast_sdk_host_is_local(mixed $host): bool
{
    if (!is_string($host)) {
        return false;
    }
    $host = strtolower(trim($host));
    return $host === 'localhost'
        || str_ends_with($host, '.localhost')
        || $host === '127.0.0.1'
        || $host === '[::1]'
        || $host === '::1';
}

function _stattic_spacefast_sdk_config_string(array $config, string $key): ?string
{
    $value = $config[$key] ?? null;
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

function _stattic_spacefast_sdk_base_url(array $sdkConfig): ?string
{
    $base = _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_api_base')
        ?? _stattic_config_value('SPACEFAST_CAST_API_URL');
    $base = rtrim($base, '/');
    // Every consumer (the SDK loader and the Collab frame) gets the same
    // answer: a configured value that is not a URL is absent, not a base.
    return $base !== '' && filter_var($base, FILTER_VALIDATE_URL) !== false ? $base : null;
}

// THE SDK configuration, and the only place it comes from: the Space overlay,
// written by the control plane's serving-state push. Nothing here asks the
// control plane at request time. A Cast endpoint move, a Comments toggle or a
// theme change syncs when the overlay swaps.
function _stattic_spacefast_sdk_config(array $serving): array
{
    $sdk = is_array($serving['sdk'] ?? null) ? $serving['sdk'] : [];
    return is_array($sdk['config'] ?? null) ? $sdk['config'] : [];
}

// The production tag JavaScript this Space releases, served inside the one
// loader response. Small bodies ride the overlay; anything larger lives in the
// CAS so the per-request overlay include stays small.
function _stattic_spacefast_sdk_tag_body(string $privateRoot, array $serving): string
{
    $sdk = is_array($serving['sdk'] ?? null) ? $serving['sdk'] : [];
    if (is_string($sdk['body'] ?? null)) {
        return $sdk['body'];
    }
    $sha = $sdk['body_sha256'] ?? null;
    if (!is_string($sha) || !_stattic_is_sha256_hex($sha)) {
        return '';
    }
    $body = _stattic_v4_blob_contents([
        'private_root' => $privateRoot,
        'space_id' => is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '',
    ], $sha);
    // A missing blob costs the page its tags, never its SDK: the loader still
    // boots and the next overlay swap re-declares the body.
    return is_string($body) ? $body : '';
}

/**
 * Preview surface or live surface: the ONE predicate for every lane on this
 * route. An immutable version host is a preview surface; the live host is not.
 * The host the request arrived on is the proof. A `?preview=` token names a tag
 * release, never a Comments lane, or anyone could consult the preview toggle
 * from the live site by decorating the script URL.
 */
function _stattic_spacefast_preview_surface(array $serving): bool
{
    return !empty($serving['immutable']);
}

// Whether Comments is on for THIS surface (preview host vs live host). The
// storage lane's anonymous-commenter admission asks the same question, so the
// predicate lives once.
function _stattic_comments_enabled_for_surface(array $serving): bool
{
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $comments = is_array($sdkConfig['comments'] ?? null) ? $sdkConfig['comments'] : [];
    $lane = _stattic_spacefast_preview_surface($serving) ? 'preview' : 'live';
    return ($comments[$lane] ?? null) === true;
}

function _stattic_comments_overlay_theme(array $comments): array
{
    $theme = is_array($comments['theme'] ?? null) ? $comments['theme'] : [];
    $accent = $theme['accent'] ?? null;
    return [
        'accent' => is_string($accent) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $accent) === 1
            ? $accent
            : null,
        'hide_branding' => ($theme['hide_branding'] ?? null) === true,
    ];
}

/**
 * The Comments configuration for THIS host, assembled entirely on this host.
 *
 * Everything space-level (Cast endpoints, the published/preview toggles, the
 * theme, the feature set, the screenshot endpoint) rides the overlay. The
 * runtime adds only what it alone knows: which version host the visitor is on,
 * and its own same-origin ticket endpoint. Nothing here is per-page, so it can
 * be embedded verbatim in the cacheable SDK bootstrap. The SDK derives the one
 * per-page value, the room key, from location.pathname.
 */
function _stattic_comments_local_config(string $privateRoot, array $serving, string $requestHost): array
{
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $comments = is_array($sdkConfig['comments'] ?? null) ? $sdkConfig['comments'] : [];
    $origin = _stattic_comments_request_origin($requestHost);
    $theme = _stattic_comments_overlay_theme($comments);
    $endpoints = [
        'ticket' => ($origin ?? '') . STATTIC_COMMENTS_TICKET_PATH,
        'storage' => ($origin ?? '') . '/storage',
    ];
    $disabled = [
        'enabled' => false,
        'resource_key' => null,
        'version' => ['id' => null, 'current' => null, 'url' => null],
        'space' => ['live_url' => null],
        'theme' => $theme,
        'ws_url' => null,
        'endpoints' => $endpoints,
        'uploads' => null,
        'features' => [
            'picker' => true,
            'drawing' => false,
            'capture' => false,
            'attachments' => false,
            'notices' => false,
        ],
    ];

    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    $resourceKey = _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_resource_key');
    $wsUrl = _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_ws_url');
    $previewHost = _stattic_spacefast_preview_surface($serving);
    if (
        $spaceId === ''
        || $origin === null
        || $resourceKey === null
        || $wsUrl === null
        || !_stattic_comments_enabled_for_surface($serving)
    ) {
        return $disabled;
    }

    $versionId = is_string($serving['version_id'] ?? null) ? $serving['version_id'] : null;
    $liveVersionId = is_string($serving['live_version_id'] ?? null) ? $serving['live_version_id'] : null;
    $liveUrl = is_string($comments['live_url'] ?? null) ? $comments['live_url'] : null;
    $features = is_array($comments['features'] ?? null) ? $comments['features'] : [];
    // The read key rides the served config. That is the whole "fresh URLs"
    // mechanism: clients compose attachment URLs from {base, key} + id, nothing
    // per-object is signed, and a rotation syncs on the next config
    // revalidate.
    require_once __DIR__ . '/storage.php';
    return [
        'enabled' => true,
        'resource_key' => $resourceKey,
        'version' => [
            // Comments-on-live IS the live context: no version URL to point at.
            'id' => $previewHost ? $versionId : null,
            'current' => $liveVersionId,
            'url' => $previewHost ? $origin . '/' : null,
        ],
        'space' => ['live_url' => $previewHost ? $liveUrl : null],
        'theme' => $theme,
        'ws_url' => $wsUrl,
        'endpoints' => $endpoints,
        'uploads' => [
            'base' => $origin . STATTIC_UPLOADS_PUBLIC_URL_PREFIX,
            'key' => _stattic_storage_read_key($privateRoot),
        ],
        'features' => [
            'picker' => ($features['picker'] ?? null) !== false,
            'drawing' => ($features['drawing'] ?? null) === true,
            'capture' => ($features['capture'] ?? null) === true,
            'attachments' => ($features['attachments'] ?? null) === true,
            'notices' => ($features['notices'] ?? null) === true,
        ],
    ];
}

function _stattic_spacefast_sdk_revision(string $body): string
{
    return 'runtime:' . hash('sha256', $body);
}

function _stattic_spacefast_sdk_preview_token(): ?string
{
    if (isset($_GET['preview']) && is_string($_GET['preview']) && trim($_GET['preview']) !== '') {
        return trim($_GET['preview']);
    }
    return null;
}

// The `v` query component the control plane bakes into the injected SDK URL.
// Its presence, not its value, admits the immutable cache policy: the token is
// opaque here and changes only when the served body would.
function _stattic_spacefast_sdk_versioned_request(): bool
{
    return isset($_GET['v']) && is_string($_GET['v']) && trim($_GET['v']) !== '';
}

function _stattic_send_spacefast_sdk_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *', false);
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS', false);
    header('Access-Control-Allow-Headers: If-None-Match', false);
    header('Cross-Origin-Resource-Policy: cross-origin', false);
    header('Timing-Allow-Origin: *', false);
}
