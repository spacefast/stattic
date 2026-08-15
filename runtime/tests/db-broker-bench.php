<?php
declare(strict_types=1);

// Benchmark for the MySQL capability broker.
//
//   php runtime/tests/db-broker-bench.php mysql://user:pass@host:port/db [iterations]
//
// It measures the three things the broker's design rests on, against a real
// MySQL: what connection reuse is worth, what the statement cache is worth on
// top of that, and what batching is worth to a transport that pays per call.
// The first two baselines are deliberately *not* the library — they are the
// alternatives, so the numbers say what the design bought.
require_once __DIR__ . '/../engine/shared/context.php';
require_once __DIR__ . '/../engine/shared/db-broker.php';

$url = (string) ($argv[1] ?? getenv('SPACEFAST_ZERO_DATABASE_URL'));
if ($url === '') {
    fwrite(STDERR, "usage: db-broker-bench.php <mysql-url> [iterations]\n");
    exit(2);
}
$iterations = max(1, (int) ($argv[2] ?? 200));
mysqli_report(MYSQLI_REPORT_OFF);

$parts = parse_url($url);
$host = (string) ($parts['host'] ?? '127.0.0.1');
$user = rawurldecode((string) ($parts['user'] ?? ''));
$pass = rawurldecode((string) ($parts['pass'] ?? ''));
$name = ltrim((string) ($parts['path'] ?? ''), '/');
$port = (int) ($parts['port'] ?? 3306);

_stattic_db_broker_bind($url, 'provider');

$setup = new mysqli($host, $user, $pass, $name, $port);
if ($setup->connect_errno !== 0) {
    fwrite(STDERR, "bench: could not connect\n");
    exit(1);
}
$serverVersion = $setup->server_info;
$setup->query('DROP TABLE IF EXISTS bench_rows');
$setup->query('CREATE TABLE bench_rows (id INT PRIMARY KEY, v VARCHAR(64)) ENGINE=InnoDB');
$insert = $setup->prepare('INSERT INTO bench_rows VALUES (?, ?)');
for ($i = 1; $i <= 64; $i++) {
    $value = 'row-' . $i;
    $insert->bind_param('is', $i, $value);
    $insert->execute();
}
$insert->close();
$setup->close();

/** @return array{ms:float,total:float} */
function measure(int $iterations, callable $body): array
{
    // Each iteration stands for one invocation, so the per-invocation operation
    // budget is reset the way a new request would reset it. Without this the
    // benchmark silently starts measuring the cost of returning
    // zero_db_operation_budget_exhausted, which is very fast and very useless.
    _stattic_db_broker_reset_metrics();
    $body();
    $started = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        _stattic_db_broker_reset_metrics();
        $body();
    }
    $elapsed = (hrtime(true) - $started) / 1e6;

    return ['ms' => $elapsed / $iterations, 'total' => $elapsed];
}

/** Fails loudly rather than letting an error path masquerade as a fast one. */
function must_succeed(string $response): void
{
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        fwrite(STDERR, "bench: operation failed: {$response}\n");
        exit(1);
    }
}

function report(string $label, array $measurement): void
{
    printf("  %-52s %9.3f ms/op\n", $label, $measurement['ms']);
}

$SELECT = 'SELECT id, v FROM bench_rows WHERE id = ?';

echo "MySQL {$serverVersion}, PHP " . PHP_VERSION . ", {$iterations} iterations\n\n";

echo "One query per operation\n";

// What the native runner does today: Opts::from_url + Pool::new + get_conn for
// every single operation, then a prepare, then the execute.
report('connect per operation (today)', measure($iterations, function () use ($host, $user, $pass, $name, $port, $SELECT) {
    $link = new mysqli($host, $user, $pass, $name, $port);
    $link->query(STATTIC_DB_SESSION_PIN);
    $stmt = $link->prepare($SELECT);
    $id = 7;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $result->fetch_row();
    $result->free();
    $stmt->close();
    $link->close();
}));

