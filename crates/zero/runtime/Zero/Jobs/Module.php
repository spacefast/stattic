<?php

declare(strict_types=1);

namespace Zero\Jobs;

use Zero\Extensions;
use Zero\HttpError;
use Zero\Js;

/** Feature 3 (§6.1): system tables, the Idempotency-Key wrapper and the `zero__*` job tables. */
final class Module
{
    public const STATES = ['pending', 'running', 'done', 'failed', 'dead'];
    private const USAGE = 'Usage: jobs list [--state s] | jobs run <id> | jobs retry <id> | jobs cancel <id> | jobs maintain';

    public static function register(): void
    {
        Storage::register();
        Extensions::aroundOperation(Receipts::around(...));
        Extensions::cli('jobs', self::cli(...));
        // `agents: true` jobs become `<app>/job-<name>` abilities (lane F); the ability runs this in a transaction.
        \Zero\Abilities\Module::jobs(static fn (string $job, array $input): string => Jobs::enqueue($job, $input)['id']);
        Webhooks::register();
        if (function_exists('add_action')) {
            \Zero\Wp\Jobs\ActionSchedulerQueue::register();
        }
    }

    /** `wp zero jobs …`, inside the CLI transaction. */
    public static function cli(array $args, array $flags): mixed
    {
        $usage = static fn () => new HttpError(400, 'invalid_command', self::USAGE);
        $id = static fn () => $args[1] ?? throw $usage();

        return match ($args[0] ?? '') {
            'list' => self::list(isset($flags['state']) ? (string) $flags['state'] : null),
            'run' => self::afterCommit(static fn () => Runner::run($id()) ?? throw HttpError::notFound("No runnable job {$id()}")),
            'retry' => self::retry($id()),
            'cancel' => self::cancel($id()),
            'maintain' => self::afterCommit(Runner::maintain(...)),
            default => throw $usage(),
        };
    }

    /**
     * Handlers must not run inside the CLI command's transaction: there are no savepoints, so a
     * caught handler failure would commit its partial writes. The result is filled in after commit.
     */
    private static function afterCommit(callable $fn): \JsonSerializable
    {
        $result = new class implements \JsonSerializable {
            public mixed $value = null;

            public function jsonSerialize(): mixed
            {
                return $this->value;
            }
        };
        Extensions::afterCommit(static function () use ($fn, $result): void {
            $result->value = $fn();
        });

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public static function list(?string $state): array
    {
        if ($state !== null && !in_array($state, self::STATES, true)) {
            throw HttpError::invalidInput('state must be one of ' . implode(', ', self::STATES), '/state');
        }
        $rows = Storage::run('SELECT "id", "job", "input", "key", "state", "attempts", "runAt", "lastError", "createdAt", "doneAt" FROM {jobs}'
            . ($state === null ? '' : ' WHERE "state" = ?') . ' ORDER BY "createdAt", "id"', $state === null ? [] : [$state]);

        return array_map(static fn (array $r): array => ['input' => Js::decodeJson((string) $r['input'], 4096), 'attempts' => (int) $r['attempts']] + $r, $rows);
    }

    private static function retry(string $id): array
    {
        $row = Storage::run('SELECT "key", "state" FROM {jobs} WHERE "id" = ?' . Storage::forUpdate(), [$id])[0]
            ?? throw HttpError::notFound("No job $id");
        if (!in_array($row['state'], ['failed', 'dead'], true)) {
            throw new HttpError(409, 'not_retryable', "Job $id is {$row['state']}; only failed or dead jobs can be retried");
        }
        $now = Js::iso(Js::now());
        Storage::run(Storage::updateIgnore() . ' {jobs} SET "state" = \'pending\', "attempts" = 0, "runAt" = ?, "doneAt" = NULL, "lockedAt" = NULL, "dedupe" = "key" WHERE "id" = ?', [$now, $id]);
        if (Storage::changes() === 0) {
            throw new HttpError(409, 'key_in_use', "Another pending job holds key '{$row['key']}'; retry $id after it finishes");
        }
        Outbox::queue()->schedule($id, $now);

        return ['id' => $id, 'state' => 'pending'];
    }

    private static function cancel(string $id): array
    {
        $row = Storage::run('SELECT "state" FROM {jobs} WHERE "id" = ?' . Storage::forUpdate(), [$id])[0]
            ?? throw HttpError::notFound("No job $id");
        if (!in_array($row['state'], ['pending', 'failed'], true)) {
            throw new HttpError(409, 'not_cancellable', "Job $id is {$row['state']}; only pending or failed jobs can be cancelled");
        }
        Storage::run('UPDATE {jobs} SET "state" = \'dead\', "dedupe" = NULL, "lastError" = \'cancelled\', "doneAt" = ? WHERE "id" = ?', [Js::iso(Js::now()), $id]);

        return ['id' => $id, 'state' => 'dead'];
    }
}
