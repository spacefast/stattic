<?php

declare(strict_types=1);

namespace Zero\Identity;

/** Registered OIDC applications: public clients with one exact callback and its origin. */
final class Applications
{
    /**
     * Validate and store an application. An existing id with identical values is returned
     * unchanged; different values under the same id are an idempotency conflict.
     *
     * @return array{id: string}
     */
    public static function add(string $name, string $redirect, string $origin, ?string $id = null): array
    {
        $db = Plugin::$db;
        if ($id === null || !preg_match('/^app_[A-Za-z0-9_]{1,60}$/D', $id)) {
            throw new Failure("invalid_application_id", "Use an id like app_example.");
        }
        $name = trim($name);
        if ($name === "" || strlen($name) > 120) {
            throw new Failure("invalid_name", "Name the application (up to 120 characters).");
        }
        $parts = wp_parse_url($redirect);
        $expected = is_array($parts)
            ? ($parts["scheme"] ?? "") . "://" . ($parts["host"] ?? "") . (isset($parts["port"]) ? ":" . $parts["port"] : "")
            : "";
        $local =
            defined("ZERO_IDENTITY_DEV_HTTP") &&
            ZERO_IDENTITY_DEV_HTTP &&
            in_array($parts["host"] ?? "", ["localhost", "127.0.0.1", "app.identity.localhost"], true);
        if (
            !is_array($parts) ||
            $origin !== $expected ||
            isset($parts["fragment"]) ||
            isset($parts["user"]) ||
            str_contains($redirect, "*") ||
            (($parts["scheme"] ?? "") !== "https" && !$local)
        ) {
            throw new Failure("invalid_redirect", "Register an exact HTTPS callback and its origin.");
        }
        return $db->transaction(function () use ($db, $id, $name, $redirect, $origin) {
            $existing = $db->row("SELECT * FROM {$db->prefix}applications WHERE id=%s FOR UPDATE", $id);
            if ($existing) {
                if (
                    $existing["name"] !== $name ||
                    $existing["redirects"] !== wp_json_encode([$redirect]) ||
                    $existing["origins"] !== wp_json_encode([$origin])
                ) {
                    throw new Failure("idempotency_conflict", "This request key was already used.", 409);
                }
                return ["id" => $id];
            }
            $db->insert("applications", [
                "id" => $id,
                "name" => $name,
                "redirects" => wp_json_encode([$redirect]),
                "origins" => wp_json_encode([$origin]),
            ]);
            Plugin::$security->event(get_current_user_id(), "application.created", ["id" => $id]);
            return ["id" => $id];
        });
    }
}
