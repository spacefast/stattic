<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * Read-only view of the generated schema.php (version 5, phase-2 §3.1) plus the value codecs shared
 * by the write pipeline, filters, globals and action input validation (§3.4).
 *
 * Values exist in three forms:
 *  - input: whatever the client/hook sent;
 *  - normalized (JS form): coerced + validated, nested defaults applied — what hooks, rules and
 *    responses see (`normalize`);
 *  - storage: int|float|string|null as written by every store (`store`; JSON text for the
 *    JSON-valued types). `encode` = store(normalize(..)). `decode` turns storage back into JS form.
 */
final class Schema
{
    public const SERIES = '0.3';

    /** The role ladder, lowest first; guests (§6.2) sit below subscribers. */
    public const ROLES = ['guest', 'subscriber', 'author', 'editor', 'admin'];
    private const JSON_TYPES = ['json', 'mediaList', 'select', 'refList', 'group', 'repeater', 'blocks'];
    private const STRING_TYPES = ['text', 'email', 'url', 'slug', 'richText'];
    private const LIST_TYPES = ['mediaList', 'select', 'refList', 'repeater', 'blocks'];
    private const SLUG_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /** @param array{version?: int, runtime?: string, collections?: array<string, array>, globals?: array<string, array>, actions?: array<string, array>} $raw */
    public function __construct(private readonly array $raw)
    {
    }

    public static function load(string $file): self
    {
        $raw = require $file;
        if (!is_array($raw)) {
            throw new \RuntimeException("$file must return an array");
        }
        if (($raw['version'] ?? null) !== 5) {
            throw new \RuntimeException('schema.php version 5 required; rebuild with this TypedPress version');
        }

        if (($raw['runtime'] ?? null) !== self::SERIES) {
            $series = $raw['runtime'] ?? '(missing)';
            throw new \RuntimeException("schema.php built for runtime $series; this runtime is " . self::SERIES . '; rebuild with this TypedPress version');
        }

        // `runtimeSchema: true` (spec §2.10): the site's active schema version adds its fields.
        return new self(isset($raw['runtimeSchema']) ? RuntimeSchema\Loader::merge($raw, dirname($file)) : $raw);
    }

    /**
     * Null unless the app set `runtimeSchema: true`. Otherwise the version the build contains
     * (`version`, `hash`) and the site's active version this request runs (`active`: its `version`
     * and `hash`).
     */
    public function commerce(): ?array { return $this->raw['store'] ?? null; }

    public function raw(): array { return $this->raw; }

    public function retention(): ?array
    {
        return is_array($this->raw['retention'] ?? null) ? $this->raw['retention'] : null;
    }

    public function runtimeSchema(): ?array
    {
        return $this->raw['runtimeSchema'] ?? null;
    }

    public function version(): int
    {
        return $this->raw['version'];
    }

    public function runtime(): ?string
    {
        return $this->raw['runtime'] ?? null;
    }

    public function name(): string
    {
        return (string) ($this->raw['name'] ?? 'app');
    }

    /**
     * WordPress's and TypedPress's own first path segments (`wp-admin`, `wp-json`, `__tp`, `feed`, …),
     * lowercased: what the app's files never answer for.
     *
     * @return list<string>
     */
    public function reserved(): array
    {
        return array_map('strtolower', $this->raw['reserved']);
    }

    /**
     * Resolves `.alias(...)` field names in an input, patch, filter, or orderBy object to their
     * canonical column names. Throws 422 if both a canonical field and its alias are provided.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function resolveAliases(array $spec, array $data, string $ptr = ''): array
    {
        $map = [];
        foreach ($spec['columns'] ?? [] as $name => $col) {
            foreach ($col['aliases'] ?? [] as $alias) {
                $map[(string) $alias] = (string) $name;
            }
        }
        if ($map === []) {
            return $data;
        }
        $out = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            $canonical = $map[$key] ?? $key;
            if ($canonical !== $key && array_key_exists($canonical, $data)) {
                throw HttpError::invalidInput("Cannot provide both '$canonical' and its alias '$key'", $ptr . HttpError::seg($key));
            }
            if (array_key_exists($canonical, $out)) {
                throw HttpError::invalidInput("Duplicate aliased field for '$canonical'", $ptr . HttpError::seg($key));
            }
            $out[$canonical] = $v;
        }

        return $out;
    }

    /** @return array<string, array> */
    public function collections(): array
    {
        return $this->raw['collections'] ?? [];
    }

    /**
     * `taxonomy()` kinds (docs/decisions/2026-11-11-authoring-surface.md, rulings 25-34): the same kind
     * shape as a collection, with `taxonomy => true` and `wp.taxonomy`. They live in core's term tables,
     * so they stay out of {@see collections()}; {@see collection()} resolves them by name like any kind.
     *
     * @return array<string, array>
     */
    public function taxonomies(): array
    {
        return $this->raw['taxonomies'] ?? [];
    }

