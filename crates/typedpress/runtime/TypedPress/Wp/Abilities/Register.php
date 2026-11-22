<?php

declare(strict_types=1);

namespace TypedPress\Wp\Abilities;

use TypedPress\Abilities\Module;
use TypedPress\Access;
use TypedPress\Extensions;
use TypedPress\HttpError;
use TypedPress\Schema;
use TypedPress\Wp\Runtime;

/**
 * Every API operation of the active app as a core WordPress Ability `<app>/<kebab-name>` (§7.1),
 * plus the app's named MCP server. Descriptors are built once per request from schema.php:
 *   name, op, label, description, input, output, annotations, agents, first (meta),
 *   rule [rule, op, what], data? (input → rule data), defer? (rule needs the pipeline),
 *   call (input → [method, path, query, body]) and result? (data → output), or run (input → output).
 * A post kind with revisions also gets one ability per staged-version step ({@see staged()}).
 * `agents: false` keeps the ability registered (PHP, `wp typedpress run`) but out of core REST and MCP.
 */
final class Register
{
    /** @var ?list<array> */
    private static ?array $operations = null;
    /** @var array<string, true> */
    private static array $names = [];
    private static ?string $sanitizing = null;
    private static ?string $method = null;
    private static ?string $untyped = null;
    /** The REST run endpoint's JSON `input`, decoded with `{}` kept: [ability name, input]. */
    private static ?array $raw = null;
    /**
     * @var array{string, array<string, string>}|null the run request's ability and its headers an
     * operation reads (Idempotency-Key, TypedPress-Signature): they belong to that one run, not to abilities
     * its code calls in turn.
     */
    private static ?array $headers = null;
    /** @var array<string, string> headers the operation answered with, for the run response */
    private static array $responded = [];

    public static function categories(): void
    {
        $schema = self::schema();
        if ($schema !== null) {
            wp_register_ability_category(self::kebab($schema->name()), [
                'label' => self::appLabel($schema),
                'description' => 'Operations of the ' . self::appLabel($schema) . ' app, built with TypedPress.',
            ]);
        }
    }

    public static function abilities(): void
    {
        foreach (self::operations() as $op) {
            wp_register_ability($op['name'], self::args($op));
        }
    }

    /** mcp_adapter_init: the app's own server, every agent-visible ability as a named tool. */
    public static function mcp(object $adapter): void
    {
        $schema = self::schema();
        // Not on TypedPress's API, which never serves `/mcp` (see Module::register).
        if ($schema === null || \TypedPress\Wp\Facade::path() !== null) {
            return;
        }
        $id = 'typedpress-' . self::kebab($schema->name());
        $tools = array_values(array_map(static fn (array $op): string => $op['name'], array_filter(self::operations(), static fn (array $op): bool => $op['agents'])));
        $adapter->create_server($id, 'mcp', $id, self::appLabel($schema), 'TypedPress app ' . $schema->name(), Runtime::VERSION,
            [\WP\MCP\Transport\HttpTransport::class], \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class, null, $tools);
    }

    /** @return list<array> the descriptors of the active app */
    public static function operations(): array
    {
        $schema = self::schema();
        if ($schema === null) {
            return [];
        }
        if (self::$operations === null) {
            self::$operations = self::build($schema);
            self::$names = array_fill_keys(array_column(self::$operations, 'name'), true);
        }

        return self::$operations;
    }

    /**
     * wp_ability_validate_input: TypedPress's pipeline is the one validator of its abilities, so a bad
     * input gets REST's 422 problem and pointer instead of core's generic 400. While the REST run
     * endpoint sanitizes its `input` argument, core sanitizes only what validates: query-string
     * input (GET/DELETE) is then typed by core's own check, as a query string must be, and JSON
     * input (POST) is declared invalid so core passes it through byte-for-byte to TypedPress.
     */
    public static function validate(mixed $valid, mixed $input, string $name): mixed
    {
        self::operations();
        if (!isset(self::$names[$name])) {
            return $valid;
        }

        if (in_array(self::$sanitizing, ['GET', 'DELETE'], true)) {
            // Core sanitizes (types) a query string only when all of it validates; remember which.
            self::$untyped = $valid === true ? null : ($valid instanceof \WP_Error ? $valid->get_error_message() : 'Invalid input');
        }

        return match (self::$sanitizing) {
            null => true,
            'GET', 'DELETE' => $valid,
            default => new \WP_Error('tp_raw_input', 'TypedPress validates JSON input itself'),
        };
    }

