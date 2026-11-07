<?php

declare(strict_types=1);

namespace Zero\Identity;

use Zero\Identity\Protocol\AuthCode;
use Zero\Identity\Protocol\OAuthError;

/**
 * The OpenID Connect issuer: authorization code + PKCE (S256, public clients only), refresh token
 * rotation with family revocation on reuse, UserInfo, RFC 7009 revocation and RP-initiated logout.
 *
 * Access tokens are RS256 JWTs (300 s) whose `jti` is a `tokens` row; refresh tokens are opaque
 * `zr_` strings stored as their sha256. Every token row carries the `family` of the grant it came
 * from, so revoking a family ends one application sign-in.
 */
final class OAuth
{
    public const ACCESS_TTL = 300;
    public const REFRESH_TTL = 2592000;
    private const SCOPES = ["openid", "profile", "email"];

    private AuthCode $codes;

    public function __construct(private DB $db, private Accounts $accounts)
    {
        $this->codes = new AuthCode($db);
    }

    public static function issuer(): string
    {
        return untrailingslashit(home_url("/identity"));
    }

    public static function discovery(): array
    {
        $base = self::issuer();
        return [
            "issuer" => $base,
            "authorization_endpoint" => $base . "/authorize",
            "token_endpoint" => $base . "/token",
            "userinfo_endpoint" => $base . "/userinfo",
            "revocation_endpoint" => $base . "/revoke",
            "end_session_endpoint" => $base . "/logout",
            "jwks_uri" => $base . "/jwks",
            "response_types_supported" => ["code"],
            "grant_types_supported" => ["authorization_code", "refresh_token"],
            "subject_types_supported" => ["public"],
            "id_token_signing_alg_values_supported" => ["RS256"],
            "token_endpoint_auth_methods_supported" => ["none"],
            "revocation_endpoint_auth_methods_supported" => ["none"],
            "code_challenge_methods_supported" => ["S256"],
            "scopes_supported" => self::SCOPES,
            "claims_supported" => ["sub", "iss", "aud", "exp", "iat", "nonce", "auth_time", "sid", "name", "email", "email_verified"],
        ];
    }

    /**
     * Validate an authorization request. Problems with the client or redirect URI never redirect
     * (RFC 6749 §4.1.2.1), so every failure here is a Failure shown to the user.
     *
     * @return array{client: array, redirect_uri: string, scope: string, scopes: list<string>, state: string, nonce: string, code_challenge: string}
     */
    public function authorization(array $query): array
    {
        $client = $this->client((string) ($query["client_id"] ?? ""));
        $redirect = (string) ($query["redirect_uri"] ?? "");
        if (!$client || !in_array($redirect, json_decode($client["redirects"], true), true)) {
            throw new Failure("invalid_client", "This application or its callback is not registered.");
        }
        $scopes = array_values(array_unique(array_filter(explode(" ", (string) ($query["scope"] ?? "")))));
        $state = (string) ($query["state"] ?? "");
        if (
            ($query["response_type"] ?? "") !== "code" ||
            !preg_match('/^[A-Za-z0-9_-]{16,255}$/D', (string) ($query["nonce"] ?? "")) ||
            strlen($state) < 16 ||
            strlen($state) > 512 ||
            !in_array("openid", $scopes, true) ||
            array_diff($scopes, self::SCOPES) !== []
        ) {
            throw new Failure(
                "invalid_authorization_request",
                "An OpenID request with state, nonce and S256 PKCE is required.",
            );
        }
        $challenge = AuthCode::requireChallenge($query["code_challenge"] ?? null, $query["code_challenge_method"] ?? null);
        if (isset($query["max_age"]) && (!is_scalar($query["max_age"]) || !ctype_digit((string) $query["max_age"]))) {
            throw new Failure("invalid_max_age", "max_age must be a nonnegative integer.");
        }
        if (isset($query["prompt"]) && !in_array($query["prompt"], ["login", "consent"], true)) {
            throw new Failure("unsupported_prompt", "This prompt is not supported.");
        }
        return [
            "client" => $client,
            "redirect_uri" => $redirect,
            "scope" => implode(" ", $scopes),
            "scopes" => $scopes,
            "state" => $state,
            "nonce" => (string) $query["nonce"],
            "code_challenge" => $challenge,
        ];
    }

