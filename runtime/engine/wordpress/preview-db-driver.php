<?php
declare(strict_types=1);

// MySQL parity layer for SpacePress preview SQLite connections (on top of the
// box's pinned SQLite Database Integration 3.1.3 plugin). Loaded by
// wordpress/preview-db.php and by runtime/tests/spacepress-preview-parity.test.ts
// so previews and the parity suite exercise the exact same driver code.

const SPACEFAST_PREVIEW_SQLITE_BUSY_TIMEOUT = 50000;

/**
 * Normalize text for MySQL utf8mb4_unicode_ci LIKE matching: decompose
 * accents (NFD), strip combining marks, recompose (NFC) so a Hangul syllable
 * stays one character for `_`, and fold Unicode case.
 */
function spacefast_sqlite_fold_for_like(string $value): string
{
    if (class_exists(Normalizer::class)) {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($decomposed)) {
            $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed);
            if (is_string($stripped)) {
                $recomposed = Normalizer::normalize($stripped, Normalizer::FORM_C);
                $value = is_string($recomposed) ? $recomposed : $stripped;
            }
        }
    }

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * SQLite LIKE(pattern, value[, escape]) UDF with Unicode case and accent
 * folding matching MySQL utf8mb4_unicode_ci.
 */
function spacefast_sqlite_like(?string $pattern, ?string $value, ?string $escape = null, bool $hasEscape = false): ?int
{
    if ($pattern === null || $value === null) {
        return null;
    }
    if ($hasEscape) {
        if ($escape === null) {
            return null;
        }
        $escapeLen = function_exists('mb_strlen') ? mb_strlen($escape, 'UTF-8') : strlen($escape);
        if ($escapeLen > 1) {
            throw new InvalidArgumentException('ESCAPE expression must be a single character');
        }
    } else {
        $escape = '';
    }

    // Group combining marks with their base character so `_` and escape
    // sequences operate on whole characters rather than code-point fragments.
    $validUtf8 = preg_match('//u', $pattern) === 1 && preg_match('//u', $value) === 1;
    if ($validUtf8) {
        $units = preg_split('/(?=\X)/u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($units)) {
            $units = [];
        }
    } else {
        $units = $pattern === '' ? [] : str_split($pattern);
    }

    $regex = '';
    $count = count($units);
    for ($i = 0; $i < $count; $i++) {
        $unit = $units[$i];
        if ($escape !== '' && $unit === $escape) {
            if ($i + 1 < $count) {
                $i++;
                $literal = $units[$i];
            } else {
                $literal = $escape;
            }
            $folded = $validUtf8 ? spacefast_sqlite_fold_for_like($literal) : strtolower($literal);
            $regex .= preg_quote($folded, '~');
        } elseif ($unit === '%') {
            $regex .= '.*';
        } elseif ($unit === '_') {
            $regex .= '.';
        } else {
            $folded = $validUtf8 ? spacefast_sqlite_fold_for_like($unit) : strtolower($unit);
            $regex .= preg_quote($folded, '~');
        }
    }

    if ($validUtf8) {
        $foldedValue = spacefast_sqlite_fold_for_like($value);
        $matched = preg_match("~\\A{$regex}\\z~us", $foldedValue);
        if ($matched !== false) {
            return $matched === 1 ? 1 : 0;
        }
    }

    return preg_match("~\\A{$regex}\\z~s", strtolower($value)) === 1 ? 1 : 0;
}

/** PRAGMA user_version of a database whose NOCASE indexes stay in SQLite's built-in order. */
const SPACEFAST_SQLITE_NOCASE_BUILTIN = 1;

/**
 * PRAGMA user_version naming the NOCASE order the parity collation sorts in:
 * it changes with the ICU version (and without intl), so an upgrade reindexes.
 */
function spacefast_sqlite_nocase_marker(): int
{
    $icu = class_exists(Collator::class) && defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : 'none';
    $marker = crc32('spacefast-nocase-pad-space-v1:icu-' . $icu) & 0x7fffffff;

    return $marker <= SPACEFAST_SQLITE_NOCASE_BUILTIN ? $marker + 2 : $marker;
}

/**
 * Register the Unicode PAD SPACE NOCASE collation on a raw SQLite PDO handle
 * BEFORE WP_SQLite_Configurator runs, so every index and comparison uses the
 * same ordering. Indexes built under another NOCASE order must be rebuilt
 * first; see spacefast_sqlite_prepare_nocase().
 */
