<?php

declare(strict_types=1);

namespace Zero;

/**
 * A raw query string parsed as the URL Standard's application/x-www-form-urlencoded parser
 * (what URLSearchParams does), not as PHP's `parse_str`/`$_GET`: names stay byte-exact
 * (`a.b` stays `a.b`, `x[]` stays `x[]`), and every pair is kept in order, so a repeated name keeps
 * all its values and `get()` answers the first.
 */
final class QueryString
{
    /** @return list<array{string, string}> name/value pairs in order */
    public static function parse(string $query): array
    {
        $query = str_starts_with($query, '?') ? substr($query, 1) : $query;
        $pairs = [];
        foreach (explode('&', $query) as $sequence) {
            if ($sequence === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $sequence, 2), 2, '');
            $pairs[] = [self::decode($name), self::decode($value)];
        }

        return $pairs;
    }

    /** `+` is a space; a `%` not followed by two hex digits stays as it is; bytes that are not UTF-8 become U+FFFD. */
    private static function decode(string $part): string
    {
        $bytes = preg_replace_callback('/%([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), str_replace('+', ' ', $part));

        // U+FFFD for each invalid sequence, as the URL Standard's UTF-8 decode without BOM does.
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub((string) $bytes, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * The compiled `req.query`: `get(name)` (first value or null), `getAll(name)`, `has(name)`
     * and `entries()`, closures over the parsed pairs.
     *
     * @param list<array{string, string}> $pairs
     */
    public static function toJs(array $pairs): JsObject
    {
        $all = static fn (mixed $name): array => array_values(array_map(
            static fn (array $pair): string => $pair[1],
            array_filter($pairs, static fn (array $pair): bool => $pair[0] === Js::str($name)),
        ));

        return new JsObject([
            'get' => static fn (mixed $name): ?string => $all($name)[0] ?? null,
            'getAll' => static fn (mixed $name): JsArray => new JsArray($all($name)),
            'has' => static fn (mixed $name): bool => $all($name) !== [],
            'entries' => static fn (): JsArray => new JsArray(array_map(static fn (array $pair): JsArray => new JsArray($pair), $pairs)),
        ]);
    }
}
