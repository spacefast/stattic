<?php

declare(strict_types=1);

namespace Zero;

/**
 * A JavaScript BigInt: an immutable arbitrary-precision integer. Pure PHP (neither GMP nor bcmath
 * is part of the platform contract): sign plus magnitude, stored as little-endian base-10^7 limbs,
 * so every limb product fits a 64-bit int. Operations follow ECMAScript 6.1.6.2: division
 * truncates toward zero, the remainder takes the dividend's sign, and bitwise operators act on
 * the infinite two's-complement representation.
 */
final class JsBigInt implements \Stringable
{
    private const BASE = 10_000_000;
    private const DIGITS = 7;

    /** @param list<int> $limbs little-endian, no trailing zero limbs; empty means zero */
    private function __construct(public readonly bool $negative, private readonly array $limbs) {}

    /** A compiled literal: decimal digits, cached so a literal is one value per request. */
    public static function literal(string $digits): self
    {
        static $cache = [];
        return $cache[$digits] ??= self::fromDecimal($digits);
    }

    /** `BigInt(value)` (ECMAScript 21.2.1.1): numbers must be integral, strings parse, objects use their primitive. */
    public static function of(mixed $value): self
    {
        $value = Js::primitive($value, 'number');
        return match (true) {
            $value instanceof self => $value,
            is_bool($value) => self::fromInt((int) $value),
            // A PHP int is a JS number: past ±2^53 it is the nearest double first, as JS sees it.
            is_int($value), is_float($value) => self::fromNumber(Js::n($value)),
            is_string($value) => self::parse($value) ?? throw new JsError('Cannot convert ' . $value . ' to a BigInt', 'SyntaxError'),
            default => throw new JsError('Cannot convert ' . Js::str($value) . ' to a BigInt', 'TypeError'),
        };
    }

    /** `BigInt.asIntN(bits, value)` / `BigInt.asUintN(bits, value)`: bits through ToIndex, value must be a BigInt. */
    public static function asIntN(mixed $bits, mixed $value, bool $signed): self
    {
        $n = Js::toInteger(Js::toNumber($bits));
        if ($n < 0 || $n > 9007199254740991) { throw new JsError('Invalid value: not (convertible to) a safe integer', 'RangeError'); }
        $value = Js::primitive($value, 'number');
        if (!$value instanceof self) {
            if (is_bool($value)) { $value = self::fromInt((int) $value); }
            elseif (is_string($value)) { $value = self::parse($value) ?? throw new JsError('Cannot convert ' . $value . ' to a BigInt', 'SyntaxError'); }
            else { throw new JsError('Cannot convert ' . Js::str($value) . ' to a BigInt', 'TypeError'); }
        }
        if ($n > 1_000_000) { throw new JsError('Maximum BigInt size exceeded', 'RangeError'); }
        return $value->asN((int) $n, $signed);
    }

    public static function zero(): self
    {
        return new self(false, []);
    }

    /** @param list<int> $limbs */
    private static function make(bool $negative, array $limbs): self
    {
        while ($limbs !== [] && $limbs[count($limbs) - 1] === 0) { array_pop($limbs); }
        return new self($limbs !== [] && $negative, $limbs);
    }

    public static function fromInt(int $n): self
    {
        if ($n === PHP_INT_MIN) { return self::fromDecimal('-9223372036854775808'); }
        $negative = $n < 0;
        $n = abs($n);
        $limbs = [];
        while ($n > 0) { $limbs[] = $n % self::BASE; $n = intdiv($n, self::BASE); }
        return self::make($negative, $limbs);
    }

    /** An optional sign and decimal digits, no other characters. */
    public static function fromDecimal(string $digits): self
    {
        $negative = $digits !== '' && $digits[0] === '-';
        $digits = ltrim($digits, '+-');
        $limbs = [];
        for ($end = strlen($digits); $end > 0; $end -= self::DIGITS) {
            $start = max(0, $end - self::DIGITS);
            $limbs[] = (int) substr($digits, $start, $end - $start);
        }
        return self::make($negative, $limbs);
    }

    /** A literal's digits in base 2, 8, 10 or 16, without its prefix or `n` suffix. */
    public static function fromRadix(string $digits, int $radix): self
    {
        $digits = str_replace('_', '', $digits);
        if ($radix === 10) { return self::fromDecimal($digits); }
        $value = self::zero();
        $base = self::fromInt($radix);
        foreach (str_split(strtolower($digits)) as $digit) {
            $value = $value->mul($base)->add(self::fromInt((int) hexdec($digit)));
        }
        return $value;
    }

