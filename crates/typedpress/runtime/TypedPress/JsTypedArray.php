<?php
declare(strict_types=1);
namespace TypedPress;

/**
 * A JavaScript integer or float TypedArray: a view of $length elements starting at byte $offset
 * of a shared JsArrayBuffer. Algorithms follow ECMA-262 §23.2 (and Boa's builtins/typed_array).
 * Element bytes are little-endian, as Node reports on every platform WordPress runs on.
 */
final class JsTypedArray
{
    /** name => [bytes per element, pack format, kind] */
    public const KINDS = [
        'Int8Array' => [1, 'c', 'int'], 'Uint8Array' => [1, 'C', 'int'], 'Uint8ClampedArray' => [1, 'C', 'clamp'],
        'Int16Array' => [2, 's', 'int'], 'Uint16Array' => [2, 'v', 'int'],
        'Int32Array' => [4, 'l', 'int'], 'Uint32Array' => [4, 'V', 'int'],
        'Float32Array' => [4, 'g', 'float'], 'Float64Array' => [8, 'e', 'float'],
    ];

    public readonly int $size;

    public function __construct(public readonly string $kind, public readonly JsArrayBuffer $buffer, public readonly int $offset, public readonly int $length)
    {
        $this->size = self::KINDS[$kind][0];
    }

    /** A Uint8Array over its own copy of these bytes. */
    public static function fromBytes(string $bytes): self
    {
        return new self('Uint8Array', new JsArrayBuffer($bytes), 0, strlen($bytes));
    }

    public static function isKind(mixed $name): bool { return is_string($name) && array_key_exists($name, self::KINDS); }

    public static function allocate(string $kind, int $length): self
    {
        $size = self::KINDS[$kind][0];
        if ($length * $size > JsArrayBuffer::MAX) { throw new JsError('Array buffer allocation failed', 'RangeError'); }
        return new self($kind, new JsArrayBuffer(str_repeat("\0", $length * $size)), 0, $length);
    }

    /** `new XArray(...)`: length, typed array, ArrayBuffer (+offset, length), or iterable/array-like. */
    public static function construct(string $kind, array $a): self
    {
        $first = array_key_exists(0, $a) ? $a[0] : JsUndefined::Value;
        $size = self::KINDS[$kind][0];
        if ($first instanceof JsArrayBuffer) {
            $offset = JsArrayBuffer::byteCount($a[1] ?? JsUndefined::Value);
            if ($offset % $size !== 0) { throw new JsError('start offset of ' . $kind . ' should be a multiple of ' . $size, 'RangeError'); }
            $byteLength = $first->byteLength();
            if (($a[2] ?? JsUndefined::Value) === JsUndefined::Value) {
                if ($byteLength % $size !== 0) { throw new JsError('byte length of ' . $kind . ' should be a multiple of ' . $size, 'RangeError'); }
                if ($offset > $byteLength) { throw new JsError('Start offset ' . $offset . ' is outside the bounds of the buffer', 'RangeError'); }
                return new self($kind, $first, $offset, intdiv($byteLength - $offset, $size));
            }
            $length = JsArrayBuffer::byteCount($a[2]);
            if ($offset + $length * $size > $byteLength) { throw new JsError('Invalid typed array length: ' . $length, 'RangeError'); }
            return new self($kind, $first, $offset, $length);
        }
        if (!is_object($first) || $first instanceof JsUndefined) {
            return self::allocate($kind, JsArrayBuffer::byteCount($first));
        }
        if ($first instanceof self) {
            $out = self::allocate($kind, $first->length);
            if ($out->kind === $first->kind) { $out->buffer->bytes = $first->bytes(); }
            else { foreach ($first->values() as $i => $value) { $out->put($i, $value); } }
            return $out;
        }
        if ($first instanceof \Closure || $first instanceof JsDataView || $first instanceof JsBlob) {
            return self::allocate($kind, 0);
        }
        $values = self::listOf($first);
        $out = self::allocate($kind, count($values));
        foreach ($values as $i => $value) { $out->put($i, Js::toNumber($value)); }
        return $out;
    }

    /** Values of an iterable or array-like object, read before any conversion (§23.2.5.1). */
    private static function listOf(mixed $source): array
    {
        if ($source instanceof JsArray || $source instanceof JsSet || $source instanceof JsMap || $source instanceof JsArguments || is_string($source)) {
            return Js::dynamicIterable($source)->a;
        }
        $length = (int) min(JsArrayBuffer::MAX, max(0, Js::toInteger(Js::toNumber(Js::dynamicGet($source, 'length')))));
        $values = [];
        for ($i = 0; $i < $length; $i++) { $values[] = Js::dynamicGet($source, (string) $i); }
        return $values;
    }

