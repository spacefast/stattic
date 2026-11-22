<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * The one pipeline (§3.3): every read and write (REST, CLI, hooks, actions, editor saves) goes through
 * here, so defaults, coercion, rules, access, drafts, revisions, FK actions and hooks always run the
 * same way. Storage is a Store; Db never builds SQL itself (Sql does) and never parses driver errors.
 *
 * A null $viewer means trusted (hooks, actions, CLI): no access checks, every status visible.
 * The request's user (`actor`) is what hooks/validators see as ctx.user, trusted or not.
 */
final class Db
{
    private const MAX_HOOK_DEPTH = 8;
    private const QUERY_OPTS = ['where', 'orderBy', 'limit', 'offset', 'columns', 'with', 'search', 'searchFields'];

    private static ?self $instance = null;
    private static ?self $current = null;

    private int $depth = 0;
    private ?array $actor = null;
    /** @var list<BulkUpdate> open updates, innermost last */
    private array $bulk = [];
    /** @var array<string, array<int|string, int|string>> primary keys currently being deleted, by collection */
    private array $deleting = [];
    /** @var ?array<string, array<int|string, int>> deletion order within the current traversal */
    private ?array $deleted = null;
    private int $deleteSequence = 0;

    public function __construct(
        private readonly Store $store,
        private readonly Schema $schema,
        private readonly Media $media,
    ) {
    }

    public static function setInstance(self $db): void
    {
        self::$instance = $db;
    }

    /**
     * The Db that `db()` names: the one running the current operation, else the app's. Extensions
     * (field access, sync, locales, files…) read the schema and query through `db()`, so a second Db
     * (another app, a test fixture) gets its own schema and store, never the booted app's.
     */
    public static function instance(): self
    {
        static $booting = false;
        if (self::$current === null && self::$instance === null && !$booting) {
            // On WordPress the app boots on first use (a hook that reads through `db()` before any
            // request handler did), not on every request.
            $booting = true;
            try {
                Wp\Plugin::instance()?->app();
            } finally {
                $booting = false;
            }
        }

        return self::$current ?? self::$instance ?? throw new \LogicException('TypedPress\Db is not initialised; call App::boot() first');
    }

