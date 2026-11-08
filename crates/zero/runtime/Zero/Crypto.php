<?php

declare(strict_types=1);

namespace Zero;

/**
 * `crypto` from "zero": digests, MACs, constant-time comparison and random values, over PHP's
 * `hash`, `hash_hmac`, `hash_equals` and `random_bytes`.
 *
 * Output is byte-for-byte what the original Zero runtime's `ctx.crypto` returned: lowercase hex
 * over the UTF-8 bytes of the input string, with keys used as given. A named HMAC key reads the
 * environment value trimmed, as the original keyring did. Inputs are capped at 1 MiB.
 */
final class Crypto
{
    public const MAX_DATA = 1048576;

    public static function sha256(mixed $data): string
    {
        return hash('sha256', self::bounded($data, 'crypto.sha256'));
    }

    public static function sha1(mixed $data): string
    {
        return hash('sha1', self::bounded($data, 'crypto.sha1'));
    }

    public static function sha512(mixed $data): string
    {
        return hash('sha512', self::bounded($data, 'crypto.sha512'));
    }

    public static function md5(mixed $data): string
    {
        return hash('md5', self::bounded($data, 'crypto.md5'));
    }

    /**
     * `hmacSha256({ key, data })`: `key` is an environment variable name, or `{ value }` for a key
     * that is not a secret.
     */
    public static function hmacSha256(mixed $options): string
    {
        $options = $options instanceof JsObject ? $options->p : (is_array($options) ? $options : null);
        if ($options === null || array_diff(array_keys($options), ['key', 'data']) !== []) {
            throw new JsError('crypto.hmacSha256 takes { key, data }', 'TypeError');
        }
        $data = self::bounded($options['data'] ?? null, 'crypto.hmacSha256');
        $key = $options['key'] ?? null;
        if ($key instanceof JsObject) {
            $key = $key->p;
        }
        if (is_array($key)) {
            if (array_keys($key) !== ['value'] || !is_string($key['value'])) {
                throw new JsError('crypto.hmacSha256 key must be a variable name or { value }', 'TypeError');
            }
            // An inline key can come from request input, so it gets the same cap as the data.
            $secret = self::bounded($key['value'], 'crypto.hmacSha256');
        } elseif (is_string($key) && $key !== '') {
            $value = Env::get($key);
            $secret = $value === null ? '' : self::trim($value);
            if ($secret === '') {
                throw new HttpError(500, 'crypto_key_unavailable', "Set the secret variable $key for this site, then retry.");
            }
        } else {
            throw new JsError('crypto.hmacSha256 key must be a variable name or { value }', 'TypeError');
        }

        return hash_hmac('sha256', $data, $secret);
    }

    /** Constant time over equal lengths; different lengths are unequal. */
    public static function timingSafeEqual(mixed $a, mixed $b): bool
    {
        $a = self::bounded($a, 'crypto.timingSafeEqual');
        $b = self::bounded($b, 'crypto.timingSafeEqual');

        return strlen($a) === strlen($b) && hash_equals($a, $b);
    }

    /** `randomBytes(n)` as lowercase hex, n from 1 to 1024. */
    public static function randomHex(mixed $bytes): string
    {
        if (!is_int($bytes) && !(is_float($bytes) && floor($bytes) === $bytes)) {
            throw new JsError('crypto.randomHex needs a whole number of bytes', 'RangeError');
        }
        $bytes = (int) $bytes;
        if ($bytes < 1 || $bytes > 1024) {
            throw new JsError('crypto.randomHex takes 1 to 1024 bytes', 'RangeError');
        }

        return bin2hex(random_bytes($bytes));
    }

    /** A random RFC 4122 version 4 UUID, as `crypto.randomUUID()` returns. */
    public static function randomUUID(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** Rust's `str::trim`: Unicode White_Space at both ends, which the original keyring applied. */
    private static function trim(string $value): string
    {
        $space = '[\x{9}-\x{D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
        $trimmed = preg_replace("/\\A$space+|$space+\\z/u", '', $value);

        return is_string($trimmed) ? $trimmed : trim($value, " \t\n\r\x0B\f");
    }

    private static function bounded(mixed $value, string $call): string
    {
        if (!is_string($value)) {
            throw new JsError("$call needs a string", 'TypeError');
        }
        if (strlen($value) > self::MAX_DATA) {
            throw new HttpError(413, 'crypto_input_too_large', 'The crypto input is too large.');
        }

        return $value;
    }
}
