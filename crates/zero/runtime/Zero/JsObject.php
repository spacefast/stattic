<?php
declare(strict_types=1);
namespace Zero;
final class JsObject
{
    public bool $nullPrototype = false;
    /**
     * Non-enumerable own string-keyed properties (an Error's message, cause and stack).
     * Enumeration, JSON, spread and cloning read only $p, so these stay invisible to them.
     */
    public array $hidden = [];
    /**
     * Symbol-keyed own properties, apart from the string keys in $p so that
     * string enumeration (keys, for…in, JSON) never sees them.
     * spl_object_id(symbol) => [symbol, value, enumerable, writable, configurable]
     */
    public array $symbols = [];

    public function __construct(public array $p = [], public readonly ?JsClass $class = null, bool $nullPrototype = false)
    {
        $this->nullPrototype = $nullPrototype;
    }

    public function hasSymbol(JsSymbol $key): bool { return isset($this->symbols[spl_object_id($key)]); }

    public function getSymbol(JsSymbol $key): mixed { return $this->symbols[spl_object_id($key)][1] ?? JsUndefined::Value; }

    public function setSymbol(JsSymbol $key, mixed $value): mixed
    {
        $id = spl_object_id($key);
        if (isset($this->symbols[$id])) {
            if (!$this->symbols[$id][3]) { throw new JsError('Cannot assign to read only property ' . $key->descriptiveString(), 'TypeError'); }
            $this->symbols[$id][1] = $value;
        } else {
            $this->symbols[$id] = [$key, $value, true, true, true];
        }
        return $value;
    }
}