function spacefast_sqlite_register_collation(PDO $pdo): void
{
    $collator = class_exists(Collator::class) ? new Collator('root') : null;
    if ($collator !== null) {
        $collator->setStrength(Collator::PRIMARY);
    }

    $nocase = static function (string $a, string $b) use ($collator): int {
        $left = rtrim($a, ' ');
        $right = rtrim($b, ' ');
        if ($left === $right) {
            return 0;
        }
        if ($collator !== null) {
            $cmp = $collator->compare($left, $right);
            if ($cmp !== false) {
                return $cmp;
            }
        }

        return strcasecmp($left, $right);
    };

    if ($pdo instanceof Pdo\Sqlite) {
        $pdo->createCollation('NOCASE', $nocase);
    } else {
        $pdo->sqliteCreateCollation('NOCASE', $nocase);
    }
}

/**
 * Register the Unicode LIKE matching the parity NOCASE collation.
 */
function spacefast_sqlite_register_like(PDO $pdo): void
{
    $like2 = static fn (?string $pattern, ?string $value): ?int => spacefast_sqlite_like($pattern, $value, null, false);
    $like3 = static fn (?string $pattern, ?string $value, ?string $escape): ?int => spacefast_sqlite_like($pattern, $value, $escape, true);
    spacefast_sqlite_create_function($pdo, 'like', $like2, 2);
    spacefast_sqlite_create_function($pdo, 'like', $like3, 3);
}

/**
 * Give a connection a NOCASE collation matching the order its indexes were
 * built in. Returns true when it registered the parity collation.
 *
 * A database whose user_version is not the current marker (seeded before the
 * parity collation, or under another ICU version) has its NOCASE indexes
 * rebuilt once, inside a write transaction on a second connection so
 * concurrent requests wait on SQLite's lock and the first one does the work.
 * When stored rows collide under the parity collation (`a` and `á` in one
 * UNIQUE column), the indexes are rebuilt in SQLite's built-in order instead
 * and the database keeps it: no row is ever dropped to make an index fit.
 *
 * `$pdo` must not have a NOCASE override yet.
 */
function spacefast_sqlite_prepare_nocase(PDO $pdo, string $path, int $busyTimeoutMs): bool
{
    $marker = spacefast_sqlite_nocase_marker();
    if ($path === '' || $path === ':memory:') {
        // A private in-memory database is always new; nothing is indexed yet.
        spacefast_sqlite_register_collation($pdo);

        return true;
    }

    $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);
    $saved = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($saved !== $marker && $saved !== SPACEFAST_SQLITE_NOCASE_BUILTIN) {
        $pdoClass = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
        $parity = new $pdoClass('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $parity->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);
        spacefast_sqlite_register_collation($parity);
        $saved = spacefast_sqlite_reindex_nocase($parity, $marker);
        $parity = null;
        if ($saved === null) {
            $saved = spacefast_sqlite_reindex_nocase($pdo, SPACEFAST_SQLITE_NOCASE_BUILTIN) ?? SPACEFAST_SQLITE_NOCASE_BUILTIN;
        }
    }

    if ($saved === SPACEFAST_SQLITE_NOCASE_BUILTIN) {
        return false;
    }
    spacefast_sqlite_register_collation($pdo);

    return true;
}

/**
 * REINDEX NOCASE under `$pdo`'s NOCASE collation and stamp `$marker`, unless
 * another connection already stamped a marker. Returns the stamped marker, or
 * null when stored rows collide under this collation.
 */
function spacefast_sqlite_reindex_nocase(PDO $pdo, int $marker): ?int
{
    $current = spacefast_sqlite_nocase_marker();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $saved = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($saved === $current || $saved === SPACEFAST_SQLITE_NOCASE_BUILTIN) {
            $pdo->exec('COMMIT');

            return $saved;
        }
        try {
            $pdo->exec('REINDEX NOCASE');
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw $e;
            }
            $pdo->exec('ROLLBACK');

            return null;
        }
        $pdo->exec('PRAGMA user_version = ' . $marker);
        $pdo->exec('COMMIT');

        return $marker;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}

/**
 * Register a SQLite UDF on either Pdo\Sqlite (PHP 8.4+) or legacy PDO.
 */
function spacefast_sqlite_create_function(PDO $pdo, string $name, callable $callback, int $numArgs = -1): void
{
    if ($pdo instanceof Pdo\Sqlite) {
        $pdo->createFunction($name, $callback, $numArgs);
    } else {
        $pdo->sqliteCreateFunction($name, $callback, $numArgs);
    }
}

/**
 * Verify the pinned SQLite Database Integration plugin still exposes the
 * public seams our parity subclass relies on. Fails loudly on an incompatible
 * plugin upgrade rather than silently losing MySQL parity.
 */
