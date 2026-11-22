<?php

declare(strict_types=1);

namespace TypedPress\Live;

use TypedPress\App;
use TypedPress\Db;
use TypedPress\FieldAccess;
use TypedPress\HttpError;
use TypedPress\Js;
use TypedPress\Seam;
use TypedPress\Viewer;
use TypedPress\Wp\CompositeStore;
use TypedPress\Wp\CoreAccess;
use TypedPress\Wp\Plugin;

/**
 * Reactive queries without a push transport (docs/decisions/2026-11-13-reactive-queries.md).
 *
 * `POST /wp-json/typedpress/v1/_live` takes the reads a page is subscribed to, each with the token of
 * the answer the page last got, and answers every one with "not modified" or the fresh result:
 *
 *   { "queries": [{ "kind": "todos", "op": "list", "args": { "where": { "done": false } }, "token": "…" }] }
 *   { "data": { "results": [{ "changed": false, "token": "…" }], "poll": { "minMs": 2000, "maxMs": 30000 } } }
 *
 * A read is the kind query API (`list`, `paginate`, `get`, `count`) run as the calling visitor, so
 * the read rule, draft visibility and field masks decide every answer exactly as they do for
 * `posts.list()`. "Not modified" is decided two ways:
 *
 * - by counter, without running the read, only when the visitor may read every row and field the
 *   counter covers (see {@see scope()}). A counter the visitor can't account for is never looked at;
 * - otherwise by running the read and comparing a hash of its result with the one in the token.
 *
 * Either way the answer is the same two shapes, with a new token every time, so neither a token
 * nor an answer says that something changed which the visitor may not read.
 */
final class Module
{
    /** The facade path segment: `/wp-json/typedpress/v1/_live`. Kind names start with a letter, so it is free. */
    public const ROUTE = '_live';
    /** Reads one request may carry. */
    public const MAX_QUERIES = 20;
    /** Longest request body, in bytes. */
    public const MAX_BODY = 32_768;
    /** The furthest `offset` a subscribed read may start at, as the API's lists allow. */
    public const MAX_OFFSET = 999_999_999;
    /** Levels of relations one subscribed read may load through `with`. */
    public const MAX_WITH_DEPTH = 3;
    /** Rows a subscribed `list` returns by default, and at most: the API's own list limits. */
    public const LIST_LIMIT = 50;
    public const MAX_LIST_LIMIT = 100;
    /** Once the reads of one request have run this long, the rest answer `pending` and are asked again. */
    public const BUDGET_MS = 1000;
    /**
     * A counter answers "not modified" only for this long after the read last ran. A write that
     * never passed TypedPress's write path (another plugin's SQL or `update_post_meta`) moves no
     * counter; this bounds how long such a write can stay unseen.
     */
    public const TRUST_SECONDS = 300;
    /** What clients are told to poll at: the shortest and longest gap between two requests. */
    public const POLL = ['minMs' => 2000, 'maxMs' => 30000];

    private const OPS = ['list', 'paginate', 'get', 'count', 'page'];
    /**
     * `page` reads one request may carry. A page read that runs is a page render (far heavier than
     * a kind read): four covers a page with a few live regions and keeps one batch from asking for
     * more renders than the visitor's own page loads would cost.
     */
    public const MAX_PAGES = 4;
    private const ARGS = [
        'page' => ['url', 'region'],
        'list' => ['where', 'orderBy', 'limit', 'offset', 'with', 'search', 'locale'],
        'paginate' => ['where', 'orderBy', 'limit', 'cursor', 'with', 'search', 'locale'],
        'get' => ['with', 'locale'],
        'count' => ['where', 'search', 'locale'],
    ];

    public static function register(): void
    {
        Versions::register();
    }

