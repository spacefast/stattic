<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\Sync\Markdown;

/**
 * Markdown twins of the pages WordPress renders, `/llms.txt` and the `Content-Signal` header
 * (docs/spec/rendering.md §5). Only the v2 app plugin registers this, so a WordPress without a TypedPress
 * app is unchanged.
 *
 * - Every public URL WordPress renders has a twin: `/x/` → `/x.md`, `/` → `/index.md`, the query
 *   string kept. The twin is its own URL with one representation, so page caches keyed on the URL
 *   (Batcache, the edge) keep it apart from the HTML. Nothing here varies on `Accept`.
 * - A `.md` request is parsed as its HTML URL by WordPress itself, then answered on `wp`, after the
 *   main query and before any template, canonical redirect or the pages add-on's claim.
 * - Twins are the same for every visitor: only publicly viewable content, never drafts, previews,
 *   private posts or password-protected bodies. They are built from stored content
 *   ({@see Markdown::renderPost()}), not from the theme's HTML.
 * - HTML responses with a twin advertise it in a `Link` header and a `<link>` tag, the same tag
 *   WordPress/ai's Markdown Feeds prints for its own Markdown URLs.
 * - The app names itself on WordPress/ai's `wpai_markdown_feeds_conflicting_plugins` list, so a site
 *   that also turns on Markdown Feeds is told that two plugins answer Markdown requests.
 * - The engine's `Accept: text/markdown` lane calls {@see negotiate()} (docs/runtime-contract.md).
 */
final class MarkdownTwins
{
    public const CONTENT_TYPE = 'text/markdown; charset=UTF-8';
    /** contentsignals.org: may be indexed and used to answer questions, not used for training. */
    public const CONTENT_SIGNAL = 'search=yes, ai-input=yes, ai-train=no';
    /** Entries per kind in /llms.txt; each kind's archive link reaches the rest. */
    public const LLMS_LIMIT = 100;
    /** Inspect at most 300 published candidates per post type. Guards can leave a shorter listing. */
    private const LLMS_BATCHES = 3;

    /** The `.md` URI this request asked for, while WordPress parses it as its HTML URL. */
    private static ?string $twin = null;
    /** false outside negotiate(); null while it runs; then the answer `wp` built for it. */
    private static array|false|null $negotiated = false;

    /** @param string $mainFile the app plugin's main file, which names it to WordPress/ai */
    public static function register(string $mainFile): void
    {
        // After the app router's handlers (0): a first/app/ route at a `.md` path or `/llms.txt` is the app's.
        add_filter('do_parse_request', [self::class, 'parseRequest'], 1);
        add_action('parse_request', [self::class, 'parsed'], PHP_INT_MAX);
        add_action('wp', [self::class, 'answer'], PHP_INT_MIN);
        add_action('wp_head', [self::class, 'linkTag'], 1);
        add_filter('robots_txt', [self::class, 'robots'], PHP_INT_MAX);
        add_filter('rest_request_before_callbacks', [self::class, 'restFields'], 10, 3);
        add_filter('rest_pre_serve_request', [self::class, 'restMarkdown'], 10, 4);
        add_filter('wpai_markdown_feeds_conflicting_plugins', static fn (mixed $plugins): array => self::conflicting((array) $plugins, $mainFile));
    }

    /**
     * WordPress/ai's Markdown Feeds lists the active plugins that also serve Markdown
     * (`plugin file => name`, kept when `is_plugin_active()`), so its settings warn about two answers
     * to the same Markdown requests and two `<link rel="alternate" type="text/markdown">` tags.
     *
     * @param array<string, string> $plugins
     * @return array<string, string>
     */
    public static function conflicting(array $plugins, string $mainFile): array
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $name = (string) (get_plugin_data($mainFile, false, false)['Name'] ?? '');
        $plugins[plugin_basename($mainFile)] = $name !== '' ? $name : 'TypedPress';

