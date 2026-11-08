<?php
declare(strict_types=1);
namespace Zero;

/**
 * Descriptors carry dispatch identity; instance own properties stay in JsObject.
 *
 * Private names (`#x`) belong to one class evaluation, as in JavaScript: each
 * descriptor keeps its own brand table from receiver to private slots. Private
 * state never enters JsObject::$p, so enumeration, JSON and cloning cannot see it.
 */
final class JsClass
{
    /** @var \WeakMap<JsObject, array<string, mixed>>|null */
    private ?\WeakMap $brands = null;
    /** @var array<string, \Closure> */
    private array $methodValues = [];

    public function __construct(
        public readonly ?self $parent,
        private readonly ?\Closure $constructor,
        private readonly \Closure $fields,
        private readonly array $methods,
        private readonly array $staticMethods,
        public readonly JsObject $statics,
        private readonly array $privateMethods = [],
        private readonly array $staticPrivateMethods = [],
        private readonly ?JsObject $privateStatics = null,
        /** @var array<string, array{0: ?\Closure, 1: ?\Closure}> getter/setter pairs on the prototype */
        private readonly array $accessors = [],
        /** @var array<string, array{0: ?\Closure, 1: ?\Closure}> static getter/setter pairs */
        private readonly array $staticAccessors = [],
        /** Prototype data properties of a built-in root (Error.prototype.name and .message). */
        private readonly array $prototypeData = [],
        /** The built-in constructor this descriptor models ('Error', 'TypeError', 'RangeError'), if any. */
        public readonly ?string $builtin = null,
    ) {}

    /** @var array<string, self> */
    private static array $builtins = [];

    /**
     * The built-in Error constructors as class roots, so `class E extends Error` gets
     * Error's constructor, prototype name/message and toString, and instanceof sees them.
     */
    public static function builtin(string $name): self
    {
        if (isset(self::$builtins[$name])) { return self::$builtins[$name]; }
        if (!in_array($name, ['Error', 'TypeError', 'RangeError'], true)) {
            throw new JsError("Extending $name is not supported", 'TypeError');
        }
        $parent = $name === 'Error' ? null : self::builtin('Error');
        $constructor = static function (JsObject $self, mixed $message = JsUndefined::Value, mixed $options = JsUndefined::Value): void {
            if ($message !== JsUndefined::Value) { $self->hidden['message'] = Js::str($message); }
            // InstallErrorCause: HasProperty(options, "cause"), so an inherited cause counts too.
            if ($options instanceof JsObject && Js::hasProperty('cause', $options)) { $self->hidden['cause'] = Js::dynamicGet($options, 'cause'); }
            $self->hidden['stack'] = self::lazyStack();
        };
        $toString = static function (mixed $self): string {
            $name = Js::dynamicGet($self, 'name');
            $message = Js::dynamicGet($self, 'message');
            $name = $name === JsUndefined::Value ? 'Error' : Js::str($name);
            $message = $message === JsUndefined::Value ? '' : Js::str($message);
            return $name === '' ? $message : ($message === '' ? $name : "$name: $message");
        };
        return self::$builtins[$name] = new self(
            $parent,
            $constructor,
            static function (): void {},
            $parent === null ? ['toString' => $toString] : [],
            [],
            new JsObject(),
            prototypeData: ['name' => $name, 'message' => ''],
            builtin: $name,
        );
    }

    /** The root built-in constructor name of a class chain ('Error', 'TypeError', ...), or null. */
    public function builtinRoot(): ?string
    {
        for ($class = $this; $class !== null; $class = $class->parent) {
            if ($class->builtin !== null) { return $class->builtin; }
        }
        return null;
    }

    /** True when the chain reaches the built-in constructor $name (TypeError reaches Error too). */
    public function extendsBuiltin(string $name): bool
    {
        for ($class = $this; $class !== null; $class = $class->parent) {
            if ($class->builtin === $name) { return true; }
        }
        return false;
    }

    /** Marks a stack that is formatted on first read, as V8 does, from the error's current name and message. */
    public static function lazyStack(): \stdClass
    {
        static $marker;
        return $marker ??= new \stdClass();
    }

    /** Error.captureStackTrace(target): defines an own non-enumerable stack; Zero records no frames. */
    public static function captureStackTrace(mixed $target): JsUndefined
    {
        if (!$target instanceof JsObject) { throw new JsError('Invalid argument', 'TypeError'); }
        $target->hidden['stack'] = self::lazyStack();
        return JsUndefined::Value;
    }

