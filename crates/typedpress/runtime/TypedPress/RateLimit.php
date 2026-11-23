<?php

declare(strict_types=1);

namespace TypedPress;

use function TypedPress\db;

/**
 * `rateLimit: "10/min"` on a query or mutation: at most `count` calls per `window` seconds, counted per
 * signed-in user, or per address when signed out. A call over the limit answers 429 `rate_limited`, and
 * the message says how many seconds are left in the window.
 *
 * Each (function, caller, window) has one row in `tp__rates`, created or incremented by one upsert. It
 * holds the row's lock until the call's transaction ends, so concurrent calls count one after the other
 * and none is lost (no read-then-write). A call that fails rolls its increment back with everything else; it still
 * counted, so the increment is made again after the rollback (relative, so it never overwrites a count
 * another call made meanwhile). Rows of windows long past are removed as new ones are written: they are
 * counters, not data. Forwarded-for headers are not an identity source: REMOTE_ADDR is what the server saw.
 */
final class RateLimit
{
    public static function register(): void
    {
        SystemTables::register('__tp_rates', [
            'CREATE TABLE IF NOT EXISTS `{prefix}tp__rates` (`id` CHAR(64) NOT NULL, `ends` BIGINT NOT NULL, `used` INT NOT NULL, PRIMARY KEY (`id`), KEY `ends` (`ends`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
        ]);
    }

    /** @param array{count: int, window: int} $limit */
    public static function check(string $action, array $limit, ?array $user): void
    {
        $store = db()->store();
        if (!$store instanceof Wp\CompositeStore) {
            return; // the standalone runtime (its tests) has no site to count on
        }
        $connection = $store->db;
        $table = '`' . $connection->prefix() . 'tp__rates`';
        $window = max(1, (int) $limit['window']);
        $now = time();
        $bucket = intdiv($now, $window);
        $ends = ($bucket + 1) * $window;
        $who = $user !== null ? 'u' . $user['id'] : 'a' . ($_SERVER['REMOTE_ADDR'] ?? 'local');
        $id = hash('sha256', $action . "\0" . $who . "\0" . $window . "\0" . $bucket);
        // One statement creates or increments the row and takes its lock: concurrent calls queue on it.
        $count = static function () use ($connection, $table, $id, $ends): int {
            $connection->run("INSERT INTO $table (`id`, `ends`, `used`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `used` = `used` + 1", [$id, $ends]);

            return (int) ($connection->run("SELECT `used` FROM $table WHERE `id` = ?", [$id])[0]['used'] ?? 0);
        };
        if ($count() > (int) $limit['count']) {
            $retry = $ends - $now;
            throw new HttpError(429, 'rate_limited', 'Too many requests. Try again in ' . $retry . ' second' . ($retry === 1 ? '' : 's') . '.');
        }
        $connection->onRollback(static fn () => $count());
        if (random_int(0, 99) === 0) {
            $connection->run("DELETE FROM $table WHERE `ends` < ?", [$now - 86400]);
        }
    }
}
