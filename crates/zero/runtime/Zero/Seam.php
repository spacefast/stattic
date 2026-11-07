<?php

declare(strict_types=1);

namespace Zero;

/** Owns conversions between compiled handles, schema-shaped runtime arrays, and wire values. */
final class Seam
{
    public static function container(mixed $value): mixed
    {
        return match (true) {
            $value instanceof JsObject => $value->p,
            $value instanceof JsArray => $value->a,
            $value instanceof \stdClass => get_object_vars($value),
            default => $value,
        };
    }

    public static function toPhp(mixed $value, ?array $shape = null): mixed
    {
        self::acyclic($value, new \SplObjectStorage(), new \SplObjectStorage());
        return self::runtimeValue($value, $shape);
    }

    private static function acyclic(mixed $value, \SplObjectStorage $stack, \SplObjectStorage $done): void
    {
        if ($value instanceof JsObject || $value instanceof JsArray) {
            if ($stack->offsetExists($value)) { throw HttpError::invalidInput('Value contains a cycle'); }
            if ($done->offsetExists($value)) { return; }
            $stack[$value] = true;
            foreach (self::container($value) as $item) { self::acyclic($item, $stack, $done); }
            unset($stack[$value]);
            $done[$value] = true;
        } elseif (is_array($value)) {
            foreach ($value as $item) { self::acyclic($item, $stack, $done); }
        }
    }

    private static function runtimeValue(mixed $value, ?array $shape): mixed
    {
        if (($shape['type'] ?? null) === 'json') { return ($shape['system'] ?? false) ? self::runtimeValue($value, null) : Js::detach(self::jsonJs($value)); }
        $type = is_string($shape['type'] ?? null) ? $shape['type'] : null;
        if ($type === 'query') { return self::query($shape['collection'], $value); }
        if ($type === 'sync') { return self::sync($shape['collection'], $value); }
        if ($shape !== null && ($type === null || $type === 'group') && $value instanceof JsArray) { return $value; }
        if (in_array($type, ['repeater', 'blocks', 'mediaList', 'select'], true) && ($value instanceof JsObject || $value instanceof \stdClass)) { return self::jsonJs($value); }
        $value = self::container($value);
        if (!is_array($value)) { return $value; }
        if ($shape === null) {
            $out = [];
            foreach (Js::ownKeys($value) as $key) { $out[$key] = self::runtimeValue($value[$key], null); }
            return $out;
        }
        if ($type === null) {
            foreach ($value as $key => $item) { $value[$key] = self::runtimeValue($item, $shape[$key] ?? null); }
            return $value;
        }
        return match ($type) {
            'group' => self::runtimeValue($value, $shape['fields'] ?? []),
            'repeater' => array_map(static fn ($item) => self::runtimeValue($item, $shape['fields'] ?? []), $value),
            'blocks' => array_map(static function ($item) use ($shape) {
                $layout = self::container($item)['layout'] ?? null;
                return self::runtimeValue($item, is_string($layout) ? ($shape['layouts'][$layout] ?? null) : null);
            }, $value),
            default => self::runtimeValue($value, null),
        };
    }

    /** Only a json() shape can infer native PHP JSON containers; free empty arrays have no shape. */
    private static function jsonJs(mixed $value): mixed
    {
        if ($value instanceof JsObject || $value instanceof JsArray) { return $value; }
        if ($value instanceof \stdClass) {
            $out = new JsObject();
            foreach (get_object_vars($value) as $key => $item) { $out->p[$key] = self::jsonJs($item); }
            return $out;
        }
        if (is_array($value)) {
            if (array_is_list($value)) { return new JsArray(array_map(self::jsonJs(...), $value)); }
            $out = new JsObject();
            foreach (Js::ownKeys($value) as $key) { $out->p[$key] = self::jsonJs($value[$key]); }
            return $out;
        }
        return $value;
    }