        return $plugins;
    }

    // ================================================================ public API

    /**
     * The twin of a URL or root-relative path: `/x/` and `/x` → `/x.md`, the home URL → `/index.md`,
     * scheme, host and query string kept. Purges use the same rule for each URL's `.md` twin.
     */
    public static function twin(string $url): string
    {
        $parts = wp_parse_url($url);
        $parts = is_array($parts) ? $parts : [];
        $path = (string) ($parts['path'] ?? '/');
        $base = self::base();
        $relative = rtrim($base !== '' && str_starts_with($path . '/', $base . '/') ? substr($path, strlen($base)) : $path, '/');
        $origin = isset($parts['host']) ? ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : '';
        $query = (string) ($parts['query'] ?? '');

        return $origin . $base . ($relative === '' ? '/index' : $relative) . '.md' . ($query !== '' ? '?' . $query : '');
    }

    /**
     * One post as Markdown: `# Title`, then its content as WordPress shows it ({@see Markdown::renderPost()}).
     * A password-protected post gives its title and a note, never its body. Nothing else is checked:
     * the caller decides who may read the post (the twin URLs, `?format=md`).
     */
    public static function post(\WP_Post $post): string
    {
        $title = self::plain($post->post_title);
        $body = post_password_required($post) ? "This content is password protected.\n" : Markdown::renderPost($post);
        $markdown = $title === '' ? '' : '# ' . $title . "\n";
        if ($body !== '') {
            $markdown .= ($markdown === '' ? '' : "\n") . $body;
        }

        return $markdown;
    }

    /**
     * The engine's answer to `Accept: text/markdown` on an HTML URL (docs/runtime-contract.md): what
     * `<uri>.md` answers, as a response for the engine to send, plus `Cache-Control: private,
     * no-store`, `Vary: Accept` and `Content-Location: <twin URL>`. `$uri` is the request's path and
     * query string (`/x/?page=2`); a twin URI (`/x.md`) works too. Null when no TypedPress app is active:
     * the engine then serves the request as it would have without Markdown.
     *
     * Call it once per request, after wp-load.php and before anything runs WordPress's main query or
     * prints. It calls batcache_cancel(), so nothing this request prints is stored, and runs
     * WordPress's own request parsing and main query for `$uri`, so it answers exactly what the twin
     * URL answers. It prints nothing and doesn't exit, except that an app `route.ts` at that path
     * answers and exits as on any request. Send the status with status_header() (WordPress may
     * have sent one already) and every returned header replacing any of the same name.
     *
     * @return ?array{status: int, headers: array<string, string>, body: string}
     */
    public static function negotiate(string $uri): ?array
    {
        if (Runtime::plugin() === null) {
            return null;
        }
        if (function_exists('batcache_cancel')) {
            batcache_cancel();
        }
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($uri, PHP_URL_QUERY) ?? '');
        $html = (self::htmlPath($path) ?? $path) . ($query !== '' ? '?' . $query : '');
        self::rewrite($html);
        $_SERVER['QUERY_STRING'] = $query;
        parse_str($query, $get);
        // After bootstrap WordPress reads $_GET slashed (wp_magic_quotes).
        $_GET = wp_slash($get);
        $twin = self::twin(home_url(self::relative($html)));
        $private = ['Cache-Control' => 'private, no-store', 'Vary' => 'Accept', 'Content-Location' => $twin];
        // TypedPress, so that whatever a hook does from here (an exit included) leaves no shared copy.
        if (!headers_sent()) {
            foreach ($private as $name => $value) {
                header("$name: $value");
            }
        }
        self::anonymous();
        self::$twin = $twin;
        self::$negotiated = null;
        // WordPress's own headers are for the HTML page; the returned headers are the whole answer.
        $none = static fn (): array => [];
        add_filter('wp_headers', $none, PHP_INT_MAX);
        add_filter('nocache_headers', $none, PHP_INT_MAX);
        try {
            wp();
            $answer = self::$negotiated ?? throw new \LogicException('the main query ran without TypedPress answering it');
        } finally {
            remove_filter('wp_headers', $none, PHP_INT_MAX);
            remove_filter('nocache_headers', $none, PHP_INT_MAX);
            self::$twin = null;
            self::$negotiated = false;
        }
        $answer['headers'] = $private + $answer['headers'];

        return $answer;
    }

    /** Whether a Markdown negotiation (negotiate()) is running its main query in this process. */
    public static function negotiating(): bool
    {
        return self::$negotiated !== false;
    }

    /** `/llms.txt` (llmstxt.org): the site, then each public kind's newest entries and terms, linking twins. */
    public static function llms(): string
    {
        self::anonymous();
        $lines = ['# ' . self::plain((string) get_bloginfo('name')), ''];
        $tagline = self::plain((string) get_bloginfo('description'));
        if ($tagline !== '') {
            array_push($lines, '> ' . $tagline, '');
        }
        $intro = self::guarded(home_url('/')) ? 'Public pages have a Markdown version at their URL plus `.md`.'
            : 'Every page has a Markdown version at its URL plus `.md`; the home page is ' . self::link('/index.md', self::twin(home_url('/'))) . '.';
        array_push($lines, $intro, '');
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name === 'attachment') {
                continue;
            }
            $entries = [];
            for ($page = 1; $page <= self::LLMS_BATCHES; ++$page) {
                $posts = get_posts([
                    'post_type' => $type->name, 'post_status' => 'publish', 'numberposts' => self::LLMS_LIMIT, 'paged' => $page,
                    'orderby' => $type->hierarchical ? ['menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC'] : ['date' => 'DESC', 'ID' => 'DESC'],
                ]);
                foreach ($posts as $post) {
                    $entry = self::entry($post);
                    if ($entry !== null) {
                        $entries[] = $entry;
                        if (count($entries) === self::LLMS_LIMIT) {
                            break 2;
                        }
                    }
                }
                if (count($posts) < self::LLMS_LIMIT) {
                    break;
                }
            }
            $archive = $type->name === 'post' ? self::postsPage() : get_post_type_archive_link($type->name);
            if (is_string($archive) && $archive !== '' && $entries !== [] && !self::guarded($archive)) {
                $entries[] = '- ' . self::link('All ' . self::plain((string) $type->labels->name), self::twin($archive));
            }
            self::section($lines, (string) $type->labels->name, (string) $type->description, $entries);
        }
        // The app's own pages (`tp/app/`): every page route without parameters or a guard.
        $pages = [];
        foreach (AppRouter::instance()?->pages() ?? [] as [$path, $title]) {
            $pages[] = '- ' . self::link(self::plain($title), self::twin(home_url($path)));
        }
        self::section($lines, 'Site pages', '', $pages);
        foreach (get_taxonomies(['public' => true, 'publicly_queryable' => true], 'objects') as $taxonomy) {
            if ($taxonomy->name === 'post_format') {
                continue;
            }
            $terms = get_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => true, 'number' => self::LLMS_LIMIT, 'orderby' => 'count', 'order' => 'DESC']);
            $entries = [];
            foreach (is_array($terms) ? $terms : [] as $term) {
                $url = get_term_link($term);
                if (is_string($url) && !self::guarded($url)) {
                    $about = self::plain($term->description);
                    $entries[] = '- ' . self::link(self::plain($term->name), self::twin($url)) . ($about !== '' ? ': ' . $about : '');
                }
            }
            self::section($lines, (string) $taxonomy->labels->name, (string) $taxonomy->description, $entries);
        }

        return rtrim(implode("\n", $lines), "\n") . "\n";
    }

    /** The `Content-Signal` value (contentsignals.org); the `tp_content_signal` filter changes it, '' sends none. */
    public static function contentSignal(): string
    {
        return (string) apply_filters('tp_content_signal', self::CONTENT_SIGNAL);
    }

    // ================================================================ hooks

    /** do_parse_request: `/llms.txt` answers here; a `.md` URI is parsed as its HTML URL. */
    public static function parseRequest(bool $parse): bool
    {
        if (!$parse || self::$negotiated !== false || is_admin() || !in_array(self::method(), ['GET', 'HEAD'], true)) {
            return $parse;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($path === self::base() . '/llms.txt') {
            self::emit(200, ['Content-Type' => 'text/plain; charset=UTF-8'] + self::headers(), self::llms());
        }
        $html = self::htmlPath($path);
        if ($html !== null && !str_starts_with($path . '/', self::base() . '/' . rest_get_url_prefix() . '/')) {
            self::$twin = $uri;
            $query = (string) (wp_parse_url($uri, PHP_URL_QUERY) ?? '');
            self::rewrite($html . ($query !== '' ? '?' . $query : ''));
            self::anonymous();
        }

        return $parse;
    }

    /**
     * parse_request: a twin of a feed, embed, preview, trackback or error page is a 404. Turning the
     * query into one here, before WP::send_headers(), also keeps WordPress from answering it itself
     * (a feed's 304, an `?error=403` exit) with the HTML page's headers.
     */
    public static function parsed(\WP $wp): void
    {
        if (self::$twin === null) {
            return;
        }
        foreach (['feed', 'embed', 'preview', 'tb', 'robots', 'favicon', 'error'] as $var) {
            if (($wp->query_vars[$var] ?? '') !== '') {
                $wp->query_vars = ['error' => '404'];

                return;
            }
        }
    }

    /** wp: a `.md` request gets its twin; an HTML page with one advertises it. */
    public static function answer(): void
    {
        if (is_admin()) {
            return;
        }
        if (self::$twin !== null) {
            // A `tp/app/` page renders its own twin, from its compiled output (rendering.md §5).
            $app = AppRouter::instance()?->twin(self::htmlUrl(), $reads, $also, $search);
            [$status, $body] = $app ?? self::document();
            $headers = self::headers();
            if ($status === 200) {
                $headers['Link'] = '<' . self::canonical() . '>; rel="canonical"';
            }
            if (self::$negotiated === null) {
                self::$negotiated = ['status' => $status, 'headers' => $headers, 'body' => $body];

                return;
            }
            // A `.md` URL's twin is shared like an anonymous page (it is rendered as one): what it
            // read decides which writes purge it, as for the page (rendering.md §6.2). Recorded under
            // the twin's own URL: the page's layouts read more than the twin, and keep their record.
            // A twin URL that can't be recorded (a query parameter its page doesn't declare) can't be
            // purged: Batcache, which keeps a response whatever its headers, is told to drop it.
            if ($app !== null && $status === 200 && !\TypedPress\Render\Cache::record(self::$twin, $reads, $also, $search)) {
                $headers['Cache-Control'] = \TypedPress\Render\Cache::PRIVATE;
                $headers[\TypedPress\Render\Cache::EDGE_HEADER] = 'no-cache';
                if (function_exists('batcache_cancel')) {
                    batcache_cancel();
                }
                // Nor a prebuilt twin file, which no write would know to rebuild.
                \TypedPress\Render\Prebuilt::unrecorded();
            }
            self::emit($status, $headers, $body);
        }
        if (headers_sent()) {
            return;
        }
        // The route table answers for a `tp/app/` page without a read; only a URL the theme renders
        // asks whether its item has a twin, which reads the kind's rule for a signed-out visitor.
        if (AppRouter::instance()?->serves(self::htmlUrl()) || self::kind() !== null) {
            header('Link: <' . self::twin(self::htmlUrl()) . '>; rel="alternate"; type="text/markdown"', false);
        }
        $signal = self::contentSignal();
        if ($signal !== '') {
            header('Content-Signal: ' . $signal);
        }
    }

    /**
     * rest_request_before_callbacks: a `?format=md` request needs only the item's id from core, so
     * core builds no `content.rendered` or `excerpt.rendered` (two the_content runs) that
     * restMarkdown() would throw away. Core's route still runs, permission check included.
     */
    public static function restFields(mixed $response, array $handler, \WP_REST_Request $request): mixed
    {
        if ($request->get_param('format') === 'md' && in_array($request->get_method(), ['GET', 'HEAD'], true) && self::itemRoutePost($request) !== null) {
            $request->set_param('_fields', 'id');
        }

        return $response;
    }

    /**
     * The post a REST route is that post's own item route for (`/<rest_namespace>/<rest_base>/<id>`
     * of a REST post type), or null. Only that route had core check this post; another collection
     * answering the same numeric id (a category, a user) checked a different object.
     */
    private static function itemRoutePost(\WP_REST_Request $request): ?\WP_Post
    {
        if (preg_match('#/(\d+)$#', $request->get_route(), $m) !== 1) {
            return null;
        }
        $post = get_post((int) $m[1]);
        if (!$post instanceof \WP_Post || !in_array($post->post_type, get_post_types(['show_in_rest' => true]), true)) {
            return null;
        }
        $type = get_post_type_object($post->post_type);
        $base = $type?->rest_base ?: $post->post_type;
        $namespace = $type?->rest_namespace ?: 'wp/v2';

        return $request->get_route() === '/' . $namespace . '/' . $base . '/' . $post->ID ? $post : null;
    }

    /**
     * rest_pre_serve_request: `?format=md` on a core post item route (`GET /wp/v2/posts/42`) answers
     * the item as {@see post()}. Core has already run the route, so its permission check decided
     * the request; only a successful item response becomes Markdown. A password-protected post is
     * its title and a note unless core would show its content (the right `password` parameter, or
     * an editor in the edit context). The item renders as the asking user, as core's own
     * `content.rendered` does.
     */
    public static function restMarkdown(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool
    {
        if ($served || $request->get_param('format') !== 'md' || !in_array($request->get_method(), ['GET', 'HEAD'], true) || $result->get_status() !== 200) {
            return $served;
        }
        $data = $result->get_data();
        $id = is_array($data) ? (int) ($data['id'] ?? 0) : 0;
        // Only the post's own item route: core already ran its permission check for this post there.
        $post = self::itemRoutePost($request);
        if ($post === null || $post->ID !== $id) {
            return $served;
        }
        $type = get_post_type_object($post->post_type);
        // Core shows a protected body only to a request it lets in (the right `password`, or an editor
        // in the edit context): the same check here, on the route's own controller.
        $controller = $type?->get_rest_controller();
        if ($post->post_password !== '' && $controller instanceof \WP_REST_Posts_Controller && $controller->can_access_password_content($post, $request)) {
            add_filter('post_password_required', $unlock = static fn (bool $required, mixed $p): bool => get_post($p)?->ID === $post->ID ? false : $required, PHP_INT_MAX, 2);
        }
        try {
            $markdown = self::post($post);
        } finally {
            if (isset($unlock)) {
                remove_filter('post_password_required', $unlock, PHP_INT_MAX);
            }
        }
        $server->send_header('Content-Type', self::CONTENT_TYPE);
        $server->send_header('X-Content-Type-Options', 'nosniff');
        $signal = self::contentSignal();
        if ($signal !== '') {
            $server->send_header('Content-Signal', $signal);
        }
        if ($request->get_method() !== 'HEAD') {
            echo $markdown;
        }

        return true;
    }

    /** robots_txt: the same signal for crawlers that read it there (contentsignals.org). */
    public static function robots(string $output): string
    {
        $signal = self::contentSignal();

        return $signal === '' ? $output : rtrim($output, "\n") . "\n\nUser-agent: *\nContent-Signal: " . $signal . "\n";
    }

    /** wp_head: the `<link rel="alternate" type="text/markdown">` matching the `Link` header. */
    public static function linkTag(): void
    {
        if (!AppRouter::instance()?->serves(self::htmlUrl()) && self::kind() === null) {
            return;
        }
        printf('<link rel="alternate" type="text/markdown" href="%s" />' . "\n", esc_url(self::twin(self::htmlUrl())));
    }

    // ================================================================ documents

    /** @return array{int, string} the status and twin of the main query */
    private static function document(): array
    {
        $kind = self::kind();
        if ($kind === null) {
            return [404, "# Not found\n\nNothing is published at this address.\n"];
        }
        if ($kind === 'singular') {
            $post = get_queried_object();
            assert($post instanceof \WP_Post);

            return [200, self::post($post)];
        }

        return [200, self::listing()];
    }

    /** What the main query shows: 'singular', 'listing', or null when it has no twin. */
    private static function kind(): ?string
    {
        if (is_404() || is_feed() || is_robots() || is_favicon() || is_trackback() || is_embed() || is_preview()) {
            return null;
        }
        $post = is_singular() ? get_queried_object() : null;
        $router = AppRouter::instance();
        if ($router !== null) {
            $path = self::relative((string) wp_parse_url(self::requestUri(), PHP_URL_PATH));
            // The home route owns the front page. WordPress still owns queries such as /?p=123 or /?s=term.
            if (($path !== '/' || is_home() || is_front_page()) && $router->guarded($path, $post instanceof \WP_Post ? $post->post_type : '')) {
                return null;
            }
        }
        if (is_singular()) {
            return $post instanceof \WP_Post && self::hasTwin($post) ? 'singular' : null;
        }

        return is_home() || is_archive() || is_search() ? 'listing' : null;
    }

    /** An archive, the blog index or search results: title, description, entries, page links. */
    private static function listing(): string
    {
        global $wp_query;
        $lines = ['# ' . self::listingTitle(), ''];
        $about = is_category() || is_tag() || is_tax() ? self::plain((string) term_description())
            : (is_author() ? self::plain((string) get_the_author_meta('description', (int) get_queried_object_id())) : '');
        if ($about !== '') {
            array_push($lines, $about, '');
        }
        $entries = [];
        foreach ($wp_query instanceof \WP_Query ? $wp_query->posts : [] as $post) {
            if ($post instanceof \WP_Post && ($entry = self::entry($post)) !== null) {
                $entries[] = $entry;
            }
        }
        array_push($lines, ...($entries === [] ? ['Nothing is published here yet.'] : $entries));
        $page = max(1, (int) get_query_var('paged'));
        $pages = $wp_query instanceof \WP_Query ? (int) $wp_query->max_num_pages : 1;
        $links = [];
        if ($page > 1) {
            $links[] = self::link('Previous page', self::twin(get_pagenum_link($page - 1, false)));
        }
        if ($page < $pages) {
            $links[] = self::link('Next page', self::twin(get_pagenum_link($page + 1, false)));
        }
        if ($links !== []) {
            array_push($lines, '', implode(' · ', $links));
        }

        return implode("\n", $lines) . "\n";
    }

    private static function listingTitle(): string
    {
        if (is_search()) {
            return 'Search results for “' . self::plain(get_search_query(false)) . '”';
        }
        if (is_home()) {
            $page = (int) get_option('page_for_posts');

            return self::plain($page > 0 ? (string) get_post_field('post_title', $page) : (string) get_bloginfo('name'));
        }

        return self::plain((string) get_the_archive_title());
    }

    /** `- [Title](twin): summary`; a password-protected post shows its title only. */
    private static function entry(\WP_Post $post): ?string
    {
        if (!self::hasTwin($post)) {
            return null;
        }
        $url = (string) get_permalink($post);
        if (self::guarded($url, $post->post_type)) {
            return null;
        }
        $line = '- ' . self::link(self::plain($post->post_title) ?: '(untitled)', self::twin($url));
        if ($post->post_password !== '') {
            return $line;
        }
        // From what the post's twin shows (rendered blocks, so a block that hides content hides it
        // here too), never from its raw markup; a hand-written excerpt is plain text already.
        $source = $post->post_excerpt !== '' ? $post->post_excerpt : self::summaryText(Markdown::renderPost($post));
        $summary = self::plain(wp_trim_words($source, 30, '…'));

        return $line . ($summary !== '' ? ': ' . $summary : '');
    }

    /**
     * Rendered Markdown as running text for a one-line summary: link, image and emphasis syntax
     * dropped, headings and list markers too, the visible words kept.
     */
    private static function summaryText(string $markdown): string
    {
        $text = (string) preg_replace(['/!\[([^\]]*)\]\([^)]*\)/', '/\[([^\]]*)\]\([^)]*\)/', '/^\s{0,3}(#{1,6}|[-*+>]|\d+\.)\s+/m', '/(\*\*|\*|~~|`+)/', '/\\\\(?=[<\[\]\\\\])/'],
            ['$1', '$1', '', '', ''], $markdown);

        return $text;
    }

    /** @param list<string> $lines @param list<string> $entries */
    private static function section(array &$lines, string $label, string $description, array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $lines[] = '## ' . self::plain($label);
        $lines[] = '';
        $description = self::plain($description);
        if ($description !== '') {
            array_push($lines, $description, '');
        }
        array_push($lines, ...$entries);
        $lines[] = '';
    }

    private static function postsPage(): ?string
    {
        if (get_option('show_on_front') !== 'page') {
            return home_url('/');
        }
        $page = (int) get_option('page_for_posts');

        return $page > 0 ? (string) get_permalink($page) : null;
    }

    // ================================================================ helpers

    /**
     * HTML or stored text as one plain line: tags dropped, entities decoded, whitespace collapsed. A
     * `<` that would start a tag (one spelled `&lt;b&gt;` decodes to it) stays `&lt;`, so no HTML
     * reaches a listing or /llms.txt.
     */
    private static function plain(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace(['/\s+/u', '/<(?=[A-Za-z\/!?])/'], [' ', '&lt;'], $text));
    }

    private static function link(string $text, string $url): string
    {
        return '[' . str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $text) . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url) . ')';
    }

    /** @return array<string, string> headers every twin and /llms.txt carries */
    private static function headers(): array
    {
        $headers = ['Content-Type' => self::CONTENT_TYPE, 'X-Content-Type-Options' => 'nosniff'];
        if (\TypedPress\Render\Prebuilt::prerendering()) {
            // Rendered for the prebuild, which writes a twin file only from such an answer.
            $headers['X-TypedPress-Prerendered'] = '1';
            $headers['Cache-Control'] = 'private, no-store, max-age=0';
        }
        $signal = self::contentSignal();
        if ($signal !== '') {
            $headers['Content-Signal'] = $signal;
        }

        return $headers;
    }

    /** Whether an app guard covers this WordPress URL, including kind items listed by /llms.txt. */
    private static function guarded(string $url, string $postType = ''): bool
    {
        $router = AppRouter::instance();
        $path = self::relative((string) wp_parse_url($url, PHP_URL_PATH));
        return $router !== null && ($path !== '/' || !AppRouter::wordPressHomeQuery($url)) && $router->guarded($path, $postType);
    }

    /**
     * Whether a post has a twin, so whether anything may link one: publicly viewable, and not refused
     * by `tp_markdown_twin`, which lets a plugin that renders the URL itself (the pages add-on's code
     * Pages) say the stored post is not what the URL shows.
     */
    private static function hasTwin(\WP_Post $post): bool
    {
        if (!is_post_publicly_viewable($post) || !apply_filters('tp_markdown_twin', true, $post)) {
            return false;
        }
        // An item of a TypedPress kind: as a signed-out visitor reads it, through the kind's read rule,
        // and never under a page that isn't published.
        $kind = CoreAccess::collection($post->post_type);
        if ($kind !== null) {
            return CoreAccess::covers($kind, 'read', $post->ID, new \TypedPress\Viewer(null)) && KindUrls::published($post);
        }

        return true;
    }

    /**
     * The rest of this request is an anonymous visitor's, whatever cookies it carries: a twin is the
     * same for everyone, so shared caches may keep it. The main query, listings and every dynamic
     * block then see what a signed-out visitor sees, and every password-protected post stays locked,
     * a `wp-postpass_*` cookie notwithstanding (a Query Loop's post content included).
     */
    private static function anonymous(): void
    {
        wp_set_current_user(0);
        add_filter('determine_current_user', static fn (): int => 0, PHP_INT_MAX);
        add_filter('post_password_required', static function (bool $required, mixed $post): bool {
            $post = get_post($post);

            return $required || ($post instanceof \WP_Post && $post->post_password !== '');
        }, PHP_INT_MAX, 2);
    }

    /** REQUEST_URI and, when the server sets it, PATH_INFO for the HTML URI WordPress should parse. */
    private static function rewrite(string $uri): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $info = $_SERVER['PATH_INFO'] ?? null;
        if (is_string($info) && str_ends_with($info, '.md')) {
            $stem = substr($info, 0, -3);
            $_SERVER['PATH_INFO'] = $stem === '/index' || $stem === '' ? '/' : $stem;
        }
    }

    /** The HTML path a twin path stands for (`/x.md` → `/x`, `/index.md` → `/`), or null for any other path. */
    private static function htmlPath(string $path): ?string
    {
        $base = self::base();
        if (!str_ends_with($path, '.md') || ($base !== '' && !str_starts_with($path, $base . '/'))) {
            return null;
        }
        $stem = substr($path, strlen($base), -3);
        if ($stem === '/' || $stem === '' || str_ends_with($stem, '/')) {
            return null;
        }

        return $base . ($stem === '/index' ? '/' : $stem);
    }

    /** The URL WordPress parsed, absolute: for a twin request, the HTML URL it stands for. */
    private static function htmlUrl(): string
    {
        return home_url(self::relative(self::requestUri()));
    }

    /** The HTML page a twin stands for, as WordPress spells it (permalink, trailing slash). */
    private static function canonical(): string
    {
        if (is_singular() && ($object = get_queried_object()) instanceof \WP_Post) {
            return (string) get_permalink($object);
        }
        $uri = self::relative(self::requestUri());
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($uri, PHP_URL_QUERY) ?? '');

        return home_url($path === '/' ? '/' : user_trailingslashit($path)) . ($query !== '' ? '?' . $query : '');
    }

    /** The URI WordPress parsed: for a twin request, its HTML URI. */
    private static function requestUri(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /** A request URI below the home path, for home_url(). */
    private static function relative(string $uri): string
    {
        $base = self::base();

        return $base !== '' && str_starts_with($uri, $base) ? (substr($uri, strlen($base)) ?: '/') : $uri;
    }

    /** The home URL's path without its trailing slash ('' at the domain root). */
    private static function base(): string
    {
        return rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
    }

    private static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** @param array<string, string> $headers */
    private static function emit(int $status, array $headers, string $body): never
    {
        status_header($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if (self::method() !== 'HEAD') {
            echo $body;
        }
        exit;
    }
}
