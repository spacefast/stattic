<?php
declare(strict_types=1);

// This public route runs outside the paths that materialize WP.Cloud's Atomic
// persistent data, so the bootstrap config is loaded here explicitly.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/response.php';

/**
 * The three documents the collaboration layer boots from: `sdk.js`, the
 * manifest, and the theme stylesheet. One serving function because they share
 * everything that matters — the same serving state, the same revision ETag, the
 * same cache policy, the same preview-token selection.
 */
function _stattic_serve_spacefast_sdk(
    string $privateRoot,
    array $serving,
    string $requestHost,
    string $requestMethod,
    bool $privateCache,
    string $requestPath
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
        // JavaScript, not a problem document: the SDK URL is loaded by a
        // <script> tag, and a JSON body there is a parse error instead of a
        // refusal. The two fetched documents take the ordinary problem shape.
        _stattic_method_not_allowed(
            'GET, HEAD, OPTIONS',
            $requestPath === STATTIC_SPACEFAST_SDK_PATH ? [
                'body' => "window.Spacefast=window.Spacefast||{error:'method_not_allowed'};",
                'media_type' => 'application/javascript; charset=utf-8',
            ] : [
                'code' => 'method_not_allowed',
                'message' => 'This is a document: fetch it with GET.',
            ]
        );
    }

    $previewToken = _stattic_spacefast_sdk_preview_token();
    [$body, $mediaType] = match ($requestPath) {
        STATTIC_SPACEFAST_COLLAB_MANIFEST_PATH => [
            (string) json_encode(
                _stattic_spacefast_collab_manifest($privateRoot, $serving, $requestHost),
                JSON_UNESCAPED_SLASHES
            ),
            'application/json; charset=utf-8',
        ],
        STATTIC_SPACEFAST_COLLAB_THEME_PATH => [
            _stattic_spacefast_collab_theme_css($serving),
            'text/css; charset=utf-8',
        ],
        STATTIC_SPACEFAST_SDK_PATH => [
            _stattic_spacefast_sdk_bootstrap($privateRoot, $serving, $requestHost, $previewToken),
            'application/javascript; charset=utf-8',
        ],
        // serve.php admits exactly the three paths above. Anything else reached
        // this function by mistake and must not be answered with one of their
        // bodies — a wrong guess here would serve a Space's manifest under some
        // other URL.
        default => _stattic_problem_response(404, 'not_found', 'No such document.'),
    };
    $revision = _stattic_spacefast_sdk_revision($body);
    $etag = '"' . $revision . '"';

    http_response_code(200);
    header('Content-Type: ' . $mediaType, false);
    // A versioned SDK URL (?v=<engine/content token>, baked in by the control
    // plane) is content-addressed: the token changes whenever the body would,
    // so this response may pin immutably. The unversioned URL and every preview
    // stay short-lived and revalidated, so an engine revision is never frozen
    // into a visitor's cache. `_stattic_send_cache_policy_headers` still
    // downgrades private responses whatever this value is.
    $publicCacheControl = (isset($_GET['review']) || _stattic_system_view_review() !== null)
        ? STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE
        : (($previewToken === null && _stattic_spacefast_sdk_versioned_request())
        ? 'public, max-age=31536000, immutable'
        : STATTIC_DEFAULT_EDGE_CACHE_CONTROL);
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
    // Every lane here MINTS something and so reaches the control plane: a ticket
    // is auth, and auth is not this host's to issue. Configuration is not minted
    // — it is served as the collaboration manifest.
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
    $review = _stattic_system_view_review();
    // Only what this response has to PAINT: the orb's accent and whether this
    // surface speaks at all. Everything else a client needs is one fetch away
    // at the manifest, which is cacheable on its own terms.
    $overlay = _stattic_comments_local_config($privateRoot, $serving, $requestHost);
    $collabBase = _stattic_spacefast_sdk_base_url($sdkConfig);
    $pageHost = _stattic_normalize_hostname($requestHost);
    $commentsAvailable = _stattic_spacefast_collab_available($serving, $overlay);
    // Preview tag artifacts stay capability-selected at the control plane (the
    // preview token names a session, not a release); the production release is
    // one module of this same response.
    $embeddedTagBody = $previewToken === null
        ? _stattic_spacefast_sdk_tag_body($privateRoot, $serving)
        : '';
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : null;
    // The token this response was selected by, carried onto the two URLs it
    // writes so they resolve against the same draft serving state. It selects a
    // tag release, never a surface (`_stattic_spacefast_preview_surface` owns
    // that); its only other effect here is that the two new URLs, like this one,
    // stay short-lived and revalidated instead of pinning immutably.
    $query = $previewToken === null ? '' : '?preview=' . rawurlencode($previewToken);
    $ui = _stattic_spacefast_collab_ui($serving);
    // Nothing to join costs the page zero collaboration bytes: no stylesheet,
    // no placeholder orb, no module loader, not even a disabled copy. `custom`
    // gets the Space's look and nothing else — the page brought its own UI and
    // the runtime stays out of it.
    $collab = !$commentsAvailable ? '' : (
        'if(root.collabLoader)return;root.collabLoader=true;' .
        'var l=document.createElement("link");l.rel="stylesheet";l.href=' .
        json_encode(STATTIC_SPACEFAST_COLLAB_THEME_PATH . $query, JSON_UNESCAPED_SLASHES) . ';' .
        'document.head.appendChild(l);' .
        ($ui !== 'default' ? '' : (
            _stattic_spacefast_sdk_placeholder_orb($overlay) .
            'var o=document.createElement("script");' .
            'o.async=true;o.type="module";' .
            'o.src=' . json_encode(rtrim((string) $collabBase, '/') . '/sdk/v1/collab.js', JSON_UNESCAPED_SLASHES) . ';' .
            'o.setAttribute("data-sf-config",' .
            json_encode(STATTIC_SPACEFAST_COLLAB_MANIFEST_PATH . $query, JSON_UNESCAPED_SLASHES) . ');' .
            // The placeholder orb stands in for an arriving overlay. When the
            // module never arrives, remove it: a disc that pulses forever is a
            // worse lie than no orb.
            'o.onerror=function(){var e=new Error("Spacefast Comments module failed to load");console.error(e);var b=document.getElementById("sf-collab-boot-orb");if(b)b.remove();window.dispatchEvent(new CustomEvent("spacefast:collab-error",{detail:{stage:"module",message:e.message}}));};' .
            'document.head.appendChild(o);'
        ))
    );
    // A public, cached bootstrap uses the frame name only as a reload signal.
    // The unique URL rechecks the HttpOnly proof before returning review code.
    // The name contains no credential and cannot authorize a review.
    $reviewReload = $review !== null ? '' : (
        'if(/^spacefast-review:[0-9a-f-]{36}$/.test(window.name)&&window.parent!==window){' .
        'if(!root.reviewLoader){root.reviewLoader=true;var r=document.createElement("script");r.src=' . json_encode(STATTIC_SPACEFAST_SDK_PATH) . '+"?review="+encodeURIComponent(window.name.slice(17));document.head.appendChild(r);}return;}'
    );
    // A preview session loads the draft tag release from the control plane
    // instead of the published body. PHP knows every part of that URL, so the
    // page gets it finished rather than assembling one.
    $apiBase = _stattic_spacefast_sdk_api_base_url();
    $tagLoader = ($previewToken === null || $apiBase === null || $spaceId === null) ? '' : (
        'if(!root.tagLoader){var t=document.createElement("script");t.async=true;t.src=' .
        json_encode(
            $apiBase . '/v1/spaces/' . rawurlencode($spaceId) . '/tags/sdk.js'
                . '?host=' . rawurlencode($pageHost) . '&preview=' . rawurlencode($previewToken),
            JSON_UNESCAPED_SLASHES
        ) . ';' .
        't.dataset.spacefastSdk="v1";root.tagLoader=t;document.head.appendChild(t);}'
    );
    // `window.Spacefast` is a private latch, not an API: it holds the three
    // "already loading" flags so a page carrying two SDK tags boots once.
    $loader = '(function(){' .
        'var root=window.Spacefast=window.Spacefast||{};' .
        $reviewReload .
        $tagLoader .
        $collab .
        '})();';
    if ($review !== null && $collabBase !== null) {
        $review['spaceId'] = $spaceId;
        // No proof is exposed to publisher JavaScript. Only the verified bridge identity travels.
        $reviewJson = json_encode($review, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $reviewUrl = json_encode(rtrim($collabBase, '/') . '/sdk/v1/review.js', JSON_UNESCAPED_SLASHES);
        $loader .= ';(function(){var c=document.createElement("script");c.type="application/json";c.id="sf-visual-review";c.textContent=' . json_encode($reviewJson) . ';document.head.appendChild(c);var s=document.createElement("script");s.type="module";s.src=' . $reviewUrl . ';document.head.appendChild(s);})();';
    }
    return $loader . ($embeddedTagBody !== '' ? "\n" . $embeddedTagBody : '');
}