    public static function from(string $kind, array $a): self
    {
        $source = Js::requirePresent($a[0] ?? JsUndefined::Value);
        $mapper = $a[1] ?? JsUndefined::Value;
        if (($a[2] ?? JsUndefined::Value) !== JsUndefined::Value) { throw new JsError($kind . '.from thisArg is not supported', 'TypeError'); }
        if ($mapper !== JsUndefined::Value && !$mapper instanceof \Closure) { throw new JsError('Callback is not a function', 'TypeError'); }
        $values = $source instanceof self ? $source->values() : self::listOf($source);
        $out = self::allocate($kind, count($values));
        foreach ($values as $i => $value) { $out->put($i, Js::toNumber($mapper === JsUndefined::Value ? $value : $mapper($value, $i))); }
        return $out;
    }

    public static function of(string $kind, array $a): self
    {
        $out = self::allocate($kind, count($a));
        foreach (array_values($a) as $i => $value) { $out->put($i, Js::toNumber($value)); }
        return $out;
    }

    public function byteLength(): int { return $this->length * $this->size; }
    public function bytes(): string { return substr($this->buffer->bytes, $this->offset, $this->length * $this->size); }

    public function at(int $index): int|float
    {
        if ($this->size === 1 && $this->kind !== 'Int8Array') { return ord($this->buffer->bytes[$this->offset + $index]); }
        return self::decode($this->kind, substr($this->buffer->bytes, $this->offset + $index * $this->size, $this->size));
    }

    /** Store an already ToNumber-converted value (IntegerIndexedElementSet). */
    public function put(int $index, int|float $value): void
    {
        $bytes = self::encode($this->kind, $value);
        $at = $this->offset + $index * $this->size;
        if ($this->size === 1) { $this->buffer->bytes[$at] = $bytes; }
        else { $this->buffer->bytes = substr_replace($this->buffer->bytes, $bytes, $at, $this->size); }
    }

    public static function decode(string $kind, string $bytes): int|float
    {
        $value = unpack(self::KINDS[$kind][1], $bytes)[1];
        if ($kind === 'Int16Array' && $value >= 0x8000) { return $value - 0x10000; }
        if ($kind === 'Int32Array' && $value >= 0x80000000) { return $value - 0x100000000; }
        if (is_float($value)) { return $value === 0.0 && fdiv(1, $value) < 0 ? -0.0 : Js::n($value == floor($value) && abs($value) < 9007199254740992 ? (int) $value : $value); }
        return $value;
    }

    public static function encode(string $kind, int|float $value): string
    {
        [, $format, $type] = self::KINDS[$kind];
        if ($type === 'float') { return pack($format, (float) $value); }
        if ($type === 'clamp') {
            if (is_float($value) && is_nan($value) || $value <= 0) { return "\0"; }
            if ($value >= 255) { return "\xFF"; }
            $floor = floor($value); $half = $value - $floor;
            $rounded = $half < 0.5 ? $floor : ($half > 0.5 ? $floor + 1 : ((int) $floor % 2 === 0 ? $floor : $floor + 1));
            return chr((int) $rounded);
        }
        $size = self::KINDS[$kind][0];
        $modulo = 2 ** (8 * $size);
        if (is_float($value)) {
            if (!is_finite($value)) { return str_repeat("\0", $size); }
            $value = fmod($value < 0 ? ceil($value) : floor($value), (float) $modulo);
            if ($value < 0) { $value += $modulo; }
            $value = (int) $value;
        } else {
            $value %= $modulo;
            if ($value < 0) { $value += $modulo; }
        }
        return match ($size) { 1 => chr($value), 2 => pack('v', $value), default => pack('V', $value) };
    }

    /** @return list<int|float> */
    public function values(): array
    {
        if ($this->length === 0) { return []; }
        if ($this->kind === 'Uint8Array' || $this->kind === 'Uint8ClampedArray') { return array_values(unpack('C*', $this->bytes())); }
        $out = [];
        for ($i = 0; $i < $this->length; $i++) { $out[] = $this->at($i); }
        return $out;
    }

    /** Relative start/end against a length, as for slice/subarray/fill. */
    public static function range(mixed $start, mixed $end, int $length): array
    {
        $rel = static function (mixed $value, int $default) use ($length): int {
            if ($value === JsUndefined::Value) { return $default; }
            $n = Js::toInteger(Js::toNumber($value));
            return (int) ($n < 0 ? max($length + $n, 0) : min($n, $length));
        };
        return [$rel($start, 0), $rel($end, $length)];
    }

    /** A canonical numeric index string (CanonicalNumericIndexString), or null for an ordinary key. */
    public static function numericIndex(string $key): int|float|null
    {
        if ($key === '-0') { return -0.0; }
        if (preg_match('/^(?:0|[1-9][0-9]{0,15})$/D', $key)) { return (int) $key; }
        if ($key === '' || !preg_match('/^[-0-9.eEIinfatyN+]+$/D', $key)) { return null; }
        $n = Js::toNumber($key);
        return Js::str($n) === $key ? $n : null;
    }

