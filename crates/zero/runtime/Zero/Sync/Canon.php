<?php

declare(strict_types=1);

namespace Zero\Sync;

use Zero\Js;

/**
 * Canonical JSON, the one spelling both sides digest (§5.3): object keys sorted by byte order at every
 * depth, no whitespace, slashes/Unicode/line terminators unescaped, numbers as JavaScript prints them
 * (integral floats without `.0`). src/sync/canon.rs implements the same bytes; the shared golden in
 * tests/fixtures/canon pins both.
 */
final class Canon
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function json(mixed $value): string
    {
        return match (true) {
            $value === null || is_bool($value) || is_int($value) || is_string($value) => json_encode($value, self::FLAGS),
            is_float($value) => is_finite($value) ? Js::numStr($value) : throw new \InvalidArgumentException('Non-finite numbers have no JSON form'),
            $value instanceof \Zero\JsObject => self::object($value->p),
            $value instanceof \Zero\JsArray => '[' . implode(',', array_map(self::json(...), $value->a)) . ']',
            $value instanceof \stdClass => self::object(get_object_vars($value)),
            is_array($value) && array_is_list($value) => '[' . implode(',', array_map(self::json(...), $value)) . ']',
            is_array($value) => self::object($value),
            default => throw new \InvalidArgumentException('Only JSON values have a canonical form'),
        };
    }

    public static function digest(mixed $value): string
    {
        return 'sha256:' . hash('sha256', self::json($value));
    }

    private static function object(array $members): string
    {
        $keys = array_map('strval', array_keys($members));
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $key) {
            $out[] = json_encode($key, self::FLAGS) . ':' . self::json($members[$key]);
        }

        return '{' . implode(',', $out) . '}';
    }
}
