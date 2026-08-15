<?php
declare(strict_types=1);

// `/__/collab` — the Collab frame shell (collab-frame-plan §4/§5).
//
// The document is chrome and nothing else: ~2 KB of HTML carrying a boot-neutral
// manifest (space name, accent, hide_branding, the requested page) plus the
// module tag for `frame.js` on Cast. Every live value — threads, presence,
// version state, the adopted appearance — arrives in the browser over the
// shell<->SDK handshake, so this response is never a second copy of the config
// that could go stale.
//
// It dispatches after the access check (serve.php) because its bytes vary by
// Space and it must ride the host session that check just minted; the
// same-origin iframe then reuses those cookies.

require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/cache-policy.php';

function _stattic_serve_collab_frame(
    array $serving,
    string $requestHost,
    string $requestMethod,
    bool $privateCache
): void {
    // Same reason spacefast-sdk.php pulls it in: an OPEN Space reaches this lane
    // with no access code loaded, and the page descriptor lives there.
    require_once __DIR__ . '/access-rules.php';

    // R-20: the manifest varies by viewer and settings, and a cached 200 shell
    // where a 404 belongs is the failure this forbids. Private or public, no
    // store — the heavy asset is frame.js off Cast, not this document.
    $policy = _stattic_cache_policy([
        'private' => $privateCache,
        'no_store' => true,
        'public' => STATTIC_CACHE_CONTROL_NO_STORE,
    ]);
    _stattic_cache_policy_send($policy);
    // R-5: chrome that hosts capability-gated actions is never frameable
    // cross-origin. Deliberately a flat refusal and NOT the Space's framing
    // boundary (_stattic_space_frame_ancestors): the shell is the framer, never
    // the framed, so it has no cross-origin ancestor to admit.
    header("Content-Security-Policy: frame-ancestors 'self'", true);

    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD', [
            'code' => 'method_not_allowed',
            'message' => 'The Collab frame is a page: request it with GET.',
            'headers' => ['Cache-Control' => $policy['cache_control']],
        ]);
    }

    $comments = _stattic_collab_frame_comments($serving);
    $castBase = _stattic_collab_frame_cast_base($serving);
    // The frame is a Comments surface, not a bypass: the lane that decides
    // whether this Space speaks on this host decides whether the shell exists.
    // Preview lane on an immutable version host, live lane otherwise — the same
    // predicate the config lane uses, because the request reaching this host is
    // itself the proof of which surface it is.
    $lane = !empty($serving['immutable']) ? 'preview' : 'live';
    if (($comments[$lane] ?? null) !== true || $castBase === null) {
        _stattic_problem_response(
            404,
            'collab_frame_not_found',
            'Comments are not enabled for this Space.',
            [],
            ['Cache-Control' => $policy['cache_control']],
        );
    }

    _stattic_response_send(
        200,
        _stattic_collab_frame_document($serving, $comments, $castBase),
        'text/html; charset=utf-8',
        ['Cache-Control' => $policy['cache_control']],
    );
}

/**
 * The space-level Comments block of the overlay, read directly rather than
 * through the SDK lane: the frame and the SDK bootstrap answer different
 * questions off the same projection.
 */
function _stattic_collab_frame_comments(array $serving): array
{
    $sdk = is_array($serving['sdk'] ?? null) ? $serving['sdk'] : [];
    $config = is_array($sdk['config'] ?? null) ? $sdk['config'] : [];
    return is_array($config['comments'] ?? null) ? $config['comments'] : [];
}

function _stattic_collab_frame_cast_base(array $serving): ?string
{
    $sdk = is_array($serving['sdk'] ?? null) ? $serving['sdk'] : [];
    $config = is_array($sdk['config'] ?? null) ? $sdk['config'] : [];
    $base = is_string($config['cast_api_base'] ?? null) && trim($config['cast_api_base']) !== ''
        ? trim($config['cast_api_base'])
        : _stattic_config_value('SPACEFAST_CAST_API_URL');
    $base = rtrim($base, '/');
    return $base !== '' && filter_var($base, FILTER_VALIDATE_URL) ? $base : null;
}

/**
 * R-4: `?path=` is accepted only as a same-origin absolute page path — leading
 * `/`, never `//`, no scheme, no backslash, `..` resolved and never escaping the
 * root. Anything else yields null, the shell's quiet not-found state, and no
 * iframe navigation at all. `_stattic_scope_path` is that rule already; the
 * index-alias canonicalization is off so the visitor's URL is answered as asked.
 */
function _stattic_collab_frame_path(): ?string
{
    $raw = $_GET['path'] ?? null;
    if (!is_string($raw)) {
        // No page asked for is the Space's root, not a refusal.
        return $raw === null ? '/' : null;
    }
    return strlen($raw) > 1024 ? null : _stattic_scope_path($raw, false);
}

function _stattic_collab_frame_document(array $serving, array $comments, string $castBase): string
{
    $theme = is_array($comments['theme'] ?? null) ? $comments['theme'] : [];
    $accent = $theme['accent'] ?? null;
    $descriptor = _stattic_access_page_descriptor($serving);
    $name = is_array($descriptor) && is_string($descriptor['displayName'] ?? null)
        ? $descriptor['displayName']
        : null;
    $path = _stattic_collab_frame_path();
    $manifest = [
        'v' => 1,
        // Null = the requested page was not a same-origin path.
        'path' => $path,
        'space' => ['name' => $name],
        'theme' => [
            'accent' => is_string($accent) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $accent) === 1
                ? $accent
                : null,
            'hide_branding' => ($theme['hide_branding'] ?? null) === true,
        ],
    ];
    // JSON_HEX_TAG is what keeps `</script` out of the inline block.
    $json = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    $title = $name !== null ? $name . ' — review' : 'Review';
    $script = $castBase . '/sdk/v1/frame.js';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . _stattic_html_escape($title) . '</title>'
        . '<style>html,body{margin:0;height:100%;background:#16161b;color:#f4f4f6}</style>'
        . '<script type="application/json" id="sf-frame">' . $json . '</script>'
        . '<script type="module" src="' . _stattic_html_escape($script) . '"></script>'
        . '</head><body>'
        . ($path !== null
            ? '<noscript><a href="' . _stattic_html_escape($path) . '">Open the page</a></noscript>'
            : '')
        . '</body></html>';
}
