<?php
declare(strict_types=1);

// This public route runs outside the paths that materialize WP.Cloud's Atomic
// persistent data, so the bootstrap config is loaded here explicitly.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/injection-plan.php';
require_once __DIR__ . '/space-bar.php';
require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/collab-exchange.php';

// sdk.js with nothing on for this version: valid JavaScript that does nothing.
const STATTIC_SPACEFAST_SDK_OFF = "/* spacefast: injection off */\n";

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
    // SDK bytes include revocable platform and customer state. A version token
    // cannot make that state immutable; overlays can change at the same URL.
    $publicCacheControl = ($previewToken !== null || isset($_GET['review']) || _stattic_system_view_review() !== null)
        ? STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE
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
    $config = _stattic_spacefast_sdk_page_config($privateRoot, $serving, $requestHost, $previewToken);
    $embeddedTagBody = $config['tagBody'];
    unset($config['tagBody']);
    // Nothing to do at all (only the context for modules that aren't there):
    // the same answer as injection off. A tag draft or a visual review reload
    // is something to do: an older page carries its own tag and relies on it.
    if ($embeddedTagBody === '' && array_diff(array_keys($config), ['runtime']) === []) {
        return STATTIC_SPACEFAST_SDK_OFF;
    }
    // The one static script (packages/space-client/src/sdk), then this host's
    // config for it. The bytes are the same for every visitor of the host.
    $static = @file_get_contents(__DIR__ . '/space-client/sdk.js');
    if (!is_string($static)) {
        return STATTIC_SPACEFAST_SDK_OFF;
    }
    // The tag release runs as the boot's `tags` callback: after the draft
    // check (a draft replaces it) and once per page.
    return $static . "\n" .
        'window.Spacefast.boot(' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) .
        ($embeddedTagBody !== '' ? ",function(){\n" . $embeddedTagBody . "\n}" : '') . ');';
}

/**
 * What `sdk.js` does on this host's pages, as the JSON it boots from. Each
 * part is written only while the injection plan has its item on for the
 * served version (shared/injection-plan.php); nothing on is an empty config,
 * and a page then loads nothing. `tagBody` is the production tag release,
 * appended after the config rather than inside it.
 *
 * @return array<string, mixed>
 */