    /** ECMAScript StringToBigInt: trimmed decimal or 0x/0o/0b integer text; null when invalid. */
    public static function parse(string $text): ?self
    {
        $text = Js::trim($text);
        if ($text === '') { return self::zero(); }
        if (preg_match('/^0([xXoObB])([0-9a-fA-F]+)$/D', $text, $m)) {
            $radix = ['x' => 16, 'o' => 8, 'b' => 2][strtolower($m[1])];
            $valid = ['x' => '/^[0-9a-f]+$/iD', 'o' => '/^[0-7]+$/D', 'b' => '/^[01]+$/D'][strtolower($m[1])];
            return preg_match($valid, $m[2]) ? self::fromRadix($m[2], $radix) : null;
        }
        return preg_match('/^[+-]?[0-9]+$/D', $text) ? self::fromDecimal($text) : null;
    }

    /** NumberToBigInt: integral finite numbers only. */
    public static function fromNumber(int|float $n): self
    {
        if (is_int($n)) { return self::fromInt($n); }
        if (!is_finite($n) || floor($n) !== $n) {
            throw new JsError('The number ' . Js::numStr($n) . ' cannot be converted to a BigInt because it is not an integer', 'RangeError');
        }
        if (abs($n) < 9.2e18) { return self::fromInt((int) $n); }
        // Exact: a double's integer part is its mantissa times a power of two.
        $exponent = (int) floor(log(abs($n), 2)) - 52;
        $mantissa = (int) round(abs($n) / (2 ** $exponent));
        $value = self::fromInt($mantissa)->mul(self::fromInt(2)->pow(self::fromInt($exponent)));
        return $n < 0 ? $value->neg() : $value;
    }

    public function isZero(): bool
    {
        return $this->limbs === [];
    }

    public function __toString(): string
    {
        if ($this->limbs === []) { return '0'; }
        $out = (string) $this->limbs[count($this->limbs) - 1];
        for ($i = count($this->limbs) - 2; $i >= 0; $i--) {
            $out .= str_pad((string) $this->limbs[$i], self::DIGITS, '0', STR_PAD_LEFT);
        }
        return ($this->negative ? '-' : '') . $out;
    }

    /** `b.toString(radix)`: an undefined radix is 10; others go through ToIntegerOrInfinity. */
    public function toRadixArg(mixed $radix): string
    {
        if ($radix === JsUndefined::Value) { return (string) $this; }
        $n = Js::toInteger(Js::toNumber($radix));
        if ($n < 2 || $n > 36) { throw new JsError('toString() radix must be between 2 and 36', 'RangeError'); }
        return $this->toRadix((int) $n);
    }

    /** `toString(radix)` for radix 2..36. */
    public function toRadix(int $radix): string
    {
        if ($radix < 2 || $radix > 36) { throw new JsError('toString() radix must be between 2 and 36', 'RangeError'); }
        if ($radix === 10) { return (string) $this; }
        if ($this->limbs === []) { return '0'; }
        $digits = '';
        $value = $this->abs();
        $base = self::fromInt($radix);
        while (!$value->isZero()) {
            [$value, $digit] = $value->divRem($base);
            $digits = '0123456789abcdefghijklmnopqrstuvwxyz'[$digit->toInt()] . $digits;
        }
        return ($this->negative ? '-' : '') . $digits;
    }

    /** The nearest double, ties to even, as Number(bigint). */
    public function toNumber(): int|float
    {
        $text = (string) $this;
        $float = (float) $text;
        return abs($float) <= 9007199254740992 ? (int) $float : $float;
    }

    /** Only for values known to fit a PHP int. */
    private function toInt(): int
    {
        $n = 0;
        for ($i = count($this->limbs) - 1; $i >= 0; $i--) { $n = $n * self::BASE + $this->limbs[$i]; }
        return $this->negative ? -$n : $n;
    }

    private function fitsInt(): bool
    {
        return count($this->limbs) <= 2;
    }

    public function compare(self $other): int
    {
        if ($this->negative !== $other->negative) { return $this->negative ? -1 : 1; }
        $c = self::compareMagnitude($this->limbs, $other->limbs);
        return $this->negative ? -$c : $c;
    }

