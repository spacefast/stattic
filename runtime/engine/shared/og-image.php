<?php
declare(strict_types=1);

// A page's link-preview image, served from the host the visitor used.
//
// The static lane's head splice points a page's `og:image` at
// `/__spacefast/og-image.<ext>?path=<page>` on the request host, `<page>` being
// the URL the visitor loaded. serve.php resolves that route as a request for
// `<page>` (host mounts and the version they pin, an immutable hostname's
// version, residue rewrites, the page's access check), and the route answers
// for the document that request is served:
//
//   * a page that declares a local image gets that file's bytes, once its magic
//     bytes prove PNG, JPEG, WebP or GIF, the extension agrees, it fits the
//     cap, and whoever may read the page may also read the image at its own
//     URL (a narrower Grant, rule or fence on the image is no tag at all).
//     Anything else (SVG, HTML, a renamed file) gets no tag and a 404;
//   * a public page that declares none gets its screenshot, fetched from mShots
//     here. A page an anonymous visitor cannot read gets no tag at all;
//   * an external image the author named stays exactly as written.
//
// The one thing a protected Space serves without a session is its declared
// image, on its live hostname, once it is claimed and unfenced
// (_stattic_og_image_private_preview): WhatsApp, Signal and Slack fetch a
// link's og:image in a separate cookieless request, and whoever holds the link
// already sees the page. Never its screenshot, never on an immutable version
// host. No tag this writes names another origin.

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/html-insert.php';

// The marker finalization leaves on its placeholders when a page declares no
// preview image (crates/stattic-runtime-core/src/content/mod.rs).
const STATTIC_OG_IMAGE_AUTO_ATTR = 'data-spacefast-auto-image';
const STATTIC_OG_IMAGE_MAX_BYTES = 5242880;
// The window the compiler scans for preview metadata; serve.php reads the same.
const STATTIC_OG_IMAGE_HEAD_BYTES = 65536;
const STATTIC_OG_IMAGE_SCREENSHOT_ENDPOINT = 'https://s0.wp.com/mshots/v1/';
const STATTIC_OG_IMAGE_SCREENSHOT_QUERY = '?w=1200&h=630';
const STATTIC_OG_IMAGE_SCREENSHOT_TIMEOUT_MS = 8000;
const STATTIC_OG_IMAGE_TYPES = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
];
const STATTIC_OG_IMAGE_META_KEYS = ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src'];

/** The page the route request names, canonicalized, or null. */
function _stattic_og_image_page_path(): ?string
{
    $raw = $_GET['path'] ?? null;
    if (!is_string($raw) || $raw === '' || $raw[0] !== '/') {
        return null;
    }
    return _stattic_canonical_request_path($raw);
}

/** The media type a file's leading bytes prove, or null for anything else. */
function _stattic_og_image_sniff(string $leading): ?string
{
    return match (true) {
        str_starts_with($leading, "\x89PNG\r\n\x1a\n") => 'image/png',
        str_starts_with($leading, "\xFF\xD8\xFF") => 'image/jpeg',
        str_starts_with($leading, 'GIF87a'), str_starts_with($leading, 'GIF89a') => 'image/gif',
        strlen($leading) >= 12 && str_starts_with($leading, 'RIFF') && substr($leading, 8, 4) === 'WEBP' => 'image/webp',
        default => null,
    };
}

/** A plain 200 file entry's blob path and length, or null. */
function _stattic_og_image_entry_blob(array $context, ?array $entry): ?array
{
    if (
        !is_array($entry)
        || isset($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_ACTION])
        || (int) ($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_STATUS] ?? 200) !== 200
        || !is_string($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_BLOB] ?? null)
        || !is_int($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_LENGTH] ?? null)
    ) {
        return null;
    }
    $relative = _stattic_blob_relative_key((string) $context['space_id'], $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_BLOB]);
    return $relative === null ? null : [
        'path' => (string) $context['private_root'] . '/' . $relative,
        'length' => $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_LENGTH],
        'headers' => is_array($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_HEADERS] ?? null) ? $entry[STATTIC_RUNTIME_RESPONSE_ENTRY_HEADERS] : [],
        // The compiler marks the files a page declares as its share image,
        // and the absolute references it could not place on a hostname yet.
        'declared' => !empty($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_PREVIEW_IMAGE])
            || in_array((string) ($context['host'] ?? ''), (array) ($entry[STATTIC_RUNTIME_RESPONSE_ENTRY_PREVIEW_IMAGE_HOSTS] ?? []), true),
    ];
}

