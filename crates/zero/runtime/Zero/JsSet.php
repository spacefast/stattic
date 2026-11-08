<?php
declare(strict_types=1);
namespace Zero;

/** JavaScript Set: the JsMap slot model with value = key. */
final class JsSet
{
    /** @var list<array{0: mixed}|null> */
    private array $slots = [];
    /** @var array<string, int> */
    private array $index = [];
    private int $live = 0;

    public function add(mixed $value): self
    {
        $value = JsMap::normalize($value);
        $k = JsMap::key($value);
        if (!isset($this->index[$k])) {
            $this->index[$k] = count($this->slots);
            $this->slots[] = [$value];
            $this->live++;
        }
        return $this;
    }

    public function has(mixed $value): bool { return isset($this->index[JsMap::key(JsMap::normalize($value))]); }

    public function delete(mixed $value): bool
    {
        $k = JsMap::key(JsMap::normalize($value));
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

    public function iterate(): \Generator
    {
        for ($i = 0; $i < count($this->slots); $i++) {
            if ($this->slots[$i] !== null) { yield $this->slots[$i][0]; }
        }
    }

    /** @return list<mixed> */
    public function values(): array
    {
        $out = [];
        foreach ($this->slots as $slot) { if ($slot !== null) { $out[] = $slot[0]; } }
        return $out;
    }

    public function entriesIterator(): \Generator { foreach ($this->iterate() as $value) { yield new JsArray([$value, $value]); } }
    public function valuesIterator(): \Generator { yield from $this->iterate(); }

    public function forEach(\Closure $callback): void
    {
        foreach ($this->iterate() as $value) { $callback($value, $value, $this); }
    }

    /** Receipts persist the live values; object ids are per-process, so the index is rebuilt. */
    public function __serialize(): array { return ['values' => $this->values()]; }

    /** Also reads receipts written before the index existed (the same public `values` list). */
    public function __unserialize(array $data): void
    {
        $this->slots = [];
        $this->index = [];
        $this->live = 0;
        foreach ($data['values'] ?? [] as $value) { $this->add($value); }
    }
}