    /** @return array<string, array> every kind with rows: collections, tables, users and taxonomies */
    public function kinds(): array
    {
        return $this->collections() + $this->taxonomies();
    }

    /** @return array<string, array> */
    public function globals(): array
    {
        return $this->raw['globals'] ?? [];
    }

    /** @return array<string, array> */
    public function actions(): array
    {
        return $this->raw['actions'] ?? [];
    }

    /**
     * `adminScreen()` exports by screen id, each the declaration tp_admin_register_screen() takes.
     *
     * @return array<string, array>
     */
    public function screens(): array
    {
        return $this->raw['screens'] ?? [];
    }

    /**
     * `adminWidget()` exports by widget id.
     *
     * @return array<string, array>
     */
    public function widgets(): array
    {
        return $this->raw['widgets'] ?? [];
    }

    /**
     * `adminDashboard()` exports by dashboard id.
     *
     * @return array<string, array>
     */
    public function dashboards(): array
    {
        return $this->raw['dashboards'] ?? [];
    }

    public function site(): array
    {
        return $this->raw['site'] ?? ['uidPrefix' => 'zr_'];
    }

    public function locales(): ?array
    {
        return $this->raw['locales'] ?? null;
    }

    public function media(): array
    {
        return $this->raw['media'] ?? ['sizes' => []];
    }

    public function uploads(): array
    {
        return $this->raw['uploads'] ?? ['backend' => 'local'];
    }

    public function files(): array
    {
        return $this->raw['files'] ?? [];
    }

    /** `sealed`: the plugin directory slugs a sealed site runs (docs/sealed.md), or null when the site isn't sealed. */
    public function sealed(): ?array
    {
        $sealed = $this->raw['sealed'] ?? null;

        return is_array($sealed) ? array_values(array_filter((array) ($sealed['plugins'] ?? []), 'is_string')) : null;
    }

    /** `publicAuthors`: core's author archives and user listings stay public (spec §6). */
    public function publicAuthors(): bool
    {
        return ($this->raw['publicAuthors'] ?? false) === true;
    }

    /** `xmlrpc`: XML-RPC as WordPress ships it, unless the config turns it off (spec §6). */
    public function xmlrpc(): bool
    {
        return ($this->raw['xmlrpc'] ?? true) !== false;
    }

    /** `pings`: pingbacks and trackbacks as WordPress ships them, unless the config turns them off (spec §6). */
    public function pings(): bool
    {
        return ($this->raw['pings'] ?? true) !== false;
    }

    public function auth(): array
    {
        return $this->raw['auth'] ?? ['collection' => null, 'guests' => true, 'accessTtl' => 300, 'refreshTtl' => 2592000];
    }

    /**
     * Server components: `components/<file>.tsx#<export>` => the compiled function in `TypedPressApp`,
     * which takes the props object and returns `\TypedPress\Html`.
     *
     * @return array<string, string>
     */
    /** @return array<string, array{tag: string, component: string, attributes: array, required: list<string>, children: bool}> blocks content may use, by block name */
    public function contentBlocks(): array
    {
        return $this->raw['blocks'] ?? [];
    }

    public function components(): array
    {
        return $this->raw['components'] ?? [];
    }

    /**
     * Compiled `"use client"` components: namespace => the store module under the app plugin's
     * `tp/app/client/`, the server actions it calls, and any imported static assets.
     *
     * @return array<string, array{file: string, actions: list<string>, assets?: array<string, mixed>, live?: bool}>
     */
    public function islands(): array
    {
        return $this->raw['islands'] ?? [];
    }

    /**
     * Intrinsic dimensions and format for imported image assets, by filename under `tp/app/assets/`.
     *
     * @return array<string, array{width: int, height: int, format: string}>
     */
    public function assetsMeta(): array
    {
        return $this->raw['assetsMeta'] ?? [];
    }

    /** `frontend` (spec §5): null (WordPress or tp/app/ renders), false (backend only) or the external site's URL. */
    public function frontend(): string|false|null
    {
        return $this->raw['frontend'] ?? null;
    }

    /**
     * `tp/app/`: `routes` (most specific first), `notFound`, and `files` (route file => its
     * compiled component, metadata, guard, search fields and HTTP handlers); null without an app.
     *
     * @return ?array{routes: list<array>, notFound: ?array, files: array<string, array>}
     */
    public function app(): ?array
    {
        return $this->raw['app'] ?? null;
    }

    /** @return list<array{hook: string, filter: bool, priority: int, args: int, handler: string}> */
    public function wpHooks(): array
    {
        return $this->raw['wpHooks'] ?? [];
    }