/**
 * The file a request for `$filePath` on this host is served, when it is a
 * servable share image: under the cap, and its bytes a raster image of the
 * type its extension names. The URL resolves as a visitor's request for it
 * would (host mounts, the version a mount pins, residue rewrites), so a
 * reference that works in a browser works here. `path` is that URL, the one
 * the image's own access rules govern.
 *
 * @return array{blob: string, mime: string, ext: string, length: int, path: string, access_paths: list<string>, declared: bool}|null
 */
function _stattic_og_image_file(array $context, string $filePath): ?array
{
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $expected = STATTIC_OG_IMAGE_TYPES[$ext] ?? null;
    if ($expected === null) {
        return null;
    }
    $blob = _stattic_og_image_entry_blob($context, _stattic_v4_resolve_served_entry($context, $filePath, $servedPath));
    if ($blob === null || $blob['length'] <= 0 || $blob['length'] > STATTIC_OG_IMAGE_MAX_BYTES) {
        return null;
    }
    $stream = @fopen($blob['path'], 'rb');
    if ($stream === false) {
        return null;
    }
    $leading = (string) fread($stream, 16);
    fclose($stream);
    if (_stattic_og_image_sniff($leading) !== $expected) {
        return null;
    }
    return [
        'blob' => $blob['path'],
        'mime' => $expected,
        'ext' => $ext === 'jpeg' ? 'jpg' : $ext,
        'length' => $blob['length'],
        'path' => $filePath,
        // Both paths an access check for this URL admits, as for a page: the
        // URL itself, and the path a mount or rewrite serves it under.
        'access_paths' => array_values(array_unique([$filePath, (string) $servedPath])),
        'declared' => $blob['declared'],
    ];
}

/**
 * The local file a preview reference names, as an absolute URL path, or null
 * when it names another origin or nothing usable. An absolute URL is local only
 * on the request host.
 */
function _stattic_og_image_local_reference(string $reference, string $pageUrl, ?string $baseHref, string $requestHost): ?string
{
    $reference = trim($reference);
    $base = $reference === '' ? null : Uri\WhatWg\Url::parse($pageUrl);
    if ($base === null) {
        return null;
    }
    if ($baseHref !== null) {
        $base = Uri\WhatWg\Url::parse($baseHref, $base) ?? $base;
    }
    $resolved = Uri\WhatWg\Url::parse($reference, $base);
    if (
        $resolved === null
        || !in_array($resolved->getScheme(), ['http', 'https'], true)
        || strtolower((string) $resolved->getAsciiHost()) !== strtolower($requestHost)
        || $resolved->getPort() !== null
    ) {
        return null;
    }
    return _stattic_canonical_request_path(rawurldecode($resolved->getPath()));
}

function _stattic_og_image_advertised(WP_HTML_Tag_Processor $processor): bool
{
    return array_any(
        [$processor->get_attribute('property'), $processor->get_attribute('name')],
        static fn ($key): bool => is_string($key) && in_array(strtolower(trim($key)), STATTIC_OG_IMAGE_META_KEYS, true)
    );
}

/**
 * Walks the head's meta tags with the pinned HTML tokenizer, so scripts,
 * comments and quoted attributes cannot pose as preview metadata. Calls
 * `$visit($processor, $kind, $localPath)` per preview tag, where kind is
 * `placeholder:og:image`, `placeholder:twitter:card`, `local` or `external`.
 */
function _stattic_og_image_walk(WP_HTML_Tag_Processor $processor, string $pageUrl, string $host, callable $visit): void
{
    $baseHref = null;
    while ($processor->next_tag()) {
        $tag = $processor->get_tag();
        if ($tag === 'BODY') {
            return;
        }
        if ($tag === 'BASE' && $baseHref === null && is_string($href = $processor->get_attribute('href'))) {
            $baseHref = $href;
            continue;
        }
        if ($tag !== 'META') {
            continue;
        }
        $placeholder = $processor->get_attribute(STATTIC_OG_IMAGE_AUTO_ATTR);
        if (is_string($placeholder)) {
            $visit($processor, 'placeholder:' . $placeholder, null);
            continue;
        }
        $content = $processor->get_attribute('content');
        if (!_stattic_og_image_advertised($processor) || !is_string($content) || trim($content) === '') {
            continue;
        }
        $local = _stattic_og_image_local_reference($content, $pageUrl, $baseHref, $host);
        $visit($processor, $local === null ? 'external' : 'local', $local);
    }
}

