<?php
declare(strict_types=1);
namespace Zero;

/**
 * A JavaScript symbol. PHP object identity is symbol identity. The registry
 * behind Symbol.for lives for one request, like every module-level value.
 */
final class JsSymbol
{
    private const WELL_KNOWN = ['asyncIterator', 'hasInstance', 'iterator', 'toStringTag'];
    private static array $registry = [];
    private static array $wellKnown = [];

    private function __construct(public readonly string|JsUndefined $description, public readonly ?string $registryKey = null, public readonly bool $isWellKnown = false) {}

    /** `Symbol(description)`: a fresh symbol each call. */
    public static function create(JsArray $args): self
    {
        $description = array_key_exists(0, $args->a) ? $args->a[0] : JsUndefined::Value;
        return new self($description === JsUndefined::Value ? JsUndefined::Value : Js::str($description));
    }

    /** `Symbol.for(key)`: one symbol per key string for the request. */
    public static function for(mixed $key): self
    {
        $key = Js::str($key);
        return self::$registry[$key] ??= new self($key, $key);
    }

    public static function keyFor(mixed $symbol): string|JsUndefined
    {
        if (!$symbol instanceof self) { throw new JsError(Js::typeOf($symbol) . ' is not a symbol', 'TypeError'); }
        return $symbol->registryKey ?? JsUndefined::Value;
    }

    public static function wellKnown(string $name): self
    {
        if (!in_array($name, self::WELL_KNOWN, true)) { throw new JsError('Unsupported well-known symbol Symbol.' . $name, 'TypeError'); }
        return self::$wellKnown[$name] ??= new self('Symbol.' . $name, null, true);
    }

    /** Registered symbols are not weakly held, so they can't be weak collection keys. */
    public function registered(): bool { return $this->registryKey !== null; }

    /** `String(symbol)` and `symbol.toString()`; implicit conversion throws instead. */
    public function descriptiveString(): string
    {
        return 'Symbol(' . ($this->description === JsUndefined::Value ? '' : $this->description) . ')';
    }
}