    /** Run $fn with this Db as `db()`; the previous one is restored after, even on a throw. */
    public function current(callable $fn): mixed
    {
        $previous = self::$current;
        self::$current = $this;
        try {
            return $fn();
        } finally {
            self::$current = $previous;
        }
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function media(): Media
    {
        return $this->media;
    }

    /** The user hooks and validators see as ctx.user for the rest of this request/command. */
    public function setActor(?array $user): void
    {
        $this->actor = $user;
    }

    public function actor(): ?array
    {
        return $this->actor;
    }

    /** @return array{user: ?array} */
    public function hookCtx(): array
    {
        return ['user' => $this->actor];
    }

    /**
     * When the update writing row $from right now also covers row $pk and hasn't written it yet, write
     * $pk now: its own patch (with the update's viewer, access and field rules), plus $values, trusted
     * values that win over the patch (a translation group's synced values). That update then skips
     * $pk, so its hooks run once. False when no such update is open.
     */
    public function updateWithin(string $collection, int|string $from, int|string $pk, array $values): bool
    {
        $bulk = null;
        foreach (array_reverse($this->bulk) as $open) {
            if ($open->collection === $collection && $open->current === self::key($from)) {
                $bulk = $open;
                break;
            }
        }
        $key = self::key($pk);
        if ($bulk === null || !isset($bulk->pending[$key])) {
            return false;
        }
        unset($bulk->pending[$key]);
        $opts = ['where' => [$bulk->spec['primaryKey'] => $pk], 'limit' => 1];
        $existing = $this->selectDecoded($collection, $opts, '', null)[0] ?? null;
        if ($existing !== null) {
            // Through nested(): the early write counts toward MAX_HOOK_DEPTH like the write it replaces.
            $bulk->done[$key] = $this->nested(fn () => Js::atInstant(fn () => $this->updateOne($collection, $bulk->spec, $existing, $bulk->set, $bulk->viewer, $values, $bulk->errorViewer)));
        }

        return true;
    }

    /** Run $fn in one store transaction (joins an open one). Any throwable rolls back. */
    public function transaction(callable $fn): mixed
    {
        return $this->current(fn () => $this->store->transaction($fn));
    }

    /**
     * {@see transaction()} for work that usually only reads: on WordPress the transaction starts at
     * the first write ({@see Wp\Connection::reading()}), so work that writes nothing sends the
     * database neither START TRANSACTION nor COMMIT.
     */
    public function reading(callable $fn): mixed
    {
        $store = $this->store;

        return $this->current(static fn () => $store instanceof Wp\CompositeStore ? $store->db->reading($fn) : $store->transaction($fn));
    }

    /**
     * Lazy scheduled promotion (§6.3) for every drafts collection. No hooks, no revisions.
     *
     * @return array<string, list<int|string>> collection => promoted pks (only non-empty)
     */
    public function promoteDue(): array
    {
        $now = Js::iso(Js::now());
        $out = [];
        foreach ($this->schema->collections() as $name => $spec) {
            if ($spec['drafts'] ?? false) {
                $pks = $this->store->promoteDue((string) $name, $now);
                if ($pks !== []) {
                    $out[$name] = $pks;
                    Realtime\Module::promoted((string) $name, $pks);
                    foreach ($pks as $pk) {
                        $this->afterPromotion((string) $name, $pk);
                    }
                }
            }
        }

        return $out;
    }

    // ================================================================ reads

    /** @return list<array> */
    public function findMany(string $collection, array|JsObject $opts = [], ?Viewer $viewer = null): array
    {
        return $this->current(function () use ($collection, $opts, $viewer): array {
            $opts = Seam::query($collection, $opts, $this->schema);
            $rows = $this->fetch($collection, $opts, '', $viewer);

            return $this->project($rows, $collection, $opts, '');
        });
    }

    public function findFirst(string $collection, array|JsObject $opts = [], ?Viewer $viewer = null): ?array
    {
        $opts = Seam::query($collection, $opts, $this->schema);
        return $this->findMany($collection, ['limit' => 1] + $opts, $viewer)[0] ?? null;
    }

    /**
     * Full decoded rows (relations/media from `with` attached, `columns` not yet applied).
     * With $partition = [column, encoded values], rows are restricted to column IN values and
     * limit/offset apply per distinct column value.
     *
     * @param ?array{string, list<mixed>} $partition
     * @param list<array> $guards extra filters (access) — read guards are added here when $viewer is set
     * @return list<array>
     */
    private function fetch(string $collection, array &$opts, string $ptr, ?Viewer $viewer, ?array $partition = null, array $guards = [], ?array &$decoded = null): array
    {
        $publishedOnly = false;
        $decoded = $this->selectDecoded($collection, $opts, $ptr, $viewer, $partition, $guards, prune: true, publishedOnly: $publishedOnly);
        // What a page render read (its purges): only a read that named its rows and found them all
        // depends on those rows alone.
        $named = Render\Reads::recording() ? $this->named($collection, $opts, $partition, $publishedOnly) : null;
        $rows = Render\Reads::exact($collection, $named !== null && count($decoded) === $named, static fn (): array => Extensions::read($decoded, $collection, $viewer));
        if (isset($opts['with']) && $rows !== []) {
            $rows = $this->attach($collection, $rows, $opts['with'], $ptr . '/with', $viewer, $decoded);
        }

        return $rows;
    }

    /**
     * How many rows a read names by a unique key, or null when it names none: `where` (or the key a
     * relation joins on) holds the primary key, or a unique column, to one value or a list of them.
     * Every other condition only narrows that set. A unique column counts only where one value is
     * one row: not in a hierarchical kind (a slug per parent), and in a kind with drafts only for a
     * read that sees published rows alone ({@see publishedOnly()}: drafts may share a value until
     * one is published). A localized kind names none.
     *
     * A read that found exactly the rows it named answers differently only after a write to one of
     * them: another row can take a found key's value only once the found row gave it up. A named
     * row that is missing (or hidden from the viewer) can appear with a write to any row.
     *
     * @param ?array{string, list<mixed>} $partition
     */
    private function named(string $collection, array $opts, ?array $partition, bool $publishedOnly): ?int
    {
        // A localized read may answer with another row of the named row's translation group.
        if ($this->localized($collection)) {
            return null;
        }
        $spec = $this->schema->collection($collection);
        $pk = (string) $spec['primaryKey'];
        $unique = [];
        if (!($spec['hierarchical'] ?? false)) {
            foreach ($spec['columns'] as $name => $column) {
                if (($column['unique'] ?? false) && (string) $name !== $pk) {
                    $unique[(string) $name] = true;
                }
            }
        }
        $one = static fn (mixed $v): bool => is_int($v) || is_string($v) || (is_float($v) && floor($v) === $v);
        $where = is_array($opts['where'] ?? null) ? $opts['where'] : [];
        // A relation's rows are named by the key it joins on, when that is a key of theirs (the
        // row a foreign key refers to); a parent's children are joined on a column that is not.
        $by = array_intersect_key($partition !== null ? [$partition[0] => ['in' => $partition[1]]] : $where, [$pk => true] + $unique);
        foreach ($by as $column => $value) {
            if (is_array($value) && array_keys($value) === ['eq']) {
                $value = $value['eq'];
            }
            $count = match (true) {
                $one($value) => 1,
                is_array($value) && array_keys($value) === ['in'] && is_array($value['in']) && array_is_list($value['in'])
                    && array_filter($value['in'], static fn (mixed $v): bool => !$one($v)) === [] => count(array_unique(array_map(self::key(...), $value['in']))),
                default => null,
            };
            if ($count !== null && ($column === $pk || !($spec['drafts'] ?? false) || $publishedOnly)) {
                return $count;
            }
        }

        return null;
    }

    /**
     * Whether $viewer reads only the published rows of a kind with drafts under a shorthand read rule.
     * WordPress's own posts: who reads a draft is core's answer, applied by the store's guards.
     */
    private function draftsHidden(array $spec, array $rule, Viewer $viewer): bool
    {
        return ($spec['drafts'] ?? false) && Access::isShorthand($rule) && !($spec['native'] ?? false)
            && ($viewer->user === null || Schema::roleRank($viewer->user['role']) < Schema::roleRank('editor'));
    }

    /**
     * Select full storage rows without presentation transforms; write guards remain SQL filters.
     * $filterViewer confines a write selector's relation filters to rows that viewer may read.
     */
    private function selectDecoded(string $collection, array &$opts, string $ptr, ?Viewer $viewer, ?array $partition = null, array $guards = [], ?Viewer $filterViewer = null, bool $prune = false, ?bool &$publishedOnly = null): array
    {
        return $this->current(function () use ($collection, &$opts, $ptr, $viewer, $partition, $guards, $filterViewer, $prune, &$publishedOnly): array {
            $spec = $this->schema->hasCollection($collection) ? $this->schema->collection($collection) : null;
            $only = null;
            $q = $this->compileRead($collection, $opts, $ptr, $viewer, $partition, $guards, $filterViewer, $prune, $only, $publishedOnly);
            if ($partition !== null && $partition[1] === []) {
                return [];
            }
            $columns = $only === null ? $spec['columns'] : array_intersect_key($spec['columns'], $only);

            return array_map(
                static fn (array $raw) => Schema::decodeRow($columns, $raw),
                $this->withLinks($collection, $this->store->select($collection, $q), $only),
            );
        });
    }

    /**
     * The columns a read's SQL must select when it asks for some (`columns`), or null for every column:
     * the ones `columns` keeps, the primary key, what `with` loads and the keys it joins on, the
     * partition column a relation groups children by, a localized kind's locale and translations, and
     * what read extensions read. Null whenever any of those can't be known: no or an invalid `columns`
     * (project() refuses it after the read, as before), a read extension that didn't say what it reads,
     * or a field read rule written as a function (it sees the whole row).
     *
     * @return ?array<string, true>
     */
    private function selected(string $collection, array $opts, ?Viewer $viewer, ?array $partition): ?array
    {
        $columns = $opts['columns'] ?? null;
        $spec = $this->schema->collection($collection);
        $known = $spec['columns'];
        if (!is_array($columns) || ($columns !== [] && array_is_list($columns)) || array_diff_key($columns, $known) !== []) {
            return null;
        }
        $extra = Extensions::readColumns($collection, $viewer);
        if ($extra === null) {
            return null;
        }
        if ($viewer !== null) {
            foreach ($known as $def) {
                if (isset($def['access']['read']) && !Access::isShorthand($def['access']['read'])) {
                    return null;
                }
            }
        }
        $included = array_keys(array_filter($columns, static fn ($on) => $on === true));
        $keep = $included !== [] ? $included : array_keys(array_diff_key($known, array_filter($columns, static fn ($on) => $on === false)));
        $keep[] = $spec['primaryKey'];
        if ($partition !== null) {
            $keep[] = $partition[0];
        }
        $relations = $spec['relations'] ?? [];
        foreach (is_array($opts['with'] ?? null) ? $opts['with'] : [] as $name => $on) {
            if ($on === false || $on === null) {
                continue;
            }
            $keep[] = (string) $name;
            if (isset($relations[$name]) && !isset($relations[$name]['through'])) {
                $keep[] = (string) $relations[$name]['from'];
            }
        }
        array_push($keep, ...$extra);
        if ($this->localized($collection)) {
            array_push($keep, 'locale', 'translations');
        }

        return array_fill_keys(array_keys(array_intersect_key($known, array_flip(array_map('strval', $keep)))), true);
    }

    /**
     * `findMany($collection, ['where' => $where], $viewer)`'s row count as SQL (`n`): the same read
     * rule, draft visibility and query extensions (a filter through a hidden field is the same 403),
     * without reading the rows. Read extensions never drop rows (onRead keeps membership), so this is
     * the list's count. The caller runs it on the store's connection.
     *
     * @return array{sql: string, params: list<int|float|string|null>}
     */
    public function countQuery(string $collection, mixed $where = null, ?Viewer $viewer = null, array $extra = []): array
    {
        return $this->current(function () use ($collection, $where, $viewer, $extra): array {
            $opts = Seam::query($collection, ($where === null ? [] : ['where' => $where]) + $extra, $this->schema);
            $q = $this->compileRead($collection, $opts, '', $viewer);
            $q['sql'] = 'SELECT COUNT(*) AS n FROM (' . $q['sql'] . ') first_count';

            return $q;
        });
    }

    /** {@see countQuery()}, run: how many rows `findMany($collection, ['where' => $where] + $extra, $viewer)` would return. */
    public function count(string $collection, mixed $where = null, ?Viewer $viewer = null, array $extra = []): int
    {
        $q = $this->countQuery($collection, $where, $viewer, $extra);
        $store = $this->store;
        if (!$store instanceof Wp\CompositeStore) {
            throw new \LogicException('count() needs the WordPress store');
        }

        // A count reads no rows, so the read hooks don't see it: a render that counts a kind still
        // depends on every write to it (its purges, and live regions).
        Render\Reads::listed($collection);

        return (int) ($store->db->run($q['sql'], $q['params'])[0]['n'] ?? 0);
    }

    /** One read compiled: query extensions, option checks, the viewer's read guards. */
    private function compileRead(string $collection, array &$opts, string $ptr, ?Viewer $viewer, ?array $partition = null, array $guards = [], ?Viewer $filterViewer = null, bool $prune = false, ?array &$only = null, ?bool &$publishedOnly = null): array
    {
        return $this->current(function () use ($collection, &$opts, $ptr, $viewer, $partition, $guards, $filterViewer, $prune, &$only, &$publishedOnly): array {
            if (!$this->schema->hasCollection($collection)) {
                throw HttpError::invalidQuery("Unknown collection '$collection'", $ptr === '' ? null : $ptr);
            }
            $spec = $this->schema->collection($collection);
            // A viewer-bound mutation selector is a query too: extensions see its viewer.
            Extensions::query($collection, $opts, $viewer ?? $filterViewer, $ptr);
            foreach ($opts as $k => $_) {
                if (!in_array($k, self::QUERY_OPTS, true)) {
                    throw HttpError::invalidQuery("Unknown query option '$k'", $ptr . HttpError::seg($k));
                }
            }
            if ($viewer !== null) {
                array_push($guards, ...$this->readGuards($collection, $viewer, forSelect: true));
            }
            if ($publishedOnly !== null && Render\Reads::recording() && ($spec['drafts'] ?? false)) {
                // Reuse the applied read guard. Calling an authored rule again could produce a
                // different filter or repeat its side effects.
                foreach ([$opts['where'] ?? null, ...$guards] as $filter) {
                    if (is_array($filter) && in_array($filter['status'] ?? null, ['published', ['eq' => 'published']], true)) {
                        $publishedOnly = true;
                        break;
                    }
                }
            }
            $filterViewer ??= $viewer;
            // `search`: the rows whose searchable fields this reader may read hold every word, as one
            // more condition on this same read (docs/decisions/2026-11-13-search-index.md).
            if (array_key_exists('search', $opts)) {
                $search = $opts['search'];
                unset($opts['search']);
                if ($search !== null && !is_string($search)) {
                    throw HttpError::invalidQuery('search must be a string', $ptr . '/search');
                }
                if (is_string($search) && trim($search) !== '') {
                    array_push($guards, ...Search\Module::guard($collection, $search, $filterViewer, $opts['searchFields'] ?? null));
                }
            }
            unset($opts['searchFields']);
            // After the query extensions: they may consume options (`with` on a file column) the selection reads.
            $only = $prune ? $this->selected($collection, $opts, $viewer, $partition) : null;

            return Sql::select($this->schema, $this->store, $collection, $opts, $partition, $ptr, $guards,
                $filterViewer === null ? null : fn (string $target): array => $this->readGuards($target, $filterViewer, forSelect: true), $only);
        });
    }

    /** Mutation results and revision snapshots may bypass selects, or follow hooks that replace caches. */
    private function present(array $rows, string $collection, ?Viewer $viewer): array
    {
        Extensions::lookup($collection, array_column($rows, $this->schema->collection($collection)['primaryKey']));

        return Extensions::read($rows, $collection, $viewer);
    }

    /**
     * Does $viewer's read/update/delete rule (+ draft visibility for read; $data as a write's rule
     * sees it) cover row $pk? For surfaces that ask the pipeline's question outside it: core `/wp/v2`
     * and WordPress capabilities. A rule that denies outright is false, like a row it filters out.
     */
    public function permits(string $collection, string $op, int|string $pk, Viewer $viewer, ?array $data = null): bool
    {
        try {
            $guards = $op === 'read' ? $this->readGuards($collection, $viewer, forSelect: true) : $this->writeGuards($collection, $op, $viewer, $data);
        } catch (HttpError) {
            return false;
        }

        return $this->keysMatching($collection, [$this->schema->collection($collection)['primaryKey'] => $pk], $guards) !== [];
    }

    /**
     * The primary keys $viewer's read or update rule (+ draft visibility for read) covers, or null when
     * it covers every row. Throws 401/403 when the rule denies. Rows as stored: no locale or other
     * query extension narrows them. For integer primary keys a candidate narrows this first read
     * and $remaining can read the complete set later under the same evaluated guards.
     *
     * @param-out ?\Closure(): list<int|string> $remaining
     * @return ?list<int|string>
     */
    public function permittedKeys(string $collection, string $op, Viewer $viewer, ?int $candidate = null, ?\Closure &$remaining = null): ?array
    {
        $remaining = null;
        $read = $this->preparePermittedKeys($collection, $op, $viewer);
        return $read === null ? null : $read($candidate, $remaining);
    }

    /**
     * Evaluate the rule now. A REST permission check validates its SQL filters, and only needs to
     * know whether the rule refused. Its later item or list read can execute the same decision.
     * Null means unrestricted; an outright refusal throws.
     */
    public function preparePermittedKeys(string $collection, string $op, Viewer $viewer, bool $validate = false): ?\Closure
    {
        $guards = $op === 'read' ? $this->readGuards($collection, $viewer, selectWhenRestricted: true) : $this->writeGuards($collection, $op, $viewer, null);
        if ($guards === []) {
            return null;
        }
        $spec = $this->schema->collection($collection);
        $pk = $spec['primaryKey'];
        $query = $validate ? $this->current(fn () => $this->keyQuery($collection, null, $guards)) : null;
        $all = fn (): array => $this->keysMatching($collection, null, $guards, $query);

        return function (?int $candidate, ?\Closure &$remaining) use ($collection, $guards, $pk, $spec, $all): array {
            if ($candidate !== null && $spec['columns'][$pk]['type'] === 'integer') {
                $remaining = $all;
                return $this->keysMatching($collection, [$pk => $candidate], $guards);
            }
            return $all();
        };
    }

    /** @return list<int|string> primary keys of rows matching $where and every guard, as stored */
    private function keysMatching(string $collection, ?array $where, array $guards, ?array $query = null): array
    {
        return $this->current(fn () => array_column(
            $this->store->select($collection, $query ?? $this->keyQuery($collection, $where, $guards)),
            $this->schema->collection($collection)['primaryKey'],
        ));
    }

    private function keyQuery(string $collection, ?array $where, array $guards): array
    {
        $pk = $this->schema->collection($collection)['primaryKey'];
        return Sql::select($this->schema, $this->store, $collection, $where === null ? [] : ['where' => $where], guards: $guards, only: [$pk => true]);
    }

    /**
     * The row filters $viewer's reads of $collection are confined to: the read rule's filter, draft
     * visibility (`['status' => 'published']`) and the store's own guards. Empty when the viewer
     * reads every row; a 401/403 when the rule refuses the viewer.
     *
     * @return list<array|SqlPredicate>
     */
    public function readScope(string $collection, Viewer $viewer): array
    {
        return $this->readGuards($collection, $viewer);
    }

    /** The read rule (+ draft visibility) as filters; throws 401/403 when the rule is false. */
    private function readGuards(string $collection, Viewer $viewer, bool $forSelect = false, bool $selectWhenRestricted = false): array
    {
        $spec = $this->schema->collection($collection);
        $rule = Access::collectionRules($spec)['read'];
        $r = $this->current(fn () => Access::evaluate($rule, $viewer, 'read', null, $spec['columns'], $collection));
        if ($r === false) {
            throw Access::deny($viewer->user, "read $collection");
        }
        $guards = is_array($r) ? [$r] : [];
        if ($this->draftsHidden($spec, $rule, $viewer)) {
            $guards[] = ['status' => 'published'];
        }

        // If the app already restricts the scope, the keys must go through SQL anyway.
        return [...$guards, ...$this->store->readGuards($collection, $viewer, $forSelect || ($selectWhenRestricted && $guards !== []))];
    }

    /**
     * A `ref().many()` column holds no value of its own: its value is its links, the target ids in
     * position order (storage form: JSON text), read with one query per such column.
     *
     * @param list<array<string, mixed>> $raw storage rows
     * @return list<array<string, mixed>>
     */
    private function withLinks(string $collection, array $raw, ?array $only = null): array
    {
        $spec = $this->schema->collection($collection);
        $pk = $spec['primaryKey'];
        foreach ($raw === [] ? [] : $spec['columns'] as $name => $col) {
            if ($col['type'] !== 'refList' || ($only !== null && !isset($only[$name]))) {
                continue;
            }
            $ids = [];
            foreach ($this->links()->links($col['references']['join'], 'owner_id', array_column($raw, $pk)) as $link) {
                $ids[$link['owner_id']][] = $link['target_id'];
            }
            foreach ($raw as &$row) {
                $row[$name] = (string) json_encode($ids[(int) $row[$pk]] ?? []);
            }
            unset($row);
        }

        return $raw;
    }

    private function links(): Links
    {
        return $this->store instanceof Links ? $this->store : throw new \LogicException('This store keeps no ref().many() links');
    }

    /**
     * A relation through a many-ref's join table: one query for the links, one for the related rows.
     * The field's own list keeps position order unless `orderBy` sorts it; a reverse list follows the
     * related rows' order. limit/offset apply per row.
     */
    private function attachThrough(array $rows, string $name, array $rel, array $opts, string $ptr, ?Viewer $viewer, callable $sourceFor, string $pk): array
    {
        ['table' => $join, 'from' => $from, 'to' => $to] = $rel['through'];
        foreach (['limit', 'offset'] as $k) {
            if (isset($opts[$k]) && (!Js::isInteger($opts[$k]) || $opts[$k] < 0)) {
                throw HttpError::invalidQuery("$k must be a non-negative integer", $ptr . '/' . $k);
            }
        }
        [$limit, $offset] = [isset($opts['limit']) ? (int) $opts['limit'] : null, (int) ($opts['offset'] ?? 0)];
        unset($opts['limit'], $opts['offset']);
        $owners = array_values(array_unique(array_map(static fn (array $row) => (int) $sourceFor($row)[$pk], $rows)));
        $linked = [];
        foreach ($this->links()->links($join, $from, $owners) as $link) {
            $linked[$link[$from]][] = $link[$to];
        }
        $target = $this->schema->collection($rel['collection']);
        $childPk = $target['primaryKey'];
        $ids = array_values(array_unique(array_merge([], ...array_values($linked))));
        $values = array_map(static fn ($id) => Schema::encode($target['columns'][$childPk], $id, $childPk, ''), $ids);
        // Read from the side that doesn't store the links: which rows are linked changes with a
        // write to any row of the other kind, so a page render that read them listed that kind.
        if ($from === 'target_id') {
            Render\Reads::listed($rel['collection']);
        }
        $children = $this->fetch($rel['collection'], $opts, $ptr, $viewer, [$childPk, $values], decoded: $childDecoded);
        $childKeys = array_map(static fn (array $row) => (int) $row[$childPk], $childDecoded);
        $byId = array_combine($childKeys, $this->project($children, $rel['collection'], $opts, $ptr));
        $rank = array_flip($childKeys);
        $sorted = $from === 'target_id' || isset($opts['orderBy']);
        foreach ($rows as &$row) {
            $list = array_values(array_filter($linked[(int) $sourceFor($row)[$pk]] ?? [], static fn (int $id) => isset($byId[$id])));
            if ($sorted) {
                usort($list, static fn (int $a, int $b) => $rank[$a] <=> $rank[$b]);
            }
            $row[$name] = array_map(static fn (int $id) => $byId[$id], array_slice($list, $offset, $limit));
        }
        unset($row);

        return $rows;
    }

    /** Load each requested relation with one query, never N+1; populate media columns in place. */
    private function attach(string $collection, array $rows, mixed $with, string $ptr, ?Viewer $viewer, array $decoded): array
    {
        if (!is_array($with) || ($with !== [] && array_is_list($with))) {
            throw HttpError::invalidQuery('with must be an object', $ptr);
        }
        $spec = $this->schema->collection($collection);
        $relations = $spec['relations'] ?? [];
        // Read transforms may remove a foreign key; keep linkage private while attaching relations.
        $sources = array_column($decoded, null, $spec['primaryKey']);
        $sourceFor = static fn (array $row): array => $sources[$row[$spec['primaryKey']]];
        foreach ($with as $name => $opts) {
            $name = (string) $name;
            $rptr = $ptr . HttpError::seg($name);
            $mediaCol = $spec['columns'][$name] ?? null;
            if ($mediaCol !== null && in_array($mediaCol['type'], ['media', 'mediaList'], true)) {
                if ($opts === true) {
                    $rows = $this->populateMedia($rows, $name, $mediaCol['type'] === 'mediaList');
                } elseif ($opts !== false && $opts !== null) {
                    throw HttpError::invalidQuery('Media columns take `true`', $rptr);
                }
                continue;
            }
            $rel = $relations[$name] ?? throw HttpError::invalidQuery("Unknown relation '$name'", $rptr);
            if ($opts === false || $opts === null) {
                continue;
            }
            $opts = $opts === true ? [] : $opts;
            if (!is_array($opts)) {
                throw HttpError::invalidQuery('Relation options must be true or an object', $rptr);
            }
            if (isset($rel['through'])) {
                $rows = $this->attachThrough($rows, $name, $rel, $opts, $rptr, $viewer, $sourceFor, $spec['primaryKey']);
                continue;
            }
            // A `many` relation between localized collections resolves per parent (§7.3): a parent's
            // children in that parent row's locale, falling back per translation group like a top-level
            // read, one query per distinct parent locale (a nested `locale` overrides that). References
            // name one specific row, so when the relation starts at the parent's primary key, children of
            // any row in the parent's translation group belong to it, and limit/offset window per group.
            $parentPk = $spec['primaryKey'];
            $localizedMany = $rel['kind'] === 'many' && $this->localized($collection) && $this->localized($rel['collection']);
            $perLocale = $localizedMany && !array_key_exists('locale', $opts);
            $byGroup = $localizedMany && $rel['from'] === $parentPk;
            $default = $perLocale ? $this->schema->locales()['default'] : null;
            $bucketOf = static fn (array $source): string => $perLocale ? (string) ($source['locale'] ?? $default) : '';
            // A parent's window: its translation group (named by its smallest member), else its own key.
            $windowOf = static function (array $source) use ($byGroup, $parentPk, $rel): string {
                if (!$byGroup) {
                    return self::key($source[$rel['from']]);
                }
                $members = array_values(Locale\Module::members($source, $parentPk));
                usort($members, static fn ($a, $b) => strcmp(self::key($a), self::key($b)));

                return self::key($members[0] ?? $source[$parentPk]);
            };
            $buckets = [];
            foreach ($rows as $row) {
                $source = $sourceFor($row);
                $bucket = $bucketOf($source);
                $buckets[$bucket] ??= [];
                $window = $windowOf($source);
                foreach ($byGroup ? Locale\Module::members($source, $parentPk) : [$source[$rel['from']]] as $v) {
                    if ($v !== null) {
                        $buckets[$bucket][self::key($v)] = [$v, $window];
                    }
                }
            }
            if ($buckets === []) {
                $buckets[$perLocale ? (string) $default : ''] = []; // still checks access and options on the target
            }
            $targetCols = $this->schema->collection($rel['collection'])['columns'];
            $childPk = $this->schema->collection($rel['collection'])['primaryKey'];
            $groups = [];
            foreach ($buckets as $bucket => $keys) {
                $bucketOpts = $perLocale ? ['locale' => (string) $bucket] + $opts : $opts;
                $values = array_map(
                    static fn (array $k) => Schema::encode(['type' => $targetCols[$rel['to']]['type']], $k[0], $rel['to'], ''),
                    array_values($keys),
                );
                $partition = $byGroup ? [$rel['to'], $values, array_column(array_values($keys), 1)] : [$rel['to'], $values];
                $children = $this->fetch($rel['collection'], $bucketOpts, $rptr, $viewer, $partition, decoded: $childDecoded);
                $childSources = array_column($childDecoded, null, $childPk);
                foreach ($this->project($children, $rel['collection'], $bucketOpts, $rptr) as $i => $child) {
                    $to = self::key($childSources[$children[$i][$childPk]][$rel['to']]);
                    $groups[$bucket][$keys[$to][1] ?? $to][] = $child;
                }
            }
            foreach ($rows as &$row) {
                $source = $sourceFor($row);
                $group = $source[$rel['from']] === null ? [] : ($groups[$bucketOf($source)][$windowOf($source)] ?? []);
                $row[$name] = $rel['kind'] === 'many' ? $group : ($group[0] ?? null);
            }
            unset($row);
        }

        return $rows;
    }

    private function localized(string $collection): bool
    {
        return $this->schema->locales() !== null && ($this->schema->collection($collection)['localized'] ?? false);
    }

    private function populateMedia(array $rows, string $col, bool $list): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            foreach ($list ? ($row[$col] ?? []) : [$row[$col]] as $id) {
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }
        $media = $this->media->getMany(array_keys($ids));
        foreach ($rows as &$row) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            if ($list) {
                $row[$col] = $row[$col] === null ? null : array_values(array_filter(array_map(static fn ($id) => $media[$id] ?? null, $row[$col])));
            } else {
                $row[$col] = $row[$col] === null ? null : ($media[$row[$col]] ?? null);
            }
        }
        unset($row);

