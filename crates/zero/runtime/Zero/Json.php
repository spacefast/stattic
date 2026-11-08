<?php

declare(strict_types=1);

namespace Zero;

/** Native PHP JSON with round-tripping numbers, confined to this encoding operation. */
final class Json
{
    public static function encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        $previous = ini_get('serialize_precision');
        if ($previous === '-1') {
            return json_encode($value, $flags, $depth);
        }
        if (ini_set('serialize_precision', '-1') === false) {
            throw new \RuntimeException('Zero JSON requires writable serialize_precision');
        }
        try {
            return json_encode($value, $flags, $depth);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }
}
