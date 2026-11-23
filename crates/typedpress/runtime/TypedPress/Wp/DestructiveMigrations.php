<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;
use TypedPress\Js;

/**
 * Opt-in CLI-only destructive migration provider (`typedpress/danger`, ADR 2026-11-21 §3).
 *
 * Core TypedPress migrations are strictly forward-only and never drop columns, drop tables, or purge
 * data. This provider plugs into {@see Migrator}'s provider registry only when the app opts in via
 * `use: [destructiveMigrations()]` in `typedpress.config.ts`, and enforces:
 *   1. CLI-only execution (refused on HTTP requests and plugin activation).
 *   2. Two-phase retirement prerequisite (columns/tables must already be retired in `ops.json`).
 *   3. Exact live row / non-null value count confirmation (`--confirm-rows=<N>`).
 *   4. Optional (or mandatory when `requireArchive: true`) JSONL archival via `--archive-to=<path>`.
 *   5. Immutable audit rows in `{prefix}tp__migrations` with `kind = 'destructive'`, `actor`, `reason`, `rows_affected`.
 */
final class DestructiveMigrations implements MigrationProvider
{
    public const PROVIDER_ID = 'destructive-migrations';

    /**
     * @param array{id?: string, cliOnly?: bool, requireArchive?: bool, allowRawSql?: bool} $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function id(): string
    {
        return self::PROVIDER_ID;
    }

    public function cliOnly(): bool
    {
        return ($this->config['cliOnly'] ?? true) !== false;
    }

    public function describe(): string
    {
        return 'CLI-only destructive cleanup escape hatch (drop retired columns/tables, purge soft-deleted rows, audited raw SQL)';
    }

    /** Register this provider with Migrator when the app's compiled schema opted into `typedpress/danger`. */
    public static function registerFor(Plugin $plugin): ?self
    {
        $cfg = self::providerConfig($plugin);
        if ($cfg === null) {
            return null;
        }
        $provider = new self($cfg);
        Migrator::registerProvider($provider);

        return $provider;
    }

    /** @return ?array{id: string, cliOnly: bool, requireArchive: bool, allowRawSql: bool} */
    public static function providerConfig(Plugin $plugin): ?array
    {
        $providers = $plugin->schema->raw()['migrationProviders'] ?? null;
        if (!is_array($providers) || !isset($providers[self::PROVIDER_ID]) || !is_array($providers[self::PROVIDER_ID])) {
            return null;
        }
        $cfg = $providers[self::PROVIDER_ID];

        return [
            'id' => self::PROVIDER_ID,
            'cliOnly' => ($cfg['cliOnly'] ?? true) !== false,
            'requireArchive' => (bool) ($cfg['requireArchive'] ?? false),
            'allowRawSql' => (bool) ($cfg['allowRawSql'] ?? false),
        ];
    }

    /** Refuse unless running under WP-CLI (or explicit CLI test harness under CLI SAPI). */
    public static function requireCli(bool $cliContext = false): void
    {
        $isWpCli = defined('WP_CLI') && WP_CLI;
        $isCliTest = $cliContext && PHP_SAPI === 'cli';
        if (!$isWpCli && !$isCliTest) {
            throw new HttpError(
                403,
                'cli_only',
                'Destructive migrations are CLI-only and are refused during HTTP requests and plugin activation. Run via `wp typedpress danger` or `typedpress danger`.',
            );
        }
    }

    /** @return array{id: string, cliOnly: bool, requireArchive: bool, allowRawSql: bool} */
    public static function requireEnabled(Plugin $plugin): array
    {
        $cfg = self::providerConfig($plugin);
        if ($cfg === null) {
            throw new HttpError(
                403,
                'destructive_migrations_disabled',
                'Destructive migrations are not enabled for this app. Import `destructiveMigrations` from `typedpress/danger` and opt in via `defineConfig({ use: [destructiveMigrations()] })`.',
            );
        }

        return $cfg;
    }

