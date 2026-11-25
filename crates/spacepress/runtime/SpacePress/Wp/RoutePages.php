<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * Hidden, code-owned WordPress Pages for static `sp/app/` routes (docs/spec/rendering.md §7).
 *
 * Each `page.tsx` route without parameters has one Page. The router renders the route; the Page is
 * the identity the rest of WordPress uses for it, so menus, SEO plugins and `get_permalink()` see a
 * normal published Page:
 *
 * - its permalink and canonical URL are the route's URL, and `/` is the front page (`page_on_front`);
 * - it keeps one ID per route across builds (the route ID is its `_sp_route` meta and the key of
 *   the option below), so a menu item that points at it keeps working;
 * - a route with a page or layout guard stores its Page private, so search, feeds, the REST API and the
 *   sitemap never list it;
 * - it is absent from the wp-admin Pages list, its counts and REST `context=edit` lists, and
 *   `edit_post`/`delete_post` are denied on it; a write that bypasses capabilities keeps the
 *   stored fields;
 * - another URI for it (its slug, `?page_id=`) redirects to the route.
 *
 * A Page whose route is gone is moved to the trash, never deleted. A URL another published Page
 * already owns, or a front page the site chose itself, is never taken over: the route still
 * renders, without a Page, and the log says why.
 *
 * `page.md` routes own real Pages of their own ({@see ContentPages}); they are not handled here.
 */
final class RoutePages
{
    /** Who owns a Page this class wrote: the route ID, so a lost option never duplicates Pages. */
    public const META = ContentPages::META;
    /** Marks a Page as code-owned, beside `_sp_route` (which `page.md` Pages carry too). */
    public const OWNER = '_sp_code_page';

    private static ?self $instance = null;
    private static bool $writing = false;

