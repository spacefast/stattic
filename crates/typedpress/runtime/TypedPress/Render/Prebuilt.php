<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\HttpError;
use TypedPress\Js;
use TypedPress\Seam;
use TypedPress\Viewer;
use TypedPress\Wp\AppRouter;
use TypedPress\Wp\KindUrls;
use TypedPress\Wp\MarkdownTwins;
use TypedPress\Wp\Plugin;
use TypedPress\Wp\PublicFiles;
use TypedPress\Wp\WpFiles;

/**
 * Prebuilt `tp/app/` pages (docs/decisions/2026-11-13-static-output.md).
 *
 * A prebuilt page is an anonymous visitor's render through the router, fetched over HTTP with the
 * `X-TypedPress-Prerender` header (an HMAC of the path, keyed by the site's runtime secret). The router
 * answers such a request as a signed-out visitor, past Batcache and the file lane, records what it
 * read, and says which viewer function it called.
 *
 * - `urls()`: what to build, as the site sees it now (literal routes, a kind route's published items,
 *   `staticParams`), with the old URLs of those items that should redirect.
 * - In `output: "server"`, the `tp_prebuild` Action Scheduler action renders URLs into
 *   `wp-content/cache/typedpress-prebuilt/<build>/` and `serve()` answers from there before the request
 *   is parsed. A write that would purge a prebuilt URL deletes its files and schedules the action
 *   for it (on-demand regeneration); until it has run, the URL renders per request. A file is never
 *   served past MAX_AGE, and the action rebuilds every file that often, so what no write announces
 *   (an option, a menu, the time) is never older than that. Site-wide settings drop every file.
 * - The `<build>` directory is named by the app's compiled files, the runtime's content hash, and
 *   the WordPress, theme and plugin versions that write the page's head and assets.
 * - `GET /wp-json/typedpress/v1/prerender` lists the URLs for `typedpress export`, to an editor.
 */
final class Prebuilt
{
    public const HEADER = 'X-TypedPress-Prerender';
    public const REFUSED = 'X-TypedPress-Prerender-Refused';
    /** REFUSED's value for a prerender the page cache's read index couldn't record ({@see unrecorded()}). */
    public const UNRECORDED = 'unrecorded';
    public const ACTION = 'tp_prebuild';
    public const ROUTE = 'prerender';
    /** URLs one action renders; the rest go to a following action. */
    public const BATCH = 50;
    /**
     * A file older than this is not served; the next full build replaces it. What a page shows that
     * no write announces (an option, a menu, another plugin's data, the time) is never older.
     */
    public const MAX_AGE = 3600;
    /** How long a prerender token is valid, in seconds: one build, not forever. */
    public const TOKEN_TTL = 3600;
    /**
     * The cookie the loopback sends: Batcache and edge caches skip any request with a `wordpress_`
     * cookie, so a prerender always reaches PHP and is never answered from a stored copy.
     */
    public const COOKIE = 'wordpress_tp_prerender';
    /** The option holding when a write last named each prebuilt path (`stale()`). */
    private const MARKS = 'tp_prebuilt_stale';
    /** The option holding when `forget()` last dropped every file. */
    private const FORGOTTEN = 'tp_prebuilt_forgotten';

    private static ?Plugin $plugin = null;
    /** @var ?string the viewer function this prerender request called, reported in REFUSED */
    private static ?string $refused = null;

    public static function register(): void
    {
        \TypedPress\Extensions::route(self::ROUTE, self::route(...));
    }