    public function get(string $key): mixed
    {
        $index = self::numericIndex($key);
        if ($index !== null) {
            return is_int($index) && $index < $this->length ? $this->at($index) : JsUndefined::Value;
        }
        return match ($key) {
            'length' => $this->length, 'byteLength' => $this->byteLength(), 'byteOffset' => $this->offset,
            'buffer' => $this->buffer, 'BYTES_PER_ELEMENT' => $this->size,
            default => null,
        };
    }

    public function set(string $key, mixed $value): bool
    {
        $index = self::numericIndex($key);
        if ($index === null) { return false; }
        $number = Js::toNumber($value);
        if (is_int($index) && $index < $this->length) { $this->put($index, $number); }
        return true;
    }

    public function subarray(mixed $start, mixed $end): self
    {
        [$from, $to] = self::range($start, $end, $this->length);
        return new self($this->kind, $this->buffer, $this->offset + $from * $this->size, max(0, $to - $from));
    }

    public function slice(mixed $start, mixed $end): self
    {
        [$from, $to] = self::range($start, $end, $this->length);
        $count = max(0, $to - $from);
        return new self($this->kind, new JsArrayBuffer(substr($this->buffer->bytes, $this->offset + $from * $this->size, $count * $this->size)), 0, $count);
    }

    /** %TypedArray%.prototype.set(source, offset): overlapping same-buffer sources are copied first. */
    public function setFrom(mixed $source, mixed $offset): void
    {
        $target = Js::toInteger(Js::toNumber($offset === JsUndefined::Value ? 0 : $offset));
        if ($target < 0) { throw new JsError('offset is out of bounds', 'RangeError'); }
        if ($source instanceof self) {
            if ($source->length + $target > $this->length) { throw new JsError('offset is out of bounds', 'RangeError'); }
            if ($source->kind === $this->kind || $this->size === 1 && $source->size === 1 && $this->kind !== 'Int8Array' && $source->kind !== 'Int8Array') {
                $this->buffer->bytes = substr_replace($this->buffer->bytes, $source->bytes(), $this->offset + (int) $target * $this->size, $source->byteLength());
                return;
            }
            foreach ($source->values() as $i => $value) { $this->put((int) $target + $i, $value); }
            return;
        }
        if (is_int($source) || is_float($source) || is_bool($source) || $source === null || $source === JsUndefined::Value) {
            if ($source === null || $source === JsUndefined::Value) { throw new JsError('Cannot convert undefined or null to object', 'TypeError'); }
            if ($target > $this->length) { throw new JsError('offset is out of bounds', 'RangeError'); }
            return;
        }
        $length = is_string($source) ? Js::length($source) : Js::toInteger(Js::toNumber(Js::dynamicGet($source, 'length')));
        if ($length + $target > $this->length) { throw new JsError('offset is out of bounds', 'RangeError'); }
        for ($i = 0; $i < $length; $i++) {
            $this->put((int) $target + $i, Js::toNumber(Js::dynamicGet($source, (string) $i)));
        }
    }

    public function fill(mixed $value, mixed $start, mixed $end): self
    {
        $number = Js::toNumber($value);
        [$from, $to] = self::range($start, $end, $this->length);
        if ($to <= $from) { return $this; }
        $bytes = str_repeat(self::encode($this->kind, $number), $to - $from);
        $this->buffer->bytes = substr_replace($this->buffer->bytes, $bytes, $this->offset + $from * $this->size, strlen($bytes));
        return $this;
    }

    /** indexOf/lastIndexOf/includes with a numeric search value (strict equality, NaN only for includes). */
    public function search(string $method, mixed $value, mixed $from): int|bool
    {
        $length = $this->length;
        if ($method === 'lastIndexOf') {
            $k = $from === JsUndefined::Value ? $length - 1 : Js::toInteger(Js::toNumber($from));
            $k = $k < 0 ? $length + $k : min($k, $length - 1);
            if (!is_int($value) && !is_float($value)) { return -1; }
            for ($i = (int) $k; $i >= 0; $i--) { if ($this->at($i) == $value) { return $i; } }
            return -1;
        }
        $k = Js::toInteger(Js::toNumber($from === JsUndefined::Value ? 0 : $from));
        $k = $k < 0 ? max($length + $k, 0) : $k;
        $nan = $method === 'includes' && is_float($value) && is_nan($value);
        if (!is_int($value) && !is_float($value)) { return $method === 'includes' ? false : -1; }
        if (($this->kind === 'Uint8Array' || $this->kind === 'Uint8ClampedArray') && !$nan) {
            if (!is_int($value) && !(is_float($value) && floor($value) === $value) || $value < 0 || $value > 255 || $k >= $length) {
                return $method === 'includes' ? false : -1;
            }
            $hit = strpos($this->buffer->bytes, chr((int) $value), $this->offset + (int) $k);
            $found = $hit !== false && $hit < $this->offset + $length ? $hit - $this->offset : -1;
            return $method === 'includes' ? $found >= 0 : $found;
        }
        for ($i = (int) $k; $i < $length; $i++) {
            $item = $this->at($i);
            if ($nan ? is_float($item) && is_nan($item) : $item == $value) { return $method === 'includes' ? true : $i; }
        }
        return $method === 'includes' ? false : -1;
    }

