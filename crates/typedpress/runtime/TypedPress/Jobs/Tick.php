<?php

declare(strict_types=1);

namespace TypedPress\Jobs;

use TypedPress\Js;
use function TypedPress\db;

/**
 * `wp typedpress jobs tick`: one bounded pass of every background duty on this site, for a host whose
 * own worker drives WordPress (no wp-cron spawn, no Action Scheduler async runner). A host calls it
 * on a schedule and again while `remaining` is true. It never spawns HTTP requests or processes.
 *
 * In order, each within what is left of `limit` (units of work) and `budgetMs` (wall time):
 * 1. re-queue jobs whose runner died (`running` longer than Runner::STUCK_MS);
 * 2. expire idempotency receipts older than Runner::RECEIPT_TTL_MS;
 * 3. run due jobs directly, at most half of what is left, so maintenance always gets a share;
 * 4. claim and run due Action Scheduler actions (uploads cleanup, guest pruning, identity dispatch,
 *    deliveries the direct pass didn't reach), as Action Scheduler's own runners do;
 * 5. run due WP-Cron events (identity maintenance and the like), rescheduling recurring ones, under
 *    core's `doing_cron` lock.
 *
 * Overlapping ticks, a tick beside wp-cron.php, and a tick beside Action Scheduler's own runner each
 * run an action or event once: the claim and the lock are the ones those runners take.
 *
 * A failing job counts as a failure and is retried by its own policy; a failing action or event
 * counts too and the pass goes on. Expired receipts are the one thing it deletes: they are the
 * idempotency cache, kept 7 days by design (Runner::maintain deletes them the same way).
 */
