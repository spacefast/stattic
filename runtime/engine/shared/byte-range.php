<?php
declare(strict_types=1);

// null means ignore Range; false means a valid range has no satisfiable bytes.
function _stattic_v4_byte_range(string $value, int $length): array|false|null
{
    if (!preg_match('/^bytes=([0-9]*)-([0-9]*)$/iD', trim($value), $parts)
        || ($parts[1] === '' && $parts[2] === '')) {
        return null;
    }
    // PHP saturates decimal casts at PHP_INT_MAX, so oversized bounds cannot
    // wrap negative. End and suffix bounds are clipped to the representation.
    if ($parts[1] === '') {
        $suffix = (int) $parts[2];
        return $suffix === 0 || $length === 0 ? false : [max(0, $length - $suffix), $length - 1];
    }
    $start = (int) $parts[1];
    $end = $parts[2] === '' ? $length - 1 : (int) $parts[2];
    if ($parts[2] !== '' && $end < $start) {
        return null;
    }
    return $start >= $length ? false : [$start, min($end, $length - 1)];
}
