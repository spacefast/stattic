<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Validator;

/**
 * The project's `public/` folder, stored under `wp-content/` and symlinked into the document root
 * (`sp_public_root`, default `ABSPATH`, skipped on multisite or read-only roots) so the web server
 * serves direct root requests (e.g. `/favicon.ico`, `/logo.svg`, `/files/blog.json`) straight off
 * disk without booting PHP:
 * - Static assets imported from TSX/TS or referenced by relative CSS `url(...)` live under
 *   `wp-content/.../sp/app/assets/<stem>-<hash>.<ext>` (immutable, 1-year cache).
 * - `public/` holds untouched root-URL files symlinked into the document root pointing into
 *   `wp-content/`. `metadata.scripts` appends `?spv=<hash>` from `sp/app/public-files.json`.
 * - PHP answers on `do_parse_request` for what is not linked: `/.well-known/*` (so it works even on
 *   hosts with a read-only document root), a child whose parent directory shadows a literal app
 *   route, and the top-level `index.html` at `/index.html` (never linked, so `/` stays WordPress's
 *   home page under the web server's DirectoryIndex).
 */
final class PublicFiles
{
    public const MANIFEST = 'sp/app/public-files.json';
    public const SYMLINKS_OPTION = 'sp_public_symlinks';

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

    /** `/.well-known/` names WordPress plugins and hosts answer: SpacePress Identity's discovery, the host's. */
    private const WELL_KNOWN = ['openid-configuration', 'oauth-authorization-server', 'jwks.json', 'spacefast-runtime', 'stattic-runtime'];

