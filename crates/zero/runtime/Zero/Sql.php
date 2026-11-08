<?php

declare(strict_types=1);

namespace Zero;

/**
 * Drizzle filter → parameterised SQL (§3.5). One compiler for every store: all store knowledge
 * comes from Store::source() (ColumnRef expressions + joins) and Store::dialect().
 *
 * Query shape: SELECT <expr AS col, every column> FROM <table> t0 <joins for used columns>
 * WHERE <baseWhere> AND <filter> AND <guards> AND <partition> ORDER BY ... LIMIT ...
 * '{a}' in a ColumnRef/baseWhere becomes the query alias (t0, t1, ...), '{j}' the column's join
 * alias (t0_m3 = alias t0, column #3 of the Source). Joins are emitted once per (alias, column).
 */
final class Sql
{
    private const CMP = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
    private const FIELD_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'notIn', 'like', 'notLike', 'ilike', 'notIlike', 'isNull', 'isNotNull', 'has', 'NOT', 'OR', 'AND'];

    private readonly Dialect $d;
    private int $aliases = 0;
    /** @var list<int|float|string|null> */
    private array $params = [];
    /** @var array<string, array<string, true>> alias => used columns */
    private array $used = [];
    /** @var array<string, Source> */
    private array $sources = [];
    /** @var array<string, list<array>> */
    private array $relationGuards = [];
    private bool $trusted = false;

    /** @param ?\Closure(string): list<array|SqlPredicate> $guardsFor the viewer's read guards per relation target (null = trusted) */
    private function __construct(private readonly Schema $schema, private readonly Store $store, private readonly ?\Closure $guardsFor = null)
    {
        $this->d = $store->dialect();
    }

    /**
     * Compile one read. $opts may hold where/orderBy/limit/offset (other keys are ignored: Db owns
     * `columns` and `with`). $partition = [column, encoded values] restricts to column IN values with
     * limit/offset applied per distinct value (window function). $guards are extra filters ANDed in
     * (access + draft rules); their pointers are not user-facing. $ptr prefixes error pointers.
     * $guardsFor(collection) returns the viewer's read guards for a relation filter's target (or
     * throws the read denial); null means a trusted read.
     *
     * $partition[2], when given, names each value's window (parallel to the values): values with
     * the same name share one limit/offset window (a translation group's rows, Db::attach).
     *
     * @param ?array{0: string, 1: list<int|float|string|null>, 2?: list<string>} $partition
     * @param list<array|SqlPredicate> $guards
     * @return array{sql: string, params: list<int|float|string|null>}
     */
    public static function select(Schema $schema, Store $store, string $collection, array $opts, ?array $partition = null, string $ptr = '', array $guards = [], ?\Closure $guardsFor = null): array
    {
        return (new self($schema, $store, $guardsFor))->compile($collection, $opts, $partition, $ptr, $guards);
    }

    private function compile(string $collection, array $opts, ?array $partition, string $ptr, array $guards): array
    {
        $spec = $this->schema->collection($collection);
        $src = $this->source($collection);
        $alias = $this->alias();
        $conds = [];
        if ($src->baseWhere !== '') {
            $conds[] = $this->sub($src->baseWhere, $alias, '');
        }
        if (isset($opts['where'])) {
            $conds[] = $this->filter($collection, $opts['where'], $alias, $ptr . '/where');
        }
        foreach ($guards as $g) {
            $conds[] = $this->guard($collection, $g, $alias);
        }
        if ($partition !== null) {
            [$col, $values] = $partition;
            if ($values === []) {
                $conds[] = '0 = 1';
            } else {
                $conds[] = $this->cmpRef($collection, $col, $alias) . ' IN (' . self::placeholders($values) . ')';
                foreach ($values as $v) {
                    $this->params[] = $this->metaParam($spec['columns'][$col], $src->columns[$col], $v);
                }
            }
        }
        $order = $this->orderBy($collection, $opts['orderBy'] ?? [], $alias, $ptr . '/orderBy');
        $limit = self::count($opts, 'limit', $ptr);
        $offset = self::count($opts, 'offset', $ptr);

        $windowed = $partition !== null && ($limit !== null || $offset !== null);
        if ($windowed) {
            $window = $order !== [] ? $order : [$this->cmpRef($collection, $spec['primaryKey'], $alias) . ' ASC'];
            $by = $this->cmpRef($collection, $partition[0], $alias);
            if (isset($partition[2])) {
                // The select list binds nothing, so the window's parameters go first.
                $cases = [];
                foreach ($partition[1] as $i => $v) {
                    $cases[] = $this->metaParam($spec['columns'][$partition[0]], $src->columns[$partition[0]], $v);
                    $cases[] = $partition[2][$i];
                }
                $this->params = [...$cases, ...$this->params];
                $by = "CASE $by" . str_repeat(' WHEN ? THEN ?', count($partition[1])) . ' END';
            }
            $over = "PARTITION BY $by ORDER BY " . implode(', ', $window);
        }
        $select = [];
        // Schema columns only: a Source may carry helper expressions (the post-backed translation group).
        foreach ($spec['columns'] as $name => $col) {
            $expr = ($col['derived'] ?? false) ? $this->translations($collection, $alias) : $this->ref($collection, (string) $name, $alias, false);
            $select[] = $expr . ' AS ' . $this->d->quote((string) $name);
        }
        // Joins last: every expression above has marked the columns it needs.
        $from = ' FROM ' . $src->table . ' ' . $alias . $this->joins($collection, $alias)
            . ($conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds));

        if ($windowed) {
            $sql = 'SELECT * FROM (SELECT ' . implode(', ', $select) . ", ROW_NUMBER() OVER ($over) AS __zero_rn$from) z"
                . ' WHERE z.__zero_rn > ' . ($offset ?? 0)
                . ($limit === null ? '' : ' AND z.__zero_rn <= ' . (($offset ?? 0) + $limit))
                . ' ORDER BY z.__zero_rn';
        } else {
            $sql = 'SELECT ' . implode(', ', $select) . $from . ($order === [] ? '' : ' ORDER BY ' . implode(', ', $order));
            if ($limit !== null || $offset !== null) {
                $sql .= ' LIMIT ' . ($limit ?? $this->d->noLimit) . ' OFFSET ' . ($offset ?? 0);
            }
        }

        return ['sql' => $sql, 'params' => $this->params];
    }

    // ================================================================ sources, aliases, joins

    private function source(string $collection): Source
    {
        return $this->sources[$collection] ??= $this->store->source($collection);
    }

    private function alias(): string
    {
        $a = 't' . $this->aliases++;
        $this->used[$a] = [];

        return $a;
    }

    /** Substitute {a}/{j}. */
    private function sub(string $sql, string $alias, string $join): string
    {
        return strtr($sql, ['{a}' => $alias, '{j}' => $join]);
    }

    private function joinAlias(string $collection, string $col, string $alias): string
    {
        $i = array_search($col, array_keys($this->source($collection)->columns), true);

        return $alias . '_m' . (int) $i;
    }

    /** Value expression (storage form) or comparison expression for a column; marks it used. */
    private function ref(string $collection, string $col, string $alias, bool $cmp): string
    {
        $r = $this->source($collection)->columns[$col] ?? throw new \LogicException("Source for $collection has no column $col");
        $expr = $cmp && $r->cmpExpr !== '' ? $r->cmpExpr : $r->expr;
        if (str_contains($expr, '{j}')) {
            $this->used[$alias][$col] = true;
        }

        return $this->sub($expr, $alias, $this->joinAlias($collection, $col, $alias));
    }

    private function cmpRef(string $collection, string $col, string $alias): string
    {
        return $this->ref($collection, $col, $alias, true);
    }

    private function joins(string $collection, string $alias): string
    {
        $out = '';
        foreach ($this->source($collection)->columns as $name => $r) {
            if ($r->join !== '' && isset($this->used[$alias][$name])) {
                $out .= ' ' . $this->sub($r->join, $alias, $this->joinAlias($collection, (string) $name, $alias));
            }
        }

        return $out;
    }

    // ================================================================ order / limit

    /** @return list<string> "expr ASC|DESC" */
    private function orderBy(string $collection, mixed $orderBy, string $alias, string $ptr): array
    {
        if (!is_array($orderBy)) {
            throw HttpError::invalidQuery('orderBy must be an object', $ptr);
        }
        $spec = $this->schema->collection($collection);
        $out = [];
        foreach ($orderBy as $col => $dir) {
            $col = (string) $col;
            $cptr = $ptr . HttpError::seg($col);
            if (!isset($spec['columns'][$col])) {
                throw HttpError::invalidQuery("Unknown column '$col'", $cptr);
            }
            if ($dir !== 'asc' && $dir !== 'desc') {
                throw HttpError::invalidQuery("orderBy direction must be 'asc' or 'desc'", $cptr);
            }
            $kind = $this->source($collection)->columns[$col]->kind;
            if ($kind === 'json') {
                throw HttpError::invalidQuery('`orderBy` isn\'t supported on JSON columns', $cptr);
            }
            if ($kind === 'serialized-list') {
                throw HttpError::invalidQuery('`orderBy` isn\'t supported on list columns', $cptr);
            }
            if ($this->source($collection)->baseWhere !== '' && ($spec['content'] ?? null) === $col) {
                throw HttpError::invalidQuery("`orderBy` on `$col` isn't supported on editor collections", $cptr);
            }
            $out[] = $this->cmpRef($collection, $col, $alias) . ' ' . strtoupper($dir);
        }

        return $out;
    }

    private static function count(array $opts, string $key, string $ptr): ?int
    {
        $v = $opts[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!Js::isInteger($v) || $v < 0) {
            throw HttpError::invalidQuery("$key must be a non-negative integer", $ptr . '/' . $key);
        }

        return (int) $v;
    }

    // ================================================================ filters

    private function filter(string $collection, mixed $where, string $alias, string $ptr): string
    {
        if (!is_array($where) || ($where !== [] && array_is_list($where))) {
            throw HttpError::invalidQuery('Filter must be an object', $ptr);
        }
        $spec = $this->schema->collection($collection);
        $parts = [];
        foreach ($where as $key => $value) {
            $kptr = $ptr . HttpError::seg($key);
            $key = (string) $key;
            if ($key === 'OR' || $key === 'AND') {
                $parts[] = $this->combine($key, $value, $kptr, fn ($f, $p) => $this->filter($collection, $f, $alias, $p));
            } elseif ($key === 'NOT') {
                $parts[] = 'NOT (' . $this->filter($collection, $value, $alias, $kptr) . ')';
            } elseif (isset($spec['columns'][$key])) {
                $parts[] = $this->fieldFilter($collection, $key, $value, $alias, $kptr);
            } elseif (isset($spec['relations'][$key])) {
                $parts[] = $this->relationFilter($collection, $spec['relations'][$key], $value, $alias, $kptr);
            } else {
                throw HttpError::invalidQuery("Unknown column or relation '$key'", $kptr);
            }
        }

        return $parts === [] ? '1 = 1' : implode(' AND ', $parts);
    }

    private function combine(string $op, mixed $list, string $ptr, callable $compile): string
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw HttpError::invalidQuery("$op must be an array", $ptr);
        }
        if ($list === []) {
            return $op === 'OR' ? '0 = 1' : '1 = 1';
        }
        $sql = [];
        foreach ($list as $i => $f) {
            $sql[] = '(' . $compile($f, $ptr . '/' . $i) . ')';
        }

        return '(' . implode(" $op ", $sql) . ')';
    }

    private function fieldFilter(string $collection, string $name, mixed $value, string $alias, string $ptr): string
    {
        if ($value instanceof Locale\Filter) {
            return $this->localeRule($collection, $value, $alias);
        }
        if ($this->schema->collection($collection)['columns'][$name]['derived'] ?? false) {
            throw HttpError::invalidQuery("`$name` is derived and can't be filtered", $ptr);
        }
        $kind = $this->source($collection)->columns[$name]->kind;
        if ($value === null) {
            return $this->nullTest($collection, $name, $alias, true);
        }
        $isOps = is_array($value) && $value !== [] && !array_is_list($value);
        if ($kind === 'json' && is_array($value)
            && !($isOps && array_diff(array_map('strval', array_keys($value)), self::FIELD_OPS) === [])) {
            return $this->op($collection, $name, 'eq', $value, $alias, $ptr);
        }
        if ($kind === 'serialized-list' && is_array($value) && array_is_list($value)) {
            return $this->op($collection, $name, 'eq', $value, $alias, $ptr);
        }
        if (!is_array($value)) {
            return $this->op($collection, $name, 'eq', $value, $alias, $ptr);
        }
        if (!$isOps) {
            throw HttpError::invalidQuery('Field filter must be a value or an operator object', $ptr);
        }
        $parts = [];
        foreach ($value as $op => $arg) {
            $parts[] = $this->op($collection, $name, (string) $op, $arg, $alias, $ptr . HttpError::seg($op));
        }

        return implode(' AND ', $parts);
    }

    private function op(string $collection, string $name, string $op, mixed $arg, string $alias, string $ptr): string
    {
        $col = $this->schema->collection($collection)['columns'][$name];
        $src = $this->source($collection);
        $ref = $src->columns[$name];

        switch ($op) {
            case 'NOT':
                return 'NOT (' . $this->fieldFilter($collection, $name, $arg, $alias, $ptr) . ')';
            case 'OR':
            case 'AND':
                return $this->combine($op, $arg, $ptr, fn ($f, $p) => $this->fieldFilter($collection, $name, $f, $alias, $p));
            case 'isNull':
            case 'isNotNull':
                return $this->nullTest($collection, $name, $alias, ($op === 'isNull') === Js::truthy($arg));
        }
        if (!in_array($op, self::FIELD_OPS, true)) {
            throw HttpError::invalidQuery("Unknown operator '$op'", $ptr);
        }

        if ($ref->kind === 'json') {
            if ($op !== 'eq' && $op !== 'ne') {
                throw HttpError::invalidQuery("`$op` isn't supported on JSON columns", $ptr);
            }
            if ($src->baseWhere !== '' && in_array($col['type'], ['group', 'repeater', 'blocks'], true)) {
                throw HttpError::invalidQuery("`$op` on `$name` isn't supported on editor collections; filter on a scalar column", $ptr);
            }
            if ($arg === null) {
                return $this->nullTest($collection, $name, $alias, $op === 'eq');
            }
            // Whole-value comparison against the canonical JSON text the codecs store.
            $this->params[] = Js::jsonStringify($arg);

            return $this->ref($collection, $name, $alias, false) . ' ' . self::CMP[$op] . ' ?';
        }

        if ($ref->kind === 'serialized-list') {
            if ($op === 'has') {
                return $this->has($collection, $name, $arg, $alias, $ptr);
            }
            if ($op === 'eq' && $arg === []) {
                return $this->emptyList($collection, $name, $alias);
            }
            if (($op === 'eq' || $op === 'ne') && $arg === null) {
                return $this->nullTest($collection, $name, $alias, $op === 'eq');
            }
            throw HttpError::invalidQuery("`$op` isn't supported on list columns; use `has`", $ptr);
        }

        // scalar
        return match ($op) {
            'eq', 'ne', 'gt', 'gte', 'lt', 'lte' => $this->compare($collection, $name, $op, $arg, $alias, $ptr),
            'in', 'notIn' => $this->inList($collection, $name, $op === 'notIn', $arg, $alias, $ptr),
            'like', 'notLike', 'ilike', 'notIlike' => $this->like($collection, $name, $op, $arg, $alias, $ptr),
            'has' => throw HttpError::invalidQuery('`has` is only supported on list columns', $ptr),
        };
    }

    private function nullTest(string $collection, string $name, string $alias, bool $isNull): string
    {
        $ref = $this->source($collection)->columns[$name];
        // json kind may carry a cmpExpr meaning "empty" (post-backed group/repeater/blocks); scalars test expr.
        $expr = $this->ref($collection, $name, $alias, $ref->kind === 'json');

        return $expr . ($isNull ? ' IS NULL' : ' IS NOT NULL');
    }

    private function compare(string $collection, string $name, string $op, mixed $arg, string $alias, string $ptr): string
    {
        if ($arg === null) {
            return match ($op) {
                'eq' => $this->nullTest($collection, $name, $alias, true),
                'ne' => $this->nullTest($collection, $name, $alias, false),
                default => throw HttpError::invalidQuery("$op does not accept null", $ptr),
            };
        }
        $this->params[] = $this->param($collection, $name, $arg, $ptr);

        return $this->cmpRef($collection, $name, $alias) . ' ' . self::CMP[$op] . ' ?';
    }

    private function inList(string $collection, string $name, bool $negate, mixed $list, string $alias, string $ptr): string
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw HttpError::invalidQuery('Expected an array', $ptr);
        }
        if ($list === []) {
            return $negate ? '1 = 1' : '0 = 1';
        }
        foreach ($list as $i => $v) {
            $this->params[] = $this->param($collection, $name, $v, $ptr . '/' . $i);
        }

        return $this->cmpRef($collection, $name, $alias) . ($negate ? ' NOT IN (' : ' IN (') . self::placeholders($list) . ')';
    }

    private function like(string $collection, string $name, string $op, mixed $pattern, string $alias, string $ptr): string
    {
        if (!is_string($pattern)) {
            throw HttpError::invalidQuery("$op expects a string pattern", $ptr);
        }
        $this->params[] = $pattern;
        $ci = $op === 'ilike' || $op === 'notIlike';
        $sql = $this->d->like($this->ref($collection, $name, $alias, false), $ci);

        return str_starts_with($op, 'not') ? "NOT ($sql)" : $sql;
    }

    private function has(string $collection, string $name, mixed $v, string $alias, string $ptr): string
    {
        $col = $this->schema->collection($collection)['columns'][$name];
        $ref = $this->source($collection)->columns[$name];
        if ($col['type'] === 'mediaList') {
            if (!Js::isInteger($v)) {
                throw HttpError::invalidQuery('has expects an integer', $ptr);
            }
            $v = (int) $v;
        } elseif (!is_string($v)) {
            throw HttpError::invalidQuery('has expects a string', $ptr);
        }
        if ($ref->cmpExpr !== '') {
            // PHP-serialized array in postmeta: match one complete element (type-length-value can't
            // produce a false substring hit). Ids may be stored as ints or numeric strings
            // (SCF's gallery field stores strings), so ids match either form.
            $expr = $this->ref($collection, $name, $alias, true);
            $one = static fn (string $needle) => '%' . addcslashes($needle, '_%\\') . '%';
            $this->params[] = $one(serialize($v));
            if (is_int($v)) {
                $this->params[] = $one(serialize((string) $v));

                return "($expr LIKE ? OR $expr LIKE ?)";
            }

            return "$expr LIKE ?";
        }
        $expr = $this->ref($collection, $name, $alias, false);
        $this->params[] = Js::jsonStringify($v);

        return "JSON_CONTAINS($expr, ?)";
    }

    private function emptyList(string $collection, string $name, string $alias): string
    {
        $ref = $this->source($collection)->columns[$name];
        if ($ref->cmpExpr !== '') {
            // Post-backed: no row, '' and an empty serialized array all mean "empty".
            return 'COALESCE(NULLIF(' . $this->ref($collection, $name, $alias, true) . ", ''), 'a:0:{}') = 'a:0:{}'";
        }

        return $this->ref($collection, $name, $alias, false) . " = '[]'";
    }

    private function relationFilter(string $collection, array $rel, mixed $value, string $alias, string $ptr): string
    {
        $target = $rel['collection'];
        $src = $this->source($target);
        $inner = $this->alias();
        $conds = [];
        if ($src->baseWhere !== '') {
            $conds[] = $this->sub($src->baseWhere, $inner, '');
        }
        $conds[] = $this->cmpRef($target, $rel['to'], $inner) . ' = ' . $this->cmpRef($collection, $rel['from'], $alias);
        if (!$this->trusted && $this->guardsFor !== null) {
            foreach ($this->relationGuards[$target] ??= ($this->guardsFor)($target) as $g) {
                $conds[] = $this->guard($target, $g, $inner);
            }
        }
        if (is_array($value)) {
            $conds[] = $this->filter($target, $value, $inner, $ptr);
        } elseif ($value !== true && $value !== false) {
            throw HttpError::invalidQuery('Relation filter must be true, false or a filter object', $ptr);
        }
        // Joins are assembled after the inner filter so nested EXISTS can mark this alias's columns.
        $sql = 'EXISTS (SELECT 1 FROM ' . $src->table . ' ' . $inner . $this->joins($target, $inner) . ' WHERE ' . implode(' AND ', $conds) . ')';

        return $value === false ? "NOT $sql" : $sql;
    }

    /**
     * An access/draft guard. Guards are the app's own rules, so relation filters inside them are
     * trusted; only the viewer's filters are confined to rows the viewer can read.
     */
    private function guard(string $collection, array|SqlPredicate $g, string $alias): string
    {
        if ($g instanceof SqlPredicate) {
            array_push($this->params, ...$g->params);
            return '(' . $this->sub($g->sql, $alias, '') . ')';
        }
        [$was, $this->trusted] = [$this->trusted, true];
        try {
            return '(' . $this->filter($collection, $g, $alias, '') . ')';
        } finally {
            $this->trusted = $was;
        }
    }

    /** The viewer's read guards for $collection (none when trusted), cached per compile. */
    private function readGuards(string $collection): array
    {
        return $this->trusted || $this->guardsFor === null ? [] : ($this->relationGuards[$collection] ??= ($this->guardsFor)($collection));
    }

    /**
     * The locale rule (§7.3): rows in $f->locale or, with a fallback, the fallback-locale row of every
     * translation group without a row in $f->locale that the viewer may read (the subquery carries
     * the same per-target read guards as relation filters, so a hidden translation never suppresses
     * its group's fallback). $f->groupOf narrows to one row's group. Both subqueries are
     * uncorrelated; every localized Source exposes `locale` and a `translationGroup` expression.
     */
    private function localeRule(string $collection, Locale\Filter $f, string $alias): string
    {
        $pk = $this->schema->collection($collection)['primaryKey'];
        $locale = $this->cmpRef($collection, 'locale', $alias);
        $group = $this->cmpRef($collection, 'translationGroup', $alias);
        $parts = [];
        if ($f->groupOf !== null) {
            $this->params[] = $f->groupOf;
            $parts[] = '(' . $this->cmpRef($collection, $pk, $alias) . " = ? OR $group IN " . $this->groups($collection, $pk, $f->groupOf) . ')';
        }
        $this->params[] = $f->locale;
        $rule = "$locale = ?";
        if ($f->fallback !== null && $f->fallback !== $f->locale) {
            $this->params[] = $f->fallback;
            $rule = "($rule OR ($locale = ? AND ($group IS NULL OR $group NOT IN " . $this->groups($collection, 'locale', $f->locale) . ')))';
        }
        $parts[] = $rule;

        return implode(' AND ', $parts);
    }

    /** `(SELECT translationGroup FROM <collection> WHERE <$col> = ?)` over rows the viewer may read; NULL groups excluded. */
    private function groups(string $collection, string $col, int|string $value): string
    {
        $src = $this->source($collection);
        $inner = $this->alias();
        $group = $this->cmpRef($collection, 'translationGroup', $inner);
        $conds = $src->baseWhere === '' ? [] : [$this->sub($src->baseWhere, $inner, '')];
        $conds[] = $this->cmpRef($collection, $col, $inner) . ' = ?';
        $this->params[] = $value;
        $conds[] = "$group IS NOT NULL";
        foreach ($this->readGuards($collection) as $g) {
            $conds[] = $this->guard($collection, $g, $inner);
        }

        return "(SELECT $group FROM " . $src->table . ' ' . $inner . $this->joins($collection, $inner) . ' WHERE ' . implode(' AND ', $conds) . ')';
    }

    /**
     * `translations` (§7.3): locale → primary key of every row in this row's translation group, as JSON
     * text; just `{locale: pk}` for a row without a group. Literal-only SQL, so it can sit in the
     * select list ahead of the WHERE's parameters.
     */
    private function translations(string $collection, string $alias): string
    {
        $src = $this->source($collection);
        if (!isset($src->columns['locale'], $src->columns['translationGroup'])) {
            return 'NULL';
        }
        $pk = $this->schema->collection($collection)['primaryKey'];
        $inner = $this->alias();
        $conds = $src->baseWhere === '' ? [] : [$this->sub($src->baseWhere, $inner, '')];
        $conds[] = $this->cmpRef($collection, 'translationGroup', $inner) . ' = ' . $this->cmpRef($collection, 'translationGroup', $alias);
        $innerLocale = $this->ref($collection, 'locale', $inner, false);
        $conds[] = "$innerLocale IS NOT NULL";
        // JSON_OBJECTAGG aggregates an empty group to NULL, so the row's own pair is the fallback.
        $members = "(SELECT JSON_OBJECTAGG($innerLocale, " . $this->ref($collection, $pk, $inner, false) . ') FROM ' . $src->table . ' ' . $inner
            . $this->joins($collection, $inner) . ' WHERE ' . implode(' AND ', $conds) . ')';
        $locale = $this->ref($collection, 'locale', $alias, false);

        return "CASE WHEN $locale IS NULL THEN NULL ELSE COALESCE($members, JSON_OBJECT($locale, " . $this->ref($collection, $pk, $alias, false) . ')) END';
    }

    // ================================================================ values

    private function param(string $collection, string $name, mixed $v, string $ptr): int|float|string|null
    {
        $col = $this->schema->collection($collection)['columns'][$name];

        return $this->metaParam($col, $this->source($collection)->columns[$name], $this->filterValue($col, $v, $ptr));
    }

    /** Encode a filter value with the column's codec (format checks relaxed: filters compare, they don't write). */
    private function filterValue(array $col, mixed $v, string $ptr): int|float|string|null
    {
        unset($col['enum'], $col['notNull'], $col['rules']);
        $col['type'] = match ($col['type']) {
            'email', 'url', 'slug', 'richText' => 'text',
            'media' => 'integer',
            default => $col['type'],
        };
        try {
            return Schema::encode($col, $v, ltrim(strrchr($ptr, '/') ?: $ptr, '/'), $ptr);
        } catch (HttpError $e) {
            throw HttpError::invalidQuery($e->getMessage(), $ptr);
        }
    }

    /** With a cmpExpr the column compares in its WordPress meta form (§3.5.1). */
    private function metaParam(array $col, ColumnRef $ref, int|float|string|null $v): int|float|string|null
    {
        if ($ref->cmpExpr === '' || $v === null) {
            return $v;
        }

        return match ($col['type']) {
            'timestamp' => substr((string) $v, 0, 10) . ' ' . substr((string) $v, 11, 8),
            'date' => str_replace('-', '', (string) $v),
            'boolean' => $v ? '1' : '0',
            default => $v,
        };
    }

    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
