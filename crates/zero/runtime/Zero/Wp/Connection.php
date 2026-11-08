<?php

declare(strict_types=1);

namespace Zero\Wp;

/**
 * The one database connection both WP stores share: WordPress's own `$wpdb` handle, so core writes
 * (`wp_insert_post`, `update_field`, options, cron) and Zero's SQL commit or roll back together.
 *
 * Two drivers answer: `mysqli` on MySQL and MariaDB, and the SQLite Database Integration plugin's
 * PDO handle, which translates MySQL to SQLite. Compiled SQL (Sql::select) runs as a real mysqli
 * prepared statement, never through `$wpdb->prepare()` (the §3.5.1 expressions contain `%` in
 * DATE_FORMAT patterns). The SQLite driver has no prepared statements, so there the positional
 * parameters are bound as quoted literals, rows are typed as mysqli types them, and the MySQL
 * functions the translator lacks (JSON_CONTAINS, JSON_OBJECTAGG) are registered on it.
 *
 * MySQL can't roll back object caches, so every post and option touched inside a transaction is
 * recorded and its cache cleaned when the transaction rolls back (§3.3).
 */
final class Connection
{
    private int $depth = 0;
    private bool $rollbackOnly = false;
    /** Core writes in flight: editor-save hooks ignore posts Zero itself is writing (§4.9). */
    private int $busy = 0;
    /** @var array<int, true> */
    private array $touchedPosts = [];
    /** @var array<string, true> */
    private array $touchedOptions = [];
    /** @var list<callable> */
    private array $afterCommit = [];
    /** @var list<callable> */
    private array $onRollback = [];
    /** Rows the last statement changed (SQLite driver; mysqli answers ROW_COUNT() itself). */
    private int $changes = 0;
    /** @var array<int, true> SQLite handles (by object id) that already carry Zero's functions */
    /** @var \WeakMap<\PDO, true>|null the SQLite handles that already carry Zero's functions */
    private static ?\WeakMap $prepared = null;

    public function __construct(private readonly \wpdb $wpdb)
    {
        if ($wpdb->dbh instanceof \PDO) {
            self::prepareSqlite($wpdb->dbh);
        }
    }

    /** True on the SQLite Database Integration plugin (WordPress Playground, the fast suites). */
    public function sqlite(): bool
    {
        return $this->wpdb->dbh instanceof \PDO;
    }

    public function wpdb(): \wpdb
    {
        return $this->wpdb;
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    /** `utf8mb4_unicode_ci` on MariaDB and MySQL 5.7, `utf8mb4_0900_ai_ci` on MySQL 8+ (§3.5 `ilike`). */
    public function ciCollation(): string
    {
        $info = (string) $this->wpdb->db_server_info();
        if (stripos($info, 'mariadb') !== false) {
            return 'utf8mb4_unicode_ci';
        }

        return version_compare((string) $this->wpdb->db_version(), '8.0', '>=') ? 'utf8mb4_0900_ai_ci' : 'utf8mb4_unicode_ci';
    }

    // ================================================================ statements

    /**
     * Run one statement with positional `?` params. Returns rows for result-producing statements,
     * [] otherwise.
     *
     * @param list<int|float|string|null> $params
     * @return list<array<string, mixed>>
     */
    public function run(string $sql, array $params = []): array
    {
        $values = array_map(static fn ($v) => is_float($v) ? \Zero\Js::numStr($v) : $v, array_values($params));
        $dbh = $this->wpdb->dbh;
        if ($dbh instanceof \PDO) {
            return $this->runSqlite($dbh, $sql, $values);
        }
        $values = array_map(static fn ($v) => $v instanceof Bytes ? $v->value : $v, $values);
        $mysqli = $this->mysqli();
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            throw new DbError($mysqli->error . ' [' . $sql . ']', $mysqli->errno);
        }
        try {
            if (!$stmt->execute($values === [] ? null : $values)) {
                throw new DbError($stmt->error, $stmt->errno);
            }
            $result = $stmt->get_result();

            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $stmt->close();
        }
    }

