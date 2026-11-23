<?php

declare(strict_types=1);

namespace TypedPress;

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
        if ($value instanceof JsDate && ($type === 'timestamp' || $type === 'date')) {
            $iso = Js::iso($value->milliseconds);
            return $type === 'date' ? substr($iso, 0, 10) : $iso;
        }
        if ($type === 'query') { return self::query($shape['collection'], $value); }
        if ($type === 'sync') { return self::sync($shape['collection'], $value); }
        if ($shape !== null && ($type === null || $type === 'group') && $value instanceof JsArray) { return $value; }
        if (in_array($type, ['repeater', 'blocks', 'mediaList', 'select', 'refList'], true) && ($value instanceof JsObject || $value instanceof \stdClass)) { return self::jsonJs($value); }
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
        // A populated file({ bucket }) column is a FileRef: plain JSON (id, bucket, url, ...).
        if ($type === 'file' && (is_array($value) || $value instanceof \stdClass)) { return Js::detach(self::jsonJs($value)); }
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
        if (in_array($type, ['repeater', 'blocks', 'mediaList', 'select', 'refList'], true)) {
            if ($input && !array_is_list($value)) { return Js::detach(self::jsonJs($value)); }
            $out = new JsArray();
            foreach ($value as $item) {
                $itemShape = match ($type) {
                    'repeater' => $shape['fields'] ?? [],
                    'blocks' => ['layout' => ['type' => 'text']] + (is_string($layout = self::container($item)['layout'] ?? null) ? ($shape['layouts'][$layout] ?? []) : []),
                    'mediaList' => ['type' => 'media'],
                    'refList' => ['type' => 'integer'],
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
            // A `ref()` field is its id (a many-ref its ids) until `with` loads the row(s) in its place.
            $loaded = is_array($row[$name] ?? null) && (($fields[$name]['type'] ?? null) !== 'refList' || is_array($row[$name][0] ?? null));
            if (isset($fields[$name]) && !$loaded) { continue; }
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

    public static function generated(array $col, mixed $value): mixed { return self::toPhp($value === JsUndefined::Value ? null : $value, $col); }
    public static function toWire(mixed $value): mixed
    {
        $tree = Js::jsonTree(JsBinary::refuseWire($value, 'this operation'));
        // MCP consumes object operation envelopes as arrays before it invokes its JSON encoder.
        if ($tree instanceof \stdClass) {
            $members = get_object_vars($tree);
            if ($members !== [] && !array_is_list($members)) { return $members; }
        }
        return $tree;
    }

    /** Who compiled reads run as: null (trusted) unless a caller bound a visitor with asViewer(). */
    private static ?Viewer $viewer = null;

    /** The viewer compiled reads run as right now; null is trusted (spec §3). */
    public static function viewer(): ?Viewer
    {
        return self::$viewer;
    }

    /**
     * Runs $fn with compiled reads bound to $viewer (null: trusted), then restores the viewer that
     * was bound before, also when $fn throws. A page render binds the visitor here, so drafts and
     * hidden fields never reach its HTML. Async bodies, promise reactions and timers keep the viewer
     * bound where they were started or registered (JsJobs::async, JsPromise::then, Timers::set).
     * Only reads are bound: writes from code a render calls stay trusted.
     */
    public static function asViewer(?Viewer $viewer, callable $fn): mixed
    {
        $previous = self::$viewer;
        $fiber = \Fiber::getCurrent();
        self::$viewer = $viewer;
        try {
            return $fn();
        } finally {
            // A suspended fiber destroyed later runs this from elsewhere; it must not reset the
            // viewer of whatever is running then.
            if (\Fiber::getCurrent() === $fiber) {
                self::$viewer = $previous;
            }
        }
    }

    public static function findMany(string $collection, mixed $opts = []): JsArray
    {
        return new JsArray(array_map(static fn ($row) => self::rowToJs($collection, $row), Db::instance()->findMany($collection, $opts, self::$viewer)));
    }
    public static function findFirst(string $collection, mixed $opts = []): ?JsObject
    {
        $row = Db::instance()->findFirst($collection, $opts, self::$viewer);
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
    /**
     * The kind query API (spec §3): `posts.list/paginate/get/count/create/update/delete` from
     * `"typedpress:db"`, as the visitor the request runs as (access rules, field masks, draft visibility),
     * or trusted for `posts.system.*` and where no visitor is bound (jobs, signed webhooks).
     */
    private static function as(bool $trusted): ?Viewer
    {
        return $trusted ? null : (self::$viewer ?? self::$visitor);
    }

    /** Who the kind API runs as outside a render: the caller of an action or route; null is the system. */
    private static ?Viewer $visitor = null;

    /**
     * Runs $fn with the kind API bound to $visitor (an action's or a route's caller; `new Viewer(null)`
     * is an anonymous one), then restores the binding before. Unlike asViewer(), `db.*` stays trusted.
     */
    public static function asVisitor(?Viewer $visitor, callable $fn): mixed
    {
        $previous = self::$visitor;
        self::$visitor = $visitor;
        try {
            return $fn();
        } finally {
            self::$visitor = $previous;
        }
    }

    public static function kindList(string $collection, mixed $opts, bool $trusted): JsArray
    {
        $viewer = self::as($trusted);
        $opts = self::query($collection, $opts);

        return new JsArray(array_map(static fn ($row) => self::rowToJs($collection, $row), Db::instance()->findMany($collection, $opts, $viewer)));
    }

    /** `{ rows, cursor }`: `limit` rows (20 by default, at most 100) after `cursor`; `cursor` is null on the last page. */
    public static function kindPaginate(string $collection, mixed $opts, bool $trusted): JsObject
    {
        $viewer = self::as($trusted);
        $opts = self::query($collection, $opts);
        $limit = $opts['limit'] ?? 20;
        if (!is_int($limit) || $limit < 1) {
            throw HttpError::invalidQuery('limit must be a positive integer', '/limit');
        }
        $limit = min($limit, 100);
        $offset = 0;
        if (($opts['cursor'] ?? null) !== null) {
            $decoded = json_decode((string) base64_decode(strtr((string) $opts['cursor'], '-_', '+/'), true), true);
            if (!is_array($decoded) || !is_int($decoded['offset'] ?? null) || $decoded['offset'] < 1) {
                throw HttpError::invalidQuery('cursor is not one paginate() returned', '/cursor');
            }
            $offset = $decoded['offset'];
        }
        unset($opts['cursor']);
        // One row past the page says whether another page follows.
        $rows = Db::instance()->findMany($collection, ['limit' => $limit + 1, 'offset' => $offset] + $opts, $viewer);
        $more = count($rows) > $limit;
        $next = $offset + $limit;

        return new JsObject([
            'rows' => new JsArray(array_map(static fn ($row) => self::rowToJs($collection, $row), array_slice($rows, 0, $limit))),
            'cursor' => $more ? rtrim(strtr(base64_encode((string) json_encode(['offset' => $next])), '+/', '-_'), '=') : null,
        ]);
    }

    public static function kindCount(string $collection, mixed $opts, bool $trusted): int
    {
        $viewer = self::as($trusted);
        $opts = self::query($collection, $opts);
        return Db::instance()->count($collection, $opts['where'] ?? null, $viewer, array_intersect_key($opts, ['locale' => true, 'search' => true, 'deleted' => true]));
    }

    /**
     * One row by id or by a filter (`{ slug: "hello" }`); null when no row the visitor may read matches.
     * A kind the visitor may not read at all is the API's 401/403, as on every list.
     */
    public static function kindGet(string $collection, array $selector, mixed $opts, bool $trusted): ?JsObject
    {
        $db = Db::instance();
        $where = array_key_exists('id', $selector)
            ? [$db->schema()->collection($collection)['primaryKey'] => self::toPhp($selector['id'])]
            : $selector['where'];
        $row = $db->findFirst($collection, ['where' => $where] + self::query($collection, $opts), self::as($trusted));

        return $row === null ? null : self::rowToJs($collection, $row);
    }

    public static function kindCreate(string $collection, mixed $values, bool $trusted): JsObject
    {
        return self::rowToJs($collection, Db::instance()->insert($collection, $values, true, self::as($trusted))[0]);
    }

    /** The updated row; a 404 when no row has that id or the visitor's update rule doesn't cover it. */
    public static function kindUpdate(string $collection, mixed $id, mixed $patch, bool $trusted): JsObject
    {
        $db = Db::instance();
        $pk = $db->schema()->collection($collection)['primaryKey'];
        $row = $db->update($collection, $patch, [$pk => self::toPhp($id)], true, self::as($trusted))[0] ?? null;

        return self::rowToJs($collection, $row ?? throw HttpError::notFound("No $collection row with $pk " . Js::str($id)));
    }

    /** The deleted row; a 404 when no row has that id or the visitor's delete rule doesn't cover it. */
    public static function kindDelete(string $collection, mixed $id, bool $trusted): JsObject
    {
        $db = Db::instance();
        $pk = $db->schema()->collection($collection)['primaryKey'];
        $row = $db->delete($collection, [$pk => self::toPhp($id)], true, self::as($trusted))[0] ?? null;

        return self::rowToJs($collection, $row ?? throw HttpError::notFound("No $collection row with $pk " . Js::str($id)));
    }

    /** Restores a soft-deleted row (and any cascade-deleted children sharing its deletedGroup). */
    public static function kindRestore(string $collection, mixed $id, bool $trusted): JsObject
    {
        $db = Db::instance();
        $pk = $db->schema()->collection($collection)['primaryKey'];
        $row = $db->restore($collection, [$pk => self::toPhp($id)], true, self::as($trusted))[0] ?? null;

        return self::rowToJs($collection, $row ?? throw HttpError::notFound("No deleted $collection row with $pk " . Js::str($id)));
    }

    /** Permanently deletes a row (active or soft-deleted). Default access is "system". */
    public static function kindPurge(string $collection, mixed $id, bool $trusted): JsObject
    {
        $db = Db::instance();
        $pk = $db->schema()->collection($collection)['primaryKey'];
        $row = $db->purge($collection, [$pk => self::toPhp($id)], true, self::as($trusted))[0] ?? null;

        return self::rowToJs($collection, $row ?? throw HttpError::notFound("No $collection row with $pk " . Js::str($id)));
    }

    /** Lists saved revisions of a row, newest first. */
    public static function kindRevisionsList(string $collection, mixed $id, bool $trusted): JsArray
    {
        $db = Db::instance();
        $revs = $db->revisions($collection, self::toPhp($id), self::as($trusted));

        return new JsArray(array_map(static function (array $r): JsObject {
            $user = null;
            if ($r['userId'] !== null && $r['userId'] > 0 && function_exists('get_userdata')) {
                $u = get_userdata($r['userId']);
                if ($u instanceof \WP_User) {
                    $user = new JsObject(['id' => (int) $u->ID, 'name' => (string) $u->display_name]);
                }
            }

            return new JsObject([
                'id' => $r['id'],
                'version' => $r['version'],
                'op' => $r['op'],
                'at' => $r['at'],
                'user' => $user,
                'schemaMigration' => $r['schemaMigration'],
                'changed' => $r['changed'] === null ? null : new JsArray($r['changed']),
            ]);
        }, $revs));
    }

    /** Reads a single revision snapshot by version number. */
    public static function kindRevisionsGet(string $collection, mixed $id, mixed $version, bool $trusted): JsObject
    {
        $ver = is_int($version) || is_float($version) ? (int) $version : throw HttpError::invalidInput('version must be an integer');

        return self::rowToJs($collection, Db::instance()->revision($collection, self::toPhp($id), $ver, self::as($trusted), byVersion: true));
    }

    /** Diffs two revisions of a row by version number. */
    public static function kindRevisionsDiff(string $collection, mixed $id, mixed $fromVersion, mixed $toVersion, bool $trusted): JsObject
    {
        $from = is_int($fromVersion) || is_float($fromVersion) ? (int) $fromVersion : throw HttpError::invalidInput('fromVersion must be an integer');
        $to = is_int($toVersion) || is_float($toVersion) ? (int) $toVersion : throw HttpError::invalidInput('toVersion must be an integer');
        $diff = Db::instance()->diffRevisions($collection, self::toPhp($id), $from, $to, self::as($trusted), byVersion: true);

        return new JsObject([
            'from' => $diff['from'],
            'to' => $diff['to'],
            'changes' => new JsArray(array_map(
                static fn (array $c): JsObject => new JsObject([
                    'field' => $c['field'],
                    'from' => self::valueToJs($c['from']),
                    'to' => self::valueToJs($c['to']),
                ]),
                $diff['changes'],
            )),
        ]);
    }

    /** Restores a row to a prior revision by version number. */
    public static function kindRevisionsRestore(string $collection, mixed $id, mixed $version, bool $trusted): JsObject
    {
        $ver = is_int($version) || is_float($version) ? (int) $version : throw HttpError::invalidInput('version must be an integer');

        return self::rowToJs($collection, Db::instance()->restoreRevision($collection, self::toPhp($id), $ver, self::as($trusted), byVersion: true));
    }

    private static function valueToJs(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? new JsArray(array_map(self::valueToJs(...), $value))
                : new JsObject(array_map(self::valueToJs(...), $value));
        }

        return $value;
    }

    public static function globalGet(string $name): JsObject
    {
        $db = Db::instance();
        return self::toJs($db->globalGet($name, self::$viewer), $db->schema()->global($name)['fields']);
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
                Schema::filtersRelation($spec, $k, $v) => self::filter($spec['relations'][$k]['collection'], $v, $schema),
                isset($spec['columns'][$k]) => self::plainOperand($spec['columns'][$k], $v),
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