    /** True for any prototype member: accessor, method or built-in prototype data. */
    public function hasMember(string $name): bool
    {
        if ($this->accessor($name, false) !== null || $this->hasMethod($name)) { return true; }
        for ($class = $this; $class !== null; $class = $class->parent) {
            if (array_key_exists($name, $class->prototypeData)) { return true; }
        }
        return false;
    }

    /** The nearest accessor pair for a key along the prototype chain, or null. */
    public function accessor(string $name, bool $static): ?array
    {
        $own = ($static ? $this->staticAccessors : $this->accessors)[$name] ?? null;
        if ($own !== null) { return $own; }
        if (isset(($static ? $this->staticMethods : $this->methods)[$name])) { return null; }
        if ($static && array_key_exists($name, $this->statics->p)) { return null; }
        return $this->parent?->accessor($name, $static);
    }

    /** Returned by readInherited when no prototype member has the name; compared by identity. */
    public static function absent(): \stdClass
    {
        static $absent;
        return $absent ??= new \stdClass();
    }

    /** [[Get]] for a name that is not an own data property: accessor first, then methods. */
    public function readInherited(JsObject|self $receiver, string $name, bool $static): mixed
    {
        $accessor = $this->accessor($name, $static);
        if ($accessor !== null) {
            return $accessor[0] === null ? JsUndefined::Value : ($accessor[0])($receiver);
        }
        if ($static ? $this->hasStaticMethod($name) : $this->hasMethod($name)) {
            return $this->methodValue($name, $static);
        }
        if (!$static) {
            for ($class = $this; $class !== null; $class = $class->parent) {
                if (array_key_exists($name, $class->prototypeData)) { return $class->prototypeData[$name]; }
            }
        }
        return self::absent();
    }

    /** [[Set]] through an inherited accessor; returns false when no accessor intercepts the write. */
    public function writeInherited(JsObject|self $receiver, string $name, mixed $value, bool $static): bool
    {
        $accessor = $this->accessor($name, $static);
        if ($accessor === null) { return false; }
        if ($accessor[1] === null) {
            throw new JsError("Cannot set property $name of #<Object> which has only a getter", 'TypeError');
        }
        ($accessor[1])($receiver, $value);
        return true;
    }

    public function construct(mixed ...$args): JsObject
    {
        $instance = new JsObject([], $this);
        $this->initialize($instance, $args);
        return $instance;
    }

    public function initialize(JsObject $instance, array $args): void
    {
        // A built-in root only runs its own constructor; it has no fields or parent fields.
        if ($this->builtin !== null) {
            ($this->constructor)($instance, ...array_slice($args, 0, 2));
            return;
        }
        if ($this->constructor !== null) {
            if ($this->parent === null) { ($this->fields)($instance, $this); }
            ($this->constructor)($instance, ...$args);
        } else {
            $this->parent?->initialize($instance, $args);
            ($this->fields)($instance, $this);
        }
    }

    public function hasMethod(string $name): bool
    {
        return isset($this->methods[$name]) || ($this->parent?->hasMethod($name) ?? false);
    }

    public function hasStaticMethod(string $name): bool
    {
        return isset($this->staticMethods[$name]) || ($this->parent?->hasStaticMethod($name) ?? false);
    }

    private function method(string $name, bool $static): \Closure
    {
        $method = ($static ? $this->staticMethods : $this->methods)[$name] ?? null;
        if ($method !== null) { return $method; }
        if ($this->parent !== null) { return $this->parent->method($name, $static); }
        throw new JsError("Unknown class method $name", 'TypeError');
    }

    /** One function identity per class and member, like a prototype method; call/apply supply the receiver. */
    public function methodValue(string $name, bool $static): \Closure
    {
        // Inherited members share the declaring class's function, as with one prototype.
        if (!isset(($static ? $this->staticMethods : $this->methods)[$name]) && $this->parent !== null) {
            return $this->parent->methodValue($name, $static);
        }
        $key = ($static ? 'static:' : '') . $name;
        if (!isset($this->methodValues[$key])) {
            $method = $this->method($name, $static);
            $this->methodValues[$key] = Js::dynamicMethodFunction(
                static fn (...$args) => $method(JsUndefined::Value, ...$args),
                static fn ($thisArg, JsArray $args) => $method($thisArg, ...$args->a),
            );
        }
        return $this->methodValues[$key];
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
        return $this->getFor($this, $key);
    }

