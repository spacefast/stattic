<?php
declare(strict_types=1);
namespace TypedPress;

/** A JavaScript DataView over a shared JsArrayBuffer. BigInt accessors are refused. */
final class JsDataView
{
    private const TYPES = [
        'Int8' => 'Int8Array', 'Uint8' => 'Uint8Array', 'Int16' => 'Int16Array', 'Uint16' => 'Uint16Array',
        'Int32' => 'Int32Array', 'Uint32' => 'Uint32Array', 'Float32' => 'Float32Array', 'Float64' => 'Float64Array',
    ];

    public function __construct(public readonly JsArrayBuffer $buffer, public readonly int $offset, public readonly int $length) {}

    public static function construct(array $a): self
    {
        $buffer = $a[0] ?? JsUndefined::Value;
        if (!$buffer instanceof JsArrayBuffer) { throw new JsError('First argument to DataView constructor must be an ArrayBuffer', 'TypeError'); }
        $offset = JsArrayBuffer::byteCount($a[1] ?? JsUndefined::Value);
        $size = $buffer->byteLength();
        if ($offset > $size) { throw new JsError('Start offset ' . $offset . ' is outside the bounds of the buffer', 'RangeError'); }
        $length = ($a[2] ?? JsUndefined::Value) === JsUndefined::Value ? $size - $offset : JsArrayBuffer::byteCount($a[2]);
        if ($offset + $length > $size) { throw new JsError('Invalid DataView length ' . $length, 'RangeError'); }
        return new self($buffer, $offset, $length);
    }

    public function bytes(): string { return substr($this->buffer->bytes, $this->offset, $this->length); }

    public function get(string $key): mixed
    {
        return match ($key) {
            'buffer' => $this->buffer, 'byteLength' => $this->length, 'byteOffset' => $this->offset,
            default => null,
        };
    }

    public static function method(string $name): bool
    {
        return (bool) preg_match('/^(?:get|set)(?:Int8|Uint8|Int16|Uint16|Int32|Uint32|Float32|Float64)$/D', $name);
    }

    public function call(string $name, array $a): mixed
    {
        $kind = self::TYPES[substr($name, 3)] ?? null;
        if ($kind === null || !self::method($name)) {
            if ($name === 'toString' || $name === 'valueOf') { return JsBinary::call($this, $name, $a); }
            if (preg_match('/^(get|set)Big(?:Int|Uint)64$/D', $name)) { throw new JsError('BigInt DataView accessors are not supported', 'TypeError'); }
            throw new JsError($name . ' is not a function', 'TypeError');
        }
        $size = JsTypedArray::KINDS[$kind][0];
        $index = is_int($a[0] ?? null) && $a[0] >= 0 ? $a[0] : JsArrayBuffer::byteCount($a[0] ?? JsUndefined::Value);
        $write = str_starts_with($name, 'set');
        $number = $write ? Js::toNumber($a[1] ?? JsUndefined::Value) : 0;
        $little = Js::truthy($a[$write ? 2 : 1] ?? JsUndefined::Value);
        if ($index + $size > $this->length) { throw new JsError('Offset is outside the bounds of the DataView', 'RangeError'); }
        $at = $this->offset + $index;
        if ($write) {
            $bytes = JsTypedArray::encode($kind, $number);
            $this->buffer->bytes = substr_replace($this->buffer->bytes, $little ? $bytes : strrev($bytes), $at, $size);
            return JsUndefined::Value;
        }
        if ($kind === 'Uint32Array' && $little) { return unpack('V', $this->buffer->bytes, $at)[1]; }
        if ($kind === 'Uint8Array') { return ord($this->buffer->bytes[$at]); }
        $bytes = substr($this->buffer->bytes, $at, $size);
        return JsTypedArray::decode($kind, $little ? $bytes : strrev($bytes));
    }
}