    /** Approve an authorization request for a live identity session; returns the redirect URL. */
    public function authorize(array $query, array $session): string
    {
        return $this->db->transaction(function () use ($query, $session) {
            $request = $this->authorization($query);
            if (
                ($query["prompt"] ?? "") === "login" ||
                (isset($query["max_age"]) && (int) $session["verified_at"] < time() - (int) $query["max_age"])
            ) {
                throw new Failure("login_required", "A fresh sign-in is required.", 401);
            }
            $this->live((int) $session["wp_user_id"], (string) $session["id"], true);
            $code = $this->codes->issue([
                "wp_user_id" => (int) $session["wp_user_id"],
                "session_id" => $session["id"],
                "client_id" => $request["client"]["id"],
                "redirect_uri" => $request["redirect_uri"],
                "code_challenge" => $request["code_challenge"],
                "scope" => $request["scope"],
                "nonce" => $request["nonce"],
                "auth_time" => (int) $session["verified_at"],
            ]);
            return self::withQuery($request["redirect_uri"], ["code" => $code, "state" => $request["state"]]);
        });
    }

    /**
     * The token endpoint. Returns the RFC 6749 §5.1 body; failures throw OAuthError (§5.2).
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string, id_token?: string}
     */
    public function token(array $body): array
    {
        $clientId = (string) ($body["client_id"] ?? "");
        // Grant failures are returned, not thrown, so what they record commits: a consumed code
        // stays consumed and a replay's family revocation survives the error response.
        $result = $this->db->transaction(function () use ($body, $clientId) {
            if (!$this->client($clientId)) {
                return new OAuthError("invalid_client", "Unknown or disabled client.", 401);
            }
            return match ($body["grant_type"] ?? "") {
                "authorization_code" => $this->exchange($body, $clientId),
                "refresh_token" => $this->refresh((string) ($body["refresh_token"] ?? ""), $clientId),
                default => new OAuthError("unsupported_grant_type", "Use authorization_code or refresh_token."),
            };
        });
        if ($result instanceof OAuthError) {
            throw $result;
        }
        return $result;
    }

    private function exchange(array $body, string $clientId): array|OAuthError
    {
        $grant = $this->codes->redeem(
            (string) ($body["code"] ?? ""),
            $clientId,
            (string) ($body["redirect_uri"] ?? ""),
            (string) ($body["code_verifier"] ?? ""),
        );
        if (is_string($grant)) {
            return new OAuthError($grant, "The authorization code is invalid, expired or already used.");
        }
        $uid = (int) $grant["wp_user_id"];
        if (!$this->live($uid, $grant["session_id"], false)) {
            return new OAuthError("invalid_grant", "Session no longer active.");
        }
        $family = bin2hex(random_bytes(16));
        $this->codes->bind($grant["id"], $family);
        $tokens = $this->mint($uid, $clientId, $grant["session_id"], $grant["scope"], $family);
        if (in_array("openid", explode(" ", $grant["scope"]), true)) {
            $claims = [
                "iss" => self::issuer(),
                "aud" => $clientId,
                "sub" => (string) $uid,
                "iat" => time(),
                "exp" => time() + self::ACCESS_TTL,
                "nonce" => $grant["nonce"],
                "auth_time" => (int) $grant["auth_time"],
                "sid" => $grant["session_id"],
            ];
            foreach (self::claims($this->accounts->profile($uid), explode(" ", $grant["scope"])) as $name => $value) {
                $claims[$name] ??= $value;
            }
            $tokens["id_token"] = Keys::sign($claims);
        }
        return $tokens;
    }

