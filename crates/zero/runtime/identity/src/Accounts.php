<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Accounts
{
    public function __construct(private DB $db, private Security $security) {}
    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (
            strlen($email) > 254 ||
            !is_email($email) ||
            preg_match('/[^\x20-\x7e]/', $email)
        ) {
            throw new Failure("invalid_email", "Enter a valid email address.");
        }
        return $email;
    }
    public function ensure(int $uid): void
    {
        if (!get_userdata($uid)) {
            throw new Failure(
                "account_unavailable",
                "Account unavailable.",
                404,
            );
        }
        $this->db->run(
            "INSERT IGNORE INTO {$this->db->prefix}accounts (wp_user_id,created_at) VALUES (%d,%d)",
            $uid,
            time(),
        );
    }
    public function owner(string $email): int
    {
        $row = $this->db->row(
            "SELECT wp_user_id FROM {$this->db->prefix}emails WHERE normalized=%s",
            self::email($email),
        );
        return (int) ($row["wp_user_id"] ?? 0);
    }
    public function profile(int $uid): array
    {
        $this->ensure($uid);
        $a = $this->db->row(
            "SELECT * FROM {$this->db->prefix}accounts WHERE wp_user_id=%d",
            $uid,
        );
        $u = get_userdata($uid);
        $emails = $this->db->rows(
            "SELECT * FROM {$this->db->prefix}emails WHERE wp_user_id=%d ORDER BY id",
            $uid,
        );
        return [
            "id" => (string) $uid,
            "wp_user_id" => $uid,
            "name" => $u->display_name,
            "status" => $a["status"],
            "emails" => array_map(
                fn($e) => [
                    "id" => (string) $e["id"],
                    "email" => $e["normalized"],
                    "primary" =>
                        (string) $a["primary_email_id"] === (string) $e["id"],
                    "verified_at" => (int) $e["verified_at"],
                ],
                $emails,
            ),
            "providers" => array_map(
                fn($p) => ["id" => (string) $p["id"], "issuer" => $p["issuer"]],
                $this->db->rows(
                    "SELECT id,issuer FROM {$this->db->prefix}providers WHERE wp_user_id=%d",
                    $uid,
                ),
            ),
        ];
    }
    public function claim(int $uid, string $email, string $method): int
    {
        $email = self::email($email);
        $account = $this->db->lock($uid);
        $existing = $this->db->row(
            "SELECT * FROM {$this->db->prefix}emails WHERE normalized=%s",
            $email,
        );
        if ($existing) {
            if ((int) $existing["wp_user_id"] !== $uid) {
                throw new Failure(
                    "email_in_use",
                    "This address belongs to another account.",
                    409,
                );
            }
            return (int) $existing["id"];
        }
        $legacy = $this->db->rows(
            "SELECT ID FROM {$this->db->wp->users} WHERE LOWER(TRIM(user_email))=%s",
            $email,
        );
        foreach ($legacy as $user) {
            if ((int) $user["ID"] !== $uid) {
                throw new Failure(
                    "link_required",
                    "Sign in to the existing account before linking this email.",
                    409,
                );
            }
        }
        $id = $this->db->insert("emails", [
            "wp_user_id" => $uid,
            "normalized" => $email,
            "verified_at" => time(),
            "method" => $method,
        ]);
        if (!$account["primary_email_id"]) {
            $this->setPrimaryLocked($uid, $id, $email);
        }
        $this->security->event($uid, "email.verified", ["email_id" => $id]);
        return $id;
    }
    private function setPrimaryLocked(int $uid, int $id, string $email): void
    {
        $this->db->touch($uid);
        $this->db->run(
            "UPDATE {$this->db->prefix}accounts SET primary_email_id=%d WHERE wp_user_id=%d",
            $id,
            $uid,
        );
        $this->db->run(
            "UPDATE {$this->db->wp->users} SET user_email=%s WHERE ID=%d",
            $email,
            $uid,
        );
        clean_user_cache($uid);
    }
    public function primary(int $uid, int $id): void
    {
        $this->db->transaction(function () use ($uid, $id) {
            $this->db->lock($uid);
            $email = $this->db->row(
                "SELECT * FROM {$this->db->prefix}emails WHERE id=%d AND wp_user_id=%d",
                $id,
                $uid,
            );
            if (!$email) {
                throw new Failure(
                    "email_not_found",
                    "Verified email not found.",
                    404,
                );
            }
            $this->setPrimaryLocked($uid, $id, $email["normalized"]);
            $this->security->event($uid, "email.primary_changed", [
                "email_id" => $id,
            ]);
        });
    }
    public function removeEmail(int $uid, int $id): void
    {
        $this->db->transaction(function () use ($uid, $id) {
            $a = $this->db->lock($uid);
            if ((int) $a["primary_email_id"] === $id) {
                throw new Failure(
                    "primary_email_required",
                    "Choose another primary email before removing this one.",
                    409,
                );
            }
            $this->db->run(
                "DELETE FROM {$this->db->prefix}emails WHERE id=%d AND wp_user_id=%d",
                $id,
                $uid,
            );
            $this->security->event($uid, "email.removed", ["email_id" => $id]);
        });
    }
    private const DUMMY_PASSWORD_HASH = "zero_identity_dummy_password_hash";
    private const PASSWORD_PROFILES = "zero_identity_password_profiles";

    private const UNSUPPORTED_PASSWORD_PROFILE = 'unsupported';
    private const PASSWORD_INVENTORY = "zero_identity_password_inventory";
    // Operational ceilings, including imported hashes; see docs/identity-security.md.
    private const MAX_BCRYPT_COST = 15;
    private const MAX_PHPASS_LOG2 = 16;
    private const MAX_SHA_CRYPT_ROUNDS = 1_000_000;
    private const MAX_ARGON_MEMORY_KIB = 131_072;
    private const MAX_ARGON_TIME = 8;
    private const MAX_ARGON_THREADS = 2;

    /** Ordinary requests only provision fixed material and enqueue the background inventory. */
    public static function provisionPasswordHash(): void
    {
        $hash = get_option(self::DUMMY_PASSWORD_HASH);
        if (!is_string($hash) || wp_password_needs_rehash($hash)) {
            $hash = wp_hash_password(Security::token(48));
            update_option(self::DUMMY_PASSWORD_HASH, $hash, false);
        }
        if (get_option(self::PASSWORD_PROFILES) === false) {
            self::initializePasswordOption(self::PASSWORD_PROFILES, [self::passwordProfile($hash) => $hash]);
        }
        self::rememberPasswordHash($hash);
        if (get_option(self::PASSWORD_INVENTORY) === false) {
            self::initializePasswordOption(self::PASSWORD_INVENTORY, ['after' => 0, 'complete' => false]);
        }
        if (!get_option(self::PASSWORD_INVENTORY)['complete'] && !wp_next_scheduled(self::PASSWORD_INVENTORY)) {
            wp_schedule_event(time() + 60, 'zero_identity_minute', self::PASSWORD_INVENTORY);
        }
    }

    /** A locked option row serializes workers/writers; the cursor and profiles commit together. */
    public static function inventoryPasswordHashes(): void
    {
        if (get_option(self::PASSWORD_INVENTORY)['complete']) {
            wp_clear_scheduled_hook(self::PASSWORD_INVENTORY);
            return;
        }
        try {
            Plugin::$db->transaction(static function (): void {
                global $wpdb;
                $state = self::lockedPasswordOption(self::PASSWORD_INVENTORY);
                if ($state['complete']) {
                    return;
                }
                $profiles = self::lockedPasswordOption(self::PASSWORD_PROFILES);
                $previousProfiles = $profiles;
                $page = Plugin::$db->rows("SELECT ID,user_pass FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT 1000", $state['after']);
                foreach ($page as $row) {
                    $profile = self::passwordProfile($row['user_pass']);
                    if ($profile !== self::UNSUPPORTED_PASSWORD_PROFILE && !isset($profiles[$profile])) {
                        $dummy = self::dummyPasswordHash($row['user_pass']);
                        if (self::passwordProfile($dummy) === $profile) {
                            $profiles[$profile] = $dummy;
                        }
                    }
                    $state['after'] = (int) $row['ID'];
                }
                $state['complete'] = count($page) < 1000;
                if ($profiles !== $previousProfiles) {
                    self::writePasswordOption(self::PASSWORD_PROFILES, $profiles);
                }
                self::writePasswordOption(self::PASSWORD_INVENTORY, $state);
            });
        } finally {
            // A rollback (including a failed page commit) must discard uncommitted cached options.
            wp_cache_delete(self::PASSWORD_PROFILES, 'options');
            wp_cache_delete(self::PASSWORD_INVENTORY, 'options');
        }
    }

    /** Do not publish uncommitted state through update_option's shared-cache writes. */
    private static function writePasswordOption(string $name, array $value): void
    {
        global $wpdb;
        Plugin::$db->run("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s", serialize($value), $name);
    }

    /** WordPress add_option can overwrite a row inserted after its existence check. */
    private static function initializePasswordOption(string $name, array $value): void
    {
        global $wpdb;
        Plugin::$db->run("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)", $name, serialize($value));
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /** Read from storage after acquiring the row lock, never from a pre-lock option cache. */
    private static function lockedPasswordOption(string $name): array
    {
        global $wpdb;
        // SQLite has no row-level SELECT FOR UPDATE; take its write lock before reading too.
        Plugin::$db->run("UPDATE {$wpdb->options} SET option_value=option_value WHERE option_name=%s", $name);
        $row = Plugin::$db->row("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", $name);
        wp_cache_delete($name, 'options');
        return $row === null ? [] : unserialize($row['option_value'], ['allowed_classes' => false]);
    }

    /** Keep profiles after upgrades: removing the last old hash must not change just its user's work. */
    public static function rememberPasswordHash(string $hash): void
    {
        $profile = self::passwordProfile($hash);
        if ($profile === self::UNSUPPORTED_PASSWORD_PROFILE) {
            return;
        }
        $profiles = get_option(self::PASSWORD_PROFILES, []);
        if (!isset($profiles[$profile])) {
            try {
                Plugin::$db->transaction(static function () use ($profile, $hash): void {
                    // Importers may register before plugins_loaded provisions the options.
                    self::initializePasswordOption(self::PASSWORD_PROFILES, []);
                    $profiles = self::lockedPasswordOption(self::PASSWORD_PROFILES);
                    if (isset($profiles[$profile])) {
                        return;
                    }
                    $dummy = self::dummyPasswordHash($hash);
                    if (self::passwordProfile($dummy) !== $profile) {
                        return;
                    }
                    $profiles[$profile] = $dummy;
                    self::writePasswordOption(self::PASSWORD_PROFILES, $profiles);
                });
            } finally {
                wp_cache_delete(self::PASSWORD_PROFILES, 'options');
                wp_cache_delete('notoptions', 'options');
            }
        }
    }

    private static function passwordProfile(string $hash): string
    {
        if (strlen($hash) <= 32) {
            return 'md5';
        }
        if (str_starts_with($hash, '$P$')) {
            if (!preg_match('/^\$P\$[.\/A-Za-z0-9]{31}$/D', $hash)) {
                return self::UNSUPPORTED_PASSWORD_PROFILE;
            }
            $cost = strpos('./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $hash[3]);
            return $cost >= 7 && $cost <= self::MAX_PHPASS_LOG2 ? substr($hash, 0, 4) : self::UNSUPPORTED_PASSWORD_PROFILE;
        }
        $prefixed = str_starts_with($hash, '$wp');
        $encoded = $prefixed ? substr($hash, 3) : $hash;
        if (str_starts_with($encoded, '$2')) {
            // password_get_info accepts broken bcrypt strings; require canonical encoding first.
            if (!preg_match('/^\$2[abxy]\$(\d{2})\$[.\/A-Za-z0-9]{21}[.Oeu][.\/A-Za-z0-9]{30}[.CGKOSWaeimquy26]$/D', $encoded, $match)) {
                return self::UNSUPPORTED_PASSWORD_PROFILE;
            }
            return (int) $match[1] >= 4 && (int) $match[1] <= self::MAX_BCRYPT_COST
                ? json_encode([$prefixed, 'bcrypt', ['cost' => (int) $match[1]]])
                : self::UNSUPPORTED_PASSWORD_PROFILE;
        }
        if (str_starts_with($encoded, '$5$') || str_starts_with($encoded, '$6$')) {
            if (!preg_match('/^\$([56])\$(?:rounds=([1-9][0-9]*)\$)?[.\/A-Za-z0-9]{0,16}\$([.\/A-Za-z0-9]+)$/D', $encoded, $match)) {
                return self::UNSUPPORTED_PASSWORD_PROFILE;
            }
            $rounds = $match[2] === '' ? 5000 : (int) $match[2];
            if ($rounds < 1000 || $rounds > self::MAX_SHA_CRYPT_ROUNDS || strlen($match[3]) !== ($match[1] === '5' ? 43 : 86)) {
                return self::UNSUPPORTED_PASSWORD_PROFILE;
            }
            return json_encode([$prefixed, 'sha-crypt-' . $match[1], ['rounds' => $rounds]]);
        }
        if (str_starts_with($encoded, '$1$')) {
            return preg_match('/^\$1\$[.\/A-Za-z0-9]{0,8}\$[.\/A-Za-z0-9]{22}$/D', $encoded)
                ? json_encode([$prefixed, 'md5-crypt'])
                : self::UNSUPPORTED_PASSWORD_PROFILE;
        }
        // PHP's Argon encoder emits v19, a 16-byte salt and a 32-byte digest.
        if (!preg_match('/^\$(argon2i|argon2id)\$v=19\$m=([1-9][0-9]*),t=([1-9][0-9]*),p=([1-9][0-9]*)\$([A-Za-z0-9+\/]+)\$([A-Za-z0-9+\/]+)$/D', $encoded, $match)) {
            return self::UNSUPPORTED_PASSWORD_PROFILE;
        }
        $salt = base64_decode($match[5], true);
        $digest = base64_decode($match[6], true);
        if ($salt === false || strlen($salt) !== 16 || rtrim(base64_encode($salt), '=') !== $match[5]
            || $digest === false || strlen($digest) !== 32 || rtrim(base64_encode($digest), '=') !== $match[6]
            || (int) $match[4] < 1 || (int) $match[4] > self::MAX_ARGON_THREADS
            || (int) $match[2] < 8 * (int) $match[4] || (int) $match[2] > self::MAX_ARGON_MEMORY_KIB
            || (int) $match[3] < 1 || (int) $match[3] > self::MAX_ARGON_TIME) {
            return self::UNSUPPORTED_PASSWORD_PROFILE;
        }
        $info = password_get_info($encoded);
        return $info['algo'] === null
            ? self::UNSUPPORTED_PASSWORD_PROFILE
            : json_encode([$prefixed, $info['algoName'], $info['options']]);
    }

    /** Generate independent verification material; never retain a user's credential hash. */
    private static function dummyPasswordHash(string $hash): string
    {
        $password = Security::token(48);
        if (strlen($hash) <= 32) {
            return md5($password);
        }
        if (str_starts_with($hash, '$P$') && strlen($hash) === 34) {
            require_once ABSPATH . WPINC . '/class-phpass.php';
            return (new \PasswordHash(8, true))->crypt_private($password, $hash);
        }
        $prefixed = str_starts_with($hash, '$wp');
        $encoded = $prefixed ? substr($hash, 3) : $hash;
        if ($prefixed) {
            $password = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        }
        $info = password_get_info($encoded);
        try {
            $dummy = $info['algo'] === null ? crypt($password, $encoded) : password_hash($password, $info['algo'], $info['options']);
        } catch (\ValueError) {
            return ''; // Unencodable profiles cannot enter or replace a verification slot.
        }
        if (strlen($dummy) <= 2) {
            return '';
        }
        return ($prefixed ? '$wp' : '') . $dummy;
    }

    public function password(string $login, string $password): int
    {
        $uid = is_email($login) ? $this->owner($login) : 0;
        $user = $uid
            ? get_userdata($uid)
            : (is_email($login)
                ? get_user_by("email", $login)
                : get_user_by("login", $login));
        $profile = $user ? self::passwordProfile($user->user_pass) : null;
        if ($user) {
            self::rememberPasswordHash($user->user_pass);
        }
        $valid = false;
        if (!get_option(self::PASSWORD_INVENTORY)['complete']) {
            // While the inventory is incomplete, every attempt still pays current-algorithm work.
            $dummy = get_option(self::DUMMY_PASSWORD_HASH);
            wp_check_password($password, $dummy, 0);
            $supported = $user && $profile !== self::UNSUPPORTED_PASSWORD_PROFILE;
            $checked = wp_check_password($password, $supported ? $user->user_pass : $dummy, $supported ? $user->ID : 0);
            $valid = $supported && $checked;
        } else {
            // Complete inventories give every sign-in the same algorithm/cost schedule.
            foreach (get_option(self::PASSWORD_PROFILES) as $kind => $dummy) {
                $dummyProfile = self::passwordProfile($dummy);
                // Old inventory options must not replay costs rejected by the current policy.
                if ($dummyProfile === self::UNSUPPORTED_PASSWORD_PROFILE) {
                    continue;
                }
                $actual = $user && $profile !== self::UNSUPPORTED_PASSWORD_PROFILE && $profile === $kind && $dummyProfile === $profile;
                $checked = wp_check_password($password, $actual ? $user->user_pass : $dummy, $actual ? $user->ID : 0);
                if ($actual) {
                    $valid = $checked;
                }
            }
        }
        if (!$user || !$valid) {
            throw new Failure(
                "invalid_credentials",
                "The sign-in details are incorrect.",
                401,
            );
        }
        $this->ensure((int) $user->ID);
        return (int) $user->ID;
    }
    public function emailLogin(string $email): int
    {
        $email = self::email($email);
        return $this->db->transaction(function () use ($email) {
            $uid = $this->owner($email);
            if (!$uid) {
                $legacy = $this->db->rows(
                    "SELECT ID FROM {$this->db->wp->users} WHERE LOWER(TRIM(user_email))=%s",
                    $email,
                );
                if (count($legacy) > 1) {
                    throw new Failure(
                        "ownership_conflict",
                        "Contact your administrator to resolve duplicate legacy emails.",
                        409,
                    );
                }
                $uid = (int) ($legacy[0]["ID"] ?? 0);
            }
            if (!$uid) {
                $uid = $this->create($email);
            }
            $this->ensure($uid);
            $this->claim($uid, $email, "email_code");
            return $uid;
        });
    }
    private function create(string $name): int
    {
        $uid = wp_insert_user([
            "user_login" => "zi_" . bin2hex(random_bytes(16)),
            "user_pass" => Security::token(48),
            "display_name" => $name,
            "role" => "subscriber",
        ]);
        if (is_wp_error($uid)) {
            throw new Failure(
                "account_creation_failed",
                "Account could not be created.",
                409,
            );
        }
        $this->ensure($uid);
        return $uid;
    }
    public function provider(
        string $issuer,
        string $subject,
        ?string $email,
        string $name,
        ?int $linkUid = null,
        ?string $linkSessionId = null,
    ): int {
        if (
            $issuer === "" ||
            strlen($issuer) > 255 ||
            $subject === "" ||
            strlen($subject) > 512
        ) {
            throw new Failure(
                "invalid_identity",
                "Provider identity is invalid.",
            );
        }
        $key = hash("sha256", $issuer . "\0" . $subject);
        return $this->db->transaction(function () use (
            $issuer,
            $subject,
            $email,
            $name,
            $linkUid,
            $key,
            $linkSessionId,
        ) {
            if ($linkUid !== null) {
                $this->db->lock($linkUid);
                $session = $this->db->row(
                    "SELECT * FROM {$this->db->prefix}sessions WHERE id=%s AND wp_user_id=%d FOR UPDATE",
                    $linkSessionId ?? "",
                    $linkUid,
                );
                if (
                    !$session ||
                    $session["revoked_at"] !== null ||
                    (int) $session["expires_at"] <= time() ||
                    (int) $session["verified_at"] < time() - 300
                ) {
                    throw new Failure(
                        "link_session_invalid",
                        "The linking session expired or was revoked. Sign in again.",
                        403,
                    );
                }
            }
            $existing = $this->db->row(
                "SELECT * FROM {$this->db->prefix}providers WHERE identity_key=%s",
                $key,
            );
            if ($existing) {
                $uid = (int) $existing["wp_user_id"];
                if ($linkUid !== null && $linkUid !== $uid) {
                    throw new Failure(
                        "provider_in_use",
                        "This provider belongs to another account.",
                        409,
                    );
                }
                $this->db->lock($uid);
                return $uid;
            }
            $uid = $linkUid;
            if (!$uid) {
                if (!$email) {
                    throw new Failure(
                        "verified_email_required",
                        "Sign in and explicitly link this provider, or verify an email first.",
                        409,
                    );
                }
                if ($this->owner($email) || get_user_by("email", $email)) {
                    throw new Failure(
                        "link_required",
                        "Sign in to your existing account, then link this provider in Account.",
                        409,
                    );
                }
                $uid = $this->create($name ?: $email);
            }
            $this->ensure($uid);
            $this->db->lock($uid);
            $this->db->insert("providers", [
                "wp_user_id" => $uid,
                "identity_key" => $key,
                "issuer" => $issuer,
                "subject" => $subject,
                "created_at" => time(),
            ]);
            if ($email !== null) {
                $this->claim($uid, $email, "oidc");
            }
            $this->security->event($uid, "provider.linked", [
                "issuer" => $issuer,
            ]);
            return $uid;
        });
    }
    /**
     * A WordPress user is being deleted: everything identity knows about it goes with it, in the
     * caller's transaction (sessions and tokens, verified emails, providers, passkeys, factors,
     * memberships, pending challenges and codes, the account). A later user given the same id, or
     * someone re-registering the email, starts with nothing of it.
     */
    public function forgetUser(int $uid): void
    {
        $this->db->transaction(function () use ($uid): void {
            foreach (["sessions", "tokens", "codes", "challenges", "emails", "providers", "passkeys", "mfa", "recovery_codes", "members", "accounts"] as $table) {
                $this->db->run("DELETE FROM {$this->db->prefix}{$table} WHERE wp_user_id=%d", $uid);
            }
        });
    }
    /** The account state the Zero runtime enforces on every channel (§3.5). */
    public function status(int $uid): string
    {
        $row = $this->db->row(
            "SELECT status FROM {$this->db->prefix}accounts WHERE wp_user_id=%d",
            $uid,
        );
        return $row ? (string) $row["status"] : "none";
    }
    /** Mint a session + token pair without a browser flow; reserved for the Zero runtime (§3.5). */
    public function issueForUser(int $wpUserId, string $clientId): array
    {
        return (new OAuth($this->db, $this))->issueForUser($wpUserId, $clientId);
    }
    public static function isGuest(int $uid): bool
    {
        return in_array("zero_guest", (array) get_userdata($uid)?->roles, true);
    }
    /**
     * Sign in with a verified email address. A guest (role `zero_guest`) holding the current
     * session keeps its user id: the address is attached to it and it becomes a subscriber. An
     * address that already belongs to someone signs into that account; the guest is left as is.
     *
     * The upgrade locks the guest's account and its originating session and rechecks both, so a
     * second upgrade racing this one can't attach its email after the first commits. Every earlier
     * credential of the guest ends: sessions, token families, and any passkey (guests can't enroll
     * one, but nothing a former holder of the guest planted may outlive the upgrade).
     *
     * @param ?array $session the identity session the browser presented, if any
     */
    public function verifiedSignIn(string $email, ?array $session): int
    {
        $email = self::email($email);
        $guest = $session === null ? null : (int) $session["wp_user_id"];
        if ($guest === null || !self::isGuest($guest)) {
            return $this->emailLogin($email);
        }
        return $this->db->transaction(function () use ($email, $guest, $session) {
            if ($this->owner($email) || get_user_by("email", $email)) {
                return $this->emailLogin($email);
            }
            $this->ensure($guest);
            $this->db->lock($guest);
            $live = $this->db->row(
                "SELECT id FROM {$this->db->prefix}sessions WHERE id=%s AND wp_user_id=%d AND revoked_at IS NULL FOR UPDATE",
                $session["id"],
                $guest,
            );
            clean_user_cache($guest);
            if (!$live || !self::isGuest($guest)) {
                // Another upgrade won the race: this browser's guest session is gone.
                throw new Failure("unauthenticated", "This guest session ended. Sign in again.", 401);
            }
            $this->claim($guest, $email, "email_code");
            Plugin::$sessions->revoke($guest);
            $this->db->run("DELETE FROM {$this->db->prefix}passkeys WHERE wp_user_id=%d", $guest);
            $this->db->run("DELETE FROM {$this->db->prefix}mfa WHERE wp_user_id=%d", $guest);
            $this->db->run("DELETE FROM {$this->db->prefix}recovery_codes WHERE wp_user_id=%d", $guest);
            $this->db->run("DELETE FROM {$this->db->prefix}providers WHERE wp_user_id=%d", $guest);
            // Core credentials too: a fresh random password and no application passwords.
            $this->db->run(
                "UPDATE {$this->db->wp->users} SET user_pass=%s, user_activation_key='' WHERE ID=%d",
                wp_hash_password(Security::token(48)),
                $guest,
            );
            $this->db->run(
                "DELETE FROM {$this->db->wp->usermeta} WHERE user_id=%d AND meta_key IN ('_application_passwords','session_tokens')",
                $guest,
            );
            $this->db->touch($guest);
            $user = new \WP_User($guest);
            $user->set_role("subscriber");
            clean_user_cache($guest);
            $this->security->event($guest, "guest.upgraded");
            do_action("zero_identity_guest_upgraded", $guest);
            return $guest;
        });
    }
    public function suspend(int $uid, bool $suspend): void
    {
        $this->ensure($uid);
        $this->db->transaction(function () use ($uid, $suspend) {
            $this->db->run(
                "SELECT wp_user_id FROM {$this->db->prefix}accounts WHERE wp_user_id=%d FOR UPDATE",
                $uid,
            );
            $this->db->run(
                "UPDATE {$this->db->prefix}accounts SET status=%s WHERE wp_user_id=%d",
                $suspend ? "suspended" : "active",
                $uid,
            );
            $this->db->run(
                "UPDATE {$this->db->prefix}sessions SET revoked_at=%d WHERE wp_user_id=%d",
                time(),
                $uid,
            );
            $this->security->event(
                $uid,
                $suspend ? "account.suspended" : "account.restored",
            );
        });
        \WP_Session_Tokens::get_instance($uid)->destroy_all();
    }
}