    /**
     * Fold all `<id>/ops.json` folders in sorted order to compute retired columns and retired tables
     * eligible for two-phase destructive cleanup.
     *
     * @return array{
     *   retiredColumns: array<string, array{table: string, column: string, retiredIn: string, def: array}>,
     *   retiredTables: array<string, array{table: string, retiredIn: string}>,
     *   tableStorageNames: array<string, string>,
     * }
     */
    public static function retiredState(string $migrationsDir): array
    {
        $files = glob(rtrim($migrationsDir, '/') . '/*/ops.json') ?: [];
        $byId = [];
        foreach ($files as $file) {
            $id = basename(dirname($file));
            if ($id !== '' && $id !== '.' && $id !== '..') {
                $byId[$id] = $file;
            }
        }
        ksort($byId, SORT_STRING);

        $retiredColumns = [];
        $retiredTables = [];
        $physicalNames = [];

        foreach ($byId as $id => $file) {
            $doc = json_decode((string) file_get_contents($file), true);
            if (!is_array($doc) || !is_array($doc['ops'] ?? null)) {
                continue;
            }
            foreach ($doc['ops'] as $op) {
                if (!is_array($op) || !is_string($op['op'] ?? null)) {
                    continue;
                }
                $t = is_string($op['table'] ?? null) ? $op['table'] : '';
                switch ($op['op']) {
                    case 'createTable':
                        if ($t !== '') {
                            $def = is_array($op['def'] ?? null) ? $op['def'] : [];
                            if (is_string($op['physicalTable'] ?? null) && $op['physicalTable'] !== '') {
                                $physicalNames[$t] = $op['physicalTable'];
                            } elseif (is_string($def['existingTable'] ?? null) && $def['existingTable'] !== '') {
                                $physicalNames[$t] = $def['existingTable'];
                            } elseif (is_array($def['join'] ?? null)) {
                                $physicalNames[$t] = '{prefix}tp__join_' . $t;
                            } else {
                                $storage = is_string($def['storageName'] ?? null) && $def['storageName'] !== '' ? $def['storageName'] : $t;
                                $physicalNames[$t] = '{prefix}tp_' . $storage;
                            }
                            unset($retiredTables[$t]);
                        }
                        break;
                    case 'retireColumn':
                        $c = is_string($op['column'] ?? null) ? $op['column'] : '';
                        if ($t !== '' && $c !== '') {
                            $retiredColumns["$t.$c"] = [
                                'table' => $t,
                                'column' => $c,
                                'retiredIn' => (string) $id,
                                'def' => is_array($op['was'] ?? null) ? $op['was'] : [],
                            ];
                        }
                        break;
                    case 'unretireColumn':
                    case 'purgeRetiredColumn':
                        $c = is_string($op['column'] ?? null) ? $op['column'] : '';
                        unset($retiredColumns["$t.$c"]);
                        break;
                    case 'retireTable':
                        if ($t !== '') {
                            if (is_string($op['physicalTable'] ?? null) && $op['physicalTable'] !== '') {
                                $physicalNames[$t] = $op['physicalTable'];
                            }
                            $retiredTables[$t] = ['table' => $t, 'retiredIn' => (string) $id];
                            foreach (array_keys($retiredColumns) as $k) {
                                if (str_starts_with($k, "$t.")) {
                                    unset($retiredColumns[$k]);
                                }
                            }
                        }
                        break;
                    case 'unretireTable':
                    case 'purgeRetiredTable':
                        unset($retiredTables[$t]);
                        break;
                }
            }
        }

        return [
            'retiredColumns' => $retiredColumns,
            'retiredTables' => $retiredTables,
            'tableStorageNames' => $physicalNames,
        ];
    }

    /** Inspect retired columns, retired tables, live counts, and destructive audit history. */
    public static function status(Plugin $plugin, ?string $migrationsDir = null, bool $cliContext = false): array
    {
        self::requireCli($cliContext);
        $cfg = self::requireEnabled($plugin);
        $dir = $migrationsDir ?? ($plugin->dir . '/tp/app/migrations');
        $state = self::retiredState($dir);
        $conn = $plugin->connection();

        $columns = [];
        foreach ($state['retiredColumns'] as $info) {
            $physTable = self::physicalTable($plugin, $info['table'], $state['tableStorageNames']);
            $live = self::columnExists($conn, $physTable, $info['column'])
                ? self::countNonNull($conn, $physTable, $info['column'])
                : null;
            $columns[] = $info + ['physicalTable' => $physTable, 'nonNullRows' => $live, 'presentInDb' => $live !== null];
        }

        $tables = [];
        foreach ($state['retiredTables'] as $info) {
            $physTable = self::physicalTable($plugin, $info['table'], $state['tableStorageNames']);
            $live = self::tableExists($conn, $physTable) ? self::countRows($conn, $physTable) : null;
            $tables[] = $info + ['physicalTable' => $physTable, 'rows' => $live, 'presentInDb' => $live !== null];
        }

        $audit = [];
        $ledger = $conn->prefix() . 'tp__migrations';
        if (self::tableExists($conn, $ledger)) {
            $audit = $conn->run(
                "SELECT `id`, `kind`, `applied_at`, `sql`, `actor`, `reason`, `rows_affected` FROM `$ledger` WHERE `app` = ? AND `kind` = 'destructive' ORDER BY `id` DESC LIMIT 50",
                [$plugin->schema->name()],
            );
        }

        return [
            'provider' => $cfg,
            'retiredColumns' => $columns,
            'retiredTables' => $tables,
            'audit' => $audit,
        ];
    }

