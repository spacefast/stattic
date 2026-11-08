<?php

declare(strict_types=1);

namespace Zero;

/**
 * A WHATWG `URLSearchParams`: ordered name/value pairs, parsed and serialized as
 * application/x-www-form-urlencoded (see QueryString for the parser).
 */
final class SearchParams implements \JsonSerializable, \Stringable
{
    /** @var list<array{string, string}> */
    private array $pairs = [];

    public function __construct(mixed $init = null)
    {
        if ($init === null || $init === JsUndefined::Value) {
            return;
        }
        if (is_string($init)) {
            $this->pairs = QueryString::parse($init);
            return;
        }
        if ($init instanceof self) {
            $this->pairs = $init->pairs;
            return;
        }
        if ($init instanceof JsArray) {
            foreach ($init->a as $pair) {
                if (!$pair instanceof JsArray || count($pair->a) !== 2) {
                    throw new JsError("Failed to construct 'URLSearchParams': Each query pair must be an iterable [name, value] tuple", 'TypeError');
                }
                $this->append($pair->a[0], $pair->a[1]);
            }
            return;
        }
        if ($init instanceof JsObject) {
            foreach ($init->p as $name => $value) {
                $this->append((string) $name, $value);
            }
            return;
        }
        throw new JsError("Failed to construct 'URLSearchParams': unsupported init", 'TypeError');
    }

    public function append(mixed $name, mixed $value): void
    {
        $this->pairs[] = [self::scalar($name), self::scalar($value)];
    }

    public function set(mixed $name, mixed $value): void
    {
        $name = self::scalar($name);
        $value = self::scalar($value);
        $out = [];
        $done = false;
        foreach ($this->pairs as $pair) {
            if ($pair[0] !== $name) {
                $out[] = $pair;
            } elseif (!$done) {
                $out[] = [$name, $value];
                $done = true;
            }
        }
        if (!$done) {
            $out[] = [$name, $value];
        }
        $this->pairs = $out;
    }

    public function delete(mixed $name): void
    {
        $name = self::scalar($name);
        $this->pairs = array_values(array_filter($this->pairs, static fn (array $pair): bool => $pair[0] !== $name));
    }

    public function get(mixed $name): ?string
    {
        $name = self::scalar($name);
        foreach ($this->pairs as $pair) {
            if ($pair[0] === $name) {
                return $pair[1];
            }
        }

        return null;
    }

    public function getAll(mixed $name): JsArray
    {
        $name = self::scalar($name);

        return new JsArray(array_values(array_map(
            static fn (array $pair): string => $pair[1],
            array_filter($this->pairs, static fn (array $pair): bool => $pair[0] === $name),
        )));
    }

    public function has(mixed $name): bool
    {
        return $this->get($name) !== null;
    }

    public function __toString(): string
    {
        return implode('&', array_map(static fn (array $pair): string => self::encode($pair[0]) . '=' . self::encode($pair[1]), $this->pairs));
    }

    public function jsonSerialize(): \stdClass
    {
        return new \stdClass();
    }

    /** The urlencoded serializer: `*-._` and alphanumerics stay, space is `+`, the rest is %XX of UTF-8. */
    private static function encode(string $value): string
    {
        return str_replace('%20', '+', preg_replace_callback(
            '/[^*\-._0-9A-Za-z]/',
            static fn (array $m): string => sprintf('%%%02X', ord($m[0])),
            self::wellFormed($value),
        ));
    }

    /** Lone surrogates cannot occur in PHP strings; invalid UTF-8 becomes U+FFFD as USVString conversion does. */
    private static function wellFormed(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub($value, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    private static function scalar(mixed $value): string
    {
        return Js::str($value);
    }
}