    /**
     * The SQLite driver translates one MySQL statement at a time and has no prepared statements:
     * parameters go in as quoted literals. Numeric columns come back typed as mysqli types them.
     *
     * @param list<int|string|null> $values
     * @return list<array<string, mixed>>
     */
    private function runSqlite(\PDO $dbh, string $sql, array $values): array
    {
        $tokens = self::tokens($dbh, $sql);
        $literal = self::interpolate($sql, $tokens, $values, $dbh);
        $words = array_map(static fn (object $t): string => strtoupper($t->get_bytes()), array_slice($tokens, 0, 2));
        $ddl = !$dbh->inTransaction() && $words === ['ALTER', 'TABLE'];
        if ($ddl && method_exists($dbh, 'get_sqlite_pdo')) {
            // The driver runs ALTER TABLE as drop-and-recreate inside its own transaction, where SQLite
            // ignores `PRAGMA foreign_keys = OFF`: the DROP would cascade into child rows. Migrations
            // run outside Zero transactions (as on MySQL, where DDL commits), so the statement runs
            // with the pragma off inside a transaction of ours, and commits only when every foreign
            // key still holds: a constraint added over orphan rows fails as it does on MySQL.
            return $this->alterSqlite($dbh, $dbh->get_sqlite_pdo(), $literal, $sql);
        }
        try {
            $stmt = $dbh->query($literal, \PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw self::sqliteError($e, $sql);
        }

        return $this->collect($stmt, $sql);
    }

    private function alterSqlite(\PDO $dbh, \PDO $raw, string $literal, string $sql): array
    {
        // The transaction is opened through the driver, which tracks it itself before PHP 8.4; the
        // check and the pragma go to SQLite directly.
        $raw->exec('PRAGMA foreign_keys = OFF');
        try {
            $dbh->query('START TRANSACTION');
            try {
                $stmt = $dbh->query($literal, \PDO::FETCH_ASSOC);
                $broken = $raw->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC);
                if ($broken !== []) {
                    $dbh->query('ROLLBACK');
                    $tables = array_unique(array_map(static fn (array $r): string => (string) $r['table'] . ' -> ' . (string) $r['parent'], $broken));
                    throw new DbError('Cannot add or update a child row: a foreign key constraint fails (' . implode(', ', $tables) . ")  [$sql]", 1452);
                }
                $dbh->query('COMMIT');
            } catch (\PDOException $e) {
                if ($dbh->inTransaction()) {
                    $dbh->query('ROLLBACK');
                }
                throw self::sqliteError($e, $sql);
            }
        } finally {
            $raw->exec('PRAGMA foreign_keys = ON');
        }

        return $this->collect($stmt, $sql);
    }

    /**
     * The statement's tokens as the driver's own MySQL lexer reads them (whitespace and comments
     * dropped, executable comments gated by the version the driver emulates, quoting and escapes as
     * MySQL's): the one reading of the SQL that agrees with what the driver will execute.
     *
     * @return list<object> WP_MySQL_Token objects with `id`, `start`, `length` and `get_bytes()`
     */
    private static function tokens(\PDO $dbh, string $sql): array
    {
        if (!method_exists($dbh, 'create_parser')) {
            throw new DbError("the SQLite driver has no MySQL parser [$sql]", 0);
        }
        $parser = $dbh->create_parser($sql);
        $property = new \ReflectionProperty($parser, 'tokens');
        $tokens = (array) $property->getValue($parser);
        // EOF closes the list; a null token mid-stream is input the lexer could not read.
        $last = end($tokens);
        if ($last !== false && (int) $last->id === -1) {
            array_pop($tokens);
        }

        return array_values($tokens);
    }

    /**
     * The statement with its positional parameters as literals, one per `?` token. Strings are
     * quoted; `Bytes` become `X'…'`, so a binary column compares byte for byte (a text literal never
     * equals a BLOB in SQLite).
     *
     * @param list<object> $tokens
     * @param list<int|string|Bytes|null> $values
     */
    private static function interpolate(string $sql, array $tokens, array $values, \PDO $dbh): string
    {
        $out = '';
        $at = 0;
        $i = 0;
        foreach ($tokens as $token) {
            if ((int) $token->id !== \WP_MySQL_Lexer::PARAM_MARKER) {
                continue;
            }
            if (!array_key_exists($i, $values)) {
                throw new DbError("statement has more placeholders than parameters [$sql]", 0);
            }
            $v = $values[$i++];
            $out .= substr($sql, $at, $token->start - $at) . match (true) {
                $v === null => 'NULL',
                is_int($v) => (string) $v,
                $v instanceof Bytes => "X'" . bin2hex($v->value) . "'",
                default => $dbh->quote((string) $v),
            };
            $at = $token->start + $token->length;
        }
        if ($i !== count($values)) {
            throw new DbError("statement has fewer placeholders than parameters [$sql]", 0);
        }

        return $out . substr($sql, $at);
    }