    /**
     * Drop a previously retired column after verifying two-phase retirement, live non-null count,
     * optional/mandatory JSONL archival, and recording a destructive audit row in `tp__migrations`.
     *
     * @param array{confirmRows?: ?int, reason?: ?string, archiveTo?: ?string, actor?: ?string, dryRun?: bool} $opts
     */
    public static function dropColumn(
        Plugin $plugin,
        string $table,
        string $column,
        array $opts = [],
        ?string $migrationsDir = null,
        bool $cliContext = false,
    ): array {
        self::requireCli($cliContext);
        $cfg = self::requireEnabled($plugin);
        $dir = $migrationsDir ?? ($plugin->dir . '/tp/app/migrations');
        $state = self::retiredState($dir);

        if (!isset($state['retiredColumns']["$table.$column"])) {
            throw new HttpError(
                409,
                'not_retired',
                "Refusing to drop active column `$table.$column`: two-phase retirement is required. Remove `$column` from `typedpress.config.ts` and deploy a forward-only retirement migration first.",
            );
        }

        $conn = $plugin->connection();
        $retiredIn = $state['retiredColumns']["$table.$column"]['retiredIn'];
        $physTable = self::physicalTable($plugin, $table, $state['tableStorageNames']);
        if (self::isActivePhysicalColumn($plugin, $physTable, $column)) {
            throw new HttpError(
                409,
                'active_column',
                "Refusing to drop column `$physTable.$column`: it is still bound by an active collection in the compiled schema.",
            );
        }
        if (!self::columnExists($conn, $physTable, $column)) {
            throw HttpError::notFound("Column `$physTable.$column` does not exist in the database (already dropped).");
        }

        $liveCount = self::countNonNull($conn, $physTable, $column);
        $qTable = '`' . str_replace('`', '``', $physTable) . '`';
        $qCol = '`' . str_replace('`', '``', $column) . '`';
        $ddl = "ALTER TABLE $qTable DROP COLUMN $qCol;";

        if (($opts['dryRun'] ?? false) === true) {
            return [
                'dryRun' => true,
                'warning' => "DESTRUCTIVE: Will permanently drop column `$physTable.$column` ($liveCount non-null values).",
                'table' => $table,
                'physicalTable' => $physTable,
                'column' => $column,
                'nonNullRows' => $liveCount,
                'sql' => $ddl,
                'confirmFlag' => "--confirm-rows=$liveCount",
            ];
        }

        self::requireAppliedRetirement($plugin, $retiredIn, "column `$table.$column`");
        self::requireConfirmation($liveCount, $opts['confirmRows'] ?? null, "dropping column `$physTable.$column` ($liveCount non-null values)");
        $reason = self::requireReason($opts['reason'] ?? null);
        $archivePath = self::resolveArchive($cfg, $opts['archiveTo'] ?? null);

        return Migrator::locked($conn, $plugin->schemaLock(), static function () use ($plugin, $conn, $table, $physTable, $column, $qTable, $qCol, $ddl, $opts, $reason, $archivePath): array {
            $liveCount = self::countNonNull($conn, $physTable, $column);
            self::requireConfirmation($liveCount, $opts['confirmRows'] ?? null, "dropping column `$physTable.$column` ($liveCount non-null values)");
            $archived = 0;
            if ($archivePath !== null) {
                $rows = $conn->run("SELECT * FROM $qTable WHERE $qCol IS NOT NULL");
                $archived = self::writeJsonl($archivePath, $rows);
            }
            $id = self::beginAudit($plugin, "drop_col_{$table}_{$column}", $ddl, $opts['actor'] ?? null, $reason, $liveCount);
            $conn->run($ddl);
            self::finishAudit($plugin, $id, $liveCount);

            return [
                'id' => $id,
                'kind' => 'destructive',
                'operation' => 'drop-column',
                'table' => $table,
                'physicalTable' => $physTable,
                'column' => $column,
                'rowsAffected' => $liveCount,
                'archived' => $archivePath !== null ? ['path' => $archivePath, 'rows' => $archived] : null,
                'sql' => $ddl,
            ];
        });
    }

