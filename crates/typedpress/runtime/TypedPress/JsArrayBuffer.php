<?php
declare(strict_types=1);
namespace TypedPress;

/**
 * A JavaScript ArrayBuffer: one PHP binary string. Views (JsTypedArray, JsDataView) hold this
 * object, so a write through one view is visible through every view of the same buffer.
 * Fixed length; resizable buffers, transfer and SharedArrayBuffer are refused.
 */
final class JsArrayBuffer
{
    /** Bytes beyond this are refused: PHP strings are bounded by memory_limit, not 2^53. */
    public const MAX = 2147483647;

    public function __construct(public string $bytes = '') {}

    public static function allocate(mixed $length): self
    {
        return new self(str_repeat("\0", self::byteCount($length)));
    }

    /** ToIndex for lengths and offsets, refusing what PHP cannot allocate. */
    public static function byteCount(mixed $value): int
    {
        if ($value === JsUndefined::Value) { return 0; }
        $n = Js::toInteger(Js::toNumber($value));
        if ($n < 0 || $n > 9007199254740991) { throw new JsError('Invalid array length', 'RangeError'); }
        if ($n > self::MAX) { throw new JsError('Array buffer allocation failed', 'RangeError'); }
        return (int) $n;
    }

    public function byteLength(): int { return strlen($this->bytes); }

    public function slice(mixed $start, mixed $end): self
    {
        $length = strlen($this->bytes);
        [$from, $to] = JsTypedArray::range($start, $end, $length);
        return new self($to > $from ? substr($this->bytes, $from, $to - $from) : '');
    }
}
