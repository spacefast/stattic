<?php
declare(strict_types=1);

// Driver for runtime/tests/spacepress-preview-parity.test.ts: boots real
// WordPress core on one engine and runs one parity scenario through the code
// path WordPress itself uses there. On MySQL/MariaDB that is the stock `wpdb`
// over mysqli; on SQLite it is the SQLite Database Integration drop-in
// (`wp-content/db.php`), whose `WP_SQLite_DB` sends every `$wpdb->query()`
// through the plugin's MySQL-on-SQLite translator.
//
// Protocol: argv[1] is a JSON request. A one-shot action prints one JSON line
// (`{"data": ...}`) on stdout. The `worker` action reads commands from stdin and
// answers one JSON line per event, so the test can interleave two sessions.
//
// WordPress is loaded from this file's top-level scope, as wp-config.php does.

$request = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($request)) {
    fwrite(STDERR, "invalid_request_json\n");
    exit(2);
}
$parity_action = (string) $request['action'];
$parity_engine = (string) $request['engine'];
$parity_site = (string) $request['site'];
$parity_installing = $parity_action === 'install';
$parity_loads_scheduler = ($request['case'] ?? '') === 'action-scheduler';

$_SERVER['HTTP_HOST'] = 'parity.invalid';
$_SERVER['SERVER_NAME'] = 'parity.invalid';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define('DB_NAME', (string) $request['dbName']);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
if ($parity_engine === 'mysql') {
    $parity_url = parse_url((string) $request['dbUrl']);
    define('DB_USER', urldecode((string) $parity_url['user']));
    define('DB_PASSWORD', urldecode((string) $parity_url['pass']));
    define('DB_HOST', $parity_url['host'] . ':' . $parity_url['port']);
} else {
    // The drop-in at WP_CONTENT_DIR/db.php reads DB_PATH; user and host are unused.
    define('DB_USER', '');
    define('DB_PASSWORD', '');
    define('DB_HOST', 'localhost');
    define('DB_PATH', (string) $request['dbPath']);
}
define('WP_CONTENT_DIR', $parity_site . '/wp-content');
define('WP_HOME', 'http://parity.invalid');
define('WP_SITEURL', 'http://parity.invalid');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('ABSPATH', rtrim((string) $request['core'], '/') . '/');
if ($parity_installing) {
    define('WP_INSTALLING', true);
}
$table_prefix = 'wp_';

// Hooks registered before WordPress loads (WP_Hook::build_preinitialized_hooks).
// No scenario may reach the network or a mail transport: loopback permalink
// probes at install and Action Scheduler's async runner both make requests.
$parity_created_tables = [];
$wp_filter = [
    'pre_http_request' => [10 => [['function' => static fn () => new WP_Error('parity_offline', 'network disabled'), 'accepted_args' => 1]]],
    'pre_wp_mail' => [10 => [['function' => static fn () => false, 'accepted_args' => 1]]],
];
if ($parity_loads_scheduler) {
    $parity_scheduler = (string) $request['actionScheduler'];
    $wp_filter['muplugins_loaded'] = [10 => [['function' => static function () use ($parity_scheduler): void {
        // The steady state of every site: Action Scheduler has finished moving
        // off the posts table, so its own tables (ActionScheduler_DBStore) hold
        // the queue, not the transitional HybridStore a brand-new site starts on.
        update_option('action_scheduler_migration_status', 'complete');
        require_once $parity_scheduler . '/action-scheduler.php';
    }, 'accepted_args' => 0]]];
    $wp_filter['action_scheduler/created_table'] = [10 => [['function' => static function ($table, $name) use (&$parity_created_tables): void {
        $parity_created_tables[] = $name;
    }, 'accepted_args' => 2]]];
}

if ($parity_action !== 'worker') {
    ob_start();
}
require ABSPATH . 'wp-settings.php';

/** The last database error as plain text, without the SQLite driver's HTML and backtrace. */
function parity_error(): ?string
{
    global $wpdb;
    $error = (string) $wpdb->last_error;
    if ($error === '') {
        return null;
    }
    if (preg_match('~class="error_message"[^>]*>(.*?)</div>~s', $error, $match) === 1) {
        $error = $match[1];
    }

    return trim(html_entity_decode(strip_tags($error)));
}

