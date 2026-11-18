<?php

declare(strict_types=1);

namespace TypedPress\Identity;

/**
 * WordPress's sessions by verifier (the SHA-256 of a session token, what WordPress itself stores),
 * for identity sessions opened from a WordPress sign-in ({@see Plugin::browserSession}). Identity
 * keeps only the verifier, never the token, so it reads and ends the WordPress session through the
 * session manager's own verifier methods. It is a subclass only to reach those protected methods on
 * whatever manager `session_token_manager` names; it is never instantiated.
 */
abstract class WordPressSessions extends \WP_Session_Tokens
{
    /** The verifier WordPress stores for a session token. */
    public static function verifier(string $token): string
    {
        return hash("sha256", $token);
    }

    /** The WordPress session, or null when it has ended (signed out, destroyed, expired). */
    public static function find(int $uid, string $verifier): ?array
    {
        $session = \WP_Session_Tokens::get_instance($uid)->get_session($verifier);
        return is_array($session) && (int) ($session["expiration"] ?? 0) >= time() ? $session : null;
    }

    /** Ends the WordPress session; nothing when it already ended. */
    public static function end(int $uid, string $verifier): void
    {
        \WP_Session_Tokens::get_instance($uid)->update_session($verifier, null);
    }
}
