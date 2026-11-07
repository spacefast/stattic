<?php

declare(strict_types=1);

namespace Zero;

/** Runtime registrations, in feature order. Callbacks persist; afterCommit belongs to the store. */
final class Extensions
{
    /** @var array<string, list<callable>> */
    private static array $hooks = [];
    /** @var array<string, callable> */
    private static array $routes = [];
    /** @var array<string, callable> */
    private static array $commands = [];
    private static bool $unprovenReadScope = false;

    /**
     * @param callable(string $collection, array &$opts, ?Viewer $viewer, string $pointer): void $fn before option validation and SQL
     * @param bool $constraintSafe true only when row membership and constraint columns are preserved except for schema access rules
     */
    public static function beforeRead(callable $fn, bool $constraintSafe = false): void
    {
        self::$hooks['query'][] = $fn;
        self::$unprovenReadScope = self::$unprovenReadScope || !$constraintSafe;
    }

    /**
     * @param callable(array $rows, string $collection, ?Viewer $viewer): array $fn after decode, before `with`; keep primary keys intact
     * @param bool $constraintSafe true only when row membership and constraint columns are preserved except for schema access rules
     */
    public static function onRead(callable $fn, bool $constraintSafe = false): void
    {
        self::$hooks['read'][] = $fn;
        self::$unprovenReadScope = self::$unprovenReadScope || !$constraintSafe;
    }

    /** Custom read callbacks may hide arbitrary rows/values unless their policy is already guarded. */
    public static function constraintReadsSafe(): bool
    {
        return !self::$unprovenReadScope;
    }

    /** @param callable(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void $fn */
    public static function onWrite(callable $fn): void
    {
        self::$hooks['write'][] = $fn;
    }

    /** Consume virtual input before column validation; retain $provided unchanged. Normalizers must not persist effects. */
    public static function normalizeWrite(callable $fn): void
    {
        self::$hooks['input'][] = $fn;
    }

    /** @param callable(string, array, string, array, ?Viewer, callable(): Response): Response $fn after authorization, inside the transaction */
    public static function aroundOperation(callable $fn): void
    {
        self::$hooks['execute'][] = $fn;
    }

    /** @param callable(string $op, string $collection, array $row, ?array $previous): void $fn after the app hook, inside the transaction */
    public static function onWritten(callable $fn): void
    {
        self::$hooks['written'][] = $fn;
    }

    /** Run after the active store's outermost commit; immediately if no transaction is open. */
    public static function afterCommit(callable $fn): void
    {
        $db = db();
        $db->store()->afterCommit(static fn () => $db->current($fn));
    }

    /**
     * Own a first path segment. The handler receives the full decoded segment list.
     * @param callable(string, array, array, string, Viewer, array, array, array): (array{int, mixed}|Response) $handler
     */
    public static function route(string $prefix, callable $handler): void
    {
        if ($prefix === '' || str_contains($prefix, '/') || isset(self::$routes[$prefix])) {
            throw new \LogicException("Invalid or duplicate extension route '$prefix'");
        }
        self::$routes[$prefix] = $handler;
    }

    /** @param callable(array $actionSpec, string $name, string $rawBody, array $headers, ?Viewer $viewer): void $fn before input validation; null viewer is trusted CLI */
    public static function beforeAction(callable $fn): void
    {
        self::$hooks['action'][] = $fn;
    }

    /** @param callable(list<string> $args, array<string, string|true> $flags): mixed $fn inside the command transaction; args exclude the command */
    public static function cli(string $command, callable $fn): void
    {
        if ($command === '' || isset(self::$commands[$command])) {
            throw new \LogicException("Invalid or duplicate extension command '$command'");
        }
        self::$commands[$command] = $fn;
    }

    /** @param callable(array $describe): array $fn */
    public static function schemaDescribe(callable $fn): void
    {
        self::$hooks['describe'][] = $fn;
    }

    /** @param callable(string $collection, list<int|string> $pks): void $fn primes page caches before store hydration or saved-result presentation */
    public static function onLookup(callable $fn): void
    {
        self::$hooks['lookup'][] = $fn;
    }

    public static function query(string $collection, array &$opts, ?Viewer $viewer, string $pointer): void
    {
        foreach (self::$hooks['query'] ?? [] as $fn) {
            $fn($collection, $opts, $viewer, $pointer);
        }
    }

    public static function read(array $rows, string $collection, ?Viewer $viewer): array
    {
        foreach (self::$hooks['read'] ?? [] as $fn) {
            $rows = $fn($rows, $collection, $viewer);
        }

        return $rows;
    }

    public static function write(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void
    {
        foreach (self::$hooks['write'] ?? [] as $fn) {
            $fn($op, $collection, $data, $existing, $viewer, $provided);
        }
    }

    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        foreach (self::$hooks['written'] ?? [] as $fn) {
            $fn($op, $collection, $row, $previous);
        }
    }

    public static function input(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void
    {
        foreach (self::$hooks['input'] ?? [] as $fn) {
            $fn($op, $collection, $data, $existing, $viewer, $provided);
        }
    }

    public static function execute(string $scope, array $spec, string $rawBody, array $headers, ?Viewer $viewer, callable $run): Response
    {
        foreach (array_reverse(self::$hooks['execute'] ?? []) as $fn) {
            $next = $run;
            $run = static fn (): Response => $fn($scope, $spec, $rawBody, $headers, $viewer, $next);
        }

        return $run();
    }

    public static function routeHandler(string $prefix): ?callable
    {
        return self::$routes[$prefix] ?? null;
    }

    public static function action(array $spec, string $name, string $rawBody, array $headers, ?Viewer $viewer): void
    {
        foreach (self::$hooks['action'] ?? [] as $fn) {
            $fn($spec, $name, $rawBody, $headers, $viewer);
        }
    }

    public static function cliHandler(string $command): ?callable
    {
        return self::$commands[$command] ?? null;
    }

    public static function describe(array $describe): array
    {
        foreach (self::$hooks['describe'] ?? [] as $fn) {
            $describe = $fn($describe);
        }

        return $describe;
    }

    /** @param list<int|string> $pks */
    public static function lookup(string $collection, array $pks): void
    {
        if ($pks === []) {
            return;
        }
        foreach (self::$hooks['lookup'] ?? [] as $fn) {
            $fn($collection, $pks);
        }
    }
}
