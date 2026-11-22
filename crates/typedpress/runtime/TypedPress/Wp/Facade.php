<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;
use TypedPress\Js;

/**
 * `/wp-json/typedpress/v1` (spec §3a): one clean HTTP shape over core's routes. Every request is dispatched
 * to the core route with `rest_do_request()` as the caller, and only input and output are mapped, so
 * there is no second permission check, query path or write path to disagree with core:
 *
 *   GET    /wp-json/typedpress/v1/<kind>          → GET  /wp/v2/<base>        { data: rows, cursor }
 *   GET    /wp-json/typedpress/v1/<kind>/<id>     → GET  /wp/v2/<base>/<id>   { data: row } (`?format=md`: markdown)
 *   POST   /wp-json/typedpress/v1/<kind>          → POST /wp/v2/<base>        TypedPress input → core input
 *   PATCH  /wp-json/typedpress/v1/<kind>/<id>     → POST /wp/v2/<base>/<id>
 *   DELETE /wp-json/typedpress/v1/<kind>/<id>     → DELETE /wp/v2/<base>/<id> (post kinds: force)
 *   GET    /wp-json/typedpress/v1/me              → GET  /wp/v2/users/me      { id, name, role } + the user's row
 *   POST   /wp-json/typedpress/v1/actions/<name>  → /wp-abilities/v1/abilities/<app>/<name>/run (GET if readonly)
 *   GET/PATCH /wp-json/typedpress/v1/<global>      → the global's get/update Ability, through the same run route
 *   GET/PATCH /wp-json/typedpress/v1/settings      → GET /wp/v2/settings (public fields from the index for others)
 *   GET    /wp-json/typedpress/v1/menus[/<loc>]    → /wp/v2/menu-locations + /wp/v2/menu-items, public locations only
 *   POST   /wp-json/typedpress/v1/_live           → subscribed reads: "not modified" or fresh results (Live\Module)
 *   GET    /wp-json/typedpress/v1                 → the app's schema: kinds, fields, actions (`typedpress pull`)
 *   …/<kind>/<id>/staged[/compare|/promote|/preview] → a post kind's staged version ({@see Staged})
 *
 * Errors are RFC 9457 problem documents mapped from core's `WP_Error`. It is one route on WordPress's
 * REST server (`typedpress/v1`), so core does what every `/wp-json` route gets: authentication (the
 * cookie with `X-WP-Nonce`, an application password, or a TypedPress Identity bearer token), CORS and
 * preflights, `_method` and `X-HTTP-Method-Override`, and `HEAD`. The callback answers with
 * TypedPress's own body and headers ({@see served()}).
 * 0.8's `/__zero/config`, `/__zero/run`, `/__zero/auth/*` and `/__zero/realtime/events` are not
 * this facade's and are never answered here.
 */
final class Facade
{
    /** The default REST namespace the API is served under. */
    public const NAMESPACE = 'typedpress/v1';
    /** The API's default path on a site with WordPress's default REST prefix (`rest_url()` gives the real one). */
    public const PREFIX = '/wp-json/' . self::NAMESPACE;

    /** Active REST namespace (hosts may override via SP_API_NAMESPACE or `sp_api_namespace`). */
    public static function apiNamespace(): string
    {
        $ns = defined('SP_API_NAMESPACE') && is_string(SP_API_NAMESPACE) && SP_API_NAMESPACE !== ''
            ? SP_API_NAMESPACE
            : self::NAMESPACE;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('sp_api_namespace', $ns);
            if (is_string($filtered) && $filtered !== '') {
                $ns = $filtered;
            }
        }

