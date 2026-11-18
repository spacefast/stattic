<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * The URLs a committed content write changed, announced on the host's `tp_purge_urls` action
 * (docs/runtime-contract.md). Only a host that serves cached pages listens, or the runtime itself
 * where Batcache, the VIP edge cache or Jetpack Boost runs (PageCaches); with no listener this does
 * no work at all (`has_action`).
 *
 * A write to a public post, page or term names, as root-relative paths with their query:
 * - the item's URL before and after the write (a slug, status or parent change moves or hides it);
 * - the home page, the posts page, and the post type's archive, with their first pages;
 * - the archives of every term the post had or has, and its author's archive;
 * - its feeds (the site's posts and comments feeds, the post's comments feed, each term's feed),
 *   `/llms.txt`, the sitemap index and the sitemap file that lists it, and each page
 *   URL's `.md` twin.
 * Older listing pages (`/page/7/`) fall back to the edge's `s-maxage` window. URLs are collected over
 * the request, with the `tp/app/` pages the page cache's read index names (Render\Cache), and
 * announced once, after the outermost transaction commits (or at the end of the request when there
 * is none): one `tp_purge_urls` call and one `Prebuilt::stale` with the same list. More than
 * PURGE_LIMIT paths become `/*`, a whole-host purge. A rolled-back transaction announces nothing.
 */
final class Purges
{
    /** Listing pages purged after page 1. */
    public const LISTING_PAGES = 1;
    /** Above this many paths, one announcement purges the whole host. */
    public const PURGE_LIMIT = 200;

    /** @var array<string, true> paths this request's writes changed */
    private static array $paths = [];
    /** @var array<int, list<string>> post id → its URLs before this write */
    private static array $before = [];
    /** @var array<int, true> saves EditorSave::end collected; their wp_after_insert_post callback skips */
    private static array $collected = [];
    private static bool $scheduled = false;
    /** A write this request changed every page: the announcement is `/*`. */
    private static bool $everything = false;

    /** Post types whose writes change how every page looks: the Site Editor's. */
    public const DESIGN_TYPES = ['wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation'];

    public static function register(Plugin $plugin): void
    {
        // A save, trash or delete of a template, part, Global Styles or navigation menu changes
        // every page: prebuilt files go, and the page cache purges the whole host.
        $design = static function (int $id, mixed $post = null): void {
            $post = $post instanceof \WP_Post ? $post : get_post($id);
            // Core creates a default navigation menu lazily while a page renders
            // (WP_Navigation_Fallback): not an owner's edit, and a render or prebuild must not throw
            // away the files it is making.
            if (!$post instanceof \WP_Post || $post->post_status === 'auto-draft' || self::frontEnd()) {
                return;
            }
            if (in_array($post->post_type, self::DESIGN_TYPES, true)) {
                \TypedPress\Render\Prebuilt::forget();
                self::everything();
            }
        };
        add_action('save_post', $design, 10, 2);
        add_action('wp_trash_post', $design, 10, 1);
        add_action('before_delete_post', $design, 10, 2);
        add_action('pre_post_update', static function (int $id): void {
            if (self::listening()) {
                self::$before[$id] = self::postUrls(get_post($id));
            }
        }, 10, 1);
        // A save EditorSave holds open (a wp-admin or core REST save of a TypedPress post) collects its URLs
        // from EditorSave::end, before the commit, so they join the transaction's one announcement;
        // by the time this runs that save is committed and collected. Every other save collects here.
        add_action('wp_after_insert_post', static function (int $id, \WP_Post $post) use ($plugin): void {
            self::saved($plugin, $id, $post);
        }, PHP_INT_MAX, 2);
        // Trash, delete and status changes that don't go through wp_insert_post (wp_publish_post, cron).
        add_action('transition_post_status', static function (string $new, string $old, \WP_Post $post) use ($plugin): void {
            if (self::listening() && $new !== $old) {
                self::add($plugin, self::postUrls($post, $old === 'publish' || $new === 'publish'));
            }
        }, 10, 3);
        add_action('before_delete_post', static function (int $id) use ($plugin): void {
            if (self::listening()) {
                self::add($plugin, self::postUrls(get_post($id)));
            }
        }, 10, 1);
        add_action('set_object_terms', static function (int $id, array $terms, array $ttIds, string $taxonomy, bool $append, array $old) use ($plugin): void {
            $changed = array_map('intval', $ttIds);
            $previous = array_map('intval', $old);
            sort($changed);
            sort($previous);
            // Term archives list public posts only: a draft's terms show nowhere.
            if (self::listening() && $changed !== $previous && ($urls = self::postUrls(get_post($id))) !== []) {
                self::add($plugin, [...self::listings(self::termUrls([...$changed, ...$previous], $taxonomy)), ...$urls]);
            }
        }, 10, 6);
        // wp_remove_object_terms() fires this, not set_object_terms: the terms the post left.
        add_action('deleted_term_relationships', static function (int $id, array $ttIds, string $taxonomy) use ($plugin): void {
            if (self::listening() && $ttIds !== [] && ($urls = self::postUrls(get_post($id))) !== []) {
                self::add($plugin, [...self::listings(self::termUrls(array_map('intval', $ttIds), $taxonomy)), ...$urls]);
            }
        }, 10, 3);
        // A term edit purges its archive before (edit_terms runs before the update, so a slug change
        // still names the old URL) and after; a delete purges it before the term goes.
        $term = static function (int $termId, string $taxonomy) use ($plugin): void {
            $term = get_term($termId, $taxonomy);
            if (self::listening() && $term instanceof \WP_Term) {
                self::add($plugin, self::listings(self::termUrls([$term->term_taxonomy_id], $taxonomy)));
            }
        };
        add_action('edit_terms', $term, 10, 2);
        add_action('edited_term', static fn (int $termId, int $ttId, string $taxonomy) => $term($termId, $taxonomy), 10, 3);
        add_action('pre_delete_term', $term, 10, 2);
    }

    /**
     * Root-relative paths for a set of URLs, each with its `.md` twin, deduplicated: what
     * `tp_purge_urls` receives. Feeds, `/llms.txt` and sitemap files have no twin.
     *
     * @param list<string> $urls absolute URLs or root-relative paths
     * @return list<string>
     */
    public static function paths(array $urls): array
    {
        $paths = [];
        $home = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '');
        foreach ($urls as $url) {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || (isset($parts['host']) && strcasecmp($parts['host'], $home) !== 0)) {
                continue;
            }
            $path = ($parts['path'] ?? '/') . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
            $paths[$path] = true;
            $bare = (string) ($parts['path'] ?? '/');
            if (!self::feed($url) && !preg_match('#/llms\.txt$|\.md$|/wp-sitemap[^/]*\.xml$#', $bare)) {
                $twin = MarkdownTwins::twin($path);
                $paths[(string) (wp_parse_url($twin, PHP_URL_PATH) ?? $twin) . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '')] = true;
            }
        }

        return array_keys($paths);
    }

    /** A host's page cache listens, or prebuilt pages need to know what changed. */
    private static function listening(): bool
    {
        return has_action('tp_purge_urls') !== false || \TypedPress\Render\Prebuilt::active();
    }

    /**
     * A saved post's URLs, before and after the save: collected once per save, announced after its
     * transaction commits (or at the end of the request when there is none).
     */
    public static function saved(Plugin $plugin, int $id, \WP_Post $post): void
    {
        if (isset(self::$collected[$id])) {
            unset(self::$collected[$id]); // EditorSave::end collected this save before its commit
            return;
        }
        if (!self::listening()) {
            return;
        }
        $before = self::$before[$id] ?? [];
        unset(self::$before[$id]);
        self::add($plugin, [...$before, ...self::postUrls($post)]);
    }

    /** EditorSave::end: the save it holds open collects its URLs now, inside the transaction. */
    public static function collect(int $id): void
    {
        $plugin = Plugin::instance();
        $post = get_post($id);
        if ($plugin !== null && $post instanceof \WP_Post) {
            self::saved($plugin, $id, $post);
            self::$collected[$id] = true;
        }
    }

    /** URLs a write changed that no post hook names (TypedPress's own writes): announced with the rest. */
    public static function announce(array $urls): void
    {
        $plugin = Plugin::instance();
        if ($plugin !== null && self::listening()) {
            self::add($plugin, $urls);
        }
    }

    /** A front-end request (a page render or a prerender), not a save an owner made. */
    private static function frontEnd(): bool
    {
        return \TypedPress\Render\Prebuilt::prerendering()
            || (did_action('template_redirect') > 0 && !is_admin() && !(defined('REST_REQUEST') && REST_REQUEST));
    }

    /**
     * A write that changes every page (a Site Editor template, part, Global Styles or navigation
     * menu): one whole-host purge, `/*`, announced with the request's other writes.
     */
    public static function everything(): void
    {
        $plugin = Plugin::instance();
        if ($plugin === null || !self::listening()) {
            return;
        }
        self::$everything = true;
        self::add($plugin, [home_url('/')], false);
    }

    /**
     * `tp/app/` pages whose recorded reads a write changed (Render\Cache, inside the write's
     * transaction): announced with the rest. `/llms.txt` lists content, not pages, and isn't added.
     *
     * @param list<string> $urls
     */
    public static function recorded(array $urls): void
    {
        $plugin = Plugin::instance();
        if ($plugin !== null && self::listening()) {
            self::add($plugin, $urls, false);
        }
    }

    /** @param list<string> $urls */
    private static function add(Plugin $plugin, array $urls, bool $content = true): void
    {
        if ($urls === []) {
            return;
        }
        if ($content) {
            $urls[] = home_url('/llms.txt');
        }
        foreach (self::paths($urls) as $path) {
            self::$paths[$path] = true;
        }
        if (self::$scheduled) {
            return;
        }
        self::$scheduled = true;
        $connection = $plugin->connection();
        if ($connection->inTransaction()) {
            $connection->afterCommit(self::flush(...));
            $connection->onRollback(static function (): void {
                self::$paths = [];
                self::$everything = false;
                self::$scheduled = false;
            });
        } else {
            // A write outside any transaction (wp-admin, core REST) has committed already; one call
            // per request covers every write it makes.
            add_action('shutdown', self::flush(...), 0);
        }
    }

    /** Announce what this request's committed writes changed, once. */
    public static function flush(): void
    {
        $paths = array_keys(self::$paths);
        $everything = self::$everything;
        self::$paths = [];
        self::$scheduled = false;
        self::$everything = false;
        if ($paths !== [] && self::listening()) {
            $paths = $everything || count($paths) > self::PURGE_LIMIT ? ['/*'] : $paths;
            \TypedPress\Render\Prebuilt::stale($paths);
            if (has_action('tp_purge_urls') !== false) {
                do_action('tp_purge_urls', $paths);
            }
        }
    }

    /**
     * A post's public URLs: itself, the listings that show it, its terms' and author's archives,
     * feeds. Nothing for a post nobody can see now, unless $wasPublic (a post that just left `publish`
     * still has cached copies).
     *
     * @return list<string>
     */
    private static function postUrls(?\WP_Post $post, bool $wasPublic = false): array
    {
        if (!$post instanceof \WP_Post || $post->post_type === 'revision' || wp_is_post_autosave($post)) {
            return [];
        }
        $type = get_post_type_object($post->post_type);
        // A TypedPress kind with a `url` is publicly queryable without being `public` (it stays out of
        // core's public lists), and its items still have public URLs to purge.
        if (!$type instanceof \WP_Post_Type || !($type->public || $type->publicly_queryable) || (!$wasPublic && !is_post_publicly_viewable($post))) {
            return [];
        }
        $permalink = self::permalink($post);
        // The site's posts and comments feeds, and the post's own comments feed (VIP Cache Manager's set).
        $urls = [$permalink, home_url('/'), get_feed_link(), get_feed_link('comments_' . get_default_feed()), self::commentsFeed($post, $permalink)];
        if ($post->post_type === 'post') {
            $page = (int) get_option('page_for_posts');
            if ($page > 0) {
                $urls[] = (string) get_permalink($page);
            }
            $urls[] = Authors::archiveUrl((int) $post->post_author);
        }
        $archive = get_post_type_archive_link($post->post_type);
        if (is_string($archive)) {
            $urls[] = $archive;
        }
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);
            if (is_array($terms)) {
                array_push($urls, ...self::termUrls(array_map(static fn (\WP_Term $t): int => $t->term_taxonomy_id, $terms), $taxonomy));
            }
        }
        $urls = array_values(array_filter($urls, static fn (mixed $url): bool => is_string($url) && $url !== ''));

        // Other addresses that show this post (a `page.md` route answers with and without a trailing
        // slash), and the sitemap files that list it (core's and TypedPress's), which have no twins.
        return [...(array) apply_filters('tp_post_purge_urls', [$urls[0], ...self::listings(array_slice($urls, 1))], $post), ...Sitemap::filesFor($post)];
    }

    /**
     * Listing URLs with their next LISTING_PAGES pages (`/category/news/page/2/`); feeds have none.
     *
     * @param list<string> $urls
     * @return list<string>
     */
    private static function listings(array $urls): array
    {
        $out = $urls;
        for ($page = 2; $page <= 1 + self::LISTING_PAGES; $page++) {
            foreach ($urls as $url) {
                if (!self::feed($url)) {
                    $out[] = self::paged($url, $page);
                }
            }
        }

        return $out;
    }

    /**
     * Archive and feed URLs of terms in a public taxonomy, by term_taxonomy_id.
     *
     * @param list<int> $ttIds
     * @return list<string>
     */
    private static function termUrls(array $ttIds, string $taxonomy): array
    {
        $object = get_taxonomy($taxonomy);
        if (!$object instanceof \WP_Taxonomy || !$object->public || !$object->publicly_queryable) {
            return [];
        }
        $urls = [];
        foreach (array_unique($ttIds) as $ttId) {
            $term = get_term_by('term_taxonomy_id', (int) $ttId, $taxonomy);
            $link = $term instanceof \WP_Term ? get_term_link($term) : null;
            if (is_string($link)) {
                $urls[] = $link;
                // Plain permalinks would spell it `?feed=rss2&amp;cat=3`, escaped for HTML.
                $feed = get_term_feed_link($term->term_id, $taxonomy);
                if (is_string($feed) && $feed !== '') {
                    $urls[] = str_replace('&amp;', '&', $feed);
                }
            }
        }

        return $urls;
    }

    /**
     * The URL a post is or was published at. Trashing renames the slug (`hello__trashed`) before any
     * hook sees the post, and an unpublished post's permalink is its `?p=` form: both are spelled as
     * the published post was.
     */
    private static function permalink(\WP_Post $post): string
    {
        if ($post->post_status === 'publish' && !str_ends_with($post->post_name, '__trashed')) {
            return (string) get_permalink($post);
        }
        $published = clone $post;
        $published->post_status = 'publish';
        $desired = get_post_meta($post->ID, '_wp_desired_post_slug', true);
        $published->post_name = is_string($desired) && $desired !== '' ? $desired : (string) preg_replace('/__trashed(-\d+)?$/', '', $post->post_name);

        return (string) get_permalink($published);
    }

    /**
     * A post's comments feed, as get_post_comments_feed_link() spells it, from the URL the post is or
     * was published at (a moved or trashed post's old one). TypedPress sites always use pretty permalinks
     * (Hardening::permalinks); a front page's feed hangs off its page URL, not the home page.
     */
    private static function commentsFeed(\WP_Post $post, string $permalink): string
    {
        if (get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $post->ID) {
            $permalink = _get_page_link($post->ID);
        }

        return trailingslashit($permalink) . user_trailingslashit('feed', 'single_feed');
    }

    /** Whether a URL is a feed (`/feed/`, `/category/x/feed/`, `?feed=rss2`): no page 2, no `.md` twin. */
    private static function feed(string $url): bool
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        parse_str((string) (wp_parse_url($url, PHP_URL_QUERY) ?? ''), $query);

        return isset($query['feed']) || preg_match('#/(feed|rss2?|atom|rdf)(/[a-z0-9]+)?/?$#', $path) === 1;
    }

    /** Page $page of a listing URL, as WordPress links it. */
    private static function paged(string $url, int $page): string
    {
        global $wp_rewrite;
        if ($wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks()) {
            return user_trailingslashit(trailingslashit($url) . $wp_rewrite->pagination_base . '/' . $page, 'paged');
        }

        return add_query_arg('paged', $page, $url);
    }
}
