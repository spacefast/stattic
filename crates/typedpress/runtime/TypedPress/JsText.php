<?php
declare(strict_types=1);
namespace TypedPress;

/**
 * WHATWG Encoding: TextEncoder and TextDecoder, UTF-8 only. One class models both objects; $decoder
 * tells them apart. TypedPress strings are valid UTF-8 already (lone surrogates are refused before they
 * exist), so encoding returns the stored bytes.
 */
final class JsText
{
    public const LABELS = ['utf-8', 'utf8', 'unicode-1-1-utf-8', 'unicode11utf8', 'unicode20utf8', 'x-unicode20utf8'];

    private function __construct(public readonly bool $decoder, public readonly bool $fatal = false, public readonly bool $ignoreBOM = false) {}

    public static function encoder(array $a): self { return new self(false); }

    public static function decoder(array $a): self
    {
        $label = array_key_exists(0, $a) && $a[0] !== JsUndefined::Value ? Js::str($a[0]) : 'utf-8';
        $label = strtolower(trim($label, "\t\n\f\r "));
        if (!in_array($label, self::LABELS, true)) {
            throw new JsError('The "' . $label . '" encoding is not supported by compiled code; only UTF-8 is', 'TypeError');
        }
        $options = $a[1] ?? JsUndefined::Value;
        $read = static fn (string $key): bool => !Js::isNullish($options) && Js::truthy(Js::dynamicGet($options, $key));
        return new self(true, $read('fatal'), $read('ignoreBOM'));
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'encoding' => 'utf-8',
            'fatal' => $this->decoder ? $this->fatal : null,
            'ignoreBOM' => $this->decoder ? $this->ignoreBOM : null,
            default => null,
        };
    }

    public function call(string $name, array $a): mixed
    {
        $get = static fn (int $i): mixed => array_key_exists($i, $a) ? $a[$i] : JsUndefined::Value;
        if (!$this->decoder && $name === 'encode') {
            $bytes = $get(0) === JsUndefined::Value ? '' : Js::str($get(0));
            return JsTypedArray::fromBytes($bytes);
        }
        if (!$this->decoder && $name === 'encodeInto') { return self::encodeInto(Js::str($get(0)), $get(1)); }
        if ($this->decoder && $name === 'decode') {
            $options = $get(1);
            if (!Js::isNullish($options) && Js::truthy(Js::dynamicGet($options, 'stream'))) {
                throw new JsError('Streaming TextDecoder.decode is not supported', 'TypeError');
            }
            $input = $get(0);
            if ($input === JsUndefined::Value) { return ''; }
            return self::decode(self::source($input, 'TextDecoder.decode'), $this->fatal, $this->ignoreBOM);
        }
        throw new JsError($name . ' is not a function', 'TypeError');
    }

    /** The bytes of a BufferSource. */
    public static function source(mixed $input, string $what): string
    {
        return match (true) {
            $input instanceof JsArrayBuffer => $input->bytes,
            $input instanceof JsTypedArray, $input instanceof JsDataView => $input->bytes(),
            default => throw new JsError($what . ' needs an ArrayBuffer or an ArrayBuffer view', 'TypeError'),
        };
    }

    private static function encodeInto(string $source, mixed $destination): JsObject
    {
        if (!$destination instanceof JsTypedArray || $destination->kind !== 'Uint8Array') {
            throw new JsError('encodeInto needs a Uint8Array destination', 'TypeError');
        }
        $room = $destination->length; $read = 0; $written = 0; $length = strlen($source);
        while ($written < $length) {
            $lead = ord($source[$written]);
            $size = $lead < 0x80 ? 1 : ($lead < 0xE0 ? 2 : ($lead < 0xF0 ? 3 : 4));
            if ($written + $size > $room) { break; }
            $written += $size;
            $read += $size === 4 ? 2 : 1;
        }
        $destination->buffer->bytes = substr_replace($destination->buffer->bytes, substr($source, 0, $written), $destination->offset, $written);
        return new JsObject(['read' => $read, 'written' => $written]);
    }

    /**
     * The WHATWG UTF-8 decoder. Each maximal subpart of an ill-formed sequence becomes one U+FFFD;
     * mb_scrub and mb_convert_encoding replace different spans, so only valid input takes the
     * mbstring fast path.
     */
    public static function decode(string $bytes, bool $fatal = false, bool $ignoreBOM = false): string
    {
        if (!$ignoreBOM && str_starts_with($bytes, "\xEF\xBB\xBF")) { $bytes = substr($bytes, 3); }
        if (mb_check_encoding($bytes, 'UTF-8')) { return $bytes; }
        if ($fatal) { throw new JsError('The encoded data was not valid for encoding utf-8', 'TypeError'); }
        $out = ''; $length = strlen($bytes); $i = 0;
        while ($i < $length) {
            $lead = ord($bytes[$i]);
            if ($lead < 0x80) { $out .= $bytes[$i++]; continue; }
            [$needed, $lower, $upper] = match (true) {
                $lead >= 0xC2 && $lead <= 0xDF => [1, 0x80, 0xBF],
                $lead === 0xE0 => [2, 0xA0, 0xBF], $lead === 0xED => [2, 0x80, 0x9F],
                $lead >= 0xE1 && $lead <= 0xEF => [2, 0x80, 0xBF],
                $lead === 0xF0 => [3, 0x90, 0xBF], $lead === 0xF4 => [3, 0x80, 0x8F],
                $lead >= 0xF1 && $lead <= 0xF3 => [3, 0x80, 0xBF],
                default => [0, 0, 0],
            };
            $start = $i++;
            $seen = 0;
            while ($seen < $needed && $i < $length) {
                $byte = ord($bytes[$i]);
                if ($byte < $lower || $byte > $upper) { break; }
                $lower = 0x80; $upper = 0xBF; $seen++; $i++;
            }
            $out .= $needed > 0 && $seen === $needed ? substr($bytes, $start, $needed + 1) : "\u{FFFD}";
        }
        return $out;
    }
}
