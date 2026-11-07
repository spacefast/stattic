<?php

declare(strict_types=1);

namespace Zero\Identity;

/**
 * `Authorization: Bearer <access token>` on WordPress: a `determine_current_user` filter at
 * priority 20, after core's cookie and application-password resolvers, with the same checks as
 * UserInfo (signature, issuer, expiry, unrevoked jti and session, active account, enabled app).
 *
 * A presented token that does not resolve is an authentication error, not an anonymous request:
 * `rest_authentication_errors` answers 401 `invalid_token` (403 `suspended` when the signature is
 * ours but the account is suspended or deleted). A consumer that renders its own errors can take
 * the reason through `failure()` and opt out with the `zero_identity_bearer_error` filter.
 */
final class Bearer
{
    private static ?string $failure = null;
    private static ?array $token = null;

    public static function resolve(mixed $userId): mixed
    {
        self::$token = null;
        self::$failure = null;
        $raw = self::header();
        // An application password (core, priority 20 before us) wins; a logged-in WordPress cookie
        // doesn't: REST discards a cookie user without a nonce, and an explicit Bearer is the
        // caller's chosen identity.
        if ($userId && ($raw === null || self::appPassword())) {
            return $userId;
        }
        if ($raw === null) {
            return $userId;
        }
        try {
            $token = (new OAuth(Plugin::$db, Plugin::$accounts))->access($raw);
            if ($token === null) {
                $claims = Keys::verify($raw, true);
                $status = $claims !== null && is_string($claims["sub"] ?? null) && ctype_digit($claims["sub"])
                    ? Plugin::$accounts->status((int) $claims["sub"])
                    : "none";
                self::$failure = in_array($status, ["suspended", "deletion_requested", "deleted"], true) ? "suspended" : "invalid_token";
                return $userId;
            }
            self::$failure = null;
            self::$token = $token;
            return $token["uid"];
        } catch (Failure $e) {
            self::$failure = $e->kind;
            return 0;
        }
    }

    private static function appPassword(): bool
    {
        return (bool) ($GLOBALS["wp_rest_application_password_status"] ?? false) && !is_wp_error($GLOBALS["wp_rest_application_password_status"]);
    }

    /** rest_authentication_errors: a failed Bearer becomes an error unless a consumer handles it. */
    public static function errors(mixed $error): mixed
    {
        if (self::$token !== null) {
            // Authenticated by its Bearer: answering `true` keeps core's cookie-nonce check
            // (priority 100) from logging out a request that also carried a WordPress cookie.
            return $error ?? true;
        }
        if ($error !== null || self::$failure === null) {
            return $error;
        }
        if (self::$failure === "storage_unavailable") {
            return new \WP_Error("storage_unavailable", "Identity storage is unavailable. Please retry.", ["status" => 503]);
        }
        $suspended = self::$failure === "suspended";
        $wpError = new \WP_Error(
            self::$failure,
            $suspended ? "This account is suspended." : "The access token is invalid, expired or revoked.",
            ["status" => $suspended ? 403 : 401],
        );
        return apply_filters("zero_identity_bearer_error", $wpError, self::$failure);
    }

    /** Why the presented Bearer token failed this request: 'invalid_token', 'suspended' or null. */
    public static function failure(): ?string
    {
        return self::$failure;
    }

    /** The access token that authenticated this request (null for cookies/app passwords). */
    public static function token(): ?array
    {
        return self::$token;
    }

    private static function header(): ?string
    {
        $header = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";
        if (!is_string($header) || !preg_match('/^Bearer\s+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\s*$/D', $header, $m)) {
            return null;
        }
        return $m[1];
    }
}