    /** Called by the app plugin as it boots: the file lane, the regeneration action and its triggers. */
    public static function boot(Plugin $plugin): void
    {
        $app = $plugin->schema->app();
        // A build mounted to inspect serves no page and schedules no build of them.
        if (!is_array($app) || !$app['hasPrebuilt'] || \TypedPress\Wp\Runtime::inspecting()) {
            return;
        }
        self::$plugin = $plugin;
        if (self::prerendering()) {
            // The render as any signed-out visitor sees it, whatever cookies came with the request.
            add_filter('determine_current_user', static fn (): int => 0, PHP_INT_MAX);
            if (function_exists('batcache_cancel')) {
                batcache_cancel();
            }
            if (($_SERVER['HTTP_X_TYPEDPRESS_EXPORT'] ?? '') === '1') {
                self::forExport();
            }
            return;
        }
        if (($app['output'] ?? 'server') !== 'server') {
            return;
        }
        add_action('plugins_loaded', self::serve(...), PHP_INT_MAX);
        add_action(self::ACTION, self::build(...), 10, 1);
        // After a build is initialized (first request, migrate, activation) the files are made, and
        // made again every MAX_AGE by the same stock Action Scheduler action.
        add_action('init', static function (): void {
            if (self::$plugin?->unavailable() !== null || !function_exists('as_enqueue_async_action')) {
                return;
            }
            $state = get_option(self::option());
            // Rebuilt at three quarters of MAX_AGE, so a file is replaced before it stops being served.
            $due = !is_array($state) || ($state['state'] === 'built' && $state['at'] + (int) (self::MAX_AGE * 3 / 4) <= time())
                || ($state['state'] === 'failed' && $state['at'] + $state['retry'] <= time())
                // A build that never finished (its action was killed) is tried again after MAX_AGE.
                || ($state['state'] === 'scheduled' && $state['at'] + self::MAX_AGE <= time());
            if ($due && !as_has_scheduled_action(self::ACTION, [[]], 'typedpress')) {
                update_option(self::option(), ['state' => 'scheduled', 'at' => time(), 'retry' => (int) ($state['retry'] ?? 60)], false);
                as_enqueue_async_action(self::ACTION, [[]], 'typedpress');
            }
        }, 20);
        add_action('update_option_active_sitewide_plugins', static fn () => self::forget(), 10, 0);
        add_action('upgrader_process_complete', self::forget(...), 10, 0);
        add_action('activated_plugin', self::forget(...), 10, 0);
        add_action('deactivated_plugin', self::forget(...), 10, 0);
    }

    /** Every prebuilt file goes, and the next request schedules a full build. */
    public static function forget(): void
    {
        if (self::$plugin === null) {
            return;
        }
        // Marked before the files go: a render already running in another process writes nothing,
        // and a full build that began before now records nothing (write(), build()).
        update_option(self::FORGOTTEN, microtime(true), false);
        if (is_dir(self::dir())) {
            self::remove(self::dir());
        }
        delete_option(self::option());
    }

    /** Prebuilt files are served here and kept current: the build is `output: "server"` with a prebuilt page. */
    public static function active(): bool
    {
        return self::$plugin !== null && !self::prerendering() && ((self::$plugin->schema->app()['output'] ?? 'server') === 'server');
    }

    /**
     * `typedpress export`: WordPress's head links to what a static site doesn't serve (feeds, the REST
     * API, RSD, oEmbed, the shortlink, the generator, a DNS prefetch of the site's own host) are
     * left out of the page.
     */
    private static function forExport(): void
    {
        add_action('wp_head', static function (): void {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_shortlink_wp_head', 10);
            remove_action('wp_head', 'wp_generator');
        }, 0);
        add_filter('wp_resource_hints', static fn (array $urls, string $type): array => $type === 'dns-prefetch' ? [] : $urls, 10, 2);
        add_filter('wp_speculation_rules_configuration', '__return_null');
    }

    /** The option that says this build's files have been scheduled. */
    private static function option(): string
    {
        return 'tp_prebuilt_' . substr(self::build_id(), 0, 16);
    }

    private const COMPILED_FILES = ['schema.php', 'functions.php', 'content.php'];

    /**
     * What a prebuilt file was rendered from: the app's compiled schema, page functions and content
     * pages, and the runtime that rendered them. A deploy that changes any of them starts an empty
     * directory, so no file from another build is ever served.
     */
    private static function build_id(): string
    {
        static $id = null;
        if ($id !== null || self::$plugin === null) {
            return (string) $id;
        }
        // The store has already hashed managed app and runtime trees. A loose app must inspect its
        // files. Hash the small script map even on a cache hit: an asset-only rebuild can replace
        // it with another map of the same size in the same second.
        $manifest = self::$plugin->mountedManifest();
        $trees = $manifest === null ? null : ['app' => $manifest['app'], 'runtime' => $manifest['runtime']];
        $key = [$trees ?? (defined('TP_RUNTIME_HASH') ? TP_RUNTIME_HASH : ''), get_bloginfo('version'), get_option('stylesheet'),
            get_option('active_plugins'), get_site_option('active_sitewide_plugins'), wp_get_mu_plugins()];
        $mapHash = '-';
        if ($trees === null) {
            $root = self::$plugin->dir . '/tp/app/';
            foreach (self::COMPILED_FILES as $file) {
                $key[] = [@filesize($root . $file), @filemtime($root . $file)];
            }
            $mapHash = PublicFiles::manifestHash(self::$plugin) ?? '-';
            $key[] = $mapHash;
        }
        $key = md5((string) wp_json_encode($key));
        $stored = get_option('tp_prebuilt_id');
        if (is_array($stored) && ($stored['key'] ?? null) === $key && is_string($stored['id'] ?? null)) {
            return $id = $stored['id'];
        }
        $id = self::computeId($trees, $mapHash);
        update_option('tp_prebuilt_id', ['key' => $key, 'id' => $id], true);
        return $id;
    }

