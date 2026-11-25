<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Html;
use SpacePress\Js;
use SpacePress\JsObject;
use SpacePress\Render\Halt;
use SpacePress\Render\Navigation;
use SpacePress\Render\Reads;
use SpacePress\Render\RoutePlan;
use SpacePress\Seam;
use SpacePress\Viewer;

/**
 * `sp/app/` on WordPress (docs/spec/rendering.md, spec.md §5): the one `template_redirect` gate
 * for the front end.
 *
 * Request flow, for GET and HEAD on the public site (wp-admin, REST, feeds, robots, login and cron
 * never reach it, so they work in every mode):
 *
 * 1. Match the path against the compiled route table (most specific first).
 * 2. Guards: outer layouts first, then the page's own rule, as the visitor. A refusal sends a signed-out
 *    visitor to the login page and answers 403 to a signed-in one.
 * 3. Render the page inside its layouts with compiled reads bound to the visitor
 *    (`Seam::asViewer`), so drafts and hidden fields never reach the HTML. `notFound()` renders
 *    `not-found.tsx` with a 404, `redirect()` redirects, and anything else thrown renders
 *    `error.tsx` with a 500 and logs the error (its details never reach the page).
 * 4. Emit the document: the layouts' head (title, description, canonical, robots), `wp_head`,
 *    the rendered body inside the router region, `wp_footer`.
 *
 * Unsafe methods on a page with handlers (`export async function POST`) and every method on a
 * `route.ts` are answered on `do_parse_request` instead, like the config's `routes`: before
 * WordPress resolves a query.
 *
 * When `sp/app/` exists, SpacePress owns the front end: a URL no route matches renders
 * `not-found.tsx` (or WordPress's own 404 page when there is none). Kind URLs WordPress itself
 * resolves (a post, a term archive) keep rendering with the theme until an app route matches them.
 *
 * An anonymous visitor's render of a page without a guard is cacheable (Batcache and
 * the edge, `SpacePress\Render\Cache::policy`); everything else is `private, no-store`. Each cacheable
 * render records the rows it read, so a write purges exactly the URLs that showed it.
 */
final class AppRouter
{
    private static ?self $instance = null;
    /** @var array<string, mixed> */
    private array $app;

    public static function register(Plugin $plugin): void
    {
        add_action('wp_ajax_spacepress_rest_nonce', [\SpacePress\Islands::class, 'renewClientNonce']);
        add_action('wp_ajax_nopriv_spacepress_rest_nonce', [\SpacePress\Islands::class, 'renewClientNonce']);
        $app = $plugin->schema->app();
        if (!is_array($app) || self::$instance !== null) {
            return;
        }
        self::$instance = new self($plugin, $app);
        add_filter('do_parse_request', [self::$instance, 'handlers'], 0);
        // wp-login.php's sign-in view, once wp-login.php has decided what it shows: `login.tsx`, or
        // the sign-in block template an owner edited in the Site Editor.
        add_filter('wp_login_errors', [self::$instance, 'login'], PHP_INT_MAX, 2);
        // After the frontend-mode gate (-1) and a kind URL's old-slug redirect (0).
        add_action('template_redirect', [self::$instance, 'claim'], 1);
        add_filter('pre_handle_404', [self::$instance, 'paged'], 10, 2);
    }

    /**
     * `pre_handle_404`: an item WordPress found for a page that serves its kind is found, whatever
     * the page's own query parameters are. Core reads `?page=2` as `<!--nextpage-->` paging and
     * calls a post without it a 404 (WP::handle_404), but on an app page it is the page's argument
     * (`<Pagination>`'s by default), and the page renders.
     */
    public function paged(bool $preempt, \WP_Query $query): bool
    {
        if ($preempt || !$query->is_singular() || !$query->post instanceof \WP_Post || ($query->query_vars['page'] ?? '') === '') {
            return $preempt;
        }
        $path = $this->path();
        $match = $path === null || $this->reserved($path) ? null : $this->matchPath($path);
        if ($match === null || ($match['route']['page'] ?? null) === null || $this->kindType($match['route']) !== $query->post->post_type) {
            return $preempt;
        }
        status_header(200);
        return true;
    }

