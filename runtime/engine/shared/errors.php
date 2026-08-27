<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/response.php';

// The edge does not key on Vary, so a shared-cacheable body that varies on
// Accept gets pinned to whichever client arrived first. Only responses no cache
// may store are allowed to negotiate.
function _stattic_page_may_negotiate(array $headers): bool
{
    foreach ($headers as $name => $value) {
        if (is_string($name) && strtolower($name) === 'cache-control' && is_scalar($value)) {
            return !_stattic_cache_control_allows_shared_store((string) $value);
        }
    }
    return false;
}

function _stattic_page_representation(string $ambiguousDefault = 'text'): string
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $fetchMode = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    if (str_contains($accept, 'application/json') || ($fetchMode === 'cors' && !str_contains($accept, 'text/html'))) {
        return 'json';
    }
    if (str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml') || $fetchMode === 'navigate') {
        return 'html';
    }
    if (str_contains($accept, 'text/plain')) {
        return 'text';
    }
    return $ambiguousDefault;
}

function _stattic_page_route_candidates(string $requestPath): array
{
    // Trim only '/', never '.': stripping dots turns `/.well-known/x` into a
    // `/well-known/` route so custom pages under a dot-directory never resolve.
    $directory = str_ends_with($requestPath, '/') ? trim($requestPath, '/') : trim(dirname($requestPath), '/');
    $routes = [];
    while (true) {
        $routes[] = $directory === '' ? '/' : '/' . $directory . '/';
        if ($directory === '') {
            break;
        }
        $parent = dirname($directory);
        $directory = $parent === '.' || $parent === '/' ? '' : trim($parent, '/');
    }
    return $routes;
}

function _stattic_page_artifact(array $context, string $pageId): ?string
{
    $serving = is_array($context['serving'] ?? null) ? $context['serving'] : [];
    if ($serving === [] && is_array($GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null)) {
        $serving = $GLOBALS['SPACEFAST_PAGE_SERVING'];
    }
    $versionId = is_string($serving['version_id'] ?? null) ? $serving['version_id'] : '';
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    // The Space overlay carries the live version's page pointers.
    $pages = is_array($serving['pages'] ?? null) ? $serving['pages'] : [];
    $routes = is_array($pages['routes'] ?? null) ? $pages['routes'] : [];
    $requestPath = is_string($context['request_path'] ?? null) ? $context['request_path'] : _stattic_runtime_request_path();
    $artifact = is_string($context['artifact'] ?? null) ? $context['artifact'] : null;
    if ($artifact === null) {
        $platform = is_array($pages['platform'] ?? null) ? $pages['platform'] : [];
        $artifact = is_string($platform[$pageId] ?? null) ? $platform[$pageId] : null;
    }
    if ($artifact === null) {
        foreach (_stattic_page_route_candidates($requestPath) as $routePath) {
            $route = is_array($routes[$routePath] ?? null) ? $routes[$routePath] : [];
            $pagePointers = is_array($route['pages'] ?? null) ? $route['pages'] : [];
            if (is_string($pagePointers[$pageId] ?? null)) {
                $artifact = $pagePointers[$pageId];
                break;
            }
        }
    }
    $root = _stattic_access_private_root();
    if (!is_string($artifact) || preg_match('/^[a-z0-9-]{1,240}$/', $artifact) !== 1 || $root === '' || !_stattic_id_valid($spaceId) || !_stattic_id_valid($versionId)) {
        return null;
    }
    $path = _stattic_version_root($root, $spaceId, $versionId) . '/pages/' . $artifact . '.html';
    $size = filesize($path);
    if (!is_int($size) || $size < 1 || $size > 2 * 1024 * 1024) {
        return null;
    }
    $html = file_get_contents($path);
    return is_string($html) ? $html : null;
}

const STATTIC_PAGE_STATUS_REASONS = [
    200 => 'OK', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
    404 => 'Not Found', 405 => 'Method Not Allowed', 413 => 'Content Too Large',
    429 => 'Too Many Requests', 451 => 'Unavailable For Legal Reasons',
    500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
];

function _stattic_page_status_reason(int $status): string
{
    return STATTIC_PAGE_STATUS_REASONS[$status] ?? 'Error';
}