    /** @param array<string, array{pattern: string, title: string, status: string}> $routes route ID => route */
    private function __construct(private readonly Plugin $plugin, private readonly array $routes)
    {
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function register(Plugin $plugin): void
    {
        $app = $plugin->schema->app();
        if (!is_array($app) || self::$instance !== null) {
            return;
        }
        $routes = [];
        foreach ($app['routes'] ?? [] as $route) {
            $page = $route['page'] ?? null;
            $pattern = (string) $route['pattern'];
            if (!is_string($page) || str_ends_with($page, '.md') || str_contains($pattern, ':') || str_contains($pattern, '*')) {
                continue;
            }
            $routes[(string) $route['id']] = [
                'pattern' => $pattern,
                'title' => self::title($app['files'][$page] ?? [], $pattern),
                'status' => $route['guards'] !== [] ? 'private' : 'publish',
            ];
        }
        if ($routes === []) {
            return;
        }
        $self = self::$instance = new self($plugin, $routes);
        // After the app's post types (0) and its migrations (1), beside ContentPages (2). A managed
        // build reconciles from initialize ({@see install()}); its edit protection below is registered
        // on every request either way.
        if (Runtime::installs()) {
            add_action('init', [$self, 'reconcile'], 2);
        }
        // A route's Page trashed, restored or deleted by hand: the next request checks the Pages again.
        if (!Runtime::managed()) {
            $changed = static fn (int $id) => $self->routeOf($id) !== null && InstallMarker::forget();
            add_action('transition_post_status', static fn (string $new, string $old, \WP_Post $post) => $new !== $old && $changed($post->ID), 10, 3);
            add_action('deleted_post', $changed, 10, 1);
        }
        add_filter('page_link', [$self, 'link'], 10, 2);
        add_filter('get_canonical_url', [$self, 'canonicalUrl'], 10, 2);
        add_filter('map_meta_cap', [$self, 'lock'], 10, 4);
        add_filter('wp_insert_post_data', [$self, 'preserve'], PHP_INT_MAX, 2);
        add_action('pre_get_posts', [$self, 'hideFromAdmin']);
        add_filter('rest_page_query', [$self, 'hideFromRest'], 10, 2);
        add_filter('wp_count_posts', [$self, 'count'], 10, 2);
        // Before the app router (1) serves the route: WordPress's own address for the Page goes there.
        add_action('template_redirect', [$self, 'canonical'], 0);
        // The app's sitemap and llms.txt list the route already (with its robots); the Page is not listed twice.
        add_filter('wp_sitemaps_posts_query_args', [$self, 'unlisted'], 10, 2);
        add_filter('sp_markdown_twin', fn (bool $has, \WP_Post $post): bool => $has && $self->routeOf($post->ID) === null, 10, 2);
    }

    /** The page's own literal `metadata.title`, else its last path segment; the root is "Home". */
    private static function title(array $file, string $pattern): string
    {
        $title = $file['metadata']['title'] ?? null;
        if (is_string($title) && $title !== '') {
            return $title;
        }
        if (is_array($title) && is_string($title['default'] ?? null)) {
            return $title['default'];
        }
        if ($pattern === '/') {
            return 'Home';
        }
        $last = basename($pattern);

        return ucfirst(str_replace(['-', '_'], ' ', $last));
    }

    // ------------------------------------------------------------------ state

    private function option(): string
    {
        return 'sp_' . $this->plugin->schema->name() . '_route_pages';
    }

    private function digest(): string
    {
        return substr(hash('sha256', (string) wp_json_encode($this->routes)), 0, 16);
    }

    /** @return array{digest: string, pages: array<string, int>} */
    private function state(bool $fresh = false): array
    {
        if ($fresh) {
            wp_cache_delete($this->option(), 'options');
            wp_cache_delete('alloptions', 'options');
        }
        $state = get_option($this->option());
        $state = is_array($state) ? $state : [];

        return ['digest' => (string) ($state['digest'] ?? ''), 'pages' => array_map('intval', (array) ($state['pages'] ?? []))];
    }

    /** @return array<string, int> route ID => Page ID */
    public function pages(): array
    {
        return $this->state()['pages'];
    }

    public function routeOf(int $post): ?string
    {
        $route = array_search($post, $this->pages(), true);

        return is_string($route) && isset($this->routes[$route]) ? $route : null;
    }

    // ------------------------------------------------------------------ reconciling

    /** Reconciles now, for a managed build's initialize; nothing without route Pages. */
    public static function install(): void
    {
        self::$instance?->reconcile();
    }

    /**
     * What {@see install()} would write, read without writing: one step per Page {@see apply()} would
     * create, change, restore from the trash or trash, and one for the front-page options when they
     * would change. Empty when the Pages match this build. A Page apply() would leave alone (another
     * Page holds the address) is no step.
     *
     * @return list<array{kind: string, id: string, state: string}>
     */
    public static function plan(): array
    {
        $self = self::$instance;
        if ($self === null || $self->current(true)) {
            return [];
        }
        $mapped = $self->state(true)['pages'];
        $steps = [];
        $root = null;
        foreach ($self->routes as $id => $route) {
            $post = isset($mapped[$id]) ? get_post($mapped[$id]) : null;
            if (!$post instanceof \WP_Post || $post->post_type !== 'page') {
                $post = $self->adopt((string) $id);
            }
            if ($self->foreignOwner($route['pattern'], $post?->ID) !== null) {
                continue;
            }
            $wanted = ['post_status' => $route['status'], 'post_title' => $route['title'], 'post_content' => '',
                'post_name' => $route['pattern'] === '/' ? 'spacepress-home' : basename($route['pattern']), 'post_parent' => 0];
            if (!$post instanceof \WP_Post) {
                $steps[] = Runtime::step('page.create', 'route:' . $id);
            } elseif ($post->post_status === 'trash') {
                $steps[] = Runtime::step('page.restore', 'route:' . $id, ['post' => $post->ID]);
            } elseif (!isset($mapped[$id]) || get_post_meta($post->ID, self::OWNER, true) !== '1'
                || array_diff_assoc(array_map('strval', $wanted), array_map('strval', array_intersect_key(get_object_vars($post), $wanted))) !== []) {
                $steps[] = Runtime::step('page.update', 'route:' . $id, ['post' => $post->ID]);
            }
            if ($route['pattern'] === '/' && $route['status'] === 'publish') {
                $root = $post?->ID ?? 0;
            }
        }
        foreach ($mapped as $id => $post) {
            if (!isset($self->routes[$id]) && get_post($post) instanceof \WP_Post && get_post_status($post) !== 'trash') {
                $steps[] = Runtime::step('page.trash', 'route:' . $id, ['post' => $post]);
            }
        }
        if ($root !== null && ($root === 0 || get_option('show_on_front') !== 'page' || (int) get_option('page_on_front') !== $root)) {
            $steps[] = Runtime::step('front-page', 'front-page');
        }

        return $steps;
    }

    /** init: the Pages match this build's routes, once per build (or after something removed one). */
    public function reconcile(): void
    {
        if ($this->plugin->unavailable() !== null || $this->current()) {
            return;
        }
        Migrator::upgrade($this->plugin->connection(), $this->option(), fn (): bool => $this->current(true), function (): void {
            self::$writing = true;
            try {
                $this->apply();
            } finally {
                self::$writing = false;
            }
        });
    }

    private function current(bool $fresh = false): bool
    {
        $state = $this->state($fresh);
        if ($state['digest'] !== $this->digest()) {
            return false;
        }
        foreach ($this->routes as $id => $route) {
            $post = isset($state['pages'][$id]) ? get_post($state['pages'][$id]) : null;
            if ($post instanceof \WP_Post && ($post->post_type !== 'page' || $post->post_status !== $route['status'])) {
                return false;
            }
        }

        return true;
    }

    private function apply(): void
    {
        $state = $this->state(true);
        $mapped = $state['pages'];
        $wanted = [];
        foreach ($this->routes as $id => $route) {
            $post = isset($mapped[$id]) ? get_post($mapped[$id]) : null;
            if (!$post instanceof \WP_Post || $post->post_type !== 'page') {
                $post = $this->adopt($id);
            }
            $owner = $this->foreignOwner($route['pattern'], $post?->ID);
            if ($owner !== null) {
                error_log("[spacepress] the route {$route['pattern']} has no Page: WordPress Page #$owner already has that address");
                if ($post instanceof \WP_Post) {
                    $wanted[$id] = $post->ID;
                }
                continue;
            }
            $fields = [
                'post_type' => 'page',
                'post_status' => $route['status'],
                'post_title' => $route['title'],
                'post_content' => '',
                'post_name' => $route['pattern'] === '/' ? 'spacepress-home' : basename($route['pattern']),
                'post_parent' => 0,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ];
            $result = $post instanceof \WP_Post
                ? wp_update_post(wp_slash(['ID' => $post->ID] + $fields), true)
                : wp_insert_post(wp_slash($fields + ['meta_input' => [self::META => $id, self::OWNER => '1']]), true);
            if ($result instanceof \WP_Error) {
                throw new \RuntimeException("cannot store the Page for {$route['pattern']}: " . $result->get_error_message());
            }
            update_post_meta((int) $result, self::META, $id);
            update_post_meta((int) $result, self::OWNER, '1');
            $wanted[$id] = (int) $result;
        }
        // A route that is gone: its Page goes to the trash, never deleted.
        foreach ($mapped as $id => $post) {
            if (!isset($this->routes[$id]) && get_post($post) instanceof \WP_Post && get_post_status($post) !== 'trash') {
                wp_trash_post($post);
            }
        }
        update_option($this->option(), ['digest' => $this->digest(), 'pages' => $wanted], true);
        $root = null;
        foreach ($this->routes as $id => $route) {
            if ($route['pattern'] === '/' && $route['status'] === 'publish' && isset($wanted[$id])) {
                $root = $wanted[$id];
            }
        }
        if ($root !== null && $this->foreignOwner('/', $root) === null) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $root);
        }
    }

    /** A Page this app wrote for the route before the site lost its record of it. */
    private function adopt(string $id): ?\WP_Post
    {
        $found = get_posts([
            'post_type' => 'page', 'post_status' => ['publish', 'private', 'draft', 'pending', 'trash'], 'numberposts' => 1,
            'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true,
            'meta_query' => [['key' => self::META, 'value' => $id], ['key' => self::OWNER, 'value' => '1']],
        ]);

        return $found[0] ?? null;
    }

    /** Another published Page at this path, or a front page the site chose that isn't this app's. */
    private function foreignOwner(string $pattern, ?int $mine): ?int
    {
        $ours = array_values($this->state()['pages']);
        if ($mine !== null) {
            $ours[] = $mine;
        }
        if ($pattern === '/') {
            $front = (int) get_option('page_on_front');
            $isOurs = in_array($front, $ours, true) || get_post_meta($front, self::OWNER, true) === '1';

            return get_option('show_on_front') === 'page' && $front > 0 && get_post($front) instanceof \WP_Post && !$isOurs ? $front : null;
        }
        $page = get_page_by_path(ltrim($pattern, '/'), OBJECT, 'page');
        if ($page instanceof \WP_Post && $page->post_status === 'publish' && !in_array($page->ID, $ours, true)
            && get_post_meta($page->ID, self::OWNER, true) !== '1') {
            return $page->ID;
        }

        return null;
    }

    // ------------------------------------------------------------------ code ownership

    /** @param list<string> $caps */
    public function lock(array $caps, string $cap, int $user, array $args): array
    {
        if (self::$writing || !in_array($cap, ['edit_post', 'delete_post', 'edit_page', 'delete_page', 'publish_post'], true)) {
            return $caps;
        }

        return $this->routeOf((int) ($args[0] ?? 0)) !== null ? ['do_not_allow'] : $caps;
    }

    /** A write that bypassed capability checks keeps the Page as this class stored it. */
    public function preserve(array $data, array $postarr): array
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if (self::$writing || $id === 0 || $this->routeOf($id) === null) {
            return $data;
        }
        $stored = get_post($id);
        if ($stored instanceof \WP_Post) {
            foreach (['post_content', 'post_title', 'post_status', 'post_name', 'post_parent', 'post_type', 'post_password'] as $field) {
                $data[$field] = $stored->$field;
            }
        }

        return $data;
    }

    public function hideFromAdmin(\WP_Query $query): void
    {
        if (!is_admin() || self::$writing || $query->get('post_type') !== 'page' || $this->pages() === []) {
            return;
        }
        $query->set('post__not_in', [...(array) $query->get('post__not_in'), ...array_values($this->pages())]);
    }

    /** @param array<string, mixed> $args */
    public function hideFromRest(array $args, \WP_REST_Request $request): array
    {
        if ($request->get_param('context') === 'edit' && $this->pages() !== []) {
            $args['post__not_in'] = [...($args['post__not_in'] ?? []), ...array_values($this->pages())];
        }

        return $args;
    }

    public function count(object $counts, string $type): object
    {
        if ($type !== 'page' || !is_admin()) {
            return $counts;
        }
        $counts = clone $counts;
        foreach ($this->pages() as $id) {
            $status = get_post_status($id);
            if (is_string($status) && isset($counts->$status)) {
                $counts->$status = max(0, (int) $counts->$status - 1);
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function unlisted(array $args, string $type): array
    {
        if ($type === 'page' && $this->pages() !== []) {
            $args['post__not_in'] = [...($args['post__not_in'] ?? []), ...array_values($this->pages())];
        }

        return $args;
    }

    // ------------------------------------------------------------------ URLs

    public function link(string $link, int $post): string
    {
        $route = $this->routeOf($post);

        return $route === null ? $link : home_url(AppRouter::address($this->routes[$route]['pattern']));
    }

    public function canonicalUrl(string|false $url, \WP_Post $post): string|false
    {
        $route = $this->routeOf($post->ID);

        return $route === null ? $url : home_url(AppRouter::address($this->routes[$route]['pattern']));
    }

    /** template_redirect: WordPress's own address for a code Page (its slug, `?page_id=`) goes to the route. */
    public function canonical(): void
    {
        $route = (is_page() || is_front_page()) ? $this->routeOf((int) get_queried_object_id()) : null;
        if ($route === null) {
            return;
        }
        $have = '/' . trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $path = $base !== '' && str_starts_with($have, $base) ? ('/' . ltrim(substr($have, strlen($base)), '/')) : $have;
        $query = wp_unslash(array_filter($_GET, 'is_string'));
        $own = array_intersect_key($query, ['page_id' => 1, 'p' => 1, 'pagename' => 1]);
        if (rawurldecode(rtrim($path, '/') ?: '/') === $this->routes[$route]['pattern'] && $own === []) {
            return;
        }
        wp_safe_redirect(add_query_arg(urlencode_deep(array_diff_key($query, $own)), home_url(AppRouter::address($this->routes[$route]['pattern']))), 301);
        exit;
    }
}
