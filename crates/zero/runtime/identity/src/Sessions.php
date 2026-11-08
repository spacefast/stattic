<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Sessions
{
    public const COOKIE = "zi_session";
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
        // (`zero_identity_rest_cookie_auth`) or a migrated host with legacy cookie aliases.
        add_filter('rest_authentication_errors', function ($result) {
            if (is_wp_error($result) || is_user_logged_in() || ($result !== null && $result !== true)
                || !self::restCookieAuth()) { return $result; }
            $route = (string) ($GLOBALS['wp']->query_vars['rest_route'] ?? '');
            if (!str_starts_with($route, '/zero/v1/')) { return $result; }
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
            // WordPress applies `?_method=` and X-HTTP-Method-Override after authentication, so the
            // method that decides is the one the request will dispatch as.
            $method = strtoupper((string) ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_GET['_method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $origin === ''
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
        return (bool) apply_filters('zero_identity_rest_cookie_auth', false)
            || apply_filters('zero_identity_session_cookie_aliases', []) !== [];
    }
    private static function siteOrigin(): string
    {
        $home = wp_parse_url(home_url());
        return $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
    }
    public function create(int $uid): array
    {
        return $this->db->transaction(function () use ($uid) {
            $this->db->lock($uid);
            $secret = Security::token();
            $session = [
                "id" => bin2hex(random_bytes(16)),
                "wp_user_id" => $uid,
                "secret_hash" => self::digest($secret),
                "csrf" => bin2hex(random_bytes(32)),
                "created_at" => time(),
                "expires_at" => time() + 86400,
                "verified_at" => time(),
                "label" => substr(
                    sanitize_text_field(
                        $_SERVER["HTTP_USER_AGENT"] ?? "Server session",
                    ),
                    0,
                    200,
                ),
            ];
            $this->db->insert("sessions", $session);
            $this->security->event($uid, "session.created");
            return ["secret" => $secret, "session" => $session];
        });
    }
    public static function digest(string $secret): string
    {
        return hash("sha256", "zero-identity-session-v1\0" . $secret);
    }
    public function find(string $secret): ?array
    {
        $alias = false;
        if ($secret === '') {
            foreach (apply_filters('zero_identity_session_cookie_aliases', []) as $name) {
                if (is_string($name) && is_string($_COOKIE[$name] ?? null) && $_COOKIE[$name] !== '') {
                    $secret = $_COOKIE[$name];
                    $alias = true;
                    break;
                }
            }
        }
        if ($secret === "" || strlen($secret) > 512) {
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
        $session ??= Legacy\SessionUpgrade::find($this->db, $secret);
        if ($alias && $session) {
            self::cookie($secret, (int) $session['expires_at']);
            $_COOKIE[self::cookieName()] = $secret;
        }
        return $session;
    }
    public function require(?string $csrf = null): array
    {
        $session = $this->find((string) ($_COOKIE[self::cookieName()] ?? ""));
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
    public function revoke(int $uid, ?string $id = null): void
    {
        $this->db->transaction(function () use ($uid, $id) {
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
            }
        });
        if ($id === null) {
            \WP_Session_Tokens::get_instance($uid)->destroy_all();
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
