<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\Html;
use TypedPress\JsObject;
use TypedPress\Render\Halt;
use TypedPress\Seam;
use TypedPress\Sync\Markdown;

/**
 * `page.md` routes under `tp/app/`: content-owned pages (docs/spec/rendering.md §2,
 * docs/decisions/2026-11-13-content-pages.md). Each one is a real WordPress Page.
 *
 * The file is the Page's first version. The first request after a build stores each new file
 * version once: the Markdown becomes block markup, the front matter the title, excerpt and status.
 * After that the Page belongs to the site, and an editor changes it in wp-admin like any other.
 *
 * - A file version is applied once. A later version of the file replaces the Page only while
 *   nobody has edited the Page since the last one was stored; an edited (or trashed) Page keeps
 *   the site's version, except that a file which newly makes the page non-public unpublishes it.
 * - Nothing is ever deleted here: removing `page.md` leaves its Page as an ordinary WordPress Page.
 * - The route owns the URL. The Page's permalink is the route's path, whatever its slug or parent,
 *   and WordPress's own address for it redirects there.
 *
 * The app router serves the route like any other page. Its component is html() and its
 * `metadata` is metadata(), so layouts, guards, the cache policy and the Markdown twin apply
 * unchanged. Who sees what: a published Page, anyone; a Page under a layout `guard` (stored
 * private), whoever the guard admits; anything else (a draft, a Page an editor made private or
 * scheduled), only a viewer WordPress lets read it. A password set in wp-admin locks the body
 * for everyone but its editors: an app page is cached for all visitors, so it never shows an
 * unlocked body to one of them.
 */
final class ContentPages
{
    /** The Page's route id, so the Page can be found again if the option below is lost. */
    public const META = '_tp_route';
    /** File versions and build tables remembered, so two live builds never undo each other. */
    private const HISTORY = 8;

    private static ?self $instance = null;
    /** @var array<int, string> post id => route id, for this build's routes */
    private ?array $byPost = null;

    /** @param array<string, array{path: string, slug: string, title: string, description: ?string, status: string, digest: string}> $pages */
    private function __construct(private readonly Plugin $plugin, private readonly array $pages)
    {
    }

    public static function register(Plugin $plugin): void
    {
        $pages = $plugin->schema->app()['content'] ?? [];
        if ($pages === [] || self::$instance !== null) {
            return;
        }
        $self = self::$instance = new self($plugin, $pages);
        // After the app's post types (0) and its migrations (1). A managed build stores them from
        // initialize ({@see install()}): booting it writes nothing.
        if (Runtime::installs()) {
            add_action('init', [$self, 'store'], 2);
        }
        add_filter('page_link', [$self, 'link'], 10, 2);
        add_action('template_redirect', [$self, 'canonical'], 0);
        // The route renders the twin; the stored Page is listed once, under the app's pages.
        add_filter('tp_markdown_twin', static fn (bool $has, \WP_Post $post): bool => $has && $self->routeOf($post->ID) === null, 10, 2);
    }

    // ------------------------------------------------------------------ storing

    private function option(): string
    {
        return 'tp_' . $this->plugin->schema->name() . '_content';
    }

    /** @return array{builds: list<string>, pages: array<string, array{post: int, applied: list<string>, stored: string}>} */
    private function state(bool $fresh = false): array
    {
        if ($fresh) {
            wp_cache_delete($this->option(), 'options');
            wp_cache_delete('alloptions', 'options');
        }
        $state = get_option($this->option());

        return is_array($state) ? $state + ['builds' => [], 'pages' => []] : ['builds' => [], 'pages' => []];
    }

    private function build(): string
    {
        return substr(hash('sha256', (string) wp_json_encode($this->pages)), 0, 16);
    }

    /** Stores this build's file versions now, for a managed build's initialize; nothing without content pages. */
    public static function install(): void
    {
        self::$instance?->store();
    }

