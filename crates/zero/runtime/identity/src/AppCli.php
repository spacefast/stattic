<?php

declare(strict_types=1);

namespace Zero\Identity;

/** `wp zero-identity app add`: register an OIDC application (public client, PKCE required). */
final class AppCli
{
    /**
     * Register an application. With --id, rerunning with the same values returns the same app.
     *
     * ## OPTIONS
     *
     * --name=<name>
     * : Shown on the consent screen.
     *
     * --redirect=<uri>
     * : The exact callback URL.
     *
     * --origin=<origin>
     * : The callback's origin; it may call the token endpoint from a browser.
     *
     * [--id=<app_id>]
     * : A deterministic id (`app_` + letters, digits, `_`), for development harnesses.
     */
    public function add(array $args, array $flags): void
    {
        try {
            \WP_CLI::line(wp_json_encode(Applications::add(
                (string) ($flags["name"] ?? ""),
                (string) ($flags["redirect"] ?? ""),
                (string) ($flags["origin"] ?? ""),
                (string) ($flags["id"] ?? "app_" . bin2hex(random_bytes(8))),
            )));
        } catch (Failure $e) {
            \WP_CLI::error($e->kind . ": " . $e->getMessage());
        }
    }
}
