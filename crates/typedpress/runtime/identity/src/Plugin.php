<?php

declare(strict_types=1);

namespace TypedPress\Identity;

final class Plugin
{
    /** Equal to the typedpress-runtime version built alongside it (`Runtime::identity()` compares them). */
    /**
     * Identity's authenticator as a Two Factor method ({@see TwoFactorProvider}). A key without
     * backslashes: Two Factor stores it in user meta and in the WordPress session, and WordPress's
     * meta writes unslash what they store. Here, not on the provider, so reading it never loads a
     * class that extends Two Factor's (a site without Two Factor has none).
     */
    public const TWO_FACTOR_KEY = "TypedPress_Identity_TwoFactorProvider";
    public const VERSION = "0.3.0";
    private static ?\WP_Error $authenticationError = null;
    public static DB $db;
    public static Accounts $accounts;
    public static Sessions $sessions;
    public static Security $security;
    public static Api $api;
    /** What core's application-password check last answered on this request: the user, the error, or null. */
    private static \WP_User|\WP_Error|null $applicationPassword = null;
    public static function applicationPassword(): \WP_User|\WP_Error|null
    {
        return self::$applicationPassword;
    }
    public static function authenticationError(): ?\WP_Error
    {
        $application = self::$applicationPassword;
        return self::$authenticationError ?? (is_wp_error($application) && $application->get_error_code() === "storage_unavailable" ? $application : null);
    }
    private static function refusal(Failure $error): \WP_Error
    {
        return new \WP_Error($error->kind, $error->getMessage(), ["status" => $error->status]);
    }
    /**
     * Whether a build from the store mounted identity (`TypedPress\Loader\Loader::load`): then booting
     * installs nothing, and {@see install()} runs when the host initializes the build.
     */
    public static function managed(): bool
    {
        return class_exists(\TypedPress\Loader\Loader::class, false) && \TypedPress\Loader\Loader::loaded() !== null;
    }

    /**
     * Everything identity writes to be ready on this site: the stored-name upgrade from before the
     * rename, the schema, the signing key, the password-check material and its inventory schedule.
     * What a plugin-installed site does on activation and boot; a managed build runs it from
     * `Loader::initialize`.
     */
    public static function install(): void
    {
        self::installStorage();
        Keys::current();
        Accounts::provisionPasswordHash();
    }

    private static function installStorage(): void
    {
        // A site that ran zero-identity (before the rename to TypedPress) keeps its accounts and sessions.
        Legacy\ZeroNames::upgrade(self::$db);
        if ((int) get_option("tp_identity_schema_version", 0) < Schema::VERSION) {
            Schema::migrate(self::$db);
        }
    }