    /**
     * What {@see install()} would write, read without writing: one step per Page it would create,
     * change, adopt or hide, as {@see apply()} decides them. Empty when this build's versions are
     * stored. A file this build hasn't stored yet that changes no Page (the site edited it) records
     * only the history option, which is not a step.
     *
     * @return list<array{kind: string, id: string, state: string}>
     */
    public static function plan(): array
    {
        $self = self::$instance;
        if ($self === null || in_array($self->build(), $self->state(true)['builds'], true)) {
            return [];
        }
        $state = $self->state(true);
        $steps = [];
        foreach ($self->pages as $route => $page) {
            $entry = $state['pages'][$route] ?? null;
            if (in_array($page['digest'], $entry['applied'] ?? [], true)) {
                continue;
            }
            $was = $entry['status'] ?? 'publish';
            $post = $entry !== null ? get_post($entry['post']) : $self->lost($route);
            $hides = $post instanceof \WP_Post && $was === 'publish' && $page['status'] !== 'publish' && $post->post_status === 'publish';
            if ($entry === null && $post instanceof \WP_Post) {
                $steps[] = Runtime::step($hides ? 'page.adopt-hide' : 'page.adopt', 'content:' . $route, ['post' => $post->ID]);
            } elseif ($entry !== null && (!$post instanceof \WP_Post || self::fingerprint($post) !== $entry['stored'])) {
                if ($hides) {
                    $steps[] = Runtime::step('page.hide', 'content:' . $route, ['post' => $post->ID]);
                }
            } else {
                $steps[] = $post instanceof \WP_Post ? Runtime::step('page.update', 'content:' . $route, ['post' => $post->ID]) : Runtime::step('page.create', 'content:' . $route);
            }
        }

        return $steps;
    }

    /** init: stores this build's file versions, once, whichever request comes first. */
    public function store(): void
    {
        if ($this->plugin->unavailable() !== null || in_array($this->build(), $this->state()['builds'], true)) {
            return;
        }
        Migrator::upgrade(
            $this->plugin->connection(),
            $this->option(),
            fn (): bool => in_array($this->build(), $this->state(true)['builds'], true),
            fn () => $this->apply(),
        );
        $this->byPost = null;
    }

    private function apply(): void
    {
        $bodies = require $this->plugin->dir . '/tp/app/content.php';
        $state = $this->state(true);
        foreach ($this->pages as $route => $page) {
            $entry = $state['pages'][$route] ?? null;
            $applied = $entry['applied'] ?? [];
            if (in_array($page['digest'], $applied, true)) {
                continue;
            }
            $file = 'the page.md at ' . $route;
            $history = array_slice([...$applied, $page['digest']], -self::HISTORY);
            // What the last applied file said: a page only becomes non-public when it was public.
            // A record from before statuses were kept, or an adopted Page, counts as public.
            $was = $entry['status'] ?? 'publish';
            $post = $entry !== null ? get_post($entry['post']) : $this->lost($route);
            if ($entry === null && $post instanceof \WP_Post) {
                // A Page this route stored before the site lost its record of it: adopted as it is.
                $state['pages'][$route] = ['post' => $post->ID, 'applied' => $history, 'stored' => '', 'status' => $page['status']];
                $this->hide($post, $was, $page['status']);
                $this->keep($state);
                continue;
            }
            if ($entry !== null && (!$post instanceof \WP_Post || self::fingerprint($post) !== $entry['stored'])) {
                // Someone changed the Page (or trashed or deleted it): the site's version stays.
                error_log("[typedpress] $file changed, but its Page was edited on the site; the site keeps its version");
                $state['pages'][$route]['applied'] = $history;
                $state['pages'][$route]['status'] = $page['status'];
                if ($post instanceof \WP_Post) {
                    $this->hide($post, $was, $page['status']);
                }
                $this->keep($state);
                continue;
            }
            try {
                $content = Markdown::toBlocks((string) ($bodies[$route] ?? ''));
            } catch (\TypedPress\HttpError $error) {
                // The same file fails the same way on every request: report it once per build.
                error_log("[typedpress] $file was not stored: " . $error->getMessage());
                continue;
            }
            $fields = ['post_title' => $page['title'], 'post_excerpt' => (string) ($page['description'] ?? ''), 'post_content' => $content, 'post_status' => $page['status']];
            $id = self::save($post instanceof \WP_Post ? ['ID' => $post->ID] + $fields : $fields + [
                'post_type' => 'page', 'post_name' => $page['slug'], 'comment_status' => 'closed', 'ping_status' => 'closed',
                'meta_input' => [self::META => $route],
            ]);
            $state['pages'][$route] = ['post' => $id, 'applied' => $history, 'stored' => self::fingerprint(get_post($id)), 'status' => $page['status']];
            // Each Page as it lands: a failure later in the loop leaves no Page the record doesn't know.
            $this->keep($state);
        }
        $state['builds'] = array_slice([...$state['builds'], $this->build()], -self::HISTORY);
        $this->keep($state);
    }