// `optional` never swaps mid-view: a cold visitor keeps the fallback for that
// one view while the preload warms the shared spacefast.com cache; every later
// page on any space hostname renders the brand fonts immediately.
function _stattic_page_font_faces(): string
{
    static $css = null;
    if (is_string($css)) {
        return $css;
    }
    $css = '';
    foreach (STATTIC_PLATFORM_PAGE_FONTS as $url => [$family, $weight]) {
        $css .= '@font-face{font-family:"' . $family . '";src:url("' . $url . '") format("woff2");font-style:normal;font-weight:' . $weight . ';font-display:optional}';
    }
    return $css;
}

// Fonts are a cross-origin fetch; preloading ahead of the inline stylesheet
// starts it with the parse. `crossorigin` is required: font requests are CORS
// mode, and a mismatched preload is discarded.
function _stattic_page_font_preloads(): string
{
    static $html = null;
    if (is_string($html)) {
        return $html;
    }
    $html = '';
    foreach (STATTIC_PLATFORM_PAGE_FONTS as $url => [, , $preload]) {
        if ($preload) {
            $html .= '<link rel="preload" href="' . $url . '" as="font" type="font/woff2" crossorigin>';
        }
    }
    return $html;
}

function _stattic_spacefast_wordmark(): string
{
    $path = 'M20 15h24v7H27v7h17v20H20v-7h17v-7H20V15ZM51 15h24v18H58v16h-7V15Zm7 11h10v-4H58v4ZM83 15h24l9 34h-8l-2-8H84l-2 8h-8l9-34Zm3 19h18l-4-12H90l-4 12ZM145 22h-19v20h19v7h-26V15h26v7ZM152 15h26v7h-19v6h15v7h-15v7h19v7h-26V15ZM185 15h25v7h-18v6h15v7h-15v14h-7V15ZM215 15h24l9 34h-8l-2-8h-22l-2 8h-8l9-34Zm3 19h18l-4-12h-10l-4 12ZM250 15h24v7h-17v7h17v20h-24v-7h17v-7h-17V15ZM279 15h27v7h-10v27h-7V22h-10v-7Z';
    return '<svg class="sf-wordmark" viewBox="0 0 326 62" role="img" aria-label="Spacefast"><rect x="14" y="12" width="306" height="48" fill="#ff4217"/><rect x="10" y="8" width="306" height="48" fill="#141419"/><path fill="#fbf8f1" d="' . $path . '"/></svg>';
}

const STATTIC_PLATFORM_PAGE_COPY = [
    '404' => ['Page not found', ''],
    'login' => ['This space is for members', 'Sign in to continue — you’ll come right back here.'],
    'denied' => ['This page is private', ''],
    'access' => ['This space is private', ''],
    'index' => ['Files unavailable', 'The directory listing could not be loaded.'],
    'preview' => ['Preview unavailable', 'This file preview could not be loaded.'],
    'collab' => ['Review room unavailable', 'This space’s review room could not be loaded.'],
    'undeployed' => ['Waiting for launch', 'This space hasn’t been published yet. Check back soon.'],
    'suspended' => ['This space is paused', 'Serving is on hold until billing is sorted out.'],
    'legal' => ['Unavailable for legal reasons', 'This space is blocked in response to a legal demand.'],
    'gone' => ['Nothing here', 'This space is no longer available.'],
    'rate-limited' => ['Slow down a second', 'Too many requests hit this space at once. Give it a moment and try again.'],
    'tier-unavailable' => ['Back in a bit', 'This space is temporarily unavailable.'],
    'method-not-allowed' => ['That method won’t work here', 'Try the request another way.'],
    'content-too-large' => ['That request is too large', 'Send a smaller request and try again.'],
    'proxy-error' => ['The upstream missed the connection', 'Try again in a moment.'],
    'runtime-error' => ['Something broke on our side', 'The runtime hit an error serving this page. Try again in a moment.'],
];