function _stattic_og_image_processor(string $head): WP_HTML_Tag_Processor
{
    if (!class_exists('WP_HTML_Tag_Processor')) {
        require_once __DIR__ . '/../wordpress/content-markdown.php';
        spacefast_content_sync_require_toolkit();
    }
    return new WP_HTML_Tag_Processor($head);
}

/** Whether a head window can hold any preview tag the walk would visit. */
function _stattic_og_image_head_may_declare(string $head): bool
{
    return stripos($head, 'og:image') !== false
        || stripos($head, 'twitter:image') !== false
        || stripos($head, STATTIC_OG_IMAGE_AUTO_ATTR) !== false;
}

/**
 * What a page's link preview is, from its head: `file` (with the validated
 * file) for a servable declared image, `screenshot` for a public page that
 * declares none, `external` when the author named only other origins, or
 * `none`. The first local declaration decides: an author who named a file
 * that is not a real raster image gets no image, never a substitute; who may
 * read the image is _stattic_og_image_admit's to say.
 *
 * The head is tokenized at most once. A head that names no preview tag at all
 * is decided without the tokenizer (and without loading it); otherwise the
 * walk's processor rides along in `processor`, positioned on nothing yet
 * consumed, with every preview tag it visited bookmarked for the rewrite.
 *
 * @return array{kind: string, file?: array, tags: list<array{kind: string, bookmark: string}>, processor: ?WP_HTML_Tag_Processor}
 */
function _stattic_og_image_decide(string $head, array $context, string $pagePath, bool $public): array
{
    if (!_stattic_og_image_head_may_declare($head)) {
        return ['kind' => $public ? 'screenshot' : 'none', 'tags' => [], 'processor' => null];
    }
    $host = (string) $context['host'];
    $local = null;
    $external = false;
    $tags = [];
    $processor = _stattic_og_image_processor($head);
    _stattic_og_image_walk(
        $processor,
        _stattic_request_scheme() . '://' . $host . $pagePath,
        $host,
        static function (WP_HTML_Tag_Processor $tag, string $kind, ?string $path) use (&$local, &$external, &$tags): void {
            if ($kind === 'local') {
                $local ??= $path;
            } elseif ($kind === 'external') {
                $external = true;
                return;
            }
            // Only the tags the rewrite edits are bookmarked; the processor
            // holds a fixed number at once.
            $bookmark = 'og' . count($tags);
            if (count($tags) < WP_HTML_Tag_Processor::MAX_BOOKMARKS && $tag->set_bookmark($bookmark)) {
                $tags[] = ['kind' => $kind, 'bookmark' => $bookmark];
            }
        }
    );
    $decision = ['tags' => $tags, 'processor' => $processor];
    if ($local !== null) {
        $file = _stattic_og_image_file($context, $local);
        return $file === null ? ['kind' => 'none'] + $decision : ['kind' => 'file', 'file' => $file] + $decision;
    }
    return ['kind' => $external ? 'external' : ($public ? 'screenshot' : 'none')] + $decision;
}

function _stattic_og_image_serving(array $context): array
{
    return is_array($context['serving'] ?? null) ? $context['serving'] : [];
}

/** The decision for whoever the preview is for: a `file` they may not read at its own URL is `none`. */
function _stattic_og_image_admit(array $decision, array $context, bool $public): array
{
    if ($decision['kind'] === 'file' && !_stattic_og_image_reaches_file($context, $decision['file'], $public)) {
        $decision['kind'] = 'none';
        unset($decision['file']);
    }
    return $decision;
}

/**
 * Whether whoever this page's preview is for may read its image at the
 * image's own URL, the gate that governed it when the advertised URL was the
 * image path itself. Like a page, both the URL and the path a mount or rewrite
 * serves it under must admit them. A public page's image must be public too; a
 * private-preview request's must be one main's preview-image exemption would
 * have served (_stattic_og_image_private_preview_file); any other visitor must
 * be admitted to it like to any other file. A narrower Grant, rule or fence on
 * either path is no tag at all.
 */