        return $rows;
    }

    /** Apply the Drizzle `columns` selection (relations attached by `with` are kept). */
    private function project(array $rows, string $collection, array $opts, string $ptr): array
    {
        $columns = $opts['columns'] ?? null;
        if ($columns === null) {
            return $rows;
        }
        $known = $this->schema->collection($collection)['columns'];
        if (!is_array($columns) || ($columns !== [] && array_is_list($columns))) {
            throw HttpError::invalidQuery('columns must be an object', $ptr . '/columns');
        }
        foreach ($columns as $name => $on) {
            if (!isset($known[$name])) {
                throw HttpError::invalidQuery("Unknown column '$name'", $ptr . '/columns' . HttpError::seg($name));
            }
        }
        $included = array_keys(array_filter($columns, static fn ($on) => $on === true));
        $drop = $included !== []
            ? array_diff(array_keys($known), $included)
            : array_keys(array_filter($columns, static fn ($on) => $on === false));
        // A `ref()` field loaded by `with` holds the row in its id's place: `with` asked for it.
        $with = is_array($opts['with'] ?? null) ? array_keys(array_filter($opts['with'], static fn ($on) => $on !== false && $on !== null)) : [];
        $drop = array_diff($drop, array_map('strval', $with));
        $drop = array_flip($drop);

        return array_map(static fn (array $row) => array_diff_key($row, $drop), $rows);
    }

    // ================================================================ writes

    /**
     * @param ?callable(callable(): array): array $around per-row persistence continuation, after access checks
     * @return ?list<array>
     */
    public function insert(string $collection, array|JsObject|JsArray $values, bool $returning = false, ?Viewer $viewer = null, ?callable $around = null): ?array
    {
        // Only the row containers unwrap here: a json() value inside a row keeps its `{}`.
        $values = Seam::container($values);
        $rows = $values !== [] && array_is_list($values) ? array_map(fn ($r) => Seam::toPhp($r, $this->schema->collection($collection)['columns']), $values) : [Seam::toPhp($values, $this->schema->collection($collection)['columns'])];

        return $this->nested(function () use ($collection, $rows, $returning, $viewer, $around) {
            $spec = $this->schema->collection($collection);
            $out = [];
            foreach ($rows as $data) {
                if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                    throw HttpError::invalidInput('Each row must be an object', '');
                }
                // One instant per row: every `now` default, nested ones and publishedAt included.
                $out[] = Js::atInstant(fn () => $this->createOne($collection, $spec, $data, $viewer, $around));
            }

            return $returning ? $this->present($out, $collection, $viewer) : null;
        });
    }

    /** @return ?list<array> */
    public function update(string $collection, array|JsObject $set, array|JsObject $where, bool $returning = false, ?Viewer $viewer = null, ?Viewer $errorViewer = null): ?array
    {
        [$set, $where] = [Seam::container($set), Seam::filter($collection, $where, $this->schema)];
        // Flattened before the access rule sees the patch: rules get plain arrays outside json() values.
        $set = Seam::toPhp($set, $this->schema->collection($collection)['columns']);
        return $this->nested(function () use ($collection, $set, $where, $returning, $viewer, $errorViewer) {
            $spec = $this->schema->collection($collection);
            $guards = $viewer === null ? [] : $this->writeGuards($collection, 'update', $viewer, $set);
            $out = [];
            $opts = ['where' => $where];
            $rows = $this->selectDecoded($collection, $opts, '', null, null, $guards, $viewer);
            $pk = $spec['primaryKey'];
            $bulk = new BulkUpdate($collection, $spec, $set, $viewer, array_map(static fn (array $row) => self::key($row[$pk]), $rows), $errorViewer);
            $this->bulk[] = $bulk;
            try {
                foreach ($rows as $existing) {
                    $key = self::key($existing[$pk]);
                    if (isset($bulk->done[$key])) {
                        $out[] = $bulk->done[$key]; // written early by updateWithin
                        continue;
                    }
                    unset($bulk->pending[$key]);
                    $bulk->current = $key;
                    $out[] = Js::atInstant(fn () => $this->updateOne($collection, $spec, $existing, $set, $viewer, errorViewer: $errorViewer));
                }
            } finally {
                array_pop($this->bulk);
            }

            return $returning ? $this->present($out, $collection, $viewer) : null;
        });
    }

    /** @return ?list<array> */
    public function delete(string $collection, array|JsObject $where, bool $returning = false, ?Viewer $viewer = null): ?array
    {
        $where = Seam::filter($collection, $where, $this->schema);
        return $this->nested(function () use ($collection, $where, $returning, $viewer) {
            $guards = $viewer === null ? [] : $this->writeGuards($collection, 'delete', $viewer, null);
            $opts = ['where' => $where];
            $rows = $this->selectDecoded($collection, $opts, '', null, null, $guards, $viewer);
            $this->deleteRows($collection, $rows, $viewer);

            return $returning ? $this->present($rows, $collection, $viewer) : null;
        });
    }

    /** update/delete rule → filters for the row select; throws 401/403 when false. */
    private function writeGuards(string $collection, string $op, Viewer $viewer, ?array $data): array
    {
        $r = $this->current(fn () => Access::evaluate(Access::collectionRules($this->schema->collection($collection))[$op], $viewer, $op, $data, $this->schema->collection($collection)['columns'], $collection));
        if ($r === false) {
            throw Access::deny($viewer->user, "$op $collection rows");
        }

        return is_array($r) ? [$r] : [];
    }

    /** The canonical default preview and write share constraint preflight before generators run. */
    public function withDefaults(string $collection, array $data, ?Viewer $viewer = null): array
    {
        return $this->current(function () use ($collection, $data, $viewer): array {
            $columns = $this->schema->collection($collection)['columns'];
            $generations = [];
            foreach ($columns as $name => $col) {
                if (!array_key_exists($name, $data) && isset($col['default'])) {
                    $generations[$name] = $col['default'];
                }
            }
            $this->requireGeneratedVisibility($collection, $generations, $viewer);
            foreach ($generations as $name => $generation) {
                $data[$name] = Schema::generate($generation, $columns[$name]);
            }

            return $data;
        });
    }

    private function createOne(string $collection, array $spec, array $data, ?Viewer $viewer, ?callable $around = null): array
    {
        $columns = $spec['columns'];
        $provided = $data;
        Extensions::input('create', $collection, $data, null, $viewer, $provided);
        $this->rejectUnknown($columns, $data);
        $statusGiven = array_key_exists('status', $data);
        $data = $this->withDefaults($collection, $data, $viewer);
        if ($spec['drafts'] ?? false) {
            $data = $this->draftRules($data, $statusGiven ? null : 'published', null);
        }
        Extensions::write('create', $collection, $data, null, $viewer, $provided);
        $this->rejectUnknown($columns, $data);
        $fills = $spec['beforeCreateFills'] ?? [];
        foreach ($columns as $name => $col) {
            if (Schema::isRequired($col) && !array_key_exists($name, $data) && !in_array($name, $fills, true)
                && ($spec['slug'] ?? null) !== $name) {
                throw HttpError::invalidInput("$name is required", HttpError::seg($name));
            }
        }
        if ($viewer !== null) {
            Access::require(Access::collectionRules($spec)['create'], $viewer, 'create', $data, "create $collection rows", $columns);
        }
        $write = fn (): array => $this->persistCreate($collection, $spec, $data, $viewer);

        return $around === null ? $write() : $around($write);
    }

    private function persistCreate(string $collection, array $spec, array $data, ?Viewer $viewer): array
    {
        $columns = $spec['columns'];
        $ctx = $this->hookCtx();
        if (isset($spec['hooks']['beforeCreate'])) {
            $slot = $spec['hooks']['beforeCreate'];
            if (is_string($slot)) {
                $fn = '\\TypedPressApp\\' . $slot;
                // Fields a runtime schema change added (spec §2.10) are kept aside: the compiled hook
                // never saw them, and a replacement object it returns must not drop them.
                [$data, $runtime] = RuntimeSchema\Loader::split($spec, $data, RuntimeSchema\Loader::compiled() ?? ['collections' => []]);
                $jsCtx = Seam::ctx($ctx + ['data' => $data], $columns);
                $data = $jsCtx->p['data'];
                $result = $fn($data, $jsCtx);
                $data = Seam::rowToPhp($collection, $result instanceof JsObject ? $result : $data) + $runtime;
            } else {
                $result = $slot($data, $ctx);
                if (is_array($result)) { $data = $result; }
            }
            $this->rejectUnknown($columns, $data);
        }

        $norm = [];
        foreach ($columns as $name => $col) {
            $name = (string) $name;
            if ($this->derivesSlug($spec, $name, $data, !array_key_exists($name, $data))) {
                continue;
            }
            if (array_key_exists($name, $data)) {
                $norm[$name] = Schema::normalize($col, $data[$name], $name, HttpError::seg($name));
            } elseif (Schema::isRequired($col)) {
                throw HttpError::invalidInput("$name is required", HttpError::seg($name));
            }
        }
        $this->requireUniqueVisibility($collection, $norm, $viewer, true);
        $slugCol = $spec['slug'] ?? null;
        if ($slugCol !== null && !array_key_exists($slugCol, $norm)) {
            $norm[$slugCol] = $this->deriveSlug($collection, $spec, $norm, null);
        }
        $full = $norm + array_fill_keys(array_keys($columns), null);
        $this->checkValues($columns, $norm, $full, $viewer);
        $this->requireUniqueVisibility($collection, $norm, $viewer);

        $insert = $links = [];
        foreach ($norm as $name => $v) {
            if ($columns[$name]['type'] === 'refList') {
                $links[$name] = $v ?? [];
                continue;
            }
            $insert[$name] = Schema::store($columns[$name], $v);
        }
        try {
            $pk = $this->store->insert($collection, $insert);
            $this->writeLinks($columns, $pk, $links);
        } catch (\Throwable $e) {
            throw $this->store->mapError($e);
        }
        [$row, $raw] = $this->readByPk($collection, $spec, $pk);
        if ($spec['revisions'] ?? false) {
            $this->store->revisionWrite($collection, $pk, $raw, $this->actor['id'] ?? null);
        }
        $this->hook($collection, $spec, 'afterCreate', $row);
        $this->published($collection, $spec, $row, null);
        Extensions::written('create', $collection, $row, null);

        return $row;
    }

    /** @param array $values trusted values applied over $patch after access and field rules (updateWithin) */
    private function updateOne(string $collection, array $spec, array $existing, array $patch, ?Viewer $viewer, array $values = [], ?Viewer $errorViewer = null): array
    {
        $columns = $spec['columns'];
        $pk = $spec['primaryKey'];
        $provided = $patch;
        Extensions::input('update', $collection, $patch, $existing, $viewer, $provided);
        $this->checkPatch($spec, $existing, $patch);
        $generations = [];
        foreach ($columns as $name => $col) {
            if (isset($col['onUpdate']) && !array_key_exists($name, $patch)) {
                $generations[$name] = $col['onUpdate'];
            }
        }
        $this->requireGeneratedVisibility($collection, $generations, $viewer ?? $errorViewer);
        foreach ($columns as $name => $col) {
            if (isset($col['onUpdate']) && !array_key_exists($name, $patch)) {
                $patch[$name] = Schema::generate($col['onUpdate'], $col);
            }
        }
        if (($spec['drafts'] ?? false) && (array_key_exists('status', $patch) || array_key_exists('publishedAt', $patch))) {
            $patch = $this->draftRules($patch, null, $existing);
        }
        $previous = $existing;
        Extensions::write('update', $collection, $patch, $previous, $viewer, $provided);
        $patch = $values + $patch;
        $this->checkPatch($spec, $previous, $patch);
        $ctx = $this->hookCtx();
        if (isset($spec['hooks']['beforeUpdate'])) {
            $slot = $spec['hooks']['beforeUpdate'];
            if (is_string($slot)) {
                $fn = '\\TypedPressApp\\' . $slot;
                [$patch, $runtime] = RuntimeSchema\Loader::split($spec, $patch, RuntimeSchema\Loader::compiled() ?? ['collections' => []]);
                $jsCtx = Seam::ctx($ctx + ['data' => $patch], $columns);
                $patch = $jsCtx->p['data'];
                $result = $fn($patch, Seam::rowToJs($collection, $existing), $jsCtx);
                $patch = Seam::rowToPhp($collection, $result instanceof JsObject ? $result : $patch) + $runtime;
            } else {
                $result = $slot($patch, $existing, $ctx);
                if (is_array($result)) { $patch = $result; }
            }
            $this->checkPatch($spec, $previous, $patch);
        }
        unset($patch[$pk]);
        $norm = [];
        foreach ($patch as $name => $value) {
            $name = (string) $name;
            if ($this->derivesSlug($spec, $name, $patch, false)) {
                continue;
            }
            $norm[$name] = Schema::normalize($columns[$name], $value, $name, HttpError::seg($name));
        }
        $slugCol = $spec['slug'] ?? null;
        $pkValue = $this->pkValue($spec, $previous);
        $publishing = ($spec['drafts'] ?? false)
            && ($previous['status'] ?? null) === 'draft'
            && in_array($norm['status'] ?? null, ['published', 'scheduled'], true);
        if ($publishing && $slugCol !== null) {
            // WordPress drafts may share slugs. Publication is a new uniqueness operation, even
            // when the readable draft's slug was echoed or omitted from the patch.
            $this->requireUniqueVisibility($collection, [$slugCol => $patch[$slugCol] ?? $previous[$slugCol] ?? null], $viewer ?? $errorViewer, true);
            if (!array_key_exists($slugCol, $patch)) {
                $norm[$slugCol] = $previous[$slugCol] ?? null;
            }
        }
        $this->requireUniqueVisibility($collection, $patch, $viewer ?? $errorViewer, existing: $previous);
        if ($slugCol !== null && array_key_exists($slugCol, $patch) && !array_key_exists($slugCol, $norm)) {
            $norm[$slugCol] = $this->deriveSlug($collection, $spec, $norm + $previous, $pkValue);
        }
        $this->checkValues($columns, $norm, $norm + $previous, $viewer ?? $errorViewer);
        $this->requireUniqueVisibility($collection, $norm, $viewer ?? $errorViewer, existing: $previous);
        $set = $links = [];
        foreach ($norm as $name => $v) {
            if ($columns[$name]['type'] === 'refList') {
                $links[$name] = $v ?? [];
                continue;
            }
            if (!($publishing && $name === $slugCol) && $this->unchangedReadableUnique($collection, (string) $name, $v, $previous, $viewer ?? $errorViewer)) {
                continue; // WordPress meta uniqueness checks must not inspect competitors for a no-op.
            }
            $set[$name] = Schema::store($columns[$name], $v);
        }
        $this->requireIncomingVisibility($collection, $set, $viewer ?? $errorViewer);
        try {
            $this->store->update($collection, $pkValue, $set);
            $this->writeLinks($columns, $pkValue, $links);
        } catch (\Throwable $e) {
            throw $this->store->mapError($e);
        }
        [$row, $raw] = $this->readByPk($collection, $spec, $pkValue);
        if ($spec['revisions'] ?? false) {
            $this->store->revisionWrite($collection, $pkValue, $raw, $this->actor['id'] ?? null);
        }
        $this->hook($collection, $spec, 'afterUpdate', $row, $previous);
        $this->published($collection, $spec, $row, $previous);
        Extensions::written('update', $collection, $row, $previous);

        return $row;
    }

    /** A many-ref write replaces the row's whole list. @param array<string, list<int>> $links */
    public function writeLinks(array $columns, int|string $pk, array $links): void
    {
        foreach ($links as $name => $ids) {
            $this->links()->setLinks($columns[$name]['references']['join'], $pk, $ids);
        }
    }

    /** A prior row's cascade can delete a later row in the same selection. */
    private function deleteRows(string $collection, array $rows, ?Viewer $viewer): void
    {
        $root = $this->deleted === null;
        $this->deleted ??= [];
        $selectedAt = $this->deleteSequence;
        $spec = $this->schema->collection($collection);
        try {
            foreach ($rows as $row) {
                $pk = $this->pkValue($spec, $row);
                if (($this->deleted[$collection][$pk] ?? 0) <= $selectedAt) {
                    $this->deleteOne($collection, $spec, $row, $pk, $viewer);
                }
            }
        } finally {
            if ($root) {
                $this->deleted = null;
                $this->deleteSequence = 0;
            }
        }
    }

    private function deleteOne(string $collection, array $spec, array $row, int|string $pk, ?Viewer $viewer): void
    {
        if (isset($this->deleting[$collection][$pk])) {
            return;
        }
        $this->deleting[$collection][$pk] = $pk;
        try {
            $existing = $row;
            Extensions::write('delete', $collection, $row, $existing, $viewer, $existing);
            $this->hook($collection, $spec, 'beforeDelete', $row);
            $this->referentialActions($collection, $row, $viewer);
            try {
                $this->store->delete($collection, $pk);
            } catch (\Throwable $e) {
                throw $this->store->mapError($e);
            }
            $this->deleted[$collection][$pk] = ++$this->deleteSequence;
            $this->hook($collection, $spec, 'afterDelete', $row);
            Extensions::written('delete', $collection, $row, $existing);
        } finally {
            unset($this->deleting[$collection][$pk]);
        }
    }

    /**
     * Before a viewer's delete writes anything: every row that references this one must be readable by
     * the viewer (docs/decisions/2026-11-16-per-row-delete-visibility.md). The rows referencing this
     * row are counted twice in SQL, trusted and as the viewer, WordPress's trash included, without
     * loading them; visible cascades are followed to every depth. Any difference, a referencing column
     * or referenced key that may be hidden, or a read extension that may hide rows, is the one refusal
     * ({@see hiddenReference()}). What the delete then does to those rows is the viewer's own writes
     * ({@see viewerReferentialWrites()}).
     */
    private function refuseHiddenReferences(string $collection, array $row, Viewer $viewer, array &$seen = []): void
    {
        $parent = $this->schema->collection($collection);
        $at = $collection . ':' . self::key($row[$parent['primaryKey']]);
        if (isset($seen[$at])) {
            return;
        }
        $seen[$at] = true;
        // WordPress reparents child terms on delete. The implicit parent column has no schema reference.
        if (($parent['taxonomy'] ?? false) && ($parent['hierarchical'] ?? false) && isset($parent['columns']['parent'])) {
            $pk = $parent['primaryKey'];
            if (FieldAccess::mayHide($parent['columns'][$pk], $viewer) || FieldAccess::mayHide($parent['columns']['parent'], $viewer)
                || !Extensions::constraintReadsSafe()) {
                throw self::hiddenReference();
            }
            $this->hasReadableReferences($collection, ['parent' => $row[$pk]], $viewer);
        }
        // Every relationship to this kind, before any is skipped for a null key: whether a hidden key
        // is null is itself what the viewer can't read.
        foreach ($this->relationships($collection) as [, , , $def]) {
            if (FieldAccess::mayHide($parent['columns'][$def['references']['column']], $viewer) || FieldAccess::mayHide($def, $viewer)
                || !Extensions::constraintReadsSafe()) {
                throw self::hiddenReference();
            }
        }
        foreach ($this->referencing($collection, $row) as [$child, $spec, $col, $def, $where]) {
            if (!$this->hasReadableReferences($child, $where, $viewer)) {
                continue;
            }
            if ($def['type'] !== 'refList' && strtolower($def['references']['onDelete'] ?? 'no action') === 'cascade') {
                foreach ($this->referencingRows($child, $spec, $where, $viewer) as $dependent) {
                    $this->refuseHiddenReferences($child, $dependent, $viewer, $seen);
                }
            }
        }
    }

    /** Whether any rows match. Refuses the delete if any matching row is hidden. */
    private function hasReadableReferences(string $collection, array $where, Viewer $viewer): bool
    {
        $trusted = $this->countRows($collection, $where, null);
        if ($trusted === 0) {
            return false;
        }
        try {
            $visible = $this->countRows($collection, $where, $viewer);
        } catch (HttpError) {
            throw self::hiddenReference();
        }
        if ($visible !== $trusted) {
            throw self::hiddenReference();
        }

        return true;
    }

    /**
     * What a viewer's delete does to the rows that reference it is that viewer's own writes, through
     * the path a direct request takes: a cascade is their delete of each child, set null / set default
     * their update of it, and a `ref().many()` link (a taxonomy's terms too) their update of the owner
     * without that id. Collection and field rules see the real patch; hooks, extensions and listeners
     * run as for a direct write. Any refusal, or a row their rule doesn't reach, is the one refusal,
     * and the request's transaction rolls the whole delete back.
     */
    private function viewerReferentialWrites(string $collection, array $row, Viewer $viewer): void
    {
        foreach ($this->referencing($collection, $row, skipActiveCascades: true) as [$child, $spec, $col, $def, $where]) {
            $many = $def['type'] === 'refList';
            $action = strtolower($def['references']['onDelete'] ?? ($many ? 'cascade' : 'no action'));
            $expected = $this->countRows($child, $where, null);
            if ($expected === 0) {
                continue; // nothing to write: no generator runs either
            }
            if (!in_array($action, ['cascade', 'set null', 'set default'], true)) {
                // restrict: every referencing row is one the viewer reads (refuseHiddenReferences), so
                // the refusal may say where they are, as a trusted delete's does.
                throw new HttpError(409, 'reference_violation', $many ? "Rows in $child still link to this row" : "Rows in $child still reference this row");
            }
            $pk = $spec['primaryKey'];
            $target = $row[$def['references']['column']];
            $written = 0;
            $write = function () use ($child, $spec, $col, $def, $where, $viewer, $many, $action, $pk, $target, &$written): void {
                foreach ($this->referencingRows($child, $spec, $where, $viewer) as $dependent) {
                    $one = [$pk => $dependent[$pk]];
                    if ($many) {
                        $patch = [$col => array_values(array_filter($dependent[$col] ?? [], static fn ($id) => !Js::strictEq($id, $target)))];
                        $written += count($this->update($child, $patch, $one, true, $viewer) ?? []);
                    } elseif ($action === 'cascade') {
                        $written += count($this->delete($child, $one, true, $viewer) ?? []);
                    } else {
                        $value = $action === 'set default' && isset($def['default']) ? Schema::generate($def['default'], $def) : null;
                        $written += count($this->update($child, [$col => $value], $one, true, $viewer) ?? []);
                    }
                }
            };
            try {
                $this->withTrashFor($child, $write);
            } catch (\Throwable $e) {
                // A side effect's own answer may carry what the viewer can't read (an app hook's
                // message, a hidden value's constraint): never pass it on. The request's transaction
                // rolls the whole delete back; the cause goes to the log only.
                error_log('[typedpress] a delete side effect refused: ' . $e::class . ': ' . $e->getMessage());
                throw self::hiddenReference();
            }
            if ($written !== $expected) {
                throw self::hiddenReference();
            }
        }
        // Whatever hooks did meanwhile, nothing may still reference the row: the trusted referential
        // actions never run for a viewer's delete.
        foreach ($this->referencing($collection, $row, skipActiveCascades: true) as [$child, , , , $where]) {
            if ($this->countRows($child, $where, null) > 0) {
                throw self::hiddenReference();
            }
        }
    }

    /**
     * The columns of other kinds that reference $row, each with a filter for the rows that do.
     *
     * @return list<array{string, array, string, array, array}> child, its spec, column, column def, where
     */
    private function referencing(string $collection, array $row, bool $skipActiveCascades = false): array
    {
        $out = [];
        foreach ($this->relationships($collection) as [$child, $spec, $col, $def]) {
            $value = $row[$def['references']['column']] ?? null;
            if ($value === null) {
                continue;
            }
            // A many-ref's referencing rows are the owners of links to this row, filtered in SQL.
            $many = $def['type'] === 'refList';
            $where = [$col => $many ? ['has' => $value] : $value];
            // A cascade back into an active delete is completed by that delete's own call.
            $active = $this->deleting[$child] ?? [];
            if ($skipActiveCascades && $active !== [] && !$many && strtolower($def['references']['onDelete'] ?? 'no action') === 'cascade') {
                $where = ['AND' => [$where, [$spec['primaryKey'] => ['notIn' => array_values($active)]]]];
            }
            $out[] = [$child, $spec, $col, $def, $where];
        }

        return $out;
    }

    /**
     * The columns of other kinds that reference $collection, whatever a row's keys hold.
     *
     * @return list<array{string, array, string, array}> child, its spec, column, column def
     */
    private function relationships(string $collection): array
    {
        $out = [];
        foreach ($this->schema->kinds() as $child => $spec) {
            foreach ($spec['columns'] as $col => $def) {
                if (($def['references']['collection'] ?? null) === $collection) {
                    $out[] = [(string) $child, $spec, (string) $col, $def];
                }
            }
        }

        return $out;
    }

    /** The referencing rows as $viewer reads them, every locale, WordPress's trash included. */
    private function referencingRows(string $child, array $spec, array $where, Viewer $viewer): array
    {
        $opts = ['where' => $where] + (($spec['localized'] ?? false) ? ['locale' => '*'] : []);

        return $this->withTrashFor($child, fn () => $this->sqlVisibility($child, $where, fn () => $this->findMany($child, $opts, $viewer)));
    }

    /**
     * How many rows match $where, trusted or as $viewer, in SQL, WordPress's trash included: trashed
     * posts keep their references and links (core's term delete removes those links too).
     */
    private function countRows(string $collection, array $where, ?Viewer $viewer): int
    {
        $store = $this->store;
        if (!$store instanceof Wp\CompositeStore) {
            throw new \LogicException('Counting referencing rows needs the WordPress store');
        }
        $extra = $viewer !== null && ($this->schema->collection($collection)['localized'] ?? false) ? ['locale' => '*'] : [];

        return $this->withTrashFor($collection, fn () => $this->sqlVisibility($collection, $where, function () use ($collection, $where, $viewer, $extra, $store): int {
            $q = $this->countQuery($collection, $where, $viewer, $extra);

            return (int) ($store->db->run($q['sql'], $q['params'])[0]['n'] ?? 0);
        }));
    }

    private function withTrashFor(string $collection, callable $fn): mixed
    {
        $store = $this->store;

        return $store instanceof Wp\CompositeStore && $store->isPostBacked($collection) ? $store->posts->withTrash($fn) : $fn();
    }

    /**
     * Run $fn with WordPress's password visibility decided in SQL only, failing closed, about the posts
     * matching $where ({@see Wp\WpPostStore::withSqlVisibility()}): a post-password cookie is checked
     * against those posts' passwords only, however many other protected posts the kind has.
     */
    private function sqlVisibility(string $collection, array $where, callable $fn): mixed
    {
        $store = $this->store;
        if (!$store instanceof Wp\CompositeStore || !$store->isPostBacked($collection)) {
            return $fn();
        }
        $scope = $this->current(fn () => $this->keyQuery($collection, $where, []));

        return $store->posts->withSqlVisibility($fn, [$collection => $scope]);
    }

    /** The one refusal for a delete that rows the viewer can't read, or can't write, may depend on. */
    private static function hiddenReference(): HttpError
    {
        return new HttpError(409, 'reference_violation', 'Rows you can\'t read may reference this row');
    }

    /** Incoming foreign keys can reject a key change or propagate it into another hidden branch. */
    private function requireIncomingVisibility(string $collection, array $written, ?Viewer $viewer, array $seen = []): void
    {
        $key = serialize([$collection, array_keys($written)]);
        if ($viewer === null || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        foreach ($this->schema->kinds() as $child => $spec) {
            foreach ($spec['columns'] as $col => $def) {
                $ref = $def['references'] ?? null;
                if ($ref === null || $ref['collection'] !== $collection || !array_key_exists($ref['column'], $written)) {
                    continue;
                }
                $this->requireReferenceVisibility($collection, $ref['column'], (string) $child, $def, $viewer);
                if (in_array(strtolower($ref['onUpdate'] ?? 'no action'), ['cascade', 'set null', 'set default'], true)) {
                    $this->requireIncomingVisibility((string) $child, [$col => true], $viewer, $seen);
                }
            }
        }
    }

    /** Both ends of a relationship must be readable before constraints can answer about its rows. */
    private function requireReferenceVisibility(string $parent, string $column, string $child, array $def, Viewer $viewer): void
    {
        if (FieldAccess::mayHide($this->schema->collection($parent)['columns'][$column], $viewer)) {
            throw new HttpError(409, 'reference_violation', 'A hidden field may be referenced by this row');
        }
        try {
            $visible = $this->readsAllRows($child, $viewer) && !FieldAccess::mayHide($def, $viewer);
        } catch (HttpError) {
            $visible = false;
        }
        if (!$visible) {
            throw new HttpError(409, 'reference_violation', "Rows in $child may reference this row");
        }
    }

    /**
     * FK actions in the pipeline (§4.2, §11.12): for every column referencing this collection, apply
     * its onDelete to the rows pointing at $row before $row goes. Hooks and revisions apply.
     */
    private function referentialActions(string $collection, array $row, ?Viewer $viewer = null): void
    {
        if ($viewer !== null) {
            // A viewer's delete: what it does to referencing rows is that viewer's own writes, and
            // nothing else runs for them.
            $this->refuseHiddenReferences($collection, $row, $viewer);
            $this->viewerReferentialWrites($collection, $row, $viewer);
            $spec = $this->schema->collection($collection);
            foreach ($spec['columns'] as $c) {
                if ($c['type'] === 'refList') {
                    $this->links()->unlink($c['references']['join'], 'owner_id', $row[$spec['primaryKey']]); // its own links go with it
                }
            }

            return;
        }
        $spec = $this->schema->collection($collection);
        foreach ($this->schema->kinds() as $child => $cspec) {
            foreach ($cspec['columns'] as $col => $c) {
                $ref = $c['references'] ?? null;
                if ($c['type'] === 'refList') {
                    // The deleted row's own links go with it; links to it go unless `restrict`.
                    if ($child === $collection) {
                        $this->links()->unlink($ref['join'], 'owner_id', $row[$spec['primaryKey']]);
                    }
                    // A term's links go with the term: core's own delete removes them and recounts.
                    if ($ref['collection'] !== $collection || str_starts_with($ref['join'], 'term:')) {
                        continue;
                    }
                    $id = $row[$ref['column']];
                    if (strtolower($ref['onDelete'] ?? 'cascade') === 'restrict' && $this->links()->links($ref['join'], 'target_id', [$id]) !== []) {
                        throw new HttpError(409, 'reference_violation', "Rows in $child still link to this row");
                    }
                    $this->links()->unlink($ref['join'], 'target_id', $id);
                    continue;
                }
                if ($ref === null || $ref['collection'] !== $collection || $row[$ref['column']] === null) {
                    continue;
                }
                $where = [$col => $row[$ref['column']]];
                $opts = ['where' => $where, 'limit' => 1];
                $action = strtolower($ref['onDelete'] ?? 'no action');
                if ($action === 'cascade') {
                    $cascadeOpts = ['where' => $where];
                    $dependents = array_values(array_filter(
                        $this->selectDecoded((string) $child, $cascadeOpts, '', null),
                        fn (array $dependent): bool => !isset($this->deleting[$child][$this->pkValue($cspec, $dependent)]),
                    ));
                    if ($dependents !== []) {
                        $this->nested(fn () => $this->deleteRows((string) $child, $dependents, $viewer));
                    }
                } elseif ($action === 'set null' || $action === 'set default') {
                    // No rows, no write: a generator must not run (or fail) for a branch it won't change.
                    if ($this->selectDecoded((string) $child, $opts, '', null) === []) {
                        continue;
                    }
                    $value = $action === 'set default' && isset($c['default']) ? Schema::generate($c['default'], $c) : null;
                    $this->update((string) $child, [$col => $value], $where, errorViewer: $viewer);
                } elseif ($this->selectDecoded((string) $child, $opts, '', null) !== []) {
                    throw new HttpError(409, 'reference_violation', "Rows in $child still reference this row");
                }
            }
        }
    }

    /**
     * §6.1 transitions. $defaultStatus: 'published' when status was absent on create. $existing: the
     * row being updated (null on create).
     */
    private function draftRules(array $data, ?string $defaultStatus, ?array $existing): array
    {
        if ($defaultStatus !== null) {
            $data['status'] = $defaultStatus;
        }
        $status = array_key_exists('status', $data) ? $data['status'] : ($existing['status'] ?? null);
        $hasAt = array_key_exists('publishedAt', $data) && $data['publishedAt'] !== null;
        $now = Js::iso(Js::now());
        if ($status === 'published' && !$hasAt) {
            // Re-saving an already published row (publishedAt untouched) keeps its date.
            $keep = $existing !== null && !array_key_exists('publishedAt', $data) && $existing['status'] === 'published'
                && $existing['publishedAt'] !== null;
            if (!$keep) {
                $data['publishedAt'] = $now;
            }
        } elseif ($status === 'scheduled') {
            $at = array_key_exists('publishedAt', $data) ? $data['publishedAt'] : ($existing['publishedAt'] ?? null);
            $iso = $at === null ? null : Schema::normalize(['type' => 'timestamp'], $at, 'publishedAt', '/publishedAt');
            if ($iso === null || $iso <= $now) {
                throw HttpError::invalidInput('scheduled rows need a future publishedAt', '/publishedAt');
            }
        }

        return $data;
    }

    /** True when this column is the slug and must be derived (absent/empty on create, null on update). */
    private function derivesSlug(array $spec, string $name, array $data, bool $absent): bool
    {
        if (($spec['slug'] ?? null) !== $name || !isset($spec['columns'][$name]['source'])) {
            return false;
        }

        return $absent || $data[$name] === null || $data[$name] === '';
    }

    private function deriveSlug(string $collection, array $spec, array $row, int|string|null $self): ?string
    {
        $slugCol = $spec['slug'];
        $source = $spec['columns'][$slugCol]['source'] ?? null;
        $text = $source === null ? null : ($row[$source] ?? null);
        if (!is_string($text)) {
            return null;
        }
        $base = self::slugify($text);
        if ($base === '') {
            return null;
        }
        $pk = $spec['primaryKey'];
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $base : $base . '-' . $n;
            $where = [$slugCol => $candidate];
            if ($self !== null) {
                $where[$pk] = ['ne' => $self];
            }
            $opts = ['where' => $where, 'limit' => 1];
            if ($this->selectDecoded($collection, $opts, '', null) === []) {
                return $candidate;
            }
        }
    }

    public static function slugify(string $s): string
    {
        if (class_exists(\Transliterator::class) && ($t = \Transliterator::create('Any-Latin; Latin-ASCII')) !== null) {
            $s = (string) $t->transliterate($s);
        }
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');

        return rtrim(substr($s, 0, 190), '-');
    }

    /** Rules (min/max/pattern/validate, nested) + media and reference existence for written values. */
    private function checkValues(array $columns, array $norm, array $full, ?Viewer $viewer = null): void
    {
        $this->checkReferences($columns, $norm, $viewer);
        $ctx = ['data' => $full, 'user' => $this->actor];
        foreach ($norm as $name => $v) {
            Schema::checkRules($columns[$name], $v, (string) $name, HttpError::seg($name), $ctx, $columns);
        }
        $this->checkMedia($columns, $norm);
    }

    /**
     * Every media id in $norm (normalized values by column) names an existing attachment, of an
     * accepted kind. Also for core REST `meta` writes, which store through the post store directly.
     */
    public function checkMedia(array $columns, array $norm): void
    {
        $refs = [];
        foreach ($norm as $name => $v) {
            array_push($refs, ...Schema::mediaRefs($columns[$name], $v, HttpError::seg($name)));
        }
        if ($refs !== []) {
            $media = $this->media->getMany(array_map(static fn ($r) => $r[0], $refs));
            foreach ($refs as [$id, $ptr, $accept]) {
                $m = $media[$id] ?? throw HttpError::invalidInput("No media with id $id", $ptr);
                if ($accept === 'image' && !str_starts_with((string) $m['mime'], 'image/')) {
                    throw HttpError::invalidInput('Only images are accepted here', $ptr);
                }
            }
        }
    }

    /** Core REST and editor inputs use the same reference visibility check as the write pipeline. */
    public function validateWriteReferences(string $collection, array $data, ?Viewer $viewer): void
    {
        $this->current(function () use ($collection, $data, $viewer): void {
            $columns = $this->schema->collection($collection)['columns'];
            $norm = [];
            foreach ($data as $name => $value) {
                if (isset($columns[$name]['references'])) {
                    $norm[$name] = Schema::normalize($columns[$name], $value, (string) $name, HttpError::seg((string) $name));
                }
            }
            $this->checkReferences($columns, $norm, $viewer);
        });
    }

    private function checkReferences(array $columns, array $norm, ?Viewer $viewer): void
    {
        foreach ($norm as $name => $v) {
            $ref = $columns[$name]['references'] ?? null;
            if ($ref === null || $v === null) {
                continue;
            }
            if ($columns[$name]['type'] === 'refList') {
                foreach ($v as $i => $id) {
                    $this->checkReference($ref, (string) $name, $id, HttpError::seg((string) $name) . "/$i", $viewer);
                }
                continue;
            }
            $this->checkReference($ref, (string) $name, $v, HttpError::seg((string) $name), $viewer);
        }
    }

    /** The referenced row exists and, for a viewer, is one they may read: otherwise the same 409. */
    private function checkReference(array $ref, string $name, mixed $v, string $ptr, ?Viewer $viewer): void
    {
        $missing = new HttpError(409, 'reference_violation', "No referenced {$ref['collection']} row for $name", $ptr);
        $target = $this->schema->collection($ref['collection']);
        if ($viewer !== null) {
            if (FieldAccess::mayHide($target['columns'][$ref['column']], $viewer)) {
                throw $missing;
            }
        }
        $opts = ['where' => [$ref['column'] => $v], 'limit' => 1];
        if ($target['localized'] ?? false) {
            $opts['locale'] = '*';
        }
        try {
            $row = $viewer === null
                ? ($this->selectDecoded($ref['collection'], $opts, '', null)[0] ?? null)
                : $this->findFirst($ref['collection'], $opts, $viewer);
            if ($row === null || !array_key_exists($ref['column'], $row)
                || !Js::strictEq($row[$ref['column']], $v)) {
                throw $missing;
            }
        } catch (HttpError $error) {
            throw $viewer === null ? $error : $missing;
        }
    }

    /** Potential generators are checked before hidden storage decides whether they will run. */
    public function requireGeneratedVisibility(string $collection, array $generations, ?Viewer $viewer): void
    {
        $this->current(function () use ($collection, $generations, $viewer): void {
            if ($viewer === null) {
                return;
            }
            $spec = $this->schema->collection($collection);
            $candidates = [];
            foreach ($generations as $name => $generation) {
                $candidates[$name] = array_key_exists('value', $generation) ? $generation['value'] : true;
                $target = $spec['columns'][$name]['references'] ?? null;
                if ($candidates[$name] !== null && $target !== null) {
                    try {
                        $visible = $this->readsAllRows($target['collection'], $viewer)
                            && !FieldAccess::mayHide($this->schema->collection($target['collection'])['columns'][$target['column']], $viewer);
                    } catch (HttpError) {
                        $visible = false;
                    }
                    if (!$visible) {
                        throw new HttpError(409, 'reference_violation', "Rows in {$target['collection']} may be referenced by this write", HttpError::seg((string) $name));
                    }
                }
            }
            $this->requireUniqueVisibility($collection, $candidates, $viewer);
        });
    }

    /** Prove a complete namespace without reading its rows; extension narrowing is a read filter too. */
    private function readsAllRows(string $collection, Viewer $viewer): bool
    {
        if (!Extensions::constraintReadsSafe() || $this->readGuards($collection, $viewer) !== []) {
            return false;
        }
        // Constraints span every locale. Sorting is harmless; any other query rewrite may hide
        // competitors, children, or their values and cannot authorize a namespace-wide check.
        $opts = ($this->schema->collection($collection)['localized'] ?? false) ? ['locale' => '*'] : [];
        Extensions::query($collection, $opts, $viewer, '');
        unset($opts['orderBy']);
        if (($opts['where'] ?? null) === []) {
            unset($opts['where']);
        }

        return $opts === [];
    }

    /**
     * A unique write can disclose every competing row. Reject it before a lookup unless the viewer
     * may read the column across the collection. This also covers generated slugs and hook values;
     * swallowing a conflict would falsely acknowledge a write and could commit partial hook effects.
     * An unchanged value on a readable row/column is safe only when persistence skips its lookup.
     */
    public function requireUniqueVisibility(string $collection, array $data, ?Viewer $viewer, bool $creating = false, ?array $existing = null): void
    {
        $this->current(fn () => $this->checkUniqueVisibility($collection, $data, $viewer, $creating, $existing));
    }

    private function checkUniqueVisibility(string $collection, array $data, ?Viewer $viewer, bool $creating, ?array $existing): void
    {
        if ($viewer === null) {
            return;
        }
        $spec = $this->schema->collection($collection);
        $readable = null;
        foreach ($spec['columns'] as $name => $col) {
            if (!($col['unique'] ?? false) && $name !== $spec['primaryKey']) {
                continue;
            }
            $derived = ($spec['slug'] ?? null) === $name && isset($col['source'])
                && (($creating && !array_key_exists($name, $data)) || (array_key_exists($name, $data) && ($data[$name] === null || $data[$name] === '')));
            if ((!array_key_exists($name, $data) || $data[$name] === null) && !$derived) {
                continue;
            }
            // Uids are unguessable identities the caller already presents on the sync surface.
            if ($name === 'uid' && ($col['system'] ?? false)) {
                continue;
            }
            if (!$creating && !$derived && $this->unchangedReadableUnique($collection, (string) $name, $data[$name], $existing, $viewer)) {
                continue;
            }
            if ($readable === null) {
                try {
                    $readable = $this->readsAllRows($collection, $viewer);
                } catch (HttpError) {
                    $readable = false;
                }
            }
            if ((!$readable && !$this->store->showsUnique($collection, (string) $name, $viewer)) || FieldAccess::mayHide($col, $viewer)) {
                throw $viewer->user === null
                    ? new HttpError(401, 'unauthenticated', "Sign in to set $name", HttpError::seg((string) $name))
                    : new HttpError(403, 'forbidden', "You can't set $name", HttpError::seg((string) $name));
            }
        }
    }

    /** An echoed visible unique value needs no competitor lookup. Prove visibility before equality. */
    public function unchangedReadableUnique(string $collection, string $name, mixed $value, ?array $existing, ?Viewer $viewer): bool
    {
        return $this->current(function () use ($collection, $name, $value, $existing, $viewer): bool {
            if ($viewer === null || $existing === null) {
                return false;
            }
            $spec = $this->schema->collection($collection);
            $col = $spec['columns'][$name] ?? [];
            if ((!($col['unique'] ?? false) && $name !== $spec['primaryKey'])
                || !array_key_exists($name, $existing) || !isset($existing[$spec['primaryKey']])
                || FieldAccess::mayHide($col, $viewer)) {
                return false;
            }
            try {
                $opts = ['where' => [$spec['primaryKey'] => $existing[$spec['primaryKey']]]];
                if ($spec['localized'] ?? false) {
                    $opts['locale'] = '*';
                }
                $visible = $this->findFirst($collection, $opts, $viewer);
                if ($visible === null || !array_key_exists($name, $visible)
                    || !Js::strictEq($visible[$name], $existing[$name])) {
                    return false;
                }
            } catch (HttpError) {
                return false;
            }

            return Js::strictEq($value, $existing[$name]);
        });
    }

    /** Unknown fields, and fields storage computes (a term's `count` and `url`), are refused. */
    private function rejectUnknown(array $columns, array $data, ?array $existing = null): void
    {
        foreach ($data as $key => $v) {
            if (!isset($columns[$key])) {
                throw HttpError::invalidInput("Unknown field '$key'", HttpError::seg($key));
            }
            // An update may resend what it read, unchanged.
            if (($columns[$key]['readOnly'] ?? false) && ($existing === null || !Js::strictEq($v, $existing[$key] ?? null))) {
                throw HttpError::invalidInput("$key is read-only", HttpError::seg($key));
            }
        }
    }

    private function checkPatch(array $spec, array $existing, array $patch): void
    {
        $this->rejectUnknown($spec['columns'], $patch, $existing);
        $pk = $spec['primaryKey'];
        if (array_key_exists($pk, $patch) && !Js::strictEq($patch[$pk], $existing[$pk])) {
            throw HttpError::invalidInput('The primary key cannot be changed', HttpError::seg($pk));
        }
    }

    /** `afterPublish` (spec §2.8): the row is published now and wasn't before (or is new). */
    private function published(string $collection, array $spec, array $row, ?array $previous): void
    {
        if (isset($spec['hooks']['afterPublish']) && ($row['status'] ?? null) === 'published'
            && ($previous === null || ($previous['status'] ?? null) !== 'published')) {
            $this->hook($collection, $spec, 'afterPublish', $row);
        }
    }

    /**
     * A scheduled item reached its time (WP cron's `publish_future_post`, or the lazy promotion):
     * its `afterPublish`, run as the system in one transaction.
     */
    public function afterPromotion(string $collection, int|string $pk): void
    {
        $spec = $this->schema->collection($collection);
        if (!isset($spec['hooks']['afterPublish'])) {
            return;
        }
        $this->asEditor(null, function () use ($collection, $spec, $pk) {
            [$row] = $this->readByPk($collection, $spec, $pk);
            if (($row['status'] ?? null) === 'published') {
                $this->hook($collection, $spec, 'afterPublish', $row);
            }
        });
    }

    /** Call a hook that receives rows (afterCreate/afterUpdate/before|afterDelete); result ignored. */
    private function hook(string $collection, array $spec, string $name, array $row, ?array $previous = null): void
    {
        if (!isset($spec['hooks'][$name])) {
            return;
        }
        $slot = $spec['hooks'][$name];
        $ctx = $this->hookCtx();
        $fn = $slot;
        if (is_string($slot)) {
            $fn = '\\TypedPressApp\\' . $slot;
            $ctx = Seam::ctx($ctx, $spec['columns']);
            $row = Seam::rowToJs($collection, $row);
            $previous = $previous === null ? null : Seam::rowToJs($collection, $previous);
        }
        if ($previous === null) {
            $fn($row, $ctx);
        } else {
            $fn($row, $previous, $ctx);
        }
    }

    /** @return array{array, array} [decoded row, storage-form row] */
    private function readByPk(string $collection, array $spec, int|string $pk): array
    {
        $q = Sql::select($this->schema, $this->store, $collection, ['where' => [$spec['primaryKey'] => $pk], 'limit' => 1]);
        $raw = $this->withLinks($collection, $this->store->select($collection, $q))[0] ?? throw new \RuntimeException("Row $collection/$pk vanished after write");
        $raw = array_intersect_key($raw, $spec['columns']);

        return [Schema::decodeRow($spec['columns'], $raw), $raw];
    }

    private function pkValue(array $spec, array $row): int|string
    {
        $pk = $spec['primaryKey'];
        $v = Schema::encode($spec['columns'][$pk], $row[$pk], $pk, HttpError::seg($pk));

        return is_float($v) ? (int) $v : $v;
    }

    private function nested(callable $fn): mixed
    {
        if ($this->depth >= self::MAX_HOOK_DEPTH + 1) {
            throw new HttpError(500, 'hook_recursion', 'Hooks nested writes more than ' . self::MAX_HOOK_DEPTH . ' levels deep');
        }
        $this->depth++;
        try {
            return $this->current(fn () => $this->store->transaction($fn));
        } finally {
            $this->depth--;
        }
    }

    // ================================================================ editor saves (WP, §4.9)

    /** Trusted editor capture: full decoded storage values, without display transforms or projection. */
    public function editorRow(string $collection, int|string $pk): ?array
    {
        $opts = ['where' => [$this->schema->collection($collection)['primaryKey'] => $pk], 'limit' => 1];

        return $this->selectDecoded($collection, $opts, '', null)[0] ?? null;
    }

    /** Stored rows for a page of WordPress post IDs, with the same decoding as editorRow(). */
    public function editorRows(string $collection, array $ids): array
    {
        $pk = $this->schema->collection($collection)['primaryKey'];
        $opts = ['where' => [$pk => ['in' => $ids]], 'limit' => count($ids) + 1];
        $found = $this->selectDecoded($collection, $opts, '', null);
        if (count($found) > count($ids)) {
            return []; // Duplicate postmeta multiplied the joins. Fall back to the individual LIMIT 1 reads.
        }
        $rows = [];
        foreach ($found as $row) {
            $id = (int) $row[$pk];
            // Non-unique WordPress postmeta can return several rows for one post. Let the normal
            // LIMIT 1 query choose that item's row exactly as it would for an individual response.
            $rows[$id] = array_key_exists($id, $rows) ? null : $row;
        }
        return $rows;
    }

    /**
     * editorRow() for a row WordPress may hold in its trash: what a move into or out of the trash, and
     * a permanent delete from it, know of the row as stored. Every other read leaves the trash out.
     */
    public function editorRowIncludingTrash(string $collection, int|string $pk): ?array
    {
        $store = $this->store;

        return $store instanceof Wp\CompositeStore
            ? $store->posts->withTrash(fn () => $this->editorRow($collection, $pk))
            : $this->editorRow($collection, $pk);
    }

    /**
     * After a wp-admin/core REST save: afterCreate (no $previousRow) or afterUpdate, with the row
     * re-read through the store. Counts toward MAX_HOOK_DEPTH. Runs in one transaction.
     */
    public function afterEditorSave(string $collection, int|string $pk, ?array $previousRow, ?array $user = null): void
    {
        $this->asEditor($user, function () use ($collection, $pk, $previousRow) {
            $spec = $this->schema->collection($collection);
            [$row] = $this->readByPk($collection, $spec, $pk);
            $previousRow === null ? $this->hook($collection, $spec, 'afterCreate', $row) : $this->hook($collection, $spec, 'afterUpdate', $row, $previousRow);
            $this->published($collection, $spec, $row, $previousRow);
            Extensions::written($previousRow === null ? 'create' : 'update', $collection, $row, $previousRow);
        });
    }

    /** Before an editor-side delete: beforeDelete($row) and the pipeline FK actions. */
    public function beforeEditorDelete(string $collection, array $row, ?array $user = null): void
    {
        $pk = $this->pkValue($this->schema->collection($collection), $row);
        if (isset($this->deleting[$collection][$pk])) {
            return;
        }
        $this->deleting[$collection][$pk] = $pk;
        try {
            $this->asEditor($user, function () use ($collection, $row, $user) {
                $spec = $this->schema->collection($collection);
                $existing = $row;
                Extensions::write('delete', $collection, $row, $existing, null, $existing);
                $this->hook($collection, $spec, 'beforeDelete', $row);
                $this->referentialActions($collection, $row, $user === null ? null : new Viewer($user));
            });
        } finally {
            unset($this->deleting[$collection][$pk]);
        }
    }

    /** After an editor-side delete: afterDelete($row). */
    public function afterEditorDelete(string $collection, array $row, ?array $user = null): void
    {
        $this->asEditor($user, function () use ($collection, $row) {
            $this->hook($collection, $this->schema->collection($collection), 'afterDelete', $row);
            Extensions::written('delete', $collection, $row, $row);
        });
    }

    private function asEditor(?array $user, callable $fn): void
    {
        $prev = $this->actor;
        $this->actor = $user;
        try {
            $this->nested($fn);
        } finally {
            $this->actor = $prev;
        }
    }

    // ================================================================ revisions (§6.4)

    /** @return list<array{id: int, at: string, userId: ?int}> newest first; needs update access on the row */
    public function revisions(string $collection, int|string $pk, ?Viewer $viewer = null): array
    {
        $this->requireRevisable($collection, $pk, $viewer);

        return array_map(
            static fn (array $r) => ['id' => $r['id'], 'at' => $r['at'], 'userId' => $r['userId']],
            $this->current(fn () => $this->store->revisions($collection, $pk)),
        );
    }

    /** The full decoded row at a revision. */
    public function revision(string $collection, int|string $pk, int $revisionId, ?Viewer $viewer = null): array
    {
        return $this->current(fn (): array => $this->revisionAt($collection, $pk, $revisionId, $viewer));
    }

    private function revisionAt(string $collection, int|string $pk, int $revisionId, ?Viewer $viewer): array
    {
        $this->requireRevisable($collection, $pk, $viewer);

        return $this->present([$this->revisionRow($collection, $pk, $revisionId)], $collection, $viewer)[0]
            ?? throw HttpError::notFound("No revision $revisionId for $collection $pk");
    }

    /** The decoded row a revision (or autosave) holds, trusted: no access checks. */
    public function revisionRow(string $collection, int|string $pk, int $revisionId): array
    {
        $rev = $this->current(fn () => $this->store->revision($collection, $pk, $revisionId))
            ?? throw HttpError::notFound("No revision $revisionId for $collection $pk");

        return Schema::decodeRow($this->schema->collection($collection)['columns'], $rev['row']);
    }

    /**
     * Restore: the whole revision as stored, as $viewer, in one update. Fields the viewer can read are
     * its patch where the revision differs from the row, under every update and field rule. Fields
     * that may be hidden from it are written from the stored revision as trusted values, after its
     * rules pass: it sends nothing for them, so nothing about them can be probed, and a restore never
     * leaves part of the row at a later state. The answer is masked like every read.
     */
    public function restoreRevision(string $collection, int|string $pk, int $revisionId, ?Viewer $viewer = null): array
    {
        return $this->current(function () use ($collection, $pk, $revisionId, $viewer): array {
            $spec = $this->schema->collection($collection);
            $this->requireRevisable($collection, $pk, $viewer);
            $row = $this->revisionRow($collection, $pk, $revisionId);
            $opts = ['where' => [$spec['primaryKey'] => $pk]];
            $current = $this->selectDecoded($collection, $opts, '', null)[0] ?? throw HttpError::notFound("No $collection row $pk");
            $patch = $hidden = [];
            foreach ($spec['columns'] as $name => $col) {
                // A revision taken where links aren't kept with the row (WordPress revisions) leaves a
                // `refList` it holds as null as it is.
                if ($name === $spec['primaryKey'] || ($col['system'] ?? false) || isset($col['onUpdate'])
                    || ($col['type'] === 'refList' && $row[$name] === null)
                    || Js::jsonStringify($row[$name] ?? null) === Js::jsonStringify($current[$name] ?? null)) {
                    continue;
                }
                if ($viewer !== null && FieldAccess::mayHide($col, $viewer)) {
                    $hidden[$name] = $row[$name];
                } else {
                    $patch[$name] = $row[$name];
                }
            }
            if ($hidden === []) {
                return $this->update($collection, $patch, [$spec['primaryKey'] => $pk], true, $viewer)[0]
                    ?? throw HttpError::notFound("No $collection row $pk");
            }

            return $this->nested(function () use ($collection, $spec, $pk, $patch, $hidden, $viewer): array {
                $guards = $viewer === null ? [] : $this->writeGuards($collection, 'update', $viewer, $patch);
                $opts = ['where' => [$spec['primaryKey'] => $pk]];
                $existing = $this->selectDecoded($collection, $opts, '', null, null, $guards, $viewer)[0]
                    ?? throw HttpError::notFound("No $collection row $pk");
                $written = Js::atInstant(fn () => $this->updateOne($collection, $spec, $existing, $patch, $viewer, $hidden));

                return $this->present([$written], $collection, $viewer)[0];
            });
        });
    }

    private function requireRevisable(string $collection, int|string $pk, ?Viewer $viewer): void
    {
        $spec = $this->schema->collection($collection);
        if (!($spec['revisions'] ?? false)) {
            throw HttpError::notFound("$collection keeps no revisions");
        }
        $guards = $viewer === null ? [] : $this->writeGuards($collection, 'update', $viewer, null);
        $opts = ['where' => [$spec['primaryKey'] => $pk], 'limit' => 1];
        if ($this->selectDecoded($collection, $opts, '', null, null, $guards) === []) {
            throw HttpError::notFound("No $collection row $pk");
        }
    }

    // ================================================================ globals (§3.6)

    /** Current values (JS form); never-written fields read as their defaults. */
    public function globalGet(string $name, ?Viewer $viewer = null): array
    {
        return $this->current(function () use ($name, $viewer): array {
            $g = $this->schema->global($name);
            if ($viewer !== null) {
                Access::require(Access::globalRules($g)['read'], $viewer, 'read', null, "read $name");
            }
            Render\Reads::global($name);
            $stored = $this->store->globalRead($name) ?? [];
            $out = [];
            foreach ($g['fields'] as $field => $f) {
                if (array_key_exists($field, $stored)) {
                    $out[$field] = Schema::decode($f, $stored[$field]);
                } else {
                    $out[$field] = isset($f['default']) ? Schema::normalize($f, Schema::generate($f['default'], $f), (string) $field, '') : null;
                }
            }

            return $out;
        });
    }

    public function globalUpdate(string $name, array|JsObject $patch, ?Viewer $viewer = null): array
    {
        $patch = Seam::container($patch);
        $g = $this->schema->global($name);
        $patch = Seam::toPhp($patch, $g['fields']);
        if ($viewer !== null) {
            $this->current(fn () => Access::require(Access::globalRules($g)['update'], $viewer, 'update', $patch, "update $name", $g['fields']));
        }
        $fields = $g['fields'];
        $this->rejectUnknown($fields, $patch);

        // One instant: never-written fields' `now` defaults and the patch's nested defaults agree.
        return Js::atInstant(fn () => $this->transaction(function () use ($name, $fields, $patch, $viewer) {
            $current = $this->globalGet($name);
            $norm = [];
            foreach ($patch as $field => $v) {
                $norm[$field] = Schema::normalize($fields[$field], $v, (string) $field, HttpError::seg($field));
            }
            $this->checkValues($fields, $norm, $norm + $current, $viewer);
            $values = [];
            foreach ($norm + $current as $field => $v) {
                $values[$field] = Schema::store($fields[$field], $v);
            }
            $this->store->globalWrite($name, $values);

            return $this->globalGet($name);
        }));
    }

    // ================================================================ media (§4.6)

    public function mediaUpload(string $tmpPath, string $filename, ?string $alt, ?Viewer $viewer = null): array
    {
        if ($viewer !== null) {
            Access::require(Access::MEDIA['create'], $viewer, 'create', null, 'upload media');
        }

        return $this->transaction(fn () => $this->media->upload($tmpPath, $filename, $this->actor, $alt));
    }

    public function mediaGet(int $id): ?array
    {
        return $this->current(fn () => $this->media->get($id));
    }

    public function mediaList(array $opts): array
    {
        return $this->current(fn () => $this->media->list($opts));
    }

    /**
     * Delete a media row: null `media` columns and drop the id from `mediaList` columns first
     * (hooks + revisions apply). A platform's native transport may supply its own removal callback
     * to retain native response semantics and plugin hooks; a failure must throw to roll back.
     *
     * @param ?callable(): void $remove
     */
    public function mediaDelete(int $id, ?Viewer $viewer = null, ?callable $remove = null): ?array
    {
        if ($viewer !== null) {
            Access::require(Access::MEDIA['delete'], $viewer, 'delete', null, 'delete media');
        }

        return $this->nested(function () use ($id, $remove) {
            $row = $this->media->get($id);
            if ($row === null) {
                return null;
            }
            foreach ($this->schema->kinds() as $c => $spec) {
                foreach ($spec['columns'] as $col => $def) {
                    if ($def['type'] === 'media') {
                        $this->update((string) $c, [$col => null], [$col => $id]);
                    } elseif ($def['type'] === 'mediaList') {
                        $pk = $spec['primaryKey'];
                        $opts = ['where' => [$col => ['has' => $id]]];
                        foreach ($this->selectDecoded((string) $c, $opts, '', null) as $r) {
                            $kept = array_values(array_filter($r[$col], static fn ($x) => $x !== $id));
                            $this->update((string) $c, [$col => $kept], [$pk => $r[$pk]]);
                        }
                    }
                }
            }
            $remove === null ? $this->media->delete($id) : $remove();

            return $row;
        });
    }

    // ================================================================ helpers

    private static function key(mixed $v): string
    {
        return is_string($v) ? 's' . $v : 'n' . Js::str($v);
    }
}

function db(): Db
{
    return Db::instance();
}