    /**
     * The block template document() renders in (a slug, BlockTemplates): SpacePress's page template
     * until a route chooses another. Null only with a classic theme, where SpacePress prints the document.
     */
    private ?string $template = BlockTemplates::PAGE;
    /** @var array<string, ?string> templateFor() by path, for this request */
    private array $templates = [];
    /** The wp_head callback that prints the head's description, canonical and social tags in a block template. */
    private ?\Closure $headTags = null;
    /** @var list<string> the stylesheets of the route being rendered, linked by document() */
    private array $styles = [];
    /** @var list<array{file: string, type: string}> font preloads for the route being rendered */
    private array $preloads = [];
    /** This response's `Cache-Control`: private unless serve() found it shareable. */
    private string $cacheControl = 'private, no-store, max-age=0';

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /** @param array<string, mixed> $app */
    private function __construct(private readonly Plugin $plugin, array $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ matching

    private function matchPath(string $path): ?array
    {
        return self::match($this->app['routes'], $path, $this->app['routeIndex']);
    }

    /**
     * The route for a path below the home URL, with its decoded params: `:name` takes one segment,
     * `*name` one or more, `**name` zero or more (catch-alls as lists).
     *
     * @return ?array{route: array<string, mixed>, params: array<string, string|list<string>>}
     */
    public static function match(array $routes, string $path, array $index): ?array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
                return null;
            }
            $decoded = rawurldecode($raw);
            if ($decoded === '.' || $decoded === '..' || str_contains($decoded, '/') || !mb_check_encoding($decoded, 'UTF-8')) {
                return null;
            }
            $segments[] = $decoded;
        }
        $literal = $index['literal']['/' . implode('/', $segments)] ?? null;
        if ($literal !== null) {
            return ['route' => $routes[$literal], 'params' => []];
        }
        // The compiler preserves specificity order in the dynamic list.
        foreach ($index['dynamic'] as $candidate) {
            $route = $routes[$candidate];
            $pattern = $route['pattern'] === '/' ? [] : explode('/', trim((string) $route['pattern'], '/'));
            $params = [];
            $ok = true;
            foreach ($pattern as $i => $part) {
                if (str_starts_with($part, '**') || str_starts_with($part, '*')) {
                    $rest = array_slice($segments, $i);
                    if ($rest === [] && !str_starts_with($part, '**')) {
                        $ok = false;
                    }
                    $params[ltrim($part, '*')] = $rest;
                    break;
                }
                if (!array_key_exists($i, $segments)) {
                    $ok = false;
                    break;
                }
                if (str_starts_with($part, ':')) {
                    $params[substr($part, 1)] = $segments[$i];
                } elseif ($part !== $segments[$i]) {
                    $ok = false;
                    break;
                }
            }
            $last = end($pattern);
            $catchAll = is_string($last) && str_starts_with($last, '*');
            if ($ok && ($catchAll || count($pattern) === count($segments))) {
                return ['route' => $route, 'params' => $params];
            }
        }
        return null;
    }

    /** WordPress's and SpacePress's own entry points (the compiler's list): never a page, never the app's 404 (spec §5). */
    private function reserved(string $path): bool
    {
        $first = strtolower(rawurldecode(explode('/', ltrim($path, '/'), 2)[0]));
        return in_array($first, $this->app['reserved'] ?? [], true) || str_starts_with($first, 'wp-sitemap');
    }

    /**
     * The request's path, as WordPress and WooCommerce read it: `//host/shop/` is the path
     * `/host/shop/`, not `/shop/` on another host. Every reader of the request path uses this one,
     * so no two of them take the same request for different pages. `$uri` is a request URI other
     * than this request's (the page cache's read index names a URL by it).
     */
    public static function requestPath(?string $uri = null): string
    {
        return (string) (wp_parse_url('/' . ltrim($uri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/'), '/'), PHP_URL_PATH) ?? '/');
    }

    private function path(): ?string
    {
        $path = self::requestPath();
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '') {
            if ($path !== $base && !str_starts_with($path, $base . '/')) {
                return null;
            }
            $path = substr($path, strlen($base)) ?: '/';
        }
        return $path;
    }

    private function file(?string $path): ?array
    {
        return $path === null ? null : ($this->app['files'][$path] ?? null);
    }

    /**
     * A route's layouts, root first, each with its file's spec and `path`. Under SpacePress's default
     * template the theme's header and footer replace the root layout's chrome: `app/layout.tsx`
     * keeps its metadata and guard and doesn't render (`chrome` false), unless it chose a template
     * itself or the route renders in another template (docs/pages.md "Block templates").
     *
     * @param array<string, mixed> $route
     * @return list<array<string, mixed>>
     */
    private function layouts(array $route): array
    {
        $layouts = array_map(fn (string $l): array => $this->file($l) + ['path' => $l], $route['layouts'] ?? []);
        if ($layouts !== [] && self::rootLayout($layouts[0]['path']) && ($layouts[0]['template'] ?? null) === null
            && $route['template'] === 'theme' && self::themed()) {
            $layouts[0]['chrome'] = false;
        }
        return $layouts;
    }

    /** Whether a layout is a root layout: `app/layout.tsx`, or one below route groups only (`app/(site)/layout.tsx`). */
    public static function rootLayout(string $path): bool
    {
        return preg_match('#\Aapp/(?:\([^/]+\)/)*layout\.tsx\z#', $path) === 1;
    }

    /** The owning page for a local link. A public file takes precedence over a dynamic route. */
    private array $linkPages = [];

    private function linkPage(string $path): ?array
    {
        if (array_key_exists($path, $this->linkPages)) {
            return $this->linkPages[$path];
        }
        $route = $this->reserved($path) ? null : ($this->matchPath($path)['route'] ?? null);
        if (($route['page'] ?? null) === null
            || (strpbrk($route['pattern'], ':*') !== false && PublicFiles::serves($this->plugin, $path))) {
            $route = null;
        }
        return $this->linkPages[$path] = $route;
    }

    /** A query at the home path can ask WordPress for a post, an archive or an endpoint. */
    public static function wordPressHomeQuery(string $url): bool
    {
        global $wp;
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return false;
        }
        parse_str($query, $vars);
        return array_intersect(array_keys($vars), $wp->public_query_vars ?? []) !== [];
    }

    /** Only a destination the app owns and whose policy always requires a session can skip the guest redirect. */
    public function linkRequiresLogin(string $path): bool
    {
        $route = $this->linkPage($path);
        return !empty($route['loginRequired']) && !$this->rootKind($route);
    }

    /**
     * The block template a path renders in (a template slug), or `document` when pages print their
     * own document (a classic theme), or null when no app page serves it. `<Link>` carries it, so a
     * click to a page in another template loads the whole page instead of swapping the region.
     */
    public function templateFor(string $path): ?string
    {
        if (array_key_exists($path, $this->templates)) {
            return $this->templates[$path];
        }
        $route = $this->linkPage($path);
        // A kind served at the site root (`/:path`) answers only its own items, and claim() leaves
        // every other path to WordPress: which one a path is, only that request knows.
        $template = match (true) {
            $route === null || $this->rootKind($route) => null,
            !self::themed() => 'document',
            default => BlockTemplates::slug($route['template']),
        };
        return $this->templates[$path] = $template;
    }

    /** wp-login.php has its own template. Links that leave it use full browser navigation. */
    public function linkTemplateFor(string $path): ?string
    {
        $target = $this->templateFor($path);
        if ($this->template === BlockTemplates::LOGIN && $target !== (self::themed() ? BlockTemplates::LOGIN : 'document')) {
            return null;
        }
        return $target;
    }

    /** Whether pages render in block templates: the active theme is a block theme. */
    public static function themed(): bool
    {
        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }

    // ------------------------------------------------------------------ HTTP handlers

    /** `route.ts` (every method) and a page's own handlers (methods other than GET/HEAD). */
    public function handlers(bool $parse): bool
    {
        if (!$parse) {
            return $parse;
        }
        $path = $this->path();
        // A root catch-all never answers WordPress's, SpacePress's or the host's own paths (the host's
        // `/__spacefast/connectors/*` and `/__spacefast/client.js` among them), as `claim()` doesn't.
        if ($path === null || $this->reserved($path)) {
            return $parse;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $match = $this->matchPath($path);
        if ($match === null) {
            return $parse;
        }
        $route = $match['route'];
        $file = $this->file($route['handler'] ?? $route['page'] ?? null);
        $methods = $file['methods'] ?? [];
        $page = ($route['page'] ?? null) !== null;
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        if ($page && ($lookup === 'GET')) {
            return $parse;
        }
        if (!isset($methods[$lookup])) {
            // A page at the site root's catch-all serves its kind's items only; a form posting
            // back to a WordPress Page there is WordPress's.
            if ($page && ($method !== 'POST' || $this->rootKind($route))) {
                return $parse;
            }
            $this->emit(405, ['Content-Type' => 'application/problem+json', 'Allow' => implode(', ', array_keys($methods + ($page ? ['GET' => true] : [])))], (string) Js::jsonStringify([
                'type' => 'about:blank', 'title' => 'Method not allowed', 'status' => 405, 'code' => 'method_not_allowed', 'detail' => "$method is not allowed on $path",
            ]));
        }
        if ($this->plugin->unavailable() !== null) {
            $this->emit(503, ['Content-Type' => 'application/problem+json', 'Retry-After' => '5'], (string) Js::jsonStringify([
                'type' => 'about:blank', 'title' => 'Service unavailable', 'status' => 503, 'code' => 'unavailable', 'detail' => Plugin::UNAVAILABLE,
            ]));
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }
        try {
            $user = RouteRequest::caller($method, $headers);
        } catch (\SpacePress\HttpError $error) {
            $problem = \SpacePress\App::problem($error);
            $this->emit($problem['status'], ['Content-Type' => 'application/problem+json'], (string) Js::jsonStringify($problem));
        }
        $params = array_map(static fn ($v) => is_array($v) ? implode('/', $v) : $v, $match['params']);
        // The file's own `access` rule, or its `signature` (verified over the raw body; the
        // handler then runs as the system, spec §3).
        $spec = ['handler' => $methods[$lookup], 'access' => $file['handlerGuard'] ?? ['allow' => false], 'signature' => $file['signature'] ?? null];
        $query = RouteRequest::query((string) ($_SERVER['QUERY_STRING'] ?? (wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '')));
        $url = RouteRequest::url((string) ($_SERVER['HTTP_HOST'] ?? (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? '')), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
        // An unsigned handler reads as its caller, like a page; a signed webhook runs as the
        // system (spec §3), so its reads are trusted.
        $viewer = $spec['signature'] !== null ? null : new Viewer($user);
        [$status, $out, $body] = Seam::asViewer($viewer, fn (): array => $this->plugin->app()->serveRoute(
            ['name' => (string) $route['id'], 'route' => $spec, 'params' => $params],
            $method, $path, $query, (string) file_get_contents('php://input'), $headers, $user, $url,
        ));
        $this->emit($status, $out, $body);
    }

    // ------------------------------------------------------------------ pages

    public function claim(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return;
        }
        if (is_feed() || is_robots() || is_trackback() || is_favicon() || is_embed()) {
            return;
        }
        $path = $this->path();
        if ($path === null || $this->reserved($path)) {
            return;
        }
        $match = $this->matchPath(\SpacePress\Commerce\Module::routePath($this->plugin, $path));
        $page = $match === null ? null : ($match['route']['page'] ?? null);
        if ($page === null) {
            // A URL WordPress resolved itself (a post, an archive, search) keeps its theme
            // template; anything else is the app's 404.
            if (is_404()) {
                $this->notFound();
            }
            return;
        }
        // The root route is the front page only: `/` also carries `?p=`, `?s=` and other queries
        // WordPress resolves itself.
        if ($match['route']['pattern'] === '/' && !(is_front_page() || is_home())) {
            return;
        }
        // A page serving a kind at the site root (`url: "/:path"`) takes a path only when it names
        // one of that kind's items: any other path is WordPress's (a Page, a post, an archive),
        // or the app's 404 when WordPress has nothing there either.
        if ($this->rootKind($match['route']) && !$this->rootItemQueried($match['route'])) {
            if (is_404()) {
                $this->notFound();
            }
            return;
        }
        // One address per page (#462): the spelling the permalink structure gives, as WordPress's
        // redirect_canonical() gives a post's. That function runs later on template_redirect (10)
        // than this, so it never sees a page the router serves. serve() redirects only a page that
        // would render: a hidden item, a draft page.md or notFound() is a 404 at either spelling, so
        // the redirect never tells a visitor something exists. A prerender (the prebuild,
        // `spacepress export`) asks for the path as the build lists it and gets the page.
        // A request carrying a form's result (`?_flash=`) is answered where it is: the page reads the
        // result once, and that render is private and never recorded. Forms come back to the one
        // address anyway (Forms::redirect).
        $moved = null;
        if ($this->canonical($path) !== $path && !\SpacePress\Render\Prebuilt::prerendering() && !\SpacePress\Render\Forms::marked()) {
            $query = (string) (wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '');
            $moved = home_url($this->canonical($path)) . ($query !== '' ? '?' . $query : '');
        }
        // A static route's code Page ({@see RoutePages}) is the queried object while it renders,
        // as WordPress's own page template would have it: menus, SEO plugins and `the_ID()` see it.
        $id = RoutePages::instance()?->pages()[(string) $match['route']['id']] ?? null;
        if ($id === null && $this->plugin->schema->commerce() !== null) {
            $id = ContentPages::pageId((string) $match['route']['id']);
        }
        if ($id !== null) {
            self::query(get_post($id));
        }
        if ($this->plugin->unavailable() !== null) {
            $this->document(503, ['title' => get_bloginfo('name')], '<p>' . esc_html__('This page is temporarily unavailable.') . '</p>');
        }
        // A prerender (SpacePress\Render\Prebuilt) is a signed-out visitor's render, whatever came with it.
        $viewer = \SpacePress\Render\Prebuilt::prerendering() ? new Viewer(null) : CoreAccess::viewer();
        if ($viewer === null) {
            $this->notFound();
        }
        $this->serve($match['route'], $match['params'], $viewer, 200, $moved);
    }

    /**
     * A redirect to a URL a `sp/app/` page serves, for this visitor (KindUrls' old-slug and wrong-path
     * redirects): sent only when the page renders for them there, guards, read rules, unpublished
     * parents and notFound() included, since a redirect says the page exists. Null when no page serves
     * the URL, so the caller decides; true when the page does and the visitor isn't sent (the request
     * goes on, and the router answers it).
     */
    public function moveTo(string $url): ?bool
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $match = $this->reserved($path) ? null : $this->matchPath($path);
        if ($match === null || ($match['route']['page'] ?? null) === null) {
            return null;
        }
        // A form's result is read once, by the page it came back to: never by a probe.
        $viewer = \SpacePress\Render\Forms::marked() ? null : CoreAccess::viewer();
        if ($viewer !== null && $this->renders($match['route'], $match['params'], $path, $viewer)) {
            if ($match['route']['guards'] !== []) {
                self::privateResponse();
            }
            wp_safe_redirect($url, 301);
            exit;
        }
        return true;
    }

    /**
     * Whether a page renders for $viewer at $path (below the home URL): its guards admit
     * them, the item a kind route names is theirs to read, and nothing in the render calls notFound()
     * or fails. What it rendered is dropped.
     *
     * @param array<string, string|list<string>> $params
     */
    private function renders(array $route, array $params, string $path, Viewer $viewer): bool
    {
        if (!$this->pageAllowed($route['guards'], $viewer)) {
            return false;
        }
        $this->plugin->app();
        $page = $this->file($route['page']);
        $layouts = array_map(fn (string $l): array => $this->file($l) + ['path' => $l], $route['layouts']);
        try {
            return Reads::during(function () use ($route, $params, $path, $viewer, $page, $layouts): bool {
                $props = new JsObject([
                    'params' => new JsObject(array_map(static fn ($v) => is_array($v) ? new \SpacePress\JsArray($v) : $v, $params)),
                ]);
                if (($page['search'] ?? null) !== null) {
                    $props->p['search'] = $this->search($page['search']);
                }
                if (($route['kind'] ?? null) !== null) {
                    $item = Seam::asViewer($viewer, fn () => $this->item($route['kind'], $params, $path));
                    if ($item === null) {
                        return false;
                    }
                    $props->p['item'] = $item;
                }
                Seam::asViewer($viewer, fn (): array => $this->render($page, $layouts, $props));
                return true;
            });
        } catch (Halt $halt) {
            return $halt->location !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The one spelling of a path below the home URL that a `sp/app/` page answers at ({@see address()}).
     * A path no page serves (a `route.ts`, WordPress's own, a public file) keeps its spelling.
     */
    public function canonical(string $path): string
    {
        return $this->linkPage($path) === null ? $path : self::address($path);
    }

    /**
     * A page's path below the home URL as WordPress spells a permalink: with a trailing slash when the
     * permalink structure has one (`user_trailingslashit`; SpacePress's is Hardening::PERMALINKS, which
     * is what a request answered before WordPress sets up its rewrites, a prebuilt file, goes by). The
     * front page and a `.md` twin keep their spelling; callers pass page paths only ({@see canonical()}).
     */
    public static function address(string $path): string
    {
        if ($path === '' || $path === '/' || str_ends_with($path, '.md')) {
            return $path === '' ? '/' : $path;
        }
        $path = rtrim($path, '/');
        if (!isset($GLOBALS['wp_rewrite'])) {
            return str_ends_with(Hardening::PERMALINKS, '/') ? $path . '/' : $path;
        }
        return user_trailingslashit($path);
    }

    /** @param array<string, mixed> $route whether the route serves a kind whose url is the site root, `/:path` */
    private function rootKind(array $route): bool
    {
        $name = $route['kind']['name'] ?? null;
        return is_string($name) && ($this->plugin->schema->collection($name)['url'] ?? null) === '/:path';
    }

    /** @param array<string, mixed> $route whether WordPress resolved this request to an item of the root kind the route serves */
    private function rootItemQueried(array $route): bool
    {
        $post = get_queried_object();
        return is_singular() && $post instanceof \WP_Post && $post->post_type === $this->kindType($route);
    }

    /** @param array<string, mixed> $route the post type of the kind the route serves */
    private function kindType(array $route): ?string
    {
        $name = $route['kind']['name'] ?? null;
        return is_string($name) ? ($this->plugin->schema->collection($name)['wp']['postType'] ?? null) : null;
    }

    /** The main query as WordPress sets it for a singular Page: `is_page()`, `get_queried_object()`, `$post`. */
    private static function query(?\WP_Post $post): void
    {
        if (!$post instanceof \WP_Post || $post->post_type !== 'page') {
            return;
        }
        $query = new \WP_Query();
        $query->query = ['page_id' => $post->ID] + array_intersect_key($GLOBALS['wp']->query_vars ?? [], array_flip(['order-received', 'order-pay']));
        // The posts are already loaded, so set the defaults that get_posts() would supply.
        $query->query_vars = $query->fill_query_vars($query->query + [
            'fields' => 'all',
            'cache_results' => true,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
        ]);
        $query->queried_object = $post;
        $query->queried_object_id = $post->ID;
        $query->posts = [$post];
        $query->post = $post;
        $query->post_count = 1;
        $query->found_posts = 1;
        $query->max_num_pages = 1;
        $query->is_page = true;
        $query->is_singular = true;
        $query->is_home = false;
        $GLOBALS['wp_query'] = $query;
        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['post'] = $post;
        $query->setup_postdata($post);
    }

    private function notFound(bool $sharedItemMiss = false): never
    {
        global $wp_query;
        $wp_query->set_404();
        $route = $this->app['notFound'] ?? null;
        $viewer = CoreAccess::viewer() ?? new Viewer(null);
        if (!is_array($route)) {
            // The theme's own 404 template, as WordPress renders it, with core's title.
            $this->template = BlockTemplates::NOT_FOUND;
            if ($sharedItemMiss && $viewer->user === null) {
                $this->cacheControl = 'public, max-age=0, s-maxage=60, must-revalidate';
            }
            $this->document(404, ['title' => __('Page not found'), 'coreTitle' => true], '<h1>' . esc_html__('Page not found') . '</h1>');
        }
        $this->serve($route, [], $viewer, 404);
    }

    /**
     * A page the app serves at an address WordPress's own query found nothing at (a route with
     * parameters that names no kind item, such as `staticParams`): not WordPress's 404, so neither
     * its title nor `error404`. A kind URL never needs this: its rewrite rule resolves the item, so
     * WordPress found it (KindUrls::request, paged()).
     */
    private static function found(): void
    {
        global $wp_query;
        if ($wp_query instanceof \WP_Query && $wp_query->is_404()) {
            $wp_query->is_404 = false;
            status_header(200);
        }
    }

    /**
     * wp-login.php's sign-in view (`action=login`, the default, and `checkemail`), rendered by
     * `login.tsx` inside the root layout. `wp_login_errors` is the last thing wp-login.php does
     * before printing its own page: the sign-in has already run (`wp_signon`, so `authenticate`,
     * Identity's limits and Two Factor's second step are behind us), and what is left is markup.
     * The form posts back to wp-login.php. Anything this can't render (an interim sign-in inside
     * wp-admin, the Customizer's, a render error) falls through to WordPress's own page.
     *
     * Other actions (lost password, reset, register, logout, Two Factor's screens) keep
     * WordPress's own pages (docs/decisions/2026-11-15-one-sign-in-page.md).
     */
    public function login(mixed $errors, mixed $redirectTo = ''): mixed
    {
        global $action;
        if (!$errors instanceof \WP_Error || !in_array($action ?? 'login', ['login', 'checkemail'], true)
            || isset($_REQUEST['interim-login']) || isset($_REQUEST['customize-login']) || headers_sent()
            || $this->plugin->unavailable() !== null) {
            return $errors;
        }
        $route = $this->app['login'] ?? null;
        // Without login.tsx, WordPress's own page stays until the owner edits the sign-in template.
        if (!is_array($route) && !BlockTemplates::loginCustomized()) {
            return $errors;
        }
        $login = '';
        if (isset($_POST['log']) && is_string($_POST['log']) && in_array($errors->get_error_code(), ['incorrect_password', 'empty_password'], true)) {
            $login = wp_unslash($_POST['log']);
        }
        // Everything that can fail (the app's boot, Identity's storage behind the viewer, the
        // render) falls back to WordPress's own page: nobody is ever locked out of signing in.
        try {
            $this->plugin->app();
            \SpacePress\Render\LoginView::capture($errors, is_string($redirectTo) ? $redirectTo : '', $login);
            $viewer = CoreAccess::viewer() ?? new Viewer(null);
            $this->template = BlockTemplates::LOGIN;
            if (is_array($route)) {
                $page = $this->file($route['page']);
                // The root layouts wrap the page; their `guard` doesn't apply to the page that signs people in.
                $layouts = $this->layouts($route);
                [$head, $body] = Seam::asViewer($viewer, fn (): array => $this->render($page, $layouts, \SpacePress\Render\LoginView::props()));
            } else {
                // The owner's sign-in template, with the `sp/login-form` block in it.
                [$head, $body] = [['title' => __('Log In')], ''];
            }
        } catch (Halt $halt) {
            if ($halt->location === null) {
                return $errors;
            }
            wp_redirect(str_starts_with($halt->location, '/') && !str_starts_with($halt->location, '//') ? home_url(self::href($halt->location)) : $halt->location, $halt->status);
            exit;
        } catch (\Throwable $error) {
            error_log('SpacePress login.tsx failed, WordPress\'s sign-in page shown instead: ' . $error::class . ': ' . $error->getMessage());
            return $errors;
        }
        // What wp-login.php does after the filter: a reauth drops the old cookies, and the
        // password-reset cookie is cleared once it has named the login.
        if (!empty($_REQUEST['reauth'])) {
            wp_clear_auth_cookie();
        }
        $reset = 'wp-resetpass-' . COOKIEHASH;
        if (isset($_COOKIE[$reset]) && is_string($_COOKIE[$reset])) {
            [$path] = explode('?', wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
            setcookie($reset, ' ', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true);
        }
        $this->styles = is_array($route) ? ($route['styles'] ?? []) : [];
        $this->preloads = is_array($route) ? ($route['preloads'] ?? []) : [];
        // What plugins load for the sign-in form's own fields (a captcha, Two Factor's scripts), as
        // login_header() fires it.
        do_action('login_enqueue_scripts');
        // And what they print in the page's head and foot (a captcha's script, an anti-bot check):
        // the app's document has `wp_head` and `wp_footer` where wp-login.php has these.
        // Core's own callbacks on them print what wp_head and wp_footer already print here.
        foreach (['wp_robots' => 1, 'wp_resource_hints' => 8, 'wp_print_head_scripts' => 9, 'print_admin_styles' => 9, 'wp_site_icon' => 99] as $core => $priority) {
            remove_action('login_head', $core, $priority);
        }
        remove_action('login_footer', 'wp_print_footer_scripts', 20);
        remove_action('login_footer', 'wp_prefetch_admin_assets', 21);
        add_action('wp_head', static fn () => do_action('login_head'), 99);
        add_action('wp_footer', static fn () => do_action('login_footer'), 1);
        // Sign-in pages are never indexed (wp_robots_sensitive_page) and never shared.
        $head['robots'] = ['index' => false, 'follow' => false];
        add_filter('wp_robots', 'wp_robots_sensitive_page');
        add_action('wp_head', 'wp_strict_cross_origin_referrer');
        $this->cacheControl = 'private, no-store, max-age=0';
        do_action('sp_app_login_rendered', (string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $this->document(200, $head, $body);
    }

    /**
     * @param array<string, string|list<string>> $params
     * @param ?string $moved the page's one address, when the request spelled it otherwise: a page
     *   that renders for this visitor redirects there instead
     */
    private function serve(array $route, array $params, Viewer $viewer, int $status, ?string $moved = null): never
    {
        $this->styles = $route['styles'] ?? [];
        $this->preloads = $route['preloads'] ?? [];
        if ($status !== 404) {
            self::found();
        }
        $this->template = $status === 404 ? BlockTemplates::NOT_FOUND : BlockTemplates::slug($route['template']);
        $guards = $route['guards'];
        $plan = $route['plan'] ?? [];
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $missingItem = false;
        if ($moved !== null && ($plan['class'] ?? null) === 'immutable'
            && $viewer->user === null
            && RoutePlan::requestAllowsSharing($this->plugin) && \SpacePress\Render\Cache::shareable($uri, [])) {
            // This fixed page cannot reject the request or choose another response while rendering.
            if (wp_safe_redirect($moved, 301)) {
                [$control] = \SpacePress\Render\Cache::policy(200, true, false, (int) $plan['window']);
                \SpacePress\Render\Cache::send($control);
                exit;
            }
        }
        try {
            $allowed = $this->pageAllowed($guards, $viewer);
            if (!$allowed && $viewer->user === null) {
                // Back to this page after signing in, at its one address including the query.
                $query = (string) (wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '');
                $back = home_url($this->canonical((string) ($this->path() ?? '/'))) . ($query !== '' ? '?' . $query : '');
                self::privateResponse();
                wp_redirect(wp_login_url($back));
                exit;
            }
            if (!$allowed) {
                $this->document(403, ['title' => __('Forbidden')], '<h1>' . esc_html__('You do not have access to this page.') . '</h1>');
            }
            $page = $this->file($route['page']);
            $layouts = $this->layouts($route);
            $app = $this->plugin->app(); // the compiled page functions live in the app's functions.php
            $props = new JsObject([
                'params' => new JsObject(array_map(static fn ($v) => is_array($v) ? new \SpacePress\JsArray($v) : $v, $params)),
            ]);
            if (($page['search'] ?? null) !== null) {
                $props->p['search'] = $this->search($page['search']);
            }
            // The item a kind URL names is one of the render's reads: a write to any of its fields
            // purges this URL (and rebuilds a prebuilt one), not only a write core's post hooks see.
            [$head, $body] = \SpacePress\Render\Live::page($app->db, $status === 200 ? $viewer : null, function () use ($route, $params, $viewer, $props, $page, $layouts, &$missingItem): array {
                if (($route['kind'] ?? null) !== null) {
                    $item = Seam::asViewer($viewer, fn () => $this->item($route['kind'], $params));
                    if ($item === null) {
                        $missingItem = true;
                        throw Halt::notFoundSignal();
                    }
                    $props->p['item'] = $item;
                }
                return Seam::asViewer($viewer, fn (): array => $this->render($page, $layouts, $props));
            }, $reads, $also);
        } catch (Halt $halt) {
            if ($halt->location !== null) {
                self::privateResponse();
                wp_redirect(str_starts_with($halt->location, '/') && !str_starts_with($halt->location, '//') ? home_url(self::href($halt->location)) : $halt->location, $halt->status);
                exit;
            }
            if ($status === 404) {
                $this->document(404, ['title' => __('Page not found')], '<h1>' . esc_html__('Page not found') . '</h1>');
            }
            $this->notFound($missingItem && $moved === null && $this->shareableItemMiss($route, $params, $viewer, $uri));
        } catch (\SpacePress\HttpError $error) {
            // A read the visitor may not make (a guarded query) is not found for them.
            if ($error->status === 401 || $error->status === 403 || $error->status === 404) {
                $this->notFound();
            }
            $this->failed($route, $error, $viewer);
        } catch (\Throwable $error) {
            $this->failed($route, $error, $viewer);
        }
        if ($moved !== null) {
            // Only public pages share their canonical redirect. A guard may have admitted this visitor alone.
            \SpacePress\Render\Cache::send(\SpacePress\Render\Cache::PRIVATE);
            wp_safe_redirect($moved, 301);
            exit;
        }
        // Cache policy and purges (rendering.md §6.1, §6.2): an anonymous visitor's render of a
        // page without a guard is shared, unless the request carries a cookie a render may read (a
        // comment author's name, an unlocked post's password); what it read decides which writes
        // purge its URL.
        // A page a form came back to (`?_flash=`), or one that ran a search, shows that visitor's
        // result: never shared, never recorded for purges.
        $declared = $plan['search'] ?? array_keys($page['search'] ?? []);
        $anonymous = $viewer->user === null && RoutePlan::requestAllowsSharing($this->plugin)
            && !\SpacePress\Render\Forms::marked() && !\SpacePress\Render\SearchView::marked() && !\SpacePress\Render\LoginView::private()
            && \SpacePress\Render\Cache::shareable($uri, $declared);
        [$this->cacheControl, $shared] = \SpacePress\Render\Cache::policy(
            $status, $anonymous, $guards !== [] || ($plan['class'] ?? null) === 'request-private',
            (int) ($plan['window'] ?? \SpacePress\Render\Cache::WINDOW),
        );
        if ($shared && !RoutePlan::covers($plan, (string) $route['pattern'], $uri, $reads, $also)) {
            $shared = \SpacePress\Render\Cache::record($uri, $reads, $also, $declared);
        }
        // A URL the read index can't hold (a query parameter the page doesn't declare, one query
        // variant too many) can't be purged, so its response is this visitor's alone.
        if (!$shared) {
            $this->cacheControl = \SpacePress\Render\Cache::PRIVATE;
            // Nor may it become a prebuilt file, which no write would know to rebuild.
            if ($status === 200) {
                \SpacePress\Render\Prebuilt::unrecorded();
            }
        }
        do_action('sp_app_rendered', (string) $route['id'], (string) ($_SERVER['REQUEST_URI'] ?? '/'), $reads);
        $this->document($status, $head, $body);
    }

    /** Core purges the canonical address when a public post is published or moves here. */
    private function shareableItemMiss(array $route, array $params, Viewer $viewer, string $uri): bool
    {
        if (($route['plan']['class'] ?? null) !== 'anonymous-public' || $viewer->user !== null
            || \SpacePress\Render\Forms::marked()
            || \SpacePress\Render\Prebuilt::prerendering() || !RoutePlan::requestAllowsSharing($this->plugin)
            || !\SpacePress\Render\Cache::shareable($uri, [])) {
            return false;
        }
        // Restrict the negative cache to an ordinary slug with one canonical spelling. Native
        // taxonomy, hierarchical and custom URL patterns have different move and publish rules.
        $spec = $this->plugin->schema->collection($route['kind']['name']);
        $path = $this->path();
        $slug = $params[$route['kind']['params']['slug'] ?? ''] ?? null;
        return array_keys($route['kind']['params']) === ['slug'] && isset($spec['wp']['postType'])
            && empty($spec['hierarchical']) && !isset($spec['commerce'])
            && !\SpacePress\Locale\Module::localized($route['kind']['name'])
            && $path !== null && $uri === wp_parse_url(home_url($this->canonical($path)), PHP_URL_PATH)
            && is_string($slug) && preg_match('/^[a-z0-9-]+$/D', $slug) === 1
            // A row excluded by a read guard can become visible without this post being saved.
            && get_page_by_path($slug, OBJECT, [$spec['wp']['postType']]) === null;
    }

    /**
     * The item a kind URL names, read as the visitor (the caller binds the viewer), or null when it
     * is missing, not published to them, or the URL isn't its canonical one (`/2025/hello` for a
     * post from 2026). `:slug`/`:path` find the item by slug, `:id` by id. `$path` is the URL path
     * (below the home URL) to check against, the request's by default.
     *
     * @param array{name: string, params: array<string, string>} $kind
     * @param array<string, string|list<string>> $params
     */
    private function item(array $kind, array $params, ?string $path = null): ?\SpacePress\JsObject
    {
        $name = $kind['name'];
        $spec = $this->plugin->schema->collection($name);
        $value = static fn (string $param) => is_array($params[$param] ?? null) ? implode('/', $params[$param]) : (string) ($params[$param] ?? '');
        $where = null;
        foreach ($kind['params'] as $field => $param) {
            if ($field === 'id' && ctype_digit($value($param))) {
                $where = [$spec['primaryKey'] => (int) $value($param)];
            } elseif ($field === 'path' && ($spec['hierarchical'] ?? false) && empty($spec['taxonomy'])) {
                // The whole path, parent by parent: a slug is unique among its siblings only.
                $post = get_page_by_path($value($param), OBJECT, (string) $spec['wp']['postType']);
                if (!$post instanceof \WP_Post) {
                    return null;
                }
                // Under an unpublished page, a page is as unreachable as in the tree: its path
                // would name the draft. Editors preview a draft's children in wp-admin.
                foreach (get_post_ancestors($post) as $ancestor) {
                    if (get_post_status($ancestor) !== 'publish') {
                        return null;
                    }
                }
                $where = [$spec['primaryKey'] => $post->ID];
            } elseif (($field === 'slug' || $field === 'path') && ($spec['slug'] ?? null) !== null) {
                $parts = explode('/', $value($param));
                $where = [$spec['slug'] => (string) end($parts)];
            }
        }
        if ($where === null) {
            return null;
        }
        // A URL names one row whatever its language: each translation has its own slug, and a read
        // without `locale` would see only the default locale's rows (Locale\Read).
        $locale = \SpacePress\Locale\Module::localized($name) ? ['locale' => '*'] : [];
        try {
            // The row this URL names: the page depends on that row, not on a listing of its kind.
            $row = Reads::named($name, static fn () => Seam::findFirst($name, ['where' => $where] + $locale));
        } catch (\SpacePress\HttpError) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        // The URL must be the item's own: other placeholders (`:year`, a field) have to agree.
        $id = (int) ($row->p[$spec['primaryKey']] ?? 0);
        $urls = KindUrls::instance();
        if ($urls !== null) {
            // Both sides without the home path: under a subdirectory install the request path
            // starts with it (`/blog/notes/a`), and the item's path never does (`/notes/a`).
            if (!empty($spec['taxonomy'])) {
                $term = get_term($id, $spec['wp']['taxonomy']);
                if (!$term instanceof \WP_Term) { return null; }
                $want = $urls->termPath($name, $term);
            } else {
                $post = get_post($id);
                $want = $post instanceof \WP_Post ? $urls->path($name, $post) : null;
            }
            $have = '/' . trim((string) ($path ?? $this->path()), '/');
            if ($want !== null && rawurldecode($have) !== rawurldecode($want)) {
                return null;
            }
        }
        // The visitor read the item just now: what the page renders around it (its comments) need
        // not ask the kind's read rule about it again.
        if (($reader = Seam::viewer()) !== null && ($spec['wp']['postType'] ?? null) !== null
            && \SpacePress\Extensions::constraintReadsSafe()) {
            CoreAccess::readable($name, [$id], $reader);
        }
        return $row;
    }

    /**
     * The app's public static pages for `/llms.txt`: (path, title), for each page route with no
     * parameters and no guard, titled by its metadata (a literal title, else its path).
     *
     * @return list<array{string, string}>
     */
    public function pages(): array
    {
        $out = [];
        foreach ($this->app['routes'] as $route) {
            if (($route['page'] ?? null) === null || str_contains((string) $route['pattern'], ':') || str_contains((string) $route['pattern'], '*')) {
                continue;
            }
            if ($route['guards'] !== []) {
                continue;
            }
            // Only pages that render: the twin's heading is the page's title.
            $twin = $this->twin(home_url($route['pattern']));
            if ($twin === null || $twin[0] !== 200) {
                continue;
            }
            $title = preg_match('/^# (.+)$/m', $twin[1], $m) === 1 ? $m[1] : (string) $route['pattern'];
            $out[] = [(string) $route['pattern'], $title];
        }
        return $out;
    }

    /**
     * Whether a URL is a `sp/app/` page anyone may see, so whether it has a Markdown twin: a
     * page route (not a handler), without a page or layout guard.
     */
    public function serves(string $url): bool
    {
        $route = $this->twinRoute($url);
        return $route !== null;
    }

    /**
     * Whether a guarded `sp/app/` page serves this path (below the home URL): a
     * signed-out visitor is sent to sign in there, so the address is not public.
     */
    public function guarded(string $path, ?string $postType = null): bool
    {
        $match = $this->reserved($path) ? null : $this->matchPath($path);
        if ($match === null || ($match['route']['page'] ?? null) === null) {
            return false;
        }
        $route = $match['route'];
        if ($route['guards'] === []) {
            return false;
        }
        // A kind mounted at /:path leaves other post types and WordPress archives to the theme.
        return $postType === null || !$this->rootKind($route) || $this->kindType($route) === $postType;
    }

    /** @return ?array{route: array, params: array} */
    private function twinRoute(string $url): ?array
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        if ($this->reserved($path) || \SpacePress\Commerce\Module::privatePath($this->plugin, $path)) {
            return null;
        }
        $match = $this->matchPath($path);
        if ($match === null || ($match['route']['page'] ?? null) === null) {
            return null;
        }
        if ($match['route']['guards'] !== []) {
            return null;
        }
        return $match;
    }

    /**
     * A `sp/app/` page's Markdown twin (rendering.md §5: "code pages render from their compiled
     * output"): the page rendered as an anonymous visitor, its title as the heading, the page's own
     * HTML (not its layouts' navigation) converted to Markdown. Null when the URL is not an app
     * page, so the WordPress twin answers. A missing item or `notFound()` is a 404 twin. `$reads`
     * gets what the render read, `$also` the globals among it and `$search` the page's declared
     * query parameters, for the caller that serves the twin to record (rendering.md §6.2).
     *
     * @param-out array<string, array{ids: list<int|string>, listed: bool}> $reads
     * @param-out list<string> $also
     * @param-out list<string> $search
     * @return ?array{int, string}
     */
    public function twin(string $url, ?array &$reads = null, ?array &$also = null, ?array &$search = null): ?array
    {
        $reads = [];
        $also = [];
        $search = [];
        $match = $this->twinRoute($url);
        if ($match === null) {
            return null;
        }
        $this->plugin->app();
        $viewer = new Viewer(null);
        $route = $match['route'];
        $page = $this->file($route['page']);
        $search = array_keys($page['search'] ?? []);
        $layouts = array_map(fn (string $l): array => $this->file($l) + ['path' => $l], $route['layouts']);
        try {
            $rendered = Reads::during(function () use ($match, $route, $page, $layouts, $viewer): ?array {
                $props = new JsObject([
                    'params' => new JsObject(array_map(static fn ($v) => is_array($v) ? new \SpacePress\JsArray($v) : $v, $match['params'])),
                ]);
                if (($page['search'] ?? null) !== null) {
                    $props->p['search'] = $this->search($page['search']);
                }
                if (($route['kind'] ?? null) !== null) {
                    $item = Seam::asViewer($viewer, fn () => $this->item($route['kind'], $match['params']));
                    if ($item === null) {
                        // A kind at the site root has only its own items: WordPress's twin answers
                        // the rest. When WordPress resolved the path to an item of this kind the
                        // visitor may not read, the twin is a 404 like the page.
                        return $this->rootKind($route) && !$this->rootItemQueried($route) ? ['wordpress' => true] : null;
                    }
                    $props->p['item'] = $item;
                }
                // The page alone: its layouts add navigation and chrome, which a twin leaves out.
                return Seam::asViewer($viewer, fn (): array => $this->render($page, $layouts, $props, false));
            }, $reads, $also);
            if ($rendered === null) {
                return [404, "# Not found\n\nNothing is published at this address.\n"];
            }
            if (isset($rendered['wordpress'])) {
                $reads = [];
                $also = [];
                return null;
            }
            [$head, $body] = $rendered;
        } catch (Halt $halt) {
            return $halt->location === null ? [404, "# Not found\n\nNothing is published at this address.\n"] : null;
        } catch (\Throwable $error) {
            error_log('SpacePress page twin ' . ($route['id'] ?? '?') . ' failed: ' . $error::class . ': ' . $error->getMessage());
            return [500, "# Something went wrong\n"];
        }
        $markdown = trim(\SpacePress\Sync\Markdown::fromRenderedHtml($body));
        $title = trim((string) $head['title']);
        $doc = ($title !== '' && !str_starts_with($markdown, '# ') ? '# ' . $title . "\n\n" : '') . $markdown;
        return [200, rtrim($doc) . "\n"];
    }

    /**
     * One `<Live>` region of a `sp/app/` page as $viewer sees it, for the subscription endpoint
     * (`Live\Module`, op `page`): the render a GET of $url gets, without its writes. A guard that
     * refuses the viewer, `notFound()`, a redirect, a missing item or a page with no region `$region`
     * is an `HttpError`, never HTML; nothing is recorded for the page cache, no cookie is set, and
     * no form or action runs.
     *
     * @return array{html: string, reads: array, unseen: bool}
     */
    public function liveRegion(string $url, string $region, Viewer $viewer): array
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        // Below a home URL in a subdirectory, the route is the path after it, as for a GET.
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $match = $this->reserved($path) ? null : $this->matchPath($path);
        if ($match === null || ($match['route']['page'] ?? null) === null) {
            throw new \SpacePress\HttpError(404, 'not_found', 'No page at ' . $path);
        }
        $route = $match['route'];
        if (!$this->pageAllowed($route['guards'], $viewer)) {
            throw $viewer->user === null
                ? new \SpacePress\HttpError(401, 'unauthenticated', 'Sign in to see this page')
                : new \SpacePress\HttpError(403, 'forbidden', 'You do not have access to this page');
        }
        $this->plugin->app();
        $page = $this->file($route['page']);
        $layouts = $this->layouts($route);
        // The render sees the request a GET of $url would be: the page's typed search reads its query
        // string, and a kind item's canonical-URL check and its components read its URI.
        $saved = [$_SERVER['QUERY_STRING'] ?? null, $_SERVER['REQUEST_URI'] ?? null];
        $_SERVER['QUERY_STRING'] = $query;
        $_SERVER['REQUEST_URI'] = $url;
        try {
            $captured = \SpacePress\Render\Live::capture($region, function () use ($page, $layouts, $route, $match, $viewer): void {
                $props = new JsObject([
                    'params' => new JsObject(array_map(static fn ($v) => is_array($v) ? new \SpacePress\JsArray($v) : $v, $match['params'])),
                ]);
                if (($page['search'] ?? null) !== null) {
                    $props->p['search'] = $this->search($page['search']);
                }
                if (($route['kind'] ?? null) !== null) {
                    $item = Seam::asViewer($viewer, fn () => $this->item($route['kind'], $match['params']));
                    if ($item === null) {
                        throw Halt::notFoundSignal();
                    }
                    $props->p['item'] = $item;
                }
                Seam::asViewer($viewer, fn (): array => $this->render($page, $layouts, $props));
            });
        } catch (Halt) {
            throw new \SpacePress\HttpError(404, 'not_found', 'Nothing is shown at ' . $path);
        } catch (\SpacePress\HttpError | \SpacePress\JsError $error) {
            throw $error;
        } catch (\Throwable $error) {
            // A page that fails renders its error page on a GET; here it is this read's 500, logged,
            // and the batch's other reads still answer.
            error_log('SpacePress live region ' . ($route['id'] ?? '?') . "#$region failed: " . $error::class . ': ' . $error->getMessage());
            throw new \SpacePress\HttpError(500, 'internal', 'This live region failed to render');
        } finally {
            foreach (['QUERY_STRING', 'REQUEST_URI'] as $i => $key) {
                if ($saved[$i] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $saved[$i];
                }
            }
        }
        do_action('sp_app_live_rendered', (string) $route['id'], $region);
        if (!$captured['found'] || $captured['html'] === null) {
            throw new \SpacePress\HttpError(404, 'not_found', "The page at $path has no live region '$region'");
        }

        return ['html' => $captured['html'], 'reads' => $captured['reads'], 'unseen' => $captured['unseen'], 'notices' => array_keys($captured['extra'] ?? [])];
    }

    /** A render error: logged, and the nearest `error.tsx` (or a plain message) with a 500. */
    private function failed(array $route, \Throwable $error, Viewer $viewer): never
    {
        error_log('SpacePress page ' . ($route['id'] ?? '?') . ' failed: ' . $error::class . ': ' . $error->getMessage());
        $file = $this->file($route['error'] ?? null);
        if ($file !== null) {
            try {
                $layouts = $this->layouts($route);
                [$head, $body] = Seam::asViewer($viewer, fn (): array => $this->render($file, $layouts, new JsObject(['params' => new JsObject([])])));
                $this->document(500, $head, $body);
            } catch (\Throwable $again) {
                error_log('SpacePress error page failed too: ' . $again->getMessage());
            }
        }
        $this->document(500, ['title' => __('Something went wrong')], '<h1>' . esc_html__('Something went wrong.') . '</h1>');
    }

    /** @param list<string> $guards */
    private function pageAllowed(array $guards, Viewer $viewer): bool
    {
        foreach ($guards as $path) {
            if (!$this->allowed($this->app['files'][$path]['guard'], $viewer)) {
                return false;
            }
        }
        return true;
    }

    private function allowed(array $rule, Viewer $viewer): bool
    {
        if (!\SpacePress\Access::isShorthand($rule)) {
            $this->plugin->app(); // A callback guard needs the compiled functions before the page does.
        }
        $result = \SpacePress\Access::evaluate($rule, $viewer, 'read');
        return $result === true;
    }

    /** The page's typed `search`: each field parsed from the query string like action input. */
    private function search(array $fields): JsObject
    {
        $raw = [];
        foreach (\SpacePress\QueryString::parse((string) ($_SERVER['QUERY_STRING'] ?? '')) as [$name, $value]) {
            if (!isset($fields[$name]) || array_key_exists($name, $raw)) {
                continue;
            }
            $type = $fields[$name]['type'];
            $raw[$name] = match ($type) {
                'integer' => preg_match('/\A-?\d{1,15}\z/', $value) === 1 ? (int) $value : $value,
                'real' => is_numeric($value) ? (float) $value : $value,
                'boolean' => match ($value) { 'true', '1', '' => true, 'false', '0' => false, default => $value },
                default => $value,
            };
        }
        $out = [];
        foreach ($fields as $name => $field) {
            try {
                $out += \SpacePress\Schema::normalizeFields([$name => $field], array_key_exists($name, $raw) ? [$name => $raw[$name]] : [], '');
            } catch (\SpacePress\HttpError) {
                // A value the field refuses (`?page=abc`) is as if it were absent.
                $out += \SpacePress\Schema::normalizeFields([$name => $field], [], '');
            }
        }
        return Seam::toJs($out, $fields);
    }

    /**
     * The page inside its layouts, innermost first, and the merged head.
     *
     * @param list<array<string, mixed>> $layouts
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function render(?array $page, array $layouts, JsObject $props, bool $wrap = true): array
    {
        Navigation::reset();
        \SpacePress\Render\Forms::reset();
        $metadata = [];
        // A twin is the page alone: its heading is the page's own title, not a layout's template.
        foreach ($wrap ? [...$layouts, $page] : [$page] as $file) {
            $meta = $file['metadata'] ?? null;
            if (is_array($meta) && isset($meta['fn'])) {
                $fn = '\\SpacePressApp\\' . $meta['fn'];
                $meta = Seam::toPhp($fn($props));
            }
            $metadata[] = is_array($meta) ? $meta : [];
        }
        $fn = '\\SpacePressApp\\' . $page['component'];
        $html = Html::component($fn(...), $props);
        foreach ($wrap ? array_reverse($layouts) : [] as $layout) {
            if (($layout['chrome'] ?? true) === false) {
                continue;
            }
            $layoutProps = new JsObject(['params' => $props->p['params'], 'children' => $html]);
            $layoutFn = '\\SpacePressApp\\' . $layout['component'];
            $html = Html::component($layoutFn(...), $layoutProps);
        }
        // Rendering runs every component now, so their reads happen inside the visitor binding.
        $body = Html::child($html);
        return [self::head($metadata), $body];
    }

    /**
     * Metadata from the root layout down: later files override earlier ones field by field
     * (`openGraph` and `twitter` too), `links` add up, and the nearest layout's `title.template`
     * wraps the page's own title.
     *
     * @param list<array<string, mixed>> $metadata
     * @return array<string, mixed>
     */
    public static function head(array $metadata): array
    {
        $template = null;
        $title = null;
        $out = [];
        foreach ($metadata as $meta) {
            foreach ($meta as $key => $value) {
                if ($key === 'title') {
                    if (is_array($value)) {
                        $template = $value['template'] ?? null;
                        $title = $value['default'] ?? $title;
                        $out['titleIsDefault'] = true;
                    } elseif (is_string($value)) {
                        $title = $value;
                        $out['titleIsDefault'] = false;
                    }
                    continue;
                }
                if ($key === 'scripts' && is_array($value)) {
                    // Each layout's scripts and the page's all load, each once, layouts' first.
                    $out['scripts'] = array_values(array_unique([...($out['scripts'] ?? []), ...array_values($value)]));
                    continue;
                }
                if ($key === 'links' && is_array($value)) {
                    // Each link once, the first as written. Two links are the same whatever order
                    // their keys are in, and a stylesheet's default type is left out of the comparison.
                    $links = [...($out['links'] ?? []), ...array_values($value)];
                    $seen = [];
                    $out['links'] = [];
                    foreach ($links as $link) {
                        $key = $link;
                        if (is_array($key)) {
                            if (($key['rel'] ?? null) === 'stylesheet' && ($key['type'] ?? null) === 'text/css') {
                                unset($key['type']);
                            }
                            ksort($key);
                        }
                        // Invalid UTF-8 is substituted, not a failed encoding every such link would share.
                        $key = json_encode($key, JSON_INVALID_UTF8_SUBSTITUTE) ?: serialize($key);
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $out['links'][] = $link;
                        }
                    }
                    continue;
                }
                if (($key === 'openGraph' || $key === 'twitter') && is_array($value)) {
                    // A page sets only what it changes: its image, say, under the layout's type.
                    $out[$key] = array_filter($value, static fn ($v) => $v !== null) + ($out[$key] ?? []);
                    continue;
                }
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }
        }
        if ($title !== null && $template !== null && !($out['titleIsDefault'] ?? false)) {
            $title = str_replace('%s', $title, (string) $template);
        }
        unset($out['titleIsDefault']);
        $out['title'] = $title ?? get_bloginfo('name');
        return $out;
    }

    /**
     * `metadata.scripts`: script modules the page loads, through WordPress's script-module API so
     * plugins that manage scripts see them. A path is on this site (a file in `public/`); the
     * compiler accepts only such paths and https:// URLs, and a function's result is checked here.
     *
     * @param array<string, mixed> $head
     */
    private function scripts(array $head): void
    {
        foreach (is_array($head['scripts'] ?? null) ? $head['scripts'] : [] as $src) {
            if (!is_string($src) || !((str_starts_with($src, '/') && !str_starts_with($src, '//')) || str_starts_with($src, 'https://'))) {
                continue;
            }
            $handle = 'spacepress-app-script-' . substr(md5($src), 0, 12);
            if (str_starts_with($src, '/')) {
                $path = wp_parse_url($src, PHP_URL_PATH);
                $version = is_string($path) ? PublicFiles::scriptVersion($this->plugin, rawurldecode(ltrim($path, '/'))) : null;
                if ($version !== null) {
                    // Keep the author's query and place the version before any fragment.
                    $parts = explode('#', $src, 2);
                    $parts[0] .= (str_contains($parts[0], '?') ? '&' : '?') . 'spv=' . $version;
                    $src = implode('#', $parts);
                }
            }
            $client = Plugin::instance()?->schema->commerce() !== null && \SpacePress\Islands::registerClient();
            wp_register_script_module($handle, self::absolute($src), $client ? [\SpacePress\Islands::CLIENT] : [], null);
            wp_enqueue_script_module($handle);
            if ($client) {
                \SpacePress\Islands::useClient();
            }
        }
    }

    /** A path on this site (`/og/a.png`) as an absolute URL; anything else as it is. */
    private static function absolute(string $url): string
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? home_url($url) : $url;
    }

    /**
     * A same-site URL path as a link to it is written: a page's path at its one address
     * ({@see canonical()}), so a link or a `rel="canonical"` never points at a redirect. Its query
     * and fragment are kept; anything else is as it is.
     */
    public static function href(string $url): string
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $url;
        }
        $path = substr($url, 0, strcspn($url, '?#'));
        return (self::$instance?->canonical($path) ?? $path) . substr($url, strlen($path));
    }

    /**
     * The head's Open Graph and Twitter card tags and its extra `<link>`s, from `metadata.openGraph`,
     * `metadata.twitter` and `metadata.links`. Open Graph's title and description default to the
     * page's own, and its URL is the canonical one; a path is made absolute, as the protocol wants.
     *
     * @param array<string, mixed> $head
     */
    public static function social(array $head): string
    {
        $out = '';
        $graph = is_array($head['openGraph'] ?? null) ? $head['openGraph'] : null;
        if ($graph !== null) {
            $tags = [
                'og:title' => $graph['title'] ?? $head['title'] ?? null,
                'og:description' => $graph['description'] ?? $head['description'] ?? null,
                'og:type' => $graph['type'] ?? 'website',
                'og:url' => is_string($head['canonical'] ?? null) && $head['canonical'] !== '' ? self::absolute(self::href($head['canonical'])) : null,
                'og:image' => is_string($graph['image'] ?? null) && $graph['image'] !== '' ? self::absolute($graph['image']) : null,
            ];
            foreach ($tags as $property => $content) {
                if (is_string($content) && $content !== '') {
                    $url = $property === 'og:url' || $property === 'og:image';
                    $out .= '<meta property="' . $property . '" content="' . ($url ? esc_url($content) : esc_attr($content)) . "\">\n";
                }
            }
        }
        $card = is_array($head['twitter'] ?? null) ? ($head['twitter']['card'] ?? null) : null;
        if (is_string($card) && $card !== '') {
            $out .= '<meta name="twitter:card" content="' . esc_attr($card) . "\">\n";
        }
        foreach (is_array($head['links'] ?? null) ? $head['links'] : [] as $link) {
            if (!is_array($link) || !is_string($link['rel'] ?? null) || !is_string($link['href'] ?? null) || $link['rel'] === '' || $link['href'] === '') {
                continue;
            }
            $type = is_string($link['type'] ?? null) && $link['type'] !== '' ? ' type="' . esc_attr($link['type']) . '"' : '';
            $out .= '<link rel="' . esc_attr($link['rel']) . '" href="' . esc_url(self::absolute($link['href'])) . '"' . $type . ">\n";
        }
        return $out;
    }

    // ------------------------------------------------------------------ output

    /** @param array<string, string> $headers */
    private function emit(int $status, array $headers, string $body): never
    {
        status_header($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $body;
        exit;
    }

    /**
     * The page inside a block template, as `template-canvas.php` prints one: the template's blocks
     * render first (so their styles reach `wp_head`), then the document around them. WordPress
     * writes the `<title>` from the page's metadata (`pre_get_document_title`); SpacePress adds the
     * description, canonical and social tags to `wp_head`. The router region holds the page only,
     * so `<Link>` navigation swaps it and the theme's header and footer stay.
     *
     * @param array<string, mixed> $head
     */
    private function templated(\WP_Block_Template $template, array $head, string $body, int $status): never
    {
        // A non-empty pre_get_document_title is printed as it is, without core's escaping. The
        // router's own fallback pages (WordPress's 404) keep core's title ("Page not found – Site").
        if (!($head['coreTitle'] ?? false)) {
            add_filter('pre_get_document_title', static fn (): string => esc_html((string) $head['title']), PHP_INT_MAX);
        }
        add_action('wp_head', '_block_template_viewport_meta_tag', 0);
        add_action('wp_head', '_block_template_render_title_tag', 1);
        add_action('wp_head', $this->headTags = static function () use ($head): void {
            if (is_string($head['description'] ?? null) && $head['description'] !== '') {
                echo '<meta name="description" content="' . esc_attr($head['description']) . "\">\n";
            }
            if (is_string($head['canonical'] ?? null) && $head['canonical'] !== '') {
                echo '<link rel="canonical" href="' . esc_url(self::absolute(self::href($head['canonical']))) . "\">\n";
            }
            echo self::social($head);
        }, 2);
        $queued = self::queued();
        $html = BlockTemplates::html($template, Navigation::region('sp-page') . $body . Navigation::END);
        if ($html === null) {
            // The template didn't place the page: the default template, else First's own document,
            // without the styles and scripts the failed render's blocks enqueued.
            self::dequeueSince($queued);
            $fallback = $template->slug === BlockTemplates::PAGE ? null : BlockTemplates::resolve(BlockTemplates::PAGE);
            $html = $fallback === null ? null : BlockTemplates::html($fallback, Navigation::region('sp-page') . $body . Navigation::END);
            if ($html === null) {
                self::dequeueSince($queued);
            }
            if ($html === null) {
                $this->template = null;
                $this->plain($head, $body, $status);
            }
            $template = $fallback;
        }
        // The template's blocks ran after the cache policy was set: a sign-in form in a part or
        // template (the Login form block) carries per-request fields, so the page is never shared.
        $this->privateIfLoginFields();
        echo "<!DOCTYPE html>\n<html " . get_language_attributes() . ">\n<head>\n<meta charset=\"" . esc_attr((string) get_option('blog_charset')) . "\">\n";
        wp_head();
        echo "</head>\n<body class=\"" . esc_attr(implode(' ', get_body_class('spacepress-app'))) . '" data-sp-template="' . esc_attr($template->slug) . "\">\n";
        wp_body_open();
        echo $html, "\n";
        wp_footer();
        echo "</body>\n</html>\n";
        $this->finishPage($status);
        exit;
    }

    /** @param array<string, mixed> $head */
    private function document(int $status, array $head, string $body): never
    {
        status_header($status);
        if (str_starts_with($this->cacheControl, 'private')) {
            self::privateResponse();
        }
        \SpacePress\Render\Cache::send($this->cacheControl);
        if (\SpacePress\Render\Prebuilt::prerendering()) {
            // The prebuild writes a file only from an answer the router rendered for it.
            header('X-SpacePress-Prerendered: 1');
        }
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        // SpacePress writes the only <title> and canonical for its pages.
        remove_action('wp_head', '_wp_render_title_tag', 1);
        remove_action('wp_head', '_block_template_render_title_tag', 1);
        remove_action('wp_head', 'rel_canonical');
        $robots = $head['robots'] ?? null;
        if (\SpacePress\Render\SearchView::marked() || \SpacePress\Commerce\Module::privateRequest($this->plugin)) {
            // A results page is no page to index: as the header and the meta tag.
            $robots = ['index' => false] + (is_array($robots) ? $robots : []);
            header('X-Robots-Tag: noindex');
        }
        if (is_array($robots) || $status !== 200) {
            add_filter('wp_robots', static function (array $r) use ($robots, $status): array {
                if ($status !== 200 || ($robots['index'] ?? true) === false) {
                    $r['noindex'] = true;
                }
                if (($robots['follow'] ?? true) === false) {
                    $r['nofollow'] = true;
                }
                return $r;
            });
        }
        \SpacePress\Assets::enqueue($this->styles, $this->preloads);
        \SpacePress\Render\Live::enqueue();
        Navigation::enqueue(plugins_url('sp/Render/client/router.js', Runtime::file()));
        \SpacePress\Islands::registerClient();
        \SpacePress\Islands::enqueue();
        $this->scripts($head);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        if (str_starts_with($this->cacheControl, 'public')) {
            ob_start();
        }
        $template = $this->template !== null && self::themed() ? BlockTemplates::resolve($this->template) : null;
        \SpacePress\Render\Forms::expire($template?->slug ?? 'document');
        if ($template !== null) {
            $this->templated($template, $head, $body, $status);
        }
        $this->plain($head, $body, $status);
    }

    private function finishPage(int $status): void
    {
        if (str_starts_with($this->cacheControl, 'public') && !headers_sent()) {
            // Core or a plugin can enqueue a nonce or set a cookie while the document is printed.
            \SpacePress\Render\Cache::finishPage($this->cacheControl, $status);
        }
    }

    private static function privateResponse(): void
    {
        nocache_headers();
        if (function_exists('batcache_cancel')) {
            batcache_cancel();
        }
    }

    /**
     * A page with a block template rendered, or one the router found private after the template's
     * blocks ran: the Cache-Control header is still unsent, so it can change.
     */
    private function privateIfLoginFields(): void
    {
        if (!\SpacePress\Render\LoginView::private() || str_starts_with($this->cacheControl, 'private')) {
            return;
        }
        if (headers_sent($file, $line)) {
            error_log("SpacePress: a Login form block made this page private, but output started at $file:$line, so its Cache-Control could not change");
            return;
        }
        $this->cacheControl = 'private, no-store, max-age=0';
        nocache_headers();
        \SpacePress\Render\Cache::send($this->cacheControl);
        if (function_exists('batcache_cancel')) {
            batcache_cancel();
        }
        // A prebuilt file would serve one visitor's form fields to everyone.
        \SpacePress\Render\Prebuilt::viewerCall('login_form');
    }

    /**
     * What is enqueued now (styles, scripts, script modules), to take back what a render adds.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private static function queued(): array
    {
        return [wp_styles()->queue, wp_scripts()->queue, function_exists('wp_script_modules') ? wp_script_modules()->get_queue() : []];
    }

    /** @param array{0: list<string>, 1: list<string>, 2: list<string>} $before */
    private static function dequeueSince(array $before): void
    {
        [$styles, $scripts, $modules] = self::queued();
        foreach (array_diff($styles, $before[0]) as $handle) {
            wp_dequeue_style($handle);
        }
        foreach (array_diff($scripts, $before[1]) as $handle) {
            wp_dequeue_script($handle);
        }
        foreach (array_diff($modules, $before[2]) as $id) {
            wp_dequeue_script_module($id);
        }
    }

    /**
     * SpacePress's own document: under a classic theme, or when a block template can't place the page.
     *
     * @param array<string, mixed> $head
     */
    private function plain(array $head, string $body, int $status): never
    {
        // A block template that couldn't place the page may have hooked its title and head tags.
        remove_action('wp_head', '_block_template_render_title_tag', 1);
        remove_action('wp_head', '_block_template_viewport_meta_tag', 0);
        if ($this->headTags !== null) {
            remove_action('wp_head', $this->headTags, 2);
        }
        echo "<!DOCTYPE html>\n<html " . get_language_attributes() . ">\n<head>\n<meta charset=\"" . esc_attr((string) get_option('blog_charset')) . "\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        echo '<title>' . esc_html((string) $head['title']) . "</title>\n";
        if (is_string($head['description'] ?? null) && $head['description'] !== '') {
            echo '<meta name="description" content="' . esc_attr($head['description']) . "\">\n";
        }
        if (is_string($head['canonical'] ?? null) && $head['canonical'] !== '') {
            echo '<link rel="canonical" href="' . esc_url(self::absolute(self::href($head['canonical']))) . "\">\n";
        }
        echo self::social($head);
        wp_head();
        echo "</head>\n<body class=\"" . esc_attr(implode(' ', get_body_class('spacepress-app'))) . "\" data-sp-template=\"document\">\n";
        wp_body_open();
        echo Navigation::region() . $body . Navigation::END . "\n";
        wp_footer();
        echo "</body>\n</html>\n";
        $this->finishPage($status);
        exit;
    }
}
