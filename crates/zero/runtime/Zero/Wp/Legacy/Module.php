<?php

declare(strict_types=1);

namespace Zero\Wp\Legacy;

use Zero\App;
use Zero\HttpError;
use Zero\Js;
use Zero\Wp\Plugin;

/**
 * `wp zero migrate-legacy <content-model.php | -> [--dry-run] [--space=<id>]` (Migrate) and
 * `wp zero migrate-legacy-users --space=<id> [--dry-run]` (Users), WordPress only. Site maintenance
 * commands like `wp zero migrate`: each runs in its own transaction, not the app CLI's, so nothing
 * else (scheduled promotion) commits alongside it, and a dry run rolls the whole transaction back.
 * `migrate-spacefast` copies across prefixes with a transaction and durable receipt per table group.
 */
final class Module
{
    private const USAGE = 'Usage: migrate-legacy <content-model.php | -> [--dry-run] [--space=<id>]';
    private const USERS_USAGE = 'Usage: migrate-legacy-users --space=<id> [--dry-run]';

    /** @param list<string> $args the WP-CLI arguments after `zero` */
    public static function handles(array $args): bool
    {
        return in_array($args[0] ?? '', ['migrate-legacy', 'migrate-legacy-users', 'migrate-spacefast'], true);
    }

    /** Runs the command and prints `{data}` / `{error}`; returns the exit code. */
    public static function wpCli(Plugin $plugin, array $args, array $flags): int
    {
        try {
            $data = match ($args[0]) {
                'migrate-spacefast' => self::spacefast($plugin, array_slice($args, 1), $flags),
                'migrate-legacy-users' => self::users($plugin, array_slice($args, 1), $flags),
                default => self::run($plugin, array_slice($args, 1), $flags),
            };
            \WP_CLI::line((string) Js::jsonStringify(['data' => $data]));

            return 0;
        } catch (\Throwable $e) {
            \WP_CLI::line((string) Js::jsonStringify(['error' => App::problem($e) + ($e instanceof SpacefastDrift ? ['groups' => $e->groups] : [])]));

            return 1;
        }
    }

    private static function spacefast(Plugin $plugin, array $args, array $flags): array
    {
        $unknown = array_diff(array_keys($flags), ['space', 'source-prefix', 'target-prefix', 'dry-run', 'verify']);
        if ($args !== [] || $unknown !== [] || (isset($flags['dry-run']) && $flags['dry-run'] !== true)
            || (isset($flags['verify']) && ($flags['verify'] !== true || isset($flags['dry-run'])))) {
            throw new HttpError(400, 'invalid_command', 'Usage: migrate-spacefast --space=<id> --source-prefix=<prefix> --target-prefix=<prefix> [--dry-run | --verify]');
        }
        foreach (['space', 'source-prefix', 'target-prefix'] as $key) {
            if (!is_string($flags[$key] ?? null) || $flags[$key] === '') { throw new HttpError(400, 'invalid_command', "--$key is required", '/' . $key); }
        }
        $plugin->app();
        return (new Spacefast($plugin->connection(), $plugin->schema))->run($flags['space'], $flags['source-prefix'], $flags['target-prefix'], isset($flags['dry-run']), isset($flags['verify']));
    }

    private static function run(Plugin $plugin, array $args, array $flags): array
    {
        // A typo (`--dryrun`) must not fall through to a real migration.
        $unknown = array_diff(array_keys($flags), ['dry-run', 'space']);
        if (count($args) !== 1 || $unknown !== [] || (isset($flags['dry-run']) && $flags['dry-run'] !== true)
            || (isset($flags['space']) && (!is_string($flags['space']) || $flags['space'] === ''))) {
            throw new HttpError(400, 'invalid_command', self::USAGE . ($unknown === [] ? '' : ' (unknown: --' . implode(', --', $unknown) . ')'));
        }
        $file = $args[0];
        $release = self::release($file);
        $dryRun = isset($flags['dry-run']);
        $space = isset($flags['space']) && is_string($flags['space']) ? $flags['space'] : null;
        // Boot the app (loads compiled validators and default functions) without the CLI's prologue.
        $plugin->app();
        $migrate = new Migrate($plugin->connection(), $plugin->schema);
        if (!$dryRun) {
            return $plugin->connection()->transaction(static fn () => $migrate->run($release, false, $space));
        }
        // Plan and validate with real reads, then roll back: a dry run commits nothing.
        $report = null;
        try {
            $plugin->connection()->transaction(static function () use ($migrate, $release, $space, &$report): void {
                $report = $migrate->run($release, true, $space);
                throw new DryRun();
            });
        } catch (DryRun) {
        }

        return $report;
    }

    private static function users(Plugin $plugin, array $args, array $flags): array
    {
        $unknown = array_diff(array_keys($flags), ['dry-run', 'space']);
        if ($args !== [] || $unknown !== [] || (isset($flags['dry-run']) && $flags['dry-run'] !== true)
            || !is_string($flags['space'] ?? null) || $flags['space'] === '') {
            throw new HttpError(400, 'invalid_command', self::USERS_USAGE . ($unknown === [] ? '' : ' (unknown: --' . implode(', --', $unknown) . ')'));
        }
        $plugin->app();
        $users = new Users($plugin->connection(), $plugin->schema);

        return self::inTransaction($plugin, isset($flags['dry-run']), static fn (bool $dry) => $users->run($flags['space'], $dry));
    }

    /** Run $fn in its own transaction; a dry run plans with real reads, then rolls back. */
    private static function inTransaction(Plugin $plugin, bool $dryRun, callable $fn): array
    {
        if (!$dryRun) {
            return $plugin->connection()->transaction(static fn () => $fn(false));
        }
        $report = null;
        try {
            $plugin->connection()->transaction(static function () use ($fn, &$report): void {
                $report = $fn(true);
                throw new DryRun();
            });
        } catch (DryRun) {
        }

        return $report;
    }

    /** The original release: a path, or `-` for stdin (`zero migrate-legacy - < file` sends a local file). */
    private static function release(string $file): array
    {
        $tmp = null;
        if ($file === '-') {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'zero-release');
            file_put_contents($tmp, (string) stream_get_contents(STDIN));
            $file = $tmp;
        }
        try {
            if (!is_file($file)) {
                throw new HttpError(400, 'invalid_command', "No release at $file; pass the original site's content-model.php");
            }
            // The release is the original compiler's PHP artifact (`return [...]`), trusted like `wp eval-file`.
            $release = (static fn (string $path): mixed => require $path)($file);
        } finally {
            if ($tmp !== null) {
                unlink($tmp);
            }
        }
        if (!is_array($release)) {
            throw Model::refuse('', 'content-model.php must return an array');
        }

        return $release;
    }
}

