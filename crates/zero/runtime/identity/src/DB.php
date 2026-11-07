<?php

declare(strict_types=1);

namespace Zero\Identity;

final class DB
{
    public readonly string $prefix;
    private int $depth = 0;
    /** @var array<int, true> users whose WordPress rows changed in the open transaction */
    private array $touched = [];
    public function __construct(public readonly \wpdb $wp)
    {
        $this->prefix = $wp->prefix . "zero_identity_";
    }
    public function sql(string $sql, mixed ...$args): string
    {
        return $args ? $this->wp->prepare($sql, ...$args) : $sql;
    }
    public function run(string $sql, mixed ...$args): int
    {
        $result = $this->wp->query($this->sql($sql, ...$args));
        if ($result === false) {
            throw new Failure(
                "storage_conflict",
                "The operation conflicts with existing data. Please retry.",
                409,
            );
        }
        return $result;
    }
    public function row(string $sql, mixed ...$args): ?array
    {
        return $this->rows($sql, ...$args)[0] ?? null;
    }
    public function rows(string $sql, mixed ...$args): array
    {
        $result = $this->wp->query($this->sql($sql, ...$args));
        if ($result === false || $this->wp->last_error !== "") {
            throw new Failure("storage_unavailable", "Identity storage is unavailable. Please retry.", 503);
        }
        return array_map('get_object_vars', $this->wp->last_result);
    }
    public function insert(string $table, array $data): int
    {
        if ($this->wp->insert($this->prefix . $table, $data) === false) {
            throw new Failure(
                "ownership_conflict",
                "This identity is already in use.",
                409,
            );
        }
        return (int) $this->wp->insert_id;
    }
    /**
     * Run $fn in a transaction. Identity shares WordPress's `$wpdb` connection, so when anyone
     * already has a transaction open on it (the Zero runtime, another plugin) identity joins it
     * with a savepoint instead of issuing START TRANSACTION, which would silently commit the
     * owner's work. The connection itself is asked, so no host has to cooperate.
     */
    public function transaction(callable $fn): mixed
    {
        $level = $this->depth++;
        if ($level === 0 && $this->outerTransaction()) {
            $level = 1;
        }
        $savepoint = "zero_identity_" . $this->depth;
        $this->run(
            $level === 0 ? "START TRANSACTION" : "SAVEPOINT " . $savepoint,
        );
        $joined = $level === 1 && $this->depth === 1;
        try {
            $value = $fn();
            $this->run(
                $level === 0 ? "COMMIT" : "RELEASE SAVEPOINT " . $savepoint,
            );
            if ($joined) {
                // The host owns the commit and may still roll back. Leave nothing in WordPress's
                // object cache that only the uncommitted rows justify: later reads hit the database.
                $this->clean();
            }
            return $value;
        } catch (\Throwable $e) {
            $this->run(
                $level === 0
                    ? "ROLLBACK"
                    : "ROLLBACK TO SAVEPOINT " . $savepoint,
            );
            // SQL rolled back; WordPress's object cache didn't. Forget what it learned meanwhile.
            // The list stays until the outermost exit: an enclosing transaction may still roll back.
            $this->clean();
            throw $e;
        } finally {
            if (--$this->depth === 0) {
                $this->touched = [];
            }
        }
    }
    private function clean(): void
    {
        foreach (array_keys($this->touched) as $uid) {
            clean_user_cache($uid);
            wp_cache_delete($uid, "user_meta");
        }
    }
    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
    /**
     * Record a user whose wp_users/usermeta rows change here, so a rollback clears their cache.
     * Inside a host's transaction the host's rollback must clear it too: `zero_identity_touched_user`
     * tells it which users (the Zero runtime registers the cleanup with its own rollback handler).
     */
    public function touch(int $uid): void
    {
        $this->touched[$uid] = true;
        do_action("zero_identity_touched_user", $uid);
    }
    /**
     * Whether this connection has an open transaction that identity didn't start. Outside a
     * transaction autocommit discards a savepoint at once, so releasing it fails; inside one it
     * succeeds and changes nothing. Needs no privileges and works on MySQL and MariaDB alike.
     */
    private function outerTransaction(): bool
    {
        $suppress = $this->wp->suppress_errors(true);
        try {
            $this->wp->query("SAVEPOINT zero_identity_probe");
            return $this->wp->query("RELEASE SAVEPOINT zero_identity_probe") !== false;
        } finally {
            $this->wp->suppress_errors($suppress);
            $this->wp->last_error = "";
        }
    }
    public function lockSession(array $session): array
    {
        $uid = (int) $session["wp_user_id"];
        $this->lock($uid);
        $live = $this->row(
            "SELECT * FROM {$this->prefix}sessions WHERE id=%s AND wp_user_id=%d FOR UPDATE",
            $session["id"],
            $uid,
        );
        if (
            !$live ||
            $live["revoked_at"] !== null ||
            (int) $live["expires_at"] <= time() ||
            (int) $live["verified_at"] < time() - 300
        ) {
            throw new Failure(
                "reverification_required",
                "The session expired or was revoked. Confirm your identity again.",
                403,
            );
        }
        return $live;
    }
    public function credentialMutation(array $session, callable $fn): mixed
    {
        return $this->transaction(function () use ($session, $fn) {
            $this->lockSession($session);
            return $fn();
        });
    }
    public function lock(int $id): array
    {
        $row = $this->row(
            "SELECT * FROM {$this->prefix}accounts WHERE wp_user_id=%d FOR UPDATE",
            $id,
        );
        if (!$row || $row["status"] !== "active") {
            throw new Failure(
                "account_unavailable",
                "This account is unavailable.",
                403,
            );
        }
        return $row;
    }
}