    /** @param list<int> $a @param list<int> $b */
    private static function compareMagnitude(array $a, array $b): int
    {
        if (count($a) !== count($b)) { return count($a) <=> count($b); }
        for ($i = count($a) - 1; $i >= 0; $i--) {
            if ($a[$i] !== $b[$i]) { return $a[$i] <=> $b[$i]; }
        }
        return 0;
    }

    public function equals(self $other): bool
    {
        return $this->negative === $other->negative && $this->limbs === $other->limbs;
    }

    public function neg(): self
    {
        return self::make(!$this->negative, $this->limbs);
    }

    public function abs(): self
    {
        return new self(false, $this->limbs);
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    private static function addMagnitude(array $a, array $b): array
    {
        $out = [];
        $carry = 0;
        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            $sum = ($a[$i] ?? 0) + ($b[$i] ?? 0) + $carry;
            $out[] = $sum % self::BASE;
            $carry = intdiv($sum, self::BASE);
        }
        if ($carry > 0) { $out[] = $carry; }
        return $out;
    }

    /** |a| >= |b| @param list<int> $a @param list<int> $b @return list<int> */
    private static function subMagnitude(array $a, array $b): array
    {
        $out = [];
        $borrow = 0;
        for ($i = 0, $n = count($a); $i < $n; $i++) {
            $diff = $a[$i] - ($b[$i] ?? 0) - $borrow;
            $borrow = $diff < 0 ? 1 : 0;
            $out[] = $diff + $borrow * self::BASE;
        }
        return $out;
    }

    public function add(self $other): self
    {
        if ($this->negative === $other->negative) {
            return self::make($this->negative, self::addMagnitude($this->limbs, $other->limbs));
        }
        $c = self::compareMagnitude($this->limbs, $other->limbs);
        if ($c === 0) { return self::zero(); }
        return $c > 0
            ? self::make($this->negative, self::subMagnitude($this->limbs, $other->limbs))
            : self::make($other->negative, self::subMagnitude($other->limbs, $this->limbs));
    }

    public function sub(self $other): self
    {
        return $this->add($other->neg());
    }

    public function mul(self $other): self
    {
        if ($this->limbs === [] || $other->limbs === []) { return self::zero(); }
        $out = array_fill(0, count($this->limbs) + count($other->limbs), 0);
        foreach ($this->limbs as $i => $a) {
            $carry = 0;
            foreach ($other->limbs as $j => $b) {
                $t = $out[$i + $j] + $a * $b + $carry;
                $out[$i + $j] = $t % self::BASE;
                $carry = intdiv($t, self::BASE);
            }
            for ($k = $i + count($other->limbs); $carry > 0; $k++) {
                $t = $out[$k] + $carry;
                $out[$k] = $t % self::BASE;
                $carry = intdiv($t, self::BASE);
            }
        }
        return self::make($this->negative !== $other->negative, $out);
    }

    /** Truncated division and remainder (ECMAScript BigInt::divide / ::remainder). @return array{self, self} */
    public function divRem(self $other): array
    {
        if ($other->limbs === []) { throw new JsError('Division by zero', 'RangeError'); }
        if (self::compareMagnitude($this->limbs, $other->limbs) < 0) { return [self::zero(), $this]; }
        if (count($other->limbs) === 1) {
            $d = $other->limbs[0];
            $quotient = [];
            $rem = 0;
            for ($i = count($this->limbs) - 1; $i >= 0; $i--) {
                $cur = $rem * self::BASE + $this->limbs[$i];
                $quotient[$i] = intdiv($cur, $d);
                $rem = $cur % $d;
            }
            ksort($quotient);
            return [
                self::make($this->negative !== $other->negative, array_values($quotient)),
                self::make($this->negative, $rem === 0 ? [] : [$rem]),
            ];
        }
        // Schoolbook long division, one base-10^7 digit at a time, with a binary search per digit.
        $divisor = $other->abs();
        $remainder = self::zero();
        $quotient = [];
        for ($i = count($this->limbs) - 1; $i >= 0; $i--) {
            $remainder = self::make(false, array_merge([$this->limbs[$i]], $remainder->limbs));
            $low = 0;
            $high = self::BASE - 1;
            while ($low < $high) {
                $mid = intdiv($low + $high + 1, 2);
                if ($divisor->mul(self::fromInt($mid))->compare($remainder) <= 0) { $low = $mid; } else { $high = $mid - 1; }
            }
            $quotient[$i] = $low;
            if ($low > 0) { $remainder = $remainder->sub($divisor->mul(self::fromInt($low))); }
        }
        ksort($quotient);
        return [
            self::make($this->negative !== $other->negative, array_values($quotient)),
            self::make($this->negative, $remainder->limbs),
        ];
    }