/** A failed statement's error class, comparable across engines. */
function parity_error_kind(?string $error): ?string
{
    if ($error === null) {
        return null;
    }

    return preg_match('/Duplicate entry|UNIQUE constraint failed/i', $error) === 1 ? 'duplicate' : 'other';
}

/** Runs a write and returns what WordPress reports for it (`insert_id` only for an INSERT). */
function parity_write(string $sql): array
{
    global $wpdb;
    $result = $wpdb->query($sql);
    $write = ['result' => $result, 'error' => parity_error_kind(parity_error())];
    if (preg_match('/^\s*insert\s/i', $sql) === 1) {
        $write['insertId'] = (int) $wpdb->insert_id;
    }

    return $write;
}

/** @return list<string> the first column of every row */
function parity_column(string $sql): array
{
    global $wpdb;
    $values = $wpdb->get_col($sql);
    if (parity_error() !== null) {
        throw new RuntimeException('query failed: ' . $sql . ': ' . parity_error());
    }

    return array_map('strval', $values);
}

function parity_must(string $sql): void
{
    global $wpdb;
    if ($wpdb->query($sql) === false) {
        throw new RuntimeException('statement failed: ' . $sql . ': ' . parity_error());
    }
}

function parity_create(string $table, string $columns): void
{
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    parity_must("DROP TABLE IF EXISTS $table");
    // dbDelta is how WordPress plugins create tables; it needs this exact layout.
    dbDelta("CREATE TABLE $table (\n$columns\n) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    global $wpdb;
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        throw new RuntimeException("dbDelta did not create $table: " . parity_error());
    }
}

// ============================================================ scenarios