    /** Never served or linked, whatever their place in `public/`: code WordPress would run, and server config. */
    private const REFUSED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'htaccess', 'htpasswd', 'ini', 'user.ini'];

    public static function register(Plugin $plugin): void
    {
        if (is_dir($plugin->dir . '/public/.well-known') || self::manifest($plugin) !== []) {
            // After the facade (0) and the app router's handlers (0), before the Markdown twins (1):
            // serves `/.well-known/*` and any `public/` child whose parent directory would shadow a
            // literal app route (kept out of the document root so Apache/nginx still routes the page).
            add_filter('do_parse_request', static function (bool $parse) use ($plugin): bool {
                if ($parse) {
                    self::serveFallback($plugin);
                }

                return $parse;
            }, 0);
        }
        // Sync root symlinks whenever the public manifest, source, root, or literal routes change.
        // Only live builds (never unactivated managed candidates or inspect runs) sync on `init`.
        if (!Runtime::inspecting()) {
            add_action('init', static function () use ($plugin): void {
                if (self::isLiveBuild()) {
                    self::maybeSyncSymlinks($plugin);
                }
            }, 1);
        }
    }

    /** Whether the currently loaded build is live (not an inspect run or an unactivated managed candidate). */
    private static function isLiveBuild(): bool
    {
        if (Runtime::inspecting()) {
            return false;
        }
        if (!Runtime::managed()) {
            return true;
        }
        if (class_exists(\SpacePressPlugin\Mount::class, false)) {
            $mounted = \SpacePressPlugin\Mount::mounted();
            if (is_array($mounted)) {
                return ($mounted['mode'] ?? null) === 'live';
            }
        }
        $loaded = class_exists(\SpacePress\Loader\Loader::class, false) ? \SpacePress\Loader\Loader::loaded() : null;
        if (is_array($loaded) && is_string($loaded['root'] ?? null) && is_string($loaded['build'] ?? null)) {
            return is_file($loaded['root'] . '/ready/' . $loaded['build'] . '.json');
        }

        return false;
    }

    /** @return array<string, string> relative path below `public/` => 12-hex content version */
    public static function manifest(Plugin $plugin): array
    {
        return self::manifestForDir($plugin->dir);
    }

    /** @return array<string, string> */
    private static function manifestForDir(string $dir): array
    {
        static $parsed = [];
        if (!isset($parsed[$dir])) {
            $raw = self::manifestRaw($dir);
            $map = $raw === '' ? null : json_decode($raw, true);
            $parsed[$dir] = is_array($map) ? array_filter($map, static fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH) : [];
        }

        return $parsed[$dir];
    }

    public static function manifestHash(Plugin $plugin): ?string
    {
        $raw = self::manifestRaw($plugin->dir);

        return $raw === '' ? null : hash('sha256', $raw);
    }

    private static function manifestRaw(string $dir): string
    {
        static $maps = [];
        if (!isset($maps[$dir])) {
            // Read as data: a loose deploy that preserves mtime must not leave old opcodes in use.
            $file = $dir . '/' . self::MANIFEST;
            $maps[$dir] = is_file($file) ? (string) @file_get_contents($file) : '';
        }

        return $maps[$dir];
    }

    /** Content version (12-hex SHA-256) of a JavaScript file in `public/`, or null. */
    public static function scriptVersion(Plugin $plugin, string $relative): ?string
    {
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if ($ext !== 'js' && $ext !== 'mjs') {
            return null;
        }
        $manifest = self::manifest($plugin);

        return $manifest[$relative] ?? null;
    }

    /** Whether the app's `public/` folder serves a file at `$path` (below the home URL). */
    public static function serves(Plugin $plugin, string $path): bool
    {
        $rel = self::relativePath($path, $plugin->schema->reserved());
        if ($rel !== null && isset(self::manifest($plugin)[$rel])) {
            return true;
        }

        return is_dir($plugin->dir . '/public/.well-known')
            && self::wellKnownFile($plugin->dir . '/public', $path) !== null;
    }

    /**
     * The `wp-content/` directory holding `$plugin`'s public files on disk, or null when a managed
     * build has no public assets mirror configured (never expose a private build store via symlinks).
     */
    public static function publicDir(Plugin $plugin): ?string
    {
        if (Runtime::managed()) {
            $loaded = class_exists(\SpacePress\Loader\Loader::class, false) ? \SpacePress\Loader\Loader::loaded() : null;
            $assetsDir = is_array($loaded) && is_string($loaded['assetsDir'] ?? null) && $loaded['assetsDir'] !== ''
                ? rtrim($loaded['assetsDir'], '/\\')
                : null;
            $manifest = is_array($loaded['manifest'] ?? null) ? $loaded['manifest'] : null;
            if ($assetsDir === null || $manifest === null) {
                return null;
            }

            return $assetsDir . '/app/' . $manifest['app'] . '/plugins/spacepress-app-' . $manifest['name'] . '/public';
        }

        return $plugin->dir . '/public';
    }

    /** Sync root symlinks when the manifest, source, root, or literal routes changed (or removals are pending). */
    private static function maybeSyncSymlinks(Plugin $plugin): void
    {
        // No public files and no option recorded: nothing to link or clean up. The option is always
        // written autoloaded, so its absence is known from the autoloaded options without a query
        // (get_option() on a missing option costs a SELECT on every request without a persistent cache).
        if (self::manifest($plugin) === [] && !array_key_exists(self::SYMLINKS_OPTION, wp_load_alloptions())) {
            return;
        }
        $root = self::documentRoot();
        if ($root === null) {
            return;
        }
        $publicDir = self::publicDir($plugin);
        $source = $publicDir !== null ? (realpath($publicDir) ?: '') : '';
        $hash = self::manifestHash($plugin) ?? '';
        $routes = self::routesFingerprint($plugin->schema);
        $stored = get_option(self::SYMLINKS_OPTION);
        if (is_array($stored)) {
            if (($stored['root'] ?? null) === $root
                && ($stored['source'] ?? null) === $source
                && ($stored['hash'] ?? null) === $hash
                && ($stored['routes'] ?? null) === $routes
                && empty($stored['pending'])) {
                return;
            }
        }
        self::syncSymlinks($plugin);
    }

    /** Fingerprint of reserved paths and literal app routes that affect symlink eligibility. */
    private static function routesFingerprint(\SpacePress\Schema $schema): string
    {
        $reserved = $schema->reserved();
        sort($reserved);
        $literals = [];
        $app = $schema->app();
        if (is_array($app)) {
            foreach ($app['routes'] ?? [] as $route) {
                $pattern = (string) ($route['pattern'] ?? '');
                if ($pattern !== '' && !str_contains($pattern, ':') && !str_contains($pattern, '*')) {
                    $literals[] = $pattern;
                }
            }
        }
        sort($literals);

        return hash('sha256', json_encode(['reserved' => $reserved, 'literals' => $literals], JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * Sync symlinks in the document root (`sp_public_root`, default `ABSPATH`) pointing to the app's
     * `public/` files under `wp-content/`, so direct requests like `/logo.svg` or `/files/blog.json`
     * are served directly by the web server without booting PHP. Skipped on multisite or when the
     * document root is not writable. Never overwrites existing non-symlink files, never links
     * top-level `index.html`, never creates directories that shadow literal app routes, and cleans
     * up stale symlinks from previous builds.
     */
    public static function syncSymlinks(Plugin $plugin): void
    {
        $publicDir = self::publicDir($plugin);
        if ($publicDir === null) {
            return;
        }
        self::syncSymlinksForDir($plugin->dir, $publicDir, $plugin->schema);
    }

    public static function syncSymlinksForDir(string $appDir, string $publicDir, \SpacePress\Schema $schema): void
    {
        $root = self::documentRoot();
        if ($root === null) {
            return;
        }
        $stored = get_option(self::SYMLINKS_OPTION);
        $prevRoot = is_array($stored) && is_string($stored['root'] ?? null) ? rtrim($stored['root'], '/\\') : $root;
        $prevLinks = is_array($stored) && is_array($stored['links'] ?? null) ? array_values(array_filter($stored['links'], 'is_string')) : [];
        $prevDirs = is_array($stored) && is_array($stored['dirs'] ?? null) ? array_values(array_filter($stored['dirs'], 'is_string')) : [];
        $prevStale = is_array($stored) && is_array($stored['stale'] ?? null) ? $stored['stale'] : [];
        $prevLinkSet = array_fill_keys($prevLinks, true);

        $sourceTop = is_dir($publicDir) ? realpath($publicDir) : false;
        $manifest = self::manifestForDir($appDir);
        $raw = self::manifestRaw($appDir);
        $hash = $raw === '' ? '' : hash('sha256', $raw);
        $routes = self::routesFingerprint($schema);
        $reserved = $schema->reserved();

        $desired = [];
        if ($sourceTop !== false && $manifest !== []) {
            foreach (array_keys($manifest) as $rel) {
                // Never symlink top-level index.html/htm: Apache/nginx DirectoryIndex would serve it for `/`.
                $lower = strtolower($rel);
                if ($lower === 'index.html' || $lower === 'index.htm') {
                    continue;
                }
                if (self::relativePath('/' . $rel, $reserved) !== $rel) {
                    continue;
                }
                $pagePath = '/' . (str_ends_with($lower, '/index.html') ? substr($rel, 0, -strlen('/index.html')) : $rel);
                if (self::literalPage($schema, $pagePath) || self::parentShadowsLiteralPage($schema, $rel)) {
                    continue;
                }
                $real = realpath($sourceTop . '/' . $rel);
                if ($real === false || !str_starts_with($real, $sourceTop . DIRECTORY_SEPARATOR) || !is_file($real)) {
                    continue;
                }
                if (self::isRefusedName(basename($real))) {
                    continue;
                }
                $dot = false;
                foreach (explode(DIRECTORY_SEPARATOR, substr($real, strlen($sourceTop) + 1)) as $part) {
                    if (str_starts_with($part, '.')) {
                        $dot = true;
                        break;
                    }
                }
                if (!$dot) {
                    $desired[$rel] = $real;
                }
            }
        }

        // Retry any previously failed removals from earlier roots first.
        $stale = self::retryStale($prevStale);

        // Remove symlinks from previous builds that are no longer desired (or moved root).
        // Keep any link whose removal fails (with its original root) so later syncs or deactivation retry it.
        $pendingLinks = [];
        foreach ($prevLinks as $old) {
            if ($prevRoot !== $root || !isset($desired[$old])) {
                $path = $prevRoot . '/' . $old;
                if (is_link($path) && !@unlink($path) && is_link($path)) {
                    error_log("SpacePress: could not remove stale public symlink $path");
                    if ($prevRoot === $root) {
                        $pendingLinks[] = $old;
                    } else {
                        $stale[] = ['root' => $prevRoot, 'path' => $old, 'dir' => false];
                    }
                }
            }
        }

        $createdLinks = [];
        $ownedDirs = $prevRoot === $root ? array_fill_keys($prevDirs, true) : [];
        foreach ($desired as $rel => $target) {
            $dest = $root . '/' . $rel;
            $dirOk = true;
            $segments = explode('/', $rel);
            array_pop($segments);
            $cur = '';
            foreach ($segments as $seg) {
                $cur = $cur === '' ? $seg : $cur . '/' . $seg;
                $dirPath = $root . '/' . $cur;
                if (is_link($dirPath) || (file_exists($dirPath) && !is_dir($dirPath))) {
                    $dirOk = false;
                    break;
                }
                if (!is_dir($dirPath)) {
                    if (!@mkdir($dirPath, 0755)) {
                        $dirOk = false;
                        break;
                    }
                    $ownedDirs[$cur] = true;
                }
            }
            if (!$dirOk) {
                continue;
            }
            if (is_link($dest)) {
                if (@readlink($dest) === $target) {
                    $createdLinks[] = $rel;
                    continue;
                }
                if ($prevRoot === $root && isset($prevLinkSet[$rel])) {
                    if (@unlink($dest) || !is_link($dest)) {
                        if (@symlink($target, $dest)) {
                            $createdLinks[] = $rel;
                        }
                    } else {
                        error_log("SpacePress: could not replace public symlink $dest");
                        $pendingLinks[] = $rel;
                    }
                }
                continue;
            }
            if (file_exists($dest)) {
                // Never overwrite a real file or directory already in the document root.
                continue;
            }
            if (@symlink($target, $dest)) {
                $createdLinks[] = $rel;
            }
        }

        // Retain ownership of any failed removals under the current root so they are retried.
        $recordedLinks = $createdLinks;
        if ($prevRoot === $root && $pendingLinks !== []) {
            $recordedLinks = array_values(array_unique(array_merge($recordedLinks, $pendingLinks)));
        }

        // Prune empty directories SpacePress previously created that no remaining symlink uses.
        $neededDirs = [];
        foreach ($recordedLinks as $rel) {
            $dir = dirname($rel);
            while ($dir !== '.' && $dir !== '') {
                $neededDirs[$dir] = true;
                $dir = dirname($dir);
            }
        }
        $pendingDirs = false;
        $pruneList = $prevRoot === $root ? array_keys($ownedDirs) : $prevDirs;
        usort($pruneList, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/') ?: strlen($b) <=> strlen($a));
        foreach ($pruneList as $dir) {
            if ($prevRoot !== $root || !isset($neededDirs[$dir])) {
                $dirPath = $prevRoot . '/' . $dir;
                if (!is_dir($dirPath) || @rmdir($dirPath) || !is_dir($dirPath)) {
                    unset($ownedDirs[$dir]);
                } else {
                    error_log("SpacePress: could not remove stale public directory $dirPath");
                    $pendingDirs = true;
                    if ($prevRoot !== $root) {
                        $stale[] = ['root' => $prevRoot, 'path' => $dir, 'dir' => true];
                    }
                }
            }
        }
        $keptDirs = array_values(array_keys($ownedDirs));
        $hasPending = $pendingLinks !== [] || $pendingDirs || $stale !== [];

        // An app with no public files and nothing linked, owned or pending records nothing (its `{}`
        // manifest still has a hash), so activation leaves what initialize leaves: no option.
        if ($recordedLinks === [] && $keptDirs === [] && $manifest === [] && !$hasPending) {
            delete_option(self::SYMLINKS_OPTION);
        } else {
            update_option(self::SYMLINKS_OPTION, [
                'root' => $root,
                'source' => $sourceTop ?: '',
                'hash' => $hash,
                'routes' => $routes,
                'links' => $recordedLinks,
                'dirs' => $keptDirs,
                'stale' => $stale,
                'pending' => $hasPending,
            ], true);
        }
    }

    /** Remove every symlink and empty directory SpacePress created in the document root. */
    public static function removeSymlinks(): void
    {
        $stored = get_option(self::SYMLINKS_OPTION);
        if (!is_array($stored) || !is_string($stored['root'] ?? null) || $stored['root'] === '') {
            delete_option(self::SYMLINKS_OPTION);

            return;
        }
        $root = rtrim($stored['root'], '/\\');
        $stale = self::retryStale(is_array($stored['stale'] ?? null) ? $stored['stale'] : []);
        $remainingLinks = [];
        foreach (is_array($stored['links'] ?? null) ? $stored['links'] : [] as $rel) {
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $path = $root . '/' . $rel;
            if (is_link($path) && !@unlink($path) && is_link($path)) {
                error_log("SpacePress: could not remove public symlink $path during cleanup");
                $remainingLinks[] = $rel;
            }
        }
        $dirs = is_array($stored['dirs'] ?? null) ? array_values(array_filter($stored['dirs'], 'is_string')) : [];
        usort($dirs, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/') ?: strlen($b) <=> strlen($a));
        $remainingDirs = [];
        foreach ($dirs as $dir) {
            if ($dir === '') {
                continue;
            }
            $dirPath = $root . '/' . $dir;
            if (is_dir($dirPath) && !@rmdir($dirPath) && is_dir($dirPath)) {
                error_log("SpacePress: could not remove public directory $dirPath during cleanup");
                $remainingDirs[] = $dir;
            }
        }
        if ($remainingLinks === [] && $remainingDirs === [] && $stale === []) {
            delete_option(self::SYMLINKS_OPTION);
        } else {
            update_option(self::SYMLINKS_OPTION, [
                'root' => $root,
                'source' => '',
                'hash' => '',
                'routes' => '',
                'links' => $remainingLinks,
                'dirs' => $remainingDirs,
                'stale' => $stale,
                'pending' => true,
            ], true);
        }
    }

    /**
     * Retry removals that previously failed under an earlier document root, returning any still present.
     *
     * @param list<mixed> $entries
     * @return list<array{root: string, path: string, dir: bool}>
     */
    private static function retryStale(array $entries): array
    {
        $links = [];
        $dirs = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['root'] ?? null) || !is_string($entry['path'] ?? null) || $entry['root'] === '' || $entry['path'] === '') {
                continue;
            }
            $item = ['root' => rtrim($entry['root'], '/\\'), 'path' => $entry['path'], 'dir' => !empty($entry['dir'])];
            if ($item['dir']) {
                $dirs[] = $item;
            } else {
                $links[] = $item;
            }
        }
        $remaining = [];
        foreach ($links as $item) {
            $full = $item['root'] . '/' . $item['path'];
            if (is_link($full) && !@unlink($full) && is_link($full)) {
                error_log("SpacePress: could not remove stale public symlink $full");
                $remaining[] = $item;
            }
        }
        usort($dirs, static fn (array $a, array $b): int => substr_count($b['path'], '/') <=> substr_count($a['path'], '/') ?: strlen($b['path']) <=> strlen($a['path']));
        foreach ($dirs as $item) {
            $full = $item['root'] . '/' . $item['path'];
            if (is_dir($full) && !@rmdir($full) && is_dir($full)) {
                error_log("SpacePress: could not remove stale public directory $full");
                $remaining[] = $item;
            }
        }

        return $remaining;
    }

    /** Writable document root for root symlinks, or null on multisite / read-only hosts. */
    private static function documentRoot(): ?string
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return null;
        }
        $default = rtrim(ABSPATH, '/\\');
        $root = function_exists('apply_filters') ? apply_filters('sp_public_root', $default) : $default;
        if (!is_string($root) || $root === '') {
            return null;
        }
        $root = rtrim($root, '/\\');
        if (!is_dir($root) && $root !== $default) {
            @mkdir($root, 0755, true);
        }

        return is_dir($root) && is_writable($root) ? $root : null;
    }

    /**
     * The `public/` file PHP answers this request with (one the document root has no link for:
     * `/.well-known/*`, a route-shadowed child, the top-level `index.html`), or null.
     */
    public static function fallbackFile(Plugin $plugin): ?string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD' || MarkdownTwins::negotiating()) {
            return null;
        }
        $reqPath = self::requestPath();
        if ($reqPath === null || self::literalPage($plugin->schema, $reqPath)) {
            return null;
        }

        return self::wellKnownFile($plugin->dir . '/public', $reqPath) ?? self::unlinkedPublicFile($plugin, $reqPath);
    }

    /** Serve `/.well-known/*`, route-shadowed `public/` children and `/index.html` via PHP. */
    private static function serveFallback(Plugin $plugin): void
    {
        $file = self::fallbackFile($plugin);
        if ($file === null) {
            return;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $size = (int) filesize($file);
        $etag = '"' . dechex($size) . '-' . dechex((int) filemtime($file)) . '"';
        $headers = [
            'Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
        ];
        if ($extension === 'svg' || $extension === 'xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
        }
        if (Validator::matches($etag)) {
            self::emit(304, $headers, null);
        }
        $headers['Content-Length'] = (string) $size;
        self::emit(200, $headers, $method === 'HEAD' ? null : $file);
    }

    /**
     * Resolves a `public/` file the document root has no link for: the top-level `index.html` (or
     * `index.htm`), asked for by name, or a file whose parent directory shadows a literal app route.
     */
    private static function unlinkedPublicFile(Plugin $plugin, string $path): ?string
    {
        $rel = self::relativePath($path, $plugin->schema->reserved());
        if ($rel === null || !isset(self::manifest($plugin)[$rel])) {
            return null;
        }
        // `/` stays WordPress's home page: relativePath() gives no name for it, so only a request
        // that names the file (`/index.html`) reaches it.
        $rootIndex = in_array(strtolower($rel), ['index.html', 'index.htm'], true) && !str_ends_with($path, '/');
        if (!$rootIndex && !self::parentShadowsLiteralPage($plugin->schema, $rel)) {
            return null;
        }
        $publicDir = self::publicDir($plugin) ?? ($plugin->dir . '/public');
        $top = is_dir($publicDir) ? realpath($publicDir) : false;
        if ($top === false) {
            return null;
        }
        $real = realpath($top . '/' . $rel);
        if ($real === false || !str_starts_with($real, $top . DIRECTORY_SEPARATOR) || !is_file($real) || self::isRefusedName(basename($real))) {
            return null;
        }
        foreach (explode(DIRECTORY_SEPARATOR, substr($real, strlen($top) + 1)) as $part) {
            if (str_starts_with($part, '.')) {
                return null;
            }
        }

        return $real;
    }

    /** Whether any parent directory prefix of `$rel` (e.g. `docs` for `docs/guide.pdf`) matches a literal app route. */
    private static function parentShadowsLiteralPage(\SpacePress\Schema $schema, string $rel): bool
    {
        $segments = explode('/', $rel);
        array_pop($segments);
        $cur = '';
        foreach ($segments as $seg) {
            $cur = $cur === '' ? $seg : $cur . '/' . $seg;
            if (self::literalPage($schema, '/' . $cur)) {
                return true;
            }
        }

        return false;
    }

    /** Whether any dot-separated part (or full basename) of `$name` is a refused executable/config extension. */
    private static function isRefusedName(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, self::REFUSED, true)) {
            return true;
        }
        foreach (explode('.', $lower) as $part) {
            if (in_array($part, self::REFUSED, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a `/.well-known/*` path to a regular file strictly inside `$root/.well-known`, or
     * null for any other path, reserved `.well-known` name, dotfile, refused extension, or symlink
     * escaping `$root/.well-known`.
     */
    private static function wellKnownFile(string $root, ?string $relative = null): ?string
    {
        $path = $relative ?? self::requestPath();
        if ($path === null || !str_starts_with($path, '/.well-known/')) {
            return null;
        }
        $index = str_ends_with($path, '/');
        $segments = self::decodeSegments($path, allowWellKnown: true);
        if ($segments === null || count($segments) < 2 || strtolower($segments[0]) !== '.well-known') {
            return null;
        }
        if (in_array(strtolower($segments[1]), self::WELL_KNOWN, true)) {
            return null;
        }
        if ($index) {
            $segments[] = 'index.html';
        }
        $name = (string) end($segments);
        if (self::isRefusedName($name)) {
            return null;
        }
        $top = realpath($root . '/.well-known');
        $real = realpath($root . '/' . implode('/', $segments));
        if ($real === false || $top === false || !str_starts_with($real, $top . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }
        if (self::isRefusedName(basename($real))) {
            return null;
        }
        foreach (explode(DIRECTORY_SEPARATOR, substr($real, strlen($top) + 1)) as $part) {
            if (str_starts_with($part, '.')) {
                return null;
            }
        }

        return $real;
    }

    /** Request path below `home_url('/')`, or null if outside the site's base path. */
    private static function requestPath(): ?string
    {
        $path = (string) (wp_parse_url(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '/')), PHP_URL_PATH) ?? '/');
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '') {
            if ($path !== $base && !str_starts_with($path, $base . '/')) {
                return null;
            }
            $path = substr($path, strlen($base)) ?: '/';
        }

        return $path;
    }

    /**
     * Normalized relative path below `public/` for a non-well-known URL path, or null when invalid,
     * reserved, dotfile, or refused.
     *
     * @param list<string> $reserved
     */
    private static function relativePath(string $path, array $reserved): ?string
    {
        $index = str_ends_with($path, '/');
        $segments = self::decodeSegments($path, allowWellKnown: false);
        if ($segments === null || $segments === []) {
            return null;
        }
        $first = strtolower($segments[0]);
        if (in_array($first, $reserved, true) || str_starts_with($first, 'wp-')) {
            return null;
        }
        if ($index) {
            $segments[] = 'index.html';
        }
        $name = (string) end($segments);
        if (self::isRefusedName($name)) {
            return null;
        }

        return implode('/', $segments);
    }

    /** @return ?list<string> */
    private static function decodeSegments(string $path, bool $allowWellKnown): ?array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
                return null;
            }
            $segment = rawurldecode($raw);
            $dot = str_starts_with($segment, '.') && !($allowWellKnown && $segments === [] && $segment === '.well-known');
            if ($segment === '.' || $segment === '..' || $dot || str_contains($segment, "\0") || str_contains($segment, '/') || str_contains($segment, '\\')
                || str_contains($segment, ':') || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                return null;
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /** Whether a literal `sp/app/` page or route matches `$path`: the app's code wins over a file. */
    private static function literalPage(\SpacePress\Schema $schema, ?string $path): bool
    {
        $app = $schema->app();
        if (!is_array($app) || $path === null) {
            return false;
        }
        $literalOnly = ['literal' => $app['routeIndex']['literal'], 'dynamic' => []];

        return AppRouter::match($app['routes'], $path, $literalOnly) !== null;
    }

    /** @param array<string, string> $headers */
    private static function emit(int $status, array $headers, ?string $file): never
    {
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
