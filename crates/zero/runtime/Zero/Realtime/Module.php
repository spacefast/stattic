<?php

declare(strict_types=1);

namespace Zero\Realtime;

use Zero\Extensions;
use Zero\Js;

use function Zero\db;

/**
 * Change notices: one batch per committed transaction, naming what changed and never the rows.
 *
 * Every pipeline write (API, actions, hooks, jobs, wp-admin saves) reports through onWritten,
 * inside its transaction. The batch is collected per transaction and handed to the registered
 * publishers after the outermost commit; a rollback discards it. Zero ships no transport: with
 * no publisher registered, nothing is collected.
 *
 * A notice is a doorbell. Browsers re-run their own access-checked reads when one arrives, so a
 * notice may reach a viewer who cannot read the row; it says only that a row id changed.
 */
final class Module
{
    /** WordPress action fired once per commit with the batch: do_action(ACTION, array $notice). */
    public const ACTION = 'zero_realtime_changes';
    /** Ids per collection in one notice. Past this, the collection is listed without ids ("refetch all"). */
    public const MAX_IDS = 100;
    /** Longest id a notice carries. A collection with a longer id is listed without ids. */
    public const MAX_ID_LENGTH = 191;
    /** Collections per notice. A transaction touching more is announced as several notices. */
    public const MAX_COLLECTIONS = 64;

    /** @var list<Publisher> */
    private static array $publishers = [];
    /** @var array<string, array{ops: array<string, true>, ids: array<string, int|string>, overflow: bool}> by physical collection name */
    private static array $pending = [];
    private static bool $armed = false;

    public static function register(): void
    {
        Extensions::onWritten(self::written(...));
    }

    /** A host transport. Publishers run after commit, in registration order; a failing one never fails the write. */
    public static function publish(Publisher $publisher): void
    {
        self::$publishers[] = $publisher;
    }

    /** True when something will deliver notices: a registered publisher or a WordPress listener on ACTION. */
    public static function enabled(): bool
    {
        return self::$publishers !== [] || (function_exists('has_action') && has_action(self::ACTION) !== false);
    }

    /** Extensions::onWritten: inside the write's transaction. */
    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        if (!self::enabled()) {
            return;
        }
        // Only app collections are announced; system tables (media, files, revisions) are not the client's.
        $spec = db()->schema()->collections()[$collection] ?? null;
        if ($spec === null) {
            return;
        }
        $pk = $spec['primaryKey'] ?? 'id';
        $id = $row[$pk] ?? $previous[$pk] ?? null;
        $entry = &self::$pending[$collection];
        $entry ??= ['ops' => [], 'ids' => [], 'overflow' => false];
        $entry['ops'][$op] = true;
        if (is_string($id) && strlen($id) > self::MAX_ID_LENGTH) {
            $entry['overflow'] = true;
            $entry['ids'] = [];
        }
        if (!$entry['overflow'] && (is_int($id) || is_string($id))) {
            $entry['ids'][(string) $id] = $id;
            if (count($entry['ids']) > self::MAX_IDS) {
                $entry['overflow'] = true;
                $entry['ids'] = [];
            }
        }
        unset($entry);
        if (!self::$armed) {
            self::$armed = true;
            $store = db()->store();
            // Discard on rollback, flush after commit. Without a transaction afterCommit runs now.
            $connection = $store instanceof \Zero\Wp\CompositeStore ? $store->db : (method_exists($store, 'onRollback') ? $store : null);
            $connection?->onRollback(static function (): void {
                self::$pending = [];
                self::$armed = false;
            });
            Extensions::afterCommit(self::flush(...));
        }
    }

    /**
     * Scheduled promotion publishes rows without the write pipeline (no hooks, by design), so it
     * reports here: each promoted row is an `update` of its collection.
     *
     * @param list<int|string> $pks
     */
    public static function promoted(string $collection, array $pks): void
    {
        $spec = db()->schema()->collections()[$collection] ?? null;
        if ($spec === null) {
            return;
        }
        $pk = $spec['primaryKey'] ?? 'id';
        foreach ($pks as $id) {
            self::written('update', $collection, [$pk => $id], null);
        }
    }

    /** The batch of the transaction that just committed. Public for hosts that drive their own commits. */
    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        self::$armed = false;
        if ($pending === []) {
            return;
        }
        // Notices name collections by schema key, the name the generated client uses (`client.<key>`).
        $collections = db()->schema()->collections();
        $changes = [];
        foreach ($pending as $collection => $entry) {
            $ops = array_keys($entry['ops']);
            sort($ops);
            $key = (string) ($collections[$collection]['key'] ?? $collection);
            $changes[$key] = ['collection' => $key, 'ops' => $ops, 'ids' => $entry['overflow'] ? null : array_values($entry['ids'])];
        }
        ksort($changes);
        $at = Js::iso(Js::now());
        foreach (array_chunk(array_values($changes), self::MAX_COLLECTIONS) as $chunk) {
            self::emit(['id' => bin2hex(random_bytes(12)), 'at' => $at, 'changes' => $chunk]);
        }
    }

    private static function emit(array $notice): void
    {
        // Listeners and publishers run after the commit: a failure in one is logged, never thrown
        // back into a request whose write already succeeded (a retry would duplicate it).
        if (function_exists('do_action')) {
            try {
                do_action(self::ACTION, $notice);
            } catch (\Throwable $e) {
                error_log('[zero] a ' . self::ACTION . ' listener failed: ' . $e->getMessage());
            }
        }
        foreach (self::$publishers as $publisher) {
            try {
                $publisher->publish($notice);
            } catch (\Throwable $e) {
                // The write committed; a lost notice only delays clients until their next poll.
                error_log('[zero] realtime publisher ' . $publisher::class . ' failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * The notice as a room every viewer shares may carry it: ids stay only for collections any
     * visitor can list (read rule `anyone`, no drafts). Elsewhere `ids` becomes null, so a viewer
     * learns that the collection changed, never which rows.
     */
    public static function shared(array $notice): array
    {
        $byKey = [];
        foreach (db()->schema()->collections() as $table => $spec) {
            $byKey[(string) ($spec['key'] ?? $table)] = $spec;
        }
        foreach ($notice['changes'] as $i => $change) {
            $spec = $byKey[$change['collection']] ?? null;
            $public = $spec !== null && !($spec['drafts'] ?? false)
                && \Zero\Access::collectionRules($spec)['read'] === ['role' => 'anyone'];
            if (!$public) {
                $notice['changes'][$i]['ids'] = null;
            }
        }

        return $notice;
    }

    /** Tests: forget publishers and any pending batch. */
    public static function reset(): void
    {
        self::$publishers = [];
        self::$pending = [];
        self::$armed = false;
    }
}