/** Case 1: a utf8mb4_unicode_ci UNIQUE key and equality lookups. */
function parity_case_unique(): array
{
    global $wpdb;
    $t = $wpdb->prefix . 'parity_unique';
    parity_create($t, "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
email varchar(191) NOT NULL,
slug varchar(191) NOT NULL,
code varchar(64) COLLATE utf8mb4_bin DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY email (email),
UNIQUE KEY slug (slug),
UNIQUE KEY code (code)");
    $insert = static fn (string $email, string $slug): array => parity_write(
        $wpdb->prepare("INSERT INTO $t (email, slug) VALUES (%s, %s)", $email, $slug),
    );
    $kinds = static fn (array $write): array => ['result' => $write['result'], 'error' => $write['error']];

    return [
        'ascii' => [
            'first' => $kinds($insert('Ada@Example.com', 'hello-world')),
            'emailCaseVariant' => $kinds($insert('ada@example.com', 'ada-2')),
            'slugCaseVariant' => $kinds($insert('grace@example.com', 'Hello-World')),
            'emailLookup' => parity_column("SELECT email FROM $t WHERE email = 'ADA@example.com'"),
            'slugLookup' => parity_column("SELECT slug FROM $t WHERE slug = 'HELLO-WORLD'"),
        ],
        'unicodeCase' => [
            'first' => $kinds($insert('Émile@example.com', 'émile')),
            'emailCaseVariant' => $kinds($insert('émile@example.com', 'emile-case')),
            'slugCaseVariant' => $kinds($insert('zola@example.com', 'ÉMILE')),
            'emailLookup' => parity_column("SELECT email FROM $t WHERE email = 'ÉMILE@EXAMPLE.COM'"),
        ],
        'accent' => [
            'first' => $kinds($insert('chloé@example.com', 'chloé')),
            'emailAccentVariant' => $kinds($insert('chloe@example.com', 'chloe-accent')),
            'slugAccentVariant' => $kinds($insert('chloe2@example.com', 'chloe')),
            'emailLookup' => parity_column("SELECT email FROM $t WHERE email = 'chloe@example.com'"),
        ],
        // A utf8mb4_bin column (Zero's job and storage tables use them) compares byte for byte.
        'binaryCollation' => [
            'first' => $kinds(parity_write("INSERT INTO $t (email, slug, code) VALUES ('bin1@example.com', 'bin-1', 'Token')")),
            'caseVariant' => $kinds(parity_write("INSERT INTO $t (email, slug, code) VALUES ('bin2@example.com', 'bin-2', 'token')")),
            'lookup' => parity_column("SELECT code FROM $t WHERE code = 'TOKEN' ORDER BY id"),
        ],
        'trailingSpace' => [
            'first' => $kinds($insert('pad@example.com', 'pad')),
            'emailPaddedVariant' => $kinds($insert('pad@example.com ', 'pad-2')),
            'emailLookup' => parity_column("SELECT email FROM $t WHERE email = 'pad@example.com   '"),
        ],
    ];
}

/** Case 2: LIKE as WordPress writes it, and SpacePress's Zero dialect on each engine. */
function parity_case_search(array $request): array
{
    global $wpdb;
    $t = $wpdb->prefix . 'parity_search';
    parity_create($t, "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
title varchar(191) NOT NULL,
PRIMARY KEY  (id)");
    foreach (['Ada Lovelace', 'ADA BYRON', 'Grace Hopper', 'Émile Zola', '100% sure', 'under_score', 'underXscore'] as $title) {
        parity_must($wpdb->prepare("INSERT INTO $t (title) VALUES (%s)", $title));
    }
    $where = static fn (string $condition): array => parity_column("SELECT title FROM $t WHERE $condition ORDER BY id");

    // The Zero runtime's own connection and dialect, chosen as CompositeStore
    // chooses them. Its constructor registers ZERO_ILIKE on the SQLite handle.
    spl_autoload_register(static function (string $class) use ($request): void {
        if (str_starts_with($class, 'Zero\\')) {
            $file = $request['zeroRuntime'] . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
    $db = new \Zero\Wp\Connection($wpdb);
    $dialect = $db->sqlite() ? \Zero\Dialect::mysqlOnSqlite() : \Zero\Dialect::mysql($db->ciCollation());
    $zero = static fn (bool $ci, string $pattern): array => array_map(
        static fn (array $row): string => (string) $row['title'],
        $db->run("SELECT title FROM `$t` WHERE " . $dialect->like('`title`', $ci) . ' ORDER BY id', [$pattern]),
    );

    return [
        'wordpress' => [
            'likeAscii' => $where("title LIKE '%ada%'"),
            'likeBinary' => $where("title LIKE BINARY '%Ada%'"),
            'likeBinaryOtherCase' => $where("title LIKE BINARY '%ada%'"),
            'likeEscapedPercent' => $where($wpdb->prepare('title LIKE %s', $wpdb->esc_like('100%') . '%')),
            'likeEscapedUnderscore' => $where($wpdb->prepare('title LIKE %s', $wpdb->esc_like('under_') . '%')),
            'equalsAscii' => $where("title = 'ada lovelace'"),
        ],
        'wordpressUnicode' => [
            'likeUnicodeCase' => $where("title LIKE '%ÉMILE%'"),
            'likeUnicodeLower' => $where("title LIKE '%émile%'"),
            'equalsUnicodeCase' => $where("title = 'émile zola'"),
        ],
        'wordpressAccent' => [
            'likeAccent' => $where("title LIKE '%emile%'"),
            'equalsAccent' => $where("title = 'Emile Zola'"),
        ],
        'wordpressOrder' => parity_column("SELECT title FROM $t ORDER BY title, id"),
        'wordpressTrailingSpace' => $where("title = 'Grace Hopper '"),
        'zero' => [
            'ilikeAscii' => $zero(true, '%ADA%'),
            'likeCaseSensitive' => $zero(false, '%Ada%'),
            'likeCaseSensitiveOtherCase' => $zero(false, '%ada%'),
            'ilikeEscapedPercent' => $zero(true, '100\\%%'),
            'ilikeEscapedUnderscore' => $zero(true, 'under\\_%'),
            'ilikeUnicodeCase' => $zero(true, '%ÉMILE%'),
        ],
        'zeroAccent' => [
            'ilikeAccent' => $zero(true, '%emile%'),
        ],
    ];
}

/** Case 3 setup: one counter row for the concurrent workers. */
function parity_case_counter_setup(): array
{
    global $wpdb;
    $t = $wpdb->prefix . 'parity_counter';
    parity_create($t, "id bigint(20) unsigned NOT NULL,
n bigint(20) NOT NULL DEFAULT 0,
PRIMARY KEY  (id)");
    parity_must("INSERT INTO $t (id, n) VALUES (1, 0), (2, 0)");

    return parity_case_counter_read();
}

/** How long a session waits for a lock another session holds before the statement fails. */
function parity_case_lock_wait_budget(): array
{
    global $wpdb;
    $seconds = $wpdb instanceof WP_SQLite_DB
        ? (int) $wpdb->get_driver()->get_sqlite_pdo()->query('PRAGMA busy_timeout')->fetchColumn() / 1000
        : (int) $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');

    return ['seconds' => $seconds];
}

function parity_case_counter_read(): array
{
    global $wpdb;
    $t = $wpdb->prefix . 'parity_counter';

    return [
        'n' => (int) $wpdb->get_var("SELECT n FROM $t WHERE id = 1"),
        'other' => (int) $wpdb->get_var("SELECT n FROM $t WHERE id = 2"),
    ];
}

/** Case 4: Action Scheduler's schema install, enqueue, claim and run. */
function parity_case_action_scheduler(array $created): array
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $tables = ['actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs'];
    $schema = [];
    foreach ($tables as $name) {
        $table = $wpdb->prefix . $name;
        $columns = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A) as $column) {
            $columns[] = [
                'field' => $column['Field'],
                // MariaDB keeps integer display widths that MySQL 8 dropped.
                'type' => preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower((string) $column['Type'])),
                'null' => $column['Null'],
                'key' => $column['Key'],
            ];
        }
        $indexes = [];
        foreach ($wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A) as $index) {
            $indexes[] = $index['Key_name'] . ':' . $index['Seq_in_index'] . ':' . $index['Column_name'] . ':' . ((int) $index['Non_unique'] === 0 ? 'unique' : 'index');
        }
        sort($indexes);
        $schema[$name] = ['columns' => $columns, 'indexes' => $indexes];
    }
    // A second dbDelta over the same definitions must find nothing to change.
    $redelta = [];
    foreach ([new ActionScheduler_StoreSchema(), new ActionScheduler_LoggerSchema()] as $maker) {
        $definition = new ReflectionMethod($maker, 'get_table_definition');
        foreach ((new ReflectionProperty($maker, 'tables'))->getValue($maker) as $name) {
            // MariaDB reports integer display widths MySQL 8 dropped, so dbDelta
            // "changes" `tinyint(3) unsigned` to `tinyint unsigned` there on every run.
            $redelta[$name] = array_values(array_filter(
                dbDelta($definition->invoke($maker, $name)),
                static fn (string $change): bool => preg_match('/^Changed type of \S+ from (\w+)\(\d+\)(.*) to \1\2$/', $change) !== 1,
            ));
        }
    }

    $ran = [];
    add_action('parity_due', static function (int $n) use (&$ran): void {
        $ran[] = "parity_due:$n";
    });
    add_action('parity_unique', static function () use (&$ran): void {
        $ran[] = 'parity_unique';
    });
    $dueId = as_schedule_single_action(time() - 60, 'parity_due', [7], 'parity');
    $uniqueId = as_enqueue_async_action('parity_unique', [], 'parity', true);
    $uniqueAgain = as_enqueue_async_action('parity_unique', [], 'parity', true);
    $laterId = as_schedule_single_action(time() + 3600, 'parity_later', [], 'parity');

    $actions = $wpdb->prefix . 'actionscheduler_actions';
    $groups = $wpdb->prefix . 'actionscheduler_groups';
    $pending = static fn (): array => $wpdb->get_results(
        "SELECT a.hook, a.status, g.slug AS grp, a.claim_id > 0 AS claimed FROM $actions a LEFT JOIN $groups g ON g.group_id = a.group_id
         WHERE a.hook LIKE 'parity\\_%' ORDER BY a.action_id",
        ARRAY_A,
    );
    $enqueued = $pending();
    $store = ActionScheduler::store();
    $claim = $store->stake_claim(10, null, ['parity_due', 'parity_unique', 'parity_later']);
    $claimed = array_map(static fn ($id): string => $store->fetch_action((string) $id)->get_hook(), $claim->get_actions());
    sort($claimed);
    $whileClaimed = $pending();
    $store->release_claim($claim);
    $processed = ActionScheduler_QueueRunner::instance()->run('Parity');
    $afterRun = $pending();

    // A unique action whose terminal status kept its key (a custom status
    // transition): the next unique enqueue hits the key through ON DUPLICATE
    // KEY UPDATE, sees 0 affected rows, releases the stale key and retries.
    $staleFirst = as_enqueue_async_action('parity_stale', [], 'parity', true);
    $wpdb->query($wpdb->prepare("UPDATE $actions SET status = 'canceled' WHERE action_id = %d", $staleFirst));
    $staleSecond = as_enqueue_async_action('parity_stale', [], 'parity', true);
    $stale = [
        'secondIsNew' => $staleSecond > 0 && $staleSecond !== $staleFirst,
        'rows' => $wpdb->get_results(
            "SELECT status, unique_key IS NOT NULL AS keyed FROM $actions WHERE hook = 'parity_stale' ORDER BY action_id",
            ARRAY_A,
        ),
    ];
    $logs = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs l JOIN $actions a ON a.action_id = l.action_id WHERE a.hook LIKE 'parity\\_%'",
    );
    sort($created);

    return [
        'createdTables' => $created,
        'tablesExist' => (new ActionScheduler_StoreSchema())->tables_exist() && (new ActionScheduler_LoggerSchema())->tables_exist(),
        'schema' => $schema,
        'redelta' => $redelta,
        'scheduled' => [
            'due' => $dueId > 0,
            'unique' => $uniqueId > 0,
            // A unique action already pending answers 0 (Action Scheduler's documented contract).
            'uniqueAgain' => $uniqueAgain,
            'later' => $laterId > 0,
        ],
        'enqueued' => $enqueued,
        'claimed' => $claimed,
        'whileClaimed' => $whileClaimed,
        'processedAtLeast' => $processed >= 2,
        'ran' => $ran,
        'afterRun' => $afterRun,
        'hasLogs' => $logs >= 4,
        'staleUniqueKey' => $stale,
    ];
}

