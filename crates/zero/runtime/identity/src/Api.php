<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Api
{
    public function __construct(
        private DB $db,
        private Accounts $accounts,
        private Sessions $sessions,
        private Security $security,
        private Mfa $mfa,
    ) {}
    public function register(): void
    {
        register_rest_route(
            "zero-identity/v1",
            "/(?P<operation>[a-z0-9/-]+)",
            [
                "methods" => ["GET", "POST", "DELETE", "PATCH"],
                "permission_callback" => "__return_true",
                "callback" => [$this, "dispatch"],
            ],
        );
    }
    public function dispatch(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $method = $request->get_method();
            $op = $request["operation"];
            // Administration never accepts application origins, only the site's own.
            self::checkOrigin(
                (string) $request->get_header("origin"),
                $method !== "GET",
                str_starts_with((string) $op, "admin/"),
            );
            $data = $this->handle($op, $method, $request);
            $response = new \WP_REST_Response(["data" => $data], 200);
        } catch (Failure $e) {
            $response = new \WP_REST_Response(
                [
                    "error" => [
                        "code" => $e->kind,
                        "message" => $e->getMessage(),
                    ],
                ],
                $e->status,
            );
        } catch (\Throwable $e) {
            error_log(
                "Zero Identity: " . get_class($e) . " " . $e->getMessage(),
            );
            $response = new \WP_REST_Response(
                [
                    "error" => [
                        "code" => "internal_error",
                        "message" => "The request could not be completed.",
                    ],
                ],
                500,
            );
        }
        $response->header("Cache-Control", "no-store");
        return $response;
    }
    public static function checkOrigin(string $origin, bool $required, bool $siteOnly = false): void
    {
        $home = wp_parse_url(home_url());
        $expected =
            $home["scheme"] .
            "://" .
            $home["host"] .
            (isset($home["port"]) ? ":" . $home["port"] : "");
        if (
            ($required || $origin !== "") &&
            $origin !== $expected &&
            ($siteOnly || !OAuth::allowedOrigin(Plugin::$db, $origin))
        ) {
            throw new Failure(
                "invalid_origin",
                "Request origin is not allowed.",
                403,
            );
        }
    }
    private function field(
        \WP_REST_Request $r,
        string $key,
        int $max = 512,
    ): string {
        $value = $r->get_param($key);
        if (!is_string($value) || strlen($value) > $max) {
            throw new Failure("invalid_request", "Invalid " . $key . ".");
        }
        return $value;
    }
    /**
     * The second step of every sign-in: an MFA challenge or a new session. Cookies are written
     * only after the transaction commits, so a rollback never leaves a cookie for a row that
     * does not exist.
     */
    private function login(int $uid, ?callable $recheck = null): array
    {
        [$result, $cookie] = $this->db->transaction(function () use ($uid, $recheck) {
            $this->db->lock($uid);
            if ($recheck !== null) {
                $recheck();
            }
            if ($this->mfa->enabled($uid)) {
                $binding = Security::token();
                $id = $this->security->challenge("mfa", $uid, "", [], $binding);
                return [["kind" => "mfa_required", "challenge_id" => $id], ["zi_factor", $binding]];
            }
            $created = $this->sessions->create($uid);
            return [["kind" => "signed_in"], [Sessions::COOKIE, $created["secret"], $created["session"]["expires_at"]]];
        });
        if ($cookie[0] === "zi_factor") {
            setcookie(Sessions::cookieName("zi_factor"), $cookie[1], [
                "expires" => time() + 300,
                "path" => "/",
                "secure" => is_ssl(),
                "httponly" => true,
                "samesite" => "Strict",
            ]);
        } else {
            Sessions::cookie($cookie[1], $cookie[2]);
        }
        return $result;
    }
    public function issue(int $uid): array
    {
        $created = $this->sessions->create($uid);
        Sessions::cookie($created["secret"], $created["session"]["expires_at"]);
        return ["kind" => "signed_in"];
    }
    public function providerLogin(int $uid): array
    {
        return $this->login($uid);
    }
    private function handle(
        string $op,
        string $method,
        \WP_REST_Request $r,
    ): mixed {
        if ($op === "config" && $method === "GET") {
            return [
                "providers" => array_keys(Providers::config()),
                "issuer" => OAuth::issuer(),
            ];
        }
        if ($op === "sign-in/password" && $method === "POST") {
            $login = $this->field($r, "login", 254);
            $this->security->limit(
                "password:" . ($_SERVER["REMOTE_ADDR"] ?? ""),
            );
            $this->security->limit("password-account:" . strtolower($login));
            $password = $this->field($r, "password", 1024);
            $uid = $this->accounts->password($login, $password);
            // Check the password again under the account lock that session issuance holds: a reset
            // committed in between (which revokes sessions) must not be outlived by this sign-in.
            return $this->login($uid, function () use ($uid, $password): void {
                clean_user_cache($uid);
                $user = get_userdata($uid);
                if (!$user || !wp_check_password($password, $user->user_pass, $uid)) {
                    throw new Failure("invalid_credentials", "The sign-in details are incorrect.", 401);
                }
            });
        }
        if ($op === "sign-in/email" && $method === "POST") {
            $email = Accounts::email($this->field($r, "email", 254));
            $this->security->limit(
                "mail-ip:" . ($_SERVER["REMOTE_ADDR"] ?? ""),
                20,
            );
            $this->security->limit("mail:" . $email, 5);
            $code = (string) random_int(100000, 999999);
            $id = $this->security->challenge(
                "login",
                0,
                "",
                ["email" => $email],
                $code,
            );
            $this->security->mail($email, $code, "sign in", 0);
            return ["challenge_id" => $id];
        }
        if ($op === "sign-in/verify" && $method === "POST") {
            $c = $this->security->consume(
                $this->field($r, "challenge_id"),
                "login",
                $this->field($r, "code", 12),
            );
            $current = $this->sessions->find(
                (string) ($_COOKIE[Sessions::cookieName()] ?? ""),
            );
            return $this->login(
                $this->accounts->verifiedSignIn($c["payload"]["email"], $current),
            );
        }
        if ($op === "sign-in/mfa" && $method === "POST") {
            $this->security->limit("mfa:" . ($_SERVER["REMOTE_ADDR"] ?? ""));
            $code = $this->field($r, "code", 100);
            // The challenge is consumed in its own transaction (failed attempts must count), then
            // factor and session run under the account lock with the challenge rechecked: a password
            // reset expires pending challenges, so one that committed in between ends this sign-in.
            $c = $this->security->consume(
                $this->field($r, "challenge_id"),
                "mfa",
                (string) ($_COOKIE[Sessions::cookieName("zi_factor")] ?? ""),
            );
            [$result, $cookie] = $this->db->transaction(function () use ($c, $code) {
                $uid = (int) $c["wp_user_id"];
                $this->db->lock($uid);
                $live = $this->db->row(
                    "SELECT expires_at FROM {$this->db->prefix}challenges WHERE id=%s FOR UPDATE",
                    $c["id"],
                );
                if (!$live || (int) $live["expires_at"] <= time()) {
                    throw new Failure("invalid_challenge", "The code is incorrect, used, or expired.");
                }
                if (!$this->mfa->verify($uid, $code)) {
                    throw new Failure(
                        "invalid_factor",
                        "Authenticator or recovery code is invalid. Sign in again.",
                        401,
                    );
                }
                $created = $this->sessions->create($uid);
                return [["kind" => "signed_in"], [$created["secret"], $created["session"]["expires_at"]]];
            });
            Sessions::cookie($cookie[0], $cookie[1]);
            return $result;
        }
        if ($op === "sign-in/passkey/options" && $method === "POST") {
            $this->security->limit(
                "passkey:" . ($_SERVER["REMOTE_ADDR"] ?? ""),
                20,
            );
            return (new Passkeys($this->db, $this->security))->options(null);
        }
        if ($op === "sign-in/passkey/verify" && $method === "POST") {
            $credential = $r->get_param("credential");
            if (!is_array($credential)) {
                throw new Failure("invalid_passkey", "Invalid credential.");
            }
            $uid = (new Passkeys($this->db, $this->security))->finish(
                $this->field($r, "challenge_id"),
                $credential,
                null,
            );
            return $this->login($uid);
        }
        if (str_starts_with($op, "admin/")) {
            return $this->admin(substr($op, 6), $method, $r);
        }
        $s = $this->sessions->require(
            $method === "GET"
                ? null
                : (string) $r->get_header("x-identity-csrf"),
        );
        $uid = (int) $s["wp_user_id"];
        if ($op === "account" && $method === "GET") {
            return [
                "account" => $this->accounts->profile($uid) + [
                    "guest" => in_array(
                        "zero_guest",
                        (array) get_userdata($uid)->roles,
                        true,
                    ),
                ],
                "session" => [
                    "id" => $s["id"],
                    "expires_at" => (int) $s["expires_at"],
                    "verified_at" => (int) $s["verified_at"],
                ],
                "csrf" => $s["csrf"],
                "mfa_enabled" => $this->mfa->enabled($uid),
            ];
        }
        if ($op === "account" && $method === "PATCH") {
            $name = sanitize_text_field($this->field($r, "name", 120));
            if ($name === "") {
                throw new Failure("invalid_name", "Enter your name.");
            }
            wp_update_user(["ID" => $uid, "display_name" => $name]);
            return $this->accounts->profile($uid);
        }
        if ($op === "sessions" && $method === "GET") {
            return array_map(
                fn($row) => [
                    "id" => $row["id"],
                    "created_at" => (int) $row["created_at"],
                    "expires_at" => (int) $row["expires_at"],
                    "label" => $row["label"],
                ],
                $this->db->rows(
                    "SELECT * FROM {$this->db->prefix}sessions WHERE wp_user_id=%d AND revoked_at IS NULL AND expires_at>%d",
                    $uid,
                    time(),
                ),
            );
        }
        if ($op === "sessions/revoke" && $method === "POST") {
            $this->sessions->revoke($uid, $this->field($r, "id"));
            return ["revoked" => true];
        }
        if ($op === "sign-out" && $method === "POST") {
            $this->sessions->revoke($uid, $s["id"]);
            Sessions::cookie("", time() - 3600);
            return ["signed_out" => true];
        }
        if ($op === "reverify" && $method === "POST") {
            $this->security->limit("reverify:" . $uid);
            $verified = $this->accounts->password(
                get_userdata($uid)->user_login,
                $this->field($r, "password", 1024),
            );
            if (
                $verified !== $uid ||
                ($this->mfa->enabled($uid) &&
                    !$this->mfa->verify($uid, $this->field($r, "code", 100)))
            ) {
                throw new Failure(
                    "invalid_factor",
                    "Verification failed.",
                    401,
                );
            }
            $this->sessions->reverify($s);
            return ["verified" => true];
        }
        if ($op === "reverify/email" && $method === "POST") {
            $this->security->limit("reverify-mail:" . $uid, 5);
            $email = $this->db->row(
                "SELECT e.normalized FROM {$this->db->prefix}emails e JOIN {$this->db->prefix}accounts a ON a.primary_email_id=e.id WHERE a.wp_user_id=%d",
                $uid,
            );
            if (!$email) {
                throw new Failure(
                    "verified_email_required",
                    "Add a verified email first.",
                    409,
                );
            }
            $code = (string) random_int(100000, 999999);
            $id = $this->security->challenge(
                "reverify",
                $uid,
                $s["id"],
                [],
                $code,
            );
            $this->security->mail(
                $email["normalized"],
                $code,
                "confirm your identity",
                $uid,
            );
            return ["challenge_id" => $id];
        }
        if ($op === "reverify/verify" && $method === "POST") {
            $this->security->consume(
                $this->field($r, "challenge_id"),
                "reverify",
                $this->field($r, "code", 12),
                $s["id"],
            );
            if (
                $this->mfa->enabled($uid) &&
                !$this->mfa->verify($uid, $this->field($r, "factor", 100))
            ) {
                throw new Failure(
                    "invalid_factor",
                    "Authenticator verification failed.",
                    401,
                );
            }
            $this->sessions->reverify($s);
            return ["verified" => true];
        }
        $this->sessions->recent($s);
        if (Accounts::isGuest($uid)) {
            // A guest saves its account through the email upgrade only: no passkeys, factors,
            // providers or passwords that a former holder of the guest could keep using.
            throw new Failure("guest_account", "Add your email first to save this account.", 403);
        }
        if ($op === "emails/verify" && $method === "POST") {
            $c = $this->security->consume(
                $this->field($r, "challenge_id"),
                "add_email",
                $this->field($r, "code", 12),
                $s["id"],
            );
            return $this->db->credentialMutation(
                $s,
                fn() => [
                    "id" => (string) $this->accounts->claim(
                        $uid,
                        $c["payload"]["email"],
                        "email_code",
                    ),
                ],
            );
        }
        if ($op === "passkeys/register" && $method === "POST") {
            $credential = $r->get_param("credential");
            if (!is_array($credential)) {
                throw new Failure("invalid_passkey", "Invalid credential.");
            }
            (new Passkeys($this->db, $this->security))->finish(
                $this->field($r, "challenge_id"),
                $credential,
                $s,
            );
            return ["registered" => true];
        }
        $this->security->limit("sensitive:" . $uid . ":" . $op, 30, 300);
        return $this->db->credentialMutation(
            $s,
            fn() => $this->sensitive($op, $method, $r, $s),
        );
    }
    private function sensitive(
        string $op,
        string $method,
        \WP_REST_Request $r,
        array $s,
    ): mixed {
        $uid = (int) $s["wp_user_id"];
        if ($op === "passkeys" && $method === "GET") {
            return $this->db->rows(
                "SELECT id,name,created_at FROM {$this->db->prefix}passkeys WHERE wp_user_id=%d",
                $uid,
            );
        }
        if ($op === "passkeys/options" && $method === "POST") {
            return (new Passkeys($this->db, $this->security))->options($s);
        }
        if ($op === "passkeys/remove" && $method === "POST") {
            $this->db->transaction(function () use ($uid, $r) {
                $a = $this->db->lock($uid);
                if (!$a["primary_email_id"]) {
                    throw new Failure(
                        "recovery_email_required",
                        "Verify a recovery email before removing a passkey.",
                        409,
                    );
                }
                $this->db->run(
                    "DELETE FROM {$this->db->prefix}passkeys WHERE id=%s AND wp_user_id=%d",
                    $this->field($r, "id"),
                    $uid,
                );
            });
            return ["removed" => true];
        }
        if ($op === "sessions/revoke-all" && $method === "POST") {
            $this->sessions->revoke($uid);
            Sessions::cookie("", time() - 3600);
            return ["revoked" => true];
        }
        if ($op === "emails" && $method === "POST") {
            $email = Accounts::email($this->field($r, "email", 254));
            $this->security->limit("add-email:" . $uid, 5);
            $code = (string) random_int(100000, 999999);
            $id = $this->security->challenge(
                "add_email",
                $uid,
                $s["id"],
                ["email" => $email],
                $code,
            );
            $this->security->mail($email, $code, "add email", $uid);
            return ["challenge_id" => $id];
        }
        if ($op === "emails/primary" && $method === "POST") {
            $this->accounts->primary($uid, (int) $this->field($r, "id"));
            return ["updated" => true];
        }
        if ($op === "emails/remove" && $method === "POST") {
            $this->accounts->removeEmail($uid, (int) $this->field($r, "id"));
            return ["removed" => true];
        }
        if ($op === "providers/remove" && $method === "POST") {
            $account = $this->db->lock($uid);
            if (!$account["primary_email_id"]) {
                throw new Failure(
                    "recovery_email_required",
                    "Verify a recovery email before removing a provider.",
                    409,
                );
            }
            $this->db->run(
                "DELETE FROM {$this->db->prefix}providers WHERE id=%d AND wp_user_id=%d",
                (int) $this->field($r, "id"),
                $uid,
            );
            $this->security->event($uid, "provider.removed");
            return ["removed" => true];
        }
        if ($op === "providers/link" && $method === "POST") {
            $p = new Providers(
                $this->security,
                $this->accounts,
                $this->sessions,
            );
            return ["url" => $p->start($this->field($r, "provider", 32), $s)];
        }
        if ($op === "password" && $method === "POST") {
            $password = $this->field($r, "password", 1024);
            if (strlen($password) < 12) {
                throw new Failure(
                    "weak_password",
                    "Use at least 12 characters.",
                );
            }
            wp_set_password($password, $uid);
            $this->sessions->revoke($uid);
            $this->security->event($uid, "password.changed");
            return ["updated" => true];
        }
        if ($op === "mfa/setup" && $method === "POST") {
            return $this->mfa->setup($uid, get_userdata($uid)->user_login);
        }
        if ($op === "mfa/enable" && $method === "POST") {
            if (!$this->mfa->verify($uid, $this->field($r, "code", 12), true)) {
                throw new Failure(
                    "invalid_factor",
                    "Authenticator code is invalid.",
                );
            }
            $this->db->run(
                "UPDATE {$this->db->prefix}sessions SET revoked_at=%d WHERE wp_user_id=%d AND id<>%s AND revoked_at IS NULL",
                time(),
                $uid,
                $s["id"],
            );
            \WP_Session_Tokens::get_instance($uid)->destroy_all();
            $this->security->event($uid, "mfa.enabled");
            return ["recovery_codes" => $this->mfa->recoveryCodes($uid)];
        }
        if ($op === "mfa/recovery-codes" && $method === "POST") {
            if (!$this->mfa->verify($uid, $this->field($r, "code", 100))) {
                throw new Failure(
                    "invalid_factor",
                    "Authenticator verification failed.",
                    401,
                );
            }
            return ["recovery_codes" => $this->mfa->recoveryCodes($uid)];
        }
        if ($op === "mfa/remove" && $method === "POST") {
            if (!$this->mfa->verify($uid, $this->field($r, "code", 100))) {
                throw new Failure(
                    "invalid_factor",
                    "Authenticator verification failed.",
                    401,
                );
            }
            $this->db->run(
                "DELETE FROM {$this->db->prefix}mfa WHERE wp_user_id=%d",
                $uid,
            );
            $this->db->run(
                "DELETE FROM {$this->db->prefix}recovery_codes WHERE wp_user_id=%d",
                $uid,
            );
            $this->sessions->revoke($uid);
            return ["removed" => true];
        }
        if ($op === "account/export" && $method === "POST") {
            return (new Privacy($this->db, $this->security))->export($uid);
        }
        if ($op === "account/delete" && $method === "POST") {
            if ($this->field($r, "confirmation") !== "DELETE") {
                throw new Failure(
                    "confirmation_required",
                    "Type DELETE to confirm.",
                );
            }
            if (user_can($uid, "manage_options")) {
                throw new Failure(
                    "administrator_deletion",
                    "Transfer administration before requesting deletion.",
                    409,
                );
            }
            (new Privacy(
                $this->db,
                $this->security,
            ))->requireTransferredOwnership($uid);
            $this->accounts->suspend($uid, true);
            $this->db->run(
                "UPDATE {$this->db->prefix}accounts SET status='deletion_requested' WHERE wp_user_id=%d",
                $uid,
            );
            $this->security->event($uid, "account.deletion_requested");
            return ["status" => "deletion_requested"];
        }
        if (str_starts_with($op, "organizations")) {
            return (new Organizations(
                $this->db,
                $this->security,
                $this->accounts,
            ))->handle($op, $method, $r, $uid);
        }
        throw new Failure("not_found", "Endpoint not found.", 404);
    }
    private function admin(
        string $op,
        string $method,
        \WP_REST_Request $r,
    ): mixed {
        if (
            !current_user_can("manage_options") ||
            !wp_verify_nonce($r->get_header("x-wp-nonce"), "wp_rest")
        ) {
            throw new Failure(
                "forbidden",
                "Administrator access is required.",
                403,
            );
        }
        if ($method === "GET") {
            if ($op === "people") {
                return array_map(
                    fn($u) => $this->accounts->profile((int) $u["ID"]),
                    $this->db->rows(
                        "SELECT ID FROM {$this->db->wp->users} ORDER BY ID DESC LIMIT 200",
                    ),
                );
            }
            $table =
                [
                    "applications" => "applications",
                    "sessions" => "sessions",
                    "security-events" => "events",
                    "organizations" => "organizations",
                ][$op] ?? null;
            if ($table) {
                $fields =
                    $op === "sessions"
                        ? "id,wp_user_id,created_at,expires_at,revoked_at,label"
                        : "*";
                return $this->db->rows(
                    "SELECT $fields FROM {$this->db->prefix}{$table} LIMIT 200",
                );
            }
            if ($op === "webhooks") {
                return Webhooks::inventory($this->db);
            }
            if ($op === "authentication") {
                return [
                    "providers" => array_keys(Providers::config()),
                    "keys" => Keys::jwks(),
                    "email" => [
                        "transport" => "wp_mail",
                        "diagnostics" =>
                            "Security Events records mail transport acceptance and failures.",
                    ],
                ];
            }
        }
        if ($op === "applications" && $method === "POST") {
            $requestKey = $this->field($r, "idempotency_key", 64);
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestKey)) {
                throw new Failure(
                    "invalid_idempotency_key",
                    "Use a unique request key of 16 to 64 characters.",
                );
            }
            $id =
                "app_" .
                substr(
                    Security::hash(
                        "application:" .
                            get_current_user_id() .
                            ":" .
                            $requestKey,
                    ),
                    0,
                    32,
                );
            return Applications::add(
                $this->field($r, "name", 120),
                $this->field($r, "redirect_uri"),
                $this->field($r, "origin"),
                $id,
            );
        }
        if ($op === "applications/status" && $method === "POST") {
            $enabled = $r->get_param("enabled");
            if (!is_bool($enabled)) {
                throw new Failure(
                    "invalid_request",
                    "enabled must be a boolean.",
                );
            }
            $id = $this->field($r, "id", 64);
            $this->db->run(
                "UPDATE {$this->db->prefix}applications SET enabled=%d WHERE id=%s",
                $enabled ? 1 : 0,
                $id,
            );
            $this->security->event(
                get_current_user_id(),
                "application.status_changed",
                ["id" => $id, "enabled" => $enabled],
            );
            return ["updated" => true];
        }
        if ($op === "people/suspend" && $method === "POST") {
            $uid = (int) $this->field($r, "id");
            if ($uid === get_current_user_id()) {
                throw new Failure(
                    "self_suspend",
                    "Use another administrator to suspend this account.",
                    409,
                );
            }
            $this->accounts->suspend($uid, $r->get_param("suspended") === true);
            return ["updated" => true];
        }
        if ($op === "people/erase" && $method === "POST") {
            return (new Privacy($this->db, $this->security))->erase(
                (int) $this->field($r, "id"),
            );
        }
        if ($op === "sessions/revoke" && $method === "POST") {
            $this->sessions->revoke(
                (int) $this->field($r, "user_id"),
                $this->field($r, "id"),
            );
            return ["revoked" => true];
        }
        if ($op === "webhooks" && $method === "POST") {
            return Webhooks::register($this->field($r, "url", 2048));
        }
        if ($op === "webhooks/remove" && $method === "POST") {
            Webhooks::remove($this->field($r, "id", 64));
            return ["removed" => true];
        }
        if ($op === "keys/rotate" && $method === "POST") {
            Keys::rotate();
            $this->security->event(get_current_user_id(), "keys.rotated");
            return Keys::jwks();
        }
        throw new Failure("not_found", "Endpoint not found.", 404);
    }
}
