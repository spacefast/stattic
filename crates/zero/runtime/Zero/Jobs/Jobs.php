<?php

declare(strict_types=1);

namespace Zero\Jobs;

use Zero\Extensions;
use Zero\HttpError;
use Zero\Js;
use Zero\Schema;

use function Zero\db;

/** `jobs.<name>.enqueue(input, opts)` (§6.1): validated, written to the outbox, queued after commit. */
final class Jobs
{
    public const MAX_KEY = 191;

    /**
     * @param array{delay?: int|float, key?: string}|null $opts
     * @return array{id: string}
     */
    public static function enqueue(string $name, mixed $input, array|\Zero\JsObject|null $opts = null): array
    {
        $opts = $opts === null ? null : \Zero\Seam::toPhp($opts);
        $db = db();
        $spec = self::spec($name);
        $ctx = $db->hookCtx();
        $input = Schema::validateInput($spec['input'] ?? [], $input, '', $ctx['user']);
        $opts ??= [];
        $delay = $opts['delay'] ?? 0;
        if (!is_int($delay) && !is_float($delay) || !is_finite((float) $delay) || $delay < 0) {
            throw HttpError::invalidInput('delay must be a non-negative number of seconds', '/delay');
        }
        $key = $opts['key'] ?? null;
        if ($key !== null && (!is_string($key) || $key === '' || mb_strlen($key, 'UTF-8') > self::MAX_KEY)) {
            throw HttpError::invalidInput('key must be a string of 1 to ' . self::MAX_KEY . ' characters', '/key');
        }

        return $db->transaction(static function () use ($name, $input, $delay, $key, $spec): array {
            $id = self::uuid();
            $now = Js::now();
            $row = [$id, $name, Js::jsonStringify(\Zero\Seam::toJs($input, $spec['input'] ?? [])), $key, Js::iso($now + (int) round($delay * 1000)), Js::iso($now)];
            // The job row claims UNIQUE(job, dedupe) inside this transaction. A concurrent same-key insert
            // waits for it, then is ignored and returns this job; a rollback removes the row and its claim.
            $bytes = $key === null ? null : Storage::bytes($key);
            Storage::run(Storage::insertIgnore() . ' INTO {jobs} ("id", "job", "input", "key", "runAt", "createdAt", "dedupe", "state", "attempts") VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', 0)', [$id, $name, $row[2], $bytes, $row[4], $row[5], $bytes]);
            if (Storage::changes() === 0) {
                return ['id' => self::holder($name, (string) $key) ?? throw new \RuntimeException("job key '$key' is held by a job that vanished")];
            }
            // The queue hears about the job only after commit (Action Scheduler is told then).
            Storage::run('INSERT INTO {outbox} ("id", "job", "input", "key", "runAt", "createdAt") VALUES (?, ?, ?, ?, ?, ?)', $row);
            Extensions::afterCommit(static fn () => Outbox::dispatch([$id]));

            return ['id' => $id];
        });
    }

    /**
     * The queued (pending/running) job holding (job, key). Called only after an ignored insert, when
     * the row exists: a shared record lock that reads the latest commit (not a stale snapshot).
     */
    private static function holder(string $name, string $key): ?string
    {
        $row = Storage::run('SELECT "id" FROM {jobs} WHERE "job" = ? AND "dedupe" = ?' . Storage::forShare(), [$name, Storage::bytes($key)])[0] ?? null;

        return $row === null ? null : (string) $row['id'];
    }

    /** App-defined jobs take precedence over the runtime's native service and webhook jobs. */
    public static function spec(string $name): array
    {
        return db()->schema()->jobs()[$name]
            ?? \Zero\Services\Definition::jobs()[$name]
            ?? ($name === Webhooks::JOB ? Webhooks::spec() : throw new \LogicException("Unknown job '$name'"));
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
