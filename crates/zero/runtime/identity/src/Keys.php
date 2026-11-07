<?php

declare(strict_types=1);

namespace Zero\Identity;

use Zero\Identity\Vendor\Firebase\JWT\ExpiredException;
use Zero\Identity\Vendor\Firebase\JWT\JWT;
use Zero\Identity\Vendor\Firebase\JWT\Key;

/** RS256 signing keys: sealed private keys in an option, ten-minute overlap after rotation. */
final class Keys
{
    private const OPTION = "zero_identity_signing_keys";

    public static function rotate(): void
    {
        $resource = openssl_pkey_new([
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false || !openssl_pkey_export($resource, $private)) {
            throw new Failure(
                "key_generation_failed",
                "Signing key generation failed.",
                500,
            );
        }
        $details = openssl_pkey_get_details($resource);
        $keys = get_option(self::OPTION, []);
        foreach ($keys as &$key) {
            if (!isset($key["retire_at"])) {
                $key["retire_at"] = time() + 600;
            }
        }
        unset($key);
        $keys = array_filter(
            $keys,
            fn($k) => ($k["retire_at"] ?? PHP_INT_MAX) > time(),
        );
        array_unshift($keys, [
            "kid" => bin2hex(random_bytes(12)),
            "private" => Security::seal($private),
            "public" => $details["key"],
            "n" => JWT::urlsafeB64Encode($details["rsa"]["n"]),
            "e" => JWT::urlsafeB64Encode($details["rsa"]["e"]),
        ]);
        update_option(self::OPTION, array_values($keys), false);
    }

    public static function current(): array
    {
        $keys = get_option(self::OPTION, []);
        if (!$keys) {
            self::rotate();
            $keys = get_option(self::OPTION);
        }
        return $keys[0];
    }

    /** Sign claims with the current key; the header carries its kid. */
    public static function sign(array $claims): string
    {
        $key = self::current();
        return JWT::encode(
            $claims,
            Security::open($key["private"]),
            "RS256",
            $key["kid"],
        );
    }

    /**
     * Verify an RS256 JWT issued here: a live key by kid, signature, exp/nbf/iat, and `iss`.
     * Returns the claims, or null for anything else. `$expired` accepts an expired but otherwise
     * valid token (RP-initiated logout's id_token_hint); the signature is checked before expiry.
     */
    public static function verify(string $jwt, bool $expired = false): ?array
    {
        $keys = [];
        foreach (self::live() as $k) {
            $keys[$k["kid"]] = new Key($k["public"], "RS256");
        }
        try {
            $claims = (array) JWT::decode($jwt, $keys);
        } catch (ExpiredException $e) {
            if (!$expired) {
                return null;
            }
            $claims = (array) $e->getPayload();
        } catch (\Throwable) {
            return null;
        }
        return ($claims["iss"] ?? null) === OAuth::issuer() ? $claims : null;
    }

    public static function jwks(): array
    {
        self::current();
        return [
            "keys" => array_map(
                fn($k) => [
                    "kty" => "RSA",
                    "use" => "sig",
                    "alg" => "RS256",
                    "kid" => $k["kid"],
                    "n" => $k["n"],
                    "e" => $k["e"],
                ],
                self::live(),
            ),
        ];
    }

    /** @return list<array> */
    private static function live(): array
    {
        return array_values(
            array_filter(
                get_option(self::OPTION, []),
                fn($k) => ($k["retire_at"] ?? PHP_INT_MAX) > time(),
            ),
        );
    }
}