    public function pow(self $exponent): self
    {
        // V8's exact message.
        if ($exponent->negative) { throw new JsError('undefined must be positive', 'RangeError'); }
        $result = self::fromInt(1);
        $base = $this;
        $e = $exponent;
        $two = self::fromInt(2);
        while (!$e->isZero()) {
            [$e, $bit] = $e->divRem($two);
            if (!$bit->isZero()) { $result = $result->mul($base); }
            if (!$e->isZero()) { $base = $base->mul($base); }
        }
        return $result;
    }

    /** The low `$bits` bits of the two's-complement value, as a non-negative BigInt. */
    private function modPow2(int $bits): self
    {
        $modulus = self::fromInt(2)->pow(self::fromInt($bits));
        [, $r] = $this->divRem($modulus);
        return $r->negative ? $r->add($modulus) : $r;
    }

    /** Bytes of the two's-complement value, little-endian, wide enough for both operands. @return list<int> */
    private function twosBytes(int $count): array
    {
        $value = $this->modPow2($count * 8);
        $bytes = [];
        $byte = self::fromInt(256);
        for ($i = 0; $i < $count; $i++) {
            [$value, $b] = $value->divRem($byte);
            $bytes[] = $b->toInt();
        }
        return $bytes;
    }

    /** @param list<int> $bytes */
    private static function fromTwosBytes(array $bytes): self
    {
        $negative = ($bytes[count($bytes) - 1] & 0x80) !== 0;
        $value = self::zero();
        $byte = self::fromInt(256);
        for ($i = count($bytes) - 1; $i >= 0; $i--) { $value = $value->mul($byte)->add(self::fromInt($bytes[$i])); }
        return $negative ? $value->sub(self::fromInt(2)->pow(self::fromInt(count($bytes) * 8))) : $value;
    }

    private function byteWidth(): int
    {
        // Enough bytes for the magnitude plus a sign bit.
        return intdiv(strlen((string) $this->abs()) * 10, 24) + 2;
    }

    public function bitwise(string $operator, self $other): self
    {
        $width = max($this->byteWidth(), $other->byteWidth());
        $a = $this->twosBytes($width);
        $b = $other->twosBytes($width);
        $out = [];
        for ($i = 0; $i < $width; $i++) {
            $out[] = match ($operator) { '&' => $a[$i] & $b[$i], '|' => $a[$i] | $b[$i], '^' => $a[$i] ^ $b[$i] };
        }
        return self::fromTwosBytes($out);
    }

    public function not(): self
    {
        return $this->neg()->sub(self::fromInt(1));
    }

    /** `<<` and `>>` (a negative count shifts the other way); `>>` floors, like JavaScript. */
    public function shift(self $count, bool $left): self
    {
        if ($count->negative) { return $this->shift($count->neg(), !$left); }
        if (!$count->fitsInt() || $count->toInt() > 1_000_000) {
            if ($left && !$this->isZero()) { throw new JsError('Maximum BigInt size exceeded', 'RangeError'); }
            return $left || !$this->negative ? self::zero() : self::fromInt(-1);
        }
        $factor = self::fromInt(2)->pow($count);
        if ($left) { return $this->mul($factor); }
        [$q, $r] = $this->divRem($factor);
        return $this->negative && !$r->isZero() ? $q->sub(self::fromInt(1)) : $q;
    }

    /** BigInt.asIntN / BigInt.asUintN. */
    public function asN(int $bits, bool $signed): self
    {
        if ($bits === 0) { return self::zero(); }
        $value = $this->modPow2($bits);
        if ($signed && $value->compare(self::fromInt(2)->pow(self::fromInt($bits - 1))) >= 0) {
            return $value->sub(self::fromInt(2)->pow(self::fromInt($bits)));
        }
        return $value;
    }
}