function _stattic_spacefast_sdk_page_config(
    string $privateRoot,
    array $serving,
    string $requestHost,
    ?string $previewToken
): array {
    $on = static fn (string $item): bool => _stattic_injection_on($serving, $item);
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $review = _stattic_system_view_review();
    $overlay = _stattic_comments_local_config($privateRoot, $serving, $requestHost);
    $collabBase = _stattic_spacefast_sdk_base_url($sdkConfig);
    $pageHost = _stattic_normalize_hostname($requestHost);
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : null;
    // The token this response was selected by, carried onto the URLs it
    // writes so they resolve against the same draft serving state.
    $query = $previewToken === null ? '' : '?preview=' . rawurlencode($previewToken);
    $config = [];

    // Preview tag artifacts stay capability-selected at the control plane; the
    // production release is one body of this same response, sent while any
    // item it carries is on.
    $config['tagBody'] = $previewToken === null
        && ($on('tags-dynamic') || $on('remix') || $on('referral-chip') || $on('space-bar'))
        ? _stattic_spacefast_sdk_tag_body($privateRoot, $serving)
        : '';

    // Old outputs carry their own SDK tag: a tag-preview query or a visual
    // review reloads sdk.js for that draft instead.
    $reload = [];
    if ($previewToken === null && $on('tag-preview')) {
        $reload['preview'] = STATTIC_TAG_PREVIEW_QUERY_NAME;
    }
    // A visual review only ever opens an immutable version host.
    if ($review === null && !empty($serving['immutable']) && $on('review')) {
        $reload['review'] = true;
    }
    if ($reload !== []) {
        $config['reload'] = $reload;
    }

    $scripts = [];
    // Space Users' browser helper, `window.Spacefast.users`, defined by sdk.js.
    if ($on('users-api') && ($serving['users']['enabled'] ?? false) === true) {
        $config['users'] = true;
    }
    if ($on('zero-realtime') && ($serving['zero_generation'] ?? null) === 'v2') {
        $scripts[] = ['src' => STATTIC_ZERO_V2_REALTIME_PATH];
    }
    $apiBase = _stattic_spacefast_sdk_api_base_url();
    if ($previewToken !== null && $apiBase !== null && $spaceId !== null && $on('tag-preview')) {
        $scripts[] = ['src' => $apiBase . '/v1/spaces/' . rawurlencode($spaceId) . '/tags/sdk.js'
            . '?host=' . rawurlencode($pageHost) . '&preview=' . rawurlencode($previewToken)];
    }
    if ($review !== null && $collabBase !== null && $on('review')) {
        // No proof is exposed to publisher JavaScript. Only the verified bridge identity travels.
        $review['spaceId'] = $spaceId;
        $scripts[] = [
            'src' => rtrim($collabBase, '/') . '/sdk/v1/review.js',
            'module' => true,
            'json' => ['id' => 'sf-visual-review', 'text' => (string) json_encode($review, JSON_UNESCAPED_SLASHES)],
        ];
    }
    if ($scripts !== []) {
        $config['scripts'] = $scripts;
    }

    $tracker = _stattic_spacefast_tracker_for_page($serving, $previewToken);
    if ($tracker !== null) {
        $config['tracker'] = [
            'src' => $tracker['src'],
            'site' => $tracker['key'],
            'endpoint' => $tracker['endpoint'],
            ...($tracker['wait'] ? ['wait' => true] : []),
        ];
    }

    // Nothing to join costs the page zero collaboration bytes. `custom` gets
    // the Space's look and nothing else: the page brought its own UI.
    if (_stattic_spacefast_collab_available($serving, $overlay)) {
        $ui = _stattic_spacefast_collab_ui($serving);
        $config['collab'] = [
            'theme' => STATTIC_SPACEFAST_COLLAB_THEME_PATH . $query,
            'config' => STATTIC_SPACEFAST_COLLAB_MANIFEST_PATH . $query,
            ...($ui === 'default' && $collabBase !== null
                ? ['module' => rtrim($collabBase, '/') . '/sdk/v1/collab.js']
                : []),
        ];
    }

    $bar = $previewToken === null && $review === null
        ? _stattic_spacefast_bar_tried($serving, $requestHost)
        : null;
    if ($bar !== null && $collabBase !== null) {
        require_once __DIR__ . '/share-oauth.php';
        $config['bar'] = [
            'frame' => $bar['frame'],
            'dashboard' => $bar['dashboard'],
            'module' => rtrim($collabBase, '/') . '/sdk/v1/bar.js',
            // A Space anyone may open: only a browser with the returning-account
            // marker asks the session, so a visitor without one costs nothing,
            // and anyone it does not draw the frame for gets the mark: Sign in
            // on this Space, and the Space on Spacefast.
            ...(($bar['public'] ?? false) === true
                ? [
                    'public' => ['marker' => _stattic_returning_account_cookie_name() . '='],
                    'mark' => [
                        'links' => [],
                        ...(_stattic_share_oauth_url($serving) !== null
                            ? ['signIn' => STATTIC_SHARE_OAUTH_START_PATH]
                            : []),
                    ],
                ]
                : []),
        ];
    }

    if (count($config) > 1 || $config['tagBody'] !== '') {
        require_once __DIR__ . '/../shared/brand.php';
        $config['runtime'] = [
            'live' => $previewToken === null
                && !isset($_GET['review'])
                && $review === null
                && !_stattic_spacefast_preview_surface($serving),
            'brand' => _stattic_brand()['name'],
        ];
    }
    return $config;
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
    require_once __DIR__ . '/../shared/jwt.php';
    return _stattic_runtime_api_base_url() ?: null;
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
    return ($comments[$lane] ?? null) === true && _stattic_injection_on($serving, 'comments');
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
 * The accent is re-checked against 6-hex here and normalized to lowercase:
 * invalid values become null rather than failing `collabThemeSchema` validation
 * in clients parsing the manifest, while uppercase hex from an older serving
 * state is still accepted and published in lowercase.
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
    // Null while the Space is unclaimed: there is no account to continue
    // with. The descriptor's own `accountUrl` is the gate; the URL the page
    // goes to is this host's account start, which runs the Space's sign-in.
    $accountStart = is_array($descriptor)
        && is_string($descriptor['accountUrl'] ?? null)
        && $origin !== null
        ? $origin . STATTIC_ACCESS_ACCOUNT_START_PATH
        : null;

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
        'mentionableUrl' => $available && $origin !== null
            ? $origin . STATTIC_COMMENTS_MENTIONABLE_PATH
            : null,
        // The control plane itself, for the one lane the page pulls on its own
        // behalf with its own bearer (reply-email consent). Null when a
        // deployment names none — a self-host, a local harness — so the page
        // refuses that lane instead of guessing an origin.
        'apiBase' => _stattic_spacefast_sdk_api_base_url(),
        'accountUrl' => $accountStart,
        // The one cookie name this engine writes when an account signs in here
        // and spends on a silent start. Naming it is the promise that the start
        // renews with the Space's own sign-in (`prompt=none`), so a Space that
        // does not sign in that way says nothing and the overlay never tries.
        'returningAccountCookie' => $accountStart !== null && _stattic_share_oauth_url($serving) !== null
            ? _stattic_returning_account_cookie_name()
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
 * The Comments facts THIS host resolves for the SDK bootstrap, the collab
 * manifest and the ticket exchange: whether this surface collaborates, the Cast
 * endpoint and resource, the same-origin ticket and upload endpoints, and the
 * notices flag. Everything space-level rides the overlay; nothing here is
 * per-page.
 */
function _stattic_comments_local_config(string $privateRoot, array $serving, string $requestHost): array
{
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $comments = is_array($sdkConfig['comments'] ?? null) ? $sdkConfig['comments'] : [];
    $origin = _stattic_comments_request_origin($requestHost);
    $endpoints = ['ticket' => ($origin ?? '') . STATTIC_COMMENTS_TICKET_PATH];
    $disabled = [
        'enabled' => false,
        'resource_key' => null,
        'ws_url' => null,
        'endpoints' => $endpoints,
        'uploads' => null,
        'features' => ['notices' => false],
    ];

    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    $resourceKey = _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_resource_key');
    $wsUrl = _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_ws_url');
    if (
        $spaceId === ''
        || $origin === null
        || $resourceKey === null
        || $wsUrl === null
        || !_stattic_comments_enabled_for_surface($serving)
    ) {
        return $disabled;
    }

    $features = is_array($comments['features'] ?? null) ? $comments['features'] : [];
    // The read key rides the served config. That is the whole "fresh URLs"
    // mechanism: clients compose attachment URLs from {base, key} + id, nothing
    // per-object is signed, and a rotation syncs on the next config
    // revalidate.
    require_once __DIR__ . '/storage.php';
    return [
        'enabled' => true,
        'resource_key' => $resourceKey,
        'ws_url' => $wsUrl,
        'endpoints' => $endpoints,
        'uploads' => [
            'base' => $origin . STATTIC_UPLOADS_PUBLIC_URL_PREFIX,
            // This Space's key only: an open Space hands this manifest to
            // anonymous visitors, and it must not open a neighbour's uploads.
            'key' => _stattic_storage_read_key($privateRoot, $spaceId),
        ],
        'features' => ['notices' => ($features['notices'] ?? null) === true],
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

function _stattic_send_spacefast_sdk_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *', false);
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS', false);
    header('Access-Control-Allow-Headers: If-None-Match', false);
    header('Cross-Origin-Resource-Policy: cross-origin', false);
    header('Timing-Allow-Origin: *', false);
}