function _stattic_platform_page_html(string $pageId, int $status, string $message, string $fragment = '', string $requestPath = '', string $titleOverride = ''): string
{
    $copy = STATTIC_PLATFORM_PAGE_COPY[$pageId]
        ?? ['Spacefast could not serve this page', $message !== '' ? trim($message) : 'Try again in a moment.'];
    $title = _stattic_html_escape($titleOverride !== '' ? $titleOverride : $copy[0]);
    $description = $copy[1] === '' ? '' : '<p class="sf-copy">' . _stattic_html_escape($copy[1]) . '</p>';
    $sitePage = in_array($pageId, ['404', 'denied', 'access', 'index', 'preview', 'collab'], true);
    $plain = in_array($pageId, ['index', 'preview', 'collab'], true);
    $showStatus = !$sitePage || $pageId === '404';
    $eyebrow = $showStatus ? '<p class="sf-eyebrow">' . $status . ' · ' . _stattic_html_escape(_stattic_page_status_reason($status)) . '</p>' : '';
    if ($pageId === '404') {
        $path = $requestPath !== '' ? _stattic_html_escape($requestPath) : 'this address';
        $description = '<p class="sf-copy">There’s nothing at <span class="sf-inline-path">' . $path . '</span>.</p><div class="sf-actions"><a class="sf-button" href="/">Go to the homepage</a></div>';
    }
    if ($pageId === 'denied') {
        $description .= $fragment;
    } elseif ($pageId === 'access') {
        $description .= '<div class="sf-access-default">' . $fragment . '</div>';
    }
    $brand = $sitePage
        ? '<a class="sf-site-brand" href="/" aria-label="Homepage"><span aria-hidden="true"></span><b>This space</b></a>'
        : _stattic_spacefast_wordmark();
    $footerWordmark = $sitePage
        ? '<span class="sf-powered" tabindex="0">' . _stattic_spacefast_wordmark() . '<span class="sf-powered-line"><a href="https://spacefast.com">Best way to share what your agent made</a></span></span>'
        : '';
    $footer = '<footer><div class="sf-colophon-row">' . $footerWordmark . '<a class="sf-help" href="https://spacefast.com/help">Need help?</a></div></footer>';
    $css = <<<'CSS'
:root{color-scheme:light dark;--bg:#f9f8f4;--fg:#1a1913;--muted:#6f6b62;--accent:#ff4217;--on-accent:#1a1913;--display:"Recoleta",Georgia,serif;--body:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display","Segoe UI",system-ui,Roboto,"Helvetica Neue",Arial,sans-serif;--mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace}*{box-sizing:border-box}html{min-width:320px;background:var(--bg);-webkit-font-smoothing:antialiased}body{margin:0;min-height:100vh;background:var(--bg);color:var(--fg);font:14px/1.65 var(--body)}.sf-page{min-height:100vh;display:flex;flex-direction:column;padding:clamp(22px,4vw,44px)}header{display:flex;min-height:32px;align-items:center;justify-content:center}.sf-page.plain header{justify-content:flex-start}.sf-wordmark{display:block;width:auto;height:17px}.sf-site-brand{display:inline-flex;align-items:center;gap:10px;color:var(--fg);text-decoration:none}.sf-site-brand span{width:7px;height:7px;flex:none;background:var(--accent)}.sf-site-brand b{font-size:13px;font-weight:600}main{flex:1;width:100%;max-width:620px;margin:auto;padding:clamp(34px,9vh,84px) 0;display:flex;flex-direction:column;align-items:center;text-align:center}.sf-page.plain main{max-width:680px;margin:0;padding:clamp(24px,6vh,56px) 0;align-items:flex-start;text-align:left}.sf-eyebrow{margin:0 0 16px;color:var(--muted);font:500 11px/1.5 var(--mono);letter-spacing:.12em;text-transform:uppercase}.sf-page.plain .sf-eyebrow{margin-bottom:14px}h1{max-width:20ch;margin:0 0 12px;font:400 clamp(1.9rem,5vw,2.8rem)/1.22 var(--display);letter-spacing:-.012em;text-wrap:balance}.sf-copy{max-width:52ch;margin:0;color:var(--muted);text-wrap:pretty}.sf-copy+.sf-copy{margin-top:12px}.sf-inline-path{color:var(--fg);font-family:var(--mono);font-size:.9em;overflow-wrap:anywhere}.sf-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:14px;margin-top:22px}.sf-button,.sf-access{display:inline-flex;min-height:44px;align-items:center;justify-content:center;border:0;background:var(--accent);color:var(--on-accent);padding:10px 16px;font:600 13px/1.4 var(--body);text-decoration:none;cursor:pointer}.sf-button:hover,.sf-access:hover{filter:brightness(1.06)}a:focus-visible{outline:2px solid #a93115;outline-offset:2px}footer{display:flex;flex-direction:column;align-items:center;gap:10px;padding:44px 0 26px;margin-top:auto;color:var(--muted)}.sf-page.plain footer{align-items:flex-start}.sf-colophon-row{display:flex;align-items:center;gap:20px;flex-wrap:wrap}.sf-powered{display:inline-flex;align-items:center;gap:10px;cursor:pointer;outline:none}.sf-powered .sf-wordmark{height:11px}.sf-powered-line{display:none;font-size:12px;color:var(--muted)}.sf-powered:hover .sf-powered-line,.sf-powered:focus .sf-powered-line,.sf-powered:focus-within .sf-powered-line{display:inline}.sf-powered-line a{color:var(--accent);text-decoration:underline;text-underline-offset:3px}.sf-help{font-size:12px;color:var(--muted);text-decoration:underline;text-underline-offset:3px}.sf-help:hover{color:var(--fg)}@media(max-width:520px){.sf-page{padding:20px}.sf-button,.sf-access{min-height:48px}footer{padding-top:32px}}@media(prefers-color-scheme:dark){:root{--bg:#141313;--fg:#e9e7e2;--muted:#8f8b84}.sf-button,.sf-access{--on-accent:#141313}}@media(forced-colors:active){.sf-site-brand span{background:CanvasText}.sf-powered:focus{outline:1px solid CanvasText;outline-offset:3px}.sf-button,.sf-access{border:1px solid ButtonText}}
CSS;
    $accessCss = '.sf-page.access main{max-width:26rem;padding:clamp(42px,8vh,72px) 0}.sf-page.access h1{max-width:18ch;margin-bottom:14px;font:600 clamp(2rem,7vw,2.65rem)/1.12 var(--body);letter-spacing:-.035em}.sf-page.access .sf-copy{font-size:15px;line-height:1.6}.sf-access-default{display:flex;width:100%;flex-direction:column;align-items:center}.sf-access-status{padding:10px 12px;border:1px solid color-mix(in srgb,var(--fg) 10%,transparent);border-radius:8px;background:color-mix(in srgb,var(--fg) 4%,transparent);color:var(--fg)}.sf-access-lanes{display:flex;flex-direction:column;gap:16px;width:min(23rem,100%);margin-top:30px;text-align:left}.sf-access-lanes form{display:flex;flex-direction:column;gap:14px;margin:0}.sf-input{box-sizing:border-box;width:100%;min-height:46px;padding:11px 12px;border:1px solid color-mix(in srgb,var(--fg) 18%,transparent);border-radius:8px;outline:2px solid transparent;outline-offset:-1px;background:color-mix(in srgb,var(--bg) 92%,var(--fg));color:var(--fg);font:500 15px/1.45 var(--body);appearance:none}.sf-input::placeholder{color:color-mix(in srgb,var(--muted) 82%,transparent);font:inherit}.sf-input:hover{border-color:color-mix(in srgb,var(--fg) 34%,transparent)}.sf-input:focus-visible{border-color:var(--accent);outline-color:var(--accent)}textarea.sf-input{min-height:92px;resize:vertical}.sf-label{color:var(--fg);font:600 13px/1.4 var(--body)}.sf-access-lanes .sf-button{width:100%;min-height:46px;border-radius:8px}.sf-access-or{display:flex;align-items:center;gap:12px;color:var(--muted);font-size:13px}.sf-access-or:before,.sf-access-or:after{content:"";flex:1;border-top:1px solid color-mix(in srgb,var(--fg) 12%,transparent)}.sf-error{margin:0;color:#b42318;font-size:14px}.sf-access-password-row{display:flex;gap:8px}.sf-access-password-row .sf-input{min-width:0;flex:1}.sf-access-password-row .sf-button{width:auto;flex:none}.sf-access-request{padding-top:2px}.sf-access-request summary{padding:4px 0;cursor:pointer;color:var(--muted);font:550 14px/1.5 var(--body)}.sf-access-request summary:hover{color:var(--fg)}.sf-access-request[open] summary{color:var(--fg)}.sf-access-request form{margin-top:16px}.sf-button:focus-visible{outline:2px solid #a93115;outline-offset:2px}@media(max-width:520px){.sf-page.access main{padding:38px 0}.sf-page.access h1{font-size:2rem}.sf-page.access .sf-copy{font-size:16px}.sf-input{min-height:48px;font-size:16px}.sf-access-lanes .sf-button{min-height:48px}.sf-access-password-row{flex-direction:column}.sf-access-password-row .sf-button{width:100%}}@media(prefers-color-scheme:dark){.sf-access-status{background:color-mix(in srgb,var(--fg) 5%,transparent)}.sf-error{color:#f97066}}';
    $css = _stattic_page_font_faces() . $css . ($pageId === 'access' ? $accessCss : '');
    $robots = (!$sitePage || $pageId === 'denied' || $pageId === 'access')
        ? '<meta name="robots" content="noindex">'
        : '';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $robots . '<title>' . $title . '</title>' . _stattic_page_font_preloads() . '<style>' . $css . '</style></head><body><div class="sf-page ' . ($pageId === 'access' ? 'access ' : '') . ($plain ? 'plain' : 'center') . '"><header>' . $brand . '</header><main>' . $eyebrow . '<h1>' . $title . '</h1>' . $description . '</main>' . $footer . '</div></body></html>';
}

function _stattic_replace_page_runtime_slot(string $html, string $name, string $replacement): string
{
    $pattern = '/<!--sf-runtime:' . preg_quote($name, '/') . ':start-->[\s\S]*?<!--sf-runtime:' . preg_quote($name, '/') . ':end-->/';
    $rendered = preg_replace_callback($pattern, static fn (): string => $replacement, $html);
    return is_string($rendered) ? $rendered : $html;
}

function _stattic_compose_page_artifact(string $html, string $pageId, array $context): string
{
    $hasFragment = array_key_exists('fragment', $context) && is_string($context['fragment']);
    $fragment = $hasFragment ? $context['fragment'] : '';
    if ($pageId === 'denied' && $hasFragment) {
        return _stattic_replace_page_runtime_slot($html, 'denial', $fragment);
    }
    if ($pageId === 'access' && $hasFragment) {
        // Custom templates place the blocks; the runtime owns their contents so
        // forms, states and actions stay correct whatever the template does.
        $statusFragment = is_string($context['status_fragment'] ?? null)
            ? $context['status_fragment']
            : '';
        $lanesFragment = is_string($context['lanes_fragment'] ?? null)
            ? $context['lanes_fragment']
            : $fragment;
        $html = _stattic_replace_page_runtime_slot($html, 'access-status', $statusFragment);
        return _stattic_replace_page_runtime_slot($html, 'access-lanes', $lanesFragment);
    }
    if ($pageId === '404') {
        $requestPath = is_string($context['request_path'] ?? null) ? $context['request_path'] : '';
        if ($requestPath !== '') {
            return _stattic_replace_page_runtime_slot($html, 'request-path', _stattic_html_escape($requestPath));
        }
    }
    return $html;
}

function _stattic_serve_page(string $pageId, array $context = []): void
{
    $status = is_int($context['status'] ?? null) ? $context['status'] : 500;
    $headers = is_array($context['headers'] ?? null) ? $context['headers'] : [];
    $message = is_string($context['message'] ?? null) ? $context['message'] : '';
    $code = is_string($context['code'] ?? null) ? $context['code'] : str_replace('-', '_', $pageId);
    $customizable = ($context['customizable'] ?? false) === true;
    $fragment = is_string($context['fragment'] ?? null) ? $context['fragment'] : '';
    // A page that declared no policy is a fault page: nothing may store it.
    $headers['Cache-Control'] ??= STATTIC_CACHE_CONTROL_NO_STORE;
    if (($context['private'] ?? false) === true) {
        $headers = _stattic_private_content_response_headers($headers);
        _stattic_clear_private_content_response_headers();
    }
    $ambiguousDefault = $pageId === 'denied' || $pageId === 'access' ? 'html' : 'text';
    $negotiable = _stattic_page_may_negotiate($headers);
    $representation = $negotiable ? _stattic_page_representation($ambiguousDefault) : 'html';
    $requestMethod = _stattic_runtime_request_method();
    http_response_code($status);
    // §16: every fault page carries no-store or private, so this resolves to
    // the bypass directive. A denial or an error must never be the copy the
    // edge holds for the next visitor.
    foreach (_stattic_apply_platform_header_policy($headers) as $name => $value) {
        if (is_string($name) && is_scalar($value)) header($name . ': ' . (string) $value, true);
    }
    if ($negotiable) {
        header('Vary: Accept, Sec-Fetch-Mode', false);
    }
    if ($representation === 'json') {
        // `page` is an RFC 9457 extension member: the platform page an HTML
        // request would have been shown.
        _stattic_response_send(
            $status,
            json_encode(_stattic_problem_document($status, $code, trim($message), ['page' => $pageId]), JSON_UNESCAPED_SLASHES) . "\n",
            STATTIC_PROBLEM_MEDIA_TYPE . '; charset=utf-8',
        );
    }
    if ($representation === 'text') {
        _stattic_response_send(
            $status,
            ($message !== '' ? trim($message) : str_replace('-', ' ', $pageId)) . "\n",
            'text/plain; charset=utf-8',
        );
    }
    // Built only when it will be sent: composing a customized page reads an
    // artifact off disk, which a HEAD must not pay for.
    $html = '';
    if ($requestMethod !== 'HEAD') {
        $artifact = $customizable ? _stattic_page_artifact($context, $pageId) : null;
        $requestPath = is_string($context['request_path'] ?? null)
            ? $context['request_path']
            : _stattic_runtime_request_path();
        $html = $artifact !== null
            ? _stattic_compose_page_artifact($artifact, $pageId, $context)
            : _stattic_platform_page_html(
                $pageId,
                $status,
                $message,
                $fragment,
                $requestPath,
                is_string($context['title'] ?? null) ? $context['title'] : ''
            );
    }
    _stattic_response_send($status, $html, 'text/html; charset=utf-8');
}

function _stattic_render_platform_page(string $pageId, int $status, array $headers = [], string $fallback = '', bool $private = false): void
{
    $map = [
        'not-found' => 'gone', 'runtime-invariant-error' => 'runtime-error',
        'request-too-large' => 'content-too-large',
        'tombstone-dmca' => 'legal', 'tombstone-suspended' => 'suspended',
        'tombstone-generic' => 'gone',
    ];
    $resolvedPageId = $map[$pageId] ?? $pageId;
    // CSAM must remain indistinguishable from an unknown host. It deliberately
    // takes the same built-in undeployed fallback even when a stale Space
    // pointer still exists. Other platform pages may use a compiled partner
    // artifact when the request resolved a serving overlay.
    _stattic_serve_page($resolvedPageId, [
        'status' => $status,
        'headers' => $headers,
        'message' => $fallback,
        'code' => str_replace('-', '_', $pageId),
        'private' => $private,
        'customizable' => $pageId !== 'tombstone-csam' && $resolvedPageId !== 'undeployed',
    ]);
}

function _stattic_render_not_found(): void
{
    _stattic_render_platform_page('not-found', 404, ['Cache-Control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL], "Not Found\n");
}

function _stattic_render_runtime_invariant_error(string $code, string $message): void
{
    _stattic_serve_page('runtime-error', ['status' => 500, 'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE, 'X-Spacefast-Runtime-Error' => $code], 'message' => $message, 'code' => $code, 'customizable' => true]);
}

function _stattic_render_admission_shed(int $retryAfterSeconds, string $code = 'rate_limited'): void
{
    _stattic_serve_page('rate-limited', ['status' => 429, 'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE, 'Retry-After' => (string) $retryAfterSeconds], 'message' => 'Too Many Requests', 'code' => $code, 'customizable' => true]);
}

function _stattic_render_tier_fetch_unavailable(int $retryAfterSeconds): void
{
    _stattic_serve_page('tier-unavailable', ['status' => 503, 'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE, 'Retry-After' => (string) $retryAfterSeconds], 'message' => 'Service Unavailable', 'code' => 'tier_unavailable', 'customizable' => true]);
}

// A pointer/artifact that EXISTS could not be read this instant. Deliberately
// NOT the invariant-error 500 (nothing is violated) and never a state page:
// rendering `undeployed`/`denied`/404 off a failed read is the lie this code
// path exists to prevent. no-store keeps the edge from holding the failure.
function _stattic_render_runtime_unavailable(string $code, int $retryAfterSeconds = 5): never
{
    _stattic_serve_page('tier-unavailable', ['status' => 503, 'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE, 'Retry-After' => (string) $retryAfterSeconds, 'X-Spacefast-Unavailable' => $code], 'message' => 'Service Unavailable', 'code' => $code, 'customizable' => true]);
    exit;
}
