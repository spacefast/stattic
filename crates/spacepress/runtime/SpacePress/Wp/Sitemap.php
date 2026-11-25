<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Viewer;

/**
 * One sitemap for the site, through WordPress core's own (`wp-sitemap.xml`, `wp_sitemaps`):
 *
 * - core's `posts`, `taxonomies` and `users` providers list posts, pages, terms and authors as they
 *   always do, with the links SpacePress's kind `url` patterns filter;
 * - SpacePress adds one provider, `spacepress`, with a sub-sitemap per kind that has a `url`
 *   (`wp-sitemap-spacepress-<kind>-1.xml`) and one for `sp/app/` pages
 *   (`wp-sitemap-spacepress-app-pages-1.xml`; a kind's name has no `-`, so the two never collide).
 *
 * Only what an anonymous visitor may read is listed. A kind's rows are the ones its read rule and
 * draft visibility cover for a signed-out viewer, selected by SpacePress's own read pipeline (the same
 * query `posts.list()` runs for a visitor), so a draft, a row a filter rule hides and a kind readers
 * must sign in for never appear. An app page is listed when it is one `/llms.txt` lists (no
 * parameters, no layout `guard`, and it renders for a signed-out visitor) and does not ask robots to
 * stay away (`metadata.robots.index: false`). The home page is core's entry, never a second one.
 * A password-protected post or item is left out as well, from core's files and from SpacePress's.
 *
 * Front-end modes (spec §5): with `frontend: false` there are no public pages, so there is no
 * sitemap at all; with an external frontend every entry points at that site (the link filters of
 * {@see KindUrls} already move posts, pages, terms and kind items there), and the sitemap itself is
 * answered here for that site to serve or name in its robots.txt.
 *
 * Core renders the XML, the index, the stylesheets and the `robots.txt` line. Nothing here writes XML.
 * A write purges the sitemap files it changed with the item's other URLs ({@see Purges}).
 */
final class Sitemap
{
    /** The provider's name in core's registry and in its URLs. */
    public const NAME = 'spacepress';
    /** The sub-sitemap that lists `sp/app/` pages. */
    public const PAGES = 'app-pages';
    /** The option that records each sub-sitemap's file count, for purges. */
    private const PAGES_SEEN = 'sp_sitemap_pages';

