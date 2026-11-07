<?php

declare(strict_types=1);

namespace Zero;

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
    private const QUERY_OPTS = ['where', 'orderBy', 'limit', 'offset', 'columns', 'with'];

    private static ?self $instance = null;
    private static ?self $current = null;

    private int $depth = 0;
    private ?array $actor = null;
    /** @var list<BulkUpdate> open updates, innermost last */
    private array $bulk = [];

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
        return self::$current ?? self::$instance ?? throw new \LogicException('Zero\Db is not initialised; call App::boot() first');
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
        $decoded = $this->selectDecoded($collection, $opts, $ptr, $viewer, $partition, $guards);
        $rows = Extensions::read($decoded, $collection, $viewer);
        if (isset($opts['with']) && $rows !== []) {
            $rows = $this->attach($collection, $rows, $opts['with'], $ptr . '/with', $viewer, $decoded);
        }

        return $rows;
    }

    /**
     * Select full storage rows without presentation transforms; write guards remain SQL filters.
     * $filterViewer confines a write selector's relation filters to rows that viewer may read.
     */
    private function selectDecoded(string $collection, array &$opts, string $ptr, ?Viewer $viewer, ?array $partition = null, array $guards = [], ?Viewer $filterViewer = null): array
    {
        return $this->current(function () use ($collection, &$opts, $ptr, $viewer, $partition, $guards, $filterViewer): array {
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
                array_push($guards, ...$this->readGuards($collection, $viewer));
            }
            $filterViewer ??= $viewer;
            $q = Sql::select($this->schema, $this->store, $collection, $opts, $partition, $ptr, $guards,
                $filterViewer === null ? null : fn (string $target): array => $this->readGuards($target, $filterViewer));
            if ($partition !== null && $partition[1] === []) {
                return [];
            }
            return array_map(
                static fn (array $raw) => Schema::decodeRow($spec['columns'], $raw),
                $this->store->select($collection, $q),
            );
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
            $guards = $op === 'read' ? $this->readGuards($collection, $viewer) : $this->writeGuards($collection, $op, $viewer, $data);
        } catch (HttpError) {
            return false;
        }

        return $this->keysMatching($collection, [$this->schema->collection($collection)['primaryKey'] => $pk], $guards) !== [];
    }

    /**
     * The primary keys $viewer's read or update rule (+ draft visibility for read) covers, or null when
     * it covers every row. Throws 401/403 when the rule denies. Rows as stored: no locale or other
     * query extension narrows them.
     *
     * @return ?list<int|string>
     */
    public function permittedKeys(string $collection, string $op, Viewer $viewer): ?array
    {
        $guards = $op === 'read' ? $this->readGuards($collection, $viewer) : $this->writeGuards($collection, $op, $viewer, null);

        return $guards === [] ? null : $this->keysMatching($collection, null, $guards);
    }

    /** @return list<int|string> primary keys of rows matching $where and every guard, as stored */
    private function keysMatching(string $collection, ?array $where, array $guards): array
    {
        return $this->current(function () use ($collection, $where, $guards): array {
            $pk = $this->schema->collection($collection)['primaryKey'];
            $q = Sql::select($this->schema, $this->store, $collection, $where === null ? [] : ['where' => $where], null, '', $guards);

            return array_column($this->store->select($collection, $q), $pk);
        });
    }

    /** The read rule (+ draft visibility) as filters; throws 401/403 when the rule is false. */
    private function readGuards(string $collection, Viewer $viewer): array
    {
        $spec = $this->schema->collection($collection);
        $rule = Access::collectionRules($spec)['read'];
        $r = $this->current(fn () => Access::evaluate($rule, $viewer, 'read', null, $spec['columns'], $collection));
        if ($r === false) {
            throw Access::deny($viewer->user, "read $collection");
        }
        $guards = is_array($r) ? [$r] : [];
        if (($spec['drafts'] ?? false) && Access::isShorthand($rule)
            && ($viewer->user === null || Schema::roleRank($viewer->user['role']) < Schema::roleRank('editor'))) {
            $guards[] = ['status' => 'published'];
        }

        return [...$guards, ...$this->store->readGuards($collection, $viewer)];
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
            foreach ($rows as $row) {
                $this->deleteOne($collection, $row, $viewer);
            }

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
                $fn = '\\ZeroApp\\' . $slot;
                $jsCtx = Seam::ctx($ctx + ['data' => $data], $columns);
                $data = $jsCtx->p['data'];
                $result = $fn($data, $jsCtx);
                $data = Seam::rowToPhp($collection, $result instanceof JsObject ? $result : $data);
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

        $insert = [];
        foreach ($norm as $name => $v) {
            $insert[$name] = Schema::store($columns[$name], $v);
        }
        try {
            $pk = $this->store->insert($collection, $insert);
        } catch (\Throwable $e) {
            throw $this->store->mapError($e);
        }
        [$row, $raw] = $this->readByPk($collection, $spec, $pk);
        if ($spec['revisions'] ?? false) {
            $this->store->revisionWrite($collection, $pk, $raw, $this->actor['id'] ?? null);
        }
        $this->hook($collection, $spec, 'afterCreate', $row);
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
                $fn = '\\ZeroApp\\' . $slot;
                $jsCtx = Seam::ctx($ctx + ['data' => $patch], $columns);
                $patch = $jsCtx->p['data'];
                $result = $fn($patch, Seam::rowToJs($collection, $existing), $jsCtx);
                $patch = Seam::rowToPhp($collection, $result instanceof JsObject ? $result : $patch);
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
        $set = [];
        foreach ($norm as $name => $v) {
            if (!($publishing && $name === $slugCol) && $this->unchangedReadableUnique($collection, (string) $name, $v, $previous, $viewer ?? $errorViewer)) {
                continue; // WordPress meta uniqueness checks must not inspect competitors for a no-op.
            }
            $set[$name] = Schema::store($columns[$name], $v);
        }
        $this->requireIncomingVisibility($collection, $set, $viewer ?? $errorViewer);
        try {
            $this->store->update($collection, $pkValue, $set);
        } catch (\Throwable $e) {
            throw $this->store->mapError($e);
        }
        [$row, $raw] = $this->readByPk($collection, $spec, $pkValue);
        if ($spec['revisions'] ?? false) {
            $this->store->revisionWrite($collection, $pkValue, $raw, $this->actor['id'] ?? null);
        }
        $this->hook($collection, $spec, 'afterUpdate', $row, $previous);
        Extensions::written('update', $collection, $row, $previous);

        return $row;
    }

    private function deleteOne(string $collection, array $row, ?Viewer $viewer): void
    {
        $spec = $this->schema->collection($collection);
        $existing = $row;
        Extensions::write('delete', $collection, $row, $existing, $viewer, $existing);
        $this->hook($collection, $spec, 'beforeDelete', $row);
        $this->referentialActions($collection, $row, $viewer);
        try {
            $this->store->delete($collection, $this->pkValue($spec, $row));
        } catch (\Throwable $e) {
            throw $this->store->mapError($e);
        }
        $this->hook($collection, $spec, 'afterDelete', $row);
        Extensions::written('delete', $collection, $row, $existing);
    }

    /** Check the whole cascading graph before looking for any children, including empty branches. */
    private function requireDeleteVisibility(string $collection, ?Viewer $viewer, array $seen = []): void
    {
        if ($viewer === null || isset($seen[$collection])) {
            return;
        }
        $seen[$collection] = true;
        foreach ($this->schema->collections() as $child => $spec) {
            foreach ($spec['columns'] as $col => $def) {
                $ref = $def['references'] ?? null;
                if ($ref === null || $ref['collection'] !== $collection) {
                    continue;
                }
                $this->requireReferenceVisibility($collection, $ref['column'], (string) $child, $def, $viewer);
                $action = strtolower($ref['onDelete'] ?? 'no action');
                if ($action === 'cascade') {
                    $this->requireDeleteVisibility((string) $child, $viewer, $seen);
                } else {
                    if (in_array($action, ['set null', 'set default'], true)) {
                        // These writes run as referential effects, but their errors still belong to
                        // this viewer. Check potential generated values even on an empty branch.
                        $inputs = [$col => $action === 'set default' ? ($def['default'] ?? ['value' => null]) : ['value' => null]];
                        foreach ($spec['columns'] as $name => $column) {
                            if ($name !== $col && isset($column['onUpdate'])) {
                                $inputs[$name] = $column['onUpdate'];
                            }
                        }
                        if (isset($spec['hooks']['beforeUpdate'])) {
                            foreach ($spec['columns'] as $name => $column) {
                                if ($name !== $spec['primaryKey'] && (($column['unique'] ?? false) || isset($column['references']))) {
                                    $inputs[$name] = ['fn' => true]; // a hook may replace any constraint value
                                }
                            }
                        }
                        $this->requireGeneratedVisibility((string) $child, $inputs, $viewer);
                    }
                }
            }
        }
    }

    /** Incoming foreign keys can reject a key change or propagate it into another hidden branch. */
    private function requireIncomingVisibility(string $collection, array $written, ?Viewer $viewer, array $seen = []): void
    {
        $key = serialize([$collection, array_keys($written)]);
        if ($viewer === null || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        foreach ($this->schema->collections() as $child => $spec) {
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
        $this->requireDeleteVisibility($collection, $viewer);
        foreach ($this->schema->collections() as $child => $cspec) {
            foreach ($cspec['columns'] as $col => $c) {
                $ref = $c['references'] ?? null;
                if ($ref === null || $ref['collection'] !== $collection || $row[$ref['column']] === null) {
                    continue;
                }
                $where = [$col => $row[$ref['column']]];
                $opts = ['where' => $where, 'limit' => 1];
                $action = strtolower($ref['onDelete'] ?? 'no action');
                if ($action === 'cascade') {
                    $this->nested(function () use ($child, $where, $viewer): void {
                        $opts = ['where' => $where];
                        foreach ($this->selectDecoded((string) $child, $opts, '', null) as $dependent) {
                            $this->deleteOne((string) $child, $dependent, $viewer);
                        }
                    });
                } elseif ($action === 'set null' || $action === 'set default') {
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
            $missing = new HttpError(409, 'reference_violation', "No referenced {$ref['collection']} row for $name", HttpError::seg($name));
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
            if (!$readable || FieldAccess::mayHide($col, $viewer)) {
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

    private function rejectUnknown(array $columns, array $data): void
    {
        foreach ($data as $key => $_) {
            if (!isset($columns[$key])) {
                throw HttpError::invalidInput("Unknown field '$key'", HttpError::seg($key));
            }
        }
    }

    private function checkPatch(array $spec, array $existing, array $patch): void
    {
        $this->rejectUnknown($spec['columns'], $patch);
        $pk = $spec['primaryKey'];
        if (array_key_exists($pk, $patch) && !Js::strictEq($patch[$pk], $existing[$pk])) {
            throw HttpError::invalidInput('The primary key cannot be changed', HttpError::seg($pk));
        }
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
            $fn = '\\ZeroApp\\' . $slot;
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
        $raw = $this->store->select($collection, $q)[0] ?? throw new \RuntimeException("Row $collection/$pk vanished after write");
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
            Extensions::written($previousRow === null ? 'create' : 'update', $collection, $row, $previousRow);
        });
    }

    /** Before an editor-side delete: beforeDelete($row) and the pipeline FK actions. */
    public function beforeEditorDelete(string $collection, array $row, ?array $user = null): void
    {
        $this->asEditor($user, function () use ($collection, $row, $user) {
            $spec = $this->schema->collection($collection);
            $existing = $row;
            Extensions::write('delete', $collection, $row, $existing, null, $existing);
            $this->hook($collection, $spec, 'beforeDelete', $row);
            $this->referentialActions($collection, $row, $user === null ? null : new Viewer($user));
        });
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
     * Restore: the revision's user columns become an update patch (pk, system columns and onUpdate
     * columns excluded), run through the full update pipeline.
     */
    public function restoreRevision(string $collection, int|string $pk, int $revisionId, ?Viewer $viewer = null): array
    {
        $spec = $this->schema->collection($collection);
        $this->requireRevisable($collection, $pk, $viewer);
        $row = $this->revisionRow($collection, $pk, $revisionId);
        $patch = [];
        foreach ($spec['columns'] as $name => $col) {
            if ($name !== $spec['primaryKey'] && !($col['system'] ?? false) && !isset($col['onUpdate'])) {
                $patch[$name] = $row[$name];
            }
        }

        return $this->update($collection, $patch, [$spec['primaryKey'] => $pk], true, $viewer)[0]
            ?? throw HttpError::notFound("No $collection row $pk");
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
            foreach ($this->schema->collections() as $c => $spec) {
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
