<?php
declare(strict_types=1);
namespace Zero;

/** WeakSet on PHP's native WeakMap: object and unregistered-symbol values only. */
final class JsWeakSet
{
    private \WeakMap $values;

    public function __construct() { $this->values = new \WeakMap(); }

    public function add(mixed $value): self
    {
        if (!JsWeakMap::canBeHeldWeakly($value)) { throw new JsError('Invalid value used in weak set', 'TypeError'); }
        $this->values[$value] = true;
        return $this;
    }

    public function has(mixed $value): bool
    {
        return JsWeakMap::canBeHeldWeakly($value) && $this->values->offsetExists($value);
    }

    public function delete(mixed $value): bool
    {
        if (!$this->has($value)) { return false; }
        unset($this->values[$value]);
        return true;
    }
}