function spacefast_sqlite_assert_plugin_contract(): void
{
    foreach (['WP_SQLite_DB', 'WP_MySQL_On_SQLite', 'WP_MySQL_On_SQLite_Statement'] as $class) {
        if (!class_exists($class)) {
            throw new RuntimeException("Spacefast SQLite parity requires {$class} from sqlite-database-integration.");
        }
        $ref = new ReflectionClass($class);
        if ($ref->isFinal()) {
            throw new RuntimeException("Spacefast SQLite parity cannot extend final class {$class}.");
        }
    }

    $driverRef = new ReflectionClass('WP_MySQL_On_SQLite');
    foreach (['query', 'lastInsertId', 'execute_sqlite_query', 'create_parser', 'get_sqlite_pdo'] as $method) {
        if (!$driverRef->hasMethod($method)) {
            throw new RuntimeException("Spacefast SQLite parity requires WP_MySQL_On_SQLite::{$method}().");
        }
        $m = $driverRef->getMethod($method);
        if (!$m->isPublic() || $m->isFinal()) {
            throw new RuntimeException("Spacefast SQLite parity requires WP_MySQL_On_SQLite::{$method}() to be public and overridable.");
        }
    }

    $stmtCtor = (new ReflectionClass('WP_MySQL_On_SQLite_Statement'))->getConstructor();
    if ($stmtCtor === null || !$stmtCtor->isPublic() || $stmtCtor->getNumberOfParameters() < 4) {
        throw new RuntimeException('Spacefast SQLite parity requires WP_MySQL_On_SQLite_Statement::__construct($statement, $query, $column_meta_resolver, $affected_rows).');
    }
}

