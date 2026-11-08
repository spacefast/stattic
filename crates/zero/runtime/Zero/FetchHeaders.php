<?php

declare(strict_types=1);

namespace Zero;

/**
 * A WHATWG `Headers`: case-insensitive names, repeated names joined with ", ", iteration in sorted
 * name order. Response headers are immutable, as in fetch.
 */
final class FetchHeaders implements \JsonSerializable, \Stringable
{
    /** @var array<string, string> lowercase name => combined value */
    private array $values = [];

    public function __construct(mixed $init = null, private readonly bool $immutable = false)
    {
        if ($init === null || $init === JsUndefined::Value) {
            return;
        }
        if ($init instanceof self) {
            $this->values = $init->values;
            return;
        }
        if ($init instanceof JsArray) {
            foreach ($init->a as $pair) {
                if (!$pair instanceof JsArray || count($pair->a) !== 2) {
                    throw new JsError('Headers constructor: expected name/value pair to be length 2', 'TypeError');
                }
                $this->append($pair->a[0], $pair->a[1]);
            }
            return;
        }
        $pairs = $init instanceof JsObject ? $init->p : (is_array($init) ? $init : null);
        if ($pairs === null) {
            throw new JsError('Headers constructor: expected an object, pairs or Headers', 'TypeError');
        }
        foreach ($pairs as $name => $value) {
            $this->append((string) $name, $value);
        }
    }

    /** @param array<string, string> $values lowercase names, already validated */
    public static function received(array $values): self
    {
        $headers = new self(null, true);
        $headers->values = $values;

        return $headers;
    }

    public function get(mixed $name): ?string
    {
        return $this->values[self::name($name)] ?? null;
    }

    public function has(mixed $name): bool
    {
        return isset($this->values[self::name($name)]);
    }

    public function set(mixed $name, mixed $value): void
    {
        $this->guard();
        $this->values[self::name($name)] = self::value($value);
    }

    public function append(mixed $name, mixed $value): void
    {
        $this->guard();
        $name = self::name($name);
        $value = self::value($value);
        $this->values[$name] = isset($this->values[$name]) ? $this->values[$name] . ', ' . $value : $value;
    }

    public function delete(mixed $name): void
    {
        $this->guard();
        unset($this->values[self::name($name)]);
    }

    /** `forEach((value, name, headers) => ...)`, sorted by name. */
    public function forEach(callable $f): void
    {
        foreach ($this->sorted() as $name => $value) {
            $f($value, $name, $this);
        }
    }

    /** @return array<string, string> lowercase names in sorted order */
    public function sorted(): array
    {
        $values = $this->values;
        ksort($values, SORT_STRING);

        return $values;
    }

    public function jsonSerialize(): \stdClass
    {
        return new \stdClass();
    }

    public function __toString(): string
    {
        return '[object Headers]';
    }

    private function guard(): void
    {
        if ($this->immutable) {
            throw new JsError('immutable', 'TypeError');
        }
    }

    private static function name(mixed $name): string
    {
        $name = Js::str($name);
        if (preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) !== 1) {
            throw new JsError("Headers: invalid header name \"$name\"", 'TypeError');
        }

        return strtolower($name);
    }

    /** Leading and trailing HTTP whitespace is removed; CR, LF and NUL are refused. */
    private static function value(mixed $value): string
    {
        $value = trim(Js::str($value), " \t\n\r");
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new JsError('Headers: invalid header value', 'TypeError');
        }

        return $value;
    }
}