        return trim($ns, '/');
    }

    /** The absolute URL of a facade path (`/media/42`), as WordPress's REST URL. */
    public static function url(string $path = ''): string
    {
        return rest_url(self::apiNamespace() . $path);
    }

    /**
     * TypedPress's row of a post kind as $viewer reads it (field masks, system fields), or null when the
     * read rule doesn't cover it. Core has already decided the item is readable; this only gives the
     * facade TypedPress's own shape of it (PostsController puts it on the facade's dispatches).
     */
    public static function tpRow(string $collection, int $id, \TypedPress\Viewer $viewer): ?object
    {
        $key = $collection . "\0" . ($viewer->user['id'] ?? 0) . "\0" . ($viewer->user['role'] ?? '');
        if (!isset(self::$rows[$key]) || !array_key_exists($id, self::$rows[$key])) {
            self::prefetch($collection, [$id], $viewer);
        }
        $row = self::$rows[$key][$id] ?? null;

        return $row === null ? null : (object) PostMeta::values($collection, $row, array_map('strval', array_keys($row)));
    }

    /** @var array<string, array<int, ?array>> TypedPress's rows by collection and viewer, then id, for this dispatch's page */
    private static array $rows = [];

    /**
     * TypedPress's rows for $ids as $viewer reads them, in one query: a list page costs one read for all
     * its rows, not one per row. Ids the read rule leaves out are recorded as null.
     *
     * @param list<int> $ids
     */
    public static function prefetch(string $collection, array $ids, \TypedPress\Viewer $viewer): void
    {
        $key = $collection . "\0" . ($viewer->user['id'] ?? 0) . "\0" . ($viewer->user['role'] ?? '');
        $missing = array_values(array_diff(array_map('intval', $ids), array_keys(self::$rows[$key] ?? [])));
        if ($missing === []) {
            return;
        }
        $db = Plugin::instance()?->db() ?? throw new \LogicException('No TypedPress app');
        $pk = $db->schema()->collection($collection)['primaryKey'];
        $all = \TypedPress\Locale\Module::localized($collection) ? ['locale' => '*'] : [];
        try {
            $found = $db->findMany($collection, ['where' => [$pk => ['in' => $missing]]] + $all, $viewer);
        } catch (HttpError) {
            $found = [];
        }
        $byId = array_column($found, null, $pk);
        foreach ($missing as $id) {
            self::$rows[$key][$id] = $byId[$id] ?? null;
        }
    }

    private const LIMIT = 50;
    private const MAX_LIMIT = 100;

    public static function register(Plugin $plugin): void
    {
        add_action('rest_api_init', static function () use ($plugin): void {
            $handler = [
                'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'callback' => static fn (\WP_REST_Request $request): \WP_REST_Response => self::answer($plugin, $request),
                // Each path is checked by what it dispatches to: core's route, the ability's own check.
                'permission_callback' => '__return_true',
            ];
            $namespace = self::apiNamespace();
            register_rest_route($namespace, '/(?P<path>.+)', $handler);
            // The namespace's own path is the app's schema, in place of core's namespace index.
            rest_get_server()->register_route($namespace, '/' . $namespace, [$handler], true);
        });
        add_filter('request', [self::class, 'decoded']);
        add_filter('rest_authentication_errors', [self::class, 'authenticate'], 1); // after AgentScope::booted()
        add_filter('rest_pre_serve_request', [self::class, 'served'], 100, 3);
        add_filter('rest_allowed_cors_headers', static fn (array $headers, mixed $request = null): array => $request instanceof \WP_REST_Request && self::below((string) $request->get_route()) !== null
            ? array_merge($headers, ['Idempotency-Key', 'TypedPress-Signature']) : $headers, 10, 2);
    }

    /** The answer the API's callback made for this request: status, headers, body. */
    private static ?array $answer = null;

    /** The API path being answered ('' for the schema), once core has dispatched to the API. */
    private static ?string $serving = null;

    /** The API's callback: TypedPress's answer, sent as it is by {@see served()}. */
    private static function answer(Plugin $plugin, \WP_REST_Request $request): \WP_REST_Response
    {
        $path = self::below((string) $request->get_route()) ?? '';
        self::$serving = $path;
        try {
            if ($plugin->unavailable() !== null) {
                throw new HttpError(503, 'unavailable', Plugin::UNAVAILABLE);
            }
            // The route is decoded ({@see decoded()}); route() takes the path as sent, one segment at a time.
            $sent = implode('/', array_map('rawurlencode', explode('/', $path)));
            self::$answer = self::route($plugin, $request->get_method(), $sent, self::query(), $request->get_body());
        } catch (HttpError | \TypedPress\JsError $e) {
            self::$answer = self::problemAnswer(\TypedPress\App::problem($e));
        }

        return new \WP_REST_Response([], self::$answer[0]);
    }

    /**
     * rest_pre_serve_request: the API's answer goes out as TypedPress made it (its own body, headers and
     * status), and a request core refused before the API ran (a bad nonce, a scoped credential, a
     * malformed JSON body) gets the same answer as a problem document, or a form its flash. Every
     * answer depends on who asks, so Batcache keeps none.
     */
    public static function served(mixed $served, mixed $result, mixed $request): mixed
    {
        if ($served || !$request instanceof \WP_REST_Request || ($path = self::below((string) $request->get_route())) === null) {
            return $served;
        }
        if (function_exists('batcache_cancel')) {
            batcache_cancel();
        }
        $answer = self::$answer;
        self::$answer = null;
        if ($answer === null) {
            if (!$result instanceof \WP_REST_Response || !$result->is_error()) {
                return $served; // core's own answer: a preflight's route description
            }
            $problem = \TypedPress\App::problem(self::fromWpError($result->as_error(), $result->get_status()));
            $form = self::formAction($request->get_method(), $path);
            $answer = $form !== null ? \TypedPress\Render\Forms::redirect($form, $request->get_body(), null, $problem) : self::problemAnswer($problem);
        }
        [$status, $headers, $body] = $answer;
        status_header($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if ($request->get_method() !== 'HEAD') {
            \TypedPress\Response::emit($body); // a file download streams through its closure
        }

        return true;
    }

    /** @return array{int, array<string, string>, string} */
    private static function problemAnswer(array $problem): array
    {
        // Storage behind the build is short-lived: a client retries shortly.
        $retry = $problem['status'] === 503 ? ['Retry-After' => '5'] : [];

        return [$problem['status'], ['Content-Type' => 'application/problem+json'] + $retry + self::PRIVATE, (string) Js::jsonStringify($problem)];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{int, array<string, string>, string|\Closure}
     */
    public static function route(Plugin $plugin, string $method, string $path, array $query, string $body): array
    {
        $segments = array_map('rawurldecode', array_values(array_filter(explode('/', $path), static fn ($s) => $s !== '')));
        $kinds = Kinds::all($plugin);
        $name = $segments[0] ?? null;

        if ($name === null) {
            \TypedPress\App::allow($method, ['GET', 'HEAD']);

            $plugin->app(); // the running app describes itself fully (its site id, for sync)

            return self::json(200, ['data' => Kinds::describe($plugin)]);
        }
        if ($name === 'me' && count($segments) === 1) {
            \TypedPress\App::allow($method, ['GET', 'HEAD']);
            // The signed-in user: `id`, `name` and TypedPress's `role`, with the users row as this viewer
            // reads it (a member's own fields) merged in; null when signed out.
            $user = WpAuth::current();
            if ($user === null) {
                return self::json(200, ['data' => null]);
            }
            $row = Kinds::row($kinds['users'], self::dispatch('GET', '/wp/v2/users/me', ['context' => 'edit']));

            return self::json(200, ['data' => array_merge(is_array($row) ? $row : [], $user)]);
        }
        if ($name === 'actions' && count($segments) === 2) {
            return self::action($plugin, $method, $segments[1], $query, $body);
        }
        if ($name === \TypedPress\Live\Module::ROUTE && count($segments) === 1) {
            return \TypedPress\Live\Module::serve($plugin, $method, $body); // reactive queries: its own file
        }
        if (isset($plugin->schema->globals()[$name]) && count($segments) === 1) {
            return self::global($plugin, $method, $name, $body);
        }
        if (\TypedPress\Extensions::routeHandler($name) !== null && ($name !== 'media' || ($segments[2] ?? null) === 'file')) {
            // TypedPress's own features with no core route (sign-in, sync, services, private files and the
            // streams of uploads kept off the web root): their one implementation, under the facade's
            // prefix. Media rows themselves are the built-in kind, served by core's media route.
            [$status, $headers, $out] = $plugin->app()->handle($method, '/' . implode('/', array_map('rawurlencode', $segments)), $query, $body, self::headers(), $_FILES, $_POST);
            $cached = array_filter(array_keys($headers), static fn ($h) => strtolower((string) $h) === 'cache-control') !== [];

            return [$status, $cached ? $headers : $headers + self::PRIVATE, $out];
        }
        if ($name === 'settings' && count($segments) === 1 && !isset($kinds['settings'])) {
            return self::settings($method, $body);
        }
        $kind = $kinds[$name] ?? throw HttpError::notFound("Unknown kind '$name'");
        if ($kind['type'] === 'menus') {
            return self::menus($method, $segments);
        }
        $base = $kind['route'];
        if (count($segments) === 1) {
            \TypedPress\App::allow($method, ['GET', 'HEAD', 'POST']);
            if ($method === 'POST' && $kind['type'] === 'media') {
                return self::json(201, ['data' => Kinds::row($kind, self::upload($base))]);
            }
            self::gate($kind, $method === 'POST' ? 'create' : 'list');
            if ($method === 'POST' && $kind['type'] === 'comments' && \TypedPress\Render\Forms::posted()) {
                // `<Comments>`'s form without JavaScript: the same create, then 303 back to the page.
                return self::formSubmit($body, 'comments', static function () use ($kind, $base, $body): mixed {
                    $fields = ['on' => ['type' => 'integer'], 'parent' => ['type' => 'integer'], 'content' => ['type' => 'text']];
                    $input = self::body(\TypedPress\Render\Forms::decode($fields, $body));
                    $row = Kinds::row($kind, self::dispatch('POST', $base, self::context($kind), Kinds::input($kind, $input, create: true), 201));

                    return ['id' => $row['id'] ?? null, 'status' => $row['status'] ?? null];
                });
            }
            if ($method === 'POST') {
                $row = self::dispatch('POST', $base, self::context($kind), Kinds::input($kind, self::body($body), create: true), 201);

                return self::json(201, ['data' => self::rev($kind, Kinds::row($kind, $row))]);
            }

            return self::list($kind, $query);
        }
        if ($kind['type'] === 'post' && ($segments[2] ?? null) === 'revisions' && count($segments) <= 5) {
            self::gate($kind, 'update');

            return self::revisions($kind, $method, self::id($kind, $segments[1]), array_slice($segments, 3), $query, $body);
        }
        if ($kind['type'] === 'post' && ($segments[2] ?? null) === 'staged' && count($segments) <= 4) {
            // A kind's staged versions ({@see Staged}): TypedPress's own, with no core route to map.
            self::gate($kind, 'update');
            [$status, $data] = Staged::route((string) $kind['name'], self::id($kind, $segments[1]), $method, array_slice($segments, 3), $query, self::body($body));

            return self::json($status, ['data' => $data]);
        }
        if (count($segments) !== 2) {
            throw HttpError::notFound("No route for $path");
        }
        $id = self::id($kind, $segments[1]);
        \TypedPress\App::allow($method, ['GET', 'HEAD', 'PATCH', 'DELETE']);
        self::gate($kind, ['PATCH' => 'update', 'DELETE' => 'delete'][$method] ?? 'get');
        $item = "$base/" . rawurlencode((string) $id);
        if ($method === 'PATCH') {
            // `_rev` and `takeover` are the write's conditions ({@see Rev}), not fields.
            [$patch, $rev, $takeover] = $kind['type'] === 'post' ? Rev::split(self::body($body)) : [self::body($body), null, false];
            $row = Rev::guarded((string) $kind['name'], $id, $rev, $takeover, CoreAccess::viewer(),
                fn () => self::outside($kind, 'update', $id, fn () => self::dispatch('POST', $item, self::context($kind), Kinds::input($kind, $patch)), $patch), $patch);

            return self::json(200, ['data' => self::rev($kind, Kinds::row($kind, $row))]);
        }
        if ($method === 'DELETE') {
            // TypedPress's delete is permanent: core trashes posts without `force`, and refuses users without it.
            $params = match ($kind['type']) {
                'post', 'wp-post', 'comments', 'media', 'term' => ['force' => true],
                'users' => ['force' => true, 'reassign' => false], // the member's posts are kept, unowned
                default => [],
            };
            if ($kind['type'] === 'media') {
                // The answer is the row as it was; core's own rendering of the deleted attachment
                // (its description) would read the file, which a delete never needs.
                $before = Kinds::row($kind, self::dispatch('GET', $item, self::context($kind)));
                self::dispatch('DELETE', $item, $params + ['_fields' => 'deleted']);

                return self::json(200, ['data' => $before]);
            }
            $out = Rev::guarded((string) $kind['name'], $id, $query[Rev::FIELD] ?? null, ($query[Rev::TAKEOVER] ?? '') === '1', CoreAccess::viewer(),
                fn () => self::outside($kind, 'delete', $id, fn () => self::dispatch('DELETE', $item, $params)));

            return self::json(200, ['data' => Kinds::row($kind, $out['previous'] ?? $out)]);
        }
        if ($kind['type'] === 'post' && isset($query['locale']) && $query['locale'] !== '*') {
            // `?locale=fr` on an item: the member of its translation group in that locale (else the
            // group's fallback), as TypedPress's read pipeline resolves it for this viewer.
            $member = self::dispatch('GET', $base, ['where' => (string) Js::jsonStringify([$kind['spec']['primaryKey'] => $id]), 'locale' => $query['locale'], '_fields' => 'id']);
            $item = "$base/" . rawurlencode((string) ($member[0]['id'] ?? throw HttpError::notFound("No {$kind['name']} row with id $id")));
        }
        $row = self::dispatch('GET', $item, self::context($kind) + array_intersect_key($query, Kinds::first($kind) ? ['with' => true] : ['password' => true]));
        if (Kinds::first($kind) && Kinds::row($kind, $row) === null) {
            throw HttpError::notFound("No {$kind['name']} row with id $id"); // TypedPress's read rule doesn't cover it
        }
        if (($query['format'] ?? null) === 'md') {
            $markdown = Kinds::markdown($kind, $row) ?? throw HttpError::notFound("{$kind['name']} has no Markdown form");

            return [200, ['Content-Type' => MarkdownTwins::CONTENT_TYPE, 'X-Content-Type-Options' => 'nosniff'] + self::PRIVATE, $markdown];
        }

        return self::json(200, ['data' => self::rev($kind, Kinds::row($kind, $row))]);
    }

    /**
     * A list request's page: `limit` (default 50, at most 100), then `offset` or the opaque `cursor` a
     * previous page of this API returned. @return array{int, int} [limit, offset]
     */
    private static function paging(array $query): array
    {
        $limit = isset($query['limit']) ? (int) $query['limit'] : self::LIMIT;
        if ($limit < 1 || (string) $limit !== (string) ($query['limit'] ?? $limit)) {
            throw HttpError::invalidQuery('limit must be a positive integer', '/limit');
        }
        $offset = 0;
        if (isset($query['offset'])) {
            if (!preg_match('/^\d{1,9}$/D', (string) $query['offset'])) {
                throw HttpError::invalidQuery('offset must be a non-negative integer', '/offset');
            }
            $offset = (int) $query['offset'];
        }
        if (isset($query['cursor'])) {
            $decoded = json_decode((string) base64_decode(strtr((string) $query['cursor'], '-_', '+/'), true), true);
            if (!is_array($decoded) || !is_int($decoded['offset'] ?? null) || $decoded['offset'] < 1) {
                throw HttpError::invalidQuery('cursor is not one this API issued', '/cursor');
            }
            $offset = $decoded['offset'];
        }

        return [min($limit, self::MAX_LIMIT), $offset];
    }

    /** The opaque cursor of the page starting at $next, or null when $next is past the last of $total. */
    private static function cursor(int $next, int $total): ?string
    {
        return $next < $total ? rtrim(strtr(base64_encode((string) json_encode(['offset' => $next])), '+/', '-_'), '=') : null;
    }

    /** A list: TypedPress's `where`/`orderBy`/`with`/`search`, `limit` and an opaque `cursor` (the next page). */
    private static function list(array $kind, array $query): array
    {
        [$limit, $offset] = self::paging($query);
        $paging = ['per_page' => $limit] + ($offset > 0 ? ['offset' => $offset] : []);
        if (Kinds::first($kind)) {
            // `where` is always TypedPress's (an empty filter when none was given): TypedPress's pipeline picks the
            // rows, so its read rule and draft visibility decide, not core's default status filter.
            $params = array_intersect_key($query, array_flip(['where', 'orderBy', 'with', 'search', 'locale']))
                + ['where' => '{}'] + $paging + self::context($kind);
            if (isset($query['status'])) {
                // `status` is shorthand for a filter on `status`, ANDed with `where`.
                $where = Js::decodeJson((string) $params['where']);
                $params['where'] = (string) Js::jsonStringify(['AND' => [$where, ['status' => (string) $query['status']]]]);
            }
        } else {
            // A WordPress kind: core's own filters (`search`, `post`, `parent`, `status`, ...), as core
            // accepts them for this viewer.
            $params = array_diff_key($query, ['limit' => 1, 'offset' => 1, 'cursor' => 1, 'format' => 1, 'per_page' => 1, 'page' => 1, 'context' => 1])
                + $paging + self::context($kind);
        }
        $response = self::run(self::request('GET', $kind['route'], $params));
        $rows = self::unwrap($response);
        $cursor = self::cursor($offset + $limit, (int) ($response->get_headers()['X-WP-Total'] ?? 0));

        $rows = array_values(array_filter(Kinds::rows($kind, $rows), static fn ($r) => $r !== null));
        // A post kind's rows carry their live `_rev` ({@see Rev}), read for the whole page at once.
        $rows = $kind['type'] === 'post' ? Rev::attachAll((string) $kind['name'], $rows) : $rows;

        return self::json(200, ['data' => $rows, 'cursor' => $cursor]);
    }

    /**
     * `POST /actions/<name>` (`GET` when readonly): the action's Ability through core's run route
     * (`/wp-abilities/v1/abilities/<app>/<name>/run`), so core's own checks decide (input, permission,
     * execution, output) and the facade only maps: the facade method onto the one the run route takes
     * (GET for readonly, DELETE for destructive + idempotent, POST otherwise), `{ input }` in, `{ data }`
     * out. An `agents: false` action is the app's own to call ({@see Abilities\TypedPressAbility}).
     */
    private static function action(Plugin $plugin, string $method, string $name, array $query, string $body): array
    {
        $action = $plugin->schema->actions()[$name] ?? throw HttpError::notFound("Unknown action '$name'");
        if (($action['signature'] ?? null) !== null) {
            // An inbound webhook signs the raw body, which an Ability input can't carry: TypedPress's own
            // action path verifies the signature (the caller is anonymous after it). It moves to
            // `app/**/route.ts` with the authoring cutover, and this branch goes with the option.
            \TypedPress\App::allow($method, ['POST']);

            return $plugin->app()->handle('POST', '/actions/' . rawurlencode($name), [], $body, self::headers());
        }
        \TypedPress\App::allow($method, ($action['readonly'] ?? false) ? ['GET', 'HEAD', 'POST'] : ['POST']);
        if ($method === 'POST' && \TypedPress\Render\Forms::posted()) {
            return self::form($plugin, $action, $name, $body);
        }
        // No arguments is the empty object, not the empty list an empty PHP array encodes as.
        $given = $method === 'POST' ? [] : self::queryInput($query);
        $input = $method === 'POST' ? $body : ($given === [] ? '{}' : (string) Js::jsonStringify($given));

        self::$passed = [];
        [$status, $headers, $out] = self::json(200, ['data' => self::ability($plugin, Abilities\Register::kebab($name), $input, "Unknown action '$name'")]);

        return [$status, self::$passed + $headers, $out];
    }

    /**
     * A browser's form post to an action (`<form action={name}>`, rendering.md §4.2): the fields read
     * as the action's input, the action run as a JSON call runs it, and `303 See Other` back to the
     * page with the result or the error in a flash. Only a form on this site's own pages may post:
     * a cross-site `Origin` is refused before anything runs.
     *
     * @return array{int, array<string, string>, string}
     */
    private static function form(Plugin $plugin, array $action, string $name, string $body): array
    {
        return self::formSubmit($body, $name, static function () use ($plugin, $action, $name, $body): mixed {
            $input = \TypedPress\Render\Forms::decode((array) ($action['input'] ?? []), $body);
            self::$passed = [];

            return self::ability($plugin, Abilities\Register::kebab($name), $input, "Unknown action '$name'");
        });
    }

    /**
     * A browser's form post: refused from another site's page, else $run, and either way `303` back
     * to the page with the result or the problem in the flash `$flash` reads.
     *
     * @return array{int, array<string, string>, string}
     */
    private static function formSubmit(string $body, string $flash, \Closure $run): array
    {
        try {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
            $site = wp_parse_url(home_url('/'));
            if (is_string($origin) && $origin !== 'null'
                && strtolower($origin) !== strtolower(($site['scheme'] ?? 'https') . '://' . ($site['host'] ?? '') . (isset($site['port']) ? ':' . $site['port'] : ''))) {
                throw new HttpError(403, 'forbidden', 'A form posts here only from this site\'s own pages');
            }

            return \TypedPress\Render\Forms::redirect($flash, $body, $run(), null);
        } catch (HttpError | \TypedPress\JsError $e) {
            return \TypedPress\Render\Forms::redirect($flash, $body, null, \TypedPress\App::problem($e));
        }
    }

    /** What a browser's form post to `$path` flashes under (an action's name, or `comments`), if this request is one. */
    private static function formAction(string $method, string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
        if ($method !== 'POST' || !\TypedPress\Render\Forms::posted()) {
            return null;
        }
        if (count($segments) === 1 && $segments[0] === 'comments') {
            return 'comments';
        }

        return count($segments) === 2 && $segments[0] === 'actions' ? rawurldecode($segments[1]) : null;
    }

    /** `GET`/`PATCH /<global>` (spec §3a): the global's read and update Abilities through the run route. */
    private static function global(Plugin $plugin, string $method, string $name, string $body): array
    {
        \TypedPress\App::allow($method, ['GET', 'HEAD', 'PATCH']);
        $op = $method === 'PATCH' ? 'update' : 'get';

        return self::json(200, ['data' => self::ability($plugin, 'global-' . Abilities\Register::kebab($name) . '-' . $op, $op === 'update' ? $body : '{}', "Unknown global '$name'")]);
    }

    /** One run of the app's ability `<app>/<ability>` with $input (a JSON object) through core's run route. */
    private static function ability(Plugin $plugin, string $ability, string $input, string $missing): mixed
    {
        $name = Abilities\Register::kebab($plugin->schema->name()) . '/' . $ability;
        $object = wp_get_ability($name) ?? throw HttpError::notFound($missing);
        self::body($input); // a JSON object, or the facade's 400/422
        $annotations = (array) $object->get_meta_item('annotations', []);
        $method = !empty($annotations['readonly']) ? 'GET' : (!empty($annotations['destructive']) && !empty($annotations['idempotent']) ? 'DELETE' : 'POST');
        $request = new \WP_REST_Request($method, '/wp-abilities/v1/abilities/' . $name . '/run');
        if ($method === 'POST') {
            // The body as sent, so `{}` inside a json() value stays an object (Register::rawInput).
            $request->set_header('content-type', 'application/json');
            $request->set_body('{"input":' . (trim($input) === '' ? '{}' : $input) . '}');
        } else {
            $request->set_query_params(['input' => json_decode(trim($input) === '' ? '{}' : $input, true)]);
        }
        foreach (['idempotency-key'] as $header) {
            $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? null;
            if (is_string($value)) {
                $request->set_header($header, $value);
            }
        }
        // The only elevation the facade makes: for this one run, an `agents: false` ability (hidden
        // from core REST discovery and MCP) is shown to core's run route, which still runs every check
        // of its own (input, permission callback, output). Nothing else the facade dispatches sees it.
        $previous = self::$running;
        self::$running = $name;
        try {
            $response = Abilities\Register::withSentInput($name, $input, static fn () => self::run($request));
        } finally {
            self::$running = $previous;
        }
        $data = self::unwrap($response);
        self::$passed = array_intersect_key($response->get_headers(), ['Idempotent-Replayed' => true]);

        return $data;
    }

    private static ?string $running = null;

    /** The ability the API is running through core's run route right now, if any. */
    public static function running(): ?string
    {
        return self::$running;
    }

    /** @var array<string, string> response headers of the last ability run the facade passes on */
    private static array $passed = [];

    // ================================================================ dispatch

    /**
     * One internal core dispatch as the current user: its data, or its `WP_Error` thrown as TypedPress's
     * problem. $expect is the success status core answers with.
     */
    private static function dispatch(string $method, string $route, array $params, mixed $body = null, int $expect = 200): mixed
    {
        return self::unwrap(self::run(self::request($method, $route, $params, $body)), $expect);
    }

    private static int $depth = 0;

    /** Whether a core route is answering one of the facade's own dispatches right now. */
    public static function dispatching(): bool
    {
        return self::$depth > 0;
    }

    /** @var list<array{string, string}> the method and route of each core dispatch in progress, innermost last */
    private static array $dispatches = [];

    /**
     * True while the facade's innermost core dispatch reads or deletes a media item on core's media
     * route: rendering one needs its path, never its bytes. Any other dispatch (an action's run, a
     * post read that embeds media) may use the file.
     */
    public static function mediaWithoutBytes(): bool
    {
        [$method, $route] = self::$dispatches === [] ? [null, ''] : self::$dispatches[count(self::$dispatches) - 1];

        return in_array($method, ['GET', 'HEAD', 'DELETE'], true) && preg_match('#^/wp/v2/media(?:/\d+)?/?$#', $route) === 1;
    }

    private static function run(\WP_REST_Request $request): \WP_REST_Response
    {
        self::$rows = []; // each core dispatch reads its own page fresh: nothing outlives it
        self::$depth++;
        self::$dispatches[] = [$request->get_method(), (string) $request->get_route()];
        try {
            return rest_do_request($request);
        } finally {
            self::$rows = [];
            array_pop(self::$dispatches);
            self::$depth--;
        }
    }

    private static function request(string $method, string $route, array $params, mixed $body = null): \WP_REST_Request
    {
        $request = new \WP_REST_Request($method, $route);
        if ($method === 'GET' || $method === 'DELETE' || $body === null) {
            $request->set_query_params($params);
        } else {
            $request->set_query_params($params);
            $request->set_header('content-type', 'application/json');
            $request->set_body((string) Js::jsonStringify($body));
        }
        foreach (['idempotency-key'] as $header) {
            $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? null;
            if (is_string($value)) {
                $request->set_header($header, $value);
            }
        }

        return $request;
    }

    private static function unwrap(\WP_REST_Response $response, int $expect = 200): mixed
    {
        $data = rest_get_server()->response_to_data($response, false);
        if ($response->is_error() || $response->get_status() >= 400) {
            $error = $response->as_error();
            throw self::fromWpError($error, $response->get_status());
        }

        return $data;
    }

    /**
     * RFC 9457 from core's `WP_Error`: TypedPress's own codes keep their name, and core's reach clients as
     * TypedPress's. The codes core ships today map by name, as they always have. A `rest_*` code this list
     * doesn't name (one a core release adds or renames) maps by the status core gives it, so it still
     * reaches clients as TypedPress's: 401 or 403 is `unauthenticated` or `forbidden`, 404 `not_found`, 405
     * `method_not_allowed`, 400 `invalid_input`. Any other code passes through.
     */
    private static function fromWpError(\WP_Error $error, int $status): HttpError
    {
        $data = $error->get_error_data();
        $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : $status;
        $wp = (string) $error->get_error_code();
        $code = str_starts_with($wp, 'tp_') ? substr($wp, strlen('tp_')) : $wp;
        $denied = get_current_user_id() === 0 ? 'unauthenticated' : 'forbidden';
        $code = match ($code) {
            'rest_forbidden', 'rest_cannot_create', 'rest_cannot_edit', 'rest_cannot_delete', 'rest_cannot_read', 'rest_forbidden_context',
            'rest_cannot_create_user', 'rest_user_cannot_delete', 'rest_user_cannot_view', 'rest_cannot_edit_roles',
            'rest_ability_cannot_execute' => $denied,
            'rest_post_invalid_id', 'rest_user_invalid_id', 'rest_no_route', 'rest_ability_not_found', 'rest_revision_parent_id_mismatch',
            'rest_term_invalid', 'rest_comment_invalid_id', 'rest_menu_location_invalid' => 'not_found',
            'rest_invalid_param', 'rest_missing_callback_param' => 'invalid_input',
            'rest_invalid_json' => 'invalid_json',
            'rest_no_route_method', 'rest_ability_invalid_method' => 'method_not_allowed',
            default => !str_starts_with($code, 'rest_') ? $code : match ($status) {
                401, 403 => $denied,
                404 => 'not_found',
                405 => 'method_not_allowed',
                400 => 'invalid_input',
                default => $code,
            },
        };
        if ($code === 'unauthenticated') {
            $status = 401;
        }
        $pointer = is_array($data) && is_string($data['pointer'] ?? null) ? $data['pointer'] : null;
        // Core's role check refuses the `roles` the facade sent for TypedPress's `role`.
        $pointer ??= match ($wp) {
            'rest_cannot_edit_roles', 'rest_user_invalid_role' => '/role',
            default => null,
        };

        return new HttpError($status, $code, $error->get_error_message(), $pointer);
    }

    // ================================================================ request parsing

    /**
     * The API path of this request ('' for the schema), or null when it isn't the API's. WordPress
     * decides that, not the URL: the request is REST (`REST_REQUEST`, which core defines once it has
     * parsed a REST route) and the route it parsed is below the API's namespace. Null until then.
     */
    public static function path(): ?string
    {
        if (self::$serving !== null) {
            return self::$serving;
        }
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;

        return is_string($route) && defined('REST_REQUEST') && REST_REQUEST ? self::below(untrailingslashit($route)) : null;
    }

    /**
     * `request`: the API's route as the caller wrote it, decoded once however it was sent. WordPress
     * keeps a route it took from the path (`/wp-json/typedpress/v1/…`) percent-encoded as sent, so
     * that one is decoded here. PHP has decoded `?rest_route=/typedpress/v1/…`, and WordPress then
     * added its slashes to it, which come off here. Core, the scope check and the API then all read
     * the same route for both spellings.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public static function decoded(array $vars): array
    {
        $route = $vars['rest_route'] ?? null;
        if (!is_string($route)) {
            return $vars;
        }
        if (isset($_GET['rest_route']) || isset($_POST['rest_route'])) {
            $decoded = wp_unslash($route);
        } elseif (str_ends_with((string) ($GLOBALS['wp']->request ?? ''), ltrim($route, '/'))) {
            // WordPress matched its rewrite rule on the path as sent (it also tries the decoded path,
            // whose route needs no decoding).
            $decoded = rawurldecode($route);
        } else {
            return $vars;
        }
        if (self::below($decoded) !== null) {
            $vars['rest_route'] = $decoded;
        }

        return $vars;
    }

    /**
     * rest_authentication_errors, before core reads what an application password did (priority 90):
     * resolve the API's user now. WordPress first resolves the user before it knows the request is
     * REST (at `init`; while plugins load under Polylang), so without application passwords, and its
     * REST server drops that visitor. Left alone, the next resolve is core's cookie check at priority
     * 100, after the outcome was read, and a refused password (wrong, suspended, out of scope) would
     * go on as a visitor instead of answering its error. Other REST routes keep core's order.
     */
    public static function authenticate(mixed $result): mixed
    {
        if (self::path() !== null && isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            wp_get_current_user(); // a no-op when the request's user is already resolved
        }

        return $result;
    }

    /** The API path of a REST route ('' for the namespace itself), or null when the route isn't the API's. */
    public static function below(string $route): ?string
    {
        $prefix = '/' . self::apiNamespace();
        if (strcasecmp($route, $prefix) === 0) {
            return '';
        }

        return strncasecmp($route, $prefix . '/', strlen($prefix) + 1) === 0 ? substr($route, strlen($prefix)) : null;
    }

    /** The raw query string, decoded as the facade's query arguments (no PHP renaming). */
    private static function query(): array
    {
        $out = [];
        foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[urldecode($k)] = urldecode($v);
        }
        // Transport metadata, as core's own routes consume it: api-fetch's locale hint, the nonce, the
        // method override and the route of a `?rest_route=` request.
        unset($out['_locale'], $out['_wpnonce'], $out['_method'], $out['rest_route']);

        return $out;
    }

    /** A readonly action's GET input: `?input=<json>`, or the query's other arguments as strings. */
    private static function queryInput(array $query): array
    {
        if (isset($query['input'])) {
            return self::body((string) $query['input']);
        }

        return $query;
    }

    private static function body(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }
        try {
            $decoded = \TypedPress\Seam::container(Js::decodeJson($body));
        } catch (\JsonException $e) {
            throw new HttpError(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw HttpError::invalidInput('Body must be a JSON object', '');
        }

        return $decoded;
    }

    /** An item of a post kind as the API answers it: with its live `_rev` ({@see Rev}). */
    private static function rev(array $kind, mixed $row): mixed
    {
        return $kind['type'] === 'post' ? Rev::attach((string) $kind['name'], $row) : $row;
    }

    /**
     * A write core's permission check refused, on a post kind's row its $op rule doesn't cover for this
     * viewer: TypedPress's answer for such a row, a 404 (a row outside the rule is not found, §1.6). Core's
     * check still decides; only the problem it answers changes. Refusals of a covered row (a field
     * rule, a data-dependent rule) keep their status and pointer.
     */
    private static function outside(array $kind, string $op, int|string $id, callable $dispatch, array $data = []): mixed
    {
        try {
            return $dispatch();
        } catch (HttpError $e) {
            $viewer = CoreAccess::viewer();
            if ($kind['type'] !== 'post' || !in_array($e->status, [401, 403], true) || $e->pointer !== null || $viewer === null) {
                throw $e;
            }
            // As TypedPress's write pipeline answers: a rule that refuses this viewer (and this data) is
            // 401/403; a filter that leaves the row out is a 404.
            $spec = $kind['spec'];
            $rule = \TypedPress\Access::collectionRules($spec)[$op];
            if (\TypedPress\Access::evaluate($rule, $viewer, $op, $op === 'update' ? $data : null, $spec['columns'], (string) $kind['name']) === false
                || CoreAccess::covers((string) $kind['name'], $op, $id, $viewer)) {
                throw $e;
            }
            throw HttpError::notFound("No {$kind['name']} row with id $id");
        }
    }

    /**
     * A kind's `api` flags: an operation switched off (`api: { delete: false }`) is a 405 on the API,
     * as its ability is unregistered. Core's own routes keep serving wp-admin and the block editor.
     */
    private static function gate(array $kind, string $op): void
    {
        if (Kinds::first($kind) && !($kind['spec']['api'][$op] ?? false)) {
            throw new HttpError(405, 'method_not_allowed', "The $op operation is disabled for {$kind['name']}");
        }
    }

    private static function id(array $kind, string $raw): int|string
    {
        if (str_starts_with($raw, 'slug:') && in_array($kind['type'], ['post', 'wp-post', 'term'], true)) {
            $rows = self::dispatch('GET', $kind['route'], ($kind['type'] === 'post'
                ? ['where' => (string) Js::jsonStringify([$kind['spec']['slug'] ?? 'slug' => substr($raw, 5)])]
                : ['slug' => substr($raw, 5)]) + ['_fields' => 'id']);

            return (int) ($rows[0]['id'] ?? throw HttpError::notFound("No {$kind['name']} with slug " . substr($raw, 5)));
        }

        return preg_match('/^\d{1,18}$/D', $raw) ? (int) $raw : $raw;
    }

    // ================================================================ output

    /**
     * The core context to ask for: `view`. Post kinds: PostsController carries the stored title and
     * content in it. Users: the row is TypedPress's own (UserMeta::prepare), whatever core's context shows.
     */
    private static function context(array $kind): array
    {
        // Media: TypedPress's own row answers (WpMedia, through Kinds::row), so core is asked for the item's
        // id and alt only: its full attachment rendering reads the file, which no API read needs.
        return ['context' => 'view'] + ($kind['type'] === 'media' ? ['_fields' => 'id,alt_text,caption,author,tp_focal'] : []);
    }

    /**
     * `/<kind>/<id>/revisions[/<rid>[/restore]]` over core's `/wp/v2/<type>/<id>/revisions` (core's
     * revisions controller, TypedPress's snapshot and field masks on each one, CoreAccess::prepareChild).
     * A restore is the revision's row as an update of the post through its core route, so the update
     * rule, validation and hooks decide it exactly as any other edit. Needs TypedPress's update rule.
     */
    private static function revisions(array $kind, string $method, int|string $id, array $rest, array $query = [], string $body = ''): array
    {
        $collection = (string) $kind['name'];
        $spec = $kind['spec'];
        if (!($spec['revisions'] ?? false)) {
            throw HttpError::notFound("$collection keeps no revisions");
        }
        $viewer = CoreAccess::viewer();
        $route = "{$kind['route']}/" . rawurlencode((string) $id) . '/revisions';
        $db = Plugin::instance()?->db() ?? throw new \LogicException('No TypedPress app');
        // Core's revisions route decides (its edit_post check is TypedPress's update rule, through
        // map_meta_cap); a refusal is a 404 here, whoever asks: a revision list never says that a row
        // exists (§1.6).
        $hidden = static function (callable $dispatch) use ($collection, $id): mixed {
            try {
                return $dispatch();
            } catch (HttpError $e) {
                throw in_array($e->status, [401, 403], true) ? HttpError::notFound("No $collection row $id") : $e;
            }
        };
        if ($viewer === null) {
            throw HttpError::notFound("No $collection row $id");
        }
        if ($rest === []) {
            \TypedPress\App::allow($method, ['GET', 'HEAD']);
            // A page of core's revisions route, newest first, as `{ data, cursor }` like every list.
            // Autosaves are drafts in progress, not revisions: they are left out in the query, so the
            // page counts and the cursor stay exact.
            [$limit, $offset] = self::paging($query);
            $noAutosaves = static function (array $args): array {
                global $wpdb;
                $parent = (int) ($args['post_parent'] ?? 0);
                // wp_is_post_autosave(): a revision whose name holds "<parent>-autosave".
                $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND post_name LIKE %s", $parent, '%' . $wpdb->esc_like("$parent-autosave") . '%'));
                // Core already sets `post__not_in` from the route's `exclude` (default []): add to it.
                return $ids === [] ? $args : ['post__not_in' => array_merge((array) ($args['post__not_in'] ?? []), array_map('intval', $ids))] + $args;
            };
            add_filter('rest_revision_query', $noAutosaves);
            try {
                $response = self::run(self::request('GET', $route, ['context' => 'view', 'per_page' => $limit, '_fields' => 'id,author,date_gmt'] + ($offset > 0 ? ['offset' => $offset] : [])));
            } finally {
                remove_filter('rest_revision_query', $noAutosaves);
            }
            $out = [];
            foreach ((array) $hidden(static fn () => self::unwrap($response)) as $r) {
                $user = (int) ($r['author'] ?? 0) > 0 ? get_userdata((int) $r['author']) : null;
                $out[] = ['id' => (int) $r['id'], 'at' => self::isoGmt((string) ($r['date_gmt'] ?? '')),
                    'user' => $user instanceof \WP_User ? ['id' => (int) $user->ID, 'name' => (string) $user->display_name] : null];
            }

            return self::json(200, ['data' => $out, 'cursor' => self::cursor($offset + $limit, (int) ($response->get_headers()['X-WP-Total'] ?? 0))]);
        }
        if (!preg_match('/^\d{1,18}$/D', $rest[0])) {
            throw HttpError::notFound("No revision {$rest[0]}");
        }
        $rid = (int) $rest[0];
        $hidden(fn () => self::dispatch('GET', "$route/$rid", ['_fields' => 'id'])); // core's check: one of this post's, and readable
        $row = $db->revision($collection, $id, $rid, $viewer); // TypedPress's snapshot, masked for this viewer
        if (count($rest) === 1) {
            \TypedPress\App::allow($method, ['GET', 'HEAD']);

            return self::json(200, ['data' => $row]);
        }
        if ($rest[1] !== 'restore' || count($rest) !== 2) {
            throw HttpError::notFound('No such revisions route');
        }
        \TypedPress\App::allow($method, ['POST']);
        // The restore is TypedPress's update from the revision as stored, as the viewer (Db::restoreRevision):
        // fields hidden from the viewer are restored too, and never returned to it. The answer is the
        // row as core's item read gives it to this viewer, so it is masked like every other read.
        // Core has no REST restore route; wp-admin restores with wp_restore_post_revision, and this
        // write is wp_update_post too (WpPostStore), so WordPress's own save hooks fire as for it.
        // Under the item's revision token and edit lock, like any other update (Rev::guarded).
        [, $rev, $takeover] = Rev::split(self::body($body));
        Rev::guarded($collection, $id, $rev, $takeover, $viewer, static fn (): mixed => $db->transaction(static function () use ($db, $collection, $id, $rid, $viewer): void {
            $previous = $db->actor();
            $db->setActor($viewer->user);
            try {
                $db->restoreRevision($collection, $id, $rid, $viewer);
            } finally {
                $db->setActor($previous);
            }
        }));
        $restored = self::dispatch('GET', "{$kind['route']}/" . rawurlencode((string) $id), self::context($kind));

        return self::json(200, ['data' => self::rev($kind, Kinds::row($kind, $restored))]);
    }

    private static function isoGmt(string $gmt): string
    {
        return str_replace(' ', 'T', $gmt) . '.000Z';
    }

    /**
     * `POST /media`: a multipart upload (`file`, optional `alt`) as core's `POST /wp/v2/media`, which
     * keeps core's file validation, metadata and hooks, with TypedPress's media rules around it
     * ({@see Media\Rest}).
     */
    private static function upload(string $route): mixed
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['error'] ?? null)) {
            throw HttpError::invalidInput('Send the file as multipart/form-data field "file"', '/file');
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError(413, 'payload_too_large', 'The upload is larger than this server accepts');
        }
        $request = new \WP_REST_Request('POST', $route);
        $request->set_file_params(['file' => $file]);
        $alt = $_POST['alt'] ?? null;
        if ($alt !== null && !is_string($alt)) {
            throw HttpError::invalidInput('alt must be a string', '/alt');
        }
        $request->set_body_params($alt === null ? [] : ['alt_text' => wp_unslash($alt)]);

        return self::unwrap(self::run($request), 201);
    }

    /** `GET`/`PATCH /settings` (spec §2.1): core's settings for admins; the index's public fields for others. */
    private static function settings(string $method, string $body): array
    {
        \TypedPress\App::allow($method, ['GET', 'HEAD', 'PATCH']);
        if ($method === 'PATCH') {
            $settings = self::dispatch('POST', '/wp/v2/settings', [], Kinds::settingsInput(self::body($body)));
        } else {
            $response = self::run(self::request('GET', '/wp/v2/settings', []));
            $settings = $response->is_error() ? null : rest_get_server()->response_to_data($response, false);
        }
        $index = self::dispatch('GET', '/', ['_fields' => 'name,description,timezone_string,site_logo,site_icon']);

        return self::json(200, ['data' => Kinds::settings($index, is_array($settings) ? $settings : null)]);
    }

    /**
     * `GET /menus` and `/menus/<location>`: each registered location's menu as a tree. Read through core's
     * menu routes with read access opened for exactly these dispatches: a menu assigned to a theme
     * location is shown on the site to everyone, so its items are public (build plan §2).
     */
    private static function menus(string $method, array $segments): array
    {
        \TypedPress\App::allow($method, ['GET', 'HEAD']);
        if (count($segments) > 2) {
            throw HttpError::notFound('No such menus route');
        }
        $open = static fn (bool $access, mixed $request): bool => $access || ($request instanceof \WP_REST_Request
            && preg_match('#^/wp/v2/menu-(locations|items)#', $request->get_route()) && self::dispatching());
        add_filter('rest_menu_read_access', $open, 10, 2);
        try {
            $locations = self::dispatch('GET', '/wp/v2/menu-locations', []);
            $locations = is_array($locations) ? $locations : [];
            if (isset($segments[1])) {
                $locations = [$segments[1] => $locations[$segments[1]] ?? throw HttpError::notFound("No menu location '{$segments[1]}'")];
            }
            $menus = Kinds::menus($locations, static function (int $menu): array {
                $items = self::dispatch('GET', '/wp/v2/menu-items', ['menus' => $menu, 'per_page' => 100]);

                return is_array($items) ? $items : [];
            });
        } finally {
            remove_filter('rest_menu_read_access', $open, 10);
        }

        return self::json(200, ['data' => isset($segments[1]) ? $menus[0] : $menus]);
    }

    /** Every facade answer depends on who asks (cookie, application password, bearer): never cached for others. */
    private const PRIVATE = ['Cache-Control' => 'private, no-store', 'Vary' => 'Origin, Cookie, Authorization'];

    /** @return array{int, array<string, string>, string} */
    private static function json(int $status, array $body): array
    {
        return [$status, ['Content-Type' => 'application/json; charset=utf-8'] + self::PRIVATE, (string) Js::jsonStringify($body)];
    }

    /** @return array<string, string> lower-cased request headers, as TypedPress's handlers read them */
    private static function headers(): array
    {
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

        return $headers;
    }
}