    /**
     * The facade's route.
     *
     * @return array{int, array<string, string>, string}
     */
    public static function serve(Plugin $plugin, string $method, string $body): array
    {
        App::allow($method, ['POST']);
        if (strlen($body) > self::MAX_BODY) {
            throw new HttpError(413, 'payload_too_large', 'A subscription request is at most ' . self::MAX_BODY . ' bytes');
        }
        try {
            $input = Seam::container(Js::decodeJson(trim($body) === '' ? '{}' : $body));
        } catch (\JsonException $e) {
            throw new HttpError(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage());
        }
        $queries = is_array($input) ? Seam::container($input['queries'] ?? null) : null;
        if (!is_array($queries) || !array_is_list($queries) || $queries === []) {
            throw HttpError::invalidInput('queries must be a non-empty list', '/queries');
        }
        if (count($queries) > self::MAX_QUERIES) {
            throw HttpError::invalidInput('At most ' . self::MAX_QUERIES . ' queries per request', '/queries');
        }
        $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
        $results = self::evaluate($plugin->app()->db, $viewer, $queries);
        $poll = array_map('intval', array_intersect_key((array) apply_filters('tp_live_poll', self::POLL), self::POLL) + self::POLL);

        // Results are spliced in as the JSON their hash was taken of.
        $parts = array_map(static function (array $r): string {
            $json = $r['json'] ?? null;
            unset($r['json'], $r['data']);
            $head = (string) json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $json === null ? $head : substr($head, 0, -1) . ',"data":' . $json . '}';
        }, $results);

        return [200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Origin, Cookie, Authorization',
        ], '{"data":{"results":[' . implode(',', $parts) . '],"poll":' . json_encode($poll) . '}}'];
    }

    /**
     * Answers each subscribed read as $viewer. The seam for server code that renders live data
     * (a page that embeds a read's first answer and its token, so the browser's first request is
     * "not modified"): pass descriptors as plain arrays, without `token` for a first answer.
     *
     * One result per query, in order:
     * - `['changed' => true, 'token' => string, 'data' => mixed, 'json' => string]`: the read's
     *   result (`json` is `data` encoded, the form its hash was taken of);
     * - `['changed' => false, 'token' => string]`: what the caller holds is current;
     * - `['error' => array]`: the problem the read answers with (401, 403, 422, ...);
     * - `['pending' => true]`: the request's time budget ran out first; ask again.
     *
     * @param list<mixed> $queries `['kind' => key, 'op' => 'list'|'paginate'|'get'|'count', 'args' => array, 'id' => int|string, 'token' => ?string]`
     * @return list<array<string, mixed>>
     */
    public static function evaluate(Db $db, Viewer $viewer, array $queries, int $budgetMs = self::BUDGET_MS): array
    {
        return $db->current(static function () use ($db, $viewer, $queries, $budgetMs): array {
            $store = $db->store();
            if (!$store instanceof CompositeStore) {
                throw new \LogicException('Reactive queries need the WordPress store');
            }
            $plans = [];
            $pairs = [];
            foreach ($queries as $i => $query) {
                try {
                    $plan = self::plan($db, $viewer, $query, "/queries/$i");
                } catch (HttpError | \TypedPress\JsError $e) {
                    $plans[$i] = ['error' => App::problem($e)];
                    continue;
                }
                // Every read is bound to the build that answered it: a deploy that changes the code is
                // a new question, never "not modified" by counter.
                $plan['q'] = self::question($viewer, $plan['kind'], $plan['op'], $plan['id'], $plan['args']);
                $claims = Token::open($plan['token']);
                $plan['held'] = is_array($claims) && ($claims['q'] ?? null) === $plan['q'] ? $claims : null;
                if ($plan['op'] === 'page') {
                    // What the region read last time decides which counters may answer for it now.
                    $plan['scopes'] = self::pageScopes($plan['held']['k'] ?? null, $plan['prefetch']);
                }
                foreach ([...($plan['scopes'] ?? []), ...($plan['prefetch'] ?? [])] as $kind => $scope) {
                    $pairs["$kind\0$scope"] = [(string) $kind, $scope];
                }
                $plans[$i] = $plan;
            }
            // At most MAX_PAGES page reads render per request: the rest answer `pending` and the client
            // asks again at once, so a page with more regions still fills every one.
            $pages = 0;
            foreach ($plans as $i => $plan) {
                if (($plan['op'] ?? null) === 'page' && ++$pages > self::MAX_PAGES) {
                    $plans[$i] = ['pending' => true];
                }
            }
            // Every counter this request may look at, in one read, before any query runs: a write
            // that commits meanwhile leaves the token behind, never ahead.
            $versions = array_combine(array_keys($pairs), Versions::read($store->db, array_values($pairs)));
            $now = intdiv(Js::now(), 1000);
            $spent = 0.0;
            $ran = 0;
            $results = [];
            foreach ($plans as $plan) {
                if (isset($plan['error'])) {
                    $results[] = ['error' => $plan['error']];
                    continue;
                }
                if (isset($plan['pending'])) {
                    $results[] = ['pending' => true];
                    continue;
                }
                $counters = null;
                if ($plan['scopes'] !== null) {
                    $counters = [];
                    foreach ($plan['scopes'] as $kind => $scope) {
                        $counters[] = [(string) $kind, $scope, $versions["$kind\0$scope"]];
                    }
                }
                $held = $plan['held'];
                if ($held !== null && $counters !== null && ($held['c'] ?? null) === $counters
                    && is_int($held['t'] ?? null) && $held['t'] <= $now && $now - $held['t'] < self::TRUST_SECONDS) {
                    $results[] = ['changed' => false, 'token' => Token::seal($held)];
                    continue;
                }
                // The first read always runs, so every request makes progress.
                if ($ran > 0 && $spent >= $budgetMs) {
                    $results[] = ['pending' => true];
                    continue;
                }
                $ran++;
                $started = hrtime(true);
                try {
                    $data = self::run($db, $viewer, $plan);
                } catch (HttpError | \TypedPress\JsError $e) {
                    $results[] = ['error' => App::problem($e)];
                    continue;
                } finally {
                    $spent += (hrtime(true) - $started) / 1e6;
                }
                $kinds = null;
                if ($plan['op'] === 'page') {
                    // A page's dependencies are known once it has rendered: its counters are the ones
                    // read before the render for the kinds it read (null when any of them has no
                    // scope, it read nothing, or it read something TypedPress can't count).
                    $kinds = $data['unseen'] ? null : array_keys($data['reads']);
                    $scopes = self::pageScopes($kinds, $plan['prefetch']);
                    $counters = null;
                    if ($scopes !== null) {
                        $counters = [];
                        foreach ($scopes as $kind => $scope) {
                            $counters[] = [(string) $kind, $scope, $versions["$kind\0$scope"]];
                        }
                    }
                    // The kinds the render read go back with the region (sorted, by the client's schema
                    // key, the names change notices use), so the browser subscribes with them as its
                    // dependencies: a notice or a write through the same client for one of them asks
                    // again at once. Kind names are in every notice already; this reveals nothing more.
                    // Plus any notice key the render depends on that isn't a kind (`wp:comments`).
                    $data = ['html' => $data['html'], 'kinds' => self::keys($db, array_keys($data['reads']), $data['notices'] ?? [])];
                }
                $json = Js::jsonStringify($data) ?? 'null';
                $hash = self::answerHash($json, $plan['op'] === 'page');
                $token = Token::seal(['q' => $plan['q'], 'c' => $counters, 'h' => $hash, 't' => $now] + ($kinds === null ? [] : ['k' => $kinds]));
                $results[] = $held !== null && ($held['h'] ?? null) === $hash
                    ? ['changed' => false, 'token' => $token]
                    : ['changed' => true, 'token' => $token, 'data' => $data, 'json' => $json];
            }

            return $results;
        });
    }

    public static function validPageUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 2048 && preg_match('~\A/(?![/\\\\])[^\x00-\x20\x7f#]*\z~', $url) === 1;
    }

    /** A GET seed has no counter snapshot from before its data was read. The first poll checks its hash. */
    public static function pageSeed(Db $db, Viewer $viewer, string $url, string $region, string $html, array $reads, array $notices): array
    {
        $kinds = self::keys($db, $reads, $notices);
        $seed = ['url' => $url, 'kinds' => $kinds];
        try {
            $json = Js::jsonStringify(['html' => $html, 'kinds' => $kinds]) ?? 'null';
            $seed['token'] = Token::seal([
                'q' => self::question($viewer, '', 'page', null, ['url' => $url, 'region' => $region]),
                'h' => self::answerHash($json, true),
            ]);
        } catch (\Throwable) {
            // Without a token the browser subscribes with a full first read.
        }
        return $seed;
    }

    private static function question(Viewer $viewer, string $kind, string $op, int|string|null $id, array $args): string
    {
        $who = [$viewer->user['id'] ?? 0, $viewer->user['role'] ?? '', defined('COOKIEHASH') ? (string) ($_COOKIE['wp-postpass_' . COOKIEHASH] ?? '') : ''];
        return base64_encode(hash('sha256', (string) json_encode([$who, $kind, $op, $id, Js::jsonStringify($args), self::generation()]), true));
    }

    private static function answerHash(string $json, bool $page): string
    {
        // A private file's signed link carries its expiry: two reads a second apart differ
        // there alone. The hash leaves the signature out, so an unchanged row stays unchanged;
        // a changed answer still sends the fresh links. A page also carries per-render values
        // that aren't content (form nonces, sealed read tokens), left out the same way.
        $hashed = preg_replace('#(/download[^"]*?[?&]exp=)\d+&sig=[0-9a-f]{64}#', '$1', $json) ?? $json;
        if ($page) {
            $hashed = self::stable($hashed);
        }
        return base64_encode(hash('sha256', $hashed, true));
    }

    /**
     * One descriptor, checked: its collection, operation and arguments, and the counters that may
     * answer for it (null: none may, the read always runs).
     *
     * @return array{kind: string, collection: string, op: string, args: array, id: mixed, token: mixed, scopes: ?array<string, string>}
     */
    private static function plan(Db $db, Viewer $viewer, mixed $query, string $ptr): array
    {
        $query = Seam::container($query);
        if (!is_array($query) || ($query !== [] && array_is_list($query))) {
            throw HttpError::invalidInput('A query is an object: { kind, op, args }', $ptr);
        }
        foreach ($query as $key => $_) {
            if (!in_array($key, ['kind', 'op', 'args', 'id', 'token'], true)) {
                throw HttpError::invalidInput("Unknown query key '$key'", $ptr . HttpError::seg((string) $key));
            }
        }
        if (($query['op'] ?? null) === 'page') {
            return self::planPage($db, $viewer, $query, $ptr);
        }
        $kind = $query['kind'] ?? null;
        $collection = is_string($kind) ? self::collection($db, $kind) : null;
        if ($collection === null) {
            throw new HttpError(404, 'not_found', 'Unknown kind ' . (is_string($kind) ? "'$kind'" : ''), "$ptr/kind");
        }
        $op = $query['op'] ?? null;
        if (!in_array($op, self::OPS, true)) {
            throw HttpError::invalidInput('op must be one of ' . implode(', ', self::OPS), "$ptr/op");
        }
        $spec = $db->schema()->collection($collection);
        if (!($spec['api'][$op === 'get' ? 'get' : 'list'] ?? false)) {
            throw new HttpError(405, 'method_not_allowed', 'The ' . ($op === 'get' ? 'get' : 'list') . " operation is disabled for $kind", "$ptr/op");
        }
        $args = Seam::query($collection, $query['args'] ?? [], $db->schema());
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            throw HttpError::invalidQuery('args must be an object', "$ptr/args");
        }
        $allowed = self::ARGS[$op];
        $id = $query['id'] ?? null;
        if ($op === 'get') {
            // One row by id, or by a filter (`{ slug: "hello" }`).
            if (is_int($id) || is_string($id)) {
                if (isset($args['where'])) {
                    throw HttpError::invalidQuery('get takes an id or a where filter, not both', "$ptr/args/where");
                }
            } elseif (isset($args['where'])) {
                $allowed[] = 'where';
                $id = null;
            } else {
                throw HttpError::invalidInput('get needs an id or a where filter', "$ptr/id");
            }
        } elseif ($id !== null) {
            throw HttpError::invalidInput("$op takes no id", "$ptr/id");
        }
        foreach ($args as $key => $_) {
            if (!in_array($key, $allowed, true)) {
                throw HttpError::invalidQuery("Unknown query option '$key'", "$ptr/args" . HttpError::seg((string) $key));
            }
        }
        if (isset($args['offset']) && (!is_int($args['offset']) || $args['offset'] < 0 || $args['offset'] > self::MAX_OFFSET)) {
            throw HttpError::invalidQuery('offset must be an integer from 0 to ' . self::MAX_OFFSET, "$ptr/args/offset");
        }
        if (self::depth($args['with'] ?? null) > self::MAX_WITH_DEPTH) {
            throw HttpError::invalidQuery('with nests at most ' . self::MAX_WITH_DEPTH . ' levels', "$ptr/args/with");
        }
        if ($op === 'list' || $op === 'paginate') {
            // The API's own limits (the facade's lists), so a subscribed page is the page `list()` returns.
            $limit = $args['limit'] ?? self::LIST_LIMIT;
            if (!is_int($limit) || $limit < 1) {
                throw HttpError::invalidQuery('limit must be a positive integer', "$ptr/args/limit");
            }
            $args['limit'] = min($limit, self::MAX_LIST_LIMIT);
        }
        $scopes = [];
        try {
            self::dependencies($db, $viewer, $collection, $args, $scopes);
        } catch (HttpError | \TypedPress\JsError | \LogicException) {
            $scopes = null; // the read itself answers (its 401, 403 or 422), every poll
        }

        return ['kind' => (string) $kind, 'collection' => $collection, 'op' => $op, 'args' => $args, 'id' => $id, 'token' => $query['token'] ?? null, 'scopes' => $scopes];
    }

    /**
     * A `page` read (docs/decisions/2026-11-14-live-regions.md): `{ op: "page", args: { url, region } }`.
     * `url` is a path and query on this site; the router must resolve it to a page route (checked
     * when it renders). Its counters are known only after a render, so `scopes` starts from what the
     * held token says the region read, and `prefetch` lists every kind's counter this visitor could
     * be answered by, read with the others before anything renders.
     */
    private static function planPage(Db $db, Viewer $viewer, array $query, string $ptr): array
    {
        $args = Seam::container($query['args'] ?? null);
        if (!is_array($args) || array_is_list($args)) {
            throw HttpError::invalidQuery('args must be an object: { url, region }', "$ptr/args");
        }
        foreach ($args as $key => $_) {
            if (!in_array($key, self::ARGS['page'], true)) {
                throw HttpError::invalidQuery("Unknown query option '$key'", "$ptr/args" . HttpError::seg((string) $key));
            }
        }
        $url = $args['url'] ?? null;
        // A path and query on this site: one leading slash, no scheme, host, fragment or control characters.
        if (!self::validPageUrl($url)) {
            throw HttpError::invalidInput('url is a path on this site, like "/notes?page=2"', "$ptr/args/url");
        }
        $region = $args['region'] ?? null;
        if (!is_string($region) || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/', $region) !== 1) {
            throw HttpError::invalidInput('region is a live region id', "$ptr/args/region");
        }
        if (isset($query['id']) || isset($query['kind'])) {
            throw HttpError::invalidInput('A page read names no kind and no id', $ptr);
        }
        $prefetch = [];
        foreach ($db->schema()->collections() as $name => $_) {
            try {
                $scope = self::scope($db, $viewer, (string) $name);
            } catch (HttpError | \TypedPress\JsError | \LogicException) {
                $scope = null;
            }
            if ($scope !== null) {
                $prefetch[(string) $name] = $scope;
            }
        }

        return ['kind' => '', 'collection' => '', 'op' => 'page', 'args' => ['url' => $url, 'region' => $region], 'id' => null, 'token' => $query['token'] ?? null, 'scopes' => null, 'prefetch' => $prefetch];
    }

    /**
     * The counters that may answer for a page read whose region read $kinds: null unless it read at
     * least one kind and every one of them has a scope this visitor can account for.
     *
     * @param ?list<string> $kinds
     * @param array<string, string> $prefetch
     * @return ?array<string, string>
     */
    private static function pageScopes(mixed $kinds, array $prefetch): ?array
    {
        if (!is_array($kinds) || $kinds === []) {
            return null;
        }
        $scopes = [];
        foreach ($kinds as $kind) {
            if (!is_string($kind) || !isset($prefetch[$kind])) {
                return null;
            }
            $scopes[$kind] = $prefetch[$kind];
        }
        ksort($scopes);

        return $scopes;
    }

    /** A page's answer with what varies per render and isn't content left out, for its hash. */
    private static function stable(string $json): string
    {
        return preg_replace([
            '#(name=\\"_wpnonce\\" value=\\")[0-9a-f]+#',   // a signed-in form's REST nonce
            '#(&quot;token&quot;:&quot;)[A-Za-z0-9_-]+#',          // a seeded live read's sealed token
            '#([?&]_flash=)[0-9a-f]+#',                           // a form's flash marker
        ], '$1', $json) ?? $json;
    }

    /**
     * The client's schema keys (`client.<key>`, the names notices use) of the collections $names, with
     * the other notice keys a render depends on, sorted.
     *
     * @param list<string> $names
     * @param list<string> $notices
     * @return list<string>
     */
    private static function keys(Db $db, array $names, array $notices = []): array
    {
        $collections = $db->schema()->collections();
        $out = array_values(array_unique([...array_map(static fn (string $n): string => (string) ($collections[$n]['key'] ?? $n), $names), ...$notices]));
        sort($out);

        return $out;
    }

    /**
     * Which build of the app answered: every token's question includes it, so a new build never
     * answers "not modified" for an old one's answer. The compiled schema's hash today; the one place
     * that points at the deployment number once TypedPress numbers its deployments.
     */
    public static function generation(): string
    {
        return Plugin::instance()?->buildHash() ?? '';
    }

    /** How many levels of relations a `with` loads. */
    private static function depth(mixed $with): int
    {
        if (!is_array($with) || $with === []) {
            return 0;
        }
        $deepest = 0;
        foreach ($with as $o) {
            $deepest = max($deepest, is_array($o) ? self::depth($o['with'] ?? null) : 0);
        }

        return 1 + $deepest;
    }

    /** The collection of a client schema key (`client.<key>`), or null. */
    private static function collection(Db $db, string $key): ?string
    {
        foreach ($db->schema()->collections() as $name => $spec) {
            if ((string) ($spec['key'] ?? $name) === $key) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * Fills $scopes with the counter of every kind the read depends on: its own, each kind `with`
     * loads, each kind a relation filter in `where` looks into. Null as soon as one of them has no
     * counter the viewer may be answered by.
     *
     * @param ?array<string, string> $scopes
     */
    private static function dependencies(Db $db, Viewer $viewer, string $collection, array $opts, ?array &$scopes): void
    {
        if ($scopes === null) {
            return;
        }
        $spec = $db->schema()->collection($collection);
        if (!isset($scopes[$collection])) {
            $scope = self::scope($db, $viewer, $collection);
            if ($scope === null) {
                $scopes = null;

                return;
            }
            $scopes[$collection] = $scope;
        }
        $relations = $spec['relations'] ?? [];
        $with = $opts['with'] ?? [];
        foreach (is_array($with) ? $with : [] as $name => $o) {
            if ($o === false || $o === null) {
                continue;
            }
            if (!isset($relations[$name])) {
                $scopes = null; // a media or file column loaded in place: its rows have no counter

                return;
            }
            self::dependencies($db, $viewer, $relations[$name]['collection'], is_array($o) ? $o : [], $scopes);
        }
        self::filterDependencies($db, $viewer, $collection, $opts['where'] ?? null, $scopes);
    }

    /** @param ?array<string, string> $scopes */
    private static function filterDependencies(Db $db, Viewer $viewer, string $collection, mixed $where, ?array &$scopes): void
    {
        if (!is_array($where) || $scopes === null) {
            return;
        }
        $spec = $db->schema()->collection($collection);
        foreach ($where as $key => $value) {
            $key = (string) $key;
            if ($key === 'OR' || $key === 'AND') {
                foreach (is_array($value) ? $value : [] as $part) {
                    self::filterDependencies($db, $viewer, $collection, $part, $scopes);
                }
            } elseif ($key === 'NOT') {
                self::filterDependencies($db, $viewer, $collection, $value, $scopes);
            } elseif (\TypedPress\Schema::filtersRelation($spec, $key, $value)) {
                // A relation filter, also through a `ref()` field, which is a column and a relation
                // of one name: the read depends on the related kind's rows.
                self::dependencies($db, $viewer, $spec['relations'][$key]['collection'], is_array($value) ? ['where' => $value] : [], $scopes);
            }
        }
    }

    /**
     * The counter that may answer "not modified" to $viewer for $collection, or null when none may.
     *
     * A counter moves on writes to any row it covers, in any field. So it may answer only a viewer
     * who may read all of that: `all` when the viewer reads every row, `published` when the
     * viewer reads exactly the published rows; and never when a field may be hidden from the
     * viewer, or for a kind WordPress writes without TypedPress's write path (users, the built-ins).
     * Throws the read's own 401/403 when the rule refuses the viewer.
     */
    private static function scope(Db $db, Viewer $viewer, string $collection): ?string
    {
        $schema = $db->schema();
        $spec = $schema->collection($collection);
        $guards = $db->readScope($collection, $viewer);
        // A read extension that may drop rows (Extensions::onRead without `constraintSafe`) is a
        // row filter the scope above doesn't show.
        if ($collection === ($schema->auth()['collection'] ?? null) || ($spec['builtin'] ?? null) !== null
            || !\TypedPress\Extensions::constraintReadsSafe()) {
            return null;
        }
        foreach ($spec['columns'] as $def) {
            if (FieldAccess::mayHide($def, $viewer)) {
                return null;
            }
        }

        return match (true) {
            $guards === [] => Versions::ALL,
            // Only a kind with drafts keeps a `published` counter. A localized kind's published rows
            // also show their whole translation group, drafts included, which that counter misses.
            $guards === [['status' => 'published']] && ($spec['drafts'] ?? false) && !($spec['localized'] ?? false) => Versions::PUBLISHED,
            default => null,
        };
    }

    /** The read, through the kind query API as the visitor (Seam::kindList and its siblings). */
    private static function run(Db $db, Viewer $viewer, array $plan): mixed
    {
        if ($plan['op'] === 'page') {
            // One `<Live>` region of the page, rendered as the visitor, with the reads it made.
            $router = \TypedPress\Wp\AppRouter::instance() ?? throw new HttpError(404, 'not_found', 'This app has no pages');

            return $db->transaction(static fn (): array => $router->liveRegion($plan['args']['url'], $plan['args']['region'], $viewer));
        }
        $collection = $plan['collection'];
        $args = $plan['args'];

        return $db->transaction(static function () use ($db, $viewer, $plan, $collection, $args): mixed {
            $previous = $db->actor();
            $db->setActor($viewer->user);
            try {
                return Seam::asVisitor($viewer, static fn (): mixed => match ($plan['op']) {
                    'list' => Seam::kindList($collection, $args, false),
                    'paginate' => Seam::kindPaginate($collection, $args, false),
                    'count' => Seam::kindCount($collection, $args, false),
                    'get' => Seam::kindGet($collection, $plan['id'] !== null ? ['id' => $plan['id']] : ['where' => $args['where']], array_diff_key($args, ['where' => true]), false),
                });
            } finally {
                $db->setActor($previous);
            }
        });
    }
}
