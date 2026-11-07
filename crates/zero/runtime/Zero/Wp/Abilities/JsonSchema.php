<?php

declare(strict_types=1);

namespace Zero\Wp\Abilities;

use Zero\Schema;

/**
 * JSON Schemas for abilities, derived from schema.php columns (§7.1). Every node carries a `type`
 * (core's validator requires one) and uses only keywords core prepares for clients. Input schemas
 * describe what the pipeline accepts; Zero's pipeline is the validator (Register::validate). They
 * never say `additionalProperties: false`: core's run endpoint sanitizes input by its schema and
 * would silently drop unknown keys that REST rejects with a 422.
 */
final class JsonSchema
{
    private const ANY = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];
    private const STRINGS = ['text', 'richText', 'email', 'url', 'slug', 'timestamp', 'date'];
    private const NUMBERS = ['integer', 'real', 'media', 'file'];
    /** A filter region whose values core leaves untyped in a query string. */
    public const UNTYPED = ['type' => 'object'];

    /** A value of one column. $output widens media references, which `with` may populate. */
    public static function column(array $col, bool $output = false): array
    {
        $type = $col['type'];
        $s = match ($type) {
            'integer' => ['type' => 'integer'],
            'real' => ['type' => 'number'],
            'text', 'richText' => ['type' => 'string'],
            // Input carries no `format`: core's run endpoint sanitizes by format (sanitize_url on
            // `uri`), which would rewrite the value Zero then stores; Zero validates instead.
            'email' => ['type' => 'string'],
            'url' => $output ? ['type' => 'string', 'format' => 'uri'] : ['type' => 'string'],
            'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$', 'maxLength' => 200],
            'boolean' => ['type' => 'boolean'],
            // REST also accepts epoch milliseconds and any date string Zero parses.
            'timestamp' => $output ? ['type' => 'string', 'format' => 'date-time'] : ['type' => ['string', 'integer']],
            'date' => $output ? ['type' => 'string', 'format' => 'date'] : ['type' => 'string'],
            'json' => ['type' => self::ANY],
            'media', 'file' => $output ? ['type' => ['integer', 'object']] : ['type' => 'integer'],
            'mediaList' => ['type' => 'array', 'items' => $output ? ['type' => ['integer', 'object']] : ['type' => 'integer']],
            'select' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_values($col['enum'] ?? [])], 'uniqueItems' => true],
            'group' => self::object($col['fields'] ?? [], $output, false),
            'repeater' => ['type' => 'array', 'items' => self::object($col['fields'] ?? [], $output, !$output)],
            'blocks' => ['type' => 'array', 'items' => self::blocks($col['layouts'] ?? [], $output)],
            default => throw new \LogicException("Unknown column type '$type'"),
        };
        if (isset($col['enum']) && $type !== 'select') {
            $s['enum'] = array_values($col['enum']);
        }
        $rules = $col['rules'] ?? [];
        $bounds = match (true) {
            in_array($type, ['text', 'richText', 'email', 'url', 'slug'], true) => ['minLength', 'maxLength'],
            in_array($type, ['mediaList', 'select', 'repeater', 'blocks'], true) => ['minItems', 'maxItems'],
            in_array($type, ['integer', 'real'], true) => ['minimum', 'maximum'],
            default => null,
        };
        if ($bounds !== null) {
            foreach (['min' => $bounds[0], 'max' => $bounds[1]] as $rule => $keyword) {
                if (is_int($rules[$rule] ?? null) || is_float($rules[$rule] ?? null)) {
                    $s[$keyword] = $rules[$rule];
                }
            }
        }
        // `pattern` rules are JS regular expressions; core would run them as PCRE, so they stay Zero's.

        return ($col['notNull'] ?? false) ? $s : self::nullable($s);
    }

    /** A collection row as responses return it (hidden columns never leave the runtime). */
    public static function row(array $spec): array
    {
        $props = [];
        foreach ($spec['columns'] as $name => $col) {
            if (!($col['hidden'] ?? false)) {
                $props[(string) $name] = self::column($col, true);
            }
        }

        return ['type' => 'object', 'properties' => $props];
    }

    /** Create input: writable columns plus virtual inputs; required = what the pipeline requires before hooks. */
    public static function insert(array $spec, array $virtual = []): array
    {
        $props = [];
        $required = [];
        $fills = $spec['beforeCreateFills'] ?? [];
        foreach (self::writable($spec, true) as $name => $col) {
            $props[$name] = self::column($col);
            if (Schema::isRequired($col) && !in_array($name, $fills, true) && ($spec['slug'] ?? null) !== $name) {
                $required[] = $name;
            }
        }

        return self::input($props + $virtual, $required);
    }

    /** Update patch: every writable column, all optional. */
    public static function patch(array $spec): array
    {
        $props = [];
        foreach (self::writable($spec, false) as $name => $col) {
            $props[$name] = self::column($col);
        }

        return ['type' => 'object', 'properties' => $props];
    }

    /** Field objects: action input, global values, group/repeater sub-fields. */
    public static function fields(array $fields, bool $output = false): array
    {
        return self::object($fields, $output, !$output);
    }

    /** A top-level input object; `default` lets core and the MCP adapter accept an omitted input. */
    public static function input(array $props, array $required = []): array
    {
        // An empty `properties` is left out: core indexes it as an array, and [] would publish as a list.
        $s = ['type' => 'object', 'default' => []];
        if ($props !== []) {
            $s['properties'] = $props;
        }
        if ($required !== []) {
            $s['required'] = $required;
        }

        return $s;
    }

    /**
     * `where`: per column a value or an operator object (§1.3 grammar), AND/OR/NOT one level down,
     * so core types GET query-string values there. Relation filters and deeper groups are untyped
     * (`{type: object}`): exact as JSON (POST, MCP, or `where` as a JSON string like REST's
     * `?where=`), refused over a GET query string (Invoke::untyped), where core leaves strings.
     */
    public static function where(array $spec): array
    {
        return self::filterObject($spec['columns'], 1, array_keys($spec['relations'] ?? []));
    }

    /** The media library's filterable columns (§4.6). */
    public static function mediaWhere(): array
    {
        return self::filterObject([
            'id' => ['type' => 'integer'], 'filename' => ['type' => 'text'], 'mime' => ['type' => 'text'],
            'alt' => ['type' => 'text'], 'createdAt' => ['type' => 'timestamp'],
        ], 1);
    }

    private static function filterObject(array $columns, int $depth, array $relations = []): array
    {
        $props = [];
        foreach ($columns as $name => $col) {
            if (!($col['hidden'] ?? false) && ($f = self::filter($col)) !== null) {
                $props[(string) $name] = $f;
            }
        }
        foreach ($relations as $name) {
            $props[(string) $name] = self::UNTYPED;
        }
        $nested = $depth > 0 ? self::filterObject($columns, $depth - 1, $relations) : self::UNTYPED;
        $props['AND'] = ['type' => 'array', 'items' => $nested];
        $props['OR'] = ['type' => 'array', 'items' => $nested];
        $props['NOT'] = $nested;

        return ['type' => $depth > 0 ? ['object', 'string'] : 'object', 'properties' => $props];
    }

    /** `orderBy`: `{ column: "asc" | "desc" }` in priority order, or REST's `"title,-id"`. */
    public static function orderBy(array $columns): array
    {
        $props = [];
        foreach ($columns as $name) {
            $props[$name] = ['type' => 'string', 'enum' => ['asc', 'desc']];
        }

        return ['type' => ['object', 'string'], 'properties' => $props];
    }

    /** `with`: relations (true, or nested query options) and media columns to include; may be a JSON string. */
    public static function with(array $spec): array
    {
        $props = [];
        foreach (array_keys($spec['relations'] ?? []) as $name) {
            $props[(string) $name] = ['type' => ['boolean', 'object']];
        }
        foreach ($spec['columns'] as $name => $col) {
            if (in_array($col['type'], ['media', 'mediaList'], true)) {
                $props[(string) $name] = ['type' => 'boolean', 'enum' => [true]];
            }
        }

        return ['type' => ['object', 'string'], 'properties' => $props];
    }

    /** The built-in media row (§4.6); feature lanes may add keys. */
    public static function media(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'mime' => ['type' => 'string'],
            'size' => ['type' => 'integer'],
            'width' => ['type' => ['integer', 'null']],
            'height' => ['type' => ['integer', 'null']],
            'alt' => ['type' => ['string', 'null']],
            'filename' => ['type' => 'string'],
            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
        ]];
    }

    public static function nullable(array $s): array
    {
        $types = (array) $s['type'];
        if (!in_array('null', $types, true)) {
            $types[] = 'null';
        }
        $s['type'] = $types;
        if (isset($s['enum'])) {
            $s['enum'][] = null;
        }

        return $s;
    }

    /** @return array<string, array> */
    private static function writable(array $spec, bool $create): array
    {
        $out = [];
        foreach ($spec['columns'] as $name => $col) {
            $name = (string) $name;
            if (($col['hidden'] ?? false) || ($col['derived'] ?? false) || ($col['autoIncrement'] ?? false)
                || (!$create && ($name === $spec['primaryKey'] || $name === 'uid' && ($col['system'] ?? false)))) {
                continue;
            }
            $out[$name] = $col;
        }

        return $out;
    }

    private static function object(array $fields, bool $output, bool $required): array
    {
        $props = [];
        $req = [];
        foreach ($fields as $name => $col) {
            $props[(string) $name] = self::column($col, $output);
            if ($required && Schema::isRequired($col)) {
                $req[] = (string) $name;
            }
        }
        $s = ['type' => 'object'];
        if ($props !== []) {
            $s['properties'] = $props;
        }
        if ($req !== []) {
            $s['required'] = $req;
        }

        return $s;
    }

    private static function blocks(array $layouts, bool $output): array
    {
        $variants = [];
        foreach ($layouts as $layout => $fields) {
            $v = self::object($fields, $output, !$output);
            $v['properties'] = ['layout' => ['type' => 'string', 'enum' => [(string) $layout]]] + ($v['properties'] ?? []);
            $v['required'] = array_values(array_unique(['layout', ...($v['required'] ?? [])]));
            $variants[] = $v;
        }

        return $variants === [] ? ['type' => 'object'] : ['type' => 'object', 'oneOf' => $variants];
    }

    /** Column-level NOT/OR/AND (`{ id: { NOT: { in: [1] } } }`): untyped regions. */
    private static function logic(): array
    {
        return ['NOT' => self::UNTYPED, 'OR' => ['type' => 'array', 'items' => self::UNTYPED], 'AND' => ['type' => 'array', 'items' => self::UNTYPED]];
    }

    /** Value-or-operators for one column; null for kinds that only compare whole values. */
    private static function filter(array $col): ?array
    {
        $type = $col['type'];
        if (in_array($type, ['mediaList', 'select'], true)) {
            $item = $type === 'select' ? ['type' => 'string'] : ['type' => 'integer'];

            return ['type' => ['array', 'object', 'null'], 'items' => $item, 'properties' => [
                'has' => $item,
                'eq' => ['type' => ['array', 'null'], 'items' => $item],
                'ne' => ['type' => ['array', 'null'], 'items' => $item],
                'isNull' => ['type' => 'boolean'],
                'isNotNull' => ['type' => 'boolean'],
            ] + self::logic()];
        }
        $scalar = match (true) {
            in_array($type, self::STRINGS, true) => 'string',
            $type === 'real' => 'number',
            in_array($type, self::NUMBERS, true) => 'integer',
            $type === 'boolean' => 'boolean',
            default => null,
        };
        if ($scalar === null) {
            return null;
        }
        $value = ['type' => [$scalar, 'null']];
        $ops = ['eq' => $value, 'ne' => $value, 'isNull' => ['type' => 'boolean'], 'isNotNull' => ['type' => 'boolean']] + self::logic();
        if ($scalar !== 'boolean') {
            foreach (['gt', 'gte', 'lt', 'lte'] as $op) {
                $ops[$op] = ['type' => $scalar];
            }
            $ops['in'] = ['type' => 'array', 'items' => ['type' => $scalar]];
            $ops['notIn'] = ['type' => 'array', 'items' => ['type' => $scalar]];
        }
        if ($scalar === 'string') {
            foreach (['like', 'notLike', 'ilike', 'notIlike'] as $op) {
                $ops[$op] = ['type' => 'string'];
            }
        }

        return ['type' => [$scalar, 'object', 'null'], 'properties' => $ops];
    }
}
