<?php
declare(strict_types=1);
namespace Zero;

final class JsSet
{
    public array $values = [];

    public function add(mixed $value): self
    {
        if (is_float($value) && $value == 0) { $value = 0; }
        if (!$this->has($value)) { $this->values[] = $value; }
        return $this;
    }

    public function has(mixed $value): bool
    {
        foreach ($this->values as $item) {
            if (Js::strictEq($item, $value) || is_float($item) && is_nan($item) && is_float($value) && is_nan($value)) { return true; }
        }
        return false;
    }
}