final class Tick
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT = 100;
    public const DEFAULT_BUDGET_MS = 45_000;

    /** @return array{processed: int, failures: int, outboxDispatched: int, requeued: int, receiptsExpired: int, remaining: bool} */
    public static function run(int $limit = self::DEFAULT_LIMIT, int $budgetMs = self::DEFAULT_BUDGET_MS): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $deadline = microtime(true) + max(1, $budgetMs) / 1000;
        $left = static fn (int $used): int => microtime(true) >= $deadline ? 0 : max(0, $limit - $used);
        $processed = 0;
        $failures = 0;
        $remaining = false;

        // 1 and 2. Recovery and expiry, bounded by the same limit.
        $now = Js::now();
        [$requeued, $expired] = db()->transaction(static function () use ($limit, $now): array {
            $stuck = Storage::run('SELECT "id" FROM {jobs} WHERE "state" = \'running\' AND "lockedAt" < ? ORDER BY "lockedAt", "id" LIMIT ' . $limit . Storage::forUpdate(), [Js::iso($now - Runner::STUCK_MS)]);
            foreach ($stuck as $row) {
                Storage::run('UPDATE {jobs} SET "state" = \'pending\', "lockedAt" = NULL, "runAt" = ? WHERE "id" = ?', [Js::iso($now), $row['id']]);
                \TypedPress\Wp\Jobs\ActionSchedulerQueue::schedule((string) $row['id'], Js::iso($now));
            }
            Storage::run('DELETE FROM {receipts} WHERE "createdAt" < ? LIMIT ' . $limit, [Js::iso($now - Runner::RECEIPT_TTL_MS)]);

            return [count($stuck), Storage::changes()];
        });
        $remaining = $remaining || $requeued === $limit || $expired === $limit;

        // 3. Due jobs, directly: at most half of what is left, and at least one.
        $direct = max(1, intdiv($left($processed), 2));
        if ($left($processed) > 0) {
            $due = Storage::run('SELECT "id" FROM {jobs} WHERE "state" IN (\'pending\', \'failed\') AND "runAt" <= ? ORDER BY "runAt", "id" LIMIT ' . ($direct + 1), [Js::iso(Js::now())]);
            $remaining = $remaining || count($due) > $direct;
            foreach (array_slice($due, 0, $direct) as $row) {
                if ($left($processed) === 0) {
                    $remaining = true;
                    break;
                }
                $result = Runner::run((string) $row['id']);
                $processed++;
                if ($result !== null && $result['state'] !== 'done') {
                    $failures++;
                }
            }
        }

        // 4. Due Action Scheduler actions, claimed first as Action Scheduler's own runners do
        // (ActionScheduler_QueueRunner::do_batch): an action another tick or runner holds is not
        // ours to run, and one we hold no other runner starts. A job delivery whose job already ran
        // above finds it done and completes as a no-op (ActionSchedulerQueue's handler).
        if (class_exists(\ActionScheduler::class) && $left($processed) > 0) {
            $store = \ActionScheduler::store();
            // The runner's own housekeeping first, with its timeouts (ActionScheduler_QueueRunner::run):
            // claims a dead process left go back to the queue and actions it left running are failed.
            $cleanup = 10 * absint(apply_filters('action_scheduler_queue_runner_time_limit', 30));
            $cleaner = new \ActionScheduler_QueueCleaner($store);
            $cleaner->reset_timeouts($cleanup);
            $cleaner->mark_failures($cleanup);
            // One more than we may run: if it is claimed, more is left. Releasing the claim hands
            // whatever we didn't run back to the queue. The fatal-error monitor releases it, and fails
            // the action that was running, if an action ends the process (as do_batch attaches it).
            $claim = $store->stake_claim($left($processed) + 1);
            $monitor = new \ActionScheduler_FatalErrorMonitor($store);
            $monitor->attach($claim);
            try {
                $ran = 0;
                foreach ($claim->get_actions() as $id) {
                    if ($left($processed) === 0) {
                        $remaining = true;
                        break;
                    }
                    if ($store->get_claim_id((int) $id) !== $claim->get_id()) {
                        break; // the claim was lost (timed out and taken by another runner)
                    }
                    \ActionScheduler::runner()->process_action((int) $id, 'typedpress-jobs-tick');
                    $processed++;
                    $ran++;
                    if ($store->get_status((int) $id) === \ActionScheduler_Store::STATUS_FAILED) {
                        $failures++;
                    }
                }
                $remaining = $remaining || count($claim->get_actions()) > $ran;
            } finally {
                $monitor->detach();
                $store->release_claim($claim);
            }
        }

        // 5. Due WP-Cron events, run in this process under core's `doing_cron` lock (wp-cron.php):
        // while another cron run holds it, this tick leaves the events to it; if another run takes it
        // over mid-way, this one stops. Action Scheduler's own queue hook is skipped: its actions were
        // run above, within the limit.
        // Read from the database, not this process's options cache: it was filled when WordPress loaded,
        // and since then another run may have run events or taken and released the lock.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('cron', 'options');
        $cron = function_exists('wp_get_ready_cron_jobs') ? wp_get_ready_cron_jobs() : [];
        $lock = $cron === [] ? null : self::cronLock();
        if ($lock !== null) {
            try {
                foreach ($cron as $timestamp => $hooks) {
                    foreach ($hooks as $hook => $events) {
                        foreach ($events as $event) {
                            if ($hook === 'action_scheduler_run_queue') {
                                continue;
                            }
                            if ($left($processed) === 0) {
                                $remaining = true;
                                break 3;
                            }
                            if (!empty($event['schedule'])) {
                                wp_reschedule_event((int) $timestamp, (string) $event['schedule'], (string) $hook, $event['args'], true);
                            }
                            wp_unschedule_event((int) $timestamp, (string) $hook, $event['args'], true);
                            try {
                                do_action_ref_array((string) $hook, $event['args']);
                            } catch (\Throwable $e) {
                                error_log('[typedpress] jobs tick: ' . $hook . ': ' . $e->getMessage());
                                $failures++;
                            }
                            $processed++;
                            if (self::cronLockValue() !== $lock) {
                                break 3;
                            }
                        }
                    }
                }
            } finally {
                if (self::cronLockValue() === $lock) {
                    delete_transient('doing_cron');
                }
            }
        }

        // `outboxDispatched` stays in the output for hosts that read it: jobs reach Action Scheduler in
        // the transaction that queues them, so a tick has no tickets to hand over.
        return ['processed' => $processed, 'failures' => $failures, 'outboxDispatched' => 0,
            'requeued' => $requeued, 'receiptsExpired' => $expired, 'remaining' => $remaining];
    }

    /**
     * Takes core's cron lock as wp-cron.php does when an external job calls it: refused while another
     * run's lock is younger than WP_CRON_LOCK_TIMEOUT.
     *
     * @return ?string this run's lock, or null when another run holds it
     */
    private static function cronLock(): ?string
    {
        $held = self::cronLockValue();
        if ($held !== '' && (float) $held + WP_CRON_LOCK_TIMEOUT > microtime(true)) {
            return null;
        }
        $lock = sprintf('%.22F', microtime(true));
        set_transient('doing_cron', $lock);

        // Ours only if it reads back: another run may have taken it in between.
        return self::cronLockValue() === $lock ? $lock : null;
    }

    /** The cron lock as stored right now, past any cached copy (wp-cron.php's _get_cron_lock()). */
    private static function cronLockValue(): string
    {
        global $wpdb;
        if (wp_using_ext_object_cache()) {
            return (string) wp_cache_get('doing_cron', 'transient', true);
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", '_transient_doing_cron'));

        return is_object($row) ? (string) $row->option_value : '';
    }
}
