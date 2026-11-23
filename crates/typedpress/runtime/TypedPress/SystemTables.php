<?php

declare(strict_types=1);

namespace TypedPress;

/** Feature-owned, idempotent DDL. Statements use {prefix} for the WordPress table prefix. */
final class SystemTables
{
    /** @var array<string, list<string>> */
    private static array $tables = [];

    /** @param list<string> $statements */
    public static function register(string $table, array $statements): void
    {
        if (isset(self::$tables[$table])) {
            throw new \LogicException("Duplicate system table '$table'");
        }
        self::$tables[$table] = $statements;
    }

    public static function fingerprint(): string
    {
        $parts = array_map(static fn (array $statements): array => ['mysql' => $statements], self::plan());

        return substr(hash('sha256', (string) \TypedPress\Json::encode($parts)), 0, 16);
    }

    /** @return array<string, list<string>> Statements by table, sorted by table name. */
    public static function plan(): array
    {
        $plan = self::$tables;
        ksort($plan);

        return $plan;
    }

    /** Apply outside request transactions: MySQL DDL commits implicitly. */
    public static function apply(Store $store): void
    {
        foreach (self::$tables as $statements) {
            $store->applySystemStatements($statements);
        }
    }
}