    /** Rotate a refresh token. Presenting one that was already used revokes its whole family. */
    private function refresh(string $token, string $clientId): array|OAuthError
    {
        $invalid = new OAuthError("invalid_grant", "The refresh token is invalid, expired or revoked.");
        if (!preg_match('/^zr_[A-Za-z0-9_-]{43}$/D', $token)) {
            return $invalid;
        }
        $row = $this->db->row(
            "SELECT * FROM {$this->db->prefix}tokens WHERE id=%s AND kind='refresh' FOR UPDATE",
            self::digest($token),
        );
        if (!$row || !hash_equals($row["client_id"], $clientId)) {
            return $invalid;
        }
        if ($row["used_at"] !== null) {
            $this->revokeFamily($row["family"]);
            Plugin::$security->event((int) $row["wp_user_id"], "token.reuse_detected", ["client_id" => $clientId]);
            return $invalid;
        }
        if ((int) $row["revoked"] || (int) $row["expires_at"] <= time() || !$this->live((int) $row["wp_user_id"], $row["session_id"], false)) {
            return $invalid;
        }
        $tokens = $this->mint((int) $row["wp_user_id"], $clientId, $row["session_id"], $row["scope"], $row["family"]);
        $this->db->run(
            "UPDATE {$this->db->prefix}tokens SET used_at=UTC_TIMESTAMP(), replaced_by=%s WHERE id=%s",
            self::digest($tokens["refresh_token"]),
            $row["id"],
        );
        return $tokens;
    }

    /**
     * Mint a session + token pair for a WordPress user without a browser flow (guests, §6.2).
     * Only the Zero runtime may call it, and only after it has loaded.
     */
    public function issueForUser(int $uid, string $clientId): array
    {
        if (!did_action("zero_runtime_loaded")) {
            throw new \LogicException("issueForUser is reserved for the Zero runtime");
        }
        return $this->db->transaction(function () use ($uid, $clientId) {
            if (!$this->client($clientId)) {
                throw new Failure("invalid_client", "Unknown or disabled client.", 400);
            }
            $this->accounts->ensure($uid);
            $session = Plugin::$sessions->create($uid)["session"];
            return $this->mint($uid, $clientId, $session["id"], "openid profile email", bin2hex(random_bytes(16)));
        });
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string} */
    private function mint(int $uid, string $clientId, string $sessionId, string $scope, string $family): array
    {
        $jti = bin2hex(random_bytes(20));
        $now = time();
        $refresh = "zr_" . Security::token(32);
        foreach (
            [
                ["id" => $jti, "kind" => "access", "expires_at" => $now + self::ACCESS_TTL],
                ["id" => self::digest($refresh), "kind" => "refresh", "expires_at" => $now + self::REFRESH_TTL],
            ]
            as $row
        ) {
            $this->db->insert("tokens", $row + [
                "family" => $family,
                "wp_user_id" => $uid,
                "session_id" => $sessionId,
                "client_id" => $clientId,
                "scope" => $scope,
            ]);
        }
        return [
            "access_token" => Keys::sign([
                "iss" => self::issuer(),
                "aud" => $clientId,
                "sub" => (string) $uid,
                "jti" => $jti,
                "sid" => $sessionId,
                "iat" => $now,
                "nbf" => $now,
                "exp" => $now + self::ACCESS_TTL,
                "scope" => $scope,
            ]),
            "token_type" => "Bearer",
            "expires_in" => self::ACCESS_TTL,
            "refresh_token" => $refresh,
            "scope" => $scope,
        ];
    }

    /**
     * Resolve an access token the way UserInfo and Bearer do: signature + issuer + expiry, a live
     * unrevoked `jti` row for the same client and subject, an unrevoked session, an active account
     * and an enabled application. Null for anything else.
     *
     * @return ?array{uid: int, client_id: string, jti: string, session_id: string, family: string, scopes: list<string>}
     */
    public function access(string $raw): ?array
    {
        $claims = Keys::verify($raw);
        if ($claims === null || !is_string($claims["jti"] ?? null) || !is_string($claims["sub"] ?? null)) {
            return null;
        }
        $row = $this->db->row(
            "SELECT t.* FROM {$this->db->prefix}tokens t JOIN {$this->db->prefix}sessions s ON s.id=t.session_id JOIN {$this->db->prefix}accounts a ON a.wp_user_id=t.wp_user_id JOIN {$this->db->prefix}applications c ON c.id=t.client_id WHERE t.id=%s AND t.kind='access' AND t.revoked=0 AND t.expires_at>%d AND s.revoked_at IS NULL AND a.status='active' AND c.enabled=1",
            $claims["jti"],
            time(),
        );
        if (!$row || $row["client_id"] !== ($claims["aud"] ?? null) || (string) $row["wp_user_id"] !== $claims["sub"]) {
            return null;
        }
        return [
            "uid" => (int) $row["wp_user_id"],
            "client_id" => $row["client_id"],
            "jti" => $row["id"],
            "session_id" => $row["session_id"],
            "family" => $row["family"],
            "scopes" => explode(" ", $row["scope"]),
        ];
    }