    public static function boot(string $file): void
    {
        global $wpdb;
        self::$db = new DB($wpdb);
        self::$security = new Security(self::$db);
        self::$accounts = new Accounts(self::$db, self::$security);
        // A site that installs TypedPress as plugins installs identity on boot; a build mounted from the
        // store installs nothing until the host initializes it (install()).
        if (!self::managed()) {
            add_action("plugins_loaded", [Accounts::class, "provisionPasswordHash"]);
        }
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['tp_identity_minute'] = ['interval' => 60, 'display' => 'Every minute'];
            return $schedules;
        });
        add_action('tp_identity_password_inventory', [Accounts::class, 'inventoryPasswordHashes']);
        self::$sessions = new Sessions(self::$db, self::$security);
        self::$api = new Api(
            self::$db,
            self::$accounts,
            self::$sessions,
            self::$security,
            new Mfa(self::$db, self::$security),
        );
        if (!self::managed()) {
            self::installStorage();
        }
        add_action("rest_api_init", [self::$api, "register"]);
        add_filter(
            "rest_pre_serve_request",
            static function ($served, $result, $request) {
                if (
                    str_starts_with(
                        $request->get_route(),
                        "/typedpress-identity/v1/",
                    )
                ) {
                    header_remove("Access-Control-Allow-Origin");
                    header_remove("Access-Control-Allow-Credentials");
                    header("Vary: Origin", false);
                    self::cors(true);
                }
                return $served;
            },
            20,
            3,
        );
        add_action("template_redirect", fn() => self::page($file), 0);
        add_filter(
            "admin_body_class",
            static fn($classes) => $classes .
                (($_GET["page"] ?? "") === "typedpress-identity"
                    ? " zi-admin-screen"
                    : ""),
        );
        add_action("admin_menu", static function () use ($file) {
            add_menu_page(
                "Identity",
                "Identity",
                "manage_options",
                "typedpress-identity",
                fn() => self::adminPage($file),
                "dashicons-admin-users",
                3,
            );
        });
        // Failed password attempts through WordPress's own sign-in (wp-login.php, XML-RPC unless the
        // app turns it off, any caller of wp_authenticate) are limited per address and per login, with
        // the limits of Identity's own password sign-in ({@see Api}, `sign-in/password`). A failure is
        // counted when WordPress reports it; once a counter is full, every attempt is refused until
        // the window ends, the right password too. Core's handlers (priority 20) and the email one
        // below (21) answer whatever came before them, so the refusal comes after them.
        $buckets = static fn(string $login): array => [
            "wp-password:" . ($_SERVER["REMOTE_ADDR"] ?? ""),
            "wp-password-account:" . strtolower($login),
        ];
        add_filter(
            "authenticate",
            static function ($user, $login = "", $password = "") use ($buckets) {
                if (!is_string($login) || !is_string($password) || $login === "" || $password === "") {
                    return $user;
                }
                try {
                    foreach ($buckets($login) as $bucket) {
                        if (self::$security->limited($bucket)) {
                            return self::refusal(new Failure("rate_limited", "Too many attempts. Try again later.", 429));
                        }
                    }
                } catch (Failure $e) {
                    return self::refusal($e);
                }
                return $user;
            },
            30,
            3,
        );
        add_action(
            "wp_login_failed",
            static function ($login, $error = null) use ($buckets): void {
                // Not a wrong guess: Identity's own refusals, made after the password was accepted or
                // before anything was checked.
                $own = ["rate_limited", "storage_unavailable", "storage_conflict", "identity_mfa_required", "account_unavailable"];
                if (!is_string($login) || $login === "" || ($error instanceof \WP_Error && array_intersect($error->get_error_codes(), $own))) {
                    return;
                }
                foreach ($buckets($login) as $bucket) {
                    try {
                        self::$security->limit($bucket, PHP_INT_MAX);
                    } catch (Failure) {
                        // Storage can't count this one; the next attempt's check refuses if it still can't.
                    }
                }
            },
            10,
            2,
        );
        add_filter(
            "authenticate",
            static function ($user, $login, $password) {
                if (
                    $user instanceof \WP_User ||
                    !is_string($login) ||
                    !is_email($login)
                ) {
                    return $user;
                }
                if (
                    is_wp_error($user) &&
                    array_diff($user->get_error_codes(), [
                        "invalid_username",
                        "invalid_email",
                        "incorrect_password",
                    ])
                ) {
                    return $user;
                }
                try {
                    $uid = self::$accounts->owner($login);
                } catch (Failure $e) {
                    return self::refusal($e);
                }
                $account = $uid ? get_userdata($uid) : false;
                // Core already checked this account's password: checking it again would make a
                // wrong password for a known address slower than for an unknown one.
                if ($account && is_wp_error($user) && $user->get_error_code() === "incorrect_password"
                    && (int) (get_user_by("email", $login)->ID ?? 0) === $uid) {
                    return $user;
                }
                return $account
                    ? wp_authenticate_username_password(
                        null,
                        $account->user_login,
                        $password,
                    )
                    : $user;
            },
            21,
            3,
        );
        add_filter(
            "authenticate",
            static function ($user) {
                if ($user instanceof \WP_User) {
                    try {
                        $row = self::$db->row(
                            "SELECT status FROM " .
                                self::$db->prefix .
                                "accounts WHERE wp_user_id=%d",
                            $user->ID,
                        );
                    } catch (Failure $e) {
                        return self::refusal($e);
                    }
                    if ($row && $row["status"] !== "active") {
                        return new \WP_Error(
                            "account_unavailable",
                            "This account is unavailable.",
                        );
                    }
                }
                return $user;
            },
            100,
        );
        add_filter(
            "wp_authenticate_user",
            static function ($user, $password) {
                try {
                    if (
                        $user instanceof \WP_User &&
                        (new Mfa(self::$db, self::$security))->enabled(
                            (int) $user->ID,
                        )
                    ) {
                        // Core runs this filter before it checks the password: only someone who
                        // knows it learns that the account exists and has a second factor.
                        if (!wp_check_password((string) $password, $user->user_pass, $user->ID)) {
                            return new \WP_Error("incorrect_password", __("<strong>Error:</strong> Invalid username, email address or incorrect password."));
                        }
                        // wp-login.php asks for the factor itself when Two Factor will: it sends no
                        // cookie until the code checks out ({@see TwoFactorProvider}). Anywhere else
                        // (XML-RPC, a plugin calling wp_authenticate), a password alone is refused.
                        if (self::secondStep($user)) {
                            return $user;
                        }
                        return new \WP_Error(
                            "identity_mfa_required",
                            "This account needs its second factor, which this sign-in can't ask for.",
                        );
                    }
                    return $user;
                } catch (Failure $e) {
                    return self::refusal($e);
                }
            },
            100,
            2,
        );
        // Identity's authenticator is a Two Factor method: wp-login.php's second step for an account
        // with Identity MFA, whatever Two Factor's own settings say about other methods.
        add_filter("two_factor_provider_classname_" . self::TWO_FACTOR_KEY, static fn () => TwoFactorProvider::class);
        add_filter("two_factor_providers", static fn ($providers) => is_array($providers)
            // No path: Two Factor finds the class through identity's autoloader.
            ? $providers + [self::TWO_FACTOR_KEY => ""]
            : $providers, 20);
        // An account with Identity MFA has Identity's factor as its only second step: Two Factor's
        // other methods (emailed codes, its own TOTP) would be a weaker factor than the one the
        // account set up, and a WordPress sign-in made with one never opens an identity session
        // ({@see browserSession}).
        add_filter("two_factor_enabled_providers_for_user", static function ($enabled, $uid) {
            try {
                if ((new Mfa(self::$db, self::$security))->enabled((int) $uid)) {
                    return [self::TWO_FACTOR_KEY];
                }
            } catch (Failure) {
                // Storage can't say: Two Factor's own methods decide, and the password check
                // above still refuses an account whose factor can't be asked for.
            }
            return $enabled;
        }, PHP_INT_MAX, 2);
        // One sign-in: signing in on wp-login.php as someone else sets this browser's identity session
        // aside, and signing out of WordPress ends it.
        $identityCookie = static function (?int $keepFor): void {
            $secret = (string) ($_COOKIE[Sessions::cookieName()] ?? "");
            if ($secret === "") {
                return;
            }
            try {
                $session = self::$sessions->find($secret);
                if ($session && (int) $session["wp_user_id"] === $keepFor) {
                    return;
                }
                if ($session && $keepFor === null) {
                    self::$sessions->revoke((int) $session["wp_user_id"], (string) $session["id"]);
                }
            } catch (Failure) {
                // The cookie goes either way.
            }
            if (!headers_sent()) {
                Sessions::cookie("", time() - 3600);
            }
            unset($_COOKIE[Sessions::cookieName()]);
        };
        add_action("wp_login", static fn ($login, $user) => $identityCookie($user instanceof \WP_User ? (int) $user->ID : 0), 10, 2);
        add_action("wp_logout", static fn () => $identityCookie(null));
        // On the sign-in form (wp-login.php's, or `<LoginForm />`) when it signs in for Identity (an
        // application's authorization, the account screens): the ways in that need no WordPress
        // password, for accounts made with an email code, a passkey or a provider. They sign in to
        // Identity, not to the WordPress pages, so other sign-ins don't offer them.
        add_action("login_form", static function (): void {
            $redirect = is_string($_REQUEST["redirect_to"] ?? null) ? wp_unslash($_REQUEST["redirect_to"]) : "";
            if ($redirect !== self::url("") && !str_starts_with($redirect, self::url("/")) && !str_starts_with($redirect, self::url("?"))) {
                return;
            }
            $prefix = self::url("/authorize?");
            $return = str_starts_with($redirect, $prefix) ? Providers::continuation(substr($redirect, strlen(self::url("")))) : "";
            $url = add_query_arg(array_filter(["methods" => "1", "return_to" => $return !== "" ? rawurlencode($return) : null]), self::url(""));
            echo '<p class="typedpress-identity-methods"><a href="' . esc_url($url) . '">' . esc_html__("Sign in with an email code, a passkey or another account", "typedpress-identity") . "</a></p>";
        });
        $rememberPassword = static function ($uid): void {
            $user = get_userdata((int) $uid);
            if ($user instanceof \WP_User) {
                Accounts::rememberPasswordHash($user->user_pass);
            }
        };
        add_action("user_register", $rememberPassword);
        add_action("profile_update", $rememberPassword);
        add_action("wp_set_password", static fn ($password, $uid) => $rememberPassword($uid), 10, 2);
        // Every WordPress user write inside an identity transaction is recorded, so a rollback
        // (identity's own or its host's) clears what the object cache learned from it.
        $touched = static function ($uid): void {
            if (self::$db->inTransaction() && (int) $uid > 0) {
                self::$db->touch((int) $uid);
            }
        };
        foreach (["profile_update", "user_register", "wp_set_password", "set_user_role", "added_user_meta", "updated_user_meta", "deleted_user_meta"] as $hook) {
            add_action($hook, static function (...$args) use ($hook, $touched): void {
                $touched(match ($hook) {
                    "wp_set_password" => $args[1] ?? 0,
                    "added_user_meta", "updated_user_meta" => $args[1] ?? 0,
                    "deleted_user_meta" => $args[1] ?? 0,
                    default => $args[0] ?? 0,
                });
            }, 1, 4);
        }
        // A deleted WordPress user takes its identity (credentials, emails, account) with it.
        add_action("delete_user", static fn ($uid) => self::$accounts->forgetUser((int) $uid));
        // A password set anywhere (profile, reset, wp_set_password) ends every identity sign-in.
        $passwordChanged = static function ($user): void {
            $uid = $user instanceof \WP_User ? (int) $user->ID : (int) $user;
            if ($uid > 0 && self::$db->row("SELECT wp_user_id FROM " . self::$db->prefix . "accounts WHERE wp_user_id=%d", $uid)) {
                self::$sessions->revoke($uid);
            }
        };
        add_action("wp_set_password", static fn ($password, $uid) => $passwordChanged((int) $uid), 10, 2);
        add_action("after_password_reset", static fn ($user) => $passwordChanged($user));
        add_action("profile_update", static function ($uid, $old) use ($passwordChanged): void {
            $now = get_userdata((int) $uid);
            if ($now && $old instanceof \WP_User && $now->user_pass !== $old->user_pass) {
                $passwordChanged((int) $uid);
            }
        }, 10, 2);
        // Guests can't plant WordPress credentials (password, application passwords) a former
        // holder of the guest could keep after an upgrade: those belong to saved accounts.
        add_filter("wp_is_application_passwords_available_for_user", static fn ($available, $user) => $available && !($user instanceof \WP_User && in_array("tp_guest", (array) $user->roles, true)), 10, 2);
        // Application passwords skip the `authenticate` filter; refuse inactive accounts here too.
        add_action("wp_authenticate_application_password_errors", static function ($error, $user) {
            try {
                if ($user instanceof \WP_User && !in_array(self::$accounts->status((int) $user->ID), ["active", "none"], true)) {
                    $error->add("account_unavailable", "This account is unavailable.", ["status" => 403]);
                }
            } catch (Failure $e) {
                $error->add($e->kind, $e->getMessage(), ["status" => $e->status]);
            }
        }, 10, 2);
        // Core's users controller ignores an error from rest_pre_insert_user on update, so stop
        // the request before its callback runs.
        add_filter("rest_request_before_callbacks", static function ($response, $handler, $request) {
            if (!$request instanceof \WP_REST_Request || $request->get_param("password") === null
                || !preg_match('#^/wp/v2/users/(me|\d+)$#i', $request->get_route(), $m)) {
                return $response;
            }
            $id = strtolower($m[1]) === "me" ? get_current_user_id() : (int) $m[1];
            return $id > 0 && Accounts::isGuest($id)
                ? new \WP_Error("guest_account", "Add your email first to save this account.", ["status" => 403])
                : $response;
        }, 10, 3);
        add_action("user_profile_update_errors", static function ($errors, $update, $user) {
            if ($update && !empty($user->user_pass) && Accounts::isGuest((int) $user->ID)) {
                $errors->add("guest_account", "Add your email first to save this account.");
            }
        }, 10, 3);
        // Core's own application-password actions (what rest_application_password_collect_status records).
        add_action("application_password_did_authenticate", static function ($user): void {
            self::$applicationPassword = $user instanceof \WP_User ? $user : null;
        });
        add_action("application_password_failed_authentication", static function ($error): void {
            self::$applicationPassword = $error instanceof \WP_Error ? $error : null;
        });
        // Access tokens on the REST API (`Authorization: Bearer`), after core's own resolvers.
        add_filter("determine_current_user", [Bearer::class, "resolve"], 20);
        add_filter("rest_authentication_errors", [Bearer::class, "errors"], 20);
        // Core cookies and application passwords must also pass the live account check.
        add_filter("determine_current_user", static function ($uid) {
            self::$authenticationError = null;
            if (!$uid) {
                return $uid;
            }
            try {
                if (!in_array(self::$accounts->status((int) $uid), ["active", "none"], true)) {
                    self::$authenticationError = new \WP_Error("suspended", "This account is suspended.", ["status" => 403]);
                    return 0;
                }
            } catch (Failure $e) {
                self::$authenticationError = self::refusal($e);
                return 0;
            }
            return $uid;
        }, PHP_INT_MAX);
        add_filter("rest_authentication_errors", static fn ($error) => self::authenticationError() ?? $error, 99);
        add_action(
            "tp_identity_maintenance",
            fn() => (new Maintenance(self::$db, self::$security))->run(),
        );
        add_filter("wp_privacy_personal_data_exporters", static function (
            $exporters,
        ) {
            $exporters["typedpress-identity"] = [
                "exporter_friendly_name" => "Identity",
                "callback" => static function ($email) {
                    $uid = self::$accounts->owner($email);
                    return [
                        "data" => $uid
                            ? [
                                [
                                    "group_id" => "identity",
                                    "group_label" => "Identity account",
                                    "item_id" => "identity-" . $uid,
                                    "data" => [
                                        [
                                            "name" => "Account",
                                            "value" => wp_json_encode(
                                                (new Privacy(
                                                    self::$db,
                                                    self::$security,
                                                ))->export($uid),
                                            ),
                                        ],
                                    ],
                                ],
                            ]
                            : [],
                        "done" => true,
                    ];
                },
            ];
            return $exporters;
        });
        add_filter("wp_privacy_personal_data_erasers", static function (
            $erasers,
        ) {
            $erasers["typedpress-identity"] = [
                "eraser_friendly_name" => "Identity",
                "callback" => static function ($email) {
                    $uid = self::$accounts->owner($email);
                    if (!$uid) {
                        return [
                            "items_removed" => false,
                            "items_retained" => false,
                            "messages" => [],
                            "done" => true,
                        ];
                    }
                    try {
                        (new Privacy(self::$db, self::$security))->erase($uid);
                        return [
                            "items_removed" => true,
                            "items_retained" => false,
                            "messages" => [
                                "Identity data erased; authored content retains an anonymous WordPress author.",
                            ],
                            "done" => true,
                        ];
                    } catch (Failure $e) {
                        return [
                            "items_removed" => false,
                            "items_retained" => true,
                            "messages" => [$e->getMessage()],
                            "done" => true,
                        ];
                    }
                },
            ];
            return $erasers;
        });
        add_action("wp_abilities_api_init", static function () {
            if (function_exists("wp_register_ability")) {
                wp_register_ability("typedpress-identity/account", [
                    "label" => "Get identity account",
                    "description" => "Read a WordPress identity account.",
                    "input_schema" => [
                        "type" => "object",
                        "properties" => ["id" => ["type" => "integer"]],
                        "required" => ["id"],
                    ],
                    "permission_callback" => fn() => current_user_can(
                        "list_users",
                    ),
                    "execute_callback" => fn(
                        $input,
                    ) => self::$accounts->profile((int) $input["id"]),
                ]);
            }
        });
        if (defined("WP_CLI") && WP_CLI) {
            // `wp typedpress identity …`: one `typedpress` namespace, owned by the runtime's dispatcher.
            $register = static fn() => \TypedPress\Wp\Runtime::command(
                "identity",
                Cli::run(...),
            );
            did_action("tp_runtime_loaded")
                ? $register()
                : add_action("tp_runtime_loaded", $register);
            // Without the runtime (identity on its own), `wp typedpress` serves `identity` only.
            add_action("plugins_loaded", static function () {
                if (did_action("tp_runtime_loaded")) {
                    return;
                }
                \WP_CLI::add_command("typedpress", static function (array $args, array $flags) {
                    if (($args[0] ?? "") !== "identity") {
                        \WP_CLI::error(
                            "Only wp typedpress identity is available without the typedpress-runtime plugin.",
                        );
                    }
                    $code = Cli::run(array_slice($args, 1), $flags);
                    $code === 0 || \WP_CLI::halt($code);
                });
            });
        }
    }
    /**
     * Whether this sign-in is wp-login.php's own and Two Factor will ask $user for Identity's factor
     * before any cookie is sent: Two Factor is loaded, requires a second step for the user, and
     * offers Identity's authenticator among the user's methods. Otherwise the password is refused.
     */
    private static function secondStep(\WP_User $user): bool
    {
        if (($GLOBALS["pagenow"] ?? "") !== "wp-login.php" || !class_exists(\Two_Factor_Core::class, false)
            || (defined("XMLRPC_REQUEST") && XMLRPC_REQUEST) || (defined("REST_REQUEST") && REST_REQUEST)) {
            return false;
        }
        $available = \Two_Factor_Core::get_available_providers_for_user($user);
        return !is_wp_error($available) && isset($available[self::TWO_FACTOR_KEY])
            && \Two_Factor_Core::is_user_using_two_factor($user);
    }
    /** A path below the issuer as an absolute URL: `/authorize?…` → `https://site/identity/authorize?…`. */
    private static function url(string $below): string
    {
        return home_url("/identity" . $below);
    }
    /**
     * This browser's identity session: its own (`zi_session`), or one opened now from the
     * WordPress sign-in it holds, the site's one sign-in page. The new session is never fresher nor
     * longer-lived than the WordPress session it came from, and is written as this browser's
     * cookie. Null when the browser is signed in to neither.
     */
    public static function browserSession(bool $bridge = true): ?array
    {
        $session = self::$sessions->find((string) ($_COOKIE[Sessions::cookieName()] ?? ""));
        $uid = (int) wp_validate_auth_cookie("", "logged_in");
        if ($session && ($uid === 0 || (int) $session["wp_user_id"] === $uid)) {
            return $session;
        }
        // The browser is signed in to WordPress as someone else than its identity cookie says (a
        // planted cookie, a guest handoff, a sign-in elsewhere): WordPress's sign-in wins.
        if ($session) {
            Sessions::cookie("", time() - 3600);
            unset($_COOKIE[Sessions::cookieName()]);
        }
        // Until this site's storage has `sessions.wp_token` (a managed build serves requests before
        // its initialize), a WordPress sign-in opens no identity session: one it couldn't tie to the
        // WordPress session would outlive it.
        if (!$bridge || !Schema::linksWordPressSessions()) {
            return null;
        }
        $token = wp_get_session_token();
        $wp = $uid > 0 && $token !== "" ? \WP_Session_Tokens::get_instance($uid)->get($token) : null;
        if (!is_array($wp) || in_array(self::$accounts->status($uid), ["suspended", "deletion_requested", "deleted"], true)) {
            return null;
        }
        // An account with Identity MFA counts as signed in only when that WordPress sign-in passed
        // Identity's factor on wp-login.php (Two Factor records the method on the session). A
        // session made any other way (another Two Factor method, a plugin that sets the auth cookie
        // itself) doesn't open an identity session.
        if ((new Mfa(self::$db, self::$security))->enabled($uid)
            && (($wp["two-factor-provider"] ?? null) !== self::TWO_FACTOR_KEY || empty($wp["two-factor-login"]))) {
            return null;
        }
        self::$accounts->ensure($uid);
        $verifier = WordPressSessions::verifier($token);
        $created = self::$sessions->reopen($uid, $verifier)
            ?? self::$sessions->create($uid, (int) ($wp["login"] ?? 0), (int) ($wp["expiration"] ?? 0), $verifier);
        Sessions::cookie($created["secret"], (int) $created["session"]["expires_at"]);
        $_COOKIE[Sessions::cookieName()] = $created["secret"];
        return self::$sessions->find($created["secret"]);
    }
    /**
     * This request's URL, absolute: REQUEST_URI already holds a subdirectory install's path, so
     * `home_url()` would add it twice.
     */
    private static function here(): string
    {
        $home = wp_parse_url(home_url("/"));
        $uri = (string) ($_SERVER["REQUEST_URI"] ?? "/identity");
        return $home["scheme"] . "://" . $home["host"] . (isset($home["port"]) ? ":" . $home["port"] : "")
            . (str_starts_with($uri, "/") ? $uri : "/");
    }
    /**
     * RP-initiated logout (`/identity/logout`). One sign-in means one sign-out: it ends this
     * browser's identity session and, for the same user, its WordPress session.
     *
     * - With an `id_token_hint` that is still valid and names the browser's WordPress user, the
     *   application has shown who is signing out: both end and the browser goes back.
     * - Otherwise (no hint, an expired one, another user's) a link alone must not sign anyone out of
     *   the site: the browser gets a confirmation page whose form (POST, a nonce bound to the
     *   WordPress session, this site's Origin) ends both.
     */
    private static function logout(): never
    {
        $post = $_SERVER["REQUEST_METHOD"] === "POST";
        $query = $post ? $_POST : $_GET;
        $oauth = new OAuth(self::$db, self::$accounts);
        // Checked first, revoked last: nothing ends before the decision below says it may.
        $request = $oauth->logoutRequest($query);
        $target = $request["target"];
        $uid = (int) wp_validate_auth_cookie("", "logged_in");
        $session = self::$sessions->find((string) ($_COOKIE[Sessions::cookieName()] ?? ""));
        $hint = is_string($query["id_token_hint"] ?? null) ? Keys::verify($query["id_token_hint"]) : null;
        $named = is_array($hint) && $uid > 0 && (string) ($hint["sub"] ?? "") === (string) $uid;
        $confirmed = false;
        if ($post && isset($_POST["confirm"])) {
            Api::checkOrigin((string) ($_SERVER["HTTP_ORIGIN"] ?? ""), true, true);
            $confirmed = $uid > 0 && $uid === get_current_user_id()
                && wp_verify_nonce(is_string($_POST["csrf"] ?? null) ? $_POST["csrf"] : "", "typedpress-identity-logout") !== false;
            if (!$confirmed) {
                throw new Failure("invalid_csrf", "Refresh the page and try again.", 403);
            }
        }
        if ($uid > 0 && !$named && !$confirmed) {
            status_header(200);
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign out</title></head><body><main class="consent"><h1>'
                . esc_html(sprintf(__("Sign out of %s?", "typedpress-identity"), get_bloginfo("name")))
                . '</h1><form method="post">';
            foreach ($query as $key => $value) {
                if (is_string($value) && $key !== "csrf" && $key !== "confirm") {
                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                }
            }
            echo '<input type="hidden" name="csrf" value="' . esc_attr(wp_create_nonce("typedpress-identity-logout")) . '"><button name="confirm" value="1">'
                . esc_html__("Sign out", "typedpress-identity") . '</button></form><a href="' . esc_url(home_url("/")) . '">'
                . esc_html__("Stay signed in", "typedpress-identity") . "</a></main></body></html>";
            exit();
        }
        // Here the sign-out may happen: the hint names this browser's user, the user confirmed, or
        // the request carries no WordPress session (an application's own client, a cookieless HTTP
        // call, a cross-site image that drops this site's Lax cookies).
        if ($named || $confirmed) {
            $oauth->endLogout($request["claims"]);
        } elseif (is_array($hint)) {
            // Nobody here showed who is signing out: a current hint may still end the identity
            // session it names, an expired one ends nothing, and neither reaches the WordPress
            // session that identity session came from, which only that browser may end.
            $oauth->endLogout($hint, false);
        }
        if ($session) {
            self::$sessions->revoke((int) $session["wp_user_id"], (string) $session["id"]);
        }
        Sessions::cookie("", time() - 3600);
        if ($uid > 0) {
            \WP_Session_Tokens::get_instance($uid)->destroy(wp_get_session_token());
            wp_clear_auth_cookie();
        }
        wp_redirect($target, 303);
        exit();
    }
    /**
     * Sends a browser with no identity session to the site's one sign-in page, coming back to
     * `$back`. A browser already signed in to WordPress before this site's storage links WordPress
     * sessions (a managed build serving before its initialize) would come straight back without one,
     * and round again: it gets a short "try again" page instead.
     */
    private static function toLogin(string $back): never
    {
        if (!Schema::linksWordPressSessions() && (int) wp_validate_auth_cookie("", "logged_in") > 0) {
            status_header(503);
            header("Retry-After: 30");
            header("Content-Type: text/html; charset=utf-8");
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
                . esc_html__("Try again shortly", "typedpress-identity") . '</title></head><body><main class="consent"><h1>'
                . esc_html__("Your account is being updated", "typedpress-identity") . "</h1><p>"
                . esc_html__("This takes a moment. Try again shortly.", "typedpress-identity") . "</p></main></body></html>";
            exit();
        }
        wp_redirect(wp_login_url($back));
        exit();
    }
    /** The issuer's URL path (`/identity`, or `/blog/identity` under a subdirectory install). */
    public static function path(): string
    {
        return untrailingslashit((string) wp_parse_url(home_url("/identity"), PHP_URL_PATH));
    }
    /** Echo CORS headers for an enabled application's registered origin (`applications.origins`). */
    private static function cors(bool $credentials): void
    {
        $origin = (string) ($_SERVER["HTTP_ORIGIN"] ?? "");
        header("Vary: Origin", false);
        if (!OAuth::allowedOrigin(self::$db, $origin)) {
            return;
        }
        header("Access-Control-Allow-Origin: " . $origin);
        header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: content-type, authorization, x-identity-csrf");
        header("Access-Control-Max-Age: 600");
        if ($credentials) {
            header("Access-Control-Allow-Credentials: true");
        }
    }
    private static function json(array $data, int $status = 200): never
    {
        status_header($status);
        header("Content-Type: application/json");
        header("Cache-Control: no-store");
        echo wp_json_encode($data);
        exit();
    }
    private static function page(string $file): void
    {
        $path = wp_parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
        $base = wp_parse_url(home_url("/identity"), PHP_URL_PATH);
        if (
            $path !== "/.well-known/openid-configuration" &&
            !str_starts_with($path, $base)
        ) {
            return;
        }
        $canonical = wp_parse_url(home_url());
        $canonicalHost =
            $canonical["host"] .
            (isset($canonical["port"]) ? ":" . $canonical["port"] : "");
        if (($_SERVER["HTTP_HOST"] ?? "") !== $canonicalHost) {
            wp_safe_redirect(
                $canonical["scheme"] .
                    "://" .
                    $canonicalHost .
                    ($_SERVER["REQUEST_URI"] ?? "/identity"),
                303,
            );
            exit();
        }
        if (
            !is_ssl() &&
            !(
                defined("TP_IDENTITY_DEV_HTTP") &&
                TP_IDENTITY_DEV_HTTP &&
                wp_get_environment_type() === "local"
            )
        ) {
            self::json(["error" => "https_required"], 400);
        }
        nocache_headers();
        header("Referrer-Policy: same-origin");
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        try {
            $route = substr($path, strlen($base));
            if (
                $path === "/.well-known/openid-configuration" ||
                $route === "/.well-known/openid-configuration"
            ) {
                self::json(OAuth::discovery());
            }
            if ($route === "/jwks") {
                self::json(Keys::jwks());
            }
            if (in_array($route, ["/token", "/revoke", "/handoff"], true)) {
                // Public clients call these from their own origin: no cookies, no credentials.
                self::cors(false);
                if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
                    status_header(204);
                    exit();
                }
                if ($_SERVER["REQUEST_METHOD"] !== "POST") {
                    self::json(["error" => "invalid_request"], 405);
                }
                self::$security->limit(
                    "token:" . ($_SERVER["REMOTE_ADDR"] ?? ""),
                    60,
                );
                $oauth = new OAuth(self::$db, self::$accounts);
                if ($route === "/handoff") {
                    $authorization = (string) ($_SERVER["HTTP_AUTHORIZATION"] ?? "");
                    self::json(["handoff" => $oauth->handoff(str_starts_with($authorization, "Bearer ") ? substr($authorization, 7) : "")]);
                }
                if ($route === "/revoke") {
                    $oauth->revoke(
                        is_string($_POST["token"] ?? null) ? $_POST["token"] : "",
                        is_string($_POST["client_id"] ?? null) ? $_POST["client_id"] : "",
                    );
                    self::json([]);
                }
                header("Pragma: no-cache");
                self::json($oauth->token($_POST));
            }
            if ($route === "/userinfo") {
                $authorization = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
                if (!str_starts_with($authorization, "Bearer ")) {
                    self::json(["error" => "invalid_token"], 401);
                }
                self::json(
                    (new OAuth(self::$db, self::$accounts))->userinfo(
                        substr($authorization, 7),
                    ),
                );
            }
            if ($route === "/logout") {
                self::logout();
            }
            if ($route === "/provider/start") {
                $name = sanitize_key($_GET["provider"] ?? "");
                $url = (new Providers(
                    self::$security,
                    self::$accounts,
                    self::$sessions,
                ))->start($name, null, [], (string) ($_GET["return_to"] ?? ""));
                wp_redirect($url);
                exit();
            }
            if ($route === "/provider/callback") {
                $signedIn = (new Providers(
                    self::$security,
                    self::$accounts,
                    self::$sessions,
                ))->callback(sanitize_key($_GET["provider"] ?? ""));
                $result = self::$api->providerLogin($signedIn["uid"]);
                // A pending application authorization continues after the provider (and MFA).
                $params = array_filter([
                    "factor" => $result["kind"] === "mfa_required" ? $result["challenge_id"] : null,
                    "return_to" => $signedIn["return_to"] ?: null,
                    // A reauthentication resumes through its browser-bound cookie after the factor.
                    "reauth" => $signedIn["return_to"] === "/authorize?resume=1" ? "1" : null,
                ]);
                if ($result["kind"] === "signed_in" && $signedIn["return_to"] !== "") {
                    wp_safe_redirect(home_url("/identity" . $signedIn["return_to"]));
                    exit();
                }
                wp_safe_redirect(home_url("/identity") . ($params ? "?" . http_build_query($params) : ""));
                exit();
            }
            if ($route === "/authorize") {
                $query = $_SERVER["REQUEST_METHOD"] === "POST" ? $_POST : $_GET;
                if (isset($query["resume"])) {
                    [$resumeId, $resumeSecret] = array_pad(
                        explode(
                            ".",
                            (string) ($_COOKIE[Sessions::cookieName("zi_reauth")] ?? ""),
                            2,
                        ),
                        2,
                        "",
                    );
                    $resume = self::$security->consume(
                        $resumeId,
                        "authorize_reauth",
                        $resumeSecret,
                    );
                    $freshSession = self::browserSession();
                    if (
                        $freshSession === null ||
                        $freshSession["id"] ===
                            $resume["payload"]["prior_session"] ||
                        (int) $freshSession["created_at"] <
                            $resume["payload"]["requested_at"] ||
                        // A session opened from a WordPress sign-in carries that sign-in's time.
                        (int) $freshSession["verified_at"] <
                            $resume["payload"]["requested_at"]
                    ) {
                        throw new Failure(
                            "login_required",
                            "A fresh sign-in is required.",
                            401,
                        );
                    }
                    $query = $resume["payload"]["query"];
                    $query["prompt"] = "consent";
                    unset($query["max_age"]);
                }
                $oauth = new OAuth(self::$db, self::$accounts);
                $handoff = is_string($query["handoff"] ?? null) ? $query["handoff"] : null;
                unset($query["handoff"]);
                $auth = $oauth->authorization($query);
                if ($handoff !== null && $_SERVER["REQUEST_METHOD"] === "GET") {
                    // A guest arrives from its app: sign this browser in as that guest, then let
                    // the hosted screen attach an email before consenting.
                    $created = $oauth->redeemHandoff($handoff);
                    Sessions::cookie($created["secret"], $created["session"]["expires_at"]);
                    wp_safe_redirect(
                        home_url(
                            "/identity?upgrade=1&return_to=" .
                                rawurlencode("/authorize?" . http_build_query($query)),
                        ),
                    );
                    exit();
                }
                $session = self::browserSession();
                if (
                    $_SERVER["REQUEST_METHOD"] === "GET" &&
                    (($query["prompt"] ?? "") === "login" ||
                        (isset($query["max_age"]) &&
                            (!$session ||
                                (int) $session["verified_at"] <
                                    time() - (int) $query["max_age"])))
                ) {
                    $binding = Security::token();
                    $resumeId = self::$security->challenge(
                        "authorize_reauth",
                        0,
                        "",
                        [
                            "query" => $query,
                            "prior_session" => $session["id"] ?? "",
                            "requested_at" => time(),
                        ],
                        $binding,
                    );
                    setcookie(Sessions::cookieName("zi_reauth"), $resumeId . "." . $binding, [
                        "expires" => time() + 300,
                        "path" => "/",
                        "secure" => is_ssl(),
                        "httponly" => true,
                        "samesite" => "Lax",
                    ]);
                    // A new sign-in on the site's one sign-in page: WordPress drops its cookies for
                    // `reauth`, and this browser's identity session is set aside, so only a sign-in
                    // made after this request resumes the authorization.
                    Sessions::cookie("", time() - 3600);
                    wp_redirect(wp_login_url(self::url("/authorize?resume=1"), true));
                    exit();
                }
                if (!$session) {
                    self::toLogin(self::url("/authorize?" . http_build_query($query)));
                }
                if ($_SERVER["REQUEST_METHOD"] === "POST") {
                    Api::checkOrigin(
                        (string) ($_SERVER["HTTP_ORIGIN"] ?? ""),
                        true,
                    );
                    self::$sessions->require(
                        is_string($_POST["csrf"] ?? null) ? $_POST["csrf"] : "",
                    );
                    if (
                        isset($query["max_age"]) &&
                        (!ctype_digit((string) $query["max_age"]) ||
                            (int) $session["verified_at"] <
                                time() - (int) $query["max_age"])
                    ) {
                        throw new Failure(
                            "login_required",
                            "Confirm your identity in Account, then retry.",
                            401,
                        );
                    }
                    $url = $oauth->authorize($query, $session);
                    wp_redirect($url);
                    exit();
                }
                status_header(200);
                echo '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Authorize application</title><link rel="stylesheet" href="' .
                    esc_url(plugins_url("build/hosted.css", $file)) .
                    '"></head><body><main class="consent"><div class="brand">' .
                    esc_html(get_bloginfo("name")) .
                    "</div><h1>Continue to " .
                    esc_html($auth["client"]["name"]) .
                    "</h1><p>This application will receive your identity and the profile fields you approve.</p><p>Requested: " .
                    esc_html(implode(", ", $auth["scopes"])) .
                    '</p><form method="post">';
                foreach ($query as $key => $value) {
                    if (is_string($value)) {
                        echo '<input type="hidden" name="' .
                            esc_attr($key) .
                            '" value="' .
                            esc_attr($value) .
                            '">';
                    }
                }
                echo '<input type="hidden" name="csrf" value="' .
                    esc_attr($session["csrf"]) .
                    '"><button>Allow and continue</button></form><a href="' .
                    esc_url(home_url("/identity")) .
                    '">Cancel</a></main></body></html>';
                exit();
            }
            if (!in_array($route, ["", "/", "/account"], true)) {
                self::json(["error" => "not_found"], 404);
            }
            // The site has one sign-in page, wp-login.php. These screens are the account's, for a
            // browser that is signed in. Two exceptions keep the sign-ins that have no WordPress
            // password: `methods` (email code, passkey, an external provider), which the sign-in
            // form links to, and a second factor still owed after an external provider (`factor`).
            if (!isset($_GET["factor"]) && !isset($_GET["methods"]) && self::browserSession() === null) {
                $continue = Providers::continuation($_GET["return_to"] ?? "");
                self::toLogin($continue !== "" ? self::url($continue) : self::here());
            }
            status_header(200);
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' .
                esc_html(get_bloginfo("name")) .
                '</title><link rel="stylesheet" href="' .
                esc_url(plugins_url("build/hosted.css", $file)) .
                '"></head><body><div id="identity-root" data-api="' .
                esc_url(rest_url("typedpress-identity/v1/")) .
                '" data-issuer="' .
                esc_url(OAuth::issuer()) .
                '" data-login="' .
                esc_url(wp_login_url(self::here())) .
                '" data-site="' .
                esc_attr(get_bloginfo("name")) .
                '"></div><script type="module" src="' .
                esc_url(plugins_url("build/hosted.js", $file)) .
                '"></script></body></html>';
            exit();
        } catch (Protocol\OAuthError $e) {
            self::json($e->body(), $e->status);
        } catch (Failure $e) {
            self::json(
                ["error" => $e->kind, "error_description" => $e->getMessage()],
                $e->status,
            );
        } catch (\Throwable $e) {
            error_log(
                "Identity protocol: " . get_class($e) . " " . $e->getMessage(),
            );
            self::json(["error" => "server_error"], 500);
        }
    }
    private static function adminPage(string $file): void
    {
        echo '<div id="identity-admin" data-api="' .
            esc_url(rest_url("typedpress-identity/v1/admin/")) .
            '" data-nonce="' .
            esc_attr(wp_create_nonce("wp_rest")) .
            '" data-site="' .
            esc_attr(get_bloginfo("name")) .
            '"></div>';
        wp_enqueue_style(
            "typedpress-identity-admin",
            plugins_url("build/admin.css", $file),
            [],
            self::VERSION,
        );
        wp_enqueue_script(
            "typedpress-identity-admin",
            plugins_url("build/admin.js", $file),
            [],
            self::VERSION,
            true,
        );
    }
}