// The remote tier's per-call shape: the pooled link comes back from PHP's
// persistent pool (paying the implicit session reset), gets re-pinned, and
// prepares fresh because no PHP statement handle survives a request.
report('pooled + reset + pin, prepare each call', measure($iterations, function () use ($host, $user, $pass, $name, $port, $SELECT) {
    $link = new mysqli('p:' . $host, $user, $pass, $name, $port);
    $link->query(STATTIC_DB_SESSION_PIN);
    $stmt = $link->prepare($SELECT);
    $id = 7;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $result->fetch_row();
    $result->free();
    $stmt->close();
    $link->close();
}));

// The local tier: one connection held across the whole request, statements
// served from the cache. This is the library itself.
$operation = (string) json_encode(['sql' => $SELECT, 'params' => [7]]);
report('broker, pooled + statement cache', measure($iterations, function () use ($operation) {
    must_succeed(_stattic_db_broker_execute($operation));
}));

echo "\nWhat each layer costs on its own\n";

report('bare query round trip on a live link', measure($iterations, function () use ($host, $user, $pass, $name, $port) {
    static $link = null;
    if ($link === null) {
        $link = new mysqli($host, $user, $pass, $name, $port);
    }
    $result = $link->query('SELECT 1');
    $result->fetch_row();
    $result->free();
}));

report('fresh TCP connect + auth', measure($iterations, function () use ($host, $user, $pass, $name, $port) {
    $link = new mysqli($host, $user, $pass, $name, $port);
    $link->close();
}));

report('persistent reopen (the implicit reset)', measure($iterations, function () use ($host, $user, $pass, $name, $port) {
    $link = new mysqli('p:' . $host, $user, $pass, $name, $port);
    $link->close();
}));

report('session pin statement', measure($iterations, function () use ($host, $user, $pass, $name, $port) {
    static $link = null;
    if ($link === null) {
        $link = new mysqli($host, $user, $pass, $name, $port);
    }
    $link->query(STATTIC_DB_SESSION_PIN);
}));

echo "\nStatement cache, isolated\n";

$cachedOperation = (string) json_encode(['sql' => $SELECT, 'params' => [7]]);
report('broker, cache hit', measure($iterations, function () use ($cachedOperation) {
    must_succeed(_stattic_db_broker_execute($cachedOperation));
}));

// Same work, but every operation is a distinct statement so nothing can hit.
$counter = 0;
report('broker, cache miss every time', measure($iterations, function () use (&$counter, $SELECT) {
    $counter += 1;
    must_succeed(_stattic_db_broker_execute((string) json_encode([
        'sql' => $SELECT . ' /* ' . $counter . ' */',
        'params' => [7],
    ])));
}));

echo "\nBatching: 20 writes\n";

$batchIterations = max(1, intdiv($iterations, 4));
$statements = [];
for ($i = 0; $i < 20; $i++) {
    $statements[] = [
        'mode' => 'execute',
        'sql' => 'INSERT INTO bench_rows VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        'params' => [1000 + $i, 'batch-' . $i],
    ];
}
$batchOperation = (string) json_encode(['mode' => 'transaction', 'statements' => $statements]);
$batch = measure($batchIterations, function () use ($batchOperation) {
    must_succeed(_stattic_db_broker_execute($batchOperation));
});
printf("  %-52s %9.3f ms/batch\n", 'one operation carrying 20 statements', $batch['ms']);

$singles = array_map(
    static fn(array $statement): string => (string) json_encode($statement),
    $statements
);
$serial = measure($batchIterations, function () use ($singles) {
    foreach ($singles as $single) {
        must_succeed(_stattic_db_broker_execute($single));
    }
});
printf("  %-52s %9.3f ms/batch\n", '20 separate operations, same connection', $serial['ms']);

// What the remote tier actually pays: every operation is its own brokered call,
// so every operation pays the pool reset and the pin as well.
$relay = measure($batchIterations, function () use ($singles) {
    foreach ($singles as $single) {
        _stattic_db_broker_close();
        must_succeed(_stattic_db_broker_execute($single));
    }
});
printf("  %-52s %9.3f ms/batch\n", '20 separate brokered calls (relay shape)', $relay['ms']);

printf(
    "\n  batching saves %.1fx against same-connection calls, %.1fx against relay calls\n",
    $serial['ms'] / max($batch['ms'], 0.0001),
    $relay['ms'] / max($batch['ms'], 0.0001)
);

_stattic_db_broker_close();
