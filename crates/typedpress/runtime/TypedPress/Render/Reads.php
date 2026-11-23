<?php

declare(strict_types=1);

namespace TypedPress\Render;

/**
 * What a page render read: each kind, with the ids of the rows it returned and whether it ran a
 * listing (docs/spec/rendering.md §6.2), and the globals it read. Recorded through the read
 * pipeline's own hook, which sees every read the render makes, compiled or not; it returns rows
 * unchanged.
 *
 * A read is a listing unless the query names its rows by a unique key and every one of them came
 * back ({@see exact()}): how many rows a filter, a rank or a relation returned says nothing about
 * which writes change the answer. A listing that returned one row is still a listing.
 */
final class Reads
{
    /** @var ?array<string, array{ids: array<int|string, int|string>, listed: bool}> */
    private static ?array $current = null;
    /** @var array<string, true> what the render read that is not a kind's rows: `global:<name>` */
    private static array $also = [];
    private static bool $hooked = false;
    /** The kind whose read the hook sees next names its rows by a unique key ({@see exact()}). */
    private static ?string $exact = null;
    /** The kind whose reads are, right now, of the one row their caller names ({@see named()}). */
    private static ?string $named = null;

    /** A render is being recorded. */
    public static function recording(): bool
    {
        return self::$current !== null;
    }

    /**
     * Runs the hooks of one read of $collection ($read) as an exact read when $exact: its query named
     * its rows by a unique key and found them all, so only a write to one of those rows changes its
     * answer. Any other read is a listing, whatever it returned.
     *
     * @param callable(): array $read
     */
    public static function exact(string $collection, bool $exact, callable $read): array
    {
        $previous = self::$exact;
        self::$exact = $exact ? $collection : null;
        try {
            return $read();
        } finally {
            self::$exact = $previous;
        }
    }

    /**
     * Runs $fn, whose reads of $collection look up the one row a URL names (the router's item
     * lookup): the row it finds is what the page depends on, whichever column the URL names it by.
     * The item's own URL changes are announced by its post hooks (`Wp\Purges`).
     */
    public static function named(string $collection, callable $fn): mixed
    {
        $previous = self::$named;
        self::$named = $collection;
        try {
            return $fn();
        } finally {
            self::$named = $previous;
        }
    }

    /** The render read a global: a write to it changes what the render showed. */
    public static function global(string $name): void
    {
        if (self::$current !== null) {
            self::$also['global:' . $name] = true;
        }
    }

    public static function option(string $name): void
    {
        if (self::$current !== null) {
            self::$also['option:' . $name] = true;
        }
    }

    /**
     * A read that returned no rows but depended on all of a kind's (a count): recorded as a listing,
     * so any write to the kind counts as a change to what the render read.
     */
    public static function listed(string $collection): void
    {
        if (self::$current !== null) {
            self::$current[$collection] ??= ['ids' => [], 'listed' => false];
            self::$current[$collection]['listed'] = true;
        }
    }

    /**
     * Runs $fn and fills $reads with the rows it read, and $also with what it read besides
     * (`global:<name>`).
     *
     * @param-out array<string, array{ids: list<int|string>, listed: bool}> $reads
     * @param-out list<string> $also
     */
    public static function during(callable $fn, ?array &$reads = null, ?array &$also = null): mixed
    {
        if (!self::$hooked) {
            self::$hooked = true;
            \TypedPress\Extensions::onRead(static function (array $rows, string $collection): array {
                if (self::$current !== null) {
                    self::$current[$collection] ??= ['ids' => [], 'listed' => false];
                    $entry = &self::$current[$collection];
                    $entry['listed'] = $entry['listed'] || (self::$exact !== $collection && self::$named !== $collection);
                    $pk = \TypedPress\Db::instance()->schema()->collection($collection)['primaryKey'] ?? 'id';
                    foreach ($rows as $row) {
                        if (isset($row[$pk])) {
                            $id = $row[$pk];
                            // PHP converts numeric string keys to integers. Preserve strict ID identity.
                            $entry['ids'][is_int($id) ? $id : ':' . $id] = $id;
                        }
                    }
                }
                return $rows;
            }, true, static fn (): array => []); // the primary key only, which every read selects
        }
        $previous = [self::$current, self::$also];
        self::$current = [];
        self::$also = [];
        try {
            return $fn();
        } finally {
            $reads = self::$current;
            $also = array_keys(self::$also);
            [self::$current, self::$also] = $previous;
            foreach ($reads as &$entry) {
                $entry['ids'] = array_values($entry['ids']);
            }
            unset($entry);
        }
    }
}
