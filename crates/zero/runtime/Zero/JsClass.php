<?php
declare(strict_types=1);
namespace Zero;

/** Descriptors carry dispatch identity; instance own properties stay in JsObject. */
final class JsClass
{
    public function __construct(
        public readonly ?self $parent,
        private readonly ?\Closure $constructor,
        private readonly \Closure $fields,
        private readonly array $methods,
        private readonly array $staticMethods,
        public readonly JsObject $statics,
    ) {}

    public function construct(mixed ...$args): JsObject
    {
        $instance = new JsObject([], $this);
        $this->initialize($instance, $args);
        return $instance;
    }

    public function initialize(JsObject $instance, array $args): void
    {
        if ($this->constructor !== null) {
            if ($this->parent === null) { ($this->fields)($instance); }
            ($this->constructor)($instance, ...$args);
        } else {
            $this->parent?->initialize($instance, $args);
            ($this->fields)($instance);
        }
    }

    public function hasMethod(string $name): bool
    {
        return isset($this->methods[$name]) || ($this->parent?->hasMethod($name) ?? false);
    }

    private function method(string $name, bool $static): \Closure
    {
        $method = ($static ? $this->staticMethods : $this->methods)[$name] ?? null;
        if ($method !== null) { return $method; }
        if ($this->parent !== null) { return $this->parent->method($name, $static); }
        throw new JsError("Unknown class method $name", 'TypeError');
    }

    public function invokeOn(JsObject|self $receiver, string $name, array $args, bool $static): mixed
    {
        return ($this->method($name, $static))($receiver, ...$args);
    }

    public static function invoke(JsObject|self $receiver, string $name, array $args, bool $static): mixed
    {
        $class = $receiver instanceof self ? $receiver : $receiver->class;
        if ($class === null) { throw new JsError('Method receiver has no class', 'TypeError'); }
        return $class->invokeOn($receiver, $name, $args, $static);
    }

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->statics->p)) { return $this->statics->p[$key]; }
        return $this->parent?->get($key);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->statics->p) || ($this->parent?->has($key) ?? false);
    }

    public function set(string $key, mixed $value): mixed
    {
        $this->statics->p[$key] = $value;
        return $value;
    }

    public static function isInstance(mixed $value, self $expected): bool
    {
        if (!$value instanceof JsObject) { return false; }
        for ($class = $value->class; $class !== null; $class = $class->parent) {
            if ($class === $expected) { return true; }
        }
        return false;
    }
}