/**
 * Ink that reads on a filled accent: dark on light accents, white on dark ones.
 * Mirrors the SDK's `deriveAccent` (theme/adaptive.ts), which draws the same
 * line at 0.45 relative luminance — the placeholder and the real orb must not
 * disagree about the colour of the glyph they both paint.
 */
function _stattic_spacefast_sdk_accent_ink(string $accent): string
{
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        return '#ffffff';
    }
    $channel = static function (int $value): float {
        $srgb = $value / 255;
        return $srgb <= 0.04045 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
    };
    $luminance = 0.2126 * $channel((int) hexdec(substr($accent, 1, 2)))
        + 0.7152 * $channel((int) hexdec(substr($accent, 3, 2)))
        + 0.0722 * $channel((int) hexdec(substr($accent, 5, 2)));
    return $luminance > 0.45 ? '#16161b' : '#ffffff';
}

/**
 * The orb, painted before a single Cast byte is fetched.
 *
 * An accent-filled disc carrying the comment glyph, in the placement the
 * visitor last dragged it to, with the `connecting` pulse — the same visuals the
 * real orb boots into (react/styles.ts `.sf-orb`, theme/icons.ts `comment`), so
 * the handover swaps no shape and no colour. It has no behaviour: the SDK
 * removes it when the real overlay mounts. Placement mirrors the store's
 * `restoreOrbPlacement` + `orbDockStyle`; a rejected stored value falls back to
 * the default bottom-right dock.
 */