    public static function toJs(mixed $value, ?array $shape = null): mixed
    {
        return self::jsValue($value, $shape, false);
    }

    /** Input can still contain invalid or unknown fields; retain them for normal validation. */
    public static function inputToJs(mixed $value, ?array $shape): mixed
    {
        return self::jsValue($value, $shape, true);
    }

    private static function jsValue(mixed $value, ?array $shape, bool $input): mixed
    {
        if ($value === null) { return null; }
        $type = is_string($shape['type'] ?? null) ? $shape['type'] : null;
        if ($input && $type === 'sync') {
            $record = ['fields' => Db::instance()->schema()->collection($shape['collection'])['columns']];
            $shape = ['record' => $record, 'base' => $record, 'local' => $record, 'remote' => $record,
                'records' => ['type' => 'repeater', 'fields' => $shape]];
            $type = null;
        }
        if ($type === 'json' || $value instanceof JsObject || $value instanceof JsArray) { return Js::detach(self::jsonJs($value)); }
        if ($type === 'media' && is_array($value)) { return self::media($value); }
        if (!is_array($value) && !$value instanceof \stdClass) { return $value; }
        $value = self::container($value);
        if ($shape === null) {
            if ($input) { return Js::detach(self::jsonJs($value)); }
            if ($value === []) { throw new \LogicException('An empty runtime array needs a shape'); }
            throw new \LogicException('A runtime container needs a shape');
        }
        if ($type === 'row') { return self::rowToJs($shape['collection'], $value); }
        if ($type === 'rows') { return new JsArray(array_map(static fn ($row) => self::rowToJs($shape['collection'], $row), $value)); }
        if ($type === 'group') { return self::jsValue($value, $shape['fields'] ?? [], $input); }
        if (in_array($type, ['repeater', 'blocks', 'mediaList', 'select'], true)) {
            if ($input && !array_is_list($value)) { return Js::detach(self::jsonJs($value)); }
            $out = new JsArray();
            foreach ($value as $item) {
                $itemShape = match ($type) {
                    'repeater' => $shape['fields'] ?? [],
                    'blocks' => ['layout' => ['type' => 'text']] + (is_string($layout = self::container($item)['layout'] ?? null) ? ($shape['layouts'][$layout] ?? []) : []),
                    'mediaList' => ['type' => 'media'],
                    default => null,
                };
                $out->a[] = self::jsValue($item, $itemShape, $input);
            }
            return $out;
        }
        if ($input && $type !== null && $type !== 'record') { return Js::detach(self::jsonJs($value)); }
        $out = new JsObject();
        foreach (Js::ownKeys($value) as $key) {
            $out->p[$key] = self::jsValue($value[$key], $type === 'record' ? ($shape['value'] ?? null) : ($shape[$key] ?? null), $input);
        }
        return $out;
    }

    private static function media(array $row): JsObject
    {
        $fields = [];
        foreach ($row as $key => $_) { $fields[$key] = ['type' => 'json']; }
        $fields['sizes'] = ['type' => 'record', 'value' => ['type' => 'json']];
        $fields['focal'] = ['type' => 'record'];
        return self::toJs($row, $fields);
    }

    public static function rowToJs(string $collection, array $row, array $with = []): JsObject
    {
        $schema = Db::instance()->schema();
        if (!$schema->hasCollection($collection)) { return self::media($row); }
        $spec = $schema->collection($collection);
        $fields = $spec['columns'];
        // Compiled app rows follow the authored model. Internal PHP stores keep
        // hidden metadata (for example translationGroup) for their own work.
        foreach ($fields as $name => $column) {
            if ($column['hidden'] ?? false) { unset($row[$name]); }
        }
        foreach ($spec['relations'] ?? [] as $name => $relation) {
            $fields[$name] = ['type' => $relation['kind'] === 'many' ? 'rows' : 'row', 'collection' => $relation['collection']];
        }
        if ($spec['localized'] ?? false) { $fields['translations'] = ['type' => 'record']; }
        return self::toJs($row, $fields);
    }

