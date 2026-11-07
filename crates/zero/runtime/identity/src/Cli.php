<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Cli
{
    public function account(array $args): void
    {
        \WP_CLI::line(
            wp_json_encode(
                Plugin::$accounts->profile((int) ($args[0] ?? 0)),
                JSON_PRETTY_PRINT,
            ),
        );
    }
    public function suspend(array $args): void
    {
        Plugin::$accounts->suspend((int) ($args[0] ?? 0), true);
        \WP_CLI::success("Account suspended and sessions revoked.");
    }
    public function restore(array $args): void
    {
        Plugin::$accounts->suspend((int) ($args[0] ?? 0), false);
        \WP_CLI::success("Account restored.");
    }
    public function revoke(array $args): void
    {
        Plugin::$sessions->revoke((int) ($args[0] ?? 0));
        \WP_CLI::success("All identity sessions revoked.");
    }
    public function erase(array $args): void
    {
        (new Privacy(Plugin::$db, Plugin::$security))->erase(
            (int) ($args[0] ?? 0),
        );
        \WP_CLI::success(
            "Identity data erased. WordPress author retained as Deleted account.",
        );
    }
    public function webhook_add(array $args): void
    {
        $result = Webhooks::register((string) ($args[0] ?? ""));
        \WP_CLI::line(wp_json_encode($result));
    }
    public function webhook_remove(array $args): void
    {
        Webhooks::remove((string) ($args[0] ?? ""));
        \WP_CLI::success("Webhook removed.");
    }
    public function webhooks(): void
    {
        \WP_CLI::line(
            wp_json_encode(Webhooks::inventory(Plugin::$db), JSON_PRETTY_PRINT),
        );
    }
    public function maintenance(): void
    {
        (new Maintenance(Plugin::$db, Plugin::$security))->run();
        \WP_CLI::success("Maintenance batch completed.");
    }
    public function rotate_keys(): void
    {
        Keys::rotate();
        \WP_CLI::success(
            "Signing key rotated; prior keys remain valid for ten minutes.",
        );
    }
}
