<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Webhooks
{
    public static function register(string $url): array
    {
        $parts = wp_parse_url($url);
        if (
            !$parts ||
            ($parts["scheme"] ?? "") !== "https" ||
            isset($parts["user"]) ||
            isset($parts["pass"]) ||
            isset($parts["fragment"]) ||
            !wp_http_validate_url($url)
        ) {
            throw new Failure(
                "invalid_webhook_url",
                "Enter a public HTTPS webhook URL.",
            );
        }
        $id = hash("sha256", $url);
        $endpoints = get_option("zero_identity_webhooks", []);
        foreach ($endpoints as $endpoint) {
            if ($endpoint["id"] === $id) {
                throw new Failure(
                    "webhook_exists",
                    "This endpoint is already registered.",
                    409,
                );
            }
        }
        $secret = Security::token();
        $endpoints[] = [
            "id" => $id,
            "url" => $url,
            "secret" => Security::seal($secret),
        ];
        update_option("zero_identity_webhooks", $endpoints, false);
        return ["id" => $id, "secret" => $secret];
    }
    public static function remove(string $id): void
    {
        update_option(
            "zero_identity_webhooks",
            array_values(
                array_filter(
                    get_option("zero_identity_webhooks", []),
                    fn($endpoint) => $endpoint["id"] !== $id,
                ),
            ),
            false,
        );
    }
    public static function inventory(DB $db): array
    {
        return array_map(
            fn($endpoint) => [
                "id" => $endpoint["id"],
                "url" => $endpoint["url"],
                "deliveries" => $db->rows(
                    "SELECT id,event_id,attempts,next_at,delivered_at,last_status FROM {$db->prefix}deliveries WHERE endpoint_id=%s ORDER BY id DESC LIMIT 20",
                    $endpoint["id"],
                ),
            ],
            get_option("zero_identity_webhooks", []),
        );
    }
}