if (class_exists('WP_MySQL_On_SQLite')) {
    /**
     * Subclass of WP_MySQL_On_SQLite adding MySQL parity for SpacePress previews:
     * - ICU primary PAD SPACE NOCASE collation and Unicode LIKE registered before
     *   WP_SQLite_Configurator builds or checks indexes, after a one-time
     *   REINDEX NOCASE of databases indexed under another order;
     * - flock()-backed GET_LOCK / RELEASE_LOCK / IS_FREE_LOCK / IS_USED_LOCK;
     * - session LAST_INSERT_ID([expr]) SQL function;
     * - MySQL affected_rows and insert_id semantics for INSERT, INSERT IGNORE,
     *   INSERT ... SELECT, INSERT ... ON DUPLICATE KEY UPDATE, REPLACE, and UPDATE.
     */
    class Spacefast_MySQL_On_SQLite extends WP_MySQL_On_SQLite
    {
        private static int $next_connection_id = 1;

        /** @var array<string, int> In-process lock ownership table for :memory: databases. */
        private static array $memory_locks = [];

        private int $connection_id;
        private string $db_path;
        private bool $parity_ready = false;

        /** @var array<string, array{handle: resource|null, count: int}> */
        private array $named_locks = [];

        /** Session-scoped LAST_INSERT_ID() value (survives SELECTs). */
        private int $session_last_insert_id = 0;

        /** Per-statement insert_id returned by lastInsertId() (0 when nothing inserted/updated). */
        private string $statement_last_insert_id = '0';

        /** Explicit LAST_INSERT_ID(expr) value set during the current statement, if any. */
        private ?int $explicit_last_insert_id = null;

        /** Per-statement write tracking state. */
        private ?string $active_write_kind = null;
        private ?string $active_write_table = null;
        private bool $write_intercepted = false;
        private ?int $override_affected_rows = null;
        private int $tracked_inserted = 0;
        private ?int $tracked_first_insert_id = null;
        private int $tracked_updated = 0;
        private ?int $tracked_last_updated_id = null;
        private int $tracked_deleted = 0;

        public function __construct(
            string $dsn,
            ?string $username = null,
            ?string $password = null,
            ?array $options = null
        ) {
            spacefast_sqlite_assert_plugin_contract();

            $options = $options ?? [];
            $this->connection_id = self::$next_connection_id++;
            $this->db_path = isset($options['spacefast_db_path']) && is_string($options['spacefast_db_path'])
                ? $options['spacefast_db_path']
                : ':memory:';
            unset($options['spacefast_db_path']);

            if (!isset($options['sqlite_pdo']) || !($options['sqlite_pdo'] instanceof PDO)) {
                $pdoClass = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
                $options['sqlite_pdo'] = new $pdoClass(
                    'sqlite:' . $this->db_path,
                    null,
                    null,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            }
            $pdo = $options['sqlite_pdo'];
            $busyTimeout = $options['sqlite_busy_timeout'] ?? SPACEFAST_PREVIEW_SQLITE_BUSY_TIMEOUT;
            spacefast_sqlite_prepare_nocase($pdo, $this->db_path, is_int($busyTimeout) ? $busyTimeout : SPACEFAST_PREVIEW_SQLITE_BUSY_TIMEOUT);
            spacefast_sqlite_register_like($pdo);

            parent::__construct($dsn, $username, $password, $options);

            // Fail cleanly if an upgraded plugin ignores the injected sqlite_pdo handle.
            if ($this->get_sqlite_pdo() !== $pdo) {
                throw new RuntimeException('Spacefast SQLite parity requires WP_MySQL_On_SQLite to honor the sqlite_pdo option.');
            }

            $this->register_parity_functions($pdo);
            $this->parity_ready = true;
        }

        public function __destruct()
        {
            $this->release_all_named_locks();
        }

        /**
         * Release every flock handle held by this connection.
         */
        public function release_all_named_locks(): void
        {
            foreach ($this->named_locks as $key => $entry) {
                if (is_resource($entry['handle'])) {
                    @flock($entry['handle'], LOCK_UN);
                    @fclose($entry['handle']);
                } else {
                    if ((self::$memory_locks[$key] ?? null) === $this->connection_id) {
                        unset(self::$memory_locks[$key]);
                    }
                }
            }
            $this->named_locks = [];
        }

        #[ReturnTypeWillChange]
        public function lastInsertId($name = null)
        {
            // Delegate argument validation to parent, then return our MySQL-accurate value.
            parent::lastInsertId($name);

            return $this->statement_last_insert_id;
        }

        #[ReturnTypeWillChange]
        public function query(string $query, ?int $fetch_mode = null, ...$fetch_mode_args)
        {
            if (!$this->parity_ready) {
                return func_num_args() === 1
                    ? parent::query($query)
                    : parent::query($query, $fetch_mode, ...$fetch_mode_args);
            }

            $this->statement_last_insert_id = '0';
            $this->explicit_last_insert_id = null;
            $this->override_affected_rows = null;
            $this->active_write_kind = null;
            $this->active_write_table = null;
            $this->write_intercepted = false;
            $this->tracked_inserted = 0;
            $this->tracked_first_insert_id = null;
            $this->tracked_updated = 0;
            $this->tracked_last_updated_id = null;
            $this->tracked_deleted = 0;

            [$kind, $table] = $this->classify_write_query($query);
            $this->active_write_kind = $kind;
            $this->active_write_table = $table;

            try {
                $stmt = func_num_args() === 1
                    ? parent::query($query)
                    : parent::query($query, $fetch_mode, ...$fetch_mode_args);

                if ($stmt instanceof PDOStatement && $this->active_write_kind !== null) {
                    if (!$this->write_intercepted) {
                        throw new RuntimeException(
                            'Spacefast SQLite parity expected WP_MySQL_On_SQLite to dispatch '
                            . $this->active_write_kind
                            . ' queries through execute_sqlite_query().'
                        );
                    }
                    if ($this->override_affected_rows !== null) {
                        $wrapped = new WP_MySQL_On_SQLite_Statement(
                            $stmt,
                            $query,
                            static fn (int $column) => $stmt->getColumnMeta($column),
                            $this->override_affected_rows
                        );
                        if ($fetch_mode !== null) {
                            $wrapped->setFetchMode($fetch_mode, ...$fetch_mode_args);
                        }
                        return $wrapped;
                    }
                }

                return $stmt;
            } finally {
                $this->active_write_kind = null;
                $this->active_write_table = null;
            }
        }

        public function execute_sqlite_query(string $sql, array $params = []): PDOStatement
        {
            if (!$this->parity_ready || $this->active_write_kind === null) {
                return parent::execute_sqlite_query($sql, $params);
            }

            $kind = $this->active_write_kind;
            if (!$this->sql_matches_write_kind($sql, $kind)) {
                return parent::execute_sqlite_query($sql, $params);
            }

            $table = $this->extract_sqlite_target_table($sql, $kind) ?? $this->active_write_table;
            if ($table === null || str_starts_with(strtolower($table), strtolower(WP_MySQL_On_SQLite::RESERVED_PREFIX))) {
                return parent::execute_sqlite_query($sql, $params);
            }

            $this->write_intercepted = true;
            $pdo = $this->get_sqlite_pdo();
            $schema = $this->inspect_table($pdo, $table);

            if ($schema === null || $schema['columns'] === []) {
                return parent::execute_sqlite_query($sql, $params);
            }

            // Reset counters on each attempt (handles retries cleanly).
            $this->tracked_inserted = 0;
            $this->tracked_first_insert_id = null;
            $this->tracked_updated = 0;
            $this->tracked_last_updated_id = null;
            $this->tracked_deleted = 0;

            $quotedTable = self::quote_ident($table);
            $autoCol = $schema['auto_increment_column'];
            $newId = $autoCol !== null ? 'NEW.' . self::quote_ident($autoCol) : 'NULL';

            // Temp triggers count what SQLite's changes() hides: rows REPLACE
            // deletes, the first row a multi-row INSERT adds, and updates that
            // change nothing.
            $triggers = [];
            if ($kind !== 'update') {
                $triggers['_sf_parity_ins'] = "AFTER INSERT ON {$quotedTable} FOR EACH ROW BEGIN SELECT _sf_parity_on_insert({$newId}); END";
            }
            if ($kind === 'replace') {
                $triggers['_sf_parity_del'] = "AFTER DELETE ON {$quotedTable} FOR EACH ROW BEGIN SELECT _sf_parity_on_delete(); END";
            }
            if ($kind === 'update' || $kind === 'upsert') {
                $diffChecks = [];
                foreach ($schema['columns'] as $col) {
                    $q = self::quote_ident($col);
                    $diffChecks[] = "OLD.{$q} IS NOT NEW.{$q} COLLATE BINARY";
                }
                $whenClause = implode(' OR ', $diffChecks);
                $triggers['_sf_parity_upd'] = "AFTER UPDATE ON {$quotedTable} FOR EACH ROW WHEN ({$whenClause}) BEGIN SELECT _sf_parity_on_update({$newId}); END";
            }

            // REPLACE fires delete triggers for the rows it removes only with
            // recursive_triggers on.
            $recursiveTriggers = null;
            $created = [];
            try {
                if ($kind === 'replace') {
                    $recursiveTriggers = (int) $pdo->query('PRAGMA recursive_triggers')->fetchColumn();
                    $pdo->exec('PRAGMA recursive_triggers = ON');
                }
                foreach ($triggers as $name => $body) {
                    $pdo->exec("DROP TRIGGER IF EXISTS temp.{$name}");
                    $pdo->exec("CREATE TEMP TRIGGER {$name} {$body}");
                    $created[] = $name;
                }

                $stmt = parent::execute_sqlite_query($sql, $params);
            } finally {
                foreach ($created as $name) {
                    $pdo->exec("DROP TRIGGER IF EXISTS temp.{$name}");
                }
                if ($recursiveTriggers !== null) {
                    $pdo->exec('PRAGMA recursive_triggers = ' . ($recursiveTriggers === 1 ? 'ON' : 'OFF'));
                }
            }

            if ($kind === 'update') {
                $this->override_affected_rows = $this->tracked_updated;
                if ($this->explicit_last_insert_id !== null) {
                    $this->statement_last_insert_id = (string) $this->explicit_last_insert_id;
                    if ($this->explicit_last_insert_id > 0) {
                        $this->session_last_insert_id = $this->explicit_last_insert_id;
                    }
                }

                return $stmt;
            }

            // MySQL: INSERT counts 1 per inserted row; REPLACE adds 1 per row it
            // deleted; ON DUPLICATE KEY UPDATE adds 2 per changed row and 0 per
            // unchanged row. insert_id and LAST_INSERT_ID() name the FIRST row a
            // multi-row INSERT added.
            $this->override_affected_rows = $this->tracked_inserted + $this->tracked_deleted + (2 * $this->tracked_updated);
            if ($this->explicit_last_insert_id !== null) {
                $id = $this->explicit_last_insert_id;
            } elseif ($this->tracked_inserted > 0) {
                $id = $this->tracked_first_insert_id ?? 0;
            } elseif ($this->tracked_updated > 0) {
                $id = $this->tracked_last_updated_id ?? 0;
            } else {
                $id = 0;
            }
            $this->statement_last_insert_id = (string) $id;
            if ($id > 0) {
                $this->session_last_insert_id = $id;
            }

            return $stmt;
        }

        private function register_parity_functions(PDO $pdo): void
        {
            spacefast_sqlite_create_function($pdo, 'last_insert_id', function (): int {
                return $this->session_last_insert_id;
            }, 0);
            spacefast_sqlite_create_function($pdo, 'last_insert_id', function (mixed $value): int {
                $id = (int) $value;
                $this->session_last_insert_id = $id;
                $this->explicit_last_insert_id = $id;

                return $id;
            }, 1);

            spacefast_sqlite_create_function($pdo, 'get_lock', function (mixed $name, mixed $timeout): ?int {
                return $this->sql_get_lock($name, $timeout);
            }, 2);
            spacefast_sqlite_create_function($pdo, 'release_lock', function (mixed $name): ?int {
                return $this->sql_release_lock($name);
            }, 1);
            spacefast_sqlite_create_function($pdo, 'is_free_lock', function (mixed $name): ?int {
                return $this->sql_is_free_lock($name);
            }, 1);
            spacefast_sqlite_create_function($pdo, 'is_used_lock', function (mixed $name): ?int {
                return $this->sql_is_used_lock($name);
            }, 1);

            spacefast_sqlite_create_function($pdo, '_sf_parity_on_insert', function (mixed $id): int {
                $this->tracked_inserted++;
                if ($this->tracked_first_insert_id === null && $id !== null) {
                    $this->tracked_first_insert_id = (int) $id;
                }

                return 1;
            }, 1);
            spacefast_sqlite_create_function($pdo, '_sf_parity_on_delete', function (): int {
                $this->tracked_deleted++;

                return 1;
            }, 0);
            spacefast_sqlite_create_function($pdo, '_sf_parity_on_update', function (mixed $id): int {
                $this->tracked_updated++;
                if ($id !== null) {
                    $this->tracked_last_updated_id = (int) $id;
                }

                return 1;
            }, 1);
        }

        private function lock_file_path(string $normalizedKey): ?string
        {
            if ($this->db_path === '' || $this->db_path === ':memory:') {
                return null;
            }
            $dir = dirname($this->db_path) . '/.locks';
            if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create SpacePress preview lock directory: {$dir}");
            }
            $identity = realpath($this->db_path);
            if (!is_string($identity) || $identity === '') {
                $identity = $this->db_path;
            }

            return $dir . '/' . hash('sha256', $identity . "\0" . $normalizedKey) . '.lock';
        }

        private function sql_get_lock(mixed $name, mixed $timeout): ?int
        {
            if ($name === null) {
                return null;
            }
            $key = strtolower((string) $name);
            if ($key === '') {
                return null;
            }

            if (isset($this->named_locks[$key])) {
                $this->named_locks[$key]['count']++;

                return 1;
            }

            $timeoutSec = $timeout === null ? 0.0 : (float) $timeout;
            $path = $this->lock_file_path($key);
            if ($path === null) {
                if (isset(self::$memory_locks[$key])) {
                    return 0;
                }
                self::$memory_locks[$key] = $this->connection_id;
                $this->named_locks[$key] = ['handle' => null, 'count' => 1];

                return 1;
            }

            $fh = @fopen($path, 'c+');
            if ($fh === false) {
                return null;
            }

            $acquired = false;
            if ($timeoutSec < 0) {
                $acquired = flock($fh, LOCK_EX);
            } elseif ($timeoutSec === 0.0) {
                $acquired = flock($fh, LOCK_EX | LOCK_NB);
            } else {
                $deadline = hrtime(true) + (int) round($timeoutSec * 1_000_000_000);
                for (;;) {
                    if (flock($fh, LOCK_EX | LOCK_NB)) {
                        $acquired = true;
                        break;
                    }
                    $remainingNs = $deadline - hrtime(true);
                    if ($remainingNs <= 0) {
                        break;
                    }
                    usleep((int) min(10_000, max(1_000, intdiv($remainingNs, 1_000))));
                }
            }

            if (!$acquired) {
                fclose($fh);

                return 0;
            }

            @ftruncate($fh, 0);
            @rewind($fh);
            @fwrite($fh, (string) $this->connection_id);
            @fflush($fh);

            $this->named_locks[$key] = ['handle' => $fh, 'count' => 1];

            return 1;
        }

        private function sql_release_lock(mixed $name): ?int
        {
            if ($name === null) {
                return null;
            }
            $key = strtolower((string) $name);
            if ($key === '') {
                return null;
            }

            if (isset($this->named_locks[$key])) {
                $this->named_locks[$key]['count']--;
                if ($this->named_locks[$key]['count'] <= 0) {
                    $fh = $this->named_locks[$key]['handle'];
                    unset($this->named_locks[$key]);
                    if (is_resource($fh)) {
                        @ftruncate($fh, 0);
                        @fflush($fh);
                        flock($fh, LOCK_UN);
                        fclose($fh);
                    } elseif ((self::$memory_locks[$key] ?? null) === $this->connection_id) {
                        unset(self::$memory_locks[$key]);
                    }
                }

                return 1;
            }

            $holder = $this->probe_external_lock_holder($key);

            return $holder !== null ? 0 : null;
        }

        private function sql_is_free_lock(mixed $name): ?int
        {
            if ($name === null) {
                return null;
            }
            $key = strtolower((string) $name);
            if ($key === '') {
                return null;
            }
            if (isset($this->named_locks[$key])) {
                return 0;
            }

            return $this->probe_external_lock_holder($key) === null ? 1 : 0;
        }

        private function sql_is_used_lock(mixed $name): ?int
        {
            if ($name === null) {
                return null;
            }
            $key = strtolower((string) $name);
            if ($key === '') {
                return null;
            }
            if (isset($this->named_locks[$key])) {
                return $this->connection_id;
            }

            return $this->probe_external_lock_holder($key);
        }

        /**
         * Returns the holder's connection id when another session holds `$key`, or null when free.
         */
        private function probe_external_lock_holder(string $key): ?int
        {
            if ($this->db_path === '' || $this->db_path === ':memory:') {
                return self::$memory_locks[$key] ?? null;
            }
            $path = $this->lock_file_path($key);
            if ($path === null || !is_file($path)) {
                return null;
            }
            $fh = @fopen($path, 'r+');
            if ($fh === false) {
                return null;
            }
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                flock($fh, LOCK_UN);
                fclose($fh);

                return null;
            }
            $raw = trim((string) @stream_get_contents($fh));
            fclose($fh);

            return ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : 1;
        }

        /**
         * @return array{0: ?string, 1: ?string} [kind, targetTable]
         */
        private function classify_write_query(string $query): array
        {
            // Fast skip for SELECT / SHOW / SET / START TRANSACTION / COMMIT / DDL / DELETE.
            if (preg_match('/^\s*(?:\/\*[\s\S]*?\*\/\s*)*(?:INSERT|REPLACE|UPDATE|WITH)\b/i', $query) !== 1) {
                return [null, null];
            }

            try {
                $parser = $this->create_parser($query);
                $parser->next_query();
                $ast = $parser->get_query_ast();
            } catch (Throwable) {
                return [null, null];
            }
            if ($ast === null) {
                return [null, null];
            }
            $child = $ast->get_first_child_node();
            $stmt = $child !== null ? $child->get_first_child_node() : null;
            if ($stmt === null) {
                return [null, null];
            }

            if ($stmt->rule_name === 'insertStatement') {
                $kind = $stmt->has_child_node('insertUpdateList') ? 'upsert' : 'insert';
                $table = $this->table_name_from_ref($stmt->get_first_child_node('tableRef'));

                return [$kind, $table];
            }
            if ($stmt->rule_name === 'replaceStatement') {
                $table = $this->table_name_from_ref($stmt->get_first_child_node('tableRef'));

                return ['replace', $table];
            }
            if ($stmt->rule_name === 'updateStatement') {
                $table = null;
                $refs = $stmt->get_first_child_node('tableReferenceList');
                if ($refs !== null) {
                    $single = $refs->get_descendant_nodes('singleTable');
                    if (isset($single[0])) {
                        $table = $this->table_name_from_ref($single[0]->get_first_child_node('tableRef'));
                    }
                }

                return ['update', $table];
            }

            return [null, null];
        }

        private function table_name_from_ref(?WP_Parser_Node $tableRef): ?string
        {
            if ($tableRef === null) {
                return null;
            }
            $tokens = $tableRef->get_descendant_tokens();
            if ($tokens === []) {
                return null;
            }
            $last = end($tokens);
            if (!($last instanceof WP_MySQL_Token)) {
                return null;
            }

            return self::unquote_ident($last->get_value());
        }

        private function sql_matches_write_kind(string $sql, string $kind): bool
        {
            return match ($kind) {
                'insert', 'upsert' => preg_match('/^\s*INSERT\b/i', $sql) === 1,
                'replace' => preg_match('/^\s*REPLACE\b/i', $sql) === 1,
                'update' => preg_match('/^\s*(?:WITH\b|UPDATE\b)/i', $sql) === 1,
                default => false,
            };
        }

        private function extract_sqlite_target_table(string $sql, string $kind): ?string
        {
            $ident = '(?:`(?:[^`]|``)+`|"[^"]+"|[A-Za-z_][A-Za-z0-9_]*)';
            if ($kind === 'insert' || $kind === 'upsert' || $kind === 'replace') {
                if (preg_match("/^\s*(?:INSERT(?:\s+OR\s+\w+)?|REPLACE)\s+INTO\s+(?:{$ident}\s*\.\s*)?({$ident})/i", $sql, $m) === 1) {
                    return self::unquote_ident($m[1]);
                }

                return null;
            }
            if ($kind === 'update') {
                if (preg_match("/^\s*UPDATE(?:\s+OR\s+\w+)?\s+(?:{$ident}\s*\.\s*)?({$ident})/i", $sql, $m) === 1) {
                    return self::unquote_ident($m[1]);
                }
            }

            return null;
        }

        /**
         * @return array{columns: list<string>, auto_increment_column: ?string}|null
         */
        private function inspect_table(PDO $pdo, string $table): ?array
        {
            $rows = $pdo->query('PRAGMA table_info(' . self::quote_ident($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return null;
            }

            $columns = [];
            $pkColumns = [];
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                $columns[] = $name;
                if ((int) $row['pk'] > 0) {
                    $pkColumns[] = [
                        'name' => $name,
                        'type' => strtoupper((string) $row['type']),
                    ];
                }
            }

            $autoIncrementColumn = null;
            if (count($pkColumns) === 1 && $pkColumns[0]['type'] === 'INTEGER') {
                $stmt = $pdo->prepare(
                    "SELECT sql FROM sqlite_temp_master WHERE type = 'table' AND name = ? COLLATE NOCASE "
                    . "UNION ALL SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ? COLLATE NOCASE LIMIT 1"
                );
                $stmt->execute([$table, $table]);
                $createSql = $stmt->fetchColumn();
                if (is_string($createSql) && stripos($createSql, 'AUTOINCREMENT') !== false) {
                    $autoIncrementColumn = $pkColumns[0]['name'];
                }
            }

            return [
                'columns' => $columns,
                'auto_increment_column' => $autoIncrementColumn,
            ];
        }

        private static function quote_ident(string $ident): string
        {
            return '`' . str_replace('`', '``', $ident) . '`';
        }

        private static function unquote_ident(string $raw): string
        {
            if (strlen($raw) >= 2 && $raw[0] === '`' && $raw[-1] === '`') {
                return str_replace('``', '`', substr($raw, 1, -1));
            }
            if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[-1] === '"') {
                return str_replace('""', '"', substr($raw, 1, -1));
            }

            return $raw;
        }
    }
}

