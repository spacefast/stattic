<?php

declare(strict_types=1);

namespace TypedPress\Jobs;

use TypedPress\Js;
use TypedPress\Wp\Jobs\ActionSchedulerQueue;

use function TypedPress\db;

/**
 * Runs one queued job (§6.1): lock it, call the handler in its own transaction with no user, then
 * record done / failed with backoff / dead. Action Scheduler never retries, so failures are
 * re-scheduled here and AS only ever sees completed actions.
 */
final class Runner
{
    /** Seconds before attempt n+1: 1m, 5m, 15m, 1h, 3h, 6h, 12h, then 24h. */
    public const BACKOFF = [60, 300, 900, 3600, 10800, 21600, 43200, 86400];
    public const STUCK_MS = 600_000;
    public const RECEIPT_TTL_MS = 7 * 86_400_000;
    public const DEFAULT_RETRIES = 3;

    /** @return array{id: string, state: string, attempts: int, error?: string}|null null when the job isn't runnable */
    public static function run(string $id): ?array
    {
        $db = db();
        $locked = $db->transaction(static function () use ($id): ?array {
            $row = Storage::run('SELECT * FROM {jobs} WHERE "id" = ?' . Storage::forUpdate(), [$id])[0] ?? null;
            if ($row === null || !in_array($row['state'], ['pending', 'failed'], true)) {
                return null;
            }
            // Running reclaims the key (a failed job released it). If a newer job holds it, the unique
            // index ignores the update and this attempt waits a minute: two jobs never run one key.
            Storage::run(Storage::updateIgnore() . ' {jobs} SET "state" = \'running\', "lockedAt" = ?, "dedupe" = "key" WHERE "id" = ?', [Js::iso(Js::now()), $id]);
            if (Storage::changes() === 0) {
                $later = Js::iso(Js::now() + 60_000);
                Storage::run('UPDATE {jobs} SET "runAt" = ? WHERE "id" = ?', [$later, $id]);
                ActionSchedulerQueue::schedule($id, $later);

                return null;
            }

            return $row;
        });
        if ($locked === null) {
            return null;
        }
        $attempt = (int) $locked['attempts'] + 1;
        $previous = $db->actor();
        $db->setActor(null);
        $committed = false;
        try {
            $spec = Jobs::spec((string) $locked['job']);
            // Exact integers (row ids), and `{}` stays an object in json() input fields.
            $input = Js::decodeJson((string) $locked['input'], 4096);
            $input = \TypedPress\Seam::toPhp($input, $spec['input'] ?? []);
            $ctx = ['user' => null, 'attempt' => $attempt, 'jobId' => $id];
            if (!is_string($spec['handler'])) {
                // Built-in jobs that only talk to the network (webhooks) run outside a transaction, so a
                // slow endpoint never holds the database's write lock; only the bookkeeping is transactional.
                ($spec['handler'])(is_array($input) ? $input : [], $ctx);
            }
            Receipts::withFingerprint(hash('sha256', (string) $locked['input']), static function () use ($spec, $input, $ctx, $attempt, $id, $db, &$committed): void {
                $db->transaction(static function () use ($spec, $input, $ctx, $attempt, $id, &$committed): void {
                    // First commit callback: anything after it (other features' callbacks) runs on committed state.
                    \TypedPress\Extensions::afterCommit(static function () use (&$committed): void {
                        $committed = true;
                    });
                    if (is_string($spec['handler'])) {
                        $fn = '\\TypedPressApp\\' . $spec['handler'];
                        $input = \TypedPress\Seam::toJs(is_array($input) ? $input : [], $spec['input'] ?? []);
                        $ctx = \TypedPress\Seam::ctx($ctx);
                        \TypedPress\Timers::run(static fn (): mixed => $fn($input, $ctx));
                    }
                    Storage::run('UPDATE {jobs} SET "state" = \'done\', "attempts" = ?, "dedupe" = NULL, "lockedAt" = NULL, "lastError" = NULL, "doneAt" = ? WHERE "id" = ?', [$attempt, Js::iso(Js::now()), $id]);
                });
            });

            return ['id' => $id, 'state' => 'done', 'attempts' => $attempt];
        } catch (\Throwable $e) {
            if ($committed) {
                // The handler's writes and `done` are committed; retrying would repeat them.
                error_log('[typedpress] job ' . $id . ': a commit callback failed: ' . $e->getMessage());

                return ['id' => $id, 'state' => 'done', 'attempts' => $attempt];
            }

            return self::fail($locked, $attempt, $e);
        } finally {
            $db->setActor($previous);
        }
    }