function _stattic_og_image_reaches_file(array $context, array $file, bool $public): bool
{
    if (!empty($context['open'])) {
        return true;
    }
    require_once __DIR__ . '/../runtime/access-rules.php';
    if (!empty($context['private_preview'])) {
        return _stattic_og_image_private_preview_file($context, $file);
    }
    $serving = is_array($context['serving'] ?? null) ? $context['serving'] : [];
    $host = (string) $context['host'];
    foreach ($file['access_paths'] as $path) {
        $reaches = $public
            ? _stattic_access_unfenced($serving, $host, $path) && _stattic_access_anonymous_admits($serving, $host, $path)
            : _stattic_enforce_scoped_admission($serving, $host, $path, true) !== null;
        if (!$reaches) {
            return false;
        }
    }
    return true;
}

/**
 * Whether a protected Space answers `/__spacefast/og-image.<ext>?path=<page>`
 * without a session: claimed, unfenced, on its live (never an immutable
 * version or branch) hostname, for a page an anonymous visitor cannot already
 * read. The image itself must then pass _stattic_og_image_private_preview_file.
 * An unclaimed Space has no owner to consent to sharing the image.
 */
function _stattic_og_image_private_preview(array $serving, string $host, string $pagePath, string $documentPath): bool
{
    require_once __DIR__ . '/../runtime/access-rules.php';
    if (
        !empty($serving['immutable'])
        || (_stattic_grant_target($serving)['kind'] ?? null) !== 'live'
        || ($serving['authorization']['spaceClaimed'] ?? false) !== true
    ) {
        return false;
    }
    foreach (array_unique([$pagePath, $documentPath]) as $path) {
        if (!_stattic_access_unfenced($serving, $host, $path)) {
            return false;
        }
    }
    return !_stattic_access_anonymous_admits($serving, $host, $documentPath)
        || !_stattic_access_anonymous_admits($serving, $host, $pagePath);
}

/**
 * The file half of the private-preview exemption: the image the compiler
 * marked as a page's declared share image (main's `_stattic_v4_public_preview_image`),
 * at a path no fence holds. Never the screenshot, which is no file.
 */
function _stattic_og_image_private_preview_file(array $context, array $file): bool
{
    $serving = is_array($context['serving'] ?? null) ? $context['serving'] : [];
    $host = (string) $context['host'];
    return $file['declared']
        && array_all($file['access_paths'], static fn (string $path): bool => _stattic_access_unfenced($serving, $host, $path));
}

function _stattic_og_image_url(string $host, string $ext, string $pagePath): string
{
    return _stattic_request_scheme() . '://' . $host . STATTIC_OG_IMAGE_PATH_PREFIX . $ext
        . '?path=' . rawurlencode($pagePath);
}

// The tag processor can rewrite a tag but not cut it, so a dropped tag is
// reduced to one attribute carrying a per-request nonce and that exact
// serialization is removed afterwards. Author text cannot carry the nonce.
function _stattic_og_image_mark_dropped(WP_HTML_Tag_Processor $processor, string $nonce): void
{
    foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $attribute) {
        $processor->remove_attribute($attribute);
    }
    $processor->set_attribute('data-spacefast-drop', $nonce);
}

function _stattic_og_image_cut_dropped(string $html, string $nonce): string
{
    return (string) preg_replace('~<meta\s+data-spacefast-drop="' . preg_quote($nonce, '~') . '"\s*/?>\n?~i', '', $html);
}

/**
 * Rewrites a static page's head window. Local preview references and the
 * finalizer's placeholder become the route URL on the request host; a page
 * with no tag at all gets one; a declared image that fails validation or that
 * this page's visitor may not read, and the placeholder of a non-public page,
 * are cleared. External references are the author's and stay. The rewrite
 * edits the tags the decision's one walk bookmarked, so the head is tokenized
 * at most once, and not at all when it names no preview tag.
 */