    private function keep(array $state): void
    {
        update_option($this->option(), $state, true);
    }

    /**
     * The one change a new file version makes to a Page the site edited: when the file newly says
     * the page is not public (`status: draft`, or a layout `guard` above it), a published Page
     * takes that status. Its content stays the site's, and an editor can publish it again; without
     * this, WordPress's REST API and sitemap would keep showing a page the code took away.
     */
    private function hide(\WP_Post $post, string $was, string $status): void
    {
        if ($was === 'publish' && $status !== 'publish' && $post->post_status === 'publish') {
            self::save(['ID' => $post->ID, 'post_status' => $status]);
        }
    }

    /** A Page stored for this route by a site whose option was lost: found by its meta, any status. */
    private function lost(string $route): ?\WP_Post
    {
        $found = get_posts([
            'post_type' => 'page', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash'], 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC',
            'meta_key' => self::META, 'meta_value' => $route, 'suppress_filters' => true,
        ]);

        return $found[0] ?? null;
    }

    /**
     * Writes the Page as the build has it. The Markdown is the developer's own, like the code, so
     * it is stored whole whoever's request triggers the write (kses would otherwise run for a
     * signed-out visitor).
     *
     * @param array<string, mixed> $post
     */
    private static function save(array $post): int
    {
        kses_remove_filters();
        try {
            $id = isset($post['ID']) ? wp_update_post(wp_slash($post), true) : wp_insert_post(wp_slash($post), true);
        } finally {
            kses_init();
        }
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('cannot store a content page: ' . $id->get_error_message());
        }

