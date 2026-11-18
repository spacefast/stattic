<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * The project's `public/` folder at the site root (spec §1: "static files, served as-is"): a GET or
 * HEAD for `/logo.svg` answers with the app plugin's `public/logo.svg`.
 *
 * A web server that serves the files itself is faster, and a host should map `/` to the plugin's
 * `public/` before PHP where it can. This answers when nothing did, on `do_parse_request`, before
 * WordPress resolves the URL, so the same project serves the same files on `typedpress dev`, a WordPress
 * host and a Space:
 *
 * - only a path to a regular file inside `public/`, resolved without following a link out of it;
 * - never a path WordPress or TypedPress owns (`/wp-admin`, `/wp-json`, `/__tp/…`), whatever
 *   `public/` holds, and never PHP or a dotfile (except `/.well-known/`);
 * - never a path a `tp/app/` page or route serves: the app's code wins over a file;
 * - a `.md` file is served as Markdown, so a project can ship hand-written twins;
 * - `index.html` answers for its folder (`/files/` → `public/files/index.html`), except at the
 *   site root, where WordPress's home page stays (`public/index.html` is `/index.html`).
 *
 * The answer carries the type by extension, a validator, and `public, max-age=300`; a file whose
 * name has a content hash in it can be served longer by the host. It also says any origin may read
 * it (`Access-Control-Allow-Origin: *`): the file is the same for everyone and needs no sign-in, so
 * a page on another site (WordPress Playground fetching a ZIP, a font or a JSON file used elsewhere)
 * gets what a visitor's own request would. A browser never sends cookies with such a read.
 */