    /**
     * Drop a previously retired SQL table after verifying two-phase retirement, live row count,
     * optional/mandatory JSONL archival, and recording a destructive audit row in `tp__migrations`.
     *
     * @param array{confirmRows?: ?int, reason?: ?string, archiveTo?: ?string, actor?: ?string, dryRun?: bool} $opts
     */
    public static function dropTable(
        Plugin $plugin,
        string $table,
        array $opts = [],
        ?string $migrationsDir = null,
        bool $cliContext = false,
    ): array {
        self::requireCli($cliContext);
        $cfg = self::requireEnabled($plugin);
        $dir = $migrationsDir ?? ($plugin->dir . '/tp/app/migrations');
        $state = self::retiredState($dir);

        if ($plugin->schema->hasCollection($table) || !isset($state['retiredTables'][$table])) {
            throw new HttpError(
                409,
                'not_retired',
                "Refusing to drop active table `$table`: two-phase retirement is required. Remove `$table` from `typedpress.config.ts` and deploy a forward-only retirement migration first.",
            );
        }

        $conn = $plugin->connection();
        $retiredIn = $state['retiredTables'][$table]['retiredIn'];
        $physTable = self::physicalTable($plugin, $table, $state['tableStorageNames']);
        if (self::isActivePhysicalTable($plugin, $physTable)) {
            throw new HttpError(
                409,
                'active_table',
                "Refusing to drop table `$physTable`: it is still owned by an active collection in the compiled schema.",
            );
        }
        $isManaged = str_starts_with($physTable, $conn->prefix() . 'tp_') && !str_starts_with($physTable, $conn->prefix() . 'tp__');
        $isJoin = str_starts_with($physTable, $conn->prefix() . 'tp__join_');
        if (!$isManaged && !$isJoin) {
            throw new HttpError(403, 'protected_table', "Refusing to drop non-app or system table `$physTable`.");
        }
        if (!self::tableExists($conn, $physTable)) {
            throw HttpError::notFound("Table `$physTable` does not exist in the database (already dropped).");
        }

        $liveCount = self::countRows($conn, $physTable);
        $qTable = '`' . str_replace('`', '``', $physTable) . '`';
        $ddl = "DROP TABLE $qTable;";

        if (($opts['dryRun'] ?? false) === true) {
            return [
                'dryRun' => true,
                'warning' => "DESTRUCTIVE: Will permanently drop table `$physTable` ($liveCount rows).",
                'table' => $table,
                'physicalTable' => $physTable,
                'rows' => $liveCount,
                'sql' => $ddl,
                'confirmFlag' => "--confirm-rows=$liveCount",
            ];
        }

        self::requireAppliedRetirement($plugin, $retiredIn, "table `$table`");
        self::requireConfirmation($liveCount, $opts['confirmRows'] ?? null, "dropping table `$physTable` ($liveCount rows)");
        $reason = self::requireReason($opts['reason'] ?? null);
        $archivePath = self::resolveArchive($cfg, $opts['archiveTo'] ?? null);

        return Migrator::locked($conn, $plugin->schemaLock(), static function () use ($plugin, $conn, $table, $physTable, $qTable, $ddl, $opts, $reason, $archivePath): array {
            $liveCount = self::countRows($conn, $physTable);
            self::requireConfirmation($liveCount, $opts['confirmRows'] ?? null, "dropping table `$physTable` ($liveCount rows)");
            $archived = 0;
            if ($archivePath !== null) {
                $rows = $conn->run("SELECT * FROM $qTable");
                $archived = self::writeJsonl($archivePath, $rows);
            }
            $id = self::beginAudit($plugin, "drop_tbl_{$table}", $ddl, $opts['actor'] ?? null, $reason, $liveCount);
            $conn->run($ddl);
            self::finishAudit($plugin, $id, $liveCount);

            return [
                'id' => $id,
                'kind' => 'destructive',
                'operation' => 'drop-table',
                'table' => $table,
                'physicalTable' => $physTable,
                'rowsAffected' => $liveCount,
                'archived' => $archivePath !== null ? ['path' => $archivePath, 'rows' => $archived] : null,
                'sql' => $ddl,
            ];
        });
    }

