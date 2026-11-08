<?php

declare(strict_types=1);

namespace Zero;

/** Feature-owned, idempotent DDL and guarded upgrade steps on the WordPress database; statements use {prefix} for the table prefix. */
final class SystemTables
{
    /** @var array<string, list<string|callable(Store): void>> table => DDL and guarded upgrade steps */
    private static array $tables = [];

    /**
     * @param list<string|callable(Store): void> $steps SQL (`{prefix}` is the WordPress table prefix) or a guarded
     *   upgrade step (`ADD COLUMN` after checking information_schema) that receives the store
     */
    public static function register(string $table, array $steps): void
    {
        if (isset(self::$tables[$table])) {
            throw new \LogicException("Duplicate system table '$table'");
        }
        self::$tables[$table] = $steps;
    }

    /**
     * Identifies the registered set (table names and their SQL; a guard step counts by position), so
     * a site that recorded an older set — a new table, a new column step — upgrades on the next check.
     */
    public static function fingerprint(): string
    {
        $parts = [];
        foreach (self::$tables as $table => $steps) {
            $parts[$table] = ['mysql' => array_map(static fn ($s) => is_string($s) ? $s : 'step', $steps)];
        }
        ksort($parts);

        return substr(hash('sha256', (string) \Zero\Json::encode($parts)), 0, 16);
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
                    $step($store);
                }
            }
        }
    }
}
