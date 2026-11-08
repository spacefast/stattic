<?php

declare(strict_types=1);

namespace Zero\Pages;

/**
 * The pages plugin: route claiming, head, assets, region blocks and the
 * write locks that keep code-owned Pages code-owned.
 *
 * Request flow for a claimed URL, at `template_redirect` priority 0 (before
 * core's canonical redirects):
 *
 * 1. Match the path against the route table (static first).
 * 2. For an item route, read the row by slug *as the visitor*. Missing,
 *    draft-for-this-visitor or password-bearing rows become a real 404 with
 *    the not-found page; nothing about the hidden row is revealed.
 * 3. Run the route's queries as the visitor, resolve metadata, render the
 *    body into a buffer (so blocks can enqueue assets), then emit the
 *    document with `wp_head`/`wp_footer`.
 *
 * Every managed response sends `Cache-Control: private, no-store`: rendered
 * HTML depends on the viewer, and no shared cache may keep it.
 */
final class Plugin
{
    private static ?self $instance = null;
    public readonly Routes $routes;
    /** @var array<string, mixed> */
    private array $manifest;
    private string $directory;
    private string $url;
    private ?Request $request = null;
    /** @var array<string, true> */
    private array $islands = [];
    private ?string $title = null;

    public static function boot(string $mainFile, string $app): void
    {
        if (self::$instance !== null) {
            return;
        }
        $directory = dirname($mainFile);
        $manifest = json_decode((string) file_get_contents($directory . '/manifest.json'), true);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ($manifest['app'] ?? null) !== $app) {
            add_action('admin_notices', static fn () => printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('Zero pages: the build manifest is missing or for another app; rebuild with zero-pages.')));
            return;
        }
        self::$instance = new self($directory, plugin_dir_url($mainFile), $manifest);
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /** @param array<string, mixed> $manifest */
    private function __construct(string $directory, string $url, array $manifest)
    {
        $this->directory = $directory;
        $this->url = $url;
        $this->manifest = $manifest;
        $this->routes = new Routes($manifest);

        add_action('init', [$this, 'registerBlocks']);
        add_action('init', [$this, 'reconcile'], 20);
        add_action('init', [$this, 'guardWrites'], 30);
        add_action('template_redirect', [$this, 'claim'], 0);
        add_filter('redirect_canonical', [$this, 'canonicalRedirect'], 10, 2);
        add_filter('page_link', [$this, 'pageLink'], 10, 2);
        add_filter('post_type_link', [$this, 'itemLink'], 10, 3);
        add_filter('get_canonical_url', [$this, 'canonicalUrl'], 10, 2);
        // Hidden outside the editing workspace: admin lists, edit-context REST and pickers.
        add_action('pre_get_posts', [$this, 'hideFromAdmin']);
        add_filter('rest_page_query', [$this, 'hideFromRest'], 10, 2);
        add_filter('wp_count_posts', [$this, 'countPosts'], 10, 2);
        add_filter('map_meta_cap', [$this, 'lockCodePages'], 10, 4);
        add_filter('wp_insert_post_data', [$this, 'preserveCodePages'], PHP_INT_MAX, 4);
        add_filter('wp_insert_post_data', [$this, 'refuseReservedSlug'], PHP_INT_MAX - 1, 4);
        add_filter('the_content', fn (string $html): string => Renderer::current() === null ? Renderer::strip($html, $this->assetBase()) : $html, PHP_INT_MAX);
        add_action('admin_notices', [$this, 'conflictNotice']);
        add_action('admin_notices', [$this, 'reconciliationNotice']);
    }

    public function reconciliationNotice(): void
    {
        $error = $this->routes->reconciliationError();
        if ($error === null || !current_user_can('manage_options')) {
            return;
        }
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(
            'Zero pages could not update its managed Pages. ' . $error . ' WordPress remains available; Zero will retry on the next request.',
        ));
    }

    public function conflictNotice(): void
    {
        $conflicts = $this->routes->conflicts();
        if ($conflicts === [] || !current_user_can('manage_options')) {
            return;
        }
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(sprintf(
            'Zero pages: %s already belong to other WordPress content, so Zero does not serve them. Rename the route or move that content.',
            implode(', ', $conflicts),
        )));
    }

    public function db(): \Zero\Db
    {
        $plugin = \Zero\Wp\Runtime::plugin() ?? throw new \RuntimeException('Zero app is not active');
        return $plugin->db();
    }

    public function assetBase(): string
    {
        return rtrim($this->url, '/') . '/assets';
    }

    // ------------------------------------------------------------------ setup

    public function registerBlocks(): void
    {
        foreach (['each', 'show', 'island', 'rich-text'] as $name) {
            register_block_type('zero-pages/' . $name, [
                'api_version' => 3,
                'title' => 'Zero page region',
                'category' => 'design',
                'attributes' => ['region' => ['type' => 'string']],
                'supports' => ['inserter' => false, 'html' => false, 'reusable' => false],
                'skip_inner_blocks' => true,
                'render_callback' => static function (array $attributes, string $content, \WP_Block $block): string {
                    $renderer = Renderer::current();
                    // Outside a page request (a preview, a feed, another plugin rendering the stored body)
                    // a region has no viewer-bound data to show, so it renders nothing.
                    return $renderer === null ? '' : $renderer->region($attributes, $block);
                },
            ]);
        }
    }

    public function reconcile(): void
    {
        if (\Zero\Wp\Runtime::plugin() === null || \Zero\Wp\Runtime::plugin()->unavailable() !== null) {
            return;
        }
        $this->routes->reconcile($this->directory);
    }

    /** The reserved-slug guard on Zero's own write path (REST, CLI, hooks). */
    public function guardWrites(): void
    {
        $reserved = $this->routes->reservedByCollection();
        if ($reserved === []) {
            return;
        }
        \Zero\Extensions::onWrite(function (string $op, string $collection, array &$data) use ($reserved): void {
            if ($op === 'delete' || !isset($reserved[$collection])) {
                return;
            }
            $column = $this->db()->schema()->collection($collection)['slug'] ?? null;
            if ($column !== null && isset($data[$column]) && in_array((string) $data[$column], $reserved[$collection], true)) {
                throw new \Zero\HttpError(409, 'reserved_slug', sprintf('"%s" is the URL of a page; choose another slug', (string) $data[$column]), '/' . $column);
            }
        });
    }

    /** The same guard for core writes (wp-admin, /wp/v2): the slug gets the first free suffix instead of colliding. */
    public function refuseReservedSlug(array $data, array $postarr, array $unsanitized, bool $update): array
    {
        $type = (string) ($data['post_type'] ?? '');
        $reserved = $this->routes->reservedSlugs()[$type] ?? [];
        $slug = (string) ($data['post_name'] ?? '');
        if ($reserved === [] || !in_array($slug, $reserved, true)) {
            return $data;
        }
        global $wpdb;
        $id = (int) ($postarr['ID'] ?? 0);
        for ($n = 2; ; $n++) {
            $candidate = $slug . '-' . $n;
            $taken = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND ID <> %d LIMIT 1",
                $type, $candidate, $id,
            ));
            if ($taken === null && !in_array($candidate, $reserved, true)) {
                $data['post_name'] = $candidate;
                return $data;
            }
        }
    }

    // ------------------------------------------------------------------ code-owned Pages

    public function lockCodePages(array $caps, string $cap, int $userId, array $args): array
    {
        if (!in_array($cap, ['edit_post', 'delete_post', 'edit_page', 'delete_page', 'publish_post'], true) || Routes::reconciling()) {
            return $caps;
        }
        $postId = (int) ($args[0] ?? 0);
        return $this->routes->isCodePage($postId) ? ['do_not_allow'] : $caps;
    }

    /** Defense in depth: an update that bypassed capability checks keeps the code-owned fields. */
    public function preserveCodePages(array $data, array $postarr, array $unsanitized, bool $update): array
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if (!$update || Routes::reconciling() || !$this->routes->isCodePage($id)) {
            return $data;
        }
        $stored = get_post($id);
        if (!$stored instanceof \WP_Post) {
            return $data;
        }
        foreach (['post_content', 'post_title', 'post_status', 'post_name', 'post_parent', 'post_type', 'post_password'] as $field) {
            $data[$field] = $stored->$field;
        }
        return $data;
    }

    public function hideFromAdmin(\WP_Query $query): void
    {
        if (!is_admin() || Routes::reconciling() || $query->get('post_type') !== 'page') {
            return;
        }
        $ids = array_values($this->routes->pages());
        if ($ids !== []) {
            $query->set('post__not_in', array_merge((array) $query->get('post__not_in'), $ids));
        }
    }

    public function hideFromRest(array $args, \WP_REST_Request $request): array
    {
        if ($request->get_param('context') !== 'edit') {
            return $args;
        }
        $ids = array_values($this->routes->pages());
        if ($ids !== []) {
            $args['post__not_in'] = array_merge($args['post__not_in'] ?? [], $ids);
        }
        return $args;
    }

    public function countPosts(object $counts, string $type): object
    {
        if ($type === 'page' && is_admin() && isset($counts->publish)) {
            $counts->publish = max(0, (int) $counts->publish - count($this->routes->pages()));
        }
        return $counts;
    }

    // ------------------------------------------------------------------ URLs

    public function pageLink(string $link, int $postId): string
    {
        $route = $this->routes->routeForPage($postId);
        return $route === null ? $link : $this->routes->urlFor((string) $route['pattern']);
    }

    public function canonicalUrl(string|false $url, \WP_Post $post): string|false
    {
        $route = $this->routes->routeForPage($post->ID);
        return $route === null ? $url : $this->routes->urlFor((string) $route['pattern']);
    }

    public function itemLink(string $link, \WP_Post $post, bool $leaveName): string
    {
        $route = $this->routes->itemRoute($post->post_type);
        if ($route === null || $post->post_name === '') {
            return $link;
        }
        $url = $this->routes->itemUrl((string) $route['collection'], [(string) $route['slugColumn'] => $leaveName ? '%postname%' : $post->post_name]);
        return $url === null ? $link : str_replace('%25postname%25', '%postname%', $url);
    }

    /** Core must not "fix" a claimed URL toward a Page's natural URI. */
    public function canonicalRedirect(string|false $redirect, string $requested): string|false
    {
        return $this->request !== null ? false : $redirect;
    }

    // ------------------------------------------------------------------ requests

    private function requestPath(): ?string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '/');
        $home = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/');
        if (!str_starts_with($path, $home) && rtrim($path, '/') !== rtrim($home, '/')) {
            return null;
        }
        return '/' . ltrim(substr($path, strlen(rtrim($home, '/'))), '/');
    }

    public function claim(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return;
        }
        if (is_feed() || is_robots() || is_trackback() || is_favicon()) {
            return;
        }
        $path = $this->requestPath();
        if ($path === null) {
            return;
        }
        $match = $this->routes->match($path);
        // A code Page reached through another WordPress URI (`/?page_id=`, its natural slug path) goes to its route URL.
        $queried = get_queried_object();
        if ($queried instanceof \WP_Post && ($owner = $this->routes->routeForPage($queried->ID)) !== null
            && ($match === null || $match['route']['id'] !== $owner['id'])) {
            wp_safe_redirect($this->routes->urlFor((string) $owner['pattern']), 301);
            exit;
        }
        if ($match !== null && $match['route']['param'] !== null && $queried instanceof \WP_Post
            && $queried->post_type === 'page' && !$this->routes->isCodePage($queried->ID)) {
            return;
        }
        if ($match === null || !$this->routes->canServe($match['route'])) {
            return;
        }
        // The root route serves the front page only; `/` also carries search, feeds and `?p=` queries.
        if ($match['route']['pattern'] === '/' && !is_front_page()) {
            return;
        }
        if (\Zero\Wp\Runtime::plugin() === null || \Zero\Wp\Runtime::plugin()->unavailable() !== null) {
            $this->respond(503, null, '<p>' . esc_html__('This page is temporarily unavailable.') . '</p>');
        }
        $route = $match['route'];
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        $viewer = \Zero\Wp\CoreAccess::viewer();
        if ($viewer === null) {
            // A suspended account is refused everywhere; render nothing for it.
            $this->notFound();
        }
        $item = null;
        if ($route['param'] !== null) {
            $item = $this->item($route, (string) $match['params'][$route['param']], $viewer);
            if ($item === null) {
                $this->notFound();
            }
        }
        $this->serve($route, $viewer, $item, $match['params'], 200);
    }

    /** The routed row by slug, read as the visitor, or null when they may not see it. */
    private function item(array $route, string $slug, \Zero\Viewer $viewer): ?array
    {
        try {
            $row = $this->db()->findFirst((string) $route['collection'], ['where' => [(string) $route['slugColumn'] => $slug]], $viewer);
        } catch (\Zero\HttpError) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $post = get_post((int) $row[$this->db()->schema()->collection((string) $route['collection'])['primaryKey']]);
        // Password-bearing items are never served by pages in this release.
        if ($post instanceof \WP_Post && $post->post_password !== '') {
            return null;
        }
        return $row;
    }

    private function notFound(): never
    {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        $route = $this->manifest['notFound'] ?? null;
        $viewer = \Zero\Wp\CoreAccess::viewer();
        if (!is_array($route) || $viewer === null) {
            $this->respond(404, null, '<h1>' . esc_html__('Page not found') . '</h1>');
        }
        $this->serve($route, $viewer, null, [], 404);
    }

    private function serve(array $route, \Zero\Viewer $viewer, ?array $item, array $params, int $status): never
    {
        $request = new Request($route, $viewer, $item, $params, $this);
        try {
            $request->load();
        } catch (\Zero\HttpError $error) {
            if ($status === 404) {
                $this->respond(404, null, '<h1>' . esc_html__('Page not found') . '</h1>');
            }
            $this->notFound();
        }
        $this->request = $request;
        $this->setPostContext($route, $item, $status);
        $renderer = new Renderer($request, $this);
        $template = (string) file_get_contents($this->directory . '/' . $route['template']);
        $head = $this->head($route, $renderer);
        // Body first, so blocks and islands enqueue their assets before wp_head prints them.
        $body = $renderer->render($template);
        $this->enqueue($route);
        $this->respond($status, $head, $body);
    }

    /** Native blocks, shortcodes and head hooks must see the post this route actually serves. */
    private function setPostContext(array $route, ?array $item, int $status): void
    {
        $query = new \WP_Query();
        if ($status !== 200) {
            $query->is_404 = $status === 404;
            $query->posts = [];
            $GLOBALS['wp_query'] = $query;
            $GLOBALS['wp_the_query'] = $query;
            $GLOBALS['post'] = null;
            foreach (['id', 'authordata', 'currentday', 'currentmonth', 'pages', 'page', 'multipage', 'more', 'numpages'] as $name) {
                unset($GLOBALS[$name]);
            }
            return;
        }
        if ($item === null) {
            $id = $this->routes->pageId((string) $route['id']);
            $post = $id > 0 ? get_post($id) : null;
        } else {
            $spec = $this->db()->schema()->collection((string) $route['collection']);
            $stored = get_post((int) $item[$spec['primaryKey']]);
            $post = $stored instanceof \WP_Post ? clone $stored : null;
            if ($post instanceof \WP_Post) {
                // Use the visitor's projection for native fields too; never seed globals with hidden content.
                foreach (['title' => 'post_title', 'content' => 'post_content', 'slug' => 'post_name'] as $designation => $field) {
                    $column = $spec[$designation] ?? null;
                    $post->$field = $column === null ? '' : (string) ($item[$column] ?? '');
                }
                if (($spec['content'] ?? null) === null || !array_key_exists($spec['content'], $item) || $item[$spec['content']] === null) {
                    $post->post_excerpt = '';
                }
            }
        }
        if (!$post instanceof \WP_Post) {
            return;
        }
        // Core cannot query the optional URL shapes itself (and collection types have no rewrite rules).
        // Install a completed singular query instead of leaving its fallback 404 or unrelated post in place.
        $query->query = $post->post_type === 'page' ? ['page_id' => $post->ID] : ['p' => $post->ID, 'post_type' => $post->post_type];
        $query->query_vars = $query->fill_query_vars($query->query);
        $query->queried_object = $post;
        $query->queried_object_id = $post->ID;
        $query->posts = [$post];
        $query->post = $post;
        $query->post_count = 1;
        $query->found_posts = 1;
        $query->max_num_pages = 1;
        $query->is_page = $post->post_type === 'page';
        $query->is_single = !$query->is_page;
        $query->is_singular = true;
        $GLOBALS['wp_query'] = $query;
        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['post'] = $post;
        $query->setup_postdata($post);
    }

    /** @return array{title: string, description: ?string, canonical: ?string, robots: ?array} */
    private function head(array $route, Renderer $renderer): array
    {
        // The page's own title goes through the nearest ancestor layout's template, once.
        $title = null;
        $template = null;
        $description = null;
        $canonical = null;
        $robots = null;
        foreach ($route['metadata'] as $meta) {
            if (isset($meta['titleTemplate'])) {
                $template = $meta['titleTemplate'];
            }
            if (isset($meta['title'])) {
                $title = $renderer->substitute((string) $meta['title']);
            }
            if (isset($meta['description'])) {
                $description = $renderer->substitute((string) $meta['description']);
            }
            if (isset($meta['canonical'])) {
                $canonical = $renderer->substitute((string) $meta['canonical']);
            }
            if (isset($meta['robots'])) {
                $robots = $meta['robots'];
            }
        }
        if ($title !== null && $title !== '' && $template !== null) {
            $title = str_replace('%s', $title, (string) $template['template']);
        } elseif ($title === null || $title === '') {
            $title = $template !== null ? (string) $template['default'] : null;
        }
        $this->title = $title ?? (string) $route['title'];
        if ($canonical === null || $canonical === '') {
            $canonical = $route['param'] === null
                ? ($route['id'] === 'zero:not-found' ? null : $this->routes->urlFor((string) $route['pattern']))
                : ($this->request?->item !== null ? $this->routes->itemUrl((string) $route['collection'], $this->request->item) : null);
        } elseif (str_starts_with($canonical, '/') && !str_starts_with($canonical, '//')) {
            $canonical = home_url($canonical);
        }
        return ['title' => $this->title, 'description' => $description, 'canonical' => $canonical, 'robots' => $robots];
    }

    private function enqueue(array $route): void
    {
        add_action('wp_enqueue_scripts', function () use ($route): void {
            foreach ($route['css'] as $index => $file) {
                wp_enqueue_style('zero-pages-' . $route['key'] . '-' . $index, $this->url . $file, [], null);
            }
        });
    }

    public function enqueueIsland(string $namespace): void
    {
        if (isset($this->islands[$namespace])) {
            return;
        }
        $island = $this->manifest['islands'][$namespace] ?? null;
        if (!is_array($island)) {
            return;
        }
        $this->islands[$namespace] = true;
        $handle = 'zero-pages-' . sanitize_key(str_replace('/', '-', $namespace));
        wp_register_script_module($handle, $this->url . $island['file'], ['@wordpress/interactivity'], null);
        wp_enqueue_script_module($handle);
        $actions = [];
        foreach ($island['actions'] as $action) {
            $actions[$action] = rest_url('zero/v1/actions/' . rawurlencode((string) $action));
        }
        // A REST nonce lets the island call actions as the signed-in visitor; anonymous visitors call as anonymous.
        wp_interactivity_config($namespace, ['actions' => $actions, 'nonce' => wp_create_nonce('wp_rest')]);
    }

    /** @param ?array{title: string, description: ?string, canonical: ?string, robots: ?array} $head */
    private function respond(int $status, ?array $head, string $body): never
    {
        if ($status !== 200) {
            $this->setPostContext([], null, $status);
        }
        status_header($status);
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        // Zero emits the only <title> and canonical for claimed routes.
        remove_action('wp_head', '_wp_render_title_tag', 1);
        remove_action('wp_head', '_block_template_render_title_tag', 1);
        remove_action('wp_head', 'rel_canonical');
        if ($head !== null && is_array($head['robots'])) {
            add_filter('wp_robots', static function (array $robots) use ($head): array {
                if (($head['robots']['index'] ?? true) === false) {
                    $robots['noindex'] = true;
                }
                if (($head['robots']['follow'] ?? true) === false) {
                    $robots['nofollow'] = true;
                }
                return $robots;
            });
        }
        $title = $head['title'] ?? wp_get_document_title();
        echo "<!DOCTYPE html>\n<html " . get_language_attributes() . ">\n<head>\n<meta charset=\"" . esc_attr((string) get_option('blog_charset')) . "\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        echo '<title>' . esc_html($title) . "</title>\n";
        if (($head['description'] ?? null) !== null && $head['description'] !== '') {
            echo '<meta name="description" content="' . esc_attr($head['description']) . "\">\n";
        }
        if (($head['canonical'] ?? null) !== null) {
            echo '<link rel="canonical" href="' . esc_url($head['canonical']) . "\">\n";
        }
        wp_head();
        echo "</head>\n<body class=\"" . esc_attr(implode(' ', get_body_class('zero-pages'))) . "\">\n";
        wp_body_open();
        echo '<div class="wp-site-blocks">' . $body . "</div>\n";
        wp_footer();
        echo "</body>\n</html>\n";
        exit;
    }
}
