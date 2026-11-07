<?php

declare(strict_types=1);

namespace Zero\Wp\Abilities;

use Zero\Abilities\Module;
use Zero\Access;
use Zero\Extensions;
use Zero\HttpError;
use Zero\Schema;
use Zero\Wp\Runtime;

/**
 * Every API operation of the active app as a core WordPress Ability `<app>/<kebab-name>` (§7.1),
 * plus the app's named MCP server. Descriptors are built once per request from schema.php:
 *   name, op, label, description, input, output, annotations, agents, zero (meta),
 *   rule [rule, op, what], data? (input → rule data), defer? (rule needs the pipeline),
 *   call (input → [method, path, query, body]) and result? (data → output), or run (input → output).
 * `agents: false` keeps the ability registered (PHP, `wp zero run`) but out of core REST and MCP.
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

    public static function categories(): void
    {
        $schema = self::schema();
        if ($schema !== null) {
            wp_register_ability_category(self::kebab($schema->name()), [
                'label' => self::appLabel($schema),
                'description' => 'Operations of the ' . self::appLabel($schema) . ' app, built with Zero.',
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
        if ($schema === null) {
            return;
        }
        $id = 'zero-' . self::kebab($schema->name());
        $tools = array_values(array_map(static fn (array $op): string => $op['name'], array_filter(self::operations(), static fn (array $op): bool => $op['agents'])));
        $adapter->create_server($id, 'mcp', $id, self::appLabel($schema), 'Zero app ' . $schema->name(), Runtime::VERSION,
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
     * wp_ability_validate_input: Zero's pipeline is the one validator of its abilities, so a bad
     * input gets REST's 422 problem and pointer instead of core's generic 400. While the REST run
     * endpoint sanitizes its `input` argument, core sanitizes only what validates: query-string
     * input (GET/DELETE) is then typed by core's own check, as a query string must be, and JSON
     * input (POST) is declared invalid so core passes it through byte-for-byte to Zero.
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
            default => new \WP_Error('zero_raw_input', 'Zero validates JSON input itself'),
        };
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
                self::$raw = $request instanceof \WP_REST_Request ? self::rawInput($request) : null;
            }

            return $passthrough;
        };
    }

    /**
     * Core decodes a POSTed run body associatively, so `{}` in a json() value reaches the ability as
     * `[]`. Zero declares its JSON input invalid during sanitizing, so core passes the body through
     * as sent: the same input, decoded again with `{}` kept, is what the ability runs on.
     */
    private static function rawInput(\WP_REST_Request $request): ?array
    {
        if ($request->get_method() !== 'POST' || !preg_match('#^/wp-abilities/v1/abilities/(.+)/run$#', (string) $request->get_route(), $m)) {
            return null;
        }
        try {
            $body = \Zero\Js::decodeJson((string) $request->get_body());
        } catch (\JsonException) {
            return null;
        }

        return $body instanceof \Zero\JsObject && array_key_exists('input', $body->p) ? [urldecode($m[1]), $body->p['input']] : null;
    }

    /** The executing ability's input as sent, when the REST run endpoint carried it. */
    public static function sentInput(string $name, mixed $input): mixed
    {
        return self::$raw !== null && self::$raw[0] === $name ? self::$raw[1] : $input;
    }

    /** rest_post_dispatch: the request that carried the input is over. */
    public static function dispatched(mixed $response): mixed
    {
        self::$method = null;
        self::$untyped = null;
        self::$raw = null;

        return $response;
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
        unset($args['execute_callback'], $args['permission_callback']);

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
        foreach ($schema->collections() as $name => $spec) {
            array_push($ops, ...self::collection($app, $label, (string) $name, $spec, $schema->locales()['all'] ?? []));
        }
        foreach ($schema->actions() as $name => $action) {
            $ops[] = self::action($app, $label, (string) $name, $action);
        }
        foreach ($schema->globals() as $name => $global) {
            array_push($ops, ...self::global($app, $label, (string) $name, $global));
        }
        array_push($ops, ...self::media($app, $label));
        if (($enqueue = Module::enqueuer()) !== null) {
            foreach ($schema->jobs() as $name => $job) {
                if ($job['agents'] ?? false) {
                    $ops[] = self::job($app, $label, (string) $name, $job, $enqueue);
                }
            }
        }

        foreach ($ops as &$op) {
            $zero = $op['zero'] ?? [];
            if (isset($zero['action'])) {
                $op['shape'] = $schema->action($zero['action'])['input'] ?? [];
            } elseif (isset($zero['job'])) {
                $op['shape'] = $schema->jobs()[$zero['job']]['input'] ?? [];
            } elseif (isset($zero['global'])) {
                $op['shape'] = $op['op'] === 'update' ? $schema->global($zero['global'])['fields'] : [];
            } elseif (isset($zero['collection']) && $schema->hasCollection($zero['collection'])) {
                $collection = $zero['collection'];
                $columns = $schema->collection($collection)['columns'];
                $op['shape'] = match ($op['op']) {
                    'create' => $columns,
                    'update' => ['id' => null, 'patch' => $columns],
                    'sync-apply', 'sync-trash', 'sync-read' => ['type' => 'sync', 'collection' => $collection],
                    default => ['type' => 'query', 'collection' => $collection],
                };
            } else {
                $op['shape'] = [];
            }
            $op['bodyShape'] = $op['op'] === 'update' && isset($zero['collection']) ? $op['shape']['patch'] : $op['shape'];
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
        $base = ['agents' => $agents, 'zero' => ['collection' => $name]];
        $api = $spec['api'] ?? [];
        $ops = [];
        $columns = array_values(array_map('strval', array_keys(array_filter($spec['columns'], static fn (array $c): bool => !($c['hidden'] ?? false)))));

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
            ];
        }
        if ($api['get'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-get", 'op' => 'get',
                'label' => "Get one of $name", 'description' => "Get one $name row of the $appLabel app by {$spec['primaryKey']}.",
                'input' => JsonSchema::input(['id' => $pk] + $with + $locale, ['id']),
                'output' => $row, 'annotations' => ['readonly' => true, 'idempotent' => true], 'rule' => [$rules['read'], 'read', "read $name"], 'defer' => true,
                'call' => static fn (array $in): array => ['GET', $item()($in), Invoke::listQuery(array_diff_key($in, ['id' => 1])), null],
            ];
        }
        if ($api['create'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-create", 'op' => 'create',
                'label' => "Create in $name", 'description' => "Create one $name row in the $appLabel app; the input is the row.",
                'input' => JsonSchema::insert($spec, $virtual), 'output' => $row, 'annotations' => [], 'rule' => [$rules['create'], 'create', "create $name rows"], 'defer' => true,
                'call' => static fn (array $in): array => ['POST', "/$name", [], $in],
            ];
        }
        if ($api['update'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-update", 'op' => 'update',
                'label' => "Update in $name", 'description' => "Update one $name row of the $appLabel app: the given fields of `patch` change, the rest stay.",
                'input' => JsonSchema::input(['id' => $pk, 'patch' => JsonSchema::patch($spec)], ['id', 'patch']),
                'output' => $row, 'annotations' => [], 'rule' => [$rules['update'], 'update', "update $name rows"], 'dataShape' => $spec['columns'], 'data' => static fn (array $in): array => Invoke::object($in['patch'] ?? null) ?? throw new \InvalidArgumentException('patch'),
                'call' => static fn (array $in): array => ['PATCH', $item()($in), [], Invoke::object($in['patch'] ?? false) ?? throw HttpError::invalidInput('patch must be an object', '/patch')],
            ];
        }
        if ($api['delete'] ?? true) {
            $ops[] = $base + [
                'name' => "$app/$k-delete", 'op' => 'delete',
                'label' => "Delete from $name", 'description' => "Delete one $name row of the $appLabel app; returns the deleted row.",
                'input' => JsonSchema::input(['id' => $pk], ['id']), 'output' => $row,
                'annotations' => ['destructive' => true], 'rule' => [$rules['delete'], 'delete', "delete $name rows"],
                'call' => static fn (array $in): array => ['DELETE', $item()($in), [], null],
            ];
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

    private static function action(string $app, string $appLabel, string $name, array $action): array
    {
        $required = array_values(array_map('strval', array_keys(array_filter($action['input'] ?? [], Schema::isRequired(...)))));
        $props = array_map(static fn (array $c): array => JsonSchema::column($c), $action['input'] ?? []);

        return [
            'name' => "$app/" . self::kebab($name), 'op' => 'run', 'agents' => (bool) ($action['agents'] ?? true),
            'label' => "Run $name", 'description' => "Run the $name action of the $appLabel app.",
            'input' => JsonSchema::input($props, $required), 'output' => [],
            // §7.1's destructive flag needs the compiler's `writes` (handler calls db.delete); schema.php v3 has none yet.
            'annotations' => ($action['idempotent'] ?? false) ? ['idempotent' => true] : [],
            'rule' => [$action['access'] ?? Access::DEFAULT_WRITE, 'create', "run $name"],
            // A signature replaces the access check (§6.1); the action path verifies it, so nothing is judged early.
            'defer' => ($action['signature'] ?? null) !== null,
            'call' => static fn (array $in): array => ['POST', '/actions/' . rawurlencode($name), [], $in],
            'zero' => ['action' => $name],
        ];
    }

    /** @return list<array> */
    private static function global(string $app, string $appLabel, string $name, array $global): array
    {
        $k = self::kebab($name);
        $rules = Access::globalRules($global);
        $values = JsonSchema::fields($global['fields'], true);
        $base = ['agents' => (bool) ($global['agents'] ?? true), 'zero' => ['global' => $name]];
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
        $base = ['agents' => true, 'zero' => ['collection' => 'media']];
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
            'annotations' => [], 'rule' => [Access::DEFAULT_WRITE, 'create', "enqueue $name"], 'zero' => ['job' => $name],
            'run' => static function (array $in) use ($enqueue, $name): array {
                Access::require(Access::DEFAULT_WRITE, Invoke::viewer(), 'create', null, "enqueue $name");
                $db = Runtime::plugin()?->app()->db ?? throw new \RuntimeException('No Zero app is active');

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
            'meta' => [
                'public' => $op['agents'],
                'show_in_rest' => $op['agents'],
                'mcp' => ['public' => $op['agents']],
                'annotations' => $op['annotations'],
                'zero' => $op['zero'] + ['op' => $op['op']],
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
