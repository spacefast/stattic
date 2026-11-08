<?php
declare(strict_types=1);
namespace Zero;

/** WeakMap on PHP's native WeakMap: object and unregistered-symbol keys only. */
final class JsWeakMap
{
    private \WeakMap $entries;

    public function __construct() { $this->entries = new \WeakMap(); }

    public static function canBeHeldWeakly(mixed $value): bool
    {
        return is_object($value) && !$value instanceof JsUndefined && !($value instanceof JsSymbol && $value->registered());
    }

    public function set(mixed $key, mixed $value): self
    {
        if (!self::canBeHeldWeakly($key)) { throw new JsError('Invalid value used as weak map key', 'TypeError'); }
        $this->entries[$key] = $value;
        return $this;
    }

    public function get(mixed $key): mixed
    {
        return self::canBeHeldWeakly($key) && isset($this->entries[$key]) ? $this->entries[$key] : JsUndefined::Value;
    }

    public function has(mixed $key): bool
    {
        return self::canBeHeldWeakly($key) && $this->entries->offsetExists($key);
    }

    public function delete(mixed $key): bool
    {
        if (!$this->has($key)) { return false; }
        unset($this->entries[$key]);
        return true;
    }
}