    public function userinfo(string $raw): array
    {
        $token = $this->access($raw) ?? throw new Failure("invalid_token", "The access token is invalid or revoked.", 401);
        return self::claims($this->accounts->profile($token["uid"]), $token["scopes"]);
    }

    /**
     * RFC 7009: a refresh token revokes its family, an access token its own `jti`. Unknown tokens
     * and other clients' tokens succeed silently.
     */
    public function revoke(string $token, string $clientId): void
    {
        $this->db->transaction(function () use ($token, $clientId) {
            if (str_starts_with($token, "zr_")) {
                $row = $this->db->row(
                    "SELECT family,client_id FROM {$this->db->prefix}tokens WHERE id=%s AND kind='refresh'",
                    self::digest($token),
                );
                if ($row && hash_equals($row["client_id"], $clientId)) {
                    $this->revokeFamily($row["family"]);
                }
                return;
            }
            $claims = Keys::verify($token);
            if ($claims !== null && ($claims["aud"] ?? null) === $clientId && is_string($claims["jti"] ?? null)) {
                $this->db->run(
                    "UPDATE {$this->db->prefix}tokens SET revoked=1 WHERE id=%s AND kind='access' AND client_id=%s",
                    $claims["jti"],
                    $clientId,
                );
            }
        });
    }

    /**
     * A guest's browser holds Bearer tokens but no identity cookie. A handoff turns a live guest
     * access token into a single-use, 60 s code that `/identity/authorize?handoff=` exchanges for
     * a first-party identity session, so the hosted screen can upgrade that same user (§3.5).
     * The handoff stays bound to the token's session, family and application: revoking either or
     * disabling the application first voids it.
     */
    public function handoff(string $access): string
    {
        $token = $this->access($access) ?? throw new Failure("invalid_token", "The access token is invalid or revoked.", 401);
        if (!in_array("zero_guest", (array) get_userdata($token["uid"])?->roles, true)) {
            throw new Failure("not_guest", "Only guest accounts use a handoff.", 403);
        }
        $secret = Security::token();
        $id = Plugin::$security->challenge(
            "guest_handoff",
            $token["uid"],
            $token["session_id"],
            ["family" => $token["family"], "client_id" => $token["client_id"]],
            $secret,
            60,
        );
        return $id . "." . $secret;
    }

    /** Consume a handoff and open an identity session for its still-live guest; returns the session. */
    public function redeemHandoff(string $handoff): array
    {
        [$id, $secret] = array_pad(explode(".", $handoff, 2), 2, "");
        return $this->db->transaction(function () use ($id, $secret) {
            $challenge = Plugin::$security->consume($id, "guest_handoff", $secret);
            $uid = (int) $challenge["wp_user_id"];
            $this->db->lock($uid);
            $live = $this->db->row(
                "SELECT t.id FROM {$this->db->prefix}tokens t JOIN {$this->db->prefix}sessions s ON s.id=t.session_id JOIN {$this->db->prefix}applications c ON c.id=t.client_id WHERE t.family=%s AND t.client_id=%s AND t.session_id=%s AND t.wp_user_id=%d AND t.revoked=0 AND s.revoked_at IS NULL AND c.enabled=1 LIMIT 1 FOR UPDATE",
                $challenge["payload"]["family"],
                $challenge["payload"]["client_id"] ?? "",
                $challenge["session_id"],
                $uid,
            );
            if (!$live || !in_array("zero_guest", (array) get_userdata($uid)?->roles, true)) {
                throw new Failure("invalid_challenge", "The code is incorrect, used, or expired.");
            }
            return Plugin::$sessions->create($uid);
        });
    }

    /** End the application sign-in an access token belongs to (its whole family). */
    public function endFamily(string $family): void
    {
        $this->revokeFamily($family);
    }

