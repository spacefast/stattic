<?php

declare(strict_types=1);

namespace Zero\Jobs;

use function Zero\db;

/**
 * Hands committed jobs to the queue. Enqueue writes the job row and its outbox ticket inside the
 * caller's transaction, so a rollback leaves nothing; this runs after the outermost commit and from
 * every worker pass, which delivers tickets a crash left between commit and dispatch.
 */
final class Outbox
{
    private static ?Queue $queue = null;

    public static function useQueue(Queue $queue): void
    {
        self::$queue = $queue;
    }

    public static function queue(): Queue
    {
        return self::$queue ?? throw new \LogicException('no job queue is registered (the runtime registers Action Scheduler when it boots)');
    }

    /**
     * @param ?list<string> $ids the committing transaction's own rows; null sweeps every committed row
     * @return int rows moved
     */
    public static function dispatch(?array $ids = null): int
    {
        // A plain read sees only committed rows, so in-flight transactions' outbox rows never block this.
        if ($ids === null) {
            $cursor = null;
            $moved = 0;
            do {
                $page = Storage::run(
                    'SELECT "id", "createdAt" FROM {outbox}' . ($cursor === null ? '' : ' WHERE "createdAt" > ? OR ("createdAt" = ? AND "id" > ?)') . ' ORDER BY "createdAt", "id" LIMIT 100',
                    $cursor === null ? [] : [$cursor['createdAt'], $cursor['createdAt'], $cursor['id']],
                );
                if ($page === []) {
                    break;
                }
                // Advance even past a failed dispatch: its ticket waits for the next sweep.
                $cursor = $page[count($page) - 1];
                $moved += self::dispatch(array_column($page, 'id'));
            } while (count($page) === 100);

            return $moved;
        }
        $moved = 0;
        foreach ($ids as $id) {
            try {
                $moved += db()->transaction(static function () use ($id): int {
                    // Lock the one ticket: a concurrent dispatcher of it waits, then finds it gone.
                    $row = Storage::run('SELECT "id", "job", "input", "key", "runAt", "createdAt" FROM {outbox} WHERE "id" = ?' . Storage::forUpdate(), [$id])[0] ?? null;
                    if ($row === null) {
                        return 0;
                    }
                    Storage::run('DELETE FROM {outbox} WHERE "id" = ?', [$id]);
                    if (Storage::run('SELECT "id" FROM {jobs} WHERE "id" = ? AND "state" = \'pending\'', [$id]) === []) {
                        return 0; // cancelled before it was handed over
                    }
                    self::queue()->schedule((string) $id, (string) $row['runAt']);

                    return 1;
                });
            } catch (\Throwable $e) {
                // The write already committed; its outbox row waits for the next sweep.
                error_log('[zero] outbox dispatch of ' . $id . ' failed: ' . $e->getMessage());
            }
        }

        return $moved;
    }
}