function _stattic_spacefast_sdk_placeholder_orb(array $overlay): string
{
    // This value is written into a CSS declaration, so only a literal 6-hex is
    // ever accepted here; anything else is the overlay's own fallback.
    $accent = $overlay['theme']['accent'] ?? null;
    $accent = is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}$/', $accent)
        ? $accent
        : '#ff603d';
    // Kept in one place with the SDK's copy: runtime/tests/spacefast-sdk.test.ts
    // pins this path against `ICON_PATHS.comment`.
    $glyph = 'M6 4h12a2 2 0 012 2v7a2 2 0 01-2 2h-7l-4 4v-4H6a2 2 0 01-2-2V6a2 2 0 012-2z';
    return 'try{' .
        'var pr=JSON.parse(localStorage.getItem("spacefast:collab:orb-corner"))||{};' .
        'var pe=/^(left|right|top|bottom)$/.test(pr.edge)?pr.edge:"right";' .
        'var pa=pr.along>=0&&pr.along<=1?pr.along:1;' .
        'var pi=pr.inset>=0&&isFinite(pr.inset)?pr.inset:16;' .
        'var pv=pe=="left"||pe=="right";' .
        'var ps=pv?innerHeight:innerWidth;' .
        'var pc=Math.min(Math.max(pa*ps-22,16),Math.max(16,ps-60));' .
        'var b=document.createElement("div");b.id="sf-collab-boot-orb";' .
        'b.style.cssText="position:fixed;z-index:2147483000;width:44px;height:44px;border-radius:999px;' .
        'pointer-events:none;display:grid;place-items:center;background:' .
        $accent . ';' .
        'box-shadow:0 2px 8px rgba(0,0,0,.12);"+' .
        'pe+":"+pi+"px;"+(pv?"top:":"left:")+pc+"px";' .
        'b.innerHTML=\'<style>@keyframes sf-boot-pulse{0%,100%{opacity:1}50%{opacity:.35}}</style>\'+' .
        '\'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="\'+' .
        json_encode(_stattic_spacefast_sdk_accent_ink($accent), JSON_UNESCAPED_SLASHES) .
        '+\'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" ' .
        'style="animation:sf-boot-pulse 1.6s ease-in-out infinite"><path d="' . $glyph . '"/></svg>\';' .
        '(document.body||document.documentElement).appendChild(b);' .
        '}catch(e){}';
}

// The API base comes from configuration, and from nowhere else.
//
// This used to fall back to rewriting the relay's hostname (`cast.` -> `api.`)
// when SPACEFAST_API_BASE_URL was absent. That guess only ever held for one
// naming convention on one host: it silently produced a wrong-but-plausible
// origin for any deployment whose relay is not a `cast.` sibling of its API,
// and it tied the API's address to the relay's, which is exactly the coupling
// the realtime transport seam exists to break. Every serving path provides the
// value explicitly — wp.cloud sites get it from the control plane's persistent
// data push (`syncWpCloudRuntimePersistentData`, which sets it unconditionally
// from `env.SPACEFAST_API_URL`) — so an absent one is a misconfigured
// deployment, and the manifest says so by omitting `apiBase` rather than
// inventing it. `readManifest` refuses a manifest without one, so the SDK
// stays headless instead of talking to a host nobody configured.
function _stattic_spacefast_sdk_api_base_url(): ?string
{
    $base = rtrim(_stattic_config_value('SPACEFAST_API_BASE_URL'), '/');
    return $base !== '' && filter_var($base, FILTER_VALIDATE_URL) ? $base : null;
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
    $theme = _stattic_spacefast_collab_theme($comments);
    return [
        'accent' => $theme['accent'],
        'hide_branding' => $theme['hideBranding'],
    ];
}