    /** @return array{id: string, state: string, attempts: int, error: string} */
    private static function fail(array $row, int $attempt, \Throwable $e): array
    {
        $id = (string) $row['id'];
        $error = mb_substr($e::class . ': ' . $e->getMessage(), 0, 2000, 'UTF-8');
        $retries = self::retries((string) $row['job']);
        $dead = $attempt >= $retries;
        $runAt = Js::iso(Js::now() + 1000 * self::BACKOFF[min($attempt, count(self::BACKOFF)) - 1]);
        db()->transaction(static function () use ($id, $attempt, $error, $dead, $runAt): void {
            Storage::run(
                $dead
                    ? 'UPDATE {jobs} SET "state" = \'dead\', "attempts" = ?, "lastError" = ?, "dedupe" = NULL, "lockedAt" = NULL, "doneAt" = ? WHERE "id" = ?'
                    : 'UPDATE {jobs} SET "state" = \'failed\', "attempts" = ?, "lastError" = ?, "runAt" = ?, "dedupe" = NULL, "lockedAt" = NULL WHERE "id" = ?',
                [$attempt, $error, $dead ? Js::iso(Js::now()) : $runAt, $id],
            );
            if (!$dead) {
                ActionSchedulerQueue::schedule($id, $runAt);
            }
        });

        return ['id' => $id, 'state' => $dead ? 'dead' : 'failed', 'attempts' => $attempt, 'error' => $error];
    }

    /**
     * Waiting (pending or failed) jobs that have no `tp_job` action at all get one, at their runAt.
     * A job and its action are written in one transaction, so this finds only jobs a runtime before
     * this one left in `tp__outbox`, ones queued before Action Scheduler was ready in a request that
     * died, and ones whose actions someone deleted. A job with any action, even one an administrator
     * cancelled in Tools > Scheduled Actions, is left alone (`wp typedpress jobs cancel` cancels a job).
     * At most $max jobs per call, in pages of 100 in runAt order (the jobs table's `due` index).
     *
     * @return int jobs given an action
     */
    public static function recover(int $max = 1000): int
    {
        $recovered = 0;
        $cursor = null;
        do {
            $page = Storage::run('SELECT "id", "runAt" FROM {jobs} WHERE ' . self::orphan()
                . ($cursor === null ? '' : ' AND ("runAt" > ? OR ("runAt" = ? AND "id" > ?))') . ' ORDER BY "runAt", "id" LIMIT 100',
                [...self::ORPHAN_ARGS, ...($cursor === null ? [] : [$cursor['runAt'], $cursor['runAt'], $cursor['id']])]);
            foreach ($page as $row) {
                $recovered += self::ensureDelivery((string) $row['id']);
            }
            $cursor = $page === [] ? null : $page[count($page) - 1];
            $max -= count($page);
        } while (count($page) === 100 && $max > 0);

        return $recovered;
    }

    /** Gives waiting job $id its action at its runAt unless it has one, under its row lock: 1 if it did. */
    public static function ensureDelivery(string $id): int
    {
        return db()->transaction(static function () use ($id): int {
            $job = Storage::run('SELECT "runAt" FROM {jobs} WHERE "id" = ? AND ' . self::orphan() . Storage::forUpdate(), [$id, ...self::ORPHAN_ARGS])[0] ?? null;
            if ($job === null) {
                return 0;
            }
            ActionSchedulerQueue::schedule($id, (string) $job['runAt']);

            return 1;
        });
    }

    /** orphan()'s parameters: the `{"id":"<uuid>"}` Action Scheduler stores as a delivery's args, around the id. */
    private const ORPHAN_ARGS = ['{"id":"', '"}'];

    /** A waiting job with no `tp_job` action of any status (args as Action Scheduler stores them, on its index). */
    private static function orphan(): string
    {
        global $wpdb;

        // The JSON's quotes are parameters (ORPHAN_ARGS): Storage::sql makes every `"` an identifier quote.
        return '"state" IN (\'pending\', \'failed\') AND NOT EXISTS (SELECT 1 FROM "' . $wpdb->prefix . 'actionscheduler_actions" a WHERE a."hook" = \''
            . ActionSchedulerQueue::HOOK . '\' AND a."args" = CONCAT(?, {jobs}."id", ?))';
    }

    private static function retries(string $job): int
    {
        try {
            return max(1, (int) (Jobs::spec($job)['retries'] ?? self::DEFAULT_RETRIES));
        } catch (\LogicException) {
            return 1; // the job was removed from the config: one failure is final
        }
    }

    /**
     * Housekeeping, run by Action Scheduler's hourly action: give waiting jobs without an action one
     * (recover), re-queue jobs whose runner died mid-run, sweep receipts older than 7 days.
     *
     * @return array{recovered: int, requeued: int, swept: int}
     */
    public static function maintain(): array
    {
        $recovered = self::recover();
        $now = Js::now();

        return db()->transaction(static function () use ($now, $recovered): array {
            $stuck = Storage::run('SELECT "id" FROM {jobs} WHERE "state" = \'running\' AND "lockedAt" < ?' . Storage::forUpdate(), [Js::iso($now - self::STUCK_MS)]);
            foreach ($stuck as $row) {
                Storage::run('UPDATE {jobs} SET "state" = \'pending\', "lockedAt" = NULL, "runAt" = ? WHERE "id" = ?', [Js::iso($now), $row['id']]);
                ActionSchedulerQueue::schedule((string) $row['id'], Js::iso($now));
            }
            $old = Storage::run('SELECT COUNT(*) AS "n" FROM {receipts} WHERE "createdAt" < ?', [Js::iso($now - self::RECEIPT_TTL_MS)]);
            Storage::run('DELETE FROM {receipts} WHERE "createdAt" < ?', [Js::iso($now - self::RECEIPT_TTL_MS)]);

            return ['recovered' => $recovered, 'requeued' => count($stuck), 'swept' => (int) $old[0]['n']];
        });
    }
}
