<?php

declare(strict_types=1);

namespace SpacePress\Live;

/**
 * What a subscriber holds between polls: the counters its last answer was computed at, a hash of
 * that answer and when it was computed, sealed with a key only the site has (secretbox, a fresh
 * nonce each time). A subscriber can't read the counters, and every answer carries a new token
 * whether or not anything changed, so a token says nothing about writes the visitor can't read.
 */
final class Token
{
    /** Plaintexts are padded to a multiple of this many bytes. */
    private const PAD = 256;

    /** Longer tokens are not ones this site issued. */
    public const MAX_LENGTH = 2048;

    /** @param array<string, mixed> $claims */
    public static function seal(array $claims): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        // Padded to a multiple of PAD bytes, so a token's length doesn't show how many digits a
        // counter has (it would grow by a character at 10, 100, 1000 writes).
        $plain = sodium_pad((string) json_encode($claims, JSON_UNESCAPED_SLASHES), self::PAD);
        $box = sodium_crypto_secretbox($plain, $nonce, self::key());

        return rtrim(strtr(base64_encode($nonce . $box), '+/', '-_'), '=');
    }

    /** The claims of a token this site sealed, or null for anything else. */
    public static function open(mixed $token): ?array
    {
        if (!is_string($token) || $token === '' || strlen($token) > self::MAX_LENGTH) {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        try {
            $plain = $plain === false ? false : sodium_unpad($plain, self::PAD);
        } catch (\SodiumException) {
            return null;
        }
        $claims = $plain === false ? null : json_decode($plain, true);

        return is_array($claims) ? $claims : null;
    }

    private static function key(): string
    {
        static $key = null;

        return $key ??= hash('sha256', "spacepress-live\0" . wp_salt('auth'), true);
    }
}