/**
 * THE one predicate for "can anything collaborate on this surface".
 *
 * `sdk.js` and `collab.json` must answer this identically or a `ui: "custom"`
 * client — which boots from the manifest alone and never reads the bootstrap —
 * would join a room the page itself refused to load. It is deliberately
 * stricter than the overlay's own `enabled` bit:
 *
 * - a review session replaces the overlay entirely, so nothing else boots;
 * - no Cast base is nowhere to connect;
 * - a machine-local Cast origin under a public control plane came from a
 *   deployment wired against a developer's machine. No visitor can reach it, so
 *   refuse to advertise it;
 * - no `commentsTicketUrl` is no way to mint auth, and a ticket is the whole
 *   join.
 *
 * `$overlay` is passed in rather than recomputed: it costs a storage read, and
 * both callers already hold it.
 */
function _stattic_spacefast_collab_available(array $serving, array $overlay): bool
{
    $collabBase = _stattic_spacefast_sdk_base_url(_stattic_spacefast_sdk_config($serving));
    $exchange = _stattic_access_page_exchange($serving);
    $ticketUrl = is_array($exchange) && is_string($exchange['commentsTicketUrl'] ?? null)
        ? $exchange['commentsTicketUrl']
        : null;
    return _stattic_system_view_review() === null
        && ($overlay['enabled'] ?? null) === true
        && $collabBase !== null
        && $ticketUrl !== null
        && !(
            _stattic_spacefast_sdk_host_is_local(parse_url($collabBase, PHP_URL_HOST))
            && !_stattic_spacefast_sdk_host_is_local(parse_url($ticketUrl, PHP_URL_HOST))
        );
}

/**
 * How the overlay is delivered for this Space: `default` (Spacefast's own UI)
 * or `custom` (the Space brought its own and wants only the theme). Anything
 * else in the projection, including its absence, is `default` — the control
 * plane is the only writer and it validates the enum. Whether the Space has
 * comments at all is a different question, answered by the availability
 * predicate, not by this one.
 */
function _stattic_spacefast_collab_ui(array $serving): string
{
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $comments = is_array($sdkConfig['comments'] ?? null) ? $sdkConfig['comments'] : [];
    return ($comments['ui'] ?? null) === 'custom' ? 'custom' : 'default';
}

/**
 * The Space's customization, exactly as the control plane projected it.
 *
 * Every value arrives already parsed, validated and normalized — colors are
 * 6-hex lowercase, fonts are safe in a style context — because the writer is
 * the only place that can do it once per publish instead of once per request.
 * This function names the keys and their types; it derives nothing.
 *
 * The accent is the one exception, and it is not derivation: it is re-checked
 * against 6-hex here because it is the only theme value that leaves JSON. The
 * placeholder orb concatenates it into an HTML attribute inside `innerHTML`, so
 * a serving state carrying anything else would be markup injection on every page
 * of the Space. Invalid is null, and the orb paints its own default. The check
 * accepts either case so an older serving state still paints, but the manifest
 * publishes the lowercase spelling `collabThemeSchema` requires — a client
 * parsing this document must not fail on how the value happens to be written.
 */
function _stattic_spacefast_collab_theme(array $comments): array
{
    $theme = is_array($comments['theme'] ?? null) ? $comments['theme'] : [];
    $string = static fn (string $key): ?string =>
        is_string($theme[$key] ?? null) ? $theme[$key] : null;
    $accent = $string('accent');
    return [
        'accent' => $accent !== null && preg_match('/\A#[0-9a-fA-F]{6}\z/', $accent) === 1
            ? strtolower($accent)
            : null,
        'background' => $string('background'),
        'font' => $string('font'),
        'name' => $string('name'),
        'logo' => $string('logo'),
        'hideBranding' => ($theme['hideBranding'] ?? null) === true,
    ];
}

// The theme stylesheet, rendered by the control plane from the same theme block
// and served byte-for-byte. No color math happens on this host.
function _stattic_spacefast_collab_theme_css(array $serving): string
{
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $comments = is_array($sdkConfig['comments'] ?? null) ? $sdkConfig['comments'] : [];
    return is_string($comments['css'] ?? null) ? $comments['css'] : '';
}