    /** Records why this request's query-string input was left untyped (null: it was typed). */
    public static function untyped(?string $reason): void
    {
        self::$untyped = $reason;
    }

    /** Why core left this request's query-string input untyped, or null when it typed it. */
    public static function untypedReason(): ?string
    {
        return self::$untyped;
    }

    /** rest_pre_dispatch … rest_request_before_callbacks brackets core's argument sanitizing. */
    public static function sanitizing(bool $on): \Closure
    {
        return static function (mixed $passthrough, mixed $server = null, mixed $request = null) use ($on): mixed {
            self::$sanitizing = $on && $request instanceof \WP_REST_Request ? $request->get_method() : null;
            if ($on) {
                self::$method = self::$sanitizing;
                self::$untyped = null;
                self::$responded = [];
                self::$headers = null;
                if ($request instanceof \WP_REST_Request && preg_match('#^/wp-abilities/v1/abilities/(.+)/run$#', (string) $request->get_route(), $m)) {
                    $headers = [];
                    foreach ($request->get_headers() as $name => $values) {
                        $headers[str_replace('_', '-', strtolower((string) $name))] = implode(', ', (array) $values);
                    }
                    self::$headers = [urldecode($m[1]), $headers];
                }
                // The run body's own input, or (for the facade's GET/DELETE runs) what withSentInput set.
                self::$raw = ($request instanceof \WP_REST_Request ? self::rawInput($request) : null) ?? self::$raw;
            }

            return $passthrough;
        };
    }

    /**
     * Core decodes a POSTed run body associatively, so `{}` in a json() value reaches the ability as
     * `[]`. TypedPress declares its JSON input invalid during sanitizing, so core passes the body through
     * as sent: the same input, decoded again with `{}` kept, is what the ability runs on.
     */
    private static function rawInput(\WP_REST_Request $request): ?array
    {
        if ($request->get_method() !== 'POST' || !preg_match('#^/wp-abilities/v1/abilities/(.+)/run$#', (string) $request->get_route(), $m)) {
            return null;
        }
        try {
            $body = \TypedPress\Js::decodeJson((string) $request->get_body());
        } catch (\JsonException) {
            return null;
        }

        return $body instanceof \TypedPress\JsObject && array_key_exists('input', $body->p) ? [urldecode($m[1]), $body->p['input']] : null;
    }

    /**
     * Run $fn with $json (a raw JSON object) as ability $name's input as sent, so `{}` inside a json()
     * value survives the array core hands to the ability (the facade's actions).
     */
    public static function withSentInput(string $name, string $json, callable $fn): mixed
    {
        $previous = self::$raw;
        try {
            $decoded = trim($json) === '' ? new \TypedPress\JsObject() : \TypedPress\Js::decodeJson($json);
        } catch (\JsonException) {
            $decoded = null;
        }
        self::$raw = $decoded === null ? null : [$name, $decoded];
        try {
            return $fn();
        } finally {
            self::$raw = $previous;
        }
    }

    /** The executing ability's input as sent, when the REST run endpoint carried it. */
    public static function sentInput(string $name, mixed $input): mixed
    {
        return self::$raw !== null && self::$raw[0] === $name ? self::$raw[1] : $input;
    }

    /** rest_post_dispatch: the request that carried the input is over; its operation's headers go out with it. */
    public static function dispatched(mixed $response): mixed
    {
        self::$method = null;
        self::$untyped = null;
        self::$raw = null;
        self::$headers = null;
        if ($response instanceof \WP_REST_Response) {
            foreach (self::$responded as $name => $value) {
                $response->header($name, $value);
            }
        }
        self::$responded = [];

        return $response;
    }

    /** rest_request_after_callbacks: the operation's own headers (`Idempotent-Replayed`) on its response. */
    public static function answered(mixed $response): mixed
    {
        if ($response instanceof \WP_REST_Response) {
            foreach (self::$responded as $name => $value) {
                $response->header($name, $value);
            }
        }
        self::$responded = [];

        return $response;
    }

    /**
     * The run request's headers, lower-cased, for ability $name's own run, once: an ability that code
     * inside it executes (`wp_get_ability()->execute()`) sees none, so an Idempotency-Key sent for the
     * outer run never keys an inner one.
     *
     * @return array<string, string>
     */
    public static function headers(string $name): array
    {
        if (self::$headers === null || self::$headers[0] !== $name) {
            return [];
        }
        $headers = self::$headers[1];
        self::$headers = null;

        return $headers;
    }

