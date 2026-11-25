<?php

declare(strict_types=1);

namespace SpacePress\Identity;

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
        foreach (get_option("sp_identity_webhooks", []) as $endpoint) {
            $this->db->insert("deliveries", [
                "event_id" => $id,
                "endpoint_id" => $endpoint["id"],
                "next_at" => time(),
            ]);
        }
        do_action("sp_identity_session_event", $type, $uid, $id);
    }
    /** @var (\Closure(): int)|null the limiter's clock, for tests; null is `time()` */
    public static ?\Closure $clock = null;
    private static function now(): int
    {
        return self::$clock === null ? time() : (self::$clock)();
    }
    /**
     * Count one attempt in a bucket, and refuse it when the bucket holds more than $max.
     *
     * A bucket is one row, and its window starts at the first attempt: `expires_at` is set then and
     * stays until it has passed, when the next attempt starts a new window from one. So a bucket
     * allows $max attempts per window in the long run, and its windows don't line up with the clock
     * or with other buckets'. It is still a counter per window, not a count over every span of
     * $window seconds: one attempt, then $max - 1 as that window ends and $max as the next begins,
     * is 2 * $max - 1 allowed close together.
     *
     * The write is one statement, so two requests at once both count. Both assignments read the
     * stored `expires_at`: MariaDB and MySQL apply them left to right, so `hits` comes first; SQLite
     * reads the stored row for every expression. The count is read back in the same transaction,
     * which holds the row until then: without it, another request restarting the window between the
     * write and the read would hand this one the new window's count. The refusal is thrown after the
     * commit, so a refused attempt stays counted.
     *
     * Rows from before windows started at the first attempt have other ids and expire unread
     * ({@see Maintenance::run()} deletes them).
     */
    public function limit(
        string $bucket,
        int $max = 10,
        int $window = 300,
    ): void {
        $id = self::hash($bucket);
        $now = self::now();
        $hits = $this->db->transaction(function () use ($id, $now, $window): int {
            $this->db->run(
                "INSERT INTO {$this->db->prefix}limits (id,hits,expires_at) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=IF(expires_at<%d,1,hits+1), expires_at=IF(expires_at<%d,%d,expires_at)",
                $id,
                $now + $window,
                $now,
                $now,
                $now + $window,
            );
            $row = $this->db->row(
                "SELECT hits FROM {$this->db->prefix}limits WHERE id=%s",
                $id,
            );
            return (int) $row["hits"];
        });
        if ($hits > $max) {
            throw new Failure(
                "rate_limited",
                "Too many attempts. Try again later.",
                429,
            );
        }
    }
    /**
     * Whether a bucket has reached $max in its current window. Counts nothing ({@see limit()} does);
     * a window that has ended holds nothing. A caller that checks here and counts later (WordPress's
     * sign-in does) lets through the requests already in flight when the bucket fills.
     */
    public function limited(string $bucket, int $max = 10): bool
    {
        $row = $this->db->row(
            "SELECT hits FROM {$this->db->prefix}limits WHERE id=%s AND expires_at>=%d",
            self::hash($bucket),
            self::now(),
        );
        return $row !== null && (int) $row["hits"] >= $max;
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
            sprintf(__("Your %s code", "spacepress-identity"), get_bloginfo("name")),
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