    /** @param ?array{app: string, runtime: string} $trees */
    private static function computeId(?array $trees, string $mapHash): string
    {
        $hash = hash_init('sha256');
        if ($trees !== null) {
            hash_update($hash, 'app:' . $trees['app'] . "\nruntime:" . $trees['runtime'] . "\n");
        } else {
            foreach (self::COMPILED_FILES as $file) {
                $path = self::$plugin->dir . '/tp/app/' . $file;
                hash_update($hash, $file . ':' . (is_file($path) ? (string) hash_file('sha256', $path) : '-') . "\n");
            }
            hash_update($hash, 'public-files:' . $mapHash . "\n");
            hash_update($hash, 'runtime:' . (defined('TP_RUNTIME_HASH') ? TP_RUNTIME_HASH : \TypedPress\Wp\Runtime::VERSION) . "\n");
        }
        // WordPress, the theme and the active plugins write the document's head and assets.
        hash_update($hash, 'wp:' . get_bloginfo('version') . ':' . get_option('stylesheet') . ':' . (string) wp_get_theme()->get('Version') . "\n");
        // Network-activated plugins, must-use plugins and drop-ins write page heads too.
        $plugins = [...(array) get_option('active_plugins', []), ...array_keys((array) get_site_option('active_sitewide_plugins', []))];
        $plugins = array_values(array_unique($plugins));
        sort($plugins);
        foreach (wp_get_mu_plugins() as $mu) {
            hash_update($hash, 'mu:' . basename($mu) . ':' . (string) @hash_file('sha256', $mu) . "\n");
        }
        foreach (['advanced-cache.php', 'object-cache.php', 'db.php'] as $dropin) {
            if (is_file(WP_CONTENT_DIR . '/' . $dropin)) {
                hash_update($hash, 'dropin:' . $dropin . ':' . (string) @filemtime(WP_CONTENT_DIR . '/' . $dropin) . "\n");
            }
        }
        foreach ($plugins as $plugin) {
            $data = function_exists('get_file_data') && is_file(WP_PLUGIN_DIR . '/' . $plugin) ? get_file_data(WP_PLUGIN_DIR . '/' . $plugin, ['v' => 'Version']) : ['v' => ''];
            hash_update($hash, 'plugin:' . $plugin . ':' . $data['v'] . "\n");
        }
        return hash_final($hash);
    }

    // ------------------------------------------------------------------ the prerender request

    /**
     * The header value that proves a request comes from this site's own prebuild or `typedpress export`:
     * `<expiry>.<HMAC of path and expiry>`, valid for TOKEN_TTL.
     */
    public static function token(string $path, ?int $expires = null): string
    {
        $expires ??= time() + self::TOKEN_TTL;
        return $expires . '.' . hash_hmac('sha256', 'typedpress-prerender:' . $expires . ':' . $path, WpFiles::ensureSecret());
    }

    /** This request carries a valid, unexpired prerender header for its own path. */
    public static function prerendering(): bool
    {
        static $answer = null;
        if ($answer !== null) {
            return $answer;
        }
        $given = $_SERVER['HTTP_X_TYPEDPRESS_PRERENDER'] ?? null;
        if (!is_string($given) || preg_match('/\A(\d{1,12})\.[0-9a-f]{64}\z/', $given, $m) !== 1 || !function_exists('get_option')) {
            return $answer = false;
        }
        $expires = (int) $m[1];
        if ($expires < time() || $expires > time() + self::TOKEN_TTL + 60) {
            return $answer = false;
        }
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        return $answer = hash_equals(self::token($path, $expires), $given);
    }

    /** A viewer function ran while prerendering: the page can't be one file for everyone. */
    public static function viewerCall(string $function): void
    {
        if (self::prerendering()) {
            self::refuse($function);
        }
    }

    /**
     * The read index couldn't record this prerender (Cache::record said no, as for an address longer
     * than the index holds), so no write would mark its file stale. In `output: "server"` no file is
     * written and the URL renders per request; a static export keeps no index, so it is not refused.
     */
    public static function unrecorded(): void
    {
        if (self::prerendering() && ((self::$plugin?->schema->app()['output'] ?? 'server') === 'server')) {
            self::refuse(self::UNRECORDED);
        }
    }