    /** @return array<string, list<string>> class => the public properties its hook view exposes */
    public function wpViews(): array
    {
        return $this->raw['wpViews'] ?? [];
    }

    public function jobs(): array
    {
        return $this->raw['jobs'] ?? [];
    }

    public function webhooks(): array
    {
        return $this->raw['webhooks'] ?? [];
    }

    public function hasCollection(string $name): bool
    {
        return isset($this->raw['collections'][$name]) || isset($this->raw['taxonomies'][$name]);
    }

    public function collection(string $name): array
    {
        return $this->raw['collections'][$name] ?? $this->raw['taxonomies'][$name]
            ?? throw HttpError::notFound("Unknown collection '$name'");
    }

    /**
     * How comments behave on a collection (spec §2.3): `guests` (bool), `moderation` ("none",
     * "first-time" or "all"), `threaded` (reply depth, 1 is flat), `closeAfterDays` (int, or null for
     * never, counted from the item's publish date) and `types` (comment types allowed besides normal
     * comments). Null when the collection takes no comments.
     *
     * @return ?array{guests: bool, moderation: string, threaded: int, closeAfterDays: ?int, types: list<string>}
     */
    public function commentPolicy(string $collection): ?array
    {
        $spec = $this->collection($collection);
        if (($spec['comments'] ?? false) !== true) {
            return null;
        }

        return $spec['commentPolicy'];
    }

    public function global(string $name): array
    {
        return $this->raw['globals'][$name]
            ?? throw HttpError::notFound("Unknown global '$name'");
    }

    public function action(string $name): array
    {
        return $this->raw['actions'][$name]
            ?? throw HttpError::notFound("Unknown action '$name'");
    }

    /** Public description (§2): no function names, hooks, beforeCreateFills or WP identifiers. */
    public function describe(): array
    {
        $collections = [];
        foreach ($this->kinds() as $name => $c) {
            $collections[$name] = [
                'key' => $c['key'] ?? $name,
                'label' => $c['label'] ?? $name,
                'editor' => $c['editor'] ?? false,
                'drafts' => $c['drafts'] ?? false,
                'revisions' => $c['revisions'] ?? false,
                'uid' => $c['uid'] ?? false,
                'sync' => $c['sync'] ?? null,
                'auth' => $c['auth'] ?? false,
                'agents' => $c['agents'] ?? true,
                'localized' => $c['localized'] ?? false,
            ] + (isset($c['commerce']) ? ['commerce' => $c['commerce']] : []) + (($c['taxonomy'] ?? false) ? ['taxonomy' => true, 'hierarchical' => (bool) ($c['hierarchical'] ?? false)] : []) + [
                'title' => $c['title'] ?? null,
                'content' => $c['content'] ?? null,
                'slug' => $c['slug'] ?? null,
                'primaryKey' => $c['primaryKey'],
            ] + (isset($c['excerpt']) ? ['excerpt' => $c['excerpt']] : []) + (isset($c['ui']) ? ['ui' => $c['ui']] : []) + [
                'columns' => array_map(self::publicColumn(...), $c['columns']),
                'relations' => $c['relations'] ?? [],
                'access' => array_map(self::accessSummary(...), Access::collectionRules($c)),
                'api' => $c['api'] ?? [],
            ];
        }
        $globals = [];
        foreach ($this->globals() as $name => $g) {
            $globals[$name] = [
                'label' => $g['label'] ?? $name,
                'agents' => $g['agents'] ?? true,
                'fields' => array_map(self::publicColumn(...), $g['fields']),
                'access' => array_map(self::accessSummary(...), Access::globalRules($g)),
            ] + (isset($g['ui']) ? ['ui' => $g['ui']] : []);
        }
        $actions = [];
        foreach ($this->actions() as $name => $a) {
            $actions[$name] = [
                'input' => array_map(self::publicColumn(...), $a['input'] ?? []),
                'agents' => $a['agents'] ?? true,
                'idempotent' => $a['idempotent'] ?? false,
                'access' => self::accessSummary($a['access'] ?? Access::DEFAULT_WRITE),
            ];
        }

        $runtimeSchema = $this->runtimeSchema();

        return Extensions::describe(($runtimeSchema === null ? [] : [
            // The schema below is the build's plus this version of the site's own changes (spec §2.10).
            'runtimeSchema' => ['version' => $runtimeSchema['active']['version'] ?? 0, 'hash' => $runtimeSchema['active']['hash'] ?? '', 'build' => ['version' => $runtimeSchema['version'] ?? 0, 'hash' => $runtimeSchema['hash'] ?? '']]
                // Why the site serves less than its newest version: a broken chain or an option written outside apply.
                + (isset($runtimeSchema['active']['problem']) ? ['problem' => $runtimeSchema['active']['problem']] : []),
        ]) + [
            'name' => $this->name(),
            'runtime' => ['series' => $this->runtime()],
            'locales' => $this->locales(), 'media' => $this->media(),
            'files' => array_map(static fn (array $b): array => ['accept' => $b['accept'], 'maxBytes' => $b['maxBytes']], $this->files()),
            'auth' => array_intersect_key($this->auth(), ['collection' => true, 'guests' => true]),
            'collections' => $collections, 'globals' => $globals, 'actions' => $actions,
        ]);
    }

