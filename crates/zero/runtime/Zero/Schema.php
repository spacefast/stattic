<?php

declare(strict_types=1);

namespace Zero;

/**
 * Read-only view of the generated schema.php (version 3, phase-2 §3.1) plus the value codecs shared
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
    private const JSON_TYPES = ['json', 'mediaList', 'select', 'group', 'repeater', 'blocks'];
    private const STRING_TYPES = ['text', 'email', 'url', 'slug', 'richText'];
    private const LIST_TYPES = ['mediaList', 'select', 'repeater', 'blocks'];
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
        if (($raw['version'] ?? null) !== 3) {
            throw new \RuntimeException('schema.php version 3 required; rebuild with this Zero version');
        }

        if (($raw['runtime'] ?? null) !== self::SERIES) {
            $series = $raw['runtime'] ?? '(missing)';
            throw new \RuntimeException("schema.php built for runtime $series; this runtime is " . self::SERIES . '; rebuild with this Zero version');
        }

        return new self($raw);
    }

    public function version(): int
    {
        return $this->raw['version'] ?? 3;
    }

    public function runtime(): ?string
    {
        return $this->raw['runtime'] ?? null;
    }

    public function name(): string
    {
        return (string) ($this->raw['name'] ?? 'app');
    }

    /** @return array<string, array> */
    public function collections(): array
    {
        return $this->raw['collections'] ?? [];
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

    public function auth(): array
    {
        return $this->raw['auth'] ?? ['collection' => null, 'guests' => true, 'accessTtl' => 300, 'refreshTtl' => 2592000];
    }

    /** @return array<string, array{method: string, path: string, handler: string, access: array, signature: ?array}> */
    public function routes(): array
    {
        return $this->raw['routes'] ?? [];
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
        return isset($this->raw['collections'][$name]);
    }

    public function collection(string $name): array
    {
        return $this->raw['collections'][$name]
            ?? throw HttpError::notFound("Unknown collection '$name'");
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
        foreach ($this->collections() as $name => $c) {
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
                'title' => $c['title'] ?? null,
                'content' => $c['content'] ?? null,
                'slug' => $c['slug'] ?? null,
                'primaryKey' => $c['primaryKey'],
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
            ];
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

        return Extensions::describe([
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

    // ================================================================ generators

    /** True when a create must receive this column (no default, not generated). */
    public static function isRequired(array $col): bool
    {
        return ($col['notNull'] ?? false) && !isset($col['default']) && !($col['autoIncrement'] ?? false);
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
            $fn = is_string($slot) ? '\\ZeroApp\\' . $slot : $slot;

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
            'real' => (is_int($v) || is_float($v)) && Js::isFinite($v) ? (float) $v : throw $bad(),
            'text', 'richText' => is_string($v) ? $v : throw $bad(),
            'email' => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : throw $bad(),
            'url' => is_string($v) && preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL) !== false ? $v : throw $bad(),
            'slug' => is_string($v) && strlen($v) <= 200 && preg_match(self::SLUG_RE, $v) ? $v : throw $bad(),
            'boolean' => is_bool($v) ? $v : throw $bad(),
            'timestamp' => self::timestamp($v) ?? throw $bad(),
            'date' => is_string($v) && self::isDate($v) ? $v : throw $bad(),
            'json' => self::jsonValue($v) ?? throw $bad(),
            'mediaList' => self::idList($v, $name, $pointer) ?? throw $bad(),
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
            if (!array_key_exists($name, $obj)) {
                if (isset($f['default'])) {
                    $obj[$name] = self::generate($f['default'], $f);
                } elseif ($f['notNull'] ?? false) {
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
            'mediaList', 'select' => is_string($v) ? json_decode($v, true) : $v,
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
                $fn = '\\ZeroApp\\' . $slot;
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