final class PublicFiles
{
    /** By extension; anything else is `application/octet-stream`. */
    private const TYPES = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json', 'map' => 'application/json', 'webmanifest' => 'application/manifest+json',
        'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8', 'xml' => 'application/xml', 'csv' => 'text/csv; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'wasm' => 'application/wasm', 'pdf' => 'application/pdf', 'zip' => 'application/zip', 'gz' => 'application/gzip',
    ];

    /** `/.well-known/` names WordPress plugins and hosts answer: TypedPress Identity's discovery, the host's. */
    private const WELL_KNOWN = ['openid-configuration', 'oauth-authorization-server', 'jwks.json', 'spacefast-runtime', 'stattic-runtime'];

    /** Never served, whatever their place in `public/`: code WordPress would run, and server config. */
    private const REFUSED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'htaccess', 'htpasswd', 'ini', 'user.ini'];

    public static function register(Plugin $plugin): void
    {
        if (!is_dir($plugin->dir . '/public')) {
            return;
        }
        // After the facade (0) and the app router's handlers (0), before the Markdown twins (1):
        // a file the app ships wins over a twin of the same name, and never over TypedPress's own paths.
        add_filter('do_parse_request', static function (bool $parse) use ($plugin): bool {
            if ($parse) {
                self::serve($plugin);
            }

            return $parse;
        }, 0);
    }

    private static function serve(Plugin $plugin): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD' || MarkdownTwins::negotiating()) {
            return;
        }
        $file = self::file($plugin->dir . '/public', $plugin->schema->reserved());
        if ($file === null || self::page($plugin)) {
            return;
        }
        $etag = '"' . dechex((int) filesize($file)) . '-' . dechex((int) filemtime($file)) . '"';
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $headers = [
            'Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
        ];
        if ($extension === 'svg' || $extension === 'xml') {
            // An SVG or XML document opened on its own runs no script.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
        }
        // WordPress slashes $_SERVER (wp_magic_quotes), so an ETag's quotes arrive escaped.
        if (self::matches(wp_unslash((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), $etag)) {
            self::emit(304, $headers, null);
        }
        $headers['Content-Length'] = (string) filesize($file);
        self::emit(200, $headers, $method === 'HEAD' ? null : $file);
    }

    /** Whether a `tp/app/` page or route serves the request's path: the app's code wins over a file. */
    private static function page(Plugin $plugin): bool
    {
        $app = $plugin->schema->app();
        if (!is_array($app)) {
            return false;
        }
        $path = (string) (wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        // As Next.js orders them: a page at a literal path, then a file, then a dynamic route
        // (`[slug]`, `[...path]`), which would otherwise take every file below it.
        foreach ($app['routes'] ?? [] as $route) {
            $pattern = (string) ($route['pattern'] ?? '');
            if (!str_contains($pattern, ':') && !str_contains($pattern, '*') && AppRouter::match([$route], $path) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file in `$root` the request's path names, or null. The path is decoded once; a segment
     * that is empty, `.`, `..`, a dotfile or has a NUL is refused, and the result must resolve
     * (links followed) to a regular file under the resolved root.
     *
     * @param list<string> $reserved first path segments WordPress or TypedPress owns
     * @param ?string $relative a path below the home URL to ask about, instead of the request's
     */
    public static function file(string $root, array $reserved, ?string $relative = null): ?string
    {
        // WordPress slashes $_SERVER (wp_magic_quotes): `it's.png` arrives as `it\'s.png`.
        $path = $relative ?? (string) (wp_parse_url(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '/')), PHP_URL_PATH) ?? '/');
        $base = $relative === null ? rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/') : '';
        if ($base !== '') {
            if ($path !== $base && !str_starts_with($path, $base . '/')) {
                return null;
            }
            $path = substr($path, strlen($base)) ?: '/';
        }
        $index = str_ends_with($path, '/');
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
                return null;
            }
            $segment = rawurldecode($raw);
            // A dotfile or dot-folder stays private (`.env`, `.git`), except `/.well-known/` (RFC 8615).
            $dot = str_starts_with($segment, '.') && !($segments === [] && $segment === '.well-known');
            // A name Windows would read as another (`run.php.`, `run.php `, `run.php::$DATA`) is refused too.
            if ($segment === '.' || $segment === '..' || $dot || str_contains($segment, "\0") || str_contains($segment, '/') || str_contains($segment, '\\')
                || str_contains($segment, ':') || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                return null;
            }
            $segments[] = $segment;
        }
        // The home page is WordPress's (its theme, or the app's `page.tsx`), never a file: a
        // `public/index.html` answers at `/index.html`.
        if ($segments === []) {
            return null;
        }
        $first = strtolower($segments[0] ?? '');
        if ($first !== '.well-known' && (in_array($first, $reserved, true) || str_starts_with($first, 'wp-'))) {
            return null;
        }
        // Under /.well-known/, what WordPress plugins and the host answer themselves stays theirs.
        if ($first === '.well-known' && in_array(strtolower($segments[1] ?? ''), self::WELL_KNOWN, true)) {
            return null;
        }
        if ($index) {
            $segments[] = 'index.html';
        }
        $name = strtolower((string) end($segments));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, self::REFUSED, true) || in_array($name, self::REFUSED, true)) {
            return null;
        }
        $real = realpath($root . '/' . implode('/', $segments));
        $top = realpath($root);
        if ($real === false || $top === false || !str_starts_with($real, $top . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }
        // The file it resolved to is judged as well: a link or a name the filesystem read
        // differently must not serve PHP or a dotfile.
        $resolved = strtolower(basename($real));
        if (in_array(strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), self::REFUSED, true) || in_array($resolved, self::REFUSED, true)) {
            return null;
        }
        foreach (array_slice(explode(DIRECTORY_SEPARATOR, substr($real, strlen($top) + 1)), $first === '.well-known' ? 1 : 0) as $part) {
            if (str_starts_with($part, '.')) {
                return null;
            }
        }

        return $real;
    }

    /** Whether the app's `public/` folder serves a file at $path (below the home URL), as it would for a request. */
    public static function serves(Plugin $plugin, string $path): bool
    {
        return is_dir($plugin->dir . '/public') && self::file($plugin->dir . '/public', $plugin->schema->reserved(), $path) !== null;
    }

    /** Whether `If-None-Match` names this ETag: `*`, a list, weak forms (`W/"…"`) included. */
    private static function matches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if ($tag === '*' || (str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag) === $etag) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $headers */
    private static function emit(int $status, array $headers, ?string $file): never
    {
        // A file isn't a page: no page cache keeps it, and no output buffer holds it whole.
        if (function_exists('batcache_cancel')) {
            batcache_cancel();
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header($status);
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        if ($file !== null) {
            readfile($file);
        }
        exit;
    }
}