    /**
     * RP-initiated logout: a valid `id_token_hint` (expired is fine) names the client and the
     * identity session to revoke; `post_logout_redirect_uri` must share an origin with one of
     * that client's registered callbacks. Returns the URL to redirect to.
     */
    public function logout(array $query): string
    {
        $hint = (string) ($query["id_token_hint"] ?? "");
        $target = (string) ($query["post_logout_redirect_uri"] ?? "");
        $claims = $hint === "" ? null : Keys::verify($hint, true);
        if ($hint !== "" && $claims === null) {
            throw new Failure("invalid_request", "id_token_hint is invalid.");
        }
        $client = $claims === null ? null : $this->client((string) ($claims["aud"] ?? ""), false);
        if ($target !== "") {
            $origins = $client === null ? [] : array_map(self::origin(...), json_decode($client["redirects"], true));
            if (!in_array(self::origin($target), $origins, true) || self::origin($target) === null) {
                throw new Failure("invalid_request", "post_logout_redirect_uri is not registered for this application.");
            }
        }
        if ($claims !== null && is_string($claims["sid"] ?? null)) {
            $session = $this->db->row(
                "SELECT wp_user_id FROM {$this->db->prefix}sessions WHERE id=%s",
                $claims["sid"],
            );
            if ($session && (string) $session["wp_user_id"] === ($claims["sub"] ?? null)) {
                Plugin::$sessions->revoke((int) $session["wp_user_id"], $claims["sid"]);
            }
        }
        if ($target === "") {
            return home_url("/identity");
        }
        return isset($query["state"]) && is_string($query["state"]) ? self::withQuery($target, ["state" => $query["state"]]) : $target;
    }

    public static function claims(array $profile, array $scopes): array
    {
        $claims = ["sub" => $profile["id"]];
        if (in_array("profile", $scopes, true)) {
            $claims["name"] = $profile["name"];
        }
        if (in_array("email", $scopes, true)) {
            foreach ($profile["emails"] as $email) {
                if ($email["primary"]) {
                    $claims["email"] = $email["email"];
                    $claims["email_verified"] = true;
                }
            }
        }
        return $claims;
    }

    /** Enabled applications whose origins may call the token, revoke and account endpoints. */
    public static function allowedOrigin(DB $db, string $origin): bool
    {
        if ($origin === "" || self::origin($origin) !== $origin) {
            return false;
        }
        foreach ($db->rows("SELECT origins FROM {$db->prefix}applications WHERE enabled=1") as $row) {
            if (in_array($origin, json_decode($row["origins"], true) ?: [], true)) {
                return true;
            }
        }
        return false;
    }

    private function client(string $id, bool $enabled = true): ?array
    {
        if ($id === "") {
            return null;
        }
        return $this->db->row(
            "SELECT * FROM {$this->db->prefix}applications WHERE id=%s" . ($enabled ? " AND enabled=1" : ""),
            $id,
        );
    }

    /**
     * Lock the account and session; an inactive account or a revoked session fails. Session
     * expiry governs the identity cookie only: an application sign-in lives as long as its
     * refresh family, until the session is revoked (sign-out, suspension, password change).
     */
    private function live(int $uid, string $sessionId, bool $throw): bool
    {
        $account = $this->db->row(
            "SELECT status FROM {$this->db->prefix}accounts WHERE wp_user_id=%d FOR UPDATE",
            $uid,
        );
        $session = $this->db->row(
            "SELECT id FROM {$this->db->prefix}sessions WHERE id=%s AND wp_user_id=%d AND revoked_at IS NULL FOR UPDATE",
            $sessionId,
            $uid,
        );
        $ok = $account && $account["status"] === "active" && $session;
        if (!$ok && $throw) {
            throw new Failure("login_required", "Session no longer active.", 401);
        }
        return (bool) $ok;
    }

    private function revokeFamily(string $family): void
    {
        if ($family !== "") {
            $this->db->run(
                "UPDATE {$this->db->prefix}tokens SET revoked=1 WHERE family=%s",
                $family,
            );
        }
    }

    private static function digest(string $token): string
    {
        return hash("sha256", $token);
    }

    private static function origin(string $url): ?string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts["scheme"], $parts["host"])) {
            return null;
        }
        return $parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : "");
    }

    private static function withQuery(string $url, array $params): string
    {
        return $url . (str_contains($url, "?") ? "&" : "?") . http_build_query($params, "", "&", PHP_QUERY_RFC3986);
    }
}
