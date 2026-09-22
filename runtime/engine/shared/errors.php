<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/page-css.generated.php';
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

// `optional` never swaps mid-view: a cold visitor keeps the fallback for that
// one view while the preload warms the shared font-CDN cache; every later page
// on any space hostname renders the brand fonts immediately. The faces come
// from the brand document, so a host that dresses its pages in system stacks
// declares no fonts and these emit nothing.
function _stattic_page_font_faces(): string
{
    static $css = null;
    if (is_string($css)) {
        return $css;
    }
    $css = '';
    foreach (_stattic_brand()['fonts'] as $font) {
        $css .= '@font-face{font-family:"' . $font['family'] . '";src:url("' . $font['url'] . '") format("woff2");font-style:normal;font-weight:' . $font['weight'] . ';font-display:optional}';
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
    foreach (_stattic_brand()['fonts'] as $font) {
        if ($font['preload']) {
            $html .= '<link rel="preload" href="' . $font['url'] . '" as="font" type="font/woff2" crossorigin>';
        }
    }
    return $html;
}

// The compiled-in mark, drawn rather than fetched so a fault page never depends
// on an asset load. A brand document naming a `wordmark_url` swaps in that image
// instead; the accessible label is the product name either way.
function _stattic_brand_wordmark(): string
{
    $name = _stattic_html_escape(_stattic_brand_value('name'));
    $wordmarkUrl = _stattic_brand_value('wordmark_url');
    if ($wordmarkUrl !== '') {
        return '<img class="sf-wordmark" src="' . _stattic_html_escape($wordmarkUrl) . '" alt="' . $name . '">';
    }
    return '<svg class="sf-wordmark" viewBox="' . STATTIC_PAGE_WORDMARK_VIEW_BOX . '" role="img" aria-label="' . $name . '"><path fill="currentColor" d="' . STATTIC_PAGE_WORDMARK_PATH . '"></path></svg>';
}

const STATTIC_PLATFORM_PAGE_COPY = [
    '404' => ['Page not found', ''],
    'login' => ['This space is for members', 'Sign in to continue — you’ll come right back here.'],
    'denied' => ['Access denied', ''],
    'access' => ['This space is private', ''],
    'index' => ['Files unavailable', 'The directory listing could not be loaded.'],
    'preview' => ['Preview unavailable', 'This file preview could not be loaded.'],
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

function _stattic_platform_page_html(string $pageId, string $message, string $fragment = '', string $titleOverride = ''): string
{
    $copy = STATTIC_PLATFORM_PAGE_COPY[$pageId]
        ?? [_stattic_brand_value('name') . ' could not serve this page', $message !== '' ? trim($message) : 'Try again in a moment.'];
    $title = _stattic_html_escape($titleOverride !== '' ? $titleOverride : $copy[0]);
    $description = $copy[1] === '' ? '' : '<p class="sf-copy">' . _stattic_html_escape($copy[1]) . '</p>';
    $sitePage = in_array($pageId, ['404', 'denied', 'access', 'index', 'preview'], true);
    // The listing and the viewer lead with their subject, so their title sits a
    // tier down; every other page leads with the message itself.
    $layout = in_array($pageId, ['index', 'preview'], true) ? 'content' : 'message';
    if ($pageId === '404') {
        $description = '<p class="sf-copy">Nothing is published at this address. It may have been renamed or removed.</p><div class="sf-actions"><a class="sf-button" href="/">Go to index</a></div>';
    }
    if ($pageId === 'denied') {
        $description .= $fragment;
    } elseif ($pageId === 'access') {
        $description .= '<div class="sf-access-default">' . $fragment . '</div>';
    }
    // One colophon on every page: the mark on the left, help on the right, over
    // a hairline. There is no top-of-page brand any more.
    $footer = '<footer class="sf-colophon"><div class="sf-colophon-row"><span class="sf-powered" tabindex="0">'
        . _stattic_brand_wordmark()
        . '<span class="sf-powered-line"><a href="' . _stattic_html_escape(_stattic_brand_value('url')) . '">' . _stattic_html_escape(_stattic_brand_value('tagline')) . '</a></span></span></div>'
        . '<a class="sf-help" href="' . _stattic_html_escape(_stattic_brand_value('help_url')) . '">Need help?</a></footer>';
    // The stylesheet is generated from packages/common so these pages and the
    // compiled ones cannot drift apart again. Brand fonts still come from the
    // brand document — the built-in pages just no longer ask for any.
    $css = _stattic_page_font_faces() . STATTIC_PAGE_CSS . ($pageId === 'access' ? STATTIC_ACCESS_PAGE_CSS : '');
    $robots = (!$sitePage || $pageId === 'denied' || $pageId === 'access')
        ? '<meta name="robots" content="noindex">'
        : '';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $robots . '<title>' . $title . '</title>' . _stattic_page_font_preloads() . '<style>' . $css . '</style></head><body><div class="sf-page' . ($pageId === 'access' ? ' access' : '') . ' sf-page-' . $layout . '"><main class="sf-main"><h1>' . $title . '</h1>' . $description . '</main>' . $footer . '</div></body></html>';
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
        $html = $artifact !== null
            ? _stattic_compose_page_artifact($artifact, $pageId, $context)
            : _stattic_platform_page_html(
                $pageId,
                $message,
                $fragment,
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