    public static function rowToPhp(string $collection, JsObject|array $row): array
    {
        return self::toPhp($row, Db::instance()->schema()->collection($collection)['columns']);
    }

    public static function ctx(array $ctx, ?array $dataShape = null): JsObject
    {
        $shape = ['user' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'text'], 'role' => ['type' => 'text']]];
        if (array_key_exists('data', $ctx)) { $shape['data'] = $dataShape; }
        return self::inputToJs($ctx, $shape);
    }

    public static function generated(array $col, mixed $value): mixed { return self::toPhp($value, $col); }
    public static function toWire(mixed $value): mixed
    {
        $tree = Js::jsonTree($value);
        // MCP consumes object operation envelopes as arrays before it invokes its JSON encoder.
        if ($tree instanceof \stdClass) {
            $members = get_object_vars($tree);
            if ($members !== [] && !array_is_list($members)) { return $members; }
        }
        return $tree;
    }

    public static function findMany(string $collection, mixed $opts = []): JsArray
    {
        return new JsArray(array_map(static fn ($row) => self::rowToJs($collection, $row), Db::instance()->findMany($collection, $opts)));
    }
    public static function findFirst(string $collection, mixed $opts = []): ?JsObject
    {
        $row = Db::instance()->findFirst($collection, $opts);
        return $row === null ? null : self::rowToJs($collection, $row);
    }
    public static function insert(string $collection, mixed $values, bool $returning = false): ?JsArray
    {
        $rows = Db::instance()->insert($collection, $values, $returning);
        return $rows === null ? null : new JsArray(array_map(static fn ($row) => self::rowToJs($collection, $row), $rows));
    }
    public static function update(string $collection, mixed $set, mixed $where, bool $returning = false): ?JsArray
    {
        $rows = Db::instance()->update($collection, $set, $where, $returning);
        return $rows === null ? null : new JsArray(array_map(static fn ($row) => self::rowToJs($collection, $row), $rows));
    }
    public static function delete(string $collection, mixed $where, bool $returning = false): ?JsArray
    {
        $rows = Db::instance()->delete($collection, $where, $returning);
        return $rows === null ? null : new JsArray(array_map(static fn ($row) => self::rowToJs($collection, $row), $rows));
    }
    public static function globalGet(string $name): JsObject
    {
        $db = Db::instance();
        return self::toJs($db->globalGet($name), $db->schema()->global($name)['fields']);
    }
    public static function globalUpdate(string $name, mixed $patch): JsObject
    {
        $db = Db::instance();
        return self::toJs($db->globalUpdate($name, $patch), $db->schema()->global($name)['fields']);
    }
    public static function enqueue(string $name, mixed $input, mixed $opts = null): JsObject
    {
        $spec = Jobs\Jobs::spec($name);
        $result = Jobs\Jobs::enqueue($name, self::toPhp($input, $spec['input'] ?? []), self::toPhp($opts));
        return self::toJs($result, ['id' => ['type' => 'text']]);
    }

    /** Sync grammar unwraps only envelopes; field values follow their column shape. */
    public static function sync(string $collection, mixed $input): array
    {
        $input = self::container($input);
        if (!is_array($input)) { throw HttpError::invalidInput('Body must be an object'); }
        if (array_key_exists('records', $input)) {
            $items = self::container($input['records']);
            if (is_array($items)) {
                $input['records'] = array_map(static fn ($item) => self::sync($collection, $item), $items);
            }
        }
        foreach (['record', 'base', 'local', 'remote'] as $key) {
            if (!array_key_exists($key, $input) || $input[$key] === null) { continue; }
            $record = self::container($input[$key]);
            if (is_array($record)) {
                if (array_key_exists('fields', $record)) {
                    $record['fields'] = self::toPhp($record['fields'], Db::instance()->schema()->collection($collection)['columns']);
                }
                $input[$key] = $record;
            }
        }
        return $input;
    }

    /** Query options as plain arrays, except a json() column's filter operands (`{ meta: { eq: {} } }`). */
    public static function query(string $collection, mixed $opts, ?Schema $schema = null): mixed
    {
        $schema ??= Db::instance()->schema();
        $opts = self::container($opts);
        if (!is_array($opts)) {
            return self::toPhp($opts);
        }
        $relations = $schema->hasCollection($collection) ? ($schema->collection($collection)['relations'] ?? []) : [];
        foreach ($opts as $k => $v) {
            $opts[$k] = match ((string) $k) {
                'where' => self::filter($collection, $v, $schema),
                // Nested relation options are queries on the related collection.
                'with' => is_array($v) || $v instanceof JsObject ? self::with($relations, $v, $schema) : self::toPhp($v),
                default => self::toPhp($v),
            };
        }

        return $opts;
    }

    private static function with(array $relations, array|JsObject $with, Schema $schema): array
    {
        $with = self::container($with);
        foreach ($with as $name => $o) {
            $with[$name] = isset($relations[$name]) && (is_array($o) || $o instanceof JsObject)
                ? self::query($relations[$name]['collection'], $o, $schema)
                : self::toPhp($o);
        }

        return $with;
    }

    /**
     * A filter as plain arrays, walking OR/AND/NOT and relations, but leaving a json() column's
     * operands as given: `{}` and `[]` are different values to compare against.
     */
    public static function filter(string $collection, mixed $where, ?Schema $schema = null): mixed
    {
        $schema ??= Db::instance()->schema();
        $where = self::container($where);
        if (!is_array($where) || !$schema->hasCollection($collection)) {
            return self::toPhp($where);
        }
        $spec = $schema->collection($collection);
        foreach ($where as $k => $v) {
            $k = (string) $k;
            $where[$k] = match (true) {
                $k === 'OR' || $k === 'AND' => is_array(self::container($v)) ? array_map(fn ($f) => self::filter($collection, $f, $schema), self::container($v)) : self::toPhp($v),
                $k === 'NOT' => self::filter($collection, $v, $schema),
                isset($spec['columns'][$k]) => self::plainOperand($spec['columns'][$k], $v),
                isset($spec['relations'][$k]) => self::filter($spec['relations'][$k]['collection'], $v, $schema),
                default => self::toPhp($v),
            };
        }

        return $where;
    }

    /**
     * A field filter: an operator object (`{ eq: v, OR: [...] }`) maps its operands by the column's
     * schema; a bare value is itself the operand. Each operand keeps `{}` only inside json() values.
     */
    private static function plainOperand(array $col, mixed $v): mixed
    {
        $ops = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'notIn', 'like', 'notLike', 'ilike', 'notIlike', 'isNull', 'isNotNull', 'has', 'NOT', 'OR', 'AND'];
        $original = $v;
        $v = self::container($v);
        $isOps = is_array($v) && $v !== [] && !array_is_list($v) && array_diff(array_map('strval', array_keys($v)), $ops) === [];
        if (!$isOps) {
            return self::toPhp($original, $col);
        }
        foreach ($v as $op => $arg) {
            $v[$op] = match ((string) $op) {
                'in', 'notIn' => is_array(self::container($arg)) ? array_map(static fn ($x) => self::toPhp($x, $col), self::container($arg)) : self::toPhp($arg),
                'OR', 'AND' => is_array(self::container($arg)) ? array_map(static fn ($x) => self::plainOperand($col, $x), self::container($arg)) : self::toPhp($arg),
                'NOT' => self::plainOperand($col, $arg),
                'isNull', 'isNotNull', 'like', 'notLike', 'ilike', 'notIlike', 'has' => self::toPhp($arg),
                default => self::toPhp($arg, $col),
            };
        }

        return $v;
    }

}
