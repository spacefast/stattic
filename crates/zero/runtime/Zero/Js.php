<?php

declare(strict_types=1);

namespace Zero;

/**
 * JavaScript semantics in PHP. See docs/runtime-contract.md ("class Js").
 *
 * Strings use UTF-8 storage and UTF-16 indexes. Arrays and objects retain handle
 * identity; explicit undefined has a sentinel (typed missing reads may be null).
 */
final class Js
{
    use JsDynamic;
    /** JS WhiteSpace + LineTerminator, as a PCRE class body. */
    public const WS = '\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE;

    private const MAX_TIME = 8.64e15;

    /** @var array<string, RegExp> */
    private static array $regexCache = [];

    // ---------------------------------------------------------------- conversions

    public static function str(mixed $v): string
    {
        return match (true) {
            $v === JsUndefined::Value => 'undefined',
            is_string($v) => $v,
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v), is_float($v) => self::numStr($v),
            $v instanceof JsArray => self::join($v),
            $v instanceof JsObject => '[object Object]',
            $v instanceof JsArguments, $v instanceof JsSet, $v instanceof JsMap, $v instanceof JsNamespace => self::dynamicObjectTag($v),
            $v instanceof JsDate => throw new JsError('Date string coercion is not supported', 'TypeError'),
            $v instanceof JsError => $v->jsName . ($v->getMessage() === '' ? '' : ': ' . $v->getMessage()),
            $v instanceof RegExp => (string) $v,
            $v instanceof \Closure => 'function () { [native code] }',
            $v instanceof \Stringable => (string) $v,
            default => '[object Object]',
        };
    }

    /** URI table adapted from ts2php; see URI-ENCODING-NOTICE.txt. */
    public static function encodeURI(string $value): string
    {
        return strtr(self::encodeURIComponent($value), [
            '%3B' => ';', '%2C' => ',', '%2F' => '/', '%3F' => '?', '%3A' => ':',
            '%40' => '@', '%26' => '&', '%3D' => '=', '%2B' => '+', '%24' => '$', '%23' => '#',
        ]);
    }

    public static function encodeURIComponent(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new JsError('URI malformed', 'URIError');
        }

        // rawurlencode alone incorrectly escapes these JavaScript URI marks.
        return strtr(rawurlencode($value), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    public static function numStr(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if ($n == 0.0) {
            return '0';
        }
        $sign = $n < 0 ? '-' : '';
        [$digits, $point] = self::shortestDigits(abs($n));
        $k = strlen($digits);
        if ($k <= $point && $point <= 21) {
            return $sign . $digits . str_repeat('0', $point - $k);
        }
        if (0 < $point && $point <= 21) {
            return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        if (-6 < $point && $point <= 0) {
            return $sign . '0.' . str_repeat('0', -$point) . $digits;
        }
        $exp = $point - 1;
        $mantissa = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);

        return $sign . $mantissa . 'e' . ($exp < 0 ? '-' : '+') . abs($exp);
    }

    /**
     * Shortest round-tripping decimal digits of a positive finite float, as
     * [digits, point] meaning 0.digits × 10^point (JS's s, n with k = strlen(s)).
     *
     * @return array{string, int}
     */
    private static function shortestDigits(float $x): array
    {
        for ($p = 0; $p < 17; $p++) {
            $s = sprintf('%.' . $p . 'e', $x);
            if ((float) $s === $x) {
                break;
            }
        }
        [$mantissa, $exp] = explode('e', $s);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');

        return [$digits === '' ? '0' : $digits, (int) $exp + 1];
    }

    public static function toNumber(mixed $v): int|float
    {
        if ($v instanceof JsDate) { return $v->milliseconds; }
        if ($v instanceof JsObject) { return self::toNumber(self::dynamicPrimitive($v, 'number')); }
        if (is_int($v)) {
            return self::n($v);
        }
        if (is_float($v)) {
            return $v;
        }
        if ($v === null) {
            return 0;
        }
        if (is_bool($v)) {
            return (int) $v;
        }
        if ($v instanceof JsArray) {
            return self::toNumber(self::str($v));
        }
        if (!is_string($v)) {
            return NAN;
        }
        $t = self::trim($v);
        if ($t === '') {
            return 0;
        }
        if (preg_match('/^[+-]?(?:\d+\.?\d*(?:[eE][+-]?\d+)?|\.\d+(?:[eE][+-]?\d+)?)$/', $t)) {
            return self::num((float) $t);
        }
        if (preg_match('/^0([xXoObB])(.+)$/', $t, $m)) {
            $radix = ['x' => 16, 'o' => 8, 'b' => 2][strtolower($m[1])];
            [$value, $used] = self::parseDigits($m[2], $radix);

            return $used === strlen($m[2]) ? self::num((float) $value) : NAN;
        }

        return match ($t) {
            'Infinity', '+Infinity' => INF,
            '-Infinity' => -INF,
            default => NAN,
        };
    }

    public static function parseInt(string $s, int|float|null $radix = null): int|float
    {
        $s = self::trimStart($s);
        $sign = 1;
        if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) {
            $sign = $s[0] === '-' ? -1 : 1;
            $s = substr($s, 1);
        }
        $r = $radix === null ? 0 : self::toInt32($radix);
        $stripPrefix = true;
        if ($r !== 0) {
            if ($r < 2 || $r > 36) {
                return NAN;
            }
            $stripPrefix = $r === 16;
        } else {
            $r = 10;
        }
        if ($stripPrefix && preg_match('/^0[xX]/', $s)) {
            $s = substr($s, 2);
            $r = 16;
        }
        [$value, $used] = self::parseDigits($s, $r);
        if ($used === 0) {
            return NAN;
        }
        if ($r === 10 && $used > 15) {
            $value = (float) substr($s, 0, $used); // correctly rounded, like V8
        }
        if ($sign < 0) {
            return $value == 0 ? -0.0 : self::num(-(float) $value);
        }

        return self::num((float) $value);
    }

    public static function parseFloat(string $s): int|float
    {
        $s = self::trimStart($s);
        if (!preg_match('/^[+-]?(?:Infinity|(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/', $s, $m)) {
            return NAN;
        }
        $t = $m[0];
        if (str_ends_with($t, 'Infinity')) {
            return $t[0] === '-' ? -INF : INF;
        }

        return self::num((float) $t);
    }

    /**
     * Longest prefix of $s made of digits valid in $radix.
     *
     * @return array{int|float, int} [value, number of bytes consumed]
     */
    private static function parseDigits(string $s, int $radix): array
    {
        $value = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $d = match (true) {
                $c >= 48 && $c <= 57 => $c - 48,
                $c >= 97 && $c <= 122 => $c - 87,
                $c >= 65 && $c <= 90 => $c - 55,
                default => 99,
            };
            if ($d >= $radix) {
                break;
            }
            $value = $value * $radix + $d; // int overflows to float on its own
        }

        return [$value, $i];
    }

    /** Integral finite floats within int range become ints (except -0). */
    /**
     * A float result as Zero's number: an int when it is a safe integer (|n| <= 2^53, not -0), else
     * the float. Ints beyond 2^53 would do exact arithmetic JS numbers can't (see n()).
     */
    private static function num(float $f): int|float
    {
        if (is_finite($f) && floor($f) === $f && abs($f) <= self::MAX_EXACT && !($f === 0.0 && fdiv(1, $f) < 0)) {
            return (int) $f;
        }

        return $f;
    }

    private const MAX_EXACT = 9007199254740992.0; // 2^53

    /**
     * An integer arithmetic result as a JS number: PHP ints are exact to 2^63, JS numbers are doubles,
     * so an int beyond ±2^53 rounds to the nearest double, as `2 ** 53 + 1` does in JS. Compiled code
     * wraps `+ - * **` and `++`/`--` in this.
     */
    public static function n(int|float $r): int|float
    {
        return is_int($r) && ($r > 9007199254740992 || $r < -9007199254740992) ? (float) $r : $r;
    }

    /**
     * Compiled `a + b`, `a - b`, `a * b` and unary `-a` on numbers: each operand as the JS number it
     * is (an exact int past ±2^53, read from storage or input, as the nearest double), then the
     * result through n(). So arithmetic matches JS on every operand, while the operands themselves,
     * and any id compiled code passes on, keep their exact stored value.
     */
    public static function add(int|float $a, int|float $b): int|float
    {
        return self::n(self::n($a) + self::n($b));
    }

    public static function sub(int|float $a, int|float $b): int|float
    {
        return self::n(self::n($a) - self::n($b));
    }

    public static function mul(int|float $a, int|float $b): int|float
    {
        return self::n(self::n($a) * self::n($b));
    }

    public static function neg(int|float $a): int|float
    {
        return -self::n($a);
    }

    /** Postfix `x++` / `x--` (`$step` 1 or -1): steps the place like add() and returns the old value. */
    public static function postStep(int|float &$x, int $step): int|float
    {
        $old = self::n($x);
        $x = self::add($old, $step);

        return $old;
    }

    /** Prefix `++x` / `--x`: the place is evaluated once (an index with side effects runs once). */
    public static function preStep(int|float &$x, int $step): int|float
    {
        return $x = self::add($x, $step);
    }

    /**
     * `a ** b` / `Math.pow`. An integer power is computed exactly, then rounded to the nearest double
     * past ±2^53 (n()), so every result a double can hold exactly (2 ** 60) matches JS. ECMA-262 leaves
     * inexact powers implementation-approximated: engines (and V8 vs this runtime) may differ in the last
     * binary digit there, e.g. 3 ** 35.
     */
    public static function pow(int|float $a, int|float $b): int|float
    {
        // ECMA-262 Number::exponentiate, where PHP's pow differs: x ** NaN is NaN (PHP: 1 ** NaN = 1) and
        // (±1) ** ±Infinity is NaN (PHP: 1).
        if (is_nan((float) $b) || (is_infinite((float) $b) && abs($a) == 1)) {
            return NAN;
        }
        [$a, $b] = [self::n($a), self::n($b)]; // operands as JS numbers, like add()
        if (is_int($a) && is_int($b) && $b >= 0) {
            $p = $a ** $b;
            if (is_int($p)) {
                return self::n($p);
            }
        }

        return self::num((float) $a ** (float) $b);
    }

    private static function jsNumbers(mixed $v): mixed
    {
        if (is_int($v)) {
            return self::n($v);
        }
        if ($v instanceof JsObject) {
            foreach ($v->p as $k => $x) { $v->p[$k] = self::jsNumbers($x); }
        } elseif ($v instanceof JsArray) {
            foreach ($v->a as $k => $x) { $v->a[$k] = self::jsNumbers($x); }
        } elseif (is_array($v)) {
            foreach ($v as $k => $x) {
                if (is_int($x) || is_array($x)) {
                    $v[$k] = self::jsNumbers($x);
                }
            }
        }

        return $v;
    }

    /** Truncate toward zero, keeping float type (and -0). */
    private static function trunc(float $f): float
    {
        return $f < 0 ? ceil($f) : floor($f);
    }

    private static function toInt32(int|float $n): int
    {
        if (is_float($n)) {
            if (!is_finite($n)) {
                return 0;
            }
            $n = fmod(self::trunc($n), 4294967296.0);
            $n = (int) $n;
        }
        $n &= 0xFFFFFFFF;

        return $n >= 0x80000000 ? $n - 0x100000000 : $n;
    }

    private static function toUint32(int|float $n): int
    {
        return self::toInt32($n) & 0xFFFFFFFF;
    }

    /** ToIntegerOrInfinity: NaN → 0, truncation toward zero. */
    private static function toInteger(int|float $n): int|float
    {
        if (is_int($n)) {
            return $n;
        }
        if (is_nan($n)) {
            return 0;
        }
        if (is_infinite($n)) {
            return $n;
        }

        return self::num(self::trunc($n)) + 0;
    }

    /** Resolve a relative index (slice/at semantics) against a length. */
    private static function relIndex(int|float $i, int $len): int
    {
        $i = self::toInteger($i);
        if ($i < 0) {
            return (int) max($len + $i, 0);
        }

        return (int) min($i, $len);
    }

    private static function clampIndex(int|float $i, int $len): int
    {
        return (int) min(max(self::toInteger($i), 0), $len);
    }

    public static function truthy(mixed $v): bool
    {
        return match (true) {
            $v === JsUndefined::Value, $v === null, $v === false, $v === '', $v === 0 => false,
            is_float($v) => !($v == 0.0 || is_nan($v)),
            default => true,
        };
    }

    public static function typeOf(mixed $v): string
    {
        return match (true) {
            $v === JsUndefined::Value => 'undefined',
            is_string($v) => 'string',
            is_int($v), is_float($v) => 'number',
            is_bool($v) => 'boolean',
            $v instanceof \Closure, $v instanceof JsClass => 'function',
            default => 'object',
        };
    }

    public static function strictEq(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return is_int($a) && is_int($b) ? $a === $b : (float) $a == (float) $b;
        }

        return $a === $b;
    }

    private static function sameValueZero(mixed $a, mixed $b): bool
    {
        if (is_float($a) && is_float($b) && is_nan($a) && is_nan($b)) {
            return true;
        }

        return self::strictEq($a, $b);
    }

    public static function isInteger(mixed $v): bool
    {
        return is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v);
    }

    public static function isFinite(mixed $v = null): bool
    {
        return is_int($v) || (is_float($v) && is_finite($v));
    }

    public static function numberIsFiniteFunction(): \Closure
    {
        static $function;

        return $function ??= static fn (mixed $value = null): bool => self::isFinite($value);
    }

    public static function toFixed(int|float $n, int $digits = 0): string
    {
        if ($digits < 0 || $digits > 100) {
            throw new JsError('toFixed() digits argument must be between 0 and 100', 'RangeError');
        }
        if (is_int($n)) {
            return $n . ($digits > 0 ? '.' . str_repeat('0', $digits) : '');
        }
        if (is_nan($n)) {
            return 'NaN';
        }
        if (abs($n) >= 1e21) {
            return self::numStr($n);
        }
        $sign = $n < 0 ? '-' : '';
        [$int, $frac] = self::exactDecimal(abs($n));
        $frac = str_pad($frac, $digits + 1, '0');
        $kept = $int . substr($frac, 0, $digits);
        if ($frac[$digits] >= '5') { // ties round up (the larger n), exact value decides
            $kept = self::incrementDecimal($kept);
        }
        $intLen = strlen($kept) - $digits;
        $out = substr($kept, 0, $intLen) . ($digits > 0 ? '.' . substr($kept, $intLen) : '');

        return $sign . $out;
    }

    /**
     * Exact decimal expansion of a non-negative finite float.
     *
     * @return array{string, string} [integer digits, fraction digits]
     */
    private static function exactDecimal(float $x): array
    {
        $bits = unpack('J', pack('E', $x))[1];
        $exp = ($bits >> 52) & 0x7FF;
        $mant = $bits & 0xFFFFFFFFFFFFF;
        if ($exp === 0) {
            $e = -1074;
        } else {
            $mant |= 1 << 52;
            $e = $exp - 1075;
        }
        if ($mant === 0) {
            return ['0', ''];
        }
        $limbs = [];
        for ($m = $mant; $m > 0; $m = intdiv($m, 1000000000)) {
            $limbs[] = $m % 1000000000;
        }
        // value = mant × 2^e = (mant × 5^-e) / 10^-e when e < 0
        [$factor, $times] = $e >= 0 ? [2, $e] : [5, -$e];
        $chunk = $factor === 2 ? 30 : 13; // 2^30, 5^13 keep limb × factor < 2^63
        for (; $times > 0; $times -= $chunk) {
            $step = min($chunk, $times);
            $mul = $factor ** $step;
            $carry = 0;
            foreach ($limbs as $i => $limb) {
                $v = $limb * $mul + $carry;
                $limbs[$i] = $v % 1000000000;
                $carry = intdiv($v, 1000000000);
            }
            while ($carry > 0) {
                $limbs[] = $carry % 1000000000;
                $carry = intdiv($carry, 1000000000);
            }
        }
        $s = (string) array_pop($limbs);
        foreach (array_reverse($limbs) as $limb) {
            $s .= str_pad((string) $limb, 9, '0', STR_PAD_LEFT);
        }
        if ($e >= 0) {
            return [$s, ''];
        }
        $point = -$e;
        $s = str_pad($s, $point + 1, '0', STR_PAD_LEFT);

        return [substr($s, 0, -$point), substr($s, -$point)];
    }

    private static function incrementDecimal(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }
            $digits[$i] = '0';
        }

        return '1' . $digits;
    }

    // ---------------------------------------------------------------- strings

    // Every string index and length counts UTF-16 code units, as in JS: the helpers below
    // work on the UTF-16LE form (2 bytes per unit) and convert results back to UTF-8.

    /** UTF-8 → UTF-16LE. */
    private static function units(string $s): string
    {
        return (string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    }

    /**
     * UTF-16LE → UTF-8. An index can split an astral character, leaving a lone surrogate that
     * UTF-8 can't hold. JS keeps it as a distinct value (`"😀".charAt(0) !== "😀".charAt(1)`);
     * any stand-in here would compare, sort or hash differently, so splitting a pair throws.
     */
    private static function fromUnits(string $u): string
    {
        $n = strlen($u);
        // The input is a contiguous slice of well-formed UTF-16; only its boundaries can split a pair.
        if ($n >= 2) {
            $first = ord($u[0]) | (ord($u[1]) << 8);
            $last = ord($u[$n - 2]) | (ord($u[$n - 1]) << 8);
            if (($first >= 0xDC00 && $first <= 0xDFFF) || ($last >= 0xD800 && $last <= 0xDBFF)) {
                self::loneSurrogate();
            }
        }

        return (string) mb_convert_encoding($u, 'UTF-8', 'UTF-16LE');
    }

    /** A result would hold a lone surrogate: see loneSurrogate(). */
    public static function splitsPair(): never
    {
        self::loneSurrogate();
    }

    private static function loneSurrogate(): never
    {
        throw new JsError('This splits a character in two (a lone UTF-16 surrogate), which compiled code can\'t represent; slice at character boundaries, for example with Array.from(s)', 'RangeError');
    }

    /** Units [$from, $to) of a UTF-16LE string, as UTF-8. */
    private static function unitSlice(string $u, int $from, int $to): string
    {
        return $from >= $to ? '' : self::fromUnits(substr($u, 2 * $from, 2 * ($to - $from)));
    }

    public static function slice(string $s, int|float $start = 0, int|float|null $end = null): string
    {
        $u = self::units($s);
        $len = intdiv(strlen($u), 2);
        $from = self::relIndex($start, $len);
        $to = $end === null ? $len : self::relIndex($end, $len);

        return self::unitSlice($u, $from, $to);
    }

    public static function substring(string $s, int|float $start, int|float|null $end = null): string
    {
        $u = self::units($s);
        $len = intdiv(strlen($u), 2);
        $a = self::clampIndex($start, $len);
        $b = $end === null ? $len : self::clampIndex($end, $len);

        return self::unitSlice($u, min($a, $b), max($a, $b));
    }

    public static function charAt(string $s, int|float $i): string
    {
        $i = self::toInteger($i);

        return $i < 0 ? '' : self::strAt($s, $i) ?? '';
    }

    /** `String.prototype.length`: UTF-16 code units (astral characters count twice). */
    public static function length(string $s): int
    {
        return intdiv(strlen(self::units($s)), 2);
    }

    /** `s.at(i)` and `s[i]`: one code unit, relative indexes allowed. */
    public static function strAt(string $s, mixed $i = 0): ?string
    {
        $i = self::toNumber($i);
        $u = self::units($s);
        $len = intdiv(strlen($u), 2);
        $i = self::toInteger($i);
        $i = $i < 0 ? $len + $i : $i;
        if ($i < 0 || $i >= $len) {
            return null;
        }

        return self::unitSlice($u, (int) $i, (int) $i + 1);
    }

    /** Numeric bracket access names a property, so only nonnegative integer indexes exist. */
    public static function strIndex(string $s, int|float $i): ?string
    {
        if (!is_finite($i) || $i < 0 || floor($i) !== (float) $i) {
            return null;
        }

        return self::strAt($s, $i);
    }

    /** String.prototype.indexOf for strings, Array.prototype.indexOf for lists. */
    public static function indexOf(string|JsArray $s, mixed $needle, mixed $from = 0): int
    {
        $from = self::toNumber($from);
        if ($s instanceof JsArray) {
            $len = count($s->a);
            for ($i = self::relIndex($from, $len); $i < $len; $i++) {
                if (self::strictEq($s->a[$i], $needle)) {
                    return $i;
                }
            }

            return -1;
        }
        $u = self::units($s);
        $n = self::units(self::str($needle));
        // A byte match at an odd offset straddles two units; keep looking.
        for ($at = 2 * self::clampIndex($from, intdiv(strlen($u), 2)); ($found = strpos($u, $n, $at)) !== false; $at = $found + 1) {
            if ($found % 2 === 0) {
                return intdiv($found, 2);
            }
        }

        return -1;
    }

    /** String.prototype.lastIndexOf for strings, Array.prototype.lastIndexOf for lists. */
    /**
     * An omitted `fromIndex` searches from the end; a given one (even `null`, which JS turns
     * into 0) is a number. Omission is the default argument, never `null`.
     */
    public static function lastIndexOf(string|JsArray $s, mixed $needle, mixed ...$fromArg): int
    {
        $from = $fromArg === [] ? null : self::toNumber($fromArg[0]);
        if ($s instanceof JsArray) {
            // fromIndex: relative to the end when negative, clamped to the last element.
            $last = count($s->a) - 1;
            if ($from !== null) {
                // Clamp before the cast: -Infinity or -1e20 must search nothing, not index 0.
                $f = self::toInteger($from);
                $last = (int) ($f < 0 ? max(count($s->a) + $f, -1) : min($f, $last));
            }
            for ($i = $last; $i >= 0; $i--) {
                if (self::strictEq($s->a[$i], $needle)) {
                    return $i;
                }
            }

            return -1;
        }
        $u = self::units($s);
        $n = self::units(self::str($needle));
        $len = intdiv(strlen($u), 2);
        $start = $from === null || is_nan($from) ? $len : self::clampIndex($from, $len);
        if ($n === '') {
            return $start;
        }
        // Walk the original buffer once, retaining the last aligned match within the bound.
        $last = -1;
        for ($at = 0; ($found = strpos($u, $n, $at)) !== false && $found <= 2 * $start; $at = $found + 1) {
            if ($found % 2 === 0) {
                $last = intdiv($found, 2);
            }
        }

        return $last;
    }

    /** @return list<?string> */
    public static function split(string $s, string|RegExp $sep, int|float|null $limit = null): JsArray
    {
        $lim = $limit === null ? 0xFFFFFFFF : self::toUint32($limit);
        if ($lim === 0) {
            return new JsArray();
        }
        if (is_string($sep)) {
            if ($sep === '') {
                // Stop at the limit before converting, as JS does: a pair split past it never
                // becomes a returned element.
                $units = $s === '' ? [] : str_split(self::units($s), 2);
                $parts = array_map(self::fromUnits(...), array_slice($units, 0, $lim));
            } else {
                $parts = explode($sep, $s);
            }

            return new JsArray(array_slice($parts, 0, $lim));
        }
        if ($s === '') {
            return new JsArray($sep->matchAt($s, 0) !== null ? [] : [$s]);
        }
        $out = [];
        $size = strlen($s);
        $p = 0;
        $q = 0;
        while ($q < $size) {
            $m = $sep->matchAt($s, $q);
            if ($m === null || $m[0] >= $size) {
                break;
            }
            [$start, $end, $groups] = $m;
            if ($end === $p) {
                $q = $start + self::charLenAt($s, $start);
                continue;
            }
            $out[] = substr($s, $p, $start - $p);
            if (count($out) === $lim) {
                return new JsArray($out);
            }
            foreach ($groups as $g) {
                $out[] = $g;
                if (count($out) === $lim) {
                    return new JsArray($out);
                }
            }
            $p = $q = $end;
        }
        $out[] = substr($s, $p);

        return new JsArray($out);
    }

    /** Byte length of the UTF-8 character starting at byte $i (1 at end of string). */
    public static function charLenAt(string $s, int $i): int
    {
        if ($i >= strlen($s)) {
            return 1;
        }
        $b = ord($s[$i]);

        return match (true) {
            $b >= 0xF0 => 4,
            $b >= 0xE0 => 3,
            $b >= 0xC0 => 2,
            default => 1,
        };
    }

    public static function padStart(string $s, int|float $len, string $fill = ' '): string
    {
        return self::pad($s, $len, $fill, true);
    }

    public static function padEnd(string $s, int|float $len, string $fill = ' '): string
    {
        return self::pad($s, $len, $fill, false);
    }

    private static function pad(string $s, int|float $len, string $fill, bool $start): string
    {
        $target = max(0, self::toInteger($len));
        $cur = self::length($s);
        if ($target <= $cur || $fill === '') {
            return $s;
        }
        if ($target > 536870888) {
            throw new JsError('Invalid string length', 'RangeError');
        }
        $need = (int) $target - $cur;
        $fill = self::units($fill);
        $padding = self::unitSlice(str_repeat($fill, intdiv(2 * $need, strlen($fill)) + 1), 0, $need);

        return $start ? $padding . $s : $s . $padding;
    }

    public static function trim(string $s): string
    {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/uD', '', $s) ?? trim($s);
    }

    public static function trimStart(string $s): string
    {
        return preg_replace('/^[' . self::WS . ']+/u', '', $s) ?? ltrim($s);
    }

    public static function trimEnd(string $s): string
    {
        return preg_replace('/[' . self::WS . ']+$/uD', '', $s) ?? rtrim($s);
    }

    public static function replace(string $s, string|RegExp $pattern, string|callable $replacement, bool $dynamic = false): string
    {
        if ($pattern instanceof RegExp) {
            if ($pattern->global) { $pattern->lastIndex = 0; }
            $matches = $pattern->global ? $pattern->matchAll($s) : array_filter([$pattern->matchAt($s, 0)]);

            return self::applyReplacements($s, $matches, $replacement, $pattern->hasNamedGroups(), $dynamic);
        }
        $pos = strpos($s, $pattern);
        if ($pos === false) {
            return $s;
        }

        return self::applyReplacements($s, [[$pos, $pos + strlen($pattern), [], []]], $replacement, false);
    }

    public static function replaceAll(string $s, string|RegExp $pattern, string|callable $replacement, bool $dynamic = false): string
    {
        if ($pattern instanceof RegExp) {
            if (!$pattern->global) {
                throw new JsError('replaceAll must be called with a global RegExp', 'TypeError');
            }

            return self::replace($s, $pattern, $replacement, $dynamic);
        }
        $matches = [];
        $plen = strlen($pattern);
        $size = strlen($s);
        for ($pos = strpos($s, $pattern); $pos !== false; $pos = $next <= $size ? strpos($s, $pattern, $next) : false) {
            $matches[] = [$pos, $pos + $plen, [], []];
            $next = $pos + ($plen > 0 ? $plen : self::charLenAt($s, $pos));
            // `""` also matches between the two UTF-16 units of a character outside the BMP.
            if ($plen === 0 && $next - $pos === 4) {
                $matches[] = [$pos, $pos, [], [], true];
            }
        }

        return self::applyReplacements($s, $matches, $replacement, false);
    }

    /**
     * @param iterable<array{int, int, list<?string>, array<string, ?string>}> $matches byte ranges + captures
     */
    private static function applyReplacements(string $s, iterable $matches, string|callable $replacement, bool $named, bool $dynamic = false): string
    {
        $out = '';
        $last = 0;
        foreach ($matches as $match) {
            [$start, $end, $groups, $names] = $match;
            // An empty match between the two halves of a surrogate pair (see RegExp::insidePair),
            // one unit past the character's start: anything inserted there splits the pair. A
            // template's `` $` `` and `$'` would carry a half too.
            $between = $match[4] ?? false;
            $matched = substr($s, $start, $end - $start);
            if (is_string($replacement)) {
                $rep = self::expandReplacement($replacement, $s, $start, $end, $groups, $named ? $names : null, $between);
            } else {
                $captures = $dynamic ? array_map(static fn ($value) => $value ?? JsUndefined::Value, $groups) : $groups;
                $args = [...$captures, self::length(substr($s, 0, $start)) + ($between ? 1 : 0), $s];
                if ($dynamic && $named) { $args[] = new JsObject(array_map(static fn ($value) => $value ?? JsUndefined::Value, $names)); }
                $rep = self::str($replacement($matched, ...$args));
            }
            if ($between && $rep !== '') {
                self::loneSurrogate();
            }
            $out .= substr($s, $last, $start - $last) . $rep;
            $last = $end;
        }

        return $out . substr($s, $last);
    }

    /**
     * GetSubstitution: $$, $&, $`, $', $n / $nn, $<name>.
     *
     * @param list<?string> $groups
     * @param ?array<string, ?string> $names null when the pattern has no named groups
     */
    private static function expandReplacement(string $tpl, string $s, int $start, int $end, array $groups, ?array $names, bool $between = false): string
    {
        if (!str_contains($tpl, '$')) {
            return $tpl;
        }
        $m = count($groups);

        return preg_replace_callback('/\$(?:(\$)|(&)|(`)|(\')|(\d\d?)|<([^>]*)>)/', static function (array $x) use ($s, $start, $end, $groups, $names, $m, $between): string {
            $tok = $x[0];
            return match (true) {
                $tok === '$$' => '$',
                $tok === '$&' => substr($s, $start, $end - $start),
                $tok === '$`' => $between ? self::loneSurrogate() : substr($s, 0, $start),
                $tok === "\$'" => $between ? self::loneSurrogate() : substr($s, $end),
                $tok[1] === '<' => $names === null ? $tok : ($names[$x[6]] ?? ''),
                default => self::numberedGroup($x[5], $groups, $m),
            };
        }, $tpl);
    }

    /** @param list<?string> $groups */
    private static function numberedGroup(string $digits, array $groups, int $m): string
    {
        $two = (int) $digits;
        if (strlen($digits) === 2 && $two >= 1 && $two <= $m) {
            return $groups[$two - 1] ?? '';
        }
        $one = (int) $digits[0];
        if ($one >= 1 && $one <= $m) {
            return ($groups[$one - 1] ?? '') . substr($digits, 1);
        }

        return '$' . $digits;
    }

    public static function repeat(string $s, int|float $n): string
    {
        $n = self::toInteger($n);
        if ($n < 0 || is_infinite($n)) {
            throw new JsError('Invalid count value: ' . self::numStr($n), 'RangeError');
        }
        if ($n * strlen($s) > 536870888) {
            throw new JsError('Invalid string length', 'RangeError');
        }

        return str_repeat($s, (int) $n);
    }

    /** Byte comparison (documented deviation from locale-aware collation). */
    public static function localeCompare(string $a, string $b): int
    {
        return strcmp($a, $b) <=> 0;
    }

    /** @return ?list<?string> */
    public static function strMatch(string $s, RegExp $re): ?JsArray
    {
        if ($re->global) {
            $all = [];
            foreach ($re->matchAll($s) as [$start, $end]) {
                $all[] = substr($s, $start, $end - $start);
            }

            return $all === [] ? null : new JsArray($all);
        }
        $m = $re->matchAt($s, 0);

        return $m === null ? null : new JsArray([substr($s, $m[0], $m[1] - $m[0]), ...$m[2]]);
    }

    // ---------------------------------------------------------------- regexp

    public static function re(string $source, string $flags): RegExp
    {
        return self::$regexCache[$flags . '/' . $source] ??= new RegExp($source, $flags);
    }

    // ---------------------------------------------------------------- arrays

    public static function index(int|float $i): ?int
    {
        return self::isInteger($i) && $i >= 0 && $i <= 4294967294 ? (int) $i : null;
    }

    public static function get(?JsArray $a, int|float $i): mixed
    {
        if ($a === null) {
            throw new JsError('Cannot read properties of null or undefined', 'TypeError');
        }
        $index = self::index($i);
        return $index === null ? null : ($a->a[$index] ?? null);
    }

    public static function setAt(?JsArray $a, int|float $i, mixed $value): mixed
    {
        if ($a === null) {
            throw new JsError('Cannot set properties of null or undefined', 'TypeError');
        }
        $index = self::index($i);
        if ($index === null || $index > count($a->a)) {
            throw new JsError('Zero arrays are dense: index ' . self::numStr($i) . ' is past length ' . count($a->a), 'RangeError');
        }
        $a->a[$index] = $value;
        return $value;
    }

    public static function key(mixed $key): string
    {
        return self::str($key);
    }

    public static function prop(JsObject|JsClass|JsUndefined|null $o, mixed $key): mixed
    {
        if (self::isNullish($o)) {
            throw new JsError('Cannot read properties of null or undefined', 'TypeError');
        }
        if ($o instanceof JsClass) { return $o->get(self::key($key)); }
        return $o->p[self::key($key)] ?? null;
    }

    public static function set(JsObject|JsClass|null $o, string $key, mixed $value): mixed
    {
        if ($o === null) {
            throw new JsError('Cannot set properties of null or undefined', 'TypeError');
        }
        if ($o instanceof JsClass) { return $o->set($key, $value); }
        $o->p[$key] = $value;
        return $value;
    }

    /** Snapshot presence before a compound assignment evaluates its right side. */
    public static function has(JsArray|JsObject|JsClass $o, mixed $key): bool
    {
        if ($o instanceof JsArray) {
            $index = self::index($key);
            return $index !== null && $index < count($o->a);
        }
        if ($o instanceof JsClass) { return $o->has(self::key($key)); }
        return array_key_exists(self::key($key), $o->p);
    }

    /** += needs the actual primitive types, including an absent old property. */
    public static function plus(mixed $a, mixed $b, bool $missing): mixed
    {
        if ($a instanceof JsArray || $a instanceof JsObject || $a instanceof RegExp) {
            $a = self::str($a);
        }
        if ($b instanceof JsArray || $b instanceof JsObject || $b instanceof RegExp) {
            $b = self::str($b);
        }
        if (is_string($a) || is_string($b)) {
            return ($missing ? 'undefined' : self::str($a)) . self::str($b);
        }
        return self::add($missing ? NAN : self::toNumber($a), self::toNumber($b));
    }

    /** A fixed-length walk may read only elements that still exist. */
    private static function element(JsArray $a, int $index): mixed
    {
        if ($index >= count($a->a)) {
            throw new JsError('Array shrank while being iterated', 'RangeError');
        }
        return $a->a[$index];
    }

    public static function find(JsArray $a, callable $f): mixed
    {
        for ($i = 0, $len = count($a->a); $i < $len; $i++) {
            $value = self::element($a, $i);
            if (self::truthy($f($value, $i, $a))) { return $value; }
        }
        return null;
    }

    public static function findIndex(JsArray $a, callable $f): int
    {
        for ($i = 0, $len = count($a->a); $i < $len; $i++) {
            $value = self::element($a, $i);
            if (self::truthy($f($value, $i, $a))) { return $i; }
        }
        return -1;
    }

    public static function findLast(JsArray $a, callable $f): mixed
    {
        for ($i = count($a->a) - 1; $i >= 0; $i--) {
            $value = self::element($a, $i);
            if (self::truthy($f($value, $i, $a))) { return $value; }
        }
        return null;
    }

    public static function findLastIndex(JsArray $a, callable $f): int
    {
        for ($i = count($a->a) - 1; $i >= 0; $i--) {
            $value = self::element($a, $i);
            if (self::truthy($f($value, $i, $a))) { return $i; }
        }
        return -1;
    }

    public static function some(JsArray $a, callable $f): bool
    {
        return self::findIndex($a, $f) !== -1;
    }

    public static function every(JsArray $a, callable $f): bool
    {
        for ($i = 0, $len = count($a->a); $i < $len; $i++) {
            $value = self::element($a, $i);
            if (!self::truthy($f($value, $i, $a))) { return false; }
        }
        return true;
    }

    public static function filter(JsArray $a, callable $f): JsArray
    {
        $out = new JsArray();
        for ($i = 0, $len = count($a->a); $i < $len; $i++) {
            $value = self::element($a, $i);
            if (self::truthy($f($value, $i, $a))) { $out->a[] = $value; }
        }
        return $out;
    }

    public static function map(JsArray $a, callable $f): JsArray
    {
        $out = new JsArray();
        for ($i = 0, $len = count($a->a); $i < $len; $i++) {
            $value = self::element($a, $i);
            $out->a[] = $f($value, $i, $a);
        }
        return $out;
    }

    public static function forEach(JsArray $a, callable $f): void
    {
        for ($i = 0, $len = count($a->a); $i < $len; $i++) {
            $value = self::element($a, $i);
            $f($value, $i, $a);
        }
    }

    public static function includes(JsArray $a, mixed $value, mixed $from = 0): bool
    {
        $len = count($a->a);
        $from = self::toInteger(self::toNumber($from));
        $start = $from < 0 ? max(0, $len + $from) : min($from, $len);
        for ($i = (int) $start; $i < $len; $i++) {
            if (self::sameValueZero($a->a[$i], $value)) { return true; }
        }
        return false;
    }

    public static function join(JsArray $a, string $sep = ','): string
    {
        return self::arrayString($a, $sep, new \SplObjectStorage());
    }

    private static function arrayString(JsArray $a, string $sep, \SplObjectStorage $stack): string
    {
        if ($stack->offsetExists($a)) { return ''; }
        $stack->offsetSet($a);
        try {
            $parts = [];
            for ($i = 0, $len = count($a->a); $i < $len; $i++) {
                $value = $a->a[$i];
                $parts[] = self::isNullish($value) ? '' : ($value instanceof JsArray ? self::arrayString($value, ',', $stack) : self::str($value));
            }
            return implode($sep, $parts);
        } finally { $stack->offsetUnset($a); }
    }

    public static function arrSlice(JsArray $a, int|float $start = 0, int|float|null $end = null): JsArray
    {
        $len = count($a->a);
        $from = self::relIndex($start, $len);
        $to = $end === null ? $len : self::relIndex($end, $len);
        return new JsArray(array_slice($a->a, $from, max(0, $to - $from)));
    }

    public static function arrAt(JsArray $a, mixed $i = 0): mixed
    {
        $i = self::toNumber($i);
        $len = count($a->a);
        $i = self::toInteger($i);
        return self::get($a, $i < 0 ? $len + $i : $i);
    }

    public static function push(JsArray $a, mixed ...$values): int
    {
        foreach ($values as $value) { $a->a[] = $value; }
        return count($a->a);
    }

    public static function unshift(JsArray $a, mixed ...$values): int
    {
        $a->a = [...$values, ...$a->a];
        return count($a->a);
    }

    public static function pop(JsArray $a): mixed { return array_pop($a->a); }
    public static function shift(JsArray $a): mixed { return array_shift($a->a); }

    public static function concat(JsArray $a, mixed ...$items): JsArray
    {
        $out = new JsArray($a->a);
        foreach ($items as $item) {
            if ($item instanceof JsArray) {
                foreach ($item->a as $value) { $out->a[] = $value; }
            } else { $out->a[] = $item; }
        }
        return $out;
    }

    public static function from(JsArray|string $source, ?callable $f = null): JsArray
    {
        $source = is_string($source) ? new JsArray(mb_str_split($source, 1, 'UTF-8')) : $source;
        $out = new JsArray();
        for ($i = 0; $i < count($source->a); $i++) {
            $value = $source->a[$i];
            $out->a[] = $f === null ? $value : $f($value, $i);
        }
        return $out;
    }

    public static function arr(iterable $values): JsArray
    {
        $out = new JsArray();
        foreach ($values as $value) { $out->a[] = $value; }
        return $out;
    }

    public static function splice(JsArray $a, int|float $start, int|float|null $deleteCount = null, mixed ...$items): JsArray
    {
        $len = count($a->a);
        $start = self::relIndex($start, $len);
        $count = $deleteCount === null ? $len - $start : self::clampIndex($deleteCount, $len - $start);
        return new JsArray(array_splice($a->a, $start, $count, $items));
    }

    public static function reverse(JsArray $a): JsArray
    {
        $a->a = array_reverse($a->a);
        return $a;
    }

    public static function toReversed(JsArray $a): JsArray { return new JsArray(array_reverse($a->a)); }

    public static function sort(JsArray $a, ?callable $cmp = null): JsArray
    {
        $len = count($a->a);
        $sorted = self::sorted($a, $cmp);
        $a->a = [...$sorted->a, ...array_slice($a->a, $len)];
        return $a;
    }

    public static function toSorted(JsArray $a, ?callable $cmp = null): JsArray { return self::sorted($a, $cmp); }

    public static function sorted(JsArray $a, ?callable $cmp = null): JsArray
    {
        $items = $a->a;
        if ($cmp !== null) {
            usort($items, static function (mixed $x, mixed $y) use ($cmp): int {
                $r = self::toNumber($cmp($y, $x));
                return $r > 0 ? -1 : ($r < 0 ? 1 : 0);
            });
            return new JsArray($items);
        }
        $keyed = [];
        foreach ($items as $item) { $keyed[] = [mb_convert_encoding(self::str($item), 'UTF-16BE', 'UTF-8'), $item]; }
        usort($keyed, static fn (array $x, array $y): int => strcmp($x[0], $y[0]) <=> 0);
        return new JsArray(array_column($keyed, 1));
    }

    public static function with(JsArray $a, int|float $index, mixed $value): JsArray
    {
        $i = self::toInteger($index);
        $i = $i < 0 ? count($a->a) + $i : $i;
        if ($i < 0 || $i >= count($a->a)) { throw new JsError('Invalid index: ' . self::numStr($index), 'RangeError'); }
        $out = new JsArray($a->a);
        $out->a[(int) $i] = $value;
        return $out;
    }

    public static function reduce(JsArray $a, callable $f, mixed ...$init): mixed
    {
        $len = count($a->a);
        if ($init === [] && $len === 0) { throw new JsError('Reduce of empty array with no initial value', 'TypeError'); }
        $acc = $init === [] ? $a->a[0] : $init[0];
        for ($i = $init === [] ? 1 : 0; $i < $len; $i++) {
            $value = self::element($a, $i);
            $acc = $f($acc, $value, $i, $a);
        }
        return $acc;
    }

    public static function flat(JsArray $a, int|float $depth = 1): JsArray
    {
        $out = new JsArray();
        $depth = self::toInteger($depth);
        foreach ($a->a as $value) {
            if ($depth > 0 && $value instanceof JsArray) {
                foreach (self::flat($value, $depth - 1)->a as $item) { $out->a[] = $item; }
            } else { $out->a[] = $value; }
        }
        return $out;
    }

    public static function flatMap(JsArray $a, callable $f): JsArray
    {
        $out = new JsArray();
        for ($i = 0, $len = count($a->a); $i < $len; $i++) {
            $value = self::element($a, $i);
            $mapped = $f($value, $i, $a);
            if ($mapped instanceof JsArray) {
                foreach ($mapped->a as $item) { $out->a[] = $item; }
            } else { $out->a[] = $mapped; }
        }
        return $out;
    }

    public static function fill(JsArray $a, mixed $value, int|float $start = 0, int|float|null $end = null): JsArray
    {
        $len = count($a->a);
        $from = self::relIndex($start, $len);
        $to = $end === null ? $len : self::relIndex($end, $len);
        for ($i = $from; $i < $to; $i++) { $a->a[$i] = $value; }
        return $a;
    }

    public static function max(int|float ...$n): int|float
    {
        $best = -INF;
        foreach ($n as $x) {
            if (is_nan((float) $x)) {
                return NAN;
            }
            if ($x > $best || ($x == 0 && $best == 0 && !self::isNegZero($x))) {
                $best = $x;
            }
        }

        return $best;
    }

    public static function min(int|float ...$n): int|float
    {
        $best = INF;
        foreach ($n as $x) {
            if (is_nan((float) $x)) {
                return NAN;
            }
            if ($x < $best || ($x == 0 && $best == 0 && self::isNegZero($x))) {
                $best = $x;
            }
        }

        return $best;
    }

    private static function isNegZero(int|float $x): bool
    {
        return is_float($x) && $x === 0.0 && fdiv(1, $x) < 0;
    }

    public static function range(int $n, callable $f): JsArray
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $f(null, $i);
        }

        return new JsArray($out);
    }

    // ---------------------------------------------------------------- objects

    /** Structural equality that tells `{}` from `[]` (two separately decoded `{}` handles are equal). */
    public static function obj(array ...$parts): JsObject
    {
        $out = new JsObject();
        foreach ($parts as $part) {
            foreach (self::ownKeys($part) as $key) { $out->p[$key] = $part[$key]; }
        }
        return $out;
    }

    public static function keys(JsObject $o): JsArray
    {
        return new JsArray(array_map('strval', self::ownKeys($o->p)));
    }

    public static function values(JsObject $o): JsArray
    {
        return new JsArray(array_map(static fn ($key) => $o->p[$key], self::ownKeys($o->p)));
    }

    public static function entries(JsObject $o): JsArray
    {
        return new JsArray(array_map(static fn ($key) => new JsArray([(string) $key, $o->p[$key]]), self::ownKeys($o->p)));
    }

    public static function fromEntries(JsArray $entries): JsObject
    {
        $out = new JsObject();
        foreach ($entries->a as $entry) { $out->p[self::key($entry->a[0])] = $entry->a[1] ?? null; }
        return $out;
    }

    public static function omit(JsObject $o, array $keys): JsObject
    {
        $skip = array_fill_keys($keys, true);
        $out = new JsObject();
        foreach (self::ownKeys($o->p) as $key) {
            if (!isset($skip[$key])) { $out->p[$key] = $o->p[$key]; }
        }
        return $out;
    }

    public static function hasOwn(JsObject|JsArray $o, mixed $key): bool
    {
        $key = self::key($key);
        if ($o instanceof JsObject) { return array_key_exists($key, $o->p); }
        if ($key === 'length') { return true; }
        if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', $key)) { return false; }
        $index = self::index((float) $key);
        return $index !== null && (string) $index === $key && $index < count($o->a);
    }

    public static function clone(mixed $value): mixed
    {
        return self::copyGraph($value, new \SplObjectStorage(), true);
    }

    public static function detach(mixed $value): mixed
    {
        return self::copyGraph($value, new \SplObjectStorage(), false);
    }

    private static function copyGraph(mixed $value, \SplObjectStorage $seen, bool $structured): mixed
    {
        if ($value instanceof \Closure) {
            if ($structured) { throw new JsError('Function could not be cloned', 'DataCloneError'); }
            return $value;
        }
        if ($value instanceof RegExp && !$structured) { return $value; }
        if ($value instanceof JsArguments && $structured) { throw new JsError('Arguments could not be cloned', 'DataCloneError'); }
        if ($value instanceof JsSet || $value instanceof JsMap || $value instanceof JsDate || $value instanceof JsArguments) {
            if ($seen->offsetExists($value)) { return $seen[$value]; }
            $copy = match (true) {
                $value instanceof JsSet => new JsSet(),
                $value instanceof JsMap => new JsMap(),
                $value instanceof JsDate => new JsDate($value->milliseconds),
                default => new JsArguments([]),
            };
            $seen[$value] = $copy;
            if ($value instanceof JsSet) {
                foreach ($value->values as $item) { $copy->add(self::copyGraph($item, $seen, $structured)); }
            } elseif ($value instanceof JsMap) {
                foreach ($value->entries as [$key, $item]) { $copy->set(self::copyGraph($key, $seen, $structured), self::copyGraph($item, $seen, $structured)); }
            } elseif ($value instanceof JsArguments) {
                foreach ($value->a as $key => $item) { $copy->a[$key] = self::copyGraph($item, $seen, $structured); }
                foreach ($value->p as $key => $item) { $copy->p[$key] = self::copyGraph($item, $seen, $structured); }
                $copy->length = self::copyGraph($value->length, $seen, $structured);
            }
            return $copy;
        }
        if ($value instanceof JsObject || $value instanceof JsArray || $value instanceof RegExp || $value instanceof JsError) {
            if ($seen->offsetExists($value)) { return $seen[$value]; }
            if ($value instanceof JsError) {
                $copy = new JsError($value->getMessage(), $value->jsName);
                $seen[$value] = $copy;
                return $copy;
            }
            if ($value instanceof RegExp) {
                $copy = new RegExp($value->source, $value->flags);
                $seen[$value] = $copy;
                return $copy;
            }
            $copy = $value instanceof JsObject ? new JsObject() : new JsArray();
            $seen[$value] = $copy;
            if ($value instanceof JsObject) {
                foreach (self::ownKeys($value->p) as $key) { $copy->p[$key] = self::copyGraph($value->p[$key], $seen, $structured); }
            } else {
                foreach ($value->a as $item) { $copy->a[] = self::copyGraph($item, $seen, $structured); }
                foreach (self::ownKeys($value->p) as $key) { $copy->p[$key] = self::copyGraph($value->p[$key], $seen, $structured); }
            }
            return $copy;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) { $value[$key] = self::copyGraph($item, $seen, $structured); }
        }
        return $value;
    }

    /** The JSON value tree shared by Zero's encoder and foreign encoders. */
    public static function jsonTree(mixed $value): mixed
    {
        return self::jsonValueTree($value, new \SplObjectStorage());
    }

    private static function jsonValueTree(mixed $value, \SplObjectStorage $stack): mixed
    {
        if ($value === JsUndefined::Value) { return null; }
        if ($value instanceof JsDate) { return is_finite((float) $value->milliseconds) ? self::iso($value->milliseconds) : null; }
        if ($value instanceof JsSet || $value instanceof JsMap || $value instanceof JsNamespace || $value instanceof JsError) { return new \stdClass(); }
        if (is_float($value)) { return !is_finite($value) ? null : ($value == 0.0 ? 0 : $value); }
        if ($value instanceof \Closure) { return null; }
        if ($value instanceof RegExp) { return new \stdClass(); }
        if (!is_array($value) && !is_object($value)) { return $value; }
        $object = is_object($value);
        if ($object) {
            if ($stack->offsetExists($value)) { throw new JsError('Converting circular structure to JSON', 'TypeError'); }
            $stack->offsetSet($value);
        }
        try {
            if ($value instanceof \JsonSerializable) { return self::jsonValueTree($value->jsonSerialize(), $stack); }
            $list = $value instanceof JsArray || is_array($value) && array_is_list($value);
            $items = match (true) {
                $value instanceof JsArray => $value->a,
                $value instanceof JsArguments => array_replace($value->a, $value->p),
                $value instanceof JsObject => $value->p,
                is_object($value) => get_object_vars($value),
                default => $value,
            };
            if ($list) {
                $out = [];
                foreach ($items as $item) { $out[] = self::jsonValueTree($item, $stack); }
                return $out;
            }
            $out = [];
            $nulKey = false;
            foreach (self::ownKeys($items) as $key) {
                if ($items[$key] instanceof \Closure || $items[$key] === JsUndefined::Value) { continue; }
                $nulKey = $nulKey || str_starts_with((string) $key, "\0");
                $out[$key] = self::jsonValueTree($items[$key], $stack);
            }
            if ($nulKey) { return $out; }
            $result = new \stdClass();
            foreach ($out as $key => $item) { $result->{(string) $key} = $item; }
            return $result;
        } finally {
            if ($object) { $stack->offsetUnset($value); }
        }
    }

    public static function sameJson(mixed $a, mixed $b): bool
    {
        return self::sameGraph($a, $b, []);
    }

    private static function sameGraph(mixed $a, mixed $b, array $seen): bool
    {
        if ($a instanceof JsObject || $a instanceof JsArray || $b instanceof JsObject || $b instanceof JsArray) {
            if (!is_object($a) || !is_object($b) || $a::class !== $b::class) { return false; }
            $pair = spl_object_id($a) . ':' . spl_object_id($b);
            if (isset($seen[$pair])) { return true; }
            $seen[$pair] = true;
            $a = $a instanceof JsObject ? $a->p : $a->a;
            $b = $b instanceof JsObject ? $b->p : $b->a;
        }
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b) || self::ownKeys($a) !== self::ownKeys($b)) { return false; }
            foreach ($a as $key => $value) {
                if (!self::sameGraph($value, $b[$key], $seen)) { return false; }
            }
            return true;
        }
        return self::strictEq($a, $b);
    }

    public static function ownKeys(array $o): array
    {
        if (array_is_list($o)) {
            return array_keys($o);
        }
        $indexes = [];
        $names = [];
        foreach ($o as $k => $_) {
            if (is_int($k) && $k >= 0 && $k < 4294967295) {
                $indexes[] = $k;
            } else {
                $names[] = $k;
            }
        }
        sort($indexes);

        return [...$indexes, ...$names];
    }

    public static function jsonStringify(mixed $v, int|float|string|null $indent = null): ?string
    {
        if ($v instanceof \Closure || $v === JsUndefined::Value) {
            return null;
        }
        $v = self::jsonTree($v);
        if (is_string($indent)) {
            // The first 10 units. Cutting a surrogate pair there only matters if the gap is
            // written (a non-empty array or object): a scalar or empty result never uses it.
            $u = self::units($indent);
            try {
                $gap = self::unitSlice($u, 0, 10);
            } catch (JsError $e) {
                // If the result doesn't depend on the gap, it's never written and the cut doesn't
                // matter: compare two encodes with different stand-in gaps.
                $plain = self::encode($v, '', '');
                if ($plain !== self::encode($v, ' ', '')) {
                    throw $e;
                }

                return $plain;
            }
        } elseif ($indent !== null) {
            $gap = str_repeat(' ', (int) min(10, max(0, self::toInteger($indent))));
        } else {
            $gap = '';
        }

        return self::encode($v, $gap, '');
    }

    private static function encode(mixed $v, string $gap, string $indent): ?string
    {
        if ($v === null || is_bool($v) || is_int($v) || is_string($v)) {
            return json_encode($v, self::JSON_FLAGS);
        }
        if (is_float($v)) {
            return is_finite($v) ? self::numStr($v) : 'null';
        }
        if ($v instanceof \Closure) {
            return null;
        }
        if ($v instanceof \JsonSerializable) {
            return self::encode($v->jsonSerialize(), $gap, $indent);
        }
        $object = is_object($v);
        if ($object) {
            $v = $v instanceof RegExp ? [] : get_object_vars($v);
            if ($v === []) {
                return '{}';
            }
        }
        if ($v === []) {
            return '[]';
        }
        $inner = $indent . $gap;
        $parts = [];
        $isList = !$object && array_is_list($v);
        foreach ($isList ? array_keys($v) : self::ownKeys($v) as $k) {
            $enc = self::encode($v[$k], $gap, $inner);
            if ($isList) {
                $parts[] = $enc ?? 'null';
            } elseif ($enc !== null) {
                $parts[] = json_encode((string) $k, self::JSON_FLAGS) . ($gap === '' ? ':' : ': ') . $enc;
            }
        }
        [$open, $close] = $isList ? ['[', ']'] : ['{', '}'];
        if ($parts === []) {
            return $open . $close;
        }
        if ($gap === '') {
            return $open . implode(',', $parts) . $close;
        }

        return $open . "\n" . $inner . implode(",\n" . $inner, $parts) . "\n" . $indent . $close;
    }

    /**
     * JSON text → handles, preserving object/list shape at every depth.
     * Integers stay exact: request bodies and stored json() values
     * may carry ids. Throws \JsonException.
     */
    public static function decodeJson(string $s, int $depth = 512): mixed
    {
        try {
            return self::fromJson(json_decode($s, false, $depth, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            // PHP objects can't hold a property named "\u0000…". Such valid JSON is decoded with a
            // placeholder for that one byte in keys, then restored, so `{}` elsewhere keeps its shape.
            // Only a real \u0000 escape (an odd run of backslashes before `u0000`) is replaced; the
            // placeholder is a fresh random token that can't occur in the document.
            if (!preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\u0000/', $s)) {
                throw $e;
            }
            do {
                $mark = 'zn' . bin2hex(random_bytes(8));
            } while (str_contains($s, $mark));
            $swapped = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\u0000/', '${1}' . $mark, $s);

            return self::restoreNul(self::fromJson(json_decode($swapped, false, $depth, JSON_THROW_ON_ERROR)), $mark);
        }
    }

    private static function restoreNul(mixed $v, string $mark): mixed
    {
        if (is_string($v)) { return str_replace($mark, "\0", $v); }
        if ($v instanceof JsArray) {
            foreach ($v->a as $i => $value) { $v->a[$i] = self::restoreNul($value, $mark); }
        } elseif ($v instanceof JsObject) {
            $props = [];
            foreach ($v->p as $key => $value) { $props[is_string($key) ? str_replace($mark, "\0", $key) : $key] = self::restoreNul($value, $mark); }
            $v->p = $props;
        }
        return $v;
    }

    private static function fromJson(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $out = new JsObject();
            foreach (get_object_vars($v) as $key => $value) { $out->p[$key] = self::fromJson($value); }
            return $out;
        }
        return is_array($v) ? new JsArray(array_map(self::fromJson(...), $v)) : $v;
    }

    public static function jsonParse(string $s): mixed
    {
        try {
            // Compiled JSON.parse: `{}` stays an object, and integers past ±2^53 are the doubles JS parses.
            return self::jsNumbers(self::decodeJson($s, 4096));
        } catch (\JsonException $e) {
            throw new JsError('Unexpected token in JSON: ' . $e->getMessage(), 'SyntaxError');
        }
    }

    /**
     * A fresh variable cell holding $v. `for (let i ...)` rebinds a captured `i` to one before each
     * update (`$i = &Js::cell($i)`), so a closure from an earlier iteration keeps its own `i`, as in JS.
     */
    public static function &cell(mixed $v): mixed
    {
        $c = $v;

        return $c;
    }

    // ---------------------------------------------------------------- misc

    private static ?int $instant = null;

    /**
     * Run $fn with now() fixed to one instant (the current one, unless already inside an instant).
     * A write runs its defaults, onUpdate values and draft dates inside one, so every `now` of a
     * row, nested fields included, is the same millisecond.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function atInstant(callable $fn): mixed
    {
        if (self::$instant !== null) {
            return $fn();
        }
        self::$instant = self::now();
        try {
            return $fn();
        } finally {
            self::$instant = null;
        }
    }

    /**
     * Epoch ms. `ZERO_NOW` (ISO string or epoch ms) pins the clock for time-travel tests (§9.1);
     * it is read on every call so an in-process test can move it. With `ZERO_NOW_STEP` (ms) set too,
     * every read advances the pinned clock by that step: a test-only seam that makes "two reads see two
     * instants" deterministic. Inside atInstant() it is that one instant.
     */
    public static function now(): int
    {
        if (self::$instant !== null) {
            return self::$instant;
        }
        $pinned = getenv('ZERO_NOW');
        if ($pinned !== false && $pinned !== '') {
            $ms = preg_match('/^-?\d+$/D', $pinned) ? (int) $pinned : self::date($pinned);
            if (is_int($ms)) {
                $step = getenv('ZERO_NOW_STEP');
                if ($step !== false && preg_match('/^\d+$/D', $step)) {
                    putenv('ZERO_NOW=' . ($ms + (int) $step));
                }

                return $ms;
            }
        }

        return (int) floor(microtime(true) * 1000);
    }

    /** new Date(v).getTime() */
    public static function date(mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return self::timeClip((float) $v);
        }
        if ($v === null || is_bool($v)) {
            return (int) $v;
        }
        if ($v instanceof JsArray) {
            $v = self::str($v);
        }

        return is_string($v) ? self::parseDate($v) : NAN;
    }

    private static function timeClip(float $t): int|float
    {
        if (!is_finite($t) || abs($t) > self::MAX_TIME) {
            return NAN;
        }

        return (int) self::trunc($t);
    }

    /** ES date-time string format (plus a space separator and ±HHmm offsets); no zone = UTC. */
    private static function parseDate(string $s): int|float
    {
        $re = '/^([+-]\d{6}|\d{4})(?:-(\d{2})(?:-(\d{2}))?)?'
            . '(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?(Z|[+-]\d{2}:?\d{2})?)?$/D';
        if (!preg_match($re, $s, $m)) {
            return NAN;
        }
        if ($m[1] === '-000000') {
            return NAN;
        }
        $y = (int) $m[1];
        $mo = ($m[2] ?? '') === '' ? 1 : (int) $m[2];
        $d = ($m[3] ?? '') === '' ? 1 : (int) $m[3];
        $h = (int) ($m[4] ?? 0);
        $mi = (int) ($m[5] ?? 0);
        $sec = (int) ($m[6] ?? 0);
        $ms = ($m[7] ?? '') === '' ? 0 : (int) substr($m[7] . '00', 0, 3);
        // Hour 24 is only midnight: every digit of the fraction counts, not the 3 kept for ms.
        $fraction = $m[7] ?? '';
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $mi > 59 || $sec > 59
            || $h > 24 || ($h === 24 && ($mi + $sec > 0 || trim($fraction, '0') !== ''))) {
            return NAN;
        }
        $offset = 0;
        $zone = $m[8] ?? '';
        if ($zone !== '' && $zone !== 'Z') {
            $zh = (int) substr($zone, 1, 2);
            $zm = (int) substr($zone, -2);
            if ($zh > 23 || $zm > 59) {
                return NAN;
            }
            $offset = ($zone[0] === '-' ? -1 : 1) * ($zh * 60 + $zm) * 60000;
        }
        $days = self::daysFromCivil($y, $mo, 1) + $d - 1;

        return self::timeClip((float) ($days * 86400000 + (($h * 60 + $mi) * 60 + $sec) * 1000 + $ms - $offset));
    }

    private static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** @return array{int, int, int} */
    private static function civilFromDays(int $z): array
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp + ($mp < 10 ? 3 : -9);

        return [$yoe + $era * 400 + ($m <= 2 ? 1 : 0), $m, $d];
    }

    /** Date.prototype.toISOString */
    public static function iso(int|float $ms): string
    {
        $t = self::timeClip((float) $ms);
        if (is_float($t)) {
            throw new JsError('Invalid time value', 'RangeError');
        }
        $days = intdiv($t, 86400000) - ($t % 86400000 < 0 ? 1 : 0);
        $rem = $t - $days * 86400000;
        [$y, $m, $d] = self::civilFromDays($days);
        $year = $y >= 0 && $y <= 9999 ? sprintf('%04d', $y) : ($y < 0 ? '-' : '+') . sprintf('%06d', abs($y));

        return sprintf(
            '%s-%02d-%02dT%02d:%02d:%02d.%03dZ',
            $year, $m, $d,
            intdiv($rem, 3600000), intdiv($rem, 60000) % 60, intdiv($rem, 1000) % 60, $rem % 1000,
        );
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0F | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function random(): float
    {
        return random_int(0, (1 << 53) - 1) / 9007199254740992.0;
    }

    public static function log(string $level, mixed ...$args): void
    {
        $parts = array_map(
            static fn ($a) => is_array($a) || (is_object($a) && !$a instanceof RegExp) ? (self::jsonStringify($a) ?? 'undefined') : self::str($a),
            $args,
        );
        error_log('[zero] ' . ($level === 'log' ? '' : $level . ': ') . implode(' ', $parts));
    }
}
