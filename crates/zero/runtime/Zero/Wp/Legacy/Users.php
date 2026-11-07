<?php

declare(strict_types=1);

namespace Zero\Wp\Legacy;

use Zero\HttpError;
use Zero\Schema;
use Zero\Wp\Connection;
use Zero\Wp\WpUserStore;

/**
 * `wp zero migrate-legacy-users --space=<id> [--dry-run]`: carry an original Zero (0.8) Space's app
 * users into the new app's `auth: true` collection (design §6.2).
 *
 * Original Zero kept app users as WordPress users already: the identity plugin's account
 * (`{prefix}sfi_accounts`, with the verified primary email in `sfi_emails`) plus usermeta
 * `_spacefast_app_user` = the Space id. The new app's members are WordPress users too
 * (WpUserStore: `id` = wp_users.ID, `email` = user_email, `name` = display_name, other columns
 * `zero_<col>`), so the move is in place and ids, passwords and sessions are kept:
 *
 * - the verified primary email becomes `user_email`;
 * - the account's status becomes zero-identity's (`{prefix}zero_identity_accounts`), which is
 *   what WpAuth and the user store enforce: a suspended original account stays suspended;
 * - the member collection's own columns start at their defaults, in WpUserStore's storage forms.
 *
 * The WordPress role is left as it is: original app users carried no stored role (authority was
 * per request), and role capabilities are the app plugin's to grant.
 *
 * Every member row is validated by Zero's codecs and rules before anything is written. Each user is
 * then checked for uniqueness against the site as it stands, users moved before it in the run
 * included, so the database compares values under its own collations, and written. A refusal names the user and
 * column, `/users/<wp user id>/<column>`. One transaction (rolled back on any failure, caches
 * included); moved users are stamped, so a second run skips them.
 */
final class Users
{
    private const APP_USER_META = '_spacefast_app_user';
    /** Stamped on a moved user so a second run skips it. */
    public const MIGRATED_META = '_zero_legacy_member';
    /** zero-identity statuses an original account may carry over (Accounts, Privacy), least to most restrictive. */
    private const STATUSES = ['active', 'suspended', 'deletion_requested'];
    /** Restriction order, so a carried-over status never weakens one zero-identity already holds. */
    private const RESTRICTION = ['active' => 0, 'suspended' => 1, 'deletion_requested' => 2, 'deleted' => 3];

    public function __construct(private readonly Connection $db, private readonly Schema $schema)
    {
    }

    /** @return array{dryRun: bool, collection: string, users: list<int>, skipped: list<array{id: int, reason: string}>} */
    public function run(string $space, bool $dryRun): array
    {
        $collection = $this->schema->auth()['collection'] ?? null;
        if ($collection === null) {
            throw Model::refuse('', 'the new app has no auth: true collection to hold the original app users');
        }
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Users::run writes, even in a dry run: run it inside a transaction');
        }
        $spec = $this->schema->collection($collection);
        $w = $this->db->wpdb();
        $original = $w->prefix . 'sfi_';
        $identity = $w->prefix . 'zero_identity_';
        if (!\Zero\Wp\IdentityBridge::available()) {
            // Its tables may outlive it, but only an active zero-identity enforces account status.
            throw Model::refuse('', 'zero-identity is not active; activate it first, so carried-over account status is enforced');
        }
        foreach ([$original . 'accounts', $identity . 'accounts'] as $table) {
            if ($this->db->run('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) === []) {
                throw Model::refuse('', $table === $original . 'accounts'
                    ? "no original identity tables ($table); the Space had no app users"
                    : "no zero-identity tables ($table); activate zero-identity first, so account status can be enforced");
            }
        }
        $rows = $this->db->run(
            "SELECT u.ID, u.display_name, a.status, a.created_at, e.normalized AS email, e.verified_at, z.status AS current FROM `{$w->users}` u"
            . " JOIN `{$w->usermeta}` m ON m.user_id = u.ID AND m.meta_key = ? AND m.meta_value = ?"
            . " LEFT JOIN `{$original}accounts` a ON a.wp_user_id = u.ID"
            . " LEFT JOIN `{$identity}accounts` z ON z.wp_user_id = u.ID"
            . " LEFT JOIN `{$original}emails` e ON e.id = a.primary_email_id AND e.wp_user_id = u.ID"
            . " WHERE NOT EXISTS (SELECT 1 FROM `{$w->usermeta}` d WHERE d.user_id = u.ID AND d.meta_key = ?)"
            . ' ORDER BY u.ID',
            [self::APP_USER_META, $space, self::MIGRATED_META],
        );
        $users = [];
        $skipped = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $at = "/users/$id";
            if ($row['status'] === 'deleted' || $row['current'] === 'deleted') {
                // Erased (by the original platform or by zero-identity since): nothing to restore.
                $skipped[] = ['id' => $id, 'reason' => 'deleted account'];
                continue;
            }
            $status = $row['status'] ?? 'active';
            if (!in_array($status, self::STATUSES, true)) {
                throw HttpError::invalidInput("app user $id has account status '$status'", "$at/status");
            }
            $email = (string) ($row['email'] ?? '');
            if ($email === '' || (int) $row['verified_at'] < 1) {
                throw HttpError::invalidInput("app user $id has no verified primary email", "$at/email");
            }
            $users[$id] = [
                'status' => $status,
                'created' => (int) ($row['created_at'] ?? 0),
                'email' => $email,
                'verified' => (int) $row['verified_at'],
                'values' => self::member($spec, $id, $email, (string) $row['display_name'], $this->role($id), $at),
            ];
        }
        // Each user is checked against the site as it stands, earlier moves included, then written.
        // A dry run writes too: Module rolls its transaction back, so it refuses what a real run would.
        foreach ($users as $id => $user) {
            $this->checkUnique($spec, $id, $user);
            $this->write($spec, $id, $user, $identity);
        }

