<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Maintenance
{
    public function __construct(private DB $db, private Security $security) {}
    public function run(): void
    {
        foreach (["limits", "challenges"] as $table) {
            $this->db->run(
                "DELETE FROM {$this->db->prefix}{$table} WHERE expires_at<%d",
                time() - 86400,
            );
        }
        $endpoints = [];
        foreach (get_option("zero_identity_webhooks", []) as $endpoint) {
            $endpoints[$endpoint["id"]] = $endpoint;
        }
        $pending = $this->db->rows(
            "SELECT d.*,e.type,e.detail,e.wp_user_id,e.created_at FROM {$this->db->prefix}deliveries d JOIN {$this->db->prefix}events e ON e.id=d.event_id WHERE delivered_at IS NULL AND attempts<8 AND next_at<=%d LIMIT 30",
            time(),
        );
        foreach ($pending as $delivery) {
            $endpoint = $endpoints[$delivery["endpoint_id"]] ?? null;
            if (!$endpoint) {
                continue;
            }
            if (
                $this->db->run(
                    "UPDATE {$this->db->prefix}deliveries SET next_at=%d,attempts=attempts+1 WHERE id=%d AND next_at=%d AND delivered_at IS NULL",
                    time() + 120,
                    $delivery["id"],
                    $delivery["next_at"],
                ) !== 1
            ) {
                continue;
            }
            $body = wp_json_encode([
                "id" => (string) $delivery["event_id"],
                "type" => $delivery["type"],
                "account_id" => (string) $delivery["wp_user_id"],
                "created_at" => (int) $delivery["created_at"],
                "data" => json_decode($delivery["detail"], true),
            ]);
            $timestamp = (string) time();
            $signature = hash_hmac(
                "sha256",
                $timestamp . "." . $body,
                Security::open($endpoint["secret"]),
            );
            $response = wp_safe_remote_post($endpoint["url"], [
                "timeout" => 10,
                "redirection" => 0,
                "headers" => [
                    "Content-Type" => "application/json",
                    "Zero-Signature" =>
                        "t=" . $timestamp . ",v1=" . $signature,
                    "Zero-Event-Id" => (string) $delivery["event_id"],
                ],
                "body" => $body,
            ]);
            $status = is_wp_error($response)
                ? 0
                : wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) {
                $this->db->run(
                    "UPDATE {$this->db->prefix}deliveries SET delivered_at=%d,last_status=%d WHERE id=%d",
                    time(),
                    $status,
                    $delivery["id"],
                );
            } else {
                $this->db->run(
                    "UPDATE {$this->db->prefix}deliveries SET last_status=%d,next_at=%d WHERE id=%d",
                    $status,
                    time() + min(86400, 60 * 2 ** (int) $delivery["attempts"]),
                    $delivery["id"],
                );
            }
        }
    }
}
