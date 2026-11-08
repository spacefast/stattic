<?php
declare(strict_types=1);
namespace Zero;

/** A built-in iterator (Map/Set `keys()`, `values()`, `entries()`), backed by a live PHP Generator. */
final class JsIterator
{
    private bool $started = false;

    public function __construct(private readonly \Generator $source, public readonly string $tag) {}

    /** Remaining values; the iterator is consumed, as in JavaScript. */
    public function rest(): \Generator
    {
        while (true) {
            $step = $this->step();
            if ($step === null) { return; }
            yield $step[0];
        }
    }

    /** @return array{0: mixed}|null */
    private function step(): ?array
    {
        if ($this->started) { $this->source->next(); } else { $this->started = true; }
        return $this->source->valid() ? [$this->source->current()] : null;
    }

    public function next(): JsObject
    {
        $step = $this->step();
        return new JsObject($step === null ? ['value' => JsUndefined::Value, 'done' => true] : ['value' => $step[0], 'done' => false]);
    }
}