    private static function accessSummary(array $rule): string|bool
    {
        return match (true) {
            isset($rule['fn']) => 'rule',
            ($rule['self'] ?? false) === true => 'self',
            ($rule['owner'] ?? false) === true => 'owner',
            isset($rule['role']) => (string) $rule['role'],
            ($rule['allow'] ?? false) === true => 'anyone',
            default => false,
        };
    }

    private static function publicColumn(array $col): array
    {
        foreach (['default', 'onUpdate'] as $k) {
            if (isset($col[$k]['fn'])) {
                $col[$k] = ['fn' => true];
            }
        }
        if (isset($col['rules']['validate'])) {
            $col['rules']['validate'] = true;
        }
        if (isset($col['access'])) {
            $col['access'] = array_map(self::accessSummary(...), $col['access']);
        }
        if (isset($col['fields'])) {
            $col['fields'] = array_map(self::publicColumn(...), $col['fields']);
        }
        if (isset($col['layouts'])) {
            $col['layouts'] = array_map(static fn (array $fields) => array_map(self::publicColumn(...), $fields), $col['layouts']);
        }

        return $col;
    }

    /** Filter operators on a field (`{ gt: 1, OR: [...] }`). */
    public const FIELD_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'notIn', 'like', 'notLike', 'ilike', 'notIlike', 'isNull', 'isNotNull', 'has', 'NOT', 'OR', 'AND'];

    /**
     * Whether a filter on `$key` goes through its relation: a `ref()` field is both the id column and
     * the relation (`where: { location: 5 }` filters the id, `where: { location: { name: "x" } }`
     * filters through it), so an object whose keys aren't all field operators means the relation.
     */
    public static function filtersRelation(array $spec, string $key, mixed $value): bool
    {
        if (!isset($spec['relations'][$key])) {
            return false;
        }
        if (!isset($spec['columns'][$key])) {
            return true;
        }
        $value = $value instanceof JsObject ? $value->p : ($value instanceof \stdClass ? get_object_vars($value) : $value);

        return is_array($value) && $value !== [] && !array_is_list($value)
            && array_diff(array_map('strval', array_keys($value)), self::FIELD_OPS) !== [];
    }

    // ================================================================ generators

    /** True when a create must receive this column (no default, not generated). */
    public static function isRequired(array $col): bool
    {
        return (($col['notNull'] ?? false) || ($col['required'] ?? false)) && !isset($col['default']) && !($col['omit'] ?? false) && !($col['autoIncrement'] ?? false) && !($col['readOnly'] ?? false)
            && !($col['type'] === 'slug' && isset($col['source'])); // derived when left out
    }

    public static function isJsonType(string $type): bool
    {
        return in_array($type, self::JSON_TYPES, true);
    }

    /** Resolve a `default` / `onUpdate` spec. */
    public static function generate(array $spec, array $col): mixed
    {
        $type = $col['type'];
        if (isset($spec['json'])) {
            // Compiler-produced literals have already been validated; the request depth limit does not apply.
            return Seam::generated($col, Js::decodeJson($spec['json'], 2147483647));
        }
        if (array_key_exists('value', $spec)) {
            return $spec['value'];
        }
        if ($spec['now'] ?? false) {
            return $type === 'date' ? gmdate('Y-m-d', intdiv(Js::now(), 1000)) : Js::iso(Js::now());
        }
        if (isset($spec['fn'])) {
            $slot = $spec['fn'];
            $fn = is_string($slot) ? '\\TypedPressApp\\' . $slot : $slot;

            return Seam::generated($col, $fn());
        }

        throw new \LogicException('Invalid generator spec');
    }

    // ================================================================ codecs

    /**
     * Validate an input value and convert it to its storage form (v1 entry point; no rules).
     *
     * @throws HttpError invalid_input
     */
    public static function encode(array $col, mixed $v, string $name, string $pointer): int|float|string|null
    {
        return self::store($col, self::normalize($col, $v, $name, $pointer));
    }

    /**
     * Coerce + type-check an input value into its JS form. Nested values get every sub-key, with
     * sub-field defaults applied. Rules (min/max/pattern/validate) are NOT run here: see checkRules.
     *
     * @throws HttpError invalid_input with an RFC 6901 pointer to the offending (nested) value
     */
    public static function normalize(array $col, mixed $v, string $name, string $pointer): mixed
    {
        if ($v === null || $v === JsUndefined::Value) {
            if ($col['notNull'] ?? false) {
                throw HttpError::invalidInput("$name is required", $pointer);
            }

            return null;
        }
        $type = $col['type'];
        $bad = static fn (): HttpError => HttpError::invalidInput("$name must be " . self::expected($type), $pointer);
        $out = match ($type) {
            'integer', 'media', 'file' => match (true) {
                is_int($v) => $v,
                is_float($v) && Js::isInteger($v) && abs($v) <= 9007199254740991 => (int) $v,
                default => throw $bad(),
            },
            // A whole number stays an int, so an id from a `number` input reads back exactly.
            'real' => is_int($v) ? $v : (is_float($v) && Js::isFinite($v) ? $v : throw $bad()),
            'text', 'richText' => is_string($v) ? $v : throw $bad(),
            'email' => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : throw $bad(),
            'url' => is_string($v) && preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL) !== false ? $v : throw $bad(),
            'slug' => is_string($v) && strlen($v) <= 200 && preg_match(self::SLUG_RE, $v) ? $v : throw $bad(),
            'boolean' => is_bool($v) ? $v : throw $bad(),
            'timestamp' => self::timestamp($v) ?? throw $bad(),
            'date' => is_string($v) && self::isDate($v) ? $v : throw $bad(),
            'json' => isset($col['shape']) ? self::shaped($col['shape'], $v, $name, $pointer) : (self::jsonValue($v) ?? throw $bad()),
            'mediaList' => self::idList($v, $name, $pointer) ?? throw $bad(),
            'refList' => self::refList($v, $name, $pointer) ?? throw $bad(),
            'select' => self::selectList($col, $v, $name, $pointer) ?? throw $bad(),
            'group' => self::isObject($v) ? self::normalizeFields($col['fields'] ?? [], $v, $pointer) : throw $bad(),
            'repeater' => is_array($v) && array_is_list($v) ? self::repeater($col, $v, $name, $pointer) : throw $bad(),
            'blocks' => is_array($v) && array_is_list($v) ? self::blocks($col, $v, $name, $pointer) : throw $bad(),
            default => throw new \LogicException("Unknown column type '$type'"),
        };
        if (isset($col['enum']) && $type !== 'select' && !in_array($out, $col['enum'], true)) {
            throw HttpError::invalidInput("$name must be one of: " . implode(', ', $col['enum']), $pointer);
        }

        return $out;
    }

    /** JS form → storage form. */
    public static function store(array $col, mixed $v): int|float|string|null
    {
        if ($v === null) {
            return null;
        }
        if (self::isJsonType($col['type'])) {
            return Js::jsonStringify($v);
        }

        return is_bool($v) ? (int) $v : $v;
    }

    /**
     * Normalize an object against a field map (group items, repeater rows, block items, globals, action
     * input): unknown keys rejected, absent fields → default or null, notNull enforced.
     */
    public static function normalizeFields(array $fields, mixed $obj, string $base): array
    {
        $obj = Seam::toPhp($obj, $fields) ?? [];
        if (!self::isObject($obj)) {
            throw HttpError::invalidInput('Expected an object', $base);
        }
        foreach ($obj as $k => $_) {
            if (!isset($fields[$k])) {
                throw HttpError::invalidInput("Unknown field '$k'", $base . HttpError::seg($k));
            }
        }
        $out = [];
        foreach ($fields as $name => $f) {
            $name = (string) $name;
            $ptr = $base . HttpError::seg($name);
            if (!array_key_exists($name, $obj) || (($f['omit'] ?? false) && $obj[$name] === JsUndefined::Value)) {
                if (isset($f['default'])) {
                    $obj[$name] = self::generate($f['default'], $f);
                } elseif ($f['omit'] ?? false) {
                    // An optional field of a typed input (`limit?: number`): left out, it stays out.
                    continue;
                } elseif (($f['notNull'] ?? false) || ($f['required'] ?? false)) {
                    throw HttpError::invalidInput("$name is required", $ptr);
                } else {
                    $out[$name] = null;
                    continue;
                }
            }
            $out[$name] = self::normalize($f, $obj[$name], $name, $ptr);
        }

        return $out;
    }

    private static function repeater(array $col, array $items, string $name, string $pointer): array
    {
        $out = [];
        foreach ($items as $i => $item) {
            $out[] = self::normalizeFields($col['fields'] ?? [], self::isObject($item) ? $item : throw HttpError::invalidInput("$name items must be objects", "$pointer/$i"), "$pointer/$i");
        }

        return $out;
    }

    private static function blocks(array $col, array $items, string $name, string $pointer): array
    {
        $out = [];
        $layouts = $col['layouts'] ?? [];
        foreach ($items as $i => $item) {
            if (!self::isObject($item)) {
                throw HttpError::invalidInput("$name items must be objects", "$pointer/$i");
            }
            $layout = $item['layout'] ?? null;
            if (!is_string($layout) || !isset($layouts[$layout])) {
                throw HttpError::invalidInput('layout must be one of: ' . implode(', ', array_keys($layouts)), "$pointer/$i/layout");
            }
            unset($item['layout']);
            $out[] = ['layout' => $layout] + self::normalizeFields($layouts[$layout], $item, "$pointer/$i");
        }

        return $out;
    }

    /** @return ?list<int> */
    private static function idList(mixed $v, string $name, string $pointer): ?array
    {
        if (!is_array($v) || !array_is_list($v)) {
            return null;
        }
        $out = [];
        foreach ($v as $i => $id) {
            if (!(is_int($id) || (is_float($id) && Js::isInteger($id)))) {
                throw HttpError::invalidInput("$name items must be media ids", "$pointer/$i");
            }
            $out[] = (int) $id;
        }

        return $out;
    }

    /** @return ?list<int> a many-ref's target ids, in write order, each once */
    private static function refList(mixed $v, string $name, string $pointer): ?array
    {
        if (!is_array($v) || !array_is_list($v)) {
            return null;
        }
        $out = [];
        foreach ($v as $i => $id) {
            if (!(is_int($id) || (is_float($id) && Js::isInteger($id) && abs($id) <= 9007199254740991))) {
                throw HttpError::invalidInput("$name items must be ids", "$pointer/$i");
            }
            if (in_array((int) $id, $out, true)) {
                throw HttpError::invalidInput("$name has a duplicate id " . (int) $id, "$pointer/$i");
            }
            $out[] = (int) $id;
        }

        return $out;
    }

    /** @return ?list<string> */
    private static function selectList(array $col, mixed $v, string $name, string $pointer): ?array
    {
        if (!is_array($v) || !array_is_list($v)) {
            return null;
        }
        $enum = $col['enum'] ?? [];
        foreach ($v as $i => $item) {
            if (!is_string($item) || !in_array($item, $enum, true)) {
                throw HttpError::invalidInput("$name items must be one of: " . implode(', ', $enum), "$pointer/$i");
            }
            if (array_search($item, $v, true) !== $i) {
                throw HttpError::invalidInput("$name has a duplicate value '$item'", "$pointer/$i");
            }
        }

        return $v;
    }

    /** A typed input's structured value, checked against the shape its parameter type gives it. */
    private static function shaped(array $shape, mixed $v, string $name, string $pointer): mixed
    {
        InputShape::check($shape, $v, $name, $pointer);

        return $v;
    }

    private static function jsonValue(mixed $v): mixed
    {
        // Anything JSON can carry; Closures/objects can't come from JSON input but may come from hooks.
        return Js::jsonStringify($v) === null ? null : $v;
    }

    private static function isObject(mixed $v): bool
    {
        return $v instanceof JsObject || (is_array($v) && ($v === [] || !array_is_list($v)));
    }

    private static function expected(string $type): string
    {
        return match ($type) {
            'integer' => 'an integer',
            'real' => 'a finite number',
            'text', 'richText' => 'a string',
            'email' => 'an email address',
            'url' => 'an absolute http(s) URL',
            'slug' => 'a slug (lowercase letters, digits and single hyphens, at most 200 characters)',
            'boolean' => 'a boolean',
            'timestamp' => 'an ISO-8601 timestamp or epoch milliseconds',
            'date' => 'a date (YYYY-MM-DD)',
            'media' => 'a media id',
            'file' => 'a file id',
            'mediaList' => 'a list of media ids',
            'refList' => 'a list of ids',
            'select' => 'a list of options',
            'group' => 'an object',
            'repeater', 'blocks' => 'a list of objects',
            default => 'a JSON value',
        };
    }

    /**
     * Epoch ms, or ISO 8601: `YYYY-MM-DD` (UTC midnight, as in JS) or `YYYY-MM-DDTHH:mm[:ss[.fff]]`
     * with `Z` or `±HH:MM`. Every component must be a real calendar/clock value (no `24:00`, no
     * `02-30` rolling into March); anything else (`now`, `10/30/2026`) is rejected.
     */
    private static function timestamp(mixed $v): ?string
    {
        if (is_int($v) || (is_float($v) && Js::isInteger($v))) {
            $ms = $v;
        } elseif (is_string($v) && self::isIsoTimestamp($v)) {
            $ms = Js::date($v);
        } else {
            return null;
        }
        try {
            $iso = Js::iso($ms);
        } catch (JsError) {
            return null;
        }

        // Stored timestamps keep a four-digit year, so an input that normalizes outside 0000-9999
        // (`9999-12-31T23:59:59-00:30`, a huge epoch) would not round-trip through this codec.
        return preg_match('/^\d{4}-/', $iso) ? $iso : null;
    }

    private static function isIsoTimestamp(string $v): bool
    {
        return (bool) preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-](\d{2}):(\d{2})))?$/D', $v, $m)
            && self::isDate($m[1])
            && (int) ($m[2] ?? 0) <= 23 && (int) ($m[3] ?? 0) <= 59 && (int) ($m[4] ?? 0) <= 59
            && (int) ($m[5] ?? 0) <= 23 && (int) ($m[6] ?? 0) <= 59;
    }

    private static function isDate(string $v): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m)) {
            return false;
        }
        // Proleptic Gregorian, like JS: checkdate() would refuse year 0000.
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $leap = $y % 4 === 0 && ($y % 100 !== 0 || $y % 400 === 0);

        return $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$mo - 1];
    }

    /** Stored JSON text (written by this runtime, so valid; unreadable text decodes to null). */
    private static function json(string $v): mixed
    {
        try {
            return Js::decodeJson($v, 2147483647);
        } catch (\JsonException) {
            return null;
        }
    }

    /** Stored value → JS-facing value. */
    public static function decode(array $col, mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }

        return match ($col['type']) {
            'integer', 'media', 'file' => (int) $v,
            'real' => (float) $v,
            'boolean' => $v === 'true' || (bool) (int) $v,
            // A json() value keeps object/list handles; structured columns are
            // normalized against their fields, so plain arrays are exact there.
            'json' => ($col['system'] ?? false) ? Seam::toPhp(is_string($v) ? self::json($v) : $v) : (is_string($v) ? self::json($v) : $v),
            'mediaList', 'select', 'refList' => is_string($v) ? json_decode($v, true) : $v,
            'group' => self::fillFields($col['fields'] ?? [], Seam::toPhp(is_string($v) ? self::json($v) : $v, $col)),
            'repeater' => array_map(
                static fn ($item) => self::fillFields($col['fields'] ?? [], $item),
                (array) Seam::toPhp(is_string($v) ? self::json($v) : $v, $col),
            ),
            'blocks' => array_map(
                static fn ($item) => is_array($item) && isset($col['layouts'][$item['layout'] ?? ''])
                    ? ['layout' => $item['layout']] + self::fillFields($col['layouts'][$item['layout']], $item)
                    : $item,
                (array) Seam::toPhp(is_string($v) ? self::json($v) : $v, $col),
            ),
            default => (string) $v,
        };
    }

    /** Every sub-key present (post-backed stores may omit empty sub-fields). */
    private static function fillFields(array $fields, mixed $obj): mixed
    {
        $obj = Seam::container($obj);
        if (!is_array($obj)) {
            return $obj;
        }
        $out = [];
        foreach ($fields as $name => $f) {
            // Sub-values are already JSON-decoded: json passes through; scalars are coerced (WP's
            // get_field returns numbers/booleans as strings); nested structures recurse.
            $v = $obj[$name] ?? null;
            $out[$name] = $f['type'] === 'json' ? $v : self::decode($f, $v);
        }

        return $out;
    }

    /** Decode a full row: every column key present, in schema order. */
    public static function decodeRow(array $columns, array $raw): array
    {
        $row = [];
        foreach ($columns as $name => $col) {
            $row[$name] = self::decode($col, $raw[$name] ?? null);
        }

        return $row;
    }

    // ================================================================ rules

    /**
     * Run min/max, pattern, validate (in that order; first failure wins) on a normalized value and,
     * recursively, on its sub-fields. $ctx is the validate ctx: ['data' => row, 'user' => ?array].
     *
     * @throws HttpError invalid_input
     */
    public static function checkRules(array $col, mixed $v, string $name, string $pointer, array $ctx, ?array $dataShape = null, ?array $path = null): void
    {
        if ($v === null) {
            return;
        }
        $dataShape ??= [$name => $col];
        $path ??= [$name];
        $type = $col['type'];
        $rules = $col['rules'] ?? [];
        if (isset($rules['min']) || isset($rules['max'])) {
            [$size, $unit] = match (true) {
                in_array($type, self::STRING_TYPES, true) => [mb_strlen($v, 'UTF-8'), 'character'],
                in_array($type, self::LIST_TYPES, true) => [count($v), 'item'],
                default => [$v, null],
            };
            foreach (['min' => 'at least', 'max' => 'at most'] as $k => $words) {
                if (!isset($rules[$k]) || ($k === 'min' ? $size >= $rules[$k] : $size <= $rules[$k])) {
                    continue;
                }
                $n = $rules[$k];
                $what = $unit === null ? Js::str($n) : Js::str($n) . " $unit" . ($n == 1 ? '' : 's');
                throw HttpError::invalidInput(
                    $unit === 'item' ? "$name must have $words $what" : "$name must be $words $what",
                    $pointer,
                );
            }
        }
        if (isset($rules['pattern']) && is_string($v)) {
            $re = Js::re($rules['pattern']['source'], $rules['pattern']['flags'] ?? '');
            if (!$re->test($v)) {
                throw HttpError::invalidInput("$name must match $re", $pointer);
            }
        }
        if (isset($rules['validate'])) {
            $slot = $rules['validate'];
            if (is_string($slot)) {
                $fn = '\\TypedPressApp\\' . $slot;
                $jsCtx = Seam::ctx($ctx, $dataShape ?? [$name => $col]);
                $value = $jsCtx->p['data'];
                foreach ($path ?? [$name] as $key) { $value = Seam::container($value)[$key] ?? null; }
                $r = $fn($value, $jsCtx);
            } else {
                $r = $slot($v, $ctx);
            }
            if (is_string($r)) {
                throw HttpError::invalidInput($r, $pointer);
            }
            if ($r === false) {
                throw HttpError::invalidInput("$name is invalid", $pointer);
            }
        }
        match ($type) {
            'group' => self::checkFields($col['fields'] ?? [], $v, $pointer, $ctx, $dataShape, $path ?? [$name]),
            'repeater' => array_map(
                static fn ($item, $i) => self::checkFields($col['fields'] ?? [], $item, "$pointer/$i", $ctx, $dataShape, [...($path ?? [$name]), $i]),
                $v,
                array_keys($v),
            ),
            'blocks' => array_map(
                static fn ($item, $i) => self::checkFields($col['layouts'][$item['layout']] ?? [], $item, "$pointer/$i", $ctx, $dataShape, [...($path ?? [$name]), $i]),
                $v,
                array_keys($v),
            ),
            default => null,
        };
    }

    public static function checkFields(array $fields, array $obj, string $base, array $ctx, ?array $dataShape = null, array $path = []): void
    {
        foreach ($fields as $name => $f) {
            self::checkRules($f, $obj[$name] ?? null, (string) $name, $base . HttpError::seg($name), $ctx, $dataShape ?? $fields, [...$path, $name]);
        }
    }

    /**
     * Media ids referenced by a normalized value, with the pointer of each, for existence/accept checks.
     *
     * @return list<array{int, string, string}> [id, pointer, accept]
     */
    public static function mediaRefs(array $col, mixed $v, string $pointer): array
    {
        if ($v === null) {
            return [];
        }
        $accept = $col['accept'] ?? 'any';

        return match ($col['type']) {
            'media' => [[$v, $pointer, $accept]],
            'mediaList' => array_map(static fn ($id, $i) => [$id, "$pointer/$i", $accept], $v, array_keys($v)),
            'group' => self::fieldMediaRefs($col['fields'] ?? [], $v, $pointer),
            'repeater' => array_merge([], ...array_map(
                static fn ($item, $i) => self::fieldMediaRefs($col['fields'] ?? [], $item, "$pointer/$i"),
                $v,
                array_keys($v),
            )),
            'blocks' => array_merge([], ...array_map(
                static fn ($item, $i) => self::fieldMediaRefs($col['layouts'][$item['layout']] ?? [], $item, "$pointer/$i"),
                $v,
                array_keys($v),
            )),
            default => [],
        };
    }

    private static function fieldMediaRefs(array $fields, array $obj, string $base): array
    {
        $out = [];
        foreach ($fields as $name => $f) {
            array_push($out, ...self::mediaRefs($f, $obj[$name] ?? null, $base . HttpError::seg($name)));
        }

        return $out;
    }

    /**
     * Validate an action input object: unknown keys rejected, defaults applied, values coerced,
     * rules run (validate ctx.data = the input). Returns the JS form.
     */
    public static function validateInput(array $columns, mixed $input, string $base = '', ?array $user = null): array
    {
        if ($input === null) {
            $input = [];
        }
        if (!self::isObject($input)) {
            throw HttpError::invalidInput('Input must be a JSON object', $base);
        }
        $out = self::normalizeFields($columns, $input, $base);
        self::checkFields($columns, $out, $base, ['data' => $out, 'user' => $user]);

        return $out;
    }

    public static function roleRank(string $role): int
    {
        $i = array_search($role, self::ROLES, true);

        return $i === false ? -1 : $i;
    }
}