    /** The operation's own response headers (`Idempotent-Replayed`), for the run response. */
    public static function responded(array $headers): void
    {
        self::$responded = array_filter($headers, static fn ($v, $k): bool => is_string($v) && strcasecmp((string) $k, 'Content-Type') !== 0, ARRAY_FILTER_USE_BOTH);
    }

    /** Whether the executing ability's input arrived as a query string (core's GET/DELETE run). */
    public static function queryString(): bool
    {
        return in_array(self::$method, ['GET', 'DELETE'], true);
    }

    /** Public view of one descriptor: what core receives, minus callbacks. */
    public static function describe(array $op): array
    {
        $args = self::args($op);
        unset($args['execute_callback'], $args['permission_callback'], $args['ability_class']);

        return ['name' => $op['name']] + $args;
    }

    /** The Rust compiler's kebab-case (`shopping_list` → `shopping-list`, `approveComment` → `approve-comment`). */
    public static function kebab(string $name): string
    {
        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($chars as $i => $c) {
            if ($c === '_' || $c === '-') {
                if (!str_ends_with($out, '-')) {
                    $out .= '-';
                }
                continue;
            }
            $prev = $chars[$i - 1] ?? '';
            $next = $chars[$i + 1] ?? '';
            if (self::upper($c) && $i > 0 && !str_ends_with($out, '-')
                && (self::lower($prev) || ctype_digit($prev) || self::lower($next))) {
                $out .= '-';
            }
            $out .= mb_strtolower($c, 'UTF-8');
        }

        return $out;
    }

    // ================================================================ descriptors

    private static function build(Schema $schema): array
    {
        $app = self::kebab($schema->name());
        $label = self::appLabel($schema);
        $ops = [];
        foreach ($schema->kinds() as $name => $spec) {
            array_push($ops, ...self::collection($app, $label, (string) $name, $spec, $schema->locales()['all'] ?? []));
        }
        foreach ($schema->actions() as $name => $action) {
            $ops[] = self::action($app, $label, (string) $name, $action);
        }
        foreach ($schema->globals() as $name => $global) {
            array_push($ops, ...self::global($app, $label, (string) $name, $global));
        }
        array_push($ops, ...self::media($app, $label));
        // Features add their own operations here (runtime schema changes: TypedPress\RuntimeSchema\Surfaces).
        $ops = apply_filters('tp_ability_operations', $ops, $app);
        if (($enqueue = Module::enqueuer()) !== null) {
            foreach ($schema->jobs() as $name => $job) {
                if ($job['agents'] ?? false) {
                    $ops[] = self::job($app, $label, (string) $name, $job, $enqueue);
                }
            }
        }

        foreach ($ops as &$op) {
            $first = $op['typedpress'] ?? [];
            if (isset($first['action'])) {
                $op['shape'] = $schema->action($first['action'])['input'] ?? [];
            } elseif (isset($first['job'])) {
                $op['shape'] = $schema->jobs()[$first['job']]['input'] ?? [];
            } elseif (isset($first['global'])) {
                $op['shape'] = $op['op'] === 'update' ? $schema->global($first['global'])['fields'] : [];
            } elseif (isset($first['collection']) && $schema->hasCollection($first['collection'])) {
                $collection = $first['collection'];
                $columns = $schema->collection($collection)['columns'];
                $op['shape'] = match ($op['op']) {
                    'create' => $columns,
                    'update', 'stage' => ['id' => null, 'patch' => $columns],
                    'sync-apply', 'sync-trash', 'sync-read' => ['type' => 'sync', 'collection' => $collection],
                    default => ['type' => 'query', 'collection' => $collection],
                };
            } else {
                $op['shape'] = [];
            }
            $op['bodyShape'] = $op['op'] === 'update' && isset($first['collection']) ? $op['shape']['patch'] : $op['shape'];
        }
        unset($op);

        return $ops;
    }