/**
 * The collaboration manifest (`collabManifestSchema` v5): everything a client
 * needs to boot, and nothing it must not have.
 *
 * One document per Space, not per page — the room key stays client-derived from
 * `location.pathname` so this response is cacheable. A Space with nothing to
 * join still answers, with `cast` and `ticketUrl` null: the client learns it is
 * on a surface that does not speak rather than guessing from a 404.
 */
function _stattic_spacefast_collab_manifest(
    string $privateRoot,
    array $serving,
    string $requestHost
): array {
    // Same reason the bootstrap does it: an OPEN Space reaches this lane with no
    // access code loaded, and the page descriptor lives there.
    require_once __DIR__ . '/access-rules.php';
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $comments = is_array($sdkConfig['comments'] ?? null) ? $sdkConfig['comments'] : [];
    $features = is_array($comments['features'] ?? null) ? $comments['features'] : [];
    $overlay = _stattic_comments_local_config($privateRoot, $serving, $requestHost);
    // THE availability predicate, the same call the bootstrap makes. A client
    // that only ever reads this document gets exactly the verdict `sdk.js`
    // reached for a client that reads both.
    $available = _stattic_spacefast_collab_available($serving, $overlay);
    $descriptor = _stattic_access_page_descriptor($serving);
    $origin = _stattic_comments_request_origin($requestHost);
    $previewHost = _stattic_spacefast_preview_surface($serving);
    $versionId = is_string($serving['version_id'] ?? null) ? $serving['version_id'] : null;
    $liveVersionId = is_string($serving['live_version_id'] ?? null)
        ? $serving['live_version_id']
        : null;
    $attachments = ($features['attachments'] ?? null) === true;
    // The same `{base, key}` the overlay config lane serves, from the same
    // place. Withheld unless there is actually somewhere to upload to, so the
    // UI can never offer an endpoint this manifest did not address.
    $uploads = is_array($overlay['uploads'] ?? null) ? $overlay['uploads'] : null;

    return [
        'version' => 5,
        'environment' => $previewHost ? 'preview' : 'production',
        'space' => [
            'id' => is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '',
            'name' => is_array($descriptor) && is_string($descriptor['displayName'] ?? null)
                ? $descriptor['displayName']
                : null,
            // Only a version host has somewhere else to go: on the live host
            // this page already IS the live Space.
            'liveUrl' => $previewHost && is_string($comments['live_url'] ?? null)
                ? $comments['live_url']
                : null,
        ],
        'artifact' => [
            'id' => $versionId,
            // Whether commenting here is commenting on the live Space or on an
            // older version — the one thing that changes what the UI says. The
            // live host is structurally current: a serving state that has not
            // recorded `live_version_id` yet must not push the SDK into a draft
            // room on the Space's own published surface.
            'current' => !$previewHost || ($versionId !== null && $versionId === $liveVersionId),
            'url' => $previewHost && $origin !== null ? $origin . '/' : null,
        ],
        'cast' => $available ? [
            'wsUrl' => (string) $overlay['ws_url'],
            'resourceKey' => (string) $overlay['resource_key'],
        ] : null,
        // Same-origin: a ticket is auth, and auth is minted through this host's
        // own exchange lane, never by the page reaching the control plane.
        'ticketUrl' => $available && is_string($overlay['endpoints']['ticket'] ?? null)
            ? $overlay['endpoints']['ticket']
            : null,
        // The control plane itself, for the one lane the page pulls on its own
        // behalf with its own bearer (reply-email consent). Null when a
        // deployment names none — a self-host, a local harness — so the page
        // refuses that lane instead of guessing an origin.
        'apiBase' => _stattic_spacefast_sdk_api_base_url(),
        // Null while the Space is unclaimed: there is no account to continue
        // with. The descriptor's own `accountUrl` is the gate; the URL the page
        // goes to is this host's account start route.
        'accountUrl' => is_array($descriptor)
            && is_string($descriptor['accountUrl'] ?? null)
            && $origin !== null
            ? $origin . STATTIC_ACCESS_ACCOUNT_START_PATH
            : null,
        'features' => [
            'picker' => ($features['picker'] ?? null) !== false,
            'drawing' => ($features['drawing'] ?? null) === true,
            'capture' => ($features['capture'] ?? null) === true,
            'attachments' => $attachments,
        ],
        'uploads' => $available && $attachments && $uploads !== null ? [
            'base' => (string) $uploads['base'],
            'key' => (string) $uploads['key'],
        ] : null,
        'ui' => _stattic_spacefast_collab_ui($serving),
        'theme' => _stattic_spacefast_collab_theme($comments),
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