function _stattic_og_image_rewrite_head(string $head, array $context, string $pagePath, bool $public): string
{
    // A Space that turned platform injection off keeps its head exactly as
    // the version stored it, its own preview tags included.
    if (_stattic_platform_injection_disabled(_stattic_og_image_serving($context))) {
        return $head;
    }
    $decision = _stattic_og_image_admit(_stattic_og_image_decide($head, $context, $pagePath, $public), $context, $public);
    $host = (string) $context['host'];
    $routeUrl = match ($decision['kind']) {
        'file' => _stattic_og_image_url($host, $decision['file']['ext'], $pagePath),
        'screenshot' => _stattic_og_image_url($host, 'jpg', $pagePath),
        default => null,
    };
    $wrote = false;
    $processor = $decision['processor'];
    if ($processor instanceof WP_HTML_Tag_Processor && $decision['tags'] !== []) {
        $nonce = bin2hex(random_bytes(8));
        foreach ($decision['tags'] as ['kind' => $kind, 'bookmark' => $bookmark]) {
            if (!$processor->seek($bookmark)) {
                continue;
            }
            if ($kind === 'placeholder:twitter:card') {
                $processor->remove_attribute(STATTIC_OG_IMAGE_AUTO_ATTR);
                if ($routeUrl !== null) {
                    $processor->set_attribute('content', 'summary_large_image');
                }
                continue;
            }
            // A placeholder carries the brand fallback, which names another
            // origin: it becomes the screenshot or nothing.
            $keep = $routeUrl !== null && ($kind === 'local' || $decision['kind'] === 'screenshot');
            if (!$keep) {
                _stattic_og_image_mark_dropped($processor, $nonce);
                continue;
            }
            $processor->remove_attribute(STATTIC_OG_IMAGE_AUTO_ATTR);
            if ($kind !== 'local') {
                $processor->set_attribute('property', 'og:image');
            }
            $processor->set_attribute('content', $routeUrl);
            $wrote = true;
        }
        $head = _stattic_og_image_cut_dropped($processor->get_updated_html(), $nonce);
    }
    // A projection with no SDK section (an older control plane) gets no tag
    // the author did not write.
    if ($routeUrl !== null && !$wrote && _stattic_platform_injection_enabled(_stattic_og_image_serving($context))) {
        $head = _stattic_splice_head_markup(
            $head,
            '<meta property="og:image" content="' . htmlspecialchars($routeUrl, ENT_QUOTES | ENT_HTML5) . '">'
        );
    }
    return $head;
}

/**
 * The bounded head window of the page entry serving `$pagePath`, or null when
 * it is no HTML page.
 */
function _stattic_og_image_page_head(array $context, string $pagePath): ?string
{
    $versionDir = (string) $context['version_dir'];
    $entry = _stattic_v4_entry($versionDir, $context['root'], $pagePath)
        ?? _stattic_v4_entry($versionDir, $context['root'], STATTIC_RUNTIME_RESPONSE_KEY_SPA);
    $blob = _stattic_og_image_entry_blob($context, $entry);
    if ($blob === null || !str_starts_with(strtolower((string) ($blob['headers']['content-type'] ?? '')), 'text/html')) {
        return null;
    }
    $stream = @fopen($blob['path'], 'rb');
    if ($stream === false) {
        return null;
    }
    $head = stream_get_contents($stream, min($blob['length'], STATTIC_OG_IMAGE_HEAD_BYTES));
    fclose($stream);
    return is_string($head) ? $head : null;
}

/**
 * The route's 404. Once the request has been admitted to a page whose verdict
 * is private, its refusal is private too: a shared copy would tell the next
 * anonymous request what the page declares.
 */
function _stattic_og_image_not_found(bool $privateCache = false): never
{
    header('Cache-Control: ' . ($privateCache ? STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE : STATTIC_DEFAULT_EDGE_CACHE_CONTROL), true);
    if ($privateCache) {
        header('A8C-Edge-Cache: no-cache', true);
    }
    header('Content-Type: text/plain; charset=utf-8', true);
    http_response_code(404);
    echo "No share image.\n";
    exit;
}

/**
 * Answers `/__spacefast/og-image.<ext>?path=<page>` once serve.php has resolved
 * `<page>` to the document `$documentPath` and either passed that page's
 * access check or found the request is a private-preview candidate
 * (`$context['private_preview']`, see _stattic_og_image_private_preview).
 * `$context['client_path']` is `<page>` itself; `$public` says whether an
 * anonymous visitor reads it.
 */