        return (int) $id;
    }

    /** What an editor can change: any difference from the stored value means the site edited it. */
    private static function fingerprint(?\WP_Post $post): string
    {
        return $post === null ? '' : substr(hash('sha256', (string) wp_json_encode([$post->post_title, $post->post_excerpt, $post->post_content, $post->post_status])), 0, 16);
    }

    // ------------------------------------------------------------------ lookups

    private function routeOf(int $post): ?string
    {
        if ($this->byPost === null) {
            $this->byPost = [];
            foreach ($this->state()['pages'] as $route => $entry) {
                if (isset($this->pages[$route])) {
                    $this->byPost[(int) $entry['post']] = (string) $route;
                }
            }
        }

        return $this->byPost[$post] ?? null;
    }

    private function post(string $route): ?\WP_Post
    {
        $id = $this->state()['pages'][$route]['post'] ?? null;
        $post = $id === null ? null : get_post((int) $id);

        return $post instanceof \WP_Post && $post->post_type === 'page' ? $post : null;
    }

    /** The Page a route shows this viewer, or null when they may not see one. */
    private function visible(string $route): ?\WP_Post
    {
        $post = $this->post($route);
        if ($post === null || $post->post_status === 'trash') {
            return null;
        }
        $editor = self::editor($post);
        // A guarded route stores its Page private; the router's guard already admitted this viewer.
        $public = $post->post_status === 'publish' || ($post->post_status === 'private' && ($this->pages[$route]['status'] ?? null) === 'private');
        if (!$public && !$editor) {
            return null;
        }
        // The editor's Preview button, as core checks it: their unsaved changes (the autosave).
        if ($editor && (int) ($_GET['preview_id'] ?? 0) === $post->ID && wp_verify_nonce((string) ($_GET['preview_nonce'] ?? ''), 'post_preview_' . $post->ID) !== false) {
            _set_preview($post);
        }

        return $post;
    }

    /** Whether the render's viewer may edit the Page, so may see it in any status. */
    private static function editor(\WP_Post $post): bool
    {
        $user = Seam::viewer()?->user['id'] ?? null;

        return $user !== null && user_can((int) $user, 'edit_post', $post->ID);
    }

    // ------------------------------------------------------------------ rendering

    /** The compiled component of a `page.md` route: the Page's title and content as WordPress renders them. */
    public static function html(string $route): Html
    {
        $post = self::$instance?->visible($route) ?? Halt::notFound();
        $title = '<h1 class="wp-block-post-title">' . Html::escape($post->post_title) . '</h1>';
        if ($post->post_password !== '' && !self::editor($post)) {
            return Html::raw($title . '<div class="entry-content wp-block-post-content"><p>' . esc_html__('This content is password protected.') . '</p></div>');
        }

        return Html::raw($title . '<div class="entry-content wp-block-post-content">' . self::content($post) . '</div>');
    }

    /** `the_content` for the Page, with the post set up as on its own WordPress template. */
    private static function content(\WP_Post $subject): string
    {
        global $post;
        $previous = $post ?? null;
        $post = $subject;
        setup_postdata($subject);
        try {
            return (string) apply_filters('the_content', $subject->post_content);
        } finally {
            $post = $previous;
            if ($previous instanceof \WP_Post) {
                setup_postdata($previous);
            } else {
                wp_reset_postdata();
            }
        }
    }

    /**
     * The route's `metadata`: the stored title and excerpt, so an editor's change shows; the
     * file's `robots`; and its `canonical`, or the route's own path. Nothing of a Page the viewer
     * may not see.
     *
     * @param array<string, mixed> $fixed
     */
    public static function metadata(string $route, array $fixed): JsObject
    {
        $out = array_map(static fn (mixed $v): mixed => is_array($v) ? new JsObject($v) : $v, $fixed);
        $post = self::$instance?->visible($route);
        if ($post !== null) {
            $out['title'] = $post->post_title;
            $out['canonical'] ??= AppRouter::address(self::$instance->pages[$route]['path']);
            if ($post->post_excerpt !== '' && $post->post_password === '') {
                $out['description'] = $post->post_excerpt;
            }
        }

        return new JsObject($out);
    }

    // ------------------------------------------------------------------ URLs

    /** `page_link`: the route's path, as the route and `<Link>` spell it, is the Page's permalink. */
    public function link(string $link, int $post): string
    {
        $route = $this->routeOf($post);

        return $route === null ? $link : $this->url($route);
    }

    /** The route's one address (`/about/`, AppRouter::address), which the Page's permalink is. */
    private function url(string $route): string
    {
        return home_url(AppRouter::address($this->pages[$route]['path']));
    }

    /** template_redirect: WordPress's own address for the Page (its slug, `?page_id=`) goes to the route. */
    public function canonical(): void
    {
        $route = is_page() ? $this->routeOf((int) get_queried_object_id()) : null;
        if ($route === null) {
            return;
        }
        $have = '/' . trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        $want = '/' . trim((string) wp_parse_url($this->url($route), PHP_URL_PATH), '/');
        $query = wp_unslash(array_filter($_GET, 'is_string'));
        $own = array_intersect_key($query, ['page_id' => 1, 'p' => 1, 'pagename' => 1]);
        // Core found the Page by its slug (`/colophon`): if one of the app's own page routes
        // serves that path, the path is that route's, whatever Page core resolved it to.
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $path = $base !== '' && str_starts_with($have, $base) ? (substr($have, strlen($base)) ?: '/') : $have;
        $routes = $this->plugin->schema->app()['routes'] ?? [];
        if ($own === [] && rawurldecode($have) !== rawurldecode($want) && (AppRouter::match($routes, $path)['route']['page'] ?? null) !== null) {
            return;
        }
        if (rawurldecode($have) === rawurldecode($want) && $own === []) {
            return;
        }
        wp_safe_redirect(add_query_arg(urlencode_deep(array_diff_key($query, $own)), $this->url($route)), 301);
        exit;
    }
}