    /**
     * Permanently purge soft-deleted rows of a kind (optionally filtered by `--older-than=<duration>`),
     * with live count confirmation, optional/mandatory archival, and an audit row in `tp__migrations`.
     *
     * @param array{olderThan?: ?string, confirmRows?: ?int, reason?: ?string, archiveTo?: ?string, actor?: ?string, dryRun?: bool} $opts
     */
    public static function purgeDeleted(
        Plugin $plugin,
        string $kind,
        array $opts = [],
        bool $cliContext = false,
    ): array {
        self::requireCli($cliContext);
        $cfg = self::requireEnabled($plugin);
        if (!$plugin->schema->hasCollection($kind)) {
            throw HttpError::notFound("Unknown kind `$kind`.");
        }
        $spec = $plugin->schema->collection($kind);
        if (!($spec['softDelete'] ?? false)) {
            throw HttpError::invalidInput("Kind `$kind` does not use soft deletes.");
        }

        $query = ['deleted' => 'only', 'limit' => 10000];
        $olderThan = $opts['olderThan'] ?? null;
        if (is_string($olderThan) && $olderThan !== '') {
            $seconds = self::parseDuration($olderThan);
            $cutoff = Js::iso(Js::now() - ($seconds * 1000));
            $query['where'] = ['deletedAt' => ['lte' => $cutoff]];
        }

        $db = $plugin->db();
        $rows = $db->findMany($kind, $query);
        $liveCount = count($rows);

        if (($opts['dryRun'] ?? false) === true) {
            return [
                'dryRun' => true,
                'warning' => "DESTRUCTIVE: Will permanently purge $liveCount soft-deleted row(s) from `$kind`.",
                'kind' => $kind,
                'rows' => $liveCount,
                'olderThan' => $olderThan,
                'confirmFlag' => "--confirm-rows=$liveCount",
            ];
        }

        self::requireConfirmation($liveCount, $opts['confirmRows'] ?? null, "purging $liveCount soft-deleted rows from `$kind`");
        $reason = self::requireReason($opts['reason'] ?? null);
        $archivePath = self::resolveArchive($cfg, $opts['archiveTo'] ?? null);
        $conn = $plugin->connection();
        $cutoff = isset($query['where']['deletedAt']['lte']) ? (string) $query['where']['deletedAt']['lte'] : null;

        return Migrator::locked($conn, $plugin->schemaLock(), static function () use ($plugin, $db, $kind, $spec, $query, $olderThan, $cutoff, $opts, $reason, $archivePath): array {
            $rows = $db->findMany($kind, $query);
            $liveCount = count($rows);
            self::requireConfirmation($liveCount, $opts['confirmRows'] ?? null, "purging $liveCount soft-deleted rows from `$kind`");

            $archived = 0;
            if ($archivePath !== null) {
                $archived = self::writeJsonl($archivePath, $rows);
            }

            $summarySql = "-- purge-deleted $kind ($liveCount rows" . ($olderThan ? ", olderThan=$olderThan" : '') . ')';
            $id = self::beginAudit($plugin, "purge_{$kind}", $summarySql, $opts['actor'] ?? null, $reason, $liveCount);
            $pk = $spec['primaryKey'];
            $purged = 0;
            foreach ($rows as $row) {
                $where = [$pk => $row[$pk]];
                if ($cutoff !== null) {
                    $where['deletedAt'] = ['lte' => $cutoff];
                }
                $gone = $db->transaction(static fn (): array => $db->purge($kind, $where, returning: true, onlyDeleted: true) ?? []);
                $purged += count($gone);
            }
            self::finishAudit($plugin, $id, $purged);

            return [
                'id' => $id,
                'kind' => 'destructive',
                'operation' => 'purge-deleted',
                'collection' => $kind,
                'rowsAffected' => $purged,
                'archived' => $archivePath !== null ? ['path' => $archivePath, 'rows' => $archived] : null,
            ];
        });
    }

