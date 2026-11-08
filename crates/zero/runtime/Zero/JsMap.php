<?php
declare(strict_types=1);
namespace Zero;

/**
 * JavaScript Map: insertion-ordered, SameValueZero keys, objects by identity.
 *
 * Entries live in an append-only slot list; a delete leaves a tombstone, so a live
 * iterator (an index into the slot list) visits entries added during iteration and
 * skips deleted ones, exactly as the ECMAScript [[MapData]] list does. `clear()`
 * tombstones every slot for the same reason. Lookup is O(1) through a key index.
 */
final class JsMap
{
    /** @var list<array{0: mixed, 1: mixed}|null> */
    private array $slots = [];
    /** @var array<string, int> */
    private array $index = [];
    private int $live = 0;

    public static function key(mixed $key): string
    {
        return match (true) {
            $key === null => 'n',
            $key === JsUndefined::Value => 'u',
            is_bool($key) => $key ? 't' : 'f',
            is_int($key) => 'd' . $key,
            is_float($key) => is_nan($key) ? 'N' : ($key == 0 ? 'd0' : (floor($key) === $key && abs($key) < 9.2e18 ? 'd' . (int) $key : 'd' . sprintf('%.17g', $key))),
            is_string($key) => 's' . $key,
            is_object($key) => 'o' . spl_object_id($key),
            default => throw new JsError('Unsupported Map key', 'TypeError'),
        };
    }

    public static function normalize(mixed $key): mixed
    {
        return is_float($key) && $key == 0 ? 0 : $key;
    }

    public function set(mixed $key, mixed $value): self
    {
        $key = self::normalize($key);
        $k = self::key($key);
        if (isset($this->index[$k])) {
            $this->slots[$this->index[$k]][1] = $value;
        } else {
            $this->index[$k] = count($this->slots);
            $this->slots[] = [$key, $value];
            $this->live++;
        }
        return $this;
    }

    public function has(mixed $key): bool { return isset($this->index[self::key(self::normalize($key))]); }

    public function get(mixed $key): mixed
    {
        $k = self::key(self::normalize($key));
        return isset($this->index[$k]) ? $this->slots[$this->index[$k]][1] : JsUndefined::Value;
    }

    public function delete(mixed $key): bool
    {
        $k = self::key(self::normalize($key));
        if (!isset($this->index[$k])) { return false; }
        $this->slots[$this->index[$k]] = null;
        unset($this->index[$k]);
        $this->live--;
        return true;
    }

    public function clear(): void
    {
        foreach ($this->slots as $i => $_) { $this->slots[$i] = null; }
        $this->index = [];
        $this->live = 0;
    }

    public function size(): int { return $this->live; }

    /** Live entries in insertion order; observes changes made while iterating. */
    public function iterate(): \Generator
    {
        for ($i = 0; $i < count($this->slots); $i++) {
            if ($this->slots[$i] !== null) { yield $this->slots[$i]; }
        }
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    public function entries(): array
    {
        return array_values(array_filter($this->slots, static fn ($slot) => $slot !== null));
    }

    public function entriesIterator(): \Generator { foreach ($this->iterate() as $entry) { yield new JsArray($entry); } }
    public function keysIterator(): \Generator { foreach ($this->iterate() as [$key]) { yield $key; } }
    public function valuesIterator(): \Generator { foreach ($this->iterate() as [, $value]) { yield $value; } }

    public function forEach(\Closure $callback): void
    {
        foreach ($this->iterate() as [$key, $value]) { $callback($value, $key, $this); }
    }

    /** Receipts persist the live entries; object ids are per-process, so the index is rebuilt. */
    public function __serialize(): array { return ['entries' => $this->entries()]; }

    /** Also reads receipts written before the index existed (the same public `entries` list). */
    public function __unserialize(array $data): void
    {
        $this->slots = [];
        $this->index = [];
        $this->live = 0;
        foreach ($data['entries'] ?? [] as [$key, $value]) { $this->set($key, $value); }
    }
}