    private static function refuse(string $why): void
    {
        if (self::$refused === null) {
            self::$refused = $why;
            if (!headers_sent()) {
                header(self::REFUSED . ': ' . $why);
            }
        }
    }

    // ------------------------------------------------------------------ what to build

    /**
     * Every URL to prebuild, as root-relative paths below the home URL, with the route that serves it;
     * and old URLs of listed kind items that redirect to them.
     *
     * @return array{urls: list<array{path: string, route: string}>, redirects: list<array{from: string, to: string}>, build: string}
     */
    public static function urls(Plugin $plugin): array
    {
        $app = $plugin->schema->app() ?? ['routes' => [], 'files' => []];
        $plugin->app();
        $urls = [];
        $redirects = [];
        $anonymous = new Viewer(null);
        foreach ($app['routes'] as $route) {
            if (!($route['prerender'] ?? false) || ($route['page'] ?? null) === null || $route['guards'] !== []) {
                continue;
            }
            $pattern = (string) $route['pattern'];
            if (!str_contains($pattern, ':') && !str_contains($pattern, '*')) {
                $urls[] = ['path' => $pattern, 'route' => (string) $route['id']];
                continue;
            }
            if (($route['kind'] ?? null) !== null) {
                foreach (self::items($plugin, (string) $route['kind']['name'], $anonymous) as [$path, $old]) {
                    $urls[] = ['path' => $path, 'route' => (string) $route['id']];
                    foreach ($old as $from) {
                        $redirects[] = ['from' => $from, 'to' => $path];
                    }
                }
                continue;
            }
            $fn = $app['files'][$route['page']]['staticParams'] ?? null;
            if (!is_string($fn)) {
                continue;
            }
            $callable = '\\TypedPressApp\\' . $fn;
            $list = Seam::asViewer($anonymous, static fn () => Seam::toPhp(\TypedPress\JsJobs::await($callable())));
            foreach (is_array($list) ? $list : [] as $i => $params) {
                $urls[] = ['path' => self::fill($pattern, is_array($params) ? $params : [], (string) $route['page'], $i), 'route' => (string) $route['id']];
            }
        }
        $seen = [];
        $urls = array_values(array_filter($urls, static function (array $u) use (&$seen, $plugin): bool {
            if (isset($seen[$u['path']]) || \TypedPress\Commerce\Module::privatePath($plugin, $u['path'])) {
                return false;
            }
            return $seen[$u['path']] = true;
        }));
        return ['urls' => $urls, 'redirects' => $redirects, 'build' => $plugin->buildHash(), 'app' => self::appHash($plugin)];
    }