    /** Static [[Get]] keeps the original class as the receiver for inherited static getters. */
    private function getFor(self $receiver, string $key): mixed
    {
        if (array_key_exists($key, $this->statics->p)) { return $this->statics->p[$key]; }
        $accessor = $this->staticAccessors[$key] ?? null;
        if ($accessor !== null) { return $accessor[0] === null ? JsUndefined::Value : ($accessor[0])($receiver); }
        return $this->parent?->getFor($receiver, $key);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->statics->p) || isset($this->staticAccessors[$key])
            || ($this->parent?->has($key) ?? false);
    }

    public function set(string $key, mixed $value): mixed
    {
        if (!array_key_exists($key, $this->statics->p) && $this->writeInherited($this, $key, $value, true)) { return $value; }
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

    /** Adds this class's brand when its field initialization starts (spec: InitializeInstanceElements). */
    public function brand(JsObject $instance): void
    {
        $this->brands ??= new \WeakMap();
        if (isset($this->brands[$instance])) {
            throw new JsError('Cannot initialize private methods of class twice on the same object', 'TypeError');
        }
        $this->brands[$instance] = [];
    }

    /** Defines a private field after its initializer ran (spec: PrivateFieldAdd). */
    public function privateDefine(JsObject $instance, string $name, mixed $value): void
    {
        $slots = $this->brands[$instance] ?? null;
        if ($slots === null || array_key_exists($name, $slots)) {
            throw new JsError("Cannot initialize $name twice on the same object", 'TypeError');
        }
        $this->brands[$instance][$name] = $value;
    }

    public function privateGet(mixed $receiver, string $name): mixed
    {
        if ($receiver === $this) {
            if ($this->privateStatics !== null && array_key_exists($name, $this->privateStatics->p)) { return $this->privateStatics->p[$name]; }
        } elseif ($receiver instanceof JsObject && $this->brands !== null && isset($this->brands[$receiver])
            && array_key_exists($name, $this->brands[$receiver])) {
            return $this->brands[$receiver][$name];
        }
        throw new JsError("Cannot read private member $name from an object whose class did not declare it", 'TypeError');
    }

    public function privateSet(mixed $receiver, string $name, mixed $value): mixed
    {
        if ($receiver === $this) {
            if ($this->privateStatics !== null && array_key_exists($name, $this->privateStatics->p)) {
                $this->privateStatics->p[$name] = $value;
                return $value;
            }
        } elseif ($receiver instanceof JsObject && $this->brands !== null && isset($this->brands[$receiver])
            && array_key_exists($name, $this->brands[$receiver])) {
            $this->brands[$receiver][$name] = $value;
            return $value;
        }
        throw new JsError("Cannot write private member $name to an object whose class did not declare it", 'TypeError');
    }

    public function privateCall(mixed $receiver, string $name, array $args): mixed
    {
        if ($receiver === $this) {
            $method = $this->staticPrivateMethods[$name] ?? null;
        } else {
            $method = $receiver instanceof JsObject && $this->brands !== null && isset($this->brands[$receiver])
                ? ($this->privateMethods[$name] ?? null)
                : null;
        }
        if ($method === null) {
            if ($receiver === $this || $receiver instanceof JsObject && isset($this->brands[$receiver])) {
                return Js::dynamicCall($this->privateGet($receiver, $name), new JsArray($args));
            }
            throw new JsError("Receiver must be an instance of the class that declares $name", 'TypeError');
        }
        return $method($receiver, ...$args);
    }

    /** `#x in value` (spec: PrivateElementFind on an object; primitives throw). */
    public function privateIn(mixed $value, string $name): bool
    {
        if (!is_object($value) || $value instanceof JsUndefined) {
            throw new JsError("Cannot use 'in' operator to search for '$name' in " . Js::str($value), 'TypeError');
        }
        if ($value === $this) {
            return isset($this->staticPrivateMethods[$name])
                || $this->privateStatics !== null && array_key_exists($name, $this->privateStatics->p);
        }
        if (!$value instanceof JsObject || $this->brands === null || !isset($this->brands[$value])) { return false; }
        return isset($this->privateMethods[$name]) || array_key_exists($name, $this->brands[$value]);
    }
}