    /** @return list<array> */
    /** @param list<string> $locales */
    private static function collection(string $app, string $appLabel, string $name, array $spec, array $locales): array
    {
        $k = self::kebab($name);
        $agents = (bool) ($spec['agents'] ?? true);
        $rules = Access::collectionRules($spec);
        $row = JsonSchema::row($spec);
        $pk = $spec['columns'][$spec['primaryKey']]['type'] === 'integer' ? ['type' => 'integer'] : ['type' => 'string'];
        $localized = (bool) ($spec['localized'] ?? false);
        $locale = $localized ? ['locale' => ['type' => 'string', 'enum' => $locales]] : [];
        // Virtual create inputs other features consume before column validation (§6.2, §7.3).
        $virtual = ($localized ? ['translationOf' => $pk] : []) + (($spec['auth'] ?? false) ? ['password' => ['type' => 'string']] : []);
        $with = ($spec['relations'] ?? []) !== [] || self::hasMedia($spec) ? ['with' => JsonSchema::with($spec)] : [];
        $item = static fn (string $suffix = ''): \Closure => static fn (array $in): string => "/$name/" . Invoke::id($in) . $suffix;
        $base = ['agents' => $agents, 'typedpress' => ['collection' => $name]];
        $api = $spec['api'] ?? [];
        $ops = [];
        $columns = array_values(array_map('strval', array_keys(array_filter($spec['columns'], static fn (array $c): bool => !($c['hidden'] ?? false)))));

        // A kind with staged versions takes `_rev` (refuse when the row changed since that read) and
        // `takeover` (take another user's edit lock) on its writes ({@see \TypedPress\Wp\Rev}).
        $guard = \TypedPress\Wp\Staged::enabled($spec) ? [\TypedPress\Wp\Rev::FIELD => ['type' => 'string'], \TypedPress\Wp\Rev::TAKEOVER => ['type' => 'boolean']] : [];
        if ($guard !== []) {
            $row['properties'][\TypedPress\Wp\Rev::FIELD] = ['type' => 'string'];
        }
        if ($api['list'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-list", 'op' => 'list',
                'label' => "List $name", 'description' => "List $name of the $appLabel app, filtered, ordered and paged like GET /$name.",
                'input' => JsonSchema::input(['where' => JsonSchema::where($spec), 'orderBy' => JsonSchema::orderBy($columns),
                    'limit' => ['type' => 'integer', 'minimum' => 0], 'offset' => ['type' => 'integer', 'minimum' => 0]] + $with + $locale),
                'output' => ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'items' => $row]], 'required' => ['rows']],
                'annotations' => ['readonly' => true, 'idempotent' => true], 'rule' => [$rules['read'], 'read', "read $name"], 'defer' => true,
                'call' => static fn (array $in): array => ['GET', "/$name", Invoke::listQuery($in), null],
                'result' => static fn (array $rows): array => ['rows' => $rows],
            ] + ($guard === [] ? [] : ['guard' => true]);
        }
        if ($api['get'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-get", 'op' => 'get',
                'label' => "Get one of $name", 'description' => "Get one $name row of the $appLabel app by {$spec['primaryKey']}.",
                'input' => JsonSchema::input(['id' => $pk] + $with + $locale, ['id']),
                'output' => $row, 'annotations' => ['readonly' => true, 'idempotent' => true], 'rule' => [$rules['read'], 'read', "read $name"], 'defer' => true,
                'call' => static fn (array $in): array => ['GET', $item()($in), Invoke::listQuery(array_diff_key($in, ['id' => 1])), null],
            ] + ($guard === [] ? [] : ['guard' => true]);
        }
        if ($api['create'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-create", 'op' => 'create',
                'label' => "Create in $name", 'description' => "Create one $name row in the $appLabel app; the input is the row.",
                'input' => JsonSchema::insert($spec, $virtual), 'output' => $row, 'annotations' => [], 'rule' => [$rules['create'], 'create', "create $name rows"], 'defer' => true,
                'call' => static fn (array $in): array => ['POST', "/$name", [], $in],
                // The new row carries its `_rev`, as the API's create answers.
            ] + ($guard === [] ? [] : ['guard' => true]);
        }
        if ($api['update'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-update", 'op' => 'update',
                'label' => "Update in $name", 'description' => "Update one $name row of the $appLabel app: the given fields of `patch` change, the rest stay."
                    . ($guard === [] ? '' : ' Send the `_rev` of your read to refuse the update if the row changed since.'),
                'input' => JsonSchema::input(['id' => $pk, 'patch' => JsonSchema::patch($spec)] + $guard, ['id', 'patch']),
                'output' => $row, 'annotations' => [], 'rule' => [$rules['update'], 'update', "update $name rows"], 'dataShape' => $spec['columns'], 'data' => static fn (array $in): array => Invoke::object($in['patch'] ?? null) ?? throw new \InvalidArgumentException('patch'),
                'call' => static fn (array $in): array => ['PATCH', $item()($in), [], Invoke::object($in['patch'] ?? false) ?? throw HttpError::invalidInput('patch must be an object', '/patch')],
            ] + ($guard === [] ? [] : ['guard' => true]);
        }
        if ($api['delete'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-delete", 'op' => 'delete',
                'label' => "Delete from $name", 'description' => "Delete one $name row of the $appLabel app; returns the deleted row.",
                'input' => JsonSchema::input(['id' => $pk] + $guard, ['id']), 'output' => $row,
                'annotations' => ['destructive' => true], 'rule' => [$rules['delete'], 'delete', "delete $name rows"],
                'call' => static fn (array $in): array => ['DELETE', $item()($in), [], null],
            ] + ($guard === [] ? [] : ['guard' => true]);
        }
        if ($spec['revisions'] ?? false) {
            $ops[] = $base + [
                'name' => "$app/$k-revisions", 'op' => 'revisions',
                'label' => "Revisions of $name", 'description' => "List the saved revisions of one $name row of the $appLabel app, newest first.",
                'input' => JsonSchema::input(['id' => $pk], ['id']),
                'output' => ['type' => 'object', 'required' => ['revisions'], 'properties' => ['revisions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'at' => ['type' => 'string', 'format' => 'date-time'],
                    'user' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]],
                ]]]]],
                'annotations' => ['readonly' => true, 'idempotent' => true], 'rule' => [$rules['update'], 'update', "update $name rows"],
                'call' => static fn (array $in): array => ['GET', $item('/revisions')($in), [], null],
                'result' => static fn (array $revisions): array => ['revisions' => $revisions],
            ];
        }
        if (\TypedPress\Wp\Staged::enabled($spec) && ($api['update'] ?? true)) {
            array_push($ops, ...self::staged($app, $appLabel, $name, $base, $rules['update'], $row, $pk, $spec));
        }
        // Content sync (§5.3) rides its `/sync` routes: these exist exactly when the sync feature does.
        if (($spec['sync'] ?? null) !== null && Extensions::routeHandler('sync') !== null) {
            // Apply is a create or an update depending on `expect`; only the sync route can tell, so it decides.
            $sync = static fn (string $op, string $label, string $description, array $input, array $annotations, \Closure $call, bool $defer = false): array => $base + [
                'name' => "$app/$k-sync-$op", 'op' => "sync-$op", 'label' => $label, 'description' => $description, 'defer' => $defer,
                'input' => $input, 'output' => [], 'annotations' => $annotations,
                'rule' => $op === 'trash' ? [$rules['delete'], 'delete', "delete $name rows"] : [$rules['update'], 'update', "update $name rows"], 'call' => $call,
            ];
            $ops[] = $sync('read', "Read $name for sync", "Read $name records of the $appLabel app as sync records with digests, by primary key pages.",
                JsonSchema::input(['after' => $pk, 'first' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]),
                ['readonly' => false, 'idempotent' => true],
                static fn (array $in): array => ['GET', "/sync/$name", array_map('strval', array_intersect_key($in, ['after' => 1, 'first' => 1])), null]);
            $ops[] = $sync('apply', "Apply a $name sync record", "Create or update one $name record of the $appLabel app when it still matches `expect`.",
                JsonSchema::input(['record' => ['type' => 'object'], 'expect' => ['type' => 'string']], ['record', 'expect']), [],
                static fn (array $in): array => ['POST', "/sync/$name", [], $in], true);
            $ops[] = $sync('trash', "Trash a $name sync record", "Trash one $name record of the $appLabel app by uid when it still matches `expect`.",
                JsonSchema::input(['uid' => ['type' => 'string'], 'expect' => ['type' => 'string']], ['uid', 'expect']), ['destructive' => true],
                static fn (array $in): array => ['POST', "/sync/$name/trash", [], $in]);
        }

        return $ops;
    }

    /**
     * A post kind's staged version ({@see \TypedPress\Wp\Staged}), one ability per step, so that a
     * credential can be given `-stage` without `-staged-promote`. Each needs the item's update rule;
     * the operation itself decides it (`defer`), so a caller it doesn't cover gets the 404 a missing
     * item gets and never learns whether anything is staged.
     *
     * @return list<array>
     */
    private static function staged(string $app, string $appLabel, string $name, array $base, array $rule, array $row, array $pk, array $spec): array
    {
        $k = self::kebab($name);
        $when = ['type' => 'string', 'format' => 'date-time'];
        $record = [
            'id' => ['type' => 'integer'], 'at' => $when, 'promoteAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'by' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]],
        ];
        $record[\TypedPress\Wp\Rev::FIELD] = ['type' => 'string'];
        $version = ['type' => 'object', 'required' => ['id', 'row', 'fields'], 'properties' => ['row' => $row, 'fields' => ['type' => 'array', 'items' => ['type' => 'string']]] + $record];
        $id = static fn (array $in): int|string => is_int($in['id'] ?? null) || (is_string($in['id'] ?? null) && $in['id'] !== '') ? $in['id'] : throw HttpError::invalidInput('id is required', '/id');
        $op = static fn (string $suffix, string $kind, string $label, string $description, array $input, array $output, array $annotations, \Closure $run): array => $base + [
            'name' => "$app/$k-$suffix", 'op' => $kind, 'label' => $label, 'description' => $description, 'input' => $input, 'output' => $output,
            'annotations' => $annotations, 'rule' => [$rule, 'update', "update $name rows"], 'defer' => true,
            'run' => static fn (array $in): mixed => $run($id($in), $in, Invoke::viewer()),
        ];
        $one = JsonSchema::input(['id' => $pk], ['id']);
        $readonly = ['readonly' => true, 'idempotent' => true];
        // Writes take the staged version's `_rev` and `takeover` ({@see \TypedPress\Wp\Rev}).
        $guard = [\TypedPress\Wp\Rev::FIELD => ['type' => 'string'], \TypedPress\Wp\Rev::TAKEOVER => ['type' => 'boolean']];
        $conditions = static fn (array $in): array => [$in[\TypedPress\Wp\Rev::FIELD] ?? null, ($in[\TypedPress\Wp\Rev::TAKEOVER] ?? false) === true];

        return [
            $op('stage', 'stage', "Stage a change to $name",
                "Save changes to one $name row of the $appLabel app as its staged version: the given fields of `patch` are staged, and the live row stays as it is until the staged version is promoted.",
                JsonSchema::input(['id' => $pk, 'patch' => JsonSchema::patch($spec)] + $guard, ['id', 'patch']), $version, [],
                static fn (int|string $id, array $in, \TypedPress\Viewer $viewer): array => \TypedPress\Wp\Staged::stage($name, $id,
                    (Invoke::object($in['patch'] ?? false) ?? throw HttpError::invalidInput('patch must be an object', '/patch')) + array_intersect_key($in, $guard), $viewer)),
            $op('staged-get', 'staged-get', "Staged version of $name",
                "Read the staged version of one $name row of the $appLabel app: the row as it would be once promoted, and the fields that are staged.",
                $one, $version, $readonly,
                static fn (int|string $id, array $in, \TypedPress\Viewer $viewer): array => \TypedPress\Wp\Staged::get($name, $id, $viewer)),
            $op('staged-compare', 'staged-compare', "Compare staged and live $name",
                "List the staged fields of one $name row of the $appLabel app whose value differs from the live row, with both values.",
                $one, ['type' => 'object', 'required' => ['id', 'changes'], 'properties' => ['changes' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['field'], 'properties' => ['field' => ['type' => 'string']]]]] + $record], $readonly,
                static fn (int|string $id, array $in, \TypedPress\Viewer $viewer): array => \TypedPress\Wp\Staged::compare($name, $id, $viewer)),
            $op('staged-promote', 'staged-promote', "Promote staged $name",
                "Make the staged version of one $name row of the $appLabel app live, as an update of the row. With `at` (a future time) the promote is scheduled instead; `at: null` cancels a scheduled promote.",
                JsonSchema::input(['id' => $pk, 'at' => ['type' => ['string', 'null'], 'format' => 'date-time']] + $guard, ['id']), [], [],
                static fn (int|string $id, array $in, \TypedPress\Viewer $viewer): array => array_key_exists('at', $in)
                    ? \TypedPress\Wp\Staged::schedule($name, $id, $in['at'], $viewer, ...$conditions($in))
                    : \TypedPress\Wp\Rev::attach($name, \TypedPress\Wp\Staged::promote($name, $id, $viewer, ...$conditions($in)))),
            $op('staged-discard', 'staged-discard', "Discard staged $name",
                "Discard the staged version of one $name row of the $appLabel app. The live row is untouched and the discarded version stays in the row's revisions.",
                JsonSchema::input(['id' => $pk] + $guard, ['id']), ['type' => 'object', 'required' => ['revision'], 'properties' => ['revision' => ['type' => 'integer']]], ['idempotent' => false],
                static fn (int|string $id, array $in, \TypedPress\Viewer $viewer): array => \TypedPress\Wp\Staged::discard($name, $id, $viewer, ...$conditions($in))),
            $op('staged-preview', 'staged-preview', "Preview link for staged $name",
                "Create a signed link to the staged version of one $name row of the $appLabel app. It works without signing in until it expires (`ttl` seconds, an hour by default) or the staged version is promoted or discarded.",
                JsonSchema::input(['id' => $pk, 'ttl' => ['type' => 'integer', 'minimum' => 60, 'maximum' => 86400]], ['id']),
                ['type' => 'object', 'required' => ['token', 'expiresAt', 'api'], 'properties' => ['token' => ['type' => 'string'], 'expiresAt' => $when, 'api' => ['type' => 'string'], 'url' => ['type' => ['string', 'null']]]], [],
                static fn (int|string $id, array $in, \TypedPress\Viewer $viewer): array => \TypedPress\Wp\Staged::preview($name, $id, $in['ttl'] ?? null, $viewer)),
        ];
    }

    private static function action(string $app, string $appLabel, string $name, array $action): array
    {
        $required = array_values(array_map('strval', array_keys(array_filter($action['input'] ?? [], Schema::isRequired(...)))));
        $props = array_map(static fn (array $c): array => JsonSchema::column($c), $action['input'] ?? []);

        return [
            'name' => "$app/" . self::kebab($name), 'op' => 'run', 'agents' => (bool) ($action['agents'] ?? true),
            'label' => "Run $name", 'description' => "Run the $name action of the $appLabel app.",
            // The result type, as the compiler inferred it from the handler (none when it has no JSON shape).
            'input' => JsonSchema::input($props, $required), 'output' => is_array($action['output'] ?? null) ? $action['output'] : [],
            // The action's own options (spec §3a). Core's run route picks the method from them: GET for
            // readonly, DELETE for destructive + idempotent, POST otherwise.
            'annotations' => array_filter([
                'readonly' => (bool) ($action['readonly'] ?? false),
                'destructive' => (bool) ($action['destructive'] ?? false),
                'idempotent' => (bool) ($action['idempotent'] ?? false) || (bool) ($action['readonly'] ?? false),
            ]),
            'rule' => [$action['access'] ?? Access::DEFAULT_WRITE, 'create', "run $name"],
            // A signature replaces the access check (§6.1); the action path verifies it, so nothing is judged early.
            'defer' => ($action['signature'] ?? null) !== null,
            'call' => static fn (array $in): array => ['POST', '/actions/' . rawurlencode($name), [], $in],
            // `personal`: the handler's code reads orders or calls an ability that needs the grant (the
            // compiler's Action::personal); AgentScope::withheld leaves it out of a credential's lists.
            'typedpress' => ['action' => $name] + (($action['personal'] ?? false) ? ['personal' => true] : []),
        ];
    }

    /** @return list<array> */
    private static function global(string $app, string $appLabel, string $name, array $global): array
    {
        $k = self::kebab($name);
        $rules = Access::globalRules($global);
        $values = JsonSchema::fields($global['fields'], true);
        $base = ['agents' => (bool) ($global['agents'] ?? true), 'typedpress' => ['global' => $name]];
        $label = $global['label'] ?? $name;
        $patch = array_map(static fn (array $c): array => JsonSchema::column($c), $global['fields']);

        return [
            $base + [
                'name' => "$app/global-$k-get", 'op' => 'get', 'label' => "Get $label", 'description' => "Read the $label values of the $appLabel app.",
                'input' => JsonSchema::input([]), 'output' => $values, 'annotations' => ['readonly' => true, 'idempotent' => true],
                'rule' => [$rules['read'], 'read', "read $name"], 'call' => static fn (array $in): array => ['GET', '/globals/' . rawurlencode($name), [], null],
            ],
            $base + [
                'name' => "$app/global-$k-update", 'op' => 'update', 'label' => "Update $label",
                'description' => "Change some $label values of the $appLabel app; the input is the patch.",
                'input' => JsonSchema::input($patch), 'output' => $values, 'annotations' => [],
                'rule' => [$rules['update'], 'update', "update $name"], 'dataShape' => $global['fields'], 'data' => static fn (array $in): array => $in,
                'call' => static fn (array $in): array => ['PATCH', '/globals/' . rawurlencode($name), [], $in],
            ],
        ];
    }

    /** @return list<array> media list/get/delete; uploads stay HTTP (abilities carry no files). */
    private static function media(string $app, string $appLabel): array
    {
        $row = JsonSchema::media();
        $base = ['agents' => true, 'typedpress' => ['collection' => 'media']];
        $id = ['id' => ['type' => 'integer']];

        return [
            $base + [
                'name' => "$app/media-list", 'op' => 'list', 'label' => 'List media', 'description' => "List the media library of the $appLabel app.",
                'input' => JsonSchema::input(['where' => JsonSchema::mediaWhere(), 'orderBy' => JsonSchema::orderBy(['id', 'filename', 'mime', 'alt', 'createdAt']),
                    'limit' => ['type' => 'integer', 'minimum' => 0], 'offset' => ['type' => 'integer', 'minimum' => 0]]),
                'output' => ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'items' => $row]], 'required' => ['rows']],
                'annotations' => ['readonly' => true, 'idempotent' => true], 'rule' => [Access::MEDIA['read'], 'read', 'read media'],
                'call' => static fn (array $in): array => ['GET', '/media', Invoke::listQuery($in), null],
                'result' => static fn (array $rows): array => ['rows' => $rows],
            ],
            $base + [
                'name' => "$app/media-get", 'op' => 'get', 'label' => 'Get media', 'description' => "Get one media item of the $appLabel app by id.",
                'input' => JsonSchema::input($id, ['id']), 'output' => $row, 'annotations' => ['readonly' => true, 'idempotent' => true],
                'rule' => [Access::MEDIA['read'], 'read', 'read media'], 'call' => static fn (array $in): array => ['GET', '/media/' . Invoke::id($in), [], null],
            ],
            $base + [
                'name' => "$app/media-delete", 'op' => 'delete', 'label' => 'Delete media', 'description' => "Delete one media item of the $appLabel app and its files.",
                'input' => JsonSchema::input($id, ['id']), 'output' => $row, 'annotations' => ['destructive' => true],
                'rule' => [Access::MEDIA['delete'], 'delete', 'delete media'], 'call' => static fn (array $in): array => ['DELETE', '/media/' . Invoke::id($in), [], null],
            ],
        ];
    }

    private static function job(string $app, string $appLabel, string $name, array $job, \Closure $enqueue): array
    {
        $required = array_values(array_map('strval', array_keys(array_filter($job['input'] ?? [], Schema::isRequired(...)))));

        return [
            'name' => "$app/job-" . self::kebab($name), 'op' => 'enqueue', 'agents' => true,
            'label' => "Enqueue $name", 'description' => "Queue the $name job of the $appLabel app; returns the job id.",
            'input' => JsonSchema::input(array_map(static fn (array $c): array => JsonSchema::column($c), $job['input'] ?? []), $required),
            'output' => ['type' => 'object', 'properties' => ['id' => ['type' => ['integer', 'string']]], 'required' => ['id']],
            'annotations' => [], 'rule' => [Access::DEFAULT_WRITE, 'create', "enqueue $name"], 'typedpress' => ['job' => $name],
            'run' => static function (array $in) use ($enqueue, $name): array {
                Access::require(Access::DEFAULT_WRITE, Invoke::viewer(), 'create', null, "enqueue $name");
                $db = Runtime::plugin()?->app()->db ?? throw new \RuntimeException('No TypedPress app is active');

                return ['id' => $db->transaction(static function () use ($db, $enqueue, $name, $in) {
                    $previous = $db->actor();
                    $db->setActor(Invoke::viewer()->user);
                    try {
                        return $enqueue($name, $in);
                    } finally {
                        $db->setActor($previous);
                    }
                })];
            },
        ];
    }

    // ================================================================ core args

    private static function args(array $op): array
    {
        return [
            'label' => $op['label'],
            'description' => $op['description'],
            'category' => explode('/', $op['name'])[0],
            'input_schema' => $op['input'],
            'output_schema' => $op['output'],
            'permission_callback' => static fn (mixed $input = null): bool|\WP_Error => Invoke::allowed($op, $input),
            'execute_callback' => static fn (mixed $input = null): mixed => Invoke::call($op, $input),
            'ability_class' => TypedPressAbility::class,
            'meta' => [
                'public' => $op['agents'],
                'show_in_rest' => $op['agents'],
                'mcp' => ['public' => $op['agents']],
                'annotations' => $op['annotations'],
                'typedpress' => $op['typedpress'] + ['op' => $op['op']],
            ],
        ];
    }

    private static function schema(): ?Schema
    {
        return Runtime::plugin()?->schema;
    }

    private static function appLabel(Schema $schema): string
    {
        return ucfirst($schema->name());
    }

    private static function hasMedia(array $spec): bool
    {
        foreach ($spec['columns'] as $col) {
            if (in_array($col['type'], ['media', 'mediaList'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function upper(string $c): bool
    {
        return $c !== '' && mb_strtolower($c, 'UTF-8') !== $c;
    }

    private static function lower(string $c): bool
    {
        return $c !== '' && mb_strtoupper($c, 'UTF-8') !== $c;
    }
}