    /** Rows of a statement, numeric columns typed as mysqli types them. */
    private function collect(\PDOStatement|false $stmt, string $sql): array
    {
        if ($stmt === false) {
            throw new DbError("statement failed [$sql]", 0);
        }
        $this->changes = $stmt->rowCount();
        $rows = $stmt->fetchAll();
        $ints = $floats = [];
        for ($c = 0, $n = $stmt->columnCount(); $c < $n; $c++) {
            $meta = $stmt->getColumnMeta($c) ?: [];
            $native = strtoupper((string) ($meta['native_type'] ?? ''));
            if (($meta['pdo_type'] ?? null) === \PDO::PARAM_INT) {
                $ints[] = $meta['name'];
            } elseif ($native === 'DOUBLE' || $native === 'FLOAT') {
                $floats[] = $meta['name'];
            }
        }
        if ($ints !== [] || $floats !== []) {
            foreach ($rows as &$row) {
                foreach ($ints as $col) {
                    if (is_string($row[$col] ?? null) && preg_match('/^-?\d+$/D', $row[$col]) === 1) {
                        $row[$col] = (int) $row[$col];
                    }
                }
                foreach ($floats as $col) {
                    if (is_string($row[$col] ?? null) && is_numeric($row[$col])) {
                        $row[$col] = (float) $row[$col];
                    }
                }
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * A translator error as the MySQL error Zero's stores map (`WpTableStore::mapError` reads the
     * errno and the key or column name): duplicates are 1062, broken references 1451, missing
     * required values 1048.
     */
    private static function sqliteError(\PDOException $e, string $sql): DbError
    {
        $message = $e->getMessage();
        if (preg_match('/UNIQUE constraint failed: (.+)$/', $message, $m) === 1) {
            $columns = array_map(static fn (string $c): string => substr($c, strrpos($c, '.') + 1), explode(', ', trim($m[1])));

            return new DbError("Duplicate entry for key '" . implode('_', $columns) . "'", 1062);
        }
        if (str_contains($message, 'FOREIGN KEY constraint failed')) {
            return new DbError('Cannot add or update a child row: a foreign key constraint fails', 1451);
        }
        if (preg_match('/NOT NULL constraint failed: [^.]+\.(\S+)/', $message, $m) === 1) {
            return new DbError("Column '{$m[1]}' cannot be null", 1048);
        }

        return new DbError($message . ' [' . $sql . ']', is_numeric($e->getCode()) ? (int) $e->getCode() : 0);
    }

    /**
     * MySQL functions Zero's SQL uses that the SQLite translator doesn't provide, on its PDO handle.
     * Registered once per handle: every Connection over the same `$wpdb` shares it.
     */
    private static function prepareSqlite(\PDO $driver): void
    {
        $pdo = method_exists($driver, 'get_sqlite_pdo') ? $driver->get_sqlite_pdo() : $driver;
        self::$prepared ??= new \WeakMap();
        if (isset(self::$prepared[$pdo])) {
            return;
        }
        self::$prepared[$pdo] = true;
        // PHP 8.4 moved the SQLite extensions onto Pdo\Sqlite, and 8.5 deprecates them on PDO; the plugin
        // still constructs a plain PDO, whose only way to add functions is the deprecated pair (silenced).
        $native = $pdo instanceof \Pdo\Sqlite;
        $function = static fn (string $name, callable $fn, int $args): bool => $native ? $pdo->createFunction($name, $fn, $args) : @$pdo->sqliteCreateFunction($name, $fn, $args);
        $aggregate = static fn (string $name, callable $step, callable $final, int $args): bool => $native ? $pdo->createAggregate($name, $step, $final, $args) : @$pdo->sqliteCreateAggregate($name, $step, $final, $args);
        // JSON_CONTAINS(doc, candidate): a list contains the candidate as one element; a scalar equals it.
        $function('JSON_CONTAINS', static function (?string $doc, ?string $candidate): ?int {
            if ($doc === null || $candidate === null) {
                return null;
            }
            $haystack = json_decode($doc, true);
            $needle = json_decode($candidate, true);

            return (int) (is_array($haystack) && array_is_list($haystack) ? in_array($needle, $haystack, true) : $haystack === $needle);
        }, 2);
        // SUBSTRING_INDEX(str, delim, count): the part before the count-th delimiter, or after the
        // |count|-th from the end when count is negative (the media view's file name from its path).
        $function('SUBSTRING_INDEX', static function (?string $str, ?string $delim, ?int $count): ?string {
            if ($str === null || $delim === null || $count === null) {
                return null;
            }
            if ($delim === '' || $count === 0) {
                return '';
            }
            $parts = explode($delim, $str);
            if (abs($count) >= count($parts)) {
                return $str;
            }

            return implode($delim, $count > 0 ? array_slice($parts, 0, $count) : array_slice($parts, $count));
        }, 3);
        // ZERO_ILIKE(str, pattern): `ilike` with Unicode case folding (SQLite's LIKE folds ASCII only;
        // the MySQL collations fold Unicode). LIKE's `%` and `_`, and `\\` escaping the next character.
        $function('ZERO_ILIKE', static function (?string $str, ?string $pattern): ?int {
            if ($str === null || $pattern === null) {
                return null;
            }
            $regex = '';
            for ($i = 0, $n = mb_strlen($pattern, 'UTF-8'); $i < $n; $i++) {
                $c = mb_substr($pattern, $i, 1, 'UTF-8');
                $regex .= match ($c) {
                    '%' => '.*',
                    '_' => '.',
                    // `\\x` is a literal x; a trailing backslash is itself (as MySQL reads it).
                    '\\' => preg_quote($i + 1 < $n ? mb_substr($pattern, ++$i, 1, 'UTF-8') : '\\', '~'),
                    default => preg_quote($c, '~'),
                };
            }

            return (int) (preg_match("~\\A$regex\\z~usi", $str) === 1);
        }, 2);
        // JSON_OBJECTAGG(key, value): a JSON object over the group, NULL for an empty group (as MySQL).
        // Values keep their storage class: an integer column is a number, a text key that looks
        // numeric stays a string, as MySQL types them.
        $aggregate(
            'JSON_OBJECTAGG',
            static function (?array $context, int $rows, mixed $key, mixed $value): array {
                $context ??= [];
                if ($key !== null) {
                    $context[(string) $key] = $value;
                }

                return $context;
            },
            static fn (?array $context, int $rows): ?string => $context === null ? null : \Zero\Json::encode((object) $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            2,
        );
    }

    /** Rows changed by the last statement on this connection (0 for an ignored insert or update). */
    public function changes(): int
    {
        if ($this->sqlite()) {
            return $this->changes;
        }

        return (int) ($this->run('SELECT ROW_COUNT() AS n')[0]['n'] ?? 0);
    }

    /**
     * Hold a site-wide named lock while $fn runs: MySQL's `GET_LOCK`, or a lock file beside the SQLite
     * database (the translator answers `GET_LOCK` without locking anything). Waits up to $seconds.
     * Throws LockBusy only for contention; infrastructure failures remain RuntimeExceptions.
     */
    public function locked(string $name, int $seconds, callable $fn): mixed
    {
        if (!$this->sqlite()) {
            $held = $this->run('SELECT GET_LOCK(?, ?) AS held', [$name, $seconds])[0]['held'] ?? null;
            if ($held === 0 || $held === '0') {
                throw new LockBusy('another request is still applying migrations; try again shortly');
            }
            if ($held !== 1 && $held !== '1') {
                throw new \RuntimeException("cannot acquire named lock $name");
            }
            try {
                return $fn();
            } finally {
                $this->run('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
        $file = (defined('DB_PATH') ? (string) DB_PATH : (string) (DB_DIR . '/' . DB_FILE)) . '.' . substr(hash('sha256', $name), 0, 16) . '.lock';
        $handle = fopen($file, 'c') ?: throw new \RuntimeException("cannot open lock file $file");
        $deadline = microtime(true) + $seconds;
        try {
            while (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if (!$wouldBlock) {
                    throw new \RuntimeException("cannot acquire lock file $file");
                }
                if (microtime(true) >= $deadline) {
                    throw new LockBusy('another request is still applying migrations; try again shortly');
                }
                usleep(50_000);
            }
            try {
                return $fn();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $statements idempotent DDL outside request transactions */
    public function applySystemStatements(array $statements): void
    {
        if ($this->inTransaction()) {
            throw new \LogicException('System DDL cannot run inside a transaction');
        }
        foreach ($statements as $statement) {
            $this->run(str_replace('{prefix}', $this->prefix(), $statement));
        }
    }

    public function insertId(): int
    {
        $dbh = $this->wpdb->dbh;

        return (int) ($dbh instanceof \PDO ? $dbh->lastInsertId() : $this->mysqli()->insert_id);
    }

    private function mysqli(): \mysqli
    {
        $dbh = $this->wpdb->dbh;
        if (!$dbh instanceof \mysqli) {
            throw new \RuntimeException('Zero needs WordPress to use the mysqli driver or the SQLite Database Integration plugin');
        }

        return $dbh;
    }

    // ================================================================ transactions

    /** Open a transaction or join the open one. Any throwable rolls back and cleans caches. */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn();
            } catch (\Throwable $e) {
                $this->rollbackOnly = true;
                throw $e;
            } finally {
                $this->depth--;
            }
        }
        $this->begin();
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
        $this->commit();

        return $result;
    }

    /**
     * Open the outermost transaction by hand, for a save that WordPress drives through hooks (§4.9):
     * Zero's own transactions join it until commit() or rollback() ends it. Throws if one is open.
     */
    public function begin(): void
    {
        if ($this->depth > 0) {
            throw new \LogicException('A transaction is already open');
        }
        $this->query('START TRANSACTION');
        $this->depth = 1;
    }

    /** Commit the outermost transaction, then run its afterCommit callbacks. A failed COMMIT rolls back. */
    public function commit(): void
    {
        if ($this->rollbackOnly) {
            $this->rollback();
            throw new \RuntimeException('Cannot commit: a joined transaction failed');
        }
        try {
            $this->query('COMMIT');
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
        $this->depth = 0;
        $after = $this->afterCommit;
        $this->reset();
        foreach ($after as $cb) {
            $cb();
        }
    }

    /** Roll the outermost transaction back, clean the caches it touched, run its onRollback callbacks. */
    public function rollback(): void
    {
        $this->depth = 0;
        $undo = $this->onRollback;
        try {
            $this->query('ROLLBACK');
        } finally {
            $this->cleanCaches();
            $this->reset();
        }
        foreach ($undo as $cb) {
            $cb();
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function afterCommit(callable $cb): void
    {
        $this->depth > 0 ? $this->afterCommit[] = $cb : $cb();
    }

    public function onRollback(callable $cb): void
    {
        if ($this->depth > 0) {
            $this->onRollback[] = $cb;
        }
    }

    public function touchPost(int $id): void
    {
        $this->touchedPosts[$id] = true;
    }

    public function touchOption(string $name): void
    {
        $this->touchedOptions[$name] = true;
    }

    /** Run a core write (wp_insert_post, update_field, ...) with the editor hooks muted. */
    public function core(callable $fn): mixed
    {
        $this->busy++;
        try {
            return $fn();
        } finally {
            $this->busy--;
        }
    }

    public function busy(): bool
    {
        return $this->busy > 0;
    }

    private function query(string $sql): void
    {
        if ($this->wpdb->query($sql) === false) {
            throw new DbError("$sql failed: " . $this->wpdb->last_error, 0);
        }
    }

    private function cleanCaches(): void
    {
        foreach (array_keys($this->touchedPosts) as $id) {
            clean_post_cache($id);
        }
        foreach (array_keys($this->touchedOptions) as $name) {
            wp_cache_delete($name, 'options');
        }
        if ($this->touchedOptions !== []) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        if (function_exists('acf_get_store')) {
            acf_get_store('values')->reset();
        }
    }

    private function reset(): void
    {
        $this->rollbackOnly = false;
        $this->touchedPosts = $this->touchedOptions = [];
        $this->afterCommit = $this->onRollback = [];
    }
}
