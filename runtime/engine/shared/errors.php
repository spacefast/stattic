<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';

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
    $directory = str_ends_with($requestPath, '/') ? trim($requestPath, '/') : trim(dirname($requestPath), '/.');
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
    $versionId = is_string($serving['version_id'] ?? null) ? $serving['version_id'] : '';
    $spaceId = is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    $versions = is_array($serving['versions'] ?? null) ? $serving['versions'] : [];
    $version = is_array($versions[$versionId] ?? null) ? $versions[$versionId] : [];
    $config = is_array($version['serving_config'] ?? null) ? $version['serving_config'] : [];
    $pages = is_array($config['pages'] ?? null) ? $config['pages'] : [];
    $routes = is_array($pages['routes'] ?? null) ? $pages['routes'] : [];
    $requestPath = is_string($context['request_path'] ?? null) ? $context['request_path'] : _stattic_runtime_request_path();
    $artifact = is_string($context['artifact'] ?? null) ? $context['artifact'] : null;
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
    $root = _spacefast_access_private_root();
    if (!is_string($artifact) || preg_match('/^[a-z0-9-]{1,240}$/', $artifact) !== 1 || $root === '' || !_spacefast_id_valid($spaceId) || !_spacefast_id_valid($versionId)) {
        return null;
    }
    $path = _spacefast_version_root($root, $spaceId, $versionId) . '/pages/' . $artifact . '.html';
    $size = @filesize($path);
    if (!is_int($size) || $size < 1 || $size > 2 * 1024 * 1024) {
        return null;
    }
    $html = @file_get_contents($path);
    return is_string($html) ? $html : null;
}

function _stattic_page_status_reason(int $status): string
{
    return [
        200 => 'OK', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 413 => 'Content Too Large',
        429 => 'Too Many Requests', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
    ][$status] ?? 'Error';
}

function _stattic_page_font_faces(): string
{
    static $css = null;
    if (is_string($css)) {
        return $css;
    }
    $faces = [
        ['Merriweather', 'merriweather-latin-variable.woff2', '300 900'],
        ['Haskoy', 'haskoy-latin-variable.woff2', '100 900'],
    ];
    $css = '';
    foreach ($faces as [$family, $file, $weight]) {
        $css .= '@font-face{font-family:"' . $family . '";src:url("/__spacefast/pages/fonts/' . $file . '") format("woff2");font-style:normal;font-weight:' . $weight . ';font-display:swap}';
    }
    return $css;
}

function _stattic_spacefast_wordmark(): string
{
    $path = 'M20 15h24v7H27v7h17v20H20v-7h17v-7H20V15ZM51 15h24v18H58v16h-7V15Zm7 11h10v-4H58v4ZM83 15h24l9 34h-8l-2-8H84l-2 8h-8l9-34Zm3 19h18l-4-12H90l-4 12ZM145 22h-19v20h19v7h-26V15h26v7ZM152 15h26v7h-19v6h15v7h-15v7h19v7h-26V15ZM185 15h25v7h-18v6h15v7h-15v14h-7V15ZM215 15h24l9 34h-8l-2-8h-22l-2 8h-8l9-34Zm3 19h18l-4-12h-10l-4 12ZM250 15h24v7h-17v7h17v20h-24v-7h17v-7h-17V15ZM279 15h27v7h-10v27h-7V22h-10v-7Z';
    return '<svg class="sf-wordmark" viewBox="0 0 326 62" role="img" aria-label="Spacefast"><rect x="14" y="12" width="306" height="48" fill="#ff4217"/><rect x="10" y="8" width="306" height="48" fill="#141419"/><path fill="#fbf8f1" d="' . $path . '"/></svg>';
}

