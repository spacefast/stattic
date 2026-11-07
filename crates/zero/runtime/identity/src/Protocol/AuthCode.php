<?php

declare(strict_types=1);

namespace Zero\Identity\Protocol;

use Zero\Identity\DB;
use Zero\Identity\Failure;
use Zero\Identity\Security;

/**
 * Authorization codes (RFC 6749 §4.1 + PKCE S256, RFC 7636): 32 random bytes, stored as their
 * sha256, single use, 60 seconds. A replayed code (with its own bindings) revokes every token
 * minted from it.
 */
final class AuthCode
{
    public const TTL = 60;

    public function __construct(private DB $db) {}

    /** @param array{wp_user_id: int, session_id: string, client_id: string, redirect_uri: string, code_challenge: string, scope: string, nonce: string, auth_time: int} $grant */
    public function issue(array $grant): string
    {
        $code = Security::token(32);
        $this->db->insert("codes", $grant + [
            "id" => self::digest($code),
            "expires_at" => time() + self::TTL,
        ]);
        return $code;
    }

    /**
     * Consume a code for a token request. Returns the grant row, or an OAuth error code when the
     * request must fail; a replay also revokes the family minted from the first redemption.
     */
    public function redeem(string $code, string $clientId, string $redirectUri, string $verifier): array|string
    {
        $row = $this->db->row(
            "SELECT * FROM {$this->db->prefix}codes WHERE id=%s FOR UPDATE",
            self::digest($code),
        );
        // Nothing changes unless the request carries the code's own bindings: another client or
        // a guessed verifier can neither burn a live code nor revoke the family it produced.
        if (
            !$row ||
            !hash_equals($row["client_id"], $clientId) ||
            !hash_equals($row["redirect_uri"], $redirectUri) ||
            !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) ||
            !hash_equals($row["code_challenge"], self::challenge($verifier))
        ) {
            return "invalid_grant";
        }
        if ((int) $row["revoked"]) {
            // The legitimate holder redeemed it before: the code leaked, so its tokens end too.
            if ($row["family"] !== null) {
                $this->db->run(
                    "UPDATE {$this->db->prefix}tokens SET revoked=1 WHERE family=%s",
                    $row["family"],
                );
            }
            return "invalid_grant";
        }
        $this->db->run(
            "UPDATE {$this->db->prefix}codes SET revoked=1 WHERE id=%s",
            $row["id"],
        );
        if ((int) $row["expires_at"] <= time()) {
            return "invalid_grant";
        }
        return $row;
    }

    /** Record which token family a code produced, so a later replay can revoke it. */
    public function bind(string $codeId, string $family): void
    {
        $this->db->run(
            "UPDATE {$this->db->prefix}codes SET family=%s WHERE id=%s",
            $family,
            $codeId,
        );
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash("sha256", $verifier, true)), "+/", "-_"), "=");
    }

    private static function digest(string $code): string
    {
        return hash("sha256", $code);
    }

    /** @throws Failure */
    public static function requireChallenge(mixed $challenge, mixed $method): string
    {
        if ($method !== "S256" || !is_string($challenge) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) {
            throw new Failure(
                "invalid_authorization_request",
                "An OpenID request with state, nonce and S256 PKCE is required.",
            );
        }
        return $challenge;
    }
}
