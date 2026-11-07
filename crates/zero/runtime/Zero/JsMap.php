<?php
declare(strict_types=1);
namespace Zero;

final class JsMap
{
    public array $entries = [];

    private function index(mixed $key): ?int
    {
        foreach ($this->entries as $index => [$candidate]) {
            if (Js::strictEq($candidate, $key) || is_float($candidate) && is_nan($candidate) && is_float($key) && is_nan($key)) { return $index; }
        }
        return null;
    }

    public function set(mixed $key, mixed $value): self
    {
        if (is_float($key) && $key == 0) { $key = 0; }
        $index = $this->index($key);
        if ($index === null) { $this->entries[] = [$key, $value]; }
        else { $this->entries[$index][1] = $value; }
        return $this;
    }

    public function has(mixed $key): bool { return $this->index($key) !== null; }
    public function get(mixed $key): mixed
    {
        $index = $this->index($key);
        return $index === null ? JsUndefined::Value : $this->entries[$index][1];
    }
}