    /**
     * The app plugin's compiled code as `typedpress export --site` compares it with the checkout's: the
     * SHA-256 of each file below `tp/app/` but its migrations (its path, a NUL, its bytes, a NUL),
     * in path order.
     * Any change to a page, a component, an action or the schema changes it.
     */
    public static function appHash(Plugin $plugin): string
    {
        $root = $plugin->dir . '/tp/app';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $file = substr($f->getPathname(), strlen($root) + 1);
            // Migrations are the site's ledger, not code a page renders with.
            if ($f->isFile() && !str_starts_with($file, 'migrations/')) {
                $files[] = $file;
            }
        }
        sort($files, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, $file . "\0" . (string) file_get_contents("$root/$file") . "\0");
        }
        return hash_final($hash);
    }

    /**
     * A kind's items a signed-out visitor may read, as (path, old paths): the path its `url` gives
     * it, and the paths it had before a slug change (core's `_wp_old_slug`).
     *
     * @return list<array{string, list<string>}>
     */
    private static function items(Plugin $plugin, string $kind, Viewer $viewer): array
    {
        $spec = $plugin->schema->collection($kind);
        $urls = KindUrls::instance();
        $postType = $spec['wp']['postType'] ?? null;
        if ($urls === null || !is_string($postType)) {
            return [];
        }
        $pk = (string) $spec['primaryKey'];
        $out = [];
        $after = null;
        do {
            $where = $after === null ? [] : [$pk => ['gt' => $after]];
            $rows = Seam::asViewer($viewer, static fn () => \TypedPress\Db::instance()->findMany($kind, ['where' => $where, 'orderBy' => [$pk => 'asc'], 'limit' => 500], $viewer));
            // The batch's posts and their meta (`_wp_old_slug`, a field in the URL) in two reads, not two per item.
            _prime_post_caches(array_map(static fn (array $row): int => (int) $row[$pk], $rows), false, true);
            foreach ($rows as $row) {
                $after = $row[$pk];
                $post = get_post((int) $row[$pk]);
                if (!$post instanceof \WP_Post || !is_post_publicly_viewable($post)) {
                    continue;
                }
                $path = $urls->path($kind, $post);
                if ($path === null) {
                    continue;
                }
                $old = [];
                foreach ((array) get_post_meta($post->ID, '_wp_old_slug') as $slug) {
                    if (!is_string($slug) || $slug === '' || $slug === $post->post_name) {
                        continue;
                    }
                    $moved = clone $post;
                    $moved->post_name = $slug;
                    $from = $urls->path($kind, $moved);
                    if ($from !== null && $from !== $path) {
                        $old[] = $from;
                    }
                }
                $out[] = [$path, $old];
            }
        } while (count($rows) === 500);
        return $out;
    }

    /** A route pattern with one `staticParams` entry's values: `/tags/:tag` + `{tag: "a b"}` → `/tags/a%20b`. */
    private static function fill(string $pattern, array $params, string $file, int $index): string
    {
        $out = [];
        foreach (explode('/', trim($pattern, '/')) as $part) {
            if (!str_starts_with($part, ':') && !str_starts_with($part, '*')) {
                $out[] = $part;
                continue;
            }
            $name = ltrim($part, ':*');
            $value = $params[$name] ?? null;
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                if (!is_string($v) || $v === '' || str_contains($v, '/') || $v === '.' || $v === '..') {
                    throw new \RuntimeException("staticParams in $file: entry $index needs `$name` as " . (is_array($value) || str_starts_with($part, '*') ? 'a list of path segments' : 'one path segment') . ', not ' . json_encode($value));
                }
                $out[] = rawurlencode($v);
            }
        }
        return '/' . implode('/', $out);
    }

    /** `GET /wp-json/typedpress/v1/prerender`: the URLs to build, to someone who may edit the site. */
    public static function route(string $method, array $parts, array $query, string $body, Viewer $viewer): array
    {
        \TypedPress\App::allow($method, ['GET']);
        $plugin = class_exists(\TypedPress\Wp\Runtime::class, false) ? \TypedPress\Wp\Runtime::plugin() : null;
        if ($plugin === null || count($parts) !== 1 || !is_array($plugin->schema->app())) {
            throw HttpError::notFound('This app has no tp/app/ pages');
        }
        if ($viewer->user === null) {
            throw new HttpError(401, 'unauthenticated', 'Sign in as an editor to list the pages to prebuild (TP_APP_PASSWORD or TP_TOKEN)');
        }
        if (!in_array($viewer->user['role'] ?? null, ['editor', 'admin'], true)) {
            throw new HttpError(403, 'forbidden', 'Only an editor or administrator lists the pages to prebuild');
        }
        $answer = self::urls($plugin);
        $answer['output'] = $plugin->schema->app()['output'] ?? 'server';
        // The header each render needs: the export fetches with it, and the site checks it.
        foreach ($answer['urls'] as &$url) {
            $url['token'] = self::token(self::full($url['path']));
            $twin = (string) (wp_parse_url(MarkdownTwins::twin(home_url($url['path'])), PHP_URL_PATH) ?? '');
            $url['twin'] = ['path' => $twin, 'token' => self::token($twin)];
        }
        unset($url);
        $answer['home'] = home_url('/');
        $answer['name'] = (string) get_bloginfo('name');
        $answer['notFound'] = ['path' => self::full('/__tp-not-found'), 'token' => self::token(self::full('/__tp-not-found'))];
        // Actions a page's client components may call from another origin: the public ones.
        $answer['actions'] = array_map(static fn (array $a): bool => ($a['access'] ?? null) === ['role' => 'anyone'], $plugin->schema->actions());
        return [200, $answer];
    }

    /** A path below the home URL as the request will carry it (with the home path in front). */
    private static function full(string $path): string
    {
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        return $base . ($path === '/' ? '/' : $path);
    }

    // ------------------------------------------------------------------ files (output: "server")

    /** This build's directory of prebuilt files; `tp_prebuilt_dir` moves it. */
    public static function dir(): string
    {
        $root = (string) apply_filters('tp_prebuilt_dir', WP_CONTENT_DIR . '/cache/typedpress-prebuilt');
        return $root . (is_multisite() ? '/sites/' . get_current_blog_id() : '') . '/' . substr(self::build_id(), 0, 16);
    }

    /**
     * The two files of a path: `/` → index.html, `/a/b` → a/b.html (and .md). Each segment is named by
     * its percent-encoded form, so `/tags/lab notes` and `/tags/lab%20notes` are one file.
     */
    private static function files(string $path): array
    {
        $stem = $path === '/' ? 'index' : implode('/', array_map(static fn (string $s): string => rawurlencode(rawurldecode($s)), explode('/', trim($path, '/'))));
        return [self::dir() . '/' . $stem . '.html', self::dir() . '/' . $stem . '.md'];
    }

    /**
     * plugins_loaded: answer a GET or HEAD for a prebuilt path from its file, before WordPress parses
     * the request. Anything that could make the answer differ falls through to the router: a query
     * string, a signed-in, preview or comment-author cookie, `Accept: text/markdown` (the Markdown
     * negotiation's, rendering.md §5), another spelling of the path (`/about` for `/about/`), a missing file or one past
     * MAX_AGE, and a site that hides itself from the public.
     */
    public static function serve(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || (string) ($_SERVER['QUERY_STRING'] ?? '') !== '' || is_admin()) {
            return;
        }
        // `Accept: text/markdown` on an HTML URL is the Markdown negotiation's (MarkdownTwins::negotiate,
        // rendering.md §5): an HTML file would answer it with the wrong representation.
        if (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/markdown')) {
            return;
        }
        $request = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (Cache::viewerCookie() || !Cache::shareable($request, [])) {
            return;
        }
        $uri = (string) (wp_parse_url($request, PHP_URL_PATH) ?? '/');
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '' && $uri !== $base && !str_starts_with($uri, $base . '/')) {
            return;
        }
        $path = substr($uri, strlen($base)) ?: '/';
        // `/index` is not the home page's URL; only its twin `/index.md` is.
        if ($path === '/index' || $path === '/index/') {
            return;
        }
        // `/.md` is not the home page twin's URL; `/index.md` is.
        if ($path === '/.md') {
            return;
        }
        $markdown = str_ends_with($path, '.md');
        $html = $markdown ? (substr($path, 0, -3) === '/index' ? '/' : substr($path, 0, -3)) : $path;
        if (self::$plugin !== null && \TypedPress\Commerce\Module::privatePath(self::$plugin, $html)) {
            return;
        }
        // A page only at its one address (`/about/`, AppRouter::address): the other spelling reaches
        // the router, which redirects it. A twin is `/about.md` only, so `/about/.md` is rejected below.
        if (!$markdown) {
            if ($html !== AppRouter::address($html)) {
                return;
            }
            $html = $html === '/' ? '/' : rtrim($html, '/');
        }
        if ($html !== '/' && (str_ends_with($html, '/') || str_contains(rawurldecode($html), '..') || str_contains(rawurldecode($html), "\0") || str_contains(rawurldecode($html), '\\'))) {
            return;
        }
        // Only the spelling the file was written under: `/%61bout` is not `/about`'s URL, and neither
        // are `/a//b` or `/./about`.
        if ($html !== '/') {
            foreach (explode('/', substr($html, 1)) as $segment) {
                if ($segment === '' || $segment === '.' || rawurlencode(rawurldecode($segment)) !== $segment) {
                    return;
                }
            }
        }
        $file = self::files($html)[$markdown ? 1 : 0];
        $mtime = @filemtime($file);
        if ($mtime === false || $mtime + self::MAX_AGE < time()) {
            return;
        }
        // A plugin that gates the site (a private site, coming soon, a membership wall) says no here,
        // and the request reaches WordPress, where its gate runs as before.
        // Not served at all while the site hides itself from the public (a private or coming-soon
        // site), whatever plugin does the hiding later in the request.
        if (!RoutePlan::publicRequest() || !apply_filters('tp_prebuilt_serve', true, $html)) {
            return;
        }
        // One open: the handle's content is tagged, measured and sent, so the ETag names exactly the
        // body even when a rebuild renames a new file into place meanwhile, and the page is streamed,
        // never held whole in memory. Any failure here renders as usual, before a prebuilt header.
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        $stat = @fstat($handle);
        $etag = is_array($stat) ? \TypedPress\Validator::stream($handle, (int) $stat['size']) : null;
        if ($etag === null) {
            fclose($handle);
            return;
        }
        [$control] = Cache::policy(200, true, false);
        Cache::send($control);
        header('X-TypedPress-Prebuilt: 1');
        if ($markdown) {
            header('Content-Type: ' . MarkdownTwins::CONTENT_TYPE);
            header('X-Content-Type-Options: nosniff');
            header('Link: <' . home_url(AppRouter::address($html)) . '>; rel="canonical"');
        } else {
            header('Content-Type: text/html; charset=' . get_option('blog_charset'));
            header('Link: <' . MarkdownTwins::twin(home_url($html)) . '>; rel="alternate"; type="text/markdown"');
        }
        $signal = MarkdownTwins::contentSignal();
        if ($signal !== '') {
            header('Content-Signal: ' . $signal);
        }
        // The content's own validator: a client (or edge) that holds it gets an empty 304. The gates
        // above already decided the file is what this request would get.
        header('ETag: ' . $etag);
        if (\TypedPress\Validator::matches($etag)) {
            fclose($handle);
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . (string) $stat['size']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            // PHP 8.5 can allocate the entire file in fpassthru(), exceeding a bounded request's memory.
            while (($chunk = fread($handle, 65536)) !== false && $chunk !== '') {
                echo $chunk;
            }
        }
        fclose($handle);
        exit;
    }

    /**
     * The `tp_prebuild` action: render paths (all of this build's when `$paths` is empty) and
     * write their files. More than BATCH paths continue in another action.
     *
     * @param list<string> $paths
     */
    public static function build(array $paths = []): void
    {
        $plugin = self::$plugin;
        if ($plugin === null || $plugin->unavailable() !== null) {
            return;
        }
        $all = $paths === [];
        $began = microtime(true);
        $listed = array_column(self::urls($plugin)['urls'], 'path');
        if ($all) {
            self::sweep();
            self::prune($listed);
            $paths = $listed;
        } else {
            // A path no longer listed (an unpublished item, a value staticParams dropped) loses
            // its files and is not built again.
            foreach (array_diff($paths, $listed) as $gone) {
                foreach (self::files((string) $gone) as $file) {
                    @unlink($file);
                }
            }
            $paths = array_values(array_intersect($paths, $listed));
        }
        $rest = array_slice($paths, self::BATCH);
        if ($rest !== []) {
            as_enqueue_async_action(self::ACTION, [$rest], 'typedpress');
        }
        foreach (array_slice($paths, 0, self::BATCH) as $path) {
            if (!self::render((string) $path)) {
                // The site can't reach itself: stop, and back off before the whole build runs again.
                $state = get_option(self::option());
                $retry = min(self::MAX_AGE, 2 * (int) (is_array($state) ? ($state['retry'] ?? 30) : 30));
                update_option(self::option(), ['state' => 'failed', 'at' => time(), 'retry' => $retry], false);
                return;
            }
        }
        // A forget() since this build began dropped its files and state: the next request schedules
        // a full build again, so nothing is recorded.
        if ($all && self::forgotten() < $began) {
            update_option(self::option(), ['state' => 'built', 'at' => time(), 'retry' => 60], false);
        }
    }

    /**
     * One path, rendered by loopback and written, or its files removed when it no longer renders.
     * False when the site couldn't be reached: nothing changed, and the build is tried again.
     * `tp_prebuild_url` routes the loopback (a container's own address), as `tp_private_probe_url`
     * does for the private-files probe.
     */
    private static function render(string $path): bool
    {
        [$html, $md] = self::files($path);
        $started = microtime(true);
        $fetch = static fn (string $url, string $signed) => wp_remote_get((string) apply_filters('tp_prebuild_url', $url), [
            'timeout' => 30, 'redirection' => 0, 'sslverify' => false,
            'headers' => [self::HEADER => self::token($signed)],
            'cookies' => [new \WP_Http_Cookie(['name' => self::COOKIE, 'value' => '1'])],
        ]);
        $page = $fetch(home_url($path), self::full($path));
        if (is_wp_error($page)) {
            error_log("TypedPress: prebuilding $path failed: " . $page->get_error_message() . '. Route the request with the tp_prebuild_url filter if the site cannot reach its own address');
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($page);
        $refused = is_wp_error($page) ? '' : (string) wp_remote_retrieve_header($page, strtolower(self::REFUSED));
        if ($code !== 200 || $refused !== '' || wp_remote_retrieve_header($page, 'set-cookie') !== '') {
            @unlink($html);
            @unlink($md);
            if ($refused === self::UNRECORDED) {
                error_log("TypedPress: $path is prebuilt, but the page cache's read index could not record its render (an address longer than the index holds?), so no write would rebuild its file; it renders per request instead");
            } elseif ($refused !== '') {
                error_log("TypedPress: $path is prebuilt, and its render called $refused; it renders per request instead");
            }
            return true;
        }
        // Only a response the router rendered for this prerender becomes a file: a shared cache's
        // stored copy carries no echo.
        if (wp_remote_retrieve_header($page, 'x-typedpress-prerendered') !== '1') {
            error_log("TypedPress: prebuilding $path got an answer the site didn't render for it (a cache in front of the loopback?); route it with tp_prebuild_url");
            return false;
        }
        // A write that named this path after the render started makes this render old.
        if (self::staleSince($path) >= $started) {
            return true;
        }
        $twin = MarkdownTwins::twin(home_url($path));
        $markdown = $fetch($twin, (string) (wp_parse_url($twin, PHP_URL_PATH) ?? ''));
        // The twin is written only from an answer rendered for this prerender, as the page is.
        $twinOk = !is_wp_error($markdown) && (int) wp_remote_retrieve_response_code($markdown) === 200
            && wp_remote_retrieve_header($markdown, 'x-typedpress-prerendered') === '1'
            && wp_remote_retrieve_header($markdown, strtolower(self::REFUSED)) === ''
            && wp_remote_retrieve_header($markdown, 'set-cookie') === '';
        if (!self::write($html, (string) wp_remote_retrieve_body($page), $path, $started)) {
            return true;
        }
        if (!$twinOk || !self::write($md, (string) wp_remote_retrieve_body($markdown), $path, $started)) {
            @unlink($md);
        }
        return true;
    }

    /**
     * Write by rename, so a reader sees the old file or the new one, never part of one. Nothing is
     * written when a write named the path, or `forget()` ran, after the render started: that render
     * is older than it.
     */
    private static function write(string $file, string $bytes, string $path, float $started): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || max(self::staleSince($path), self::forgotten()) >= $started) {
            @unlink($tmp);
            return false;
        }
        return rename($tmp, $file);
    }

    /** Removes the files of paths the build no longer lists. */
    private static function prune(array $listed): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $keep = [];
        foreach ($listed as $path) {
            foreach (self::files((string) $path) as $file) {
                $keep[$file] = true;
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && !isset($keep[$f->getPathname()])) {
                @unlink($f->getPathname());
            }
        }
    }

    /** When a write last named this path (`stale()`), as a Unix time; 0 for never. */
    /**
     * When a write last named this path (`stale()`), as a Unix time; 0 for never. Read from the
     * database each time, past the object cache, so a long batch sees a write made during it.
     */
    private static function staleSince(string $path): float
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::MARKS));
        $marks = is_string($raw) ? maybe_unserialize($raw) : null;
        return is_array($marks) ? (float) ($marks[$path] ?? 0) : 0.0;
    }

    /** When `forget()` last ran, as a Unix time; 0 for never. Read from the database, as `staleSince()`. */
    private static function forgotten(): float
    {
        global $wpdb;
        return (float) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::FORGOTTEN));
    }

    /** Removes other builds' directories: files from another build are never served, and are a cache. */
    private static function sweep(): void
    {
        $root = dirname(self::dir());
        foreach (is_dir($root) ? (array) scandir($root) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && preg_match('/^[0-9a-f]{16}$/', (string) $entry) === 1 && $root . '/' . $entry !== self::dir()) {
                self::remove($root . '/' . $entry);
            }
        }
    }

    private static function remove(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * After a committed write: the prebuilt paths among the URLs it changed lose their files now (so
     * they render per request at once) and are rebuilt by a `tp_prebuild` action.
     *
     * @param list<string> $paths root-relative paths with their query, as purges name them
     */
    public static function stale(array $paths): void
    {
        $plugin = self::$plugin;
        $app = $plugin?->schema->app();
        if (!is_array($app)) {
            return;
        }
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        $hit = [];
        $all = in_array('/*', $paths, true);
        foreach ($paths as $p) {
            $path = (string) (wp_parse_url($p, PHP_URL_PATH) ?? '');
            if ($path === '' || (string) (wp_parse_url($p, PHP_URL_QUERY) ?? '') !== '' || str_ends_with($path, '.md')) {
                continue;
            }
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base)) ?: '/';
            }
            $path = $path === '/' ? '/' : rtrim($path, '/');
            $match = AppRouter::match($app['routes'], $path, $app['routeIndex']);
            if ($match !== null && ($match['route']['prerender'] ?? false)) {
                $hit[$path] = true;
            }
        }
        if ($all) {
            self::forget();
            return;
        }
        $marks = get_option(self::MARKS);
        $marks = is_array($marks) ? array_filter($marks, static fn (float $at): bool => $at + self::MAX_AGE > microtime(true)) : [];
        foreach (array_keys($hit) as $path) {
            foreach (self::files($path) as $file) {
                @unlink($file);
            }
            $marks[$path] = microtime(true);
        }
        if ($hit !== []) {
            update_option(self::MARKS, array_slice($marks, -500, null, true), false);
        }
        if ($hit !== [] && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION, [array_keys($hit)], 'typedpress');
        }
    }
}