    /**
     * Execute an explicit raw SQL escape-hatch statement (only when `allowRawSql: true`), audited in `tp__migrations`.
     *
     * @param array{confirmRows?: ?int, reason?: ?string, actor?: ?string, dryRun?: bool} $opts
     */
    public static function rawSql(
        Plugin $plugin,
        string $sql,
        array $opts = [],
        bool $cliContext = false,
    ): array {
        self::requireCli($cliContext);
        $cfg = self::requireEnabled($plugin);
        if (!$cfg['allowRawSql']) {
            throw new HttpError(
                403,
                'raw_sql_disabled',
                'Raw SQL escape hatch is disabled. Enable it explicitly via `destructiveMigrations({ allowRawSql: true })`.',
            );
        }
        $trimmed = trim($sql);
        if ($trimmed === '') {
            throw HttpError::invalidInput('--sql must be a non-empty SQL statement');
        }
        $conn = $plugin->connection();
        $rendered = str_replace('{prefix}', $conn->prefix(), $trimmed);
        if (($opts['dryRun'] ?? false) === true) {
            return [
                'dryRun' => true,
                'warning' => 'DESTRUCTIVE: Will execute raw SQL directly against the site database.',
                'sql' => $rendered,
            ];
        }
        $reason = self::requireReason($opts['reason'] ?? null);

        return Migrator::locked($conn, $plugin->schemaLock(), static function () use ($plugin, $conn, $trimmed, $rendered, $opts, $reason): array {
            $id = self::beginAudit($plugin, 'raw_sql', $trimmed, $opts['actor'] ?? null, $reason, 0);
            $conn->run($rendered);
            $rowsAffected = max(0, $conn->changes());
            self::finishAudit($plugin, $id, $rowsAffected);

            return [
                'id' => $id,
                'kind' => 'destructive',
                'operation' => 'raw-sql',
                'sql' => $rendered,
                'rowsAffected' => $rowsAffected,
            ];
        });
    }

    /**
     * WP-CLI entry point: `wp typedpress danger <status|plan|drop-column|drop-table|purge-deleted|raw-sql>`.
     *
     * @param list<string> $args
     * @param array<string, mixed> $flags
     * @return array{int, string}
     */
    public static function wpCli(Plugin $plugin, array $args, array $flags, bool $cliContext = false): array
    {
        try {
            $sub = $args[0] ?? 'status';
            $opts = [
                'confirmRows' => isset($flags['confirm-rows']) && is_numeric($flags['confirm-rows']) ? (int) $flags['confirm-rows'] : null,
                'reason' => is_string($flags['reason'] ?? null) ? $flags['reason'] : null,
                'archiveTo' => is_string($flags['archive-to'] ?? null) ? $flags['archive-to'] : null,
                'actor' => is_string($flags['actor'] ?? null) ? $flags['actor'] : null,
                'olderThan' => is_string($flags['older-than'] ?? null) ? $flags['older-than'] : null,
                'dryRun' => ($flags['dry-run'] ?? false) === true || ($flags['dry-run'] ?? '') === 'true',
            ];
            $migrationsDir = is_string($flags['migrations-dir'] ?? null) ? $flags['migrations-dir'] : null;

            $data = match ($sub) {
                'status', 'plan' => self::status($plugin, $migrationsDir, $cliContext),
                'drop-column' => self::dropColumn(
                    $plugin,
                    $args[1] ?? throw HttpError::invalidInput('Usage: wp typedpress danger drop-column <table> <column> --confirm-rows=<N> --reason="..."'),
                    $args[2] ?? throw HttpError::invalidInput('Usage: wp typedpress danger drop-column <table> <column> --confirm-rows=<N> --reason="..."'),
                    $opts,
                    $migrationsDir,
                    $cliContext,
                ),
                'drop-table' => self::dropTable(
                    $plugin,
                    $args[1] ?? throw HttpError::invalidInput('Usage: wp typedpress danger drop-table <table> --confirm-rows=<N> --reason="..."'),
                    $opts,
                    $migrationsDir,
                    $cliContext,
                ),
                'purge-deleted' => self::purgeDeleted(
                    $plugin,
                    $args[1] ?? throw HttpError::invalidInput('Usage: wp typedpress danger purge-deleted <kind> --confirm-rows=<N> --reason="..."'),
                    $opts,
                    $cliContext,
                ),
                'raw-sql' => self::rawSql(
                    $plugin,
                    is_string($flags['sql'] ?? null) ? $flags['sql'] : throw HttpError::invalidInput('Usage: wp typedpress danger raw-sql --sql="<statement>" --reason="..."'),
                    $opts,
                    $cliContext,
                ),
                default => throw HttpError::invalidInput("Unknown danger subcommand `$sub`. Use status, plan, drop-column, drop-table, purge-deleted, or raw-sql."),
            };

            return [0, (string) Js::jsonStringify(['data' => $data])];
        } catch (\Throwable $e) {
            return [1, (string) Js::jsonStringify(['error' => \TypedPress\App::problem($e)])];
        }
    }

    // ================================================================ internals