/** Case 5: write reporting and type rules that WordPress code reads back. */
function parity_case_misc(array $request): array
{
    global $wpdb;
    $t = $wpdb->prefix . 'parity_misc';
    parity_create($t, "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
k varchar(32) NOT NULL,
v int(11) NOT NULL DEFAULT 0,
short varchar(10) NOT NULL DEFAULT '',
created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
stamped timestamp NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY k (k)");
    $value = static fn (string $column, string $k): ?string => $wpdb->get_var($wpdb->prepare("SELECT $column FROM $t WHERE k = %s", $k));
    $lastInsertId = static function () use ($wpdb): array {
        $value = $wpdb->get_var('SELECT LAST_INSERT_ID()');

        return ['value' => $value === null ? null : (int) $value, 'error' => parity_error() === null ? null : 'error'];
    };

    $insert = parity_write("INSERT INTO $t (k, v) VALUES ('a', 1)");
    $insert['lastInsertId'] = $lastInsertId();
    $ignored = parity_write("INSERT IGNORE INTO $t (k, v) VALUES ('a', 2)");
    $odkuInsert = parity_write("INSERT INTO $t (k, v) VALUES ('b', 1) ON DUPLICATE KEY UPDATE v = v + 1");
    $odkuUpdate = parity_write("INSERT INTO $t (k, v) VALUES ('b', 1) ON DUPLICATE KEY UPDATE v = v + 1");
    $odkuSame = parity_write("INSERT INTO $t (k, v) VALUES ('b', 1) ON DUPLICATE KEY UPDATE v = v");
    parity_must("INSERT INTO $t (k, v) VALUES ('e', 1)");
    $odkuAfterOther = parity_write("INSERT INTO $t (k, v) VALUES ('b', 1) ON DUPLICATE KEY UPDATE v = v + 1");
    $odkuAfterOther = [
        'result' => $odkuAfterOther['result'],
        'error' => $odkuAfterOther['error'],
        'insertIdIsUpdatedRow' => $odkuAfterOther['insertId'] === (int) $value('id', 'b'),
    ];
    $selectNothing = parity_write("INSERT INTO $t (k, v) SELECT 'z', 1 FROM DUAL WHERE 1 = 0");

    $stamped = (string) $value('stamped', 'a');
    $now = (string) $wpdb->get_var('SELECT NOW()');

    // A second session, for named-lock exclusivity.
    $first = (string) $wpdb->get_var("SELECT GET_LOCK('parity-lock', 0)");
    $primary = $wpdb;
    // The drop-in's deprecated PDO global would make a second connection warn.
    unset($GLOBALS['@pdo']);
    $class = get_class($primary);
    $other = $request['engine'] === 'mysql' ? new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) : new $class(DB_NAME);
    $GLOBALS['wpdb'] = $primary;
    $second = (string) $other->get_var("SELECT GET_LOCK('parity-lock', 0)");
    $wpdb->get_var("SELECT RELEASE_LOCK('parity-lock')");

    return [
        'insert' => $insert,
        'insertIgnoreDuplicate' => ['write' => $ignored, 'kept' => $value('v', 'a')],
        'insertSelectNothing' => $selectNothing,
        'onDuplicateKeyUpdate' => [
            'inserted' => $odkuInsert,
            'updated' => $odkuUpdate,
            'unchanged' => $odkuSame,
            // insert_id names the updated row, not the connection's last insert.
            'updatedAfterOtherInsert' => $odkuAfterOther,
            'value' => $value('v', 'b'),
        ],
        'updateAffectedRows' => [
            'unchanged' => parity_write("UPDATE $t SET v = 1 WHERE k = 'a'"),
            'changed' => parity_write("UPDATE $t SET v = 5 WHERE k = 'a'"),
            'missing' => parity_write("UPDATE $t SET v = 5 WHERE k = 'nope'"),
        ],
        'nonStrictCoercion' => [
            'longString' => parity_write("INSERT INTO $t (k, short) VALUES ('c', 'abcdefghijklmnop')")['error'],
            'storedString' => $value('short', 'c'),
            'textInInt' => parity_write("INSERT INTO $t (k, v) VALUES ('d', 'abc')")['error'],
            'storedInt' => $value('v', 'd'),
        ],
        'datetime' => [
            'zeroDefault' => $value('created', 'a'),
            'currentTimestampShape' => preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $stamped) === 1,
            'currentTimestampIsUtcNow' => abs(strtotime($stamped . ' UTC') - time()) < 300,
            'nowShape' => preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $now) === 1,
            'addMonthClamps' => $wpdb->get_var("SELECT DATE_ADD('2026-01-31 10:00:00', INTERVAL 1 MONTH)"),
        ],
        'foundRows' => [
            'page' => parity_column("SELECT SQL_CALC_FOUND_ROWS k FROM $t ORDER BY k LIMIT 1"),
            'total' => $wpdb->get_var('SELECT FOUND_ROWS()'),
        ],
        'literalComparisonIgnoresCase' => $wpdb->get_var("SELECT 'abc' = 'ABC'"),
        'namedLock' => ['first' => $first, 'secondSession' => $second],
    ];
}

