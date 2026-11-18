<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * Feature-owned, idempotent DDL and guarded upgrade steps on the WordPress database; statements use
 * {prefix} for the table prefix.
 *
 * A guarded step is PHP, so the fingerprint cannot read what it does: each one carries a version
 * (`self::step(2, fn)`), which its owner raises whenever the step's effect changes. A raised version
 * changes the fingerprint, so sites that recorded the older one run the steps again.
 */
final class SystemTables
{
    /** @var array<string, list<string|array{version: int, run: callable(Store): void}>> table => DDL and guarded upgrade steps */
    private static array $tables = [];

    /**
     * @param list<string|array{version: int, run: callable(Store): void}> $steps SQL (`{prefix}` is the
     *   WordPress table prefix) or a guarded upgrade step ({@see step()}) that receives the store
     */
    public static function register(string $table, array $steps): void
    {
        if (isset(self::$tables[$table])) {
            throw new \LogicException("Duplicate system table '$table'");
        }
        foreach ($steps as $step) {
            if (!is_string($step) && !(is_array($step) && is_int($step['version'] ?? null) && $step['version'] >= 1 && is_callable($step['run'] ?? null))) {
                throw new \LogicException("System table '$table': a guarded step is SystemTables::step(<version>, <callable>)");
            }
        }
        self::$tables[$table] = $steps;
    }

    /**
     * A guarded upgrade step at `$version`. Raise the version whenever what the step does changes:
     * that is what tells a site to run it again.
     *
     * @param callable(Store): void $run
     * @return array{version: int, run: callable(Store): void}
     */
    public static function step(int $version, callable $run): array
    {
        if ($version < 1) {
            throw new \LogicException('A guarded step version starts at 1');
        }

        return ['version' => $version, 'run' => $run];
    }

    /**
     * Identifies the registered set: table names, their SQL, and each guarded step's version. A site
     * that recorded an older set (a new table, a new column step, a raised step version) upgrades on
     * the next check.
     *
     * Version 1 is written as the word `step`, the form fingerprints had before steps carried versions,
     * so a site that already ran today's steps doesn't run them again because the format changed.
     */
    public static function fingerprint(): string
    {
        $parts = [];
        foreach (self::$tables as $table => $steps) {
            $parts[$table] = ['mysql' => array_map(static fn ($s) => is_string($s) ? $s : ($s['version'] === 1 ? 'step' : 'step@' . $s['version']), $steps)];
        }
        ksort($parts);

        return substr(hash('sha256', (string) \TypedPress\Json::encode($parts)), 0, 16);
    }

    /**
     * What applying the set runs, without running it: per table, each step's SQL, or `step@<version>`
     * for a guarded step. Table names sorted, steps in their order.
     *
     * @return array<string, list<string>>
     */
    public static function plan(): array
    {
        $plan = [];
        foreach (self::$tables as $table => $steps) {
            $plan[$table] = array_map(static fn ($s) => is_string($s) ? $s : 'step@' . $s['version'], $steps);
        }
        ksort($plan);

        return $plan;
    }

    /** Apply outside request transactions: MySQL DDL commits implicitly. */
    public static function apply(Store $store): void
    {
        if (self::$tables === []) {
            return;
        }
        $store->applySystemStatements([]); // Reject request transactions before running any upgrade guard.
        foreach (self::$tables as $steps) {
            foreach ($steps as $step) {
                if (is_string($step)) {
                    $store->applySystemStatements([$step]);
                } else {
                    ($step['run'])($store);
                }
            }
        }
    }
}