    private static function physicalTable(Plugin $plugin, string $table, array $physicalNames): string
    {
        $prefix = $plugin->connection()->prefix();
        if (isset($physicalNames[$table]) && is_string($physicalNames[$table]) && $physicalNames[$table] !== '') {
            return str_replace('{prefix}', $prefix, $physicalNames[$table]);
        }
        if ($plugin->schema->hasCollection($table)) {
            $spec = $plugin->schema->collection($table);
            if (is_string($spec['existingTable'] ?? null) && $spec['existingTable'] !== '') {
                return $spec['existingTable'];
            }
            if (is_string($spec['table'] ?? null) && $spec['table'] !== '') {
                return $prefix . $spec['table'];
            }
        }

        // Compiler always prepends `tp_` for managed tables (even when storageName starts with `tp_`).
        return $prefix . 'tp_' . $table;
    }

    private static function isActivePhysicalTable(Plugin $plugin, string $physTable): bool
    {
        $prefix = $plugin->connection()->prefix();
        foreach ($plugin->schema->collections() as $spec) {
            $owned = null;
            if (is_string($spec['existingTable'] ?? null) && $spec['existingTable'] !== '') {
                $owned = $spec['existingTable'];
            } elseif (is_string($spec['table'] ?? null) && $spec['table'] !== '') {
                $owned = $prefix . $spec['table'];
            }
            if ($owned !== null && strcasecmp($owned, $physTable) === 0) {
                return true;
            }
            foreach ($spec['columns'] ?? [] as $col) {
                if (($col['type'] ?? null) === 'refList' && is_string($col['references']['join'] ?? null)) {
                    $join = $col['references']['join'];
                    if (!str_starts_with($join, 'term:') && strcasecmp($prefix . 'tp__join_' . $join, $physTable) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function isActivePhysicalColumn(Plugin $plugin, string $physTable, string $column): bool
    {
        $prefix = $plugin->connection()->prefix();
        foreach ($plugin->schema->collections() as $spec) {
            $owned = null;
            if (is_string($spec['existingTable'] ?? null) && $spec['existingTable'] !== '') {
                $owned = $spec['existingTable'];
            } elseif (is_string($spec['table'] ?? null) && $spec['table'] !== '') {
                $owned = $prefix . $spec['table'];
            }
            if ($owned === null || strcasecmp($owned, $physTable) !== 0) {
                continue;
            }
            foreach ($spec['columns'] ?? [] as $logical => $col) {
                $phys = is_string($col['column'] ?? null) && $col['column'] !== '' ? $col['column'] : (string) $logical;
                if (strcasecmp($phys, $column) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function requireAppliedRetirement(Plugin $plugin, string $retiredIn, string $target): void
    {
        $conn = $plugin->connection();
        $ledger = $conn->prefix() . 'tp__migrations';
        if (!self::tableExists($conn, $ledger)) {
            throw new HttpError(
                409,
                'retirement_not_applied',
                "Refusing to drop $target: retirement migration `$retiredIn` has not been applied to this database yet. Run `wp typedpress migrate` first.",
            );
        }
        $rows = $conn->run(
            "SELECT 1 FROM `$ledger` WHERE `app` = ? AND `id` = ? LIMIT 1",
            [$plugin->schema->name(), $retiredIn],
        );
        if ($rows === []) {
            throw new HttpError(
                409,
                'retirement_not_applied',
                "Refusing to drop $target: retirement migration `$retiredIn` has not been applied to this database yet. Run `wp typedpress migrate` first.",
            );
        }
    }

    private static function tableExists(Connection $conn, string $table): bool
    {
        return $conn->run(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        ) !== [];
    }

    private static function columnExists(Connection $conn, string $table, string $column): bool
    {
        if (!self::tableExists($conn, $table)) {
            return false;
        }
        $q = '`' . str_replace('`', '``', $table) . '`';
        foreach ($conn->run("SHOW COLUMNS FROM $q") as $col) {
            if (strcasecmp((string) ($col['Field'] ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function countRows(Connection $conn, string $table): int
    {
        $q = '`' . str_replace('`', '``', $table) . '`';

        return (int) ($conn->run("SELECT COUNT(*) AS n FROM $q")[0]['n'] ?? 0);
    }

    private static function countNonNull(Connection $conn, string $table, string $column): int
    {
        $qTable = '`' . str_replace('`', '``', $table) . '`';
        $qCol = '`' . str_replace('`', '``', $column) . '`';

        return (int) ($conn->run("SELECT COUNT(*) AS n FROM $qTable WHERE $qCol IS NOT NULL")[0]['n'] ?? 0);
    }

    private static function requireConfirmation(int $liveCount, ?int $confirmed, string $action): void
    {
        if ($confirmed === null || $confirmed !== $liveCount) {
            $given = $confirmed === null ? 'none' : (string) $confirmed;
            throw new HttpError(
                409,
                'confirmation_mismatch',
                "DESTRUCTIVE OPERATION REFUSED: $action requires exact row-count confirmation. Live count is $liveCount (got $given). Re-run with `--confirm-rows=$liveCount` after verifying backups.",
            );
        }
    }

    private static function requireReason(?string $reason): string
    {
        $trimmed = trim((string) $reason);
        if ($trimmed === '') {
            throw HttpError::invalidInput('A non-empty `--reason="..."` is required for the destructive migration audit trail.', '/reason');
        }

        return $trimmed;
    }

    /** @param array{requireArchive: bool} $cfg */
    private static function resolveArchive(array $cfg, ?string $archiveTo): ?string
    {
        $path = trim((string) $archiveTo);
        if ($path === '') {
            if ($cfg['requireArchive']) {
                throw new HttpError(
                    422,
                    'archive_required',
                    'This app configured `destructiveMigrations({ requireArchive: true })`: pass `--archive-to=/path/to/backup.jsonl` before executing.',
                );
            }

            return null;
        }

        return $path;
    }

    private static function writeJsonl(string $path, array $rows): int
    {
        if (str_contains($path, '://') || str_contains($path, "\0")) {
            throw HttpError::invalidInput("Archive path must be a local filesystem path: `$path`.", '/archive-to');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create archive directory `$dir`.");
        }
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            throw new \RuntimeException("Could not create archive file `$path` (it already exists or is not writable). Choose a fresh archive filename.");
        }
        try {
            foreach ($rows as $row) {
                $line = (string) Js::jsonStringify($row) . "\n";
                $written = fwrite($fh, $line);
                if ($written !== strlen($line)) {
                    throw new \RuntimeException("Short write while archiving to `$path`.");
                }
            }
            if (!fflush($fh)) {
                throw new \RuntimeException("Could not flush archive file `$path`.");
            }
        } catch (\Throwable $e) {
            fclose($fh);
            @unlink($path);
            throw $e;
        }
        fclose($fh);

        return count($rows);
    }

    private static function parseDuration(string $raw): int
    {
        if (!preg_match('/^([1-9]\d*)(s|m|h|d|w|y)$/', trim($raw), $m)) {
            throw HttpError::invalidInput("Invalid --older-than duration `$raw`; use e.g. 30d, 12h, 1y.", '/older-than');
        }
        $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'y' => 31536000][$m[2]];

        return (int) $m[1] * $mult;
    }

    /** Record durable audit intent before executing destructive DDL/SQL. */
    private static function beginAudit(Plugin $plugin, string $slug, string $sql, ?string $actor, string $reason, int $expectedRows): string
    {
        $conn = $plugin->connection();
        $ledger = Migrator::ensureLedger($conn, $plugin->schema);
        $now = Js::iso(Js::now());
        $ts = gmdate('YmdHis');
        $hash = substr(hash('sha256', "$ts|$slug|$sql|$reason|" . random_bytes(8)), 0, 6);
        $cleanSlug = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($slug)), '_');
        $id = "{$ts}_{$hash}_{$cleanSlug}";
        $who = trim((string) ($actor ?? ''));
        if ($who === '') {
            $who = function_exists('get_current_user') ? (string) get_current_user() : 'cli';
        }
        $conn->run(
            "INSERT INTO `$ledger` (`app`, `id`, `kind`, `applied_at`, `sql`, `actor`, `reason`, `rows_affected`) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?)",
            [$plugin->schema->name(), $id, $now, hash('sha256', $sql), $who, $reason, $expectedRows],
        );

        return $id;
    }

    /** Finalize the audit row with `kind = 'destructive'` and the observed affected row count. */
    private static function finishAudit(Plugin $plugin, string $id, int $rowsAffected): void
    {
        $conn = $plugin->connection();
        $ledger = $conn->prefix() . 'tp__migrations';
        $conn->run(
            "UPDATE `$ledger` SET `kind` = 'destructive', `rows_affected` = ? WHERE `app` = ? AND `id` = ?",
            [$rowsAffected, $plugin->schema->name(), $id],
        );
    }
}
