<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * What an inspection reads, without changing anything, from a site a pre-release runtime installed,
 * before {@see BrandUpgrade} moves it: the move runs when a build is installed, after the plan that
 * decides whether to install it.
 */
final class Legacy
{
    /** The migration ledger as a pre-release runtime named it, and as the move names it. */
    private const LEDGERS = ['tp__migrations', 'sp__migrations'];

    /** Replay inherited migration files under current table names. Their original bytes identify the audit entry. */
    public static function migrationStatement(string $statement): string
    {
        if (!str_contains($statement, 'tp_') && !str_starts_with($statement, '-- typedpress-')) {
            return $statement;
        }
        $header = '';
        foreach (['check', 'adopt'] as $kind) {
            $mark = "-- typedpress-$kind ";
            if (!str_starts_with($statement, $mark)) {
                continue;
            }
            [$line, $statement] = explode("\n", $statement, 2);
            $meta = json_decode(substr($line, strlen($mark)), true, flags: JSON_THROW_ON_ERROR);
            foreach (['table', 'to'] as $key) {
                if (isset($meta[$key]) && str_starts_with($meta[$key], '{prefix}tp_')) {
                    $meta[$key] = '{prefix}sp_' . substr($meta[$key], strlen('{prefix}tp_'));
                }
            }
            foreach ($meta['foreignKeys'] ?? [] as $i => $fk) {
                if (str_starts_with($fk['table'], '{prefix}tp_')) {
                    $meta['foreignKeys'][$i]['table'] = '{prefix}sp_' . substr($fk['table'], strlen('{prefix}tp_'));
                }
                if (str_starts_with($fk['name'], 'tp_')) {
                    $meta['foreignKeys'][$i]['name'] = 'sp_' . substr($fk['name'], 3);
                }
            }
            $header = "-- spacepress-$kind " . json_encode($meta, JSON_THROW_ON_ERROR) . "\n";
            break;
        }
        // Compiler SQL quotes identifiers with backticks and defaults with single quotes.
        // Skip quoted values so app data that resembles a table or constraint stays intact.
        $pattern = <<<'REGEX'
~'(?:''|\\.|[^'\\])*'(*SKIP)(*F)|(`\{prefix\}|\b(?:CONSTRAINT|DROP FOREIGN KEY)\s+`)tp_~
REGEX;
        return $header . preg_replace($pattern, '${1}sp_', $statement);
    }

    /**
     * The ids a pre-release runtime recorded for `$app` (or for its earlier name, for an app renamed
     * with the brand), while the move hasn't finished; empty once it has. A move that stopped after
     * renaming the tables leaves the rows in the current ledger, still under the app's earlier name.
     *
     * @return list<string>
     */
    public static function applied(Connection $db, string $app): array
    {
        if (BrandUpgrade::done()) {
            return [];
        }
        $names = array_map(static fn (string $t): string => $db->prefix() . $t, self::LEDGERS);
        $found = $db->sqlite()
            ? $db->run("SELECT name AS n FROM sqlite_master WHERE type = 'table' AND name IN (?, ?)", $names)
            : $db->run('SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)', $names);
        $ids = [];
        foreach (array_intersect($names, array_column($found, 'n')) as $ledger) {
            $ids = [...$ids, ...array_column($db->run("SELECT `id` FROM `$ledger` WHERE `app` IN (?, ?)", [$app, BrandUpgrade::historyApp($app)]), 'id')];
        }

        return array_map('strval', $ids);
    }
}