function _stattic_og_image_serve(array $context, string $routePath, string $documentPath, string $requestMethod, bool $public, bool $privateCache): never
{
    // The route is the platform's; a Space that turned platform injection off
    // has none, so no page of it ever names one.
    if (_stattic_platform_injection_disabled(_stattic_og_image_serving($context))) {
        _stattic_og_image_not_found($privateCache);
    }
    $head = _stattic_og_image_page_head($context, $documentPath);
    $pagePath = (string) $context['client_path'];
    $decided = $head === null ? null : _stattic_og_image_decide($head, $context, $pagePath, $public);
    $requestedExt = substr($routePath, strlen(STATTIC_OG_IMAGE_PATH_PREFIX));
    $privatePreview = !empty($context['private_preview']);
    $decision = $decided === null ? null : _stattic_og_image_admit($decided, $context, $public);
    if ($privatePreview && !(($decision['kind'] ?? null) === 'file' && $requestedExt === $decision['file']['ext'])) {
        // Nothing else is the exemption's to give: the request goes through
        // the page's own gate, and an admitted visitor gets the ordinary,
        // private answer.
        require_once __DIR__ . '/../runtime/access-rules.php';
        _stattic_access_enforce_v4((string) $context['host'], $documentPath, $pagePath);
        unset($context['private_preview']);
        $privatePreview = false;
        $privateCache = true;
        $decision = $decided === null ? null : _stattic_og_image_admit($decided, $context, false);
    }
    if ($decision === null) {
        _stattic_og_image_not_found($privateCache);
    }
    $cacheControl = $privateCache ? STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE : STATTIC_DEFAULT_EDGE_CACHE_CONTROL;

    if ($decision['kind'] === 'file' && $requestedExt === $decision['file']['ext']) {
        $file = $decision['file'];
        header('Content-Type: ' . $file['mime'], true);
        header('Content-Length: ' . $file['length'], true);
        // A private-preview answer keeps the shared policy main's preview-image
        // exemption had: it lives on the live hostname only, which the route
        // write an access change or a fence makes purges.
        header('Cache-Control: ' . $cacheControl, true);
        if ($privateCache) {
            header('A8C-Edge-Cache: no-cache', true);
        }
        header('X-Content-Type-Options: nosniff', true);
        header('X-Robots-Tag: ' . ($privatePreview ? 'noindex, nofollow' : 'noindex'), true);
        http_response_code(200);
        if ($requestMethod === 'GET') {
            readfile($file['blob']);
        }
        exit;
    }
    if ($decision['kind'] === 'screenshot' && $requestedExt === 'jpg') {
        _stattic_og_image_send_screenshot($context, $pagePath, $requestMethod, $cacheControl);
    }
    _stattic_og_image_not_found($privateCache);
}

/**
 * Proxies the page's mShots screenshot. mShots answers a URL it has not
 * finished with a redirect to a generic placeholder that unfurlers would
 * cache, so anything but a rendered JPEG is a short-lived 503 instead.
 */
function _stattic_og_image_send_screenshot(array $context, string $pagePath, string $requestMethod, string $cacheControl): never
{
    // mShots keeps one screenshot per URL for a day; the version in the query
    // makes every publish its own capture.
    $target = _stattic_request_scheme() . '://' . (string) $context['host'] . $pagePath
        . '?v=' . substr(hash('sha256', (string) $context['version_id']), 0, 12);
    $endpoint = _stattic_config_value('SPACEFAST_SHARE_SCREENSHOT_ENDPOINT') ?: STATTIC_OG_IMAGE_SCREENSHOT_ENDPOINT;
    require_once __DIR__ . '/http.php';
    $result = _stattic_http_request([
        'url' => $endpoint . rawurlencode($target) . STATTIC_OG_IMAGE_SCREENSHOT_QUERY,
        'method' => 'GET',
        'timeout_ms' => STATTIC_OG_IMAGE_SCREENSHOT_TIMEOUT_MS,
        'redirects' => 0,
        'max_body_bytes' => STATTIC_OG_IMAGE_MAX_BYTES,
        // The endpoint override exists for loopback test fixtures only.
        'schemes' => str_starts_with($endpoint, 'http://127.0.0.1:') ? ['http'] : ['https'],
    ]);
    $headers = _stattic_http_header_map(is_array($result['headers'] ?? null) ? $result['headers'] : []);
    $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''), 2)[0]));
    if (($result['status'] ?? 0) !== 200 || $type !== 'image/jpeg' || _stattic_og_image_sniff(substr((string) $result['body'], 0, 16)) !== 'image/jpeg') {
        header('Cache-Control: ' . STATTIC_CACHE_CONTROL_NO_STORE, true);
        header('Retry-After: 30', true);
        header('Content-Type: text/plain; charset=utf-8', true);
        http_response_code(503);
        echo "The screenshot is still rendering.\n";
        exit;
    }
    header('Content-Type: image/jpeg', true);
    header('Content-Length: ' . strlen((string) $result['body']), true);
    header('Cache-Control: ' . $cacheControl, true);
    header('X-Content-Type-Options: nosniff', true);
    header('X-Robots-Tag: noindex', true);
    http_response_code(200);
    if ($requestMethod === 'GET') {
        echo $result['body'];
    }
    exit;
}
