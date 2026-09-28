<?php
declare(strict_types=1);

// THE canonical JSON spelling the runtime digests with. It is the spelling of
// packages/common/src/utils/canonical-json.ts, where the definition lives; both
// are pinned to packages/common/src/utils/canonical-json.fixtures.json.
//
// Dependency-free on purpose: the engine and the WordPress content kernel both
// load it, and the kernel must not pull in engine-lane helpers.
//
//  - Object members sorted by key in Unicode code point order. ksort with
//    SORT_STRING compares UTF-8 bytes, which is the same order.
//  - Strings as JSON.stringify spells them: only `"`, `\` and C0 controls are
//    escaped. U+2028/2029, `/` and non-ASCII stay raw.
//  - Numbers as ECMAScript spells them (shortest round trip, exponent past
//    1e21 and below 1e-6).
//
// A PHP array is ambiguous between a JSON object and a JSON array. A list is an
// array; `[]` is therefore `[]`, never `{}`. Pass a stdClass for an object that
// may be empty.

const STATTIC_CANONICAL_JSON_FLAGS = JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_LINE_TERMINATORS
    | JSON_THROW_ON_ERROR;

/** @throws JsonException for a value JSON cannot hold (invalid UTF-8, NAN, INF, resources). */
function _stattic_canonical_json(mixed $value): string
{
    if ($value instanceof stdClass) {
        return _stattic_canonical_json_object(get_object_vars($value));
    }
    if (is_array($value)) {
        return array_is_list($value)
            ? '[' . implode(',', array_map('_stattic_canonical_json', $value)) . ']'
            : _stattic_canonical_json_object($value);
    }
    if (is_float($value)) {
        return _stattic_canonical_json_number($value);
    }
    if (is_object($value)) {
        throw new JsonException('canonical JSON holds only arrays, stdClass and scalars');
    }
    return json_encode($value, STATTIC_CANONICAL_JSON_FLAGS);
}

function _stattic_canonical_json_object(array $members): string
{
    ksort($members, SORT_STRING);
    $encoded = [];
    foreach ($members as $key => $member) {
        $encoded[] = json_encode((string) $key, STATTIC_CANONICAL_JSON_FLAGS)
            . ':' . _stattic_canonical_json($member);
    }
    return '{' . implode(',', $encoded) . '}';
}

/** ECMAScript Number::toString for a finite double. */
function _stattic_canonical_json_number(float $value): string
{
    if (!is_finite($value)) {
        throw new JsonException('canonical JSON cannot hold a non-finite number');
    }
    if ($value == 0.0) {
        return '0';
    }
    // The fewest significant digits that read back as the same double; never
    // the ini-dependent serialize_precision.
    for ($decimals = 0; $decimals < 16; $decimals++) {
        if ((float) sprintf('%.' . $decimals . 'e', $value) === $value) {
            break;
        }
    }
    [$mantissa, $exponent] = explode('e', sprintf('%.' . $decimals . 'e', $value));
    $negative = str_starts_with($mantissa, '-');
    $digits = rtrim(str_replace(['-', '.'], '', $mantissa), '0');
    $digits = $digits === '' ? '0' : $digits;
    $point = (int) $exponent + 1;
    $count = strlen($digits);
    if ($count <= $point && $point <= 21) {
        $text = $digits . str_repeat('0', $point - $count);
    } elseif (0 < $point && $point <= 21) {
        $text = substr($digits, 0, $point) . '.' . substr($digits, $point);
    } elseif (-6 < $point && $point <= 0) {
        $text = '0.' . str_repeat('0', -$point) . $digits;
    } else {
        $shift = $point - 1;
        $text = ($count === 1 ? $digits : $digits[0] . '.' . substr($digits, 1))
            . 'e' . ($shift < 0 ? '-' : '+') . abs($shift);
    }
    return ($negative ? '-' : '') . $text;
}
