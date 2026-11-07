<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\Env;

use function Zero\db;

/**
 * Guest pruning (§6.2, `zero.prune-guests`): an Action Scheduler recurring action (daily, group
 * `zero`) deletes guests (`zero_guest` users, never upgraded) whose last sign-in activity is older
 * than `ZERO_GUEST_TTL_DAYS` (a wp-config constant or environment variable; default 30, 0 turns
 * pruning off). Last activity is the newest refresh token zero-identity minted for the guest (each
 * refresh mints one), else its registration.
 *
 * What a guest owns: rows whose reference column points at its auth-collection row, and the posts
 * it authored. A guest with an auth row is deleted through the pipeline like any member delete, so
 * each reference's onDelete applies (cascade deletes, set null clears) with hooks and webhooks; its
 * posts stay, without an author (WpUserStore). A reference that still requires it (no onDelete) or
 * a hook that throws keeps the guest, logged. Each guest is pruned in its own transaction: a kept
 * guest leaves nothing half-deleted and doesn't stop the rest.
 */
final class Guests
{
    public const HOOK = 'zero.prune-guests';
    public const DEFAULT_TTL_DAYS = 30;

    public static function register(): void
    {
        add_action(self::HOOK, static function (): void {
            $plugin = Runtime::plugin();
            // Pruning deletes rows: skip it while storage is behind or no app runs; it recurs.
            if ($plugin !== null && $plugin->unavailable() === null && $plugin->app() !== null) {
                self::prune();
            }
        });
        add_action('action_scheduler_init', static function (): void {
            if (Runtime::plugin() !== null && !as_has_scheduled_action(self::HOOK, [], 'zero')) {
                as_schedule_recurring_action(time() + 3600, DAY_IN_SECONDS, self::HOOK, [], 'zero');
            }
        });
    }

    /** @return array{pruned: list<int>, kept: list<array{id: int, reason: string}>} */
    public static function prune(): array
    {
        $out = ['pruned' => [], 'kept' => []];
        $days = self::ttlDays();
        if ($days === null || !IdentityBridge::available()) {
            return $out; // guests exist only with zero-identity
        }
        $db = Runtime::plugin()?->connection() ?? throw new \LogicException('No Zero app is active');
        $cutoff = time() - $days * 86_400;
        foreach (array_column($db->run(self::staleSql($db, false), [$cutoff]), 'ID') as $id) {
            $id = (int) $id;
            try {
                if (db()->transaction(static fn (): bool => self::pruneOne($db, $id, $cutoff))) {
                    $out['pruned'][] = $id;
                }
            } catch (\Throwable $e) {
                $out['kept'][] = ['id' => $id, 'reason' => $e->getMessage()];
                error_log('[zero] ' . self::HOOK . ": kept guest $id: " . $e->getMessage());
            }
        }

        return $out;
    }

    /** Days a guest may stay inactive, or null when pruning is off. */
    public static function ttlDays(): ?int
    {
        $raw = trim((string) Env::get('ZERO_GUEST_TTL_DAYS'));
        if ($raw === '') {
            return self::DEFAULT_TTL_DAYS;
        }
        if (!preg_match('/^\d+$/D', $raw)) {
            error_log('[zero] ' . self::HOOK . ': ZERO_GUEST_TTL_DAYS must be a whole number of days (0 turns pruning off); skipped');

            return null;
        }

        return (int) $raw === 0 ? null : (int) $raw;
    }

    /**
     * Lock what a guest upgrade locks and changes (identity's account row, then the user's roles),
     * recheck with a locking read, delete. False when an upgrade or sign-in committed first.
     */
    private static function pruneOne(Connection $db, int $id, int $cutoff): bool
    {
        $db->run('SELECT wp_user_id FROM `' . $db->wpdb()->prefix . 'zero_identity_accounts` WHERE wp_user_id = ? FOR UPDATE', [$id]);
        if ($db->run(self::staleSql($db, true), [$cutoff, $id]) === []) {
            return false;
        }
        $db->onRollback(static function () use ($id): void {
            clean_user_cache($id);
            wp_cache_delete($id, 'user_meta');
        });
        $collection = db()->schema()->auth()['collection'] ?? null;
        if ($collection !== null && db()->findFirst($collection, ['where' => ['id' => $id]]) !== null) {
            db()->delete($collection, ['id' => $id]); // onDelete, hooks, webhooks; then the user
        } else {
            WpUserStore::deleteUser($db, $id);
        }

        return true;
    }

    /** Guests last active before ? (unix seconds); with $one, just user ?, locked. */
    private static function staleSql(Connection $db, bool $one): string
    {
        $wpdb = $db->wpdb();
        $caps = $wpdb->get_blog_prefix() . 'capabilities';
        $ttl = \Zero\Identity\OAuth::REFRESH_TTL;
        // user_registered is UTC: TIMESTAMPDIFF from the epoch involves no session time zone.
        $active = "GREATEST(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', u.user_registered), COALESCE((SELECT MAX(t.expires_at) - $ttl FROM `"
            . $wpdb->prefix . "zero_identity_tokens` t WHERE t.wp_user_id = u.ID AND t.kind = 'refresh'), 0))";

        return 'SELECT u.ID FROM `' . $wpdb->users . '` u JOIN `' . $wpdb->usermeta . "` m ON m.user_id = u.ID AND m.meta_key = '" . esc_sql($caps) . "'"
            . " WHERE m.meta_value LIKE '%\"zero_guest\"%' AND $active < ?"
            . ($one ? ' AND u.ID = ? FOR UPDATE' : ' ORDER BY u.ID');
    }
}