    public static function register(Plugin $plugin): void
    {
        if ($plugin->schema->frontend() === false) {
            // No public HTML, so nothing to list: core answers its sitemap URLs with a 404.
            add_filter('wp_sitemaps_enabled', '__return_false', PHP_INT_MAX);

            return;
        }
        // Core builds its server on `init` and announces it here; providers register on this hook.
        add_action('wp_sitemaps_init', static function () use ($plugin): void {
            wp_register_sitemap_provider(self::NAME, self::provider($plugin));
        });
        // Answered before the front-end gates (an external frontend's redirect at -1, the app router
        // at 1): the sitemap is this site's own, like its feeds, whoever renders the pages.
        add_action('template_redirect', static function (): void {
            if (get_query_var('sitemap') || get_query_var('sitemap-stylesheet')) {
                self::anonymous();
                wp_sitemaps_get_server()->render_sitemaps();
                // Core renders and exits for every sitemap it has, so a sitemap still standing here
                // names a removed provider (the users sitemap while authors are private). WordPress
                // 7.2 answers it with a 404; 7.1 returns without one, so send 7.2's.
                if (get_query_var('sitemap') && !is_404()) {
                    $GLOBALS['wp_query']->set_404();
                    status_header(404);
                    nocache_headers();
                }
                // Core answered a 404 (sitemaps off, an unknown file, an empty page) and returned: end
                // here, before an external frontend's gate would redirect the request.
                if (is_404()) {
                    exit;
                }
            }
        }, -2);
        // A password-protected post's content is not a visitor's to read: core lists such posts, this
        // sitemap does not.
        add_filter('wp_sitemaps_posts_query_args', static fn (mixed $args): mixed => is_array($args) ? $args + ['has_password' => false] : $args, PHP_INT_MAX);
        // Core dates the home entry from a query of its own, which counts protected posts: date it
        // from the newest post this sitemap lists instead.
        add_filter('wp_sitemaps_posts_show_on_front_entry', static function (mixed $entry): mixed {
            if (!is_array($entry)) {
                return $entry;
            }
            unset($entry['lastmod']);
            $latest = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'numberposts' => 1,
                'orderby' => 'modified', 'order' => 'DESC', 'suppress_filters' => false]);
            if ($latest !== []) {
                $entry['lastmod'] = (string) wp_date(DATE_W3C, (int) strtotime($latest[0]->post_modified_gmt . ' UTC'));
            }

            return $entry;
        }, 0);
        // Each core sub-sitemap's file count, for purges ({@see files()}).
        // The index lists every file of every sub-sitemap, so its last entry per sub-sitemap is the count.
        $counted = [];
        add_filter('wp_sitemaps_index_entry', static function (mixed $entry, string $provider, string $subtype, int $page) use (&$counted): mixed {
            $counted["$provider:$subtype"] = max($counted["$provider:$subtype"] ?? 0, $page);
            self::remember($provider, $subtype, $counted["$provider:$subtype"]);

            return $entry;
        }, 10, 4);
        // A write that changes who may see a row without touching the post (a field a filter rule
        // reads, a users-kind field) still purges the files that list its kind.
        \SpacePress\Extensions::onWritten(static function (string $op, string $collection): void {
            if (has_action('sp_purge_urls') !== false && ($files = self::filesForKind($collection)) !== []) {
                Purges::announce($files);
            }
        });
        // Core lists the authors of public posts. With a users kind, a user is listed only when a
        // signed-out visitor may read their row.
        add_filter('wp_sitemaps_users_query_args', static fn (mixed $args): mixed => is_array($args) ? self::readableUsers($plugin, $args) : $args, PHP_INT_MAX);
        $frontend = $plugin->schema->frontend();
        if (is_string($frontend)) {
            // Core's own entries that no link filter reaches: the home page and author archives.
            $rebase = static function (mixed $entry) use ($frontend): mixed {
                if (is_array($entry) && is_string($entry['loc'] ?? null)) {
                    $entry['loc'] = KindUrls::rebase($entry['loc'], $frontend);
                }

                return $entry;
            };
            add_filter('wp_sitemaps_posts_show_on_front_entry', $rebase, PHP_INT_MAX);
            add_filter('wp_sitemaps_users_entry', $rebase, PHP_INT_MAX);
        }
    }

    /**
     * The rest of this request is a signed-out visitor's, whatever it carries: no user, and no
     * post-password cookie that would open a protected item to SpacePress's reads.
     */
    private static function anonymous(): void
    {
        wp_set_current_user(0);
        add_filter('determine_current_user', static fn (): int => 0, PHP_INT_MAX);
        unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    }

    /**
     * The sitemap files a write to $post may change, for the purge of its URLs: the index and every
     * file of the sub-sitemap that would list it. Which file holds an item depends on rows other
     * readers can't see, and asking the read rules here would run them inside the write, so every
     * file of that sub-sitemap goes; a sub-sitemap holds 2000 URLs a file, so that is a few paths.
     * The file count is the one the last sitemap request recorded, plus one for a new file.
     *
     * @return list<string>
     */
    public static function filesFor(\WP_Post $post): array
    {
        $plugin = Plugin::instance();
        if ($plugin === null || $plugin->schema->frontend() === false) {
            return [];
        }
        $server = function_exists('wp_sitemaps_get_server') ? wp_sitemaps_get_server() : null;
        if (!$server instanceof \WP_Sitemaps || !$server->sitemaps_enabled()) {
            return [];
        }
        foreach (self::subtypes($plugin) as $subtype => $kind) {
            if (isset($kind['kind']) && ($plugin->schema->collection($kind['kind'])['wp']['postType'] ?? null) === $post->post_type) {
                return self::files(self::NAME, $subtype);
            }
        }
        $posts = $server->registry->get_provider('posts');
        if (!$posts instanceof \WP_Sitemaps_Provider || !isset($posts->get_object_subtypes()[$post->post_type])) {
            return [];
        }

        return self::files('posts', $post->post_type);
    }

    /** The files of SpacePress's sub-sitemap a write to a row of $collection may change (`onWritten`). */
    public static function filesForKind(string $collection): array
    {
        $plugin = Plugin::instance();
        if ($plugin === null || $plugin->schema->frontend() === false || !function_exists('get_sitemap_url')) {
            return [];
        }
        if (($plugin->schema->auth()['collection'] ?? null) === $collection) {
            return self::files('users', '');
        }
        $subtype = self::subtypes($plugin)[self::slug($collection)] ?? null;

        return $subtype === null ? [] : self::files(self::NAME, self::slug($collection));
    }

    /** @return list<string> the index and files 1..n+1 of one sub-sitemap, n as last recorded */
    private static function files(string $provider, string $subtype): array
    {
        $seen = get_option(self::PAGES_SEEN, []);
        $n = is_array($seen) ? (int) ($seen["$provider:$subtype"] ?? 1) : 1;
        $out = [(string) get_sitemap_url('index')];
        for ($page = 1; $page <= $n + 1; $page++) {
            $out[] = (string) get_sitemap_url($provider, $subtype, $page);
        }

        return $out;
    }

    /** Records how many files a sub-sitemap has, when a request finds it changed. */
    private static function remember(string $provider, string $subtype, int $pages): void
    {
        $seen = get_option(self::PAGES_SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        if (($seen["$provider:$subtype"] ?? null) !== $pages) {
            $seen["$provider:$subtype"] = $pages;
            update_option(self::PAGES_SEEN, $seen, false);
        }
    }

    /**
     * The provider, as an anonymous subclass of core's: `WP_Sitemaps_Provider` exists only once
     * WordPress is loaded, and this file must stay loadable (and lintable) without it.
     */
    private static function provider(Plugin $plugin): \WP_Sitemaps_Provider
    {
        return new class($plugin) extends \WP_Sitemaps_Provider {
            public function __construct(private readonly Plugin $plugin)
            {
                $this->name = Sitemap::NAME;
                $this->object_type = Sitemap::NAME;
            }

            /** @return array<string, array{label: string}> one sub-sitemap per kind with URLs, and one for app pages */
            public function get_object_subtypes()
            {
                return Sitemap::subtypes($this->plugin);
            }

            /** @return list<array{loc: string, lastmod?: string}> */
            public function get_url_list($page_num, $object_subtype = '')
            {
                return Sitemap::urls($this->plugin, (string) $object_subtype, max(1, (int) $page_num));
            }

            public function get_max_num_pages($object_subtype = '')
            {
                return Sitemap::pages($this->plugin, (string) $object_subtype);
            }
        };
    }

    /**
     * The sub-sitemaps: each kind whose `url` gives its items public addresses, by a name core's
     * sitemap URLs can carry, then the app's pages.
     *
     * @return array<string, array{label: string, kind?: string}>
     */
    public static function subtypes(Plugin $plugin): array
    {
        $out = [];
        // While a build's migrations are pending its tables may not match it: list nothing of the app's.
        foreach ($plugin->unavailable() !== null ? [] : $plugin->schema->collections() as $name => $spec) {
            if (is_string($spec['url'] ?? null) && isset($spec['wp']['postType'])) {
                $out[self::slug((string) $name)] = ['label' => (string) ($spec['label'] ?? $name), 'kind' => (string) $name];
            }
        }
        // The app's pages are SpacePress's to list only while SpacePress renders them here.
        if (AppRouter::instance() !== null && $plugin->schema->frontend() === null) {
            $out[self::PAGES] = ['label' => 'Pages'];
        }

        return $out;
    }

    /** How many sitemap files a sub-sitemap needs; none when it has no public URL. */
    public static function pages(Plugin $plugin, string $subtype): int
    {
        $kind = self::subtypes($plugin)[$subtype] ?? null;
        if ($kind === null) {
            return 0;
        }
        $count = count(isset($kind['kind']) ? self::listed($plugin, $kind['kind']) : self::appPages($plugin));
        $pages = (int) ceil($count / wp_sitemaps_get_max_urls(self::NAME));
        self::remember(self::NAME, $subtype, $pages);

        return $pages;
    }

    /**
     * One file of a sub-sitemap.
     *
     * @return list<array{loc: string, lastmod?: string}>
     */
    public static function urls(Plugin $plugin, string $subtype, int $page): array
    {
        $kind = self::subtypes($plugin)[$subtype] ?? null;
        if ($kind === null) {
            return [];
        }
        $max = wp_sitemaps_get_max_urls(self::NAME);
        $entries = array_slice(isset($kind['kind']) ? self::entries($plugin, $kind['kind']) : self::appPages($plugin), ($page - 1) * $max, $max);
        $out = [];
        foreach ($entries as $entry) {
            /**
             * Filters one entry of SpacePress's sitemap, as core filters its own (`wp_sitemaps_posts_entry`).
             * Return an empty value to leave the URL out.
             *
             * @param array{loc: string, lastmod?: string} $entry
             * @param string $subtype the kind's sub-sitemap name, or `pages`
             */
            $entry = apply_filters('sp_sitemap_entry', $entry, $subtype);
            if (is_array($entry) && is_string($entry['loc'] ?? null) && $entry['loc'] !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    // ================================================================ users

    /** Core's author query, narrowed to the users whose row the users kind's read rule shows a visitor. */
    private static function readableUsers(Plugin $plugin, array $args): array
    {
        $collection = $plugin->schema->auth()['collection'] ?? null;
        // `publicAuthors`, or a kind anyone may read: every author, as WordPress ships it.
        if (!is_string($collection) || Authors::public($plugin->schema)) {
            return $args;
        }
        if ($plugin->unavailable() !== null) {
            // The rule can't be asked while storage is behind the build: list no author until it can.
            $args['include'] = [0];

            return $args;
        }
        try {
            $keys = $plugin->db()->permittedKeys($collection, 'read', new Viewer(null));
        } catch (\SpacePress\HttpError) {
            $keys = []; // the rule refuses signed-out visitors
        }
        if ($keys === null) {
            return $args;
        }
        // No readable user is no author at all: an empty `include` would mean "everyone" to core.
        $args['include'] = $keys === [] ? [0] : array_map('intval', $keys);

        return $args;
    }

    // ================================================================ kinds

    /** @var array<string, list<int>> a kind's listed ids, once per request */
    private static array $listed = [];

    /**
     * The ids of a kind's items a signed-out visitor may open, by id: the one list a file's count,
     * its paging and its entries come from, so none of them can tell a hidden row is there.
     *
     * An item is listed when SpacePress's read pipeline gives it to an anonymous viewer (the read rule,
     * a filter it returns, draft visibility, the store's guards), it is published without a
     * password, every placeholder of its pattern is filled from a field a visitor may read, and no
     * guarded `sp/app/` route serves its URL.
     *
     * @return list<int>
     */
    public static function listed(Plugin $plugin, string $kind): array
    {
        if (isset(self::$listed[$kind])) {
            return self::$listed[$kind];
        }
        $spec = $plugin->schema->collection($kind);
        $pk = $spec['primaryKey'];
        $visitor = new Viewer(null);
        if (!KindUrls::patternReadable($spec, $visitor)) {
            return self::$listed[$kind] = [];
        }
        try {
            $rows = $plugin->db()->findMany($kind, ['columns' => [$pk => true], 'orderBy' => [$pk => 'asc']] + self::everyLocale($plugin, $kind), $visitor);
        } catch (\SpacePress\HttpError) {
            return self::$listed[$kind] = []; // the read rule refuses signed-out visitors outright
        }
        $ids = array_map(static fn (array $row): int => (int) $row[$pk], $rows);
        // The posts, and their meta when the kind's URL holds a field: one read each, not one per item.
        $urls = KindUrls::instance();
        $urls === null ? _prime_post_caches($ids, false, false) : $urls->prime($kind, $ids);
        $out = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            // A published page under one that isn't is as hidden as its parent, and has no URL.
            if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_password !== '' || !KindUrls::published($post)) {
                continue;
            }
            $path = KindUrls::instance()?->path($kind, $post);
            if ($path === null || AppRouter::instance()?->guarded($path, $post->post_type) === true) {
                continue;
            }
            $out[] = $id;
        }

        return self::$listed[$kind] = $out;
    }

    /** @return list<array{loc: string, lastmod: string}> the listed items' entries, in id order */
    private static function entries(Plugin $plugin, string $kind): array
    {
        $out = [];
        foreach (self::listed($plugin, $kind) as $id) {
            $post = get_post($id);
            if ($post instanceof \WP_Post) {
                $out[] = ['loc' => (string) get_permalink($post), 'lastmod' => (string) wp_date(DATE_W3C, (int) strtotime($post->post_modified_gmt . ' UTC'))];
            }
        }

        return $out;
    }

    /** Every translation has its own URL, so a localized kind lists the rows of every locale. */
    private static function everyLocale(Plugin $plugin, string $kind): array
    {
        return $plugin->schema->locales() !== null && ($plugin->schema->collection($kind)['localized'] ?? false) ? ['locale' => '*'] : [];
    }

    // ================================================================ app pages

    /** @var ?list<array{loc: string}> the app's listed pages, once per request (finding them renders each) */
    private static ?array $appPages = null;

    /**
     * The app's public pages, in route order: the ones `/llms.txt` lists, less the home page (core's
     * pages sitemap has it) and any page whose metadata says `robots: { index: false }`.
     *
     * @return list<array{loc: string}>
     */
    private static function appPages(Plugin $plugin): array
    {
        if (self::$appPages !== null) {
            return self::$appPages;
        }
        $out = [];
        if ($plugin->unavailable() === null) {
            foreach (AppRouter::instance()?->pages() ?? [] as [$path]) {
                if ($path !== '/' && self::indexed($plugin, $path)) {
                    $out[] = ['loc' => user_trailingslashit(home_url($path))];
                }
            }
        }

        return self::$appPages = $out;
    }

    /** Whether a parameterless page asks to be indexed: its layouts' and its own `metadata.robots`. */
    private static function indexed(Plugin $plugin, string $pattern): bool
    {
        $app = $plugin->schema->app() ?? [];
        foreach ($app['routes'] ?? [] as $route) {
            if ($route['pattern'] !== $pattern || ($route['page'] ?? null) === null) {
                continue;
            }
            $metadata = [];
            foreach ([...$route['layouts'], $route['page']] as $file) {
                $meta = $app['files'][$file]['metadata'] ?? null;
                if (is_array($meta) && isset($meta['fn'])) {
                    // A function sees what an anonymous visitor's render gives it: no params here.
                    $props = new \SpacePress\JsObject(['params' => new \SpacePress\JsObject([])]);
                    try {
                        $meta = \SpacePress\Seam::asViewer(new Viewer(null), static fn () => \SpacePress\Seam::toPhp(('\\SpacePressApp\\' . $meta['fn'])($props)));
                    } catch (\Throwable) {
                        return false; // metadata a visitor's render can't produce: not listed
                    }
                }
                $metadata[] = is_array($meta) ? $meta : [];
            }

            return (AppRouter::head($metadata)['robots']['index'] ?? true) !== false;
        }

        return false;
    }

    /** A kind's name as core's sitemap URLs carry a subtype: lower-case letters, digits, `_` and `-`. */
    private static function slug(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name));
    }
}
