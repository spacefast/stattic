<?php
declare(strict_types=1);
namespace SpacePress;

/**
 * A JavaScript Blob, or a File when $name is set. The bytes are immutable.
 *
 * text(), arrayBuffer() and bytes() return already-fulfilled promises. stream() is a ReadableStream
 * with one Uint8Array chunk per non-empty part, as Node 22 streams an in-memory Blob: nested Blobs
 * contribute their own parts, and slice() cuts the parts it spans. $parts holds those byte lengths.
 */
final class JsBlob
{
    /** @param list<int> $parts */
    public function __construct(public readonly string $bytes, public readonly string $type = '', public readonly ?string $name = null, public readonly int|float $lastModified = 0, public readonly array $parts = [])
    {
        assert(array_sum($parts) === strlen($bytes));
    }

    public static function construct(string $constructor, array $a): self
    {
        $file = $constructor === 'File';
        if ($file && count($a) < 2) { throw new JsError("Failed to construct 'File': 2 arguments required", 'TypeError'); }
        $parts = $a[0] ?? JsUndefined::Value;
        $options = $a[$file ? 2 : 1] ?? JsUndefined::Value;
        $bytes = '';
        if ($parts !== JsUndefined::Value) {
            if (!is_object($parts) || $parts instanceof JsUndefined) { throw new JsError('The provided value cannot be converted to a sequence', 'TypeError'); }
            $partList = Js::dynamicIterable($parts)->a;
        }
        $type = ''; $endings = 'transparent'; $lastModified = null;
        if (!Js::isNullish($options)) {
            if (!is_object($options)) { throw new JsError('The provided options are not an object', 'TypeError'); }
            $value = Js::dynamicGet($options, 'type');
            if ($value !== JsUndefined::Value) { $type = self::normalType(Js::str($value)); }
            $value = Js::dynamicGet($options, 'endings');
            if ($value !== JsUndefined::Value) { $endings = Js::str($value); }
            if ($file) {
                $value = Js::dynamicGet($options, 'lastModified');
                if ($value !== JsUndefined::Value) { $lastModified = Js::toNumber($value); }
            }
        }
        if ($endings !== 'transparent' && $endings !== 'native') { throw new JsError("The provided value '" . $endings . "' is not a valid enum value of type EndingType", 'TypeError'); }
        $parts = [];
        foreach ($partList ?? [] as $part) {
            if ($part instanceof self) { array_push($parts, ...$part->parts); $bytes .= $part->bytes; continue; }
            // Native endings rewrite string parts only, to this host's line separator, as Node does.
            $chunk = $endings === 'native' && is_string($part) ? preg_replace('/\r\n|\r|\n/', PHP_EOL, $part) : self::part($part);
            if ($chunk !== '') { $parts[] = strlen($chunk); $bytes .= $chunk; }
        }
        if (!$file) { return new self($bytes, $type, null, 0, $parts); }
        $lastModified ??= Js::now();
        $lastModified = is_float($lastModified) && !is_finite($lastModified) ? 0 : Js::toInteger($lastModified);
        return new self($bytes, $type, Js::str($a[1]), $lastModified, $parts);
    }

    /** The part lengths that cover bytes [$from, $to). */
    private function partsIn(int $from, int $to): array
    {
        $out = []; $start = 0;
        foreach ($this->parts as $length) {
            $end = $start + $length;
            $piece = min($end, $to) - max($start, $from);
            if ($piece > 0) { $out[] = $piece; }
            $start = $end;
        }
        return $out;
    }

    private static function part(mixed $part): string
    {
        return match (true) {
            $part instanceof JsArrayBuffer => $part->bytes,
            $part instanceof JsTypedArray, $part instanceof JsDataView => $part->bytes(),
            default => Js::str($part),
        };
    }

    /** The type is lowercased; any character outside U+0020..U+007E makes it empty. */
    private static function normalType(string $type): string
    {
        return preg_match('/^[\x20-\x7E]*$/D', $type) ? strtolower($type) : '';
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'size' => strlen($this->bytes), 'type' => $this->type,
            'name' => $this->name ?? null, 'lastModified' => $this->name === null ? null : $this->lastModified,
            'webkitRelativePath' => $this->name === null ? null : '',
            default => null,
        };
    }

    public function call(string $name, array $a): mixed
    {
        $get = static fn (int $i): mixed => array_key_exists($i, $a) ? $a[$i] : JsUndefined::Value;
        switch ($name) {
            case 'slice':
                [$from, $to] = JsTypedArray::range($get(0), $get(1), strlen($this->bytes));
                $type = $get(2) === JsUndefined::Value ? '' : self::normalType(Js::str($get(2)));
                return new self($to > $from ? substr($this->bytes, $from, $to - $from) : '', $type, null, 0, $this->partsIn($from, $to));
            case 'text': return JsPromise::from(JsText::decode($this->bytes));
            case 'arrayBuffer': return JsPromise::from(new JsArrayBuffer($this->bytes));
            case 'bytes': return JsPromise::from(JsTypedArray::fromBytes($this->bytes));
            case 'stream':
                $bytes = $this->bytes; $parts = $this->parts; $at = 0;
                // One chunk per pull, so a reader that cancels early never copies the rest.
                return new JsReadableStream(new JsObject([
                    'pull' => static function (mixed $controller) use ($bytes, &$parts, &$at): JsUndefined {
                        $length = array_shift($parts);
                        if ($length !== null) {
                            Js::dynamicCallMethod($controller, 'enqueue', new JsArray([JsTypedArray::fromBytes(substr($bytes, $at, $length))]));
                            $at += $length;
                        }
                        if ($parts === []) { Js::dynamicCallMethod($controller, 'close', new JsArray()); }
                        return JsUndefined::Value;
                    },
                ]), new JsObject(['highWaterMark' => 0]));
        }
        throw new JsError($name . ' is not a function', 'TypeError');
    }
}