        return ['dryRun' => $dryRun, 'collection' => (string) $collection, 'users' => array_keys($users), 'skipped' => $skipped];
    }

    /**
     * The member row a create would store: email and name as given, every other own column at its
     * declared default, normalized and rule-checked like any write.
     *
     * @return array<string, int|float|string|null> column → storage form
     */
    public static function member(array $spec, int $id, string $email, string $name, string $role, string $at): array
    {
        // One instant per row, as for any write: every `now` default is the same millisecond.
        return \Zero\Js::atInstant(static fn () => self::memberAt($spec, $id, $email, $name, $role, $at));
    }

    private static function memberAt(array $spec, int $id, string $email, string $name, string $role, string $at): array
    {
        $row = ['email' => $email];
        if (isset($spec['columns']['name'])) {
            $row['name'] = $name;
        }
        $out = [];
        foreach ($spec['columns'] as $col => $def) {
            $col = (string) $col;
            $ptr = $at . HttpError::seg($col);
            if ($col === $spec['primaryKey'] || ($def['system'] ?? false) || ($def['hidden'] ?? false)) {
                continue;
            }
            if (!array_key_exists($col, $row)) {
                if (isset($def['default'])) {
                    $row[$col] = Schema::generate($def['default'], $def);
                } elseif ($def['notNull'] ?? false) {
                    throw HttpError::invalidInput("$col is required for members and original app users have no value", $ptr);
                } else {
                    // Nothing to start from: the column is cleared, so validators below see the
                    // row the move leaves.
                    $out[$col] = null;
                    continue;
                }
            }
            $row[$col] = Schema::normalize($def, $row[$col], $col, $ptr);
            $out[$col] = Schema::store($def, $row[$col]);
        }
        // Validators see the row the user store will read, the user's role included.
        $full = $row + [$spec['primaryKey'] => $id] + (isset($spec['columns']['role']) ? ['role' => $role] : []) + array_fill_keys(array_keys($spec['columns']), null);
        foreach ($row as $col => $value) {
            Schema::checkRules($spec['columns'][$col], $value, (string) $col, $at . HttpError::seg((string) $col), ['data' => $full, 'user' => null], $spec['columns']);
            // References and media carry integrity rules of their own (Db::checkValues); a default
            // of one can't be checked here, so it waits until after the move, as in site migration.
            $def = $spec['columns'][$col];
            if ($value !== null && (isset($def['references']) || Schema::mediaRefs($def, $value, '') !== [] || \Zero\Media\Files::refs($def, $value, '') !== [])) {
                throw Model::refuse($at . HttpError::seg((string) $col), "$col names another record, media or a private file; give it no default, migrate, then set it");
            }
        }

        return $out;
    }

    /** The ladder role WpUserStore reads for this user (`zero_role`, kept by lane E; subscriber until set). */
    private function role(int $id): string
    {
        $w = $this->db->wpdb();
        $r = $this->db->run("SELECT meta_value FROM `{$w->usermeta}` WHERE user_id = ? AND meta_key = ?", [$id, WpUserStore::ROLE_META]);

        return (string) ($r[0]['meta_value'] ?? 'subscriber');
    }

    /**
     * unique() member columns (email included, it is wp_users' own): user $id's value repeats no
     * other user's, users moved earlier in this run included. The database compares (under each
     * column's collation, so `InviteA` meets `invitea`), with locking reads like WpUserStore's
     * own checks: until this transaction commits, no other writer can take the value.
     */
    private function checkUnique(array $spec, int $id, array $user): void
    {
        $w = $this->db->wpdb();
        foreach (['email' => true] + array_filter(array_map(static fn ($d) => $d['unique'] ?? false, $spec['columns'])) as $col => $_) {
            $col = (string) $col;
            $stored = $user['values'][$col] ?? null;
            if ($col === $spec['primaryKey'] || $stored === null) {
                continue;
            }
            $key = in_array($col, ['email', 'name'], true) ? (string) $stored : (string) maybe_serialize(WpUserStore::metaForm($spec['columns'][$col], $stored));
            $ptr = "/users/$id" . HttpError::seg($col);
            $hit = match ($col) {
                'email' => $this->db->run("SELECT ID FROM `{$w->users}` WHERE user_email = ? AND ID <> ? LIMIT 1 FOR UPDATE", [$key, $id]),
                'name' => $this->db->run("SELECT ID FROM `{$w->users}` WHERE display_name = ? AND ID <> ? LIMIT 1 FOR UPDATE", [$key, $id]),
                default => $this->db->run("SELECT user_id FROM `{$w->usermeta}` WHERE meta_key = ? AND meta_value = ? AND user_id <> ? LIMIT 1 FOR UPDATE", [WpUserStore::META . $col, $key, $id]),
            };
            if ($hit !== []) {
                throw new HttpError(409, 'unique_violation', "$col '$key' already belongs to WordPress user " . reset($hit[0]), $ptr);
            }
            if ($col === 'email') {
                // zero-identity keeps one verified owner per address, site-wide.
                $owner = $this->db->run("SELECT wp_user_id FROM `{$w->prefix}zero_identity_emails` WHERE normalized = ? AND wp_user_id <> ? FOR UPDATE", [$key, $id]);
                if ($owner !== []) {
                    throw new HttpError(409, 'unique_violation', "$key is verified for zero-identity account {$owner[0]['wp_user_id']}", $ptr);
                }
            }
        }
    }

    private function write(array $spec, int $id, array $user, string $identity): void
    {
        $w = $this->db->wpdb();
        // zero-identity's status is what WpAuth enforces. It is locked and reread here, at write
        // time, and merged toward the stricter one, so a suspension (or erasure) zero-identity
        // holds, including one committed since planning, is never lifted.
        $current = $this->db->run("SELECT status FROM `{$identity}accounts` WHERE wp_user_id = ? FOR UPDATE", [$id])[0]['status'] ?? null;
        if ($current === 'deleted') {
            throw new HttpError(409, 'conflict', "app user $id was erased by zero-identity during the migration; run it again", "/users/$id");
        }
        $status = $current !== null && (self::RESTRICTION[$current] ?? 0) > self::RESTRICTION[$user['status']] ? $current : $user['status'];
        // WordPress keeps users and their meta in the object cache; a rollback must drop them.
        $this->db->onRollback(static function () use ($id): void {
            clean_user_cache($id);
            wp_cache_delete($id, 'user_meta');
        });
        $this->db->run("UPDATE `{$w->users}` SET user_email = ? WHERE ID = ?", [$user['email'], $id]);
        clean_user_cache($id); // later reads in this transaction see the new row
        if (isset($user['values']['name'])) {
            $this->db->run("UPDATE `{$w->users}` SET display_name = ? WHERE ID = ?", [(string) $user['values']['name'], $id]);
        }
        foreach ($user['values'] as $col => $stored) {
            if (in_array($col, ['email', 'name'], true)) {
                continue;
            }
            $this->db->run("DELETE FROM `{$w->usermeta}` WHERE user_id = ? AND meta_key = ?", [$id, WpUserStore::META . $col]);
            if ($stored === null) {
                continue;
            }
            $value = maybe_serialize(WpUserStore::metaForm($spec['columns'][$col], $stored));
            $this->db->run("INSERT INTO `{$w->usermeta}` (user_id, meta_key, meta_value) VALUES (?, ?, ?)", [$id, WpUserStore::META . $col, $value]);
        }
        // zero-identity's account and verified email.
        $this->db->run(
            "INSERT INTO `{$identity}accounts` (wp_user_id, primary_email_id, status, created_at) VALUES (?, NULL, ?, ?)"
            . ' ON DUPLICATE KEY UPDATE status = VALUES(status)',
            [$id, $status, $user['created'] ?: time()],
        );
        if ($this->db->run("SELECT id FROM `{$identity}emails` WHERE wp_user_id = ? AND normalized = ?", [$id, $user['email']]) === []) {
            $this->db->run(
                "INSERT INTO `{$identity}emails` (wp_user_id, normalized, verified_at, method) VALUES (?, ?, ?, 'migrated')",
                [$id, $user['email'], $user['verified']],
            );
        }
        $this->db->run(
            "UPDATE `{$identity}accounts` a JOIN `{$identity}emails` e ON e.wp_user_id = a.wp_user_id AND e.normalized = ? SET a.primary_email_id = e.id WHERE a.wp_user_id = ?",
            [$user['email'], $id],
        );
        $this->db->run("INSERT INTO `{$w->usermeta}` (user_id, meta_key, meta_value) VALUES (?, ?, '1')", [$id, self::MIGRATED_META]);
        $this->db->afterCommit(static function () use ($id): void {
            clean_user_cache($id);
            wp_cache_delete($id, 'user_meta');
        });
    }
}
