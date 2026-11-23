<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * Whether this request serves a preview binding: a host that runs each preview on its own database
 * says so with `preview: true` in `Loader::load` ({@see \TypedPress\Loader\Loader::load()}). Off by
 * default, and off everywhere TypedPress mounts itself, so production behaves as it always has.
 *
 * A preview seeds again on every new version, with the production values of its variables, so on a
 * preview the seed's writes queue no webhook deliveries and no mail ({@see quiet()}).
 */
final class Preview
{
    private static bool $on = false;
    private static bool $seeding = false;

    /** Set by the loader from its `preview` option, before the app mounts. */
    public static function bind(bool $on): void
    {
        self::$on = $on;
    }

    public static function on(): bool
    {
        return self::$on;
    }

    /** Run the seed's writes; on a preview they are {@see quiet()} meanwhile. */
    public static function seeding(callable $fn): mixed
    {
        $was = self::$seeding;
        self::$seeding = true;
        try {
            return $fn();
        } finally {
            self::$seeding = $was;
        }
    }

    /** True while a preview seeds: its writes queue no webhook deliveries and no mail. */
    public static function quiet(): bool
    {
        return self::$on && self::$seeding;
    }
}
