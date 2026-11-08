<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Security
{
    public function __construct(private DB $db) {}
    public static function token(int $bytes = 32): string
    {
        return rtrim(
            strtr(base64_encode(random_bytes($bytes)), "+/", "-_"),
            "=",
        );
    }
    public static function hash(string $value): string
    {
        return wp_hash($value, "auth", "sha256");
    }
    public static function seal(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode(
            $nonce .
                sodium_crypto_secretbox(
                    $value,
                    $nonce,
                    hash("sha256", wp_salt("secure_auth"), true),
                ),
        );
    }
    public static function open(string $value): string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 40) {
            throw new Failure("invalid_secret", "Secret unavailable.", 500);
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, 24),
            substr($raw, 0, 24),
            hash("sha256", wp_salt("secure_auth"), true),
        );
        if ($plain === false) {
            throw new Failure("invalid_secret", "Secret unavailable.", 500);
        }
        return $plain;
    }
    public function event(int $uid, string $type, array $detail = []): void
    {
        $id = $this->db->insert("events", [
            "wp_user_id" => $uid,
            "type" => $type,
            "detail" => wp_json_encode($detail),
            "created_at" => time(),
        ]);
        foreach (get_option("zero_identity_webhooks", []) as $endpoint) {
            $this->db->insert("deliveries", [
                "event_id" => $id,
                "endpoint_id" => $endpoint["id"],
                "next_at" => time(),
            ]);
        }
        do_action("zero_identity_session_event", $type, $uid, $id);
    }
    public function limit(
        string $bucket,
        int $max = 10,
        int $window = 300,
    ): void {
        $id = self::hash($bucket . ":" . intdiv(time(), $window));
        $this->db->run(
            "INSERT INTO {$this->db->prefix}limits (id,hits,expires_at) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=hits+1",
            $id,
            time() + $window,
        );
        $row = $this->db->row(
            "SELECT hits FROM {$this->db->prefix}limits WHERE id=%s",
            $id,
        );
        if ((int) $row["hits"] > $max) {
            throw new Failure(
                "rate_limited",
                "Too many attempts. Try again later.",
                429,
            );
        }
    }
    public function challenge(
        string $purpose,
        int $uid,
        string $session,
        array $payload,
        string $secret,
        int $ttl = 300,
    ): string {
        $id = bin2hex(random_bytes(16));
        $this->db->insert("challenges", [
            "id" => $id,
            "purpose" => $purpose,
            "wp_user_id" => $uid,
            "session_id" => $session,
            "secret_hash" => self::hash($secret),
            "payload" => wp_json_encode($payload),
            "expires_at" => time() + $ttl,
        ]);
        return $id;
    }
    public function consume(
        string $id,
        string $purpose,
        string $secret,
        ?string $session = null,
    ): array {
        $result = $this->db->transaction(function () use (
            $id,
            $purpose,
            $secret,
            $session,
        ) {
            $row = $this->db->row(
                "SELECT * FROM {$this->db->prefix}challenges WHERE id=%s FOR UPDATE",
                $id,
            );
            if (
                !$row ||
                $row["purpose"] !== $purpose ||
                $row["consumed_at"] !== null ||
                (int) $row["expires_at"] <= time() ||
                (int) $row["attempts"] >= 5 ||
                ($session !== null &&
                    !hash_equals($row["session_id"], $session))
            ) {
                return null;
            }
            $this->db->run(
                "UPDATE {$this->db->prefix}challenges SET attempts=attempts+1 WHERE id=%s",
                $id,
            );
            if (!hash_equals($row["secret_hash"], self::hash($secret))) {
                return null;
            }
            $this->db->run(
                "UPDATE {$this->db->prefix}challenges SET consumed_at=%d WHERE id=%s",
                time(),
                $id,
            );
            $row["payload"] = json_decode(
                $row["payload"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            return $row;
        });
        if (!$result) {
            throw new Failure(
                "invalid_challenge",
                "The code is incorrect, used, or expired.",
            );
        }
        return $result;
    }
    public function mail(
        string $email,
        string $code,
        string $purpose,
        int $uid,
    ): void {
        $ok = wp_mail(
            $email,
            sprintf(__("Your %s code", "zero-identity"), get_bloginfo("name")),
            sprintf(
                "Your code is %s.\n\nIt expires in five minutes. Request: %s.\nIf you did not request it, ignore this message.",
                $code,
                $purpose,
            ),
        );
        $this->event($uid, $ok ? "email.accepted" : "email.failed", [
            "purpose" => $purpose,
        ]);
        if (!$ok) {
            throw new Failure(
                "email_delivery_failed",
                "Email could not be sent. Please try again later.",
                503,
            );
        }
    }
}
