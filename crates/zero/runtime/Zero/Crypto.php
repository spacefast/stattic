<?php

declare(strict_types=1);

namespace Zero;

/**
 * The `node:crypto` subset and Web Crypto's `randomUUID`: digests, MACs, constant-time comparison
 * and random values, over PHP's `hash`, `hash_hmac`, `hash_equals` and `random_bytes`.
 *
 * Strings are hashed as their UTF-8 bytes, as Node's `update(string)` does. A MAC key read from
 * `process.env.NAME` is trimmed, and a missing or blank one is refused, as the original Zero
 * runtime's keyring did; any other key is used as given. Inputs are capped at 1 MiB.
 */
final class Crypto
{
    public const MAX_DATA = 1048576;
    public const ALGORITHMS = ['md5', 'sha1', 'sha256', 'sha384', 'sha512'];
    public const ENCODINGS = ['hex', 'base64', 'base64url'];

    /** `createHash(algorithm).update(...parts).digest(encoding)`. */
    public static function hash(string $algorithm, array $parts, string $encoding): string
    {
        return self::encode(hash(self::algorithm($algorithm), self::joined($parts, 'Hash'), true), $encoding);
    }

    /** `createHmac(algorithm, key).update(...parts).digest(encoding)` with a key used as given. */
    public static function hmac(string $algorithm, mixed $key, array $parts, string $encoding): string
    {
        if (!is_string($key)) {
            throw new JsError('The "key" argument must be a string', 'TypeError');
        }
        // A key can come from request input, so it gets the same cap as the data.
        $key = self::bounded($key, "$algorithm key");

        return self::encode(hash_hmac(self::algorithm($algorithm), self::joined($parts, 'Hmac'), $key, true), $encoding);
    }

    /** `createHmac(algorithm, process.env.NAME)...`: the variable is read trimmed and must be set. */
    public static function hmacEnv(string $algorithm, string $name, array $parts, string $encoding): string
    {
        $value = Env::get($name);
        $secret = $value === null ? '' : self::trim($value);
        if ($secret === '') {
            throw new HttpError(500, 'crypto_key_unavailable', "Set the secret variable $name for this site, then retry.");
        }

        return self::hmac($algorithm, $secret, $parts, $encoding);
    }

    /**
     * `timingSafeEqual(Buffer.from(a), Buffer.from(b))`: constant time over the UTF-8 bytes; inputs of
     * different byte lengths throw, as Node does.
     */
    public static function timingSafeEqual(mixed $a, mixed $b): bool
    {
        $a = self::bounded($a, 'timingSafeEqual');
        $b = self::bounded($b, 'timingSafeEqual');
        if (strlen($a) !== strlen($b)) {
            throw new JsError('Input buffers must have the same byte length', 'RangeError');
        }

        return hash_equals($a, $b);
    }

    /** `randomBytes(n).toString(encoding)`, n from 0 to 1024. */
    public static function randomBytes(mixed $bytes, string $encoding): string
    {
        if (!is_int($bytes) && !(is_float($bytes) && floor($bytes) === $bytes)) {
            throw new JsError('The "size" argument must be a whole number', 'RangeError');
        }
        $bytes = (int) $bytes;
        if ($bytes < 0 || $bytes > 1024) {
            throw new JsError('randomBytes takes 0 to 1024 bytes in Zero code', 'RangeError');
        }

        return self::encode($bytes === 0 ? '' : random_bytes($bytes), $encoding);
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

    private static function algorithm(string $algorithm): string
    {
        $name = strtolower($algorithm);
        if (!in_array($name, self::ALGORITHMS, true)) {
            throw new JsError('Digest method not supported', 'Error');
        }

        return $name;
    }

    /** @param list<mixed> $parts */
    private static function joined(array $parts, string $what): string
    {
        $data = '';
        foreach ($parts as $part) {
            $data .= self::bounded($part, "$what.update");
            if (strlen($data) > self::MAX_DATA) {
                throw new HttpError(413, 'crypto_input_too_large', 'The crypto input is too large.');
            }
        }

        return $data;
    }

    private static function encode(string $bytes, string $encoding): string
    {
        return match ($encoding) {
            'hex' => bin2hex($bytes),
            'base64' => base64_encode($bytes),
            'base64url' => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='),
            default => throw new JsError("Unknown encoding: $encoding", 'TypeError'),
        };
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
