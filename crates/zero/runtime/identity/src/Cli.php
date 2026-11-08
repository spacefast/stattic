<?php

declare(strict_types=1);

namespace Zero\Identity;

/**
 * `wp zero identity …` (and `zero identity …`), registered with the runtime's `wp zero` dispatcher.
 * Prints `{"data": …}` or `{"error": {code, detail}}` like every other `wp zero` command.
 */
final class Cli
{
    private const USAGE = 'Usage: identity account|suspend|restore|revoke|erase <user-id> | app add --name=<n> --redirect=<uri> --origin=<origin> [--id=<app_id>]'
        . ' | webhook-add <url> | webhook-remove <id> | webhooks | maintenance | rotate-keys';

    /** @param list<string> $args after `identity`; @param array<string, string|true> $flags */
    public static function run(array $args, array $flags): int
    {
        try {
            $data = self::command($args, $flags);
            \WP_CLI::line((string) wp_json_encode(['data' => $data], JSON_UNESCAPED_SLASHES));

            return 0;
        } catch (\Throwable $e) {
            $error = $e instanceof Failure
                ? ['code' => $e->kind, 'detail' => $e->getMessage()]
                : ['code' => 'internal', 'detail' => $e->getMessage()];
            \WP_CLI::line((string) wp_json_encode(['error' => $error], JSON_UNESCAPED_SLASHES));

            return 1;
        }
    }

    private static function command(array $args, array $flags): mixed
    {
        $usage = static fn () => new Failure('invalid_command', self::USAGE);
        $user = static function () use ($args, $usage): int {
            $id = $args[1] ?? '';
            if (!ctype_digit($id) || (int) $id < 1) {
                throw $usage();
            }

            return (int) $id;
        };
        $value = static fn (int $i): string => (string) ($args[$i] ?? throw $usage());

        return match ($args[0] ?? '') {
            'account' => Plugin::$accounts->profile($user()),
            'suspend' => self::done(Plugin::$accounts->suspend($user(), true), 'Account suspended and sessions revoked.'),
            'restore' => self::done(Plugin::$accounts->suspend($user(), false), 'Account restored.'),
            'revoke' => self::done(Plugin::$sessions->revoke($user()), 'All identity sessions revoked.'),
            'erase' => self::done((new Privacy(Plugin::$db, Plugin::$security))->erase($user()), 'Identity data erased. WordPress author retained as Deleted account.'),
            'app' => ($args[1] ?? '') === 'add' ? Applications::add(
                (string) ($flags['name'] ?? ''),
                (string) ($flags['redirect'] ?? ''),
                (string) ($flags['origin'] ?? ''),
                (string) ($flags['id'] ?? 'app_' . bin2hex(random_bytes(8))),
            ) : throw $usage(),
            'webhook-add' => Webhooks::register($value(1)),
            'webhook-remove' => self::done(Webhooks::remove($value(1)), 'Webhook removed.'),
            'webhooks' => Webhooks::inventory(Plugin::$db),
            'maintenance' => self::done((new Maintenance(Plugin::$db, Plugin::$security))->run(), 'Maintenance batch completed.'),
            'rotate-keys' => self::done(Keys::rotate(), 'Signing key rotated; prior keys remain valid for ten minutes.'),
            default => throw $usage(),
        };
    }

    private static function done(mixed $_, string $message): array
    {
        return ['done' => $message];
    }
}
