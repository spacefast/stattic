<?php

declare(strict_types=1);

namespace Zero\Jobs;

use Zero\HttpError;
use Zero\Js;
use Zero\Response;
use Zero\Viewer;

use function Zero\db;

/**
 * At-most-once results (§6.1): `once(key, fn)` in compiled code and the `Idempotency-Key` header on
 * creates and `idempotent: true` actions. A receipt is written in the caller's transaction, so a
 * rollback forgets it and the next attempt runs again.
 */
final class Receipts
{
    public const MAX_KEY = 64;

    /** Fingerprint of the operation input that once() calls are made for (§6.1: same key + different input → 409). */
    private static string $fingerprint = '';

    /**
     * once() is bound to the input of the operation it runs in: an action or create body
     * (aroundOperation) or a job's input (Runner). Other contexts (update/delete hooks, wp-admin saves,
     * CLI) bind to the key alone, so their keys must name what they guard (e.g. include the row id).
     */
    /** Run $fn with once() bound to this input: the action/create body, or the job's input. */
    public static function withFingerprint(string $hash, callable $fn): mixed
    {
        $previous = self::$fingerprint;
        self::$fingerprint = $hash;
        try {
            return $fn();
        } finally {
            self::$fingerprint = $previous;
        }
    }

    /** `once(key, fn)`: scope 'fn', keyed per calling user, bound to the current operation's input. */
    public static function once(string $scope, mixed $key, callable $fn): mixed
    {
        if (!is_string($key)) {
            throw new \Zero\JsError('once key must be a string', 'TypeError');
        }
        $stored = self::remember($scope, self::key($key, ''), (int) (db()->actor()['id'] ?? 0), self::$fingerprint ?: hash('sha256', ''),
            static fn (): array => ['data' => $fn()], snapshot: static fn (array $result): array => Js::clone($result));

        return $stored['data'] ?? null;
    }

    /** Extensions::aroundOperation: creates always, actions when `idempotent: true`. */
    public static function around(string $scope, array $spec, string $rawBody, array $headers, ?Viewer $viewer, callable $next): Response
    {
        $key = $headers['idempotency-key'] ?? null;
        $next = static fn (): Response => self::withFingerprint(hash('sha256', $rawBody), $next);
        if ($key === null || (str_starts_with($scope, 'action:') && !($spec['idempotent'] ?? false))) {
            return $next();
        }
        $replayed = false;
        $stored = self::remember($scope, self::key($key, '/Idempotency-Key'), (int) ($viewer?->user['id'] ?? 0), hash('sha256', $rawBody),
            static function () use ($next): array {
                $response = $next();

                return ['status' => $response->status, 'data' => $response->data];
            }, $replayed, static function (array $result) use ($scope): array {
                if (str_starts_with($scope, 'action:')) {
                    $result['data'] = Js::jsonTree($result['data']);
                }
                return $result;
            });
        $response = Response::json($stored['data'] ?? null, (int) ($stored['status'] ?? 200));

        return $replayed ? $response->withHeaders(['Idempotent-Replayed' => 'true']) : $response;
    }

    private static function key(string $key, string $pointer): string
    {
        if ($key === '' || mb_strlen($key, 'UTF-8') > self::MAX_KEY) {
            throw new HttpError(400, 'invalid_idempotency_key', 'Idempotency keys are 1 to ' . self::MAX_KEY . ' characters', $pointer === '' ? null : $pointer);
        }

        return $key;
    }

    /** @param callable(): array $run @return array the stored result */
    private static function remember(string $scope, string $key, int $userId, string $hash, callable $run, bool &$replayed = false, ?callable $snapshot = null): array
    {
        return db()->transaction(static function () use ($scope, $key, $userId, $hash, $run, &$replayed, $snapshot): array {
            $where = 'WHERE "scope" = ? AND "key" = ? AND "userId" = ?';
            // Claim first: the primary key decides a race (the loser waits, then is ignored), and no
            // locking read ever runs on an absent key, so two first uses can't deadlock on gap locks.
            [$scopeBytes, $keyBytes] = [Storage::bytes($scope), Storage::bytes($key)];
            Storage::run(Storage::insertIgnore() . ' INTO {receipts} ("scope", "key", "userId", "inputHash", "state", "createdAt") VALUES (?, ?, ?, ?, \'started\', ?)',
                [$scopeBytes, $keyBytes, $userId, $hash, Js::iso(Js::now())]);
            $existing = Storage::changes() === 1 ? null
                : Storage::run("SELECT \"inputHash\", \"state\", \"result\" FROM {receipts} $where" . Storage::forShare(), [$scopeBytes, $keyBytes, $userId])[0] ?? null;
            if ($existing !== null) {
                if (!hash_equals((string) $existing['inputHash'], $hash)) {
                    throw new HttpError(409, 'idempotency_mismatch', 'This idempotency key was used with a different request');
                }
                if ($existing['state'] !== 'done') {
                    throw new HttpError(409, 'outcome_unknown', 'A request with this idempotency key started but has no recorded outcome');
                }
                $replayed = true;
                $result = unserialize((string) $existing['result'], ['allowed_classes' => [
                    \stdClass::class, \Zero\RegExp::class, \Zero\JsObject::class, \Zero\JsArray::class, \Zero\JsError::class,
                    \Zero\JsSet::class, \Zero\JsMap::class, \Zero\JsDate::class,
                ]]);

                return is_array($result) ? $result : [];
            }
            $result = $run();
            // serialize() keeps empty objects and lists apart, so a replay is byte-identical.
            Storage::run("UPDATE {receipts} SET \"state\" = 'done', \"result\" = ? $where", [serialize($snapshot === null ? $result : $snapshot($result)), $scopeBytes, $keyBytes, $userId]);

            return $result;
        });
    }
}