function _stattic_platform_page_html(string $pageId, int $status, string $message, string $fragment = '', string $requestPath = ''): string
{
    $definitions = [
        '404' => ['Page not found', ''],
        'password' => ['This space is protected', 'Enter the password to continue.'],
        'login' => ['This space is for members', 'Sign in to continue — you’ll come right back here.'],
        'denied' => ['Access denied', ''],
        'index' => ['Files unavailable', 'The directory listing could not be loaded.'],
        'preview' => ['Preview unavailable', 'This file preview could not be loaded.'],
        'undeployed' => ['Waiting for launch', 'This space hasn’t been published yet. Check back soon.'],
        'suspended' => ['This space is paused', 'Serving is on hold until billing is sorted out.'],
        'legal' => ['Unavailable for legal reasons', 'This space is blocked in response to a legal demand.'],
        'visit-cap' => ['A little too popular', 'This space reached its visit limit. Try again later.'],
        'gone' => ['Nothing here', 'This space is no longer available.'],
        'rate-limited' => ['Slow down a second', 'Too many requests hit this space at once. Give it a moment and try again.'],
        'tier-unavailable' => ['Back in a bit', 'This space is temporarily unavailable.'],
        'method-not-allowed' => ['That method won’t work here', 'Try the request another way.'],
        'content-too-large' => ['That request is too large', 'Send a smaller request and try again.'],
        'proxy-error' => ['The upstream missed the connection', 'Try again in a moment.'],
        'runtime-error' => ['Something broke on our side', 'The runtime hit an error serving this page. Try again in a moment.'],
    ];
    $copy = $definitions[$pageId] ?? ['Spacefast could not serve this page', $message !== '' ? trim($message) : 'Try again in a moment.'];
    $title = _stattic_html_escape($copy[0]);
    $description = $copy[1] === '' ? '' : '<p class="sf-copy">' . _stattic_html_escape($copy[1]) . '</p>';
    $sitePage = in_array($pageId, ['404', 'password', 'login', 'denied', 'index', 'preview'], true);
    $plain = in_array($pageId, ['index', 'preview'], true);
    $showStatus = !$sitePage || $pageId === '404';
    $eyebrow = $showStatus ? '<p class="sf-eyebrow">' . $status . ' · ' . _stattic_html_escape(_stattic_page_status_reason($status)) . '</p>' : '';
    if ($pageId === '404') {
        $path = $requestPath !== '' ? _stattic_html_escape($requestPath) : 'this address';
        $description = '<p class="sf-copy">There’s nothing at <span class="sf-inline-path">' . $path . '</span>.</p><div class="sf-actions"><a class="sf-button" href="/">Go to the homepage</a></div>';
    }
    if (in_array($pageId, ['password', 'login', 'denied'], true)) {
        $description .= $fragment;
    }
    $brand = $sitePage
        ? '<a class="sf-site-brand" href="/" aria-label="Homepage"><span aria-hidden="true"></span><b>This space</b></a>'
        : _stattic_spacefast_wordmark();
    $footerWordmark = $sitePage
        ? '<span class="sf-powered" tabindex="0">' . _stattic_spacefast_wordmark() . '<span class="sf-powered-line"><a href="https://spacefast.com">Best way to share what your agent made</a></span></span>'
        : '';
    $footer = '<footer><div class="sf-colophon-row">' . $footerWordmark . '<a class="sf-help" href="https://spacefast.com/help">Need help?</a></div></footer>';
    $css = <<<'CSS'
:root{color-scheme:light dark;--bg:#f9f8f4;--fg:#1a1913;--muted:#6f6b62;--line:#ddd9cf;--accent:#ff4217;--on-accent:#1a1913;--error:#a82d1a;--display:"Merriweather",Georgia,serif;--body:"Haskoy",-apple-system,BlinkMacSystemFont,ui-sans-serif,"Segoe UI",sans-serif;--mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace}*{box-sizing:border-box}html{min-width:320px;background:var(--bg);-webkit-font-smoothing:antialiased}body{margin:0;min-height:100vh;background:var(--bg);color:var(--fg);font:14px/1.65 var(--body)}.sf-page{min-height:100vh;display:flex;flex-direction:column;padding:clamp(22px,4vw,44px)}header{display:flex;min-height:32px;align-items:center;justify-content:center}.sf-page.plain header{justify-content:flex-start}.sf-wordmark{display:block;width:auto;height:17px}.sf-site-brand{display:inline-flex;align-items:center;gap:10px;color:var(--fg);text-decoration:none}.sf-site-brand span{width:7px;height:7px;flex:none;background:var(--accent)}.sf-site-brand b{font-size:13px;font-weight:600}main{flex:1;width:100%;max-width:620px;margin:auto;padding:clamp(34px,9vh,84px) 0;display:flex;flex-direction:column;align-items:center;text-align:center}.sf-page.plain main{max-width:680px;margin:0;padding:clamp(24px,6vh,56px) 0;align-items:flex-start;text-align:left}.sf-eyebrow{margin:0 0 16px;color:var(--muted);font:500 11px/1.5 var(--mono);letter-spacing:.12em;text-transform:uppercase}.sf-page.plain .sf-eyebrow{margin-bottom:14px}h1{max-width:20ch;margin:0 0 12px;font:430 clamp(1.9rem,5vw,2.8rem)/1.22 var(--display);letter-spacing:-.012em;text-wrap:balance}.sf-copy{max-width:52ch;margin:0;color:var(--muted);text-wrap:pretty}.sf-copy+.sf-copy{margin-top:12px}.sf-inline-path{color:var(--fg);font-family:var(--mono);font-size:.9em;overflow-wrap:anywhere}.sf-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:14px;margin-top:22px}.sf-button,button,.sf-login{display:inline-flex;min-height:44px;align-items:center;justify-content:center;border:0;background:var(--accent);color:var(--on-accent);padding:10px 16px;font:600 13px/1.4 var(--body);text-decoration:none;cursor:pointer}.sf-button:hover,button:hover,.sf-login:hover{filter:brightness(1.06)}a:focus-visible,button:focus-visible{outline:2px solid #a93115;outline-offset:2px}.sf-block{width:100%;max-width:360px;margin-top:22px;text-align:left}.sf-block label{display:block;flex-basis:100%;margin-bottom:8px;color:var(--muted);font:500 11px/1.5 var(--mono);letter-spacing:.1em;text-transform:uppercase}.sf-block form{display:flex;flex-wrap:wrap;gap:10px}.sf-pw{min-width:0;flex:1;border:1px solid var(--line);background:transparent;color:var(--fg);padding:9px 11px;font:14px/1.5 var(--body)}.sf-pw:focus-visible{outline:2px solid #a93115;outline-offset:-2px}.sf-pw[aria-invalid=true]{border-color:var(--error)}.sf-error{flex-basis:100%;margin:0;color:var(--error);font-size:14px}.sf-login-block{display:flex;justify-content:center}.sf-login-block+.sf-login-block{margin-top:10px}footer{display:flex;flex-direction:column;align-items:center;gap:10px;padding:44px 0 26px;margin-top:auto;color:var(--muted)}.sf-page.plain footer{align-items:flex-start}.sf-colophon-row{display:flex;align-items:center;gap:20px;flex-wrap:wrap}.sf-powered{display:inline-flex;align-items:center;gap:10px;cursor:pointer;outline:none}.sf-powered .sf-wordmark{height:11px}.sf-powered-line{display:none;font-size:12px;color:var(--muted)}.sf-powered:hover .sf-powered-line,.sf-powered:focus .sf-powered-line,.sf-powered:focus-within .sf-powered-line{display:inline}.sf-powered-line a{color:var(--accent);text-decoration:underline;text-underline-offset:3px}.sf-help{font-size:12px;color:var(--muted);text-decoration:underline;text-underline-offset:3px}.sf-help:hover{color:var(--fg)}@media(max-width:520px){.sf-page{padding:20px}.sf-block form{flex-direction:column}.sf-button,button,.sf-login{min-height:48px}footer{padding-top:32px}}@media(prefers-color-scheme:dark){:root{--bg:#141313;--fg:#e9e7e2;--muted:#8f8b84;--line:#2b2a27;--error:#ff8a7a}.sf-button,button,.sf-login{--on-accent:#141313}a:focus-visible,button:focus-visible,.sf-pw:focus-visible{outline-color:#ff8a7a}}@media(forced-colors:active){.sf-site-brand span{background:CanvasText}.sf-powered:focus{outline:1px solid CanvasText;outline-offset:3px}.sf-button,button,.sf-login{border:1px solid ButtonText}}
CSS;
    $css = _stattic_page_font_faces() . $css;
    $robots = (!$sitePage || in_array($pageId, ['password', 'login', 'denied'], true))
        ? '<meta name="robots" content="noindex">'
        : '';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $robots . '<title>' . $title . '</title><style>' . $css . '</style></head><body><div class="sf-page ' . ($plain ? 'plain' : 'center') . '"><header>' . $brand . '</header><main>' . $eyebrow . '<h1>' . $title . '</h1>' . $description . '</main>' . $footer . '</div></body></html>';
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
    if (in_array($pageId, ['password', 'login'], true) && $hasFragment) {
        return _stattic_replace_page_runtime_slot($html, 'challenge', $fragment);
    }
    if ($pageId === 'denied' && $hasFragment) {
        return _stattic_replace_page_runtime_slot($html, 'denial', $fragment);
    }
    if ($pageId === '404') {
        $requestPath = is_string($context['request_path'] ?? null) ? $context['request_path'] : '';
        if ($requestPath !== '') {
            return _stattic_replace_page_runtime_slot($html, 'request-path', _stattic_html_escape($requestPath));
        }
    }
    return $html;
}

/** Selects a publish-rendered site page, or an engine-owned fault default. */
function _stattic_serve_page(string $pageId, array $context = []): void
{
    $status = is_int($context['status'] ?? null) ? $context['status'] : 500;
    $headers = is_array($context['headers'] ?? null) ? $context['headers'] : [];
    $message = is_string($context['message'] ?? null) ? $context['message'] : '';
    $code = is_string($context['code'] ?? null) ? $context['code'] : str_replace('-', '_', $pageId);
    $customizable = ($context['customizable'] ?? false) === true;
    $fragment = is_string($context['fragment'] ?? null) ? $context['fragment'] : '';
    // Gate pages exist to be seen by whoever hit the wall: an ambiguous Accept
    // still gets the functional HTML (form/login link). Fault pages keep the
    // terse one-line text default.
    $ambiguousDefault = in_array($pageId, ['password', 'login', 'denied'], true) ? 'html' : 'text';
    $representation = _stattic_page_representation($ambiguousDefault);
    $requestMethod = _stattic_runtime_request_method();
    http_response_code($status);
    foreach ($headers as $name => $value) {
        if (is_string($name) && is_scalar($value)) header($name . ': ' . (string) $value, true);
    }
    header('Vary: Accept, Sec-Fetch-Mode', false);
    if ($representation === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        $error = ['code' => $code, 'status' => $status, 'page' => $pageId];
        if ($message !== '') $error['message'] = trim($message);
        if ($pageId === 'password') $error['action'] = ['method' => 'POST', 'field' => '_pw', 'url' => (string) ($_SERVER['REQUEST_URI'] ?? '/')];
        if ($requestMethod !== 'HEAD') echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES) . "\n";
        exit;
    }
    if ($representation === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        if ($requestMethod !== 'HEAD') echo ($message !== '' ? trim($message) : str_replace('-', ' ', $pageId)) . "\n";
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    if ($requestMethod !== 'HEAD') {
        $artifact = $customizable ? _stattic_page_artifact($context, $pageId) : null;
        $requestPath = is_string($context['request_path'] ?? null)
            ? $context['request_path']
            : _stattic_runtime_request_path();
        if ($artifact !== null) {
            $legacyBasicOnlyPasswordPage = $pageId === 'password'
                && array_key_exists('fragment', $context)
                && $fragment === ''
                && !str_contains($artifact, '<!--sf-runtime:challenge:start-->');
            echo $legacyBasicOnlyPasswordPage
                ? _stattic_platform_page_html($pageId, $status, $message, '', $requestPath)
                : _stattic_compose_page_artifact($artifact, $pageId, $context);
        } else {
            echo _stattic_platform_page_html($pageId, $status, $message, $fragment, $requestPath);
        }
    }
    exit;
}

function _stattic_render_platform_page(string $pageId, int $status, array $headers = [], string $fallback = ''): void
{
    $map = [
        'not-found' => 'gone', 'runtime-invariant-error' => 'runtime-error',
        'request-too-large' => 'content-too-large',
        'tombstone-dmca' => 'legal', 'tombstone-suspended' => 'suspended',
        'tombstone-visit-cap' => 'visit-cap', 'tombstone-generic' => 'gone',
    ];
    _stattic_serve_page($map[$pageId] ?? $pageId, ['status' => $status, 'headers' => $headers, 'message' => $fallback, 'code' => str_replace('-', '_', $pageId)]);
}

function _stattic_render_not_found(): void
{
    _stattic_render_platform_page('not-found', 404, ['Cache-Control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL], "Not Found\n");
}

function _stattic_render_runtime_invariant_error(string $code, string $message): void
{
    _stattic_serve_page('runtime-error', ['status' => 500, 'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE, 'X-Spacefast-Runtime-Error' => $code], 'message' => $message, 'code' => $code]);
}

function _stattic_render_admission_shed(int $retryAfterSeconds = 2): void
{
    _stattic_serve_page('rate-limited', ['status' => 429, 'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE, 'Retry-After' => (string) $retryAfterSeconds], 'message' => 'Too Many Requests', 'code' => 'rate_limited']);
}

function _stattic_render_tier_fetch_unavailable(int $retryAfterSeconds = 30): void
{
    _stattic_serve_page('tier-unavailable', ['status' => 503, 'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE, 'Retry-After' => (string) $retryAfterSeconds], 'message' => 'Service Unavailable', 'code' => 'tier_unavailable']);
}