/**
 * Case 3 worker: one session doing read-modify-write of the counter under
 * START TRANSACTION + SELECT ... FOR UPDATE. Commands arrive on stdin:
 * `lock` opens the transaction and reads, `try-lock` probes a SQLite lock
 * without waiting, `commit` writes n+1 and commits, `release` rolls back
 * without writing, `touch` increments the other row in autocommit, and
 * `loop N` runs N whole increments, timing each with the system-wide
 * monotonic clock so the test can check the critical sections never
 * overlapped. It announces its first timed attempt before taking the lock.
 */
function parity_worker(): void
{
    global $wpdb;
    $t = $wpdb->prefix . 'parity_counter';
    $emit = static function (array $event): void {
        fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR) . "\n");
        fflush(STDOUT);
    };
    $held = null;
    $emit(['event' => 'ready']);
    while (($line = fgets(STDIN)) !== false) {
        $command = explode(' ', trim($line));
        if ($command[0] === 'lock') {
            $emit(['event' => 'locking']);
            $wpdb->query('START TRANSACTION');
            $begin = parity_error();
            $read = $wpdb->get_var("SELECT n FROM $t WHERE id = 1 FOR UPDATE");
            $held = (int) $read;
            $emit(['event' => 'locked', 'n' => $held, 'error' => $begin ?? parity_error()]);
        } elseif ($command[0] === 'try-lock') {
            $pdo = $wpdb->get_driver()->get_sqlite_pdo();
            $timeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
            $pdo->exec('PRAGMA busy_timeout = 0');
            $began = $wpdb->query('START TRANSACTION');
            $error = parity_error();
            if ($began !== false) {
                $wpdb->query('ROLLBACK');
            }
            $pdo->exec('PRAGMA busy_timeout = ' . $timeout);
            $emit(['event' => 'probed', 'result' => $began, 'error' => $error]);
        } elseif ($command[0] === 'commit') {
            $wpdb->query("UPDATE $t SET n = " . ($held + 1) . ' WHERE id = 1');
            $update = parity_error();
            $wpdb->query('COMMIT');
            $emit(['event' => 'committed', 'error' => $update ?? parity_error()]);
        } elseif ($command[0] === 'release') {
            $wpdb->query('ROLLBACK');
            $emit(['event' => 'released', 'error' => parity_error()]);
        } elseif ($command[0] === 'touch') {
            $emit(['event' => 'touching']);
            $wpdb->query("UPDATE $t SET n = n + 1 WHERE id = 2");
            $emit(['event' => 'touched', 'error' => parity_error()]);
        } elseif ($command[0] === 'loop') {
            $iterations = [];
            $errors = [];
            for ($i = 0, $n = (int) $command[1]; $i < $n; $i++) {
                $attempt = hrtime(true);
                if ($i === 0) {
                    $emit(['event' => 'attempting']);
                }
                $wpdb->query('START TRANSACTION');
                $error = parity_error();
                $read = (int) $wpdb->get_var("SELECT n FROM $t WHERE id = 1 FOR UPDATE");
                $error ??= parity_error();
                $acquired = hrtime(true);
                $wpdb->query("UPDATE $t SET n = " . ($read + 1) . ' WHERE id = 1');
                $error ??= parity_error();
                $wpdb->query('COMMIT');
                $error ??= parity_error();
                $committed = hrtime(true);
                if ($error !== null) {
                    $errors[] = $error;
                    $wpdb->query('ROLLBACK');
                }
                $iterations[] = [$read, $attempt, $acquired, $committed];
            }
            $emit(['event' => 'looped', 'iterations' => $iterations, 'errors' => $errors]);
        } elseif ($command[0] === 'exit') {
            return;
        }
    }
}

// ============================================================ dispatch

try {
    switch ($parity_action) {
        case 'install':
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            wp_install('Parity', 'admin', 'admin@example.invalid', false, '', 'disposable-parity-admin');
            $data = ['installed' => is_blog_installed(), 'siteurl' => get_option('siteurl')];
            break;
        case 'case':
            $data = match ((string) $request['case']) {
                'unique' => parity_case_unique(),
                'search' => parity_case_search($request),
                'counter-setup' => parity_case_counter_setup(),
                'counter-read' => parity_case_counter_read(),
                'lock-wait-budget' => parity_case_lock_wait_budget(),
                'integrity' => ['integrity' => array_map('strval', $wpdb->get_driver()->get_sqlite_pdo()->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN))],
                'action-scheduler' => parity_case_action_scheduler($parity_created_tables),
                'misc' => parity_case_misc($request),
            };
            break;
        case 'worker':
            parity_worker();
            exit(0);
        default:
            throw new RuntimeException('unknown action');
    }
    ob_end_clean();
    echo json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    if ($parity_action !== 'worker') {
        ob_end_clean();
    }
    echo json_encode(['error' => get_class($error) . ': ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine()], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