    public function call(string $name, array $a): mixed
    {
        if ($name === 'valueOf') { return $this; }
        $get = static fn (int $i): mixed => array_key_exists($i, $a) ? $a[$i] : JsUndefined::Value;
        $callback = static function (mixed $f): \Closure {
            if (!$f instanceof \Closure) { throw new JsError('Callback is not a function', 'TypeError'); }
            return $f;
        };
        if (in_array($name, ['forEach', 'map', 'filter', 'some', 'every', 'find', 'findIndex', 'findLast', 'findLastIndex', 'reduce'], true) && $name !== 'reduce' && $get(1) !== JsUndefined::Value) {
            throw new JsError('Callback thisArg is not supported', 'TypeError');
        }
        switch ($name) {
            case 'subarray': return $this->subarray($get(0), $get(1));
            case 'slice': return $this->slice($get(0), $get(1));
            case 'set': $this->setFrom($get(0), $get(1)); return JsUndefined::Value;
            case 'fill': return $this->fill($get(0), $get(1), $get(2));
            case 'indexOf': case 'lastIndexOf': case 'includes': return $this->search($name, $get(0), count($a) < 2 && $name === 'lastIndexOf' ? JsUndefined::Value : $get(1));
            case 'at':
                $i = Js::toInteger(Js::toNumber($get(0)));
                $i = $i < 0 ? $this->length + $i : $i;
                return $i >= 0 && $i < $this->length ? $this->at((int) $i) : JsUndefined::Value;
            case 'join':
                $separator = $get(0) === JsUndefined::Value ? ',' : Js::str($get(0));
                return implode($separator, array_map(Js::numStr(...), $this->values()));
            case 'toString': return implode(',', array_map(Js::numStr(...), $this->values()));
            case 'keys': return new JsArray(array_keys($this->values()));
            case 'values': return new JsArray($this->values());
            case 'entries': return new JsArray(array_map(static fn ($i, $v) => new JsArray([$i, $v]), array_keys($this->values()), $this->values()));
            case 'reverse':
                $values = array_reverse($this->values());
                foreach ($values as $i => $v) { $this->put($i, $v); }
                return $this;
            case 'forEach':
                $f = $callback($get(0));
                for ($i = 0; $i < $this->length; $i++) { $f($this->at($i), $i, $this); }
                return JsUndefined::Value;
            case 'map':
                $f = $callback($get(0));
                $out = self::allocate($this->kind, $this->length);
                for ($i = 0; $i < $this->length; $i++) { $out->put($i, Js::toNumber($f($this->at($i), $i, $this))); }
                return $out;
            case 'filter':
                $f = $callback($get(0)); $kept = [];
                for ($i = 0; $i < $this->length; $i++) { $v = $this->at($i); if (Js::truthy($f($v, $i, $this))) { $kept[] = $v; } }
                return self::of($this->kind, $kept);
            case 'some': case 'every':
                $f = $callback($get(0));
                for ($i = 0; $i < $this->length; $i++) {
                    $hit = Js::truthy($f($this->at($i), $i, $this));
                    if ($name === 'some' && $hit) { return true; }
                    if ($name === 'every' && !$hit) { return false; }
                }
                return $name === 'every';
            case 'find': case 'findIndex': case 'findLast': case 'findLastIndex':
                $f = $callback($get(0));
                $last = str_starts_with($name, 'findLast');
                for ($n = 0; $n < $this->length; $n++) {
                    $i = $last ? $this->length - 1 - $n : $n;
                    $v = $this->at($i);
                    if (Js::truthy($f($v, $i, $this))) { return str_ends_with($name, 'Index') ? $i : $v; }
                }
                return str_ends_with($name, 'Index') ? -1 : JsUndefined::Value;
            case 'reduce':
                $f = $callback($get(0)); $i = 0;
                if (count($a) < 2) {
                    if ($this->length === 0) { throw new JsError('Reduce of empty array with no initial value', 'TypeError'); }
                    $acc = $this->at(0); $i = 1;
                } else { $acc = $a[1]; }
                for (; $i < $this->length; $i++) { $acc = $f($acc, $this->at($i), $i, $this); }
                return $acc;
        }
        throw new JsError($name . ' is not a function', 'TypeError');
    }
}
