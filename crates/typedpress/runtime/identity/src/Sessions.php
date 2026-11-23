<?php

declare(strict_types=1);

namespace TypedPress\Identity;

final class Sessions
{
    public const COOKIE = "zi_session";
    /** Seconds a reopened session's previous secret stays valid ({@see reopen()}). */
    public const REOPEN_GRACE = 60;
    /**
     * Sibling hosts under a shared parent domain can plant Domain-scoped cookies; the __Host-
     * prefix makes the browser refuse any such cookie under this name. The prefix requires
     * Secure and Path=/, so plain-HTTP development keeps the bare name.
     */
    public static function cookieName(string $name = self::COOKIE): string
    {
        return is_ssl() ? "__Host-" . $name : $name;
    }
    public function __construct(private DB $db, private Security $security) {
        // Session cookies on the scoped REST surface are opt-in: a host serving its own frontend
        // (`tp_identity_rest_cookie_auth`).
        add_filter('rest_authentication_errors', function ($result) {
            if (is_wp_error($result) || is_user_logged_in() || ($result !== null && $result !== true)
                || !self::restCookieAuth()) { return $result; }
            // TypedPress's API, the REST namespace typedpress/v1 (identity loads without TypedPress, so
            // the namespace is literal).
            $route = strtolower(untrailingslashit((string) ($GLOBALS["wp"]->query_vars["rest_route"] ?? "")));
            if ($route !== "/typedpress/v1" && !str_starts_with($route, "/typedpress/v1/")) { return $result; }
            // The cookie is ambient: it lends no identity to another origin's request (a sibling
            // host under the same site), a cross-site fetch, or a JSONP script include.
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            if (($origin !== '' && $origin !== self::siteOrigin()) || isset($_GET['_jsonp'])
                || !in_array((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''), ['', 'same-origin', 'none'], true)) {
                return $result;
            }
            $secret = (string) ($_COOKIE[self::cookieName()] ?? '');
            try {
                $session = $this->find($secret);
            } catch (Failure $e) {
                return new \WP_Error($e->kind, $e->getMessage(), ['status' => $e->status]);
            }
            if (!$session) { return $result; }
            // A write proves intent by coming from the site's own pages or by the session's CSRF token.
            // WordPress applies `?_method=` and X-HTTP-Method-Override after authentication: a request
            // is a read only when its method and every override it carries are reads.
            $methods = array_map(static fn ($m) => strtoupper((string) $m), array_filter([$_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET['_method'] ?? null, $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null], static fn ($m) => is_string($m) && $m !== ''));
            if (array_diff($methods, ['GET', 'HEAD', 'OPTIONS']) !== [] && $origin === ''
                && !hash_equals((string) $session['csrf'], (string) ($_SERVER['HTTP_X_IDENTITY_CSRF'] ?? ''))) {
                return new \WP_Error('invalid_csrf', 'Refresh the page and try again.', ['status' => 403]);
            }
            wp_set_current_user((int) $session['wp_user_id']);
            // A page cache keyed only on the URL (Batcache) must never store this user's answer.
            if (function_exists('batcache_cancel')) { batcache_cancel(); }
            return true;
        }, 110);
        // A response to a request carrying the session cookie is that user's: never a shared copy.
        add_filter('rest_send_nocache_headers', static fn ($send) => $send
            || (self::restCookieAuth() && (string) ($_COOKIE[self::cookieName()] ?? '') !== ''));
    }
    private static function restCookieAuth(): bool
    {
        return (bool) apply_filters('tp_identity_rest_cookie_auth', false);
    }
    /**
     * Whether this request comes from the site's own pages: an `Origin` that is the site's, or
     * (where the browser sends no `Origin`, a GET) `Sec-Fetch-Site: same-origin`. A request that
     * says neither (a server-side client, an old browser) isn't treated as the site's.
     */
    public static function fromSite(): bool
    {
        $origin = (string) ($_SERVER["HTTP_ORIGIN"] ?? "");
        if ($origin !== "") {
            return $origin === self::siteOrigin();
        }
        return (string) ($_SERVER["HTTP_SEC_FETCH_SITE"] ?? "") === "same-origin";
    }
    private static function siteOrigin(): string
    {
        $home = wp_parse_url(home_url());
        return $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
    }
    /**
     * A new identity session for $uid. A session opened from a WordPress sign-in
     * ({@see Plugin::browserSession}) passes that WordPress session's verifier as `$wpToken`, when
     * its sign-in happened as `$verifiedAt` and when it ends as `$until`: it is never fresher, nor
     * longer-lived, than what it came from, and it ends when that WordPress session ends.
     */
    public function create(int $uid, ?int $verifiedAt = null, ?int $until = null, ?string $wpToken = null): array
    {
        return $this->db->transaction(function () use ($uid, $verifiedAt, $until, $wpToken) {
            $this->db->lock($uid);
            $secret = Security::token();
            $session = [
                "id" => bin2hex(random_bytes(16)),
                "wp_user_id" => $uid,
                "secret_hash" => self::digest($secret),
                "csrf" => bin2hex(random_bytes(32)),
                "created_at" => time(),
                "expires_at" => min(time() + 86400, $until ?? PHP_INT_MAX),
                "verified_at" => min(time(), $verifiedAt ?? PHP_INT_MAX),
                "label" => substr(
                    sanitize_text_field(
                        $_SERVER["HTTP_USER_AGENT"] ?? "Server session",
                    ),
                    0,
                    200,
                ),
            ];
            if ($wpToken !== null) {
                $session["wp_token"] = $wpToken;
            }
            $this->db->insert("sessions", $session);
            $this->security->event($uid, "session.created");
            return ["secret" => $secret, "session" => $session];
        });
    }
    public static function digest(string $secret): string
    {
        return hash("sha256", "typedpress-identity-session-v1\0" . $secret);
    }
    public function find(string $secret): ?array
    {
        if ($secret === "" || strlen($secret) > 512 || !Schema::ready()) {
            return null;
        }
        if (preg_match("/\\A[A-Za-z0-9_]{1,56}\\z/", $this->db->prefix) !== 1) {
            throw new \InvalidArgumentException("Invalid identity table prefix");
        }
        $session = $this->db->row(
            "SELECT s.* FROM {$this->db->prefix}sessions s JOIN {$this->db->prefix}accounts a ON a.wp_user_id=s.wp_user_id WHERE s.secret_hash=%s AND s.revoked_at IS NULL AND s.expires_at>%d AND a.status='active'",
            self::digest($secret),
            time(),
        );
        if ($session && !hash_equals((string) $session['secret_hash'], self::digest($secret))) {
            $session = null;
        }
        // A secret {@see reopen()} replaced a moment ago (parallel first requests of one browser).
        if (!$session) {
            $session = $this->db->row(
                "SELECT s.* FROM {$this->db->prefix}sessions s JOIN {$this->db->prefix}accounts a ON a.wp_user_id=s.wp_user_id WHERE s.previous_secret_hash=%s AND s.previous_until>%d AND s.revoked_at IS NULL AND s.expires_at>%d AND a.status='active'",
                self::digest($secret),
                time(),
                time(),
            );
            if ($session && !hash_equals((string) $session['previous_secret_hash'], self::digest($secret))) {
                $session = null;
            }
        }
        // Opened from a WordPress sign-in: it ends with that WordPress session.
        if ($session && !self::linked($session)) {
            $this->db->run("UPDATE {$this->db->prefix}sessions SET revoked_at=%d WHERE id=%s AND revoked_at IS NULL", time(), $session["id"]);
            $this->db->run("UPDATE {$this->db->prefix}tokens SET revoked=1 WHERE session_id=%s", $session["id"]);
            return null;
        }
        return $session;
    }
    /**
     * Whether a session opened from a WordPress sign-in still has it: the WordPress session exists
     * (WordPress drops it at its own expiry). Every other session is its own.
     *
     * The identity session's 24-hour expiry is not part of this: it bounds the browser's cookie
     * ({@see find()} reads only unexpired sessions), not the application families started from it,
     * which live until they are revoked, as they do for a session made by Identity's own sign-in.
     * A family started through wp-login.php ends when its WordPress session does.
     */
    public static function linked(array $session): bool
    {
        $verifier = $session["wp_token"] ?? null;
        if (!is_string($verifier) || $verifier === "") {
            return true;
        }
        return WordPressSessions::find((int) $session["wp_user_id"], $verifier) !== null;
    }
    /**
     * The open session this browser already has for a WordPress session, given a new secret so a
     * browser that lost its identity cookie gets the same row back instead of a new one.
     */
    public function reopen(int $uid, string $verifier): ?array
    {
        if (!Schema::ready()) {
            return null;
        }
        return $this->db->transaction(function () use ($uid, $verifier) {
            $this->db->lock($uid);
            $row = $this->db->row(
                "SELECT * FROM {$this->db->prefix}sessions WHERE wp_user_id=%d AND wp_token=%s AND revoked_at IS NULL AND expires_at>%d ORDER BY created_at DESC LIMIT 1 FOR UPDATE",
                $uid,
                $verifier,
                time(),
            );
            if (!$row) {
                return null;
            }
            // The row's secret isn't stored, only its digest, so the browser gets a new one. Parallel
            // first requests each do this: the session keeps the digest it had until the replacement
            // was made, for a short grace, so a cookie set a moment ago keeps working.
            $secret = Security::token();
            $this->db->run(
                "UPDATE {$this->db->prefix}sessions SET previous_secret_hash=secret_hash, previous_until=%d, secret_hash=%s WHERE id=%s",
                time() + self::REOPEN_GRACE,
                self::digest($secret),
                $row["id"],
            );
            return ["secret" => $secret, "session" => ["secret_hash" => self::digest($secret)] + $row];
        });
    }
    public function require(?string $csrf = null): array
    {
        // This browser's session as the hosted screens see it ({@see Plugin::browserSession}): one
        // whose user isn't the browser's WordPress user gives way to the WordPress sign-in. Only a
        // request from this site's own pages opens one from the WordPress cookie: an application
        // origin (CORS with credentials, a same-site subdomain) needs the identity cookie itself,
        // or a WordPress sign-in alone would hand it the account's CSRF token.
        $session = Plugin::browserSession(self::fromSite());
        if (!$session) {
            throw new Failure("unauthenticated", "Sign in to continue.", 401);
        }
        if ($csrf !== null && !hash_equals($session["csrf"], $csrf)) {
            throw new Failure(
                "invalid_csrf",
                "Refresh the page and try again.",
                403,
            );
        }
        return $session;
    }
    public function recent(array $session): void
    {
        if ((int) $session["verified_at"] < time() - 300) {
            throw new Failure(
                "reverification_required",
                "Confirm it is you before continuing.",
                403,
            );
        }
    }
    public function reverify(array $s): void
    {
        $this->db->transaction(function () use ($s) {
            $this->db->lock((int) $s["wp_user_id"]);
            $live = $this->db->row(
                "SELECT * FROM {$this->db->prefix}sessions WHERE id=%s AND wp_user_id=%d FOR UPDATE",
                $s["id"],
                $s["wp_user_id"],
            );
            if (
                !$live ||
                $live["revoked_at"] !== null ||
                (int) $live["expires_at"] <= time()
            ) {
                throw new Failure(
                    "unauthenticated",
                    "This session expired or was revoked.",
                    401,
                );
            }
            $this->db->run(
                "UPDATE {$this->db->prefix}sessions SET verified_at=%d WHERE id=%s",
                time(),
                $s["id"],
            );
        });
    }
    /**
     * Revokes one session ($id) or all of a user's. One session opened from a WordPress sign-in also
     * ends that WordPress session, unless `$withWordPress` is false: a request that may end the
     * identity session but has no say over the browser's WordPress sign-in (an RP logout from a
     * client holding no cookie of this site).
     */
    public function revoke(int $uid, ?string $id = null, bool $withWordPress = true): void
    {
        $linked = $this->db->transaction(function () use ($uid, $id) {
            $this->db->run(
                "SELECT wp_user_id FROM {$this->db->prefix}accounts WHERE wp_user_id=%d FOR UPDATE",
                $uid,
            );
            $this->db->run(
                "UPDATE {$this->db->prefix}sessions SET revoked_at=%d WHERE wp_user_id=%d" .
                    ($id === null ? "" : " AND id=%s"),
                ...$id === null ? [time(), $uid] : [time(), $uid, $id],
            );
            if ($id === null) {
                // Sign-ins waiting on a second factor started before this revocation end too,
                // including one whose challenge was just consumed and is waiting on this lock.
                $this->db->run(
                    "UPDATE {$this->db->prefix}challenges SET expires_at=0 WHERE wp_user_id=%d AND purpose='mfa' AND expires_at>0",
                    $uid,
                );
                return null;
            }
            return $this->db->row("SELECT wp_token FROM {$this->db->prefix}sessions WHERE id=%s AND wp_user_id=%d", $id, $uid)["wp_token"] ?? null;
        });
        if ($id === null) {
            \WP_Session_Tokens::get_instance($uid)->destroy_all();
        } elseif ($withWordPress && is_string($linked) && $linked !== "") {
            // One sign-in: the WordPress session this one was opened from ends with it.
            WordPressSessions::end($uid, $linked);
        }
        $this->security->event(
            $uid,
            $id === null ? "session.global_revoked" : "session.revoked",
        );
    }
    public static function cookie(string $secret, int $expires): void
    {
        setcookie(self::cookieName(), $secret, [
            "expires" => $expires,
            "path" => "/",
            "secure" => is_ssl(),
            "httponly" => true,
            "samesite" => "Lax",
        ]);
    }
}