if (class_exists('WP_SQLite_DB')) {
    /**
     * WP_SQLite_DB subclass that opens Spacefast_MySQL_On_SQLite with the
     * preview parity collation, busy timeout, flock locks, and MySQL write
     * reporting.
     */
    class Spacefast_SQLite_DB extends WP_SQLite_DB
    {
        public function db_connect($allow_bail = true)
        {
            if ($this->dbh) {
                return $this->ready;
            }

            $this->last_error = '';
            if (isset($GLOBALS['@pdo'])) {
                trigger_error(
                    'PDO injection via $GLOBALS[\'@pdo\'] is no longer supported. The existing PDO will be ignored and a new connection will be created.',
                    E_USER_WARNING
                );
            }

            if ($this->dbname === null || $this->dbname === '') {
                $this->bail(
                    'The database name was not set. The SQLite driver requires a database name to be set to emulate MySQL information schema tables.',
                    'db_connect_fail'
                );

                return false;
            }

            try {
                $pdoClass = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
                $pdo = new $pdoClass(
                    'sqlite:' . DB_PATH,
                    null,
                    null,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $options = [
                    'sqlite_pdo' => $pdo,
                    'spacefast_db_path' => DB_PATH,
                    'sqlite_journal_mode' => defined('SQLITE_JOURNAL_MODE') ? SQLITE_JOURNAL_MODE : 'WAL',
                    'sqlite_busy_timeout' => defined('SQLITE_BUSY_TIMEOUT') ? SQLITE_BUSY_TIMEOUT : SPACEFAST_PREVIEW_SQLITE_BUSY_TIMEOUT,
                ];
                $dbh = new Spacefast_MySQL_On_SQLite(
                    sprintf(
                        'mysql-on-sqlite:path=%s;dbname=%s',
                        str_replace(';', ';;', DB_PATH),
                        str_replace(';', ';;', $this->dbname)
                    ),
                    null,
                    null,
                    $options
                );
                $dbh->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
                $this->dbh = $dbh;
                $GLOBALS['@pdo'] = $dbh->get_sqlite_pdo();
            } catch (Throwable $e) {
                $this->last_error = $e->getMessage();
            }

            if ($this->last_error) {
                return false;
            }

            $this->has_connected = true;
            $this->set_charset($this->dbh);
            $this->ready = true;
            $this->set_sql_mode();

            return true;
        }

        public function close()
        {
            if ($this->dbh instanceof Spacefast_MySQL_On_SQLite) {
                $this->dbh->release_all_named_locks();
            }

            return parent::close();
        }
    }
}
