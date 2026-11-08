<?php

declare(strict_types=1);

namespace Zero\Identity\Providers;

use Zero\Identity\Failure;
use Zero\Identity\Vendor\Firebase\JWT\JWK;
use Zero\Identity\Vendor\Firebase\JWT\JWT;

/**
 * An OpenID Connect relying party for one external provider: discovery, an authorization request
 * with state, nonce and S256 PKCE, the code exchange, ID token verification (RS256, or EdDSA over
 * Ed25519) against the provider's JWKS (issuer, audience/azp, nonce, expiry, at_hash), and
 * UserInfo. `$discovery` overrides single metadata entries; the rest come from the issuer's
 * discovery document, fetched only when an entry is needed. An empty secret is a public client:
 * the code exchange sends no client_secret. Transport is WordPress HTTP; nothing is kept in a PHP
 * session (the caller stores `start()`'s state).
 */
final class OidcClient
{
    private ?array $metadata = null;

    public function __construct(
        private string $issuer,
        private string $clientId,
        private string $secret,
        private string $redirect,
        private ?array $discovery = null,
        private string $algorithm = "RS256",
    ) {
        if (!in_array($algorithm, ["RS256", "EdDSA"], true)) {
            throw new Failure("provider_not_configured", "This sign-in provider uses an unsupported algorithm.", 503);
        }
    }

    /** @return array{url: string, store: array{state: string, nonce: string, verifier: string}} */
    public function start(array $extra = []): array
    {
        $store = [
            "state" => self::random(),
            "nonce" => self::random(),
            "verifier" => self::random(48),
        ];
        $query = [
            "response_type" => "code",
            "client_id" => $this->clientId,
            "redirect_uri" => $this->redirect,
            "scope" => "openid email profile",
            "state" => $store["state"],
            "nonce" => $store["nonce"],
            "code_challenge" => JWT::urlsafeB64Encode(hash("sha256", $store["verifier"], true)),
            "code_challenge_method" => "S256",
        ] + $extra;
        $endpoint = $this->config("authorization_endpoint");
        return [
            "url" => $endpoint . (str_contains($endpoint, "?") ? "&" : "?") . http_build_query($query, "", "&", PHP_QUERY_RFC3986),
            "store" => $store,
        ];
    }

    /**
     * Complete the callback: the returned state must match the stored one, then the code is
     * exchanged, the ID token verified and UserInfo fetched (its `sub` must match).
     *
     * @return array{claims: array, info: array}
     */
    public function finish(array $store, array $query): array
    {
        if (!is_string($query["state"] ?? null) || !hash_equals($store["state"], $query["state"])) {
            throw new Failure("invalid_state", "The provider sign-in expired or is invalid.");
        }
        if (!is_string($query["code"] ?? null)) {
            throw new \RuntimeException("missing code");
        }
        $tokens = $this->json(wp_safe_remote_post($this->config("token_endpoint"), [
            "timeout" => 10,
            "redirection" => 0,
            "headers" => ["Accept" => "application/json"],
            "body" => [
                "grant_type" => "authorization_code",
                "code" => $query["code"],
                "redirect_uri" => $this->redirect,
                "client_id" => $this->clientId,
                "code_verifier" => $store["verifier"],
            ] + ($this->secret === "" ? [] : ["client_secret" => $this->secret]),
        ]));
        if (!is_string($tokens["id_token"] ?? null) || !is_string($tokens["access_token"] ?? null)) {
            throw new \RuntimeException("token response without tokens");
        }
        $claims = $this->verify($tokens["id_token"], $tokens["access_token"], $store["nonce"]);
        $info = $this->json(wp_safe_remote_get($this->config("userinfo_endpoint"), [
            "timeout" => 10,
            "redirection" => 0,
            "headers" => ["Authorization" => "Bearer " . $tokens["access_token"], "Accept" => "application/json"],
        ]));
        if (($info["sub"] ?? null) !== $claims["sub"]) {
            throw new \RuntimeException("userinfo subject mismatch");
        }
        return ["claims" => $claims, "info" => $info];
    }

    private function verify(string $jwt, string $accessToken, string $nonce): array
    {
        $parts = explode(".", $jwt);
        $header = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[0]), true) : null;
        if (
            !is_array($header) ||
            ($header["alg"] ?? null) !== $this->algorithm ||
            !is_string($header["kid"] ?? null) ||
            isset($header["jwk"]) || isset($header["jku"]) || isset($header["x5u"])
        ) {
            throw new \RuntimeException("unacceptable ID token header");
        }
        $jwks = $this->json(wp_safe_remote_get($this->config("jwks_uri"), ["timeout" => 10, "redirection" => 0]));
        $keys = array_values(array_filter(
            is_array($jwks["keys"] ?? null) ? $jwks["keys"] : [],
            fn($k) => is_array($k) && ($k["kid"] ?? null) === $header["kid"]
                && ($this->algorithm === "EdDSA" ? ($k["kty"] ?? null) === "OKP" && ($k["crv"] ?? null) === "Ed25519" : ($k["kty"] ?? null) === "RSA")
                && ($k["alg"] ?? $this->algorithm) === $this->algorithm && ($k["use"] ?? "sig") === "sig",
        ));
        if (count($keys) !== 1) {
            throw new \RuntimeException("no unique signing key");
        }
        JWT::$leeway = 30;
        try {
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(["keys" => $keys], $this->algorithm));
        } finally {
            JWT::$leeway = 0;
        }
        $aud = $claims["aud"] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (
            !in_array($this->clientId, $audiences, true) ||
            (count($audiences) > 1 && ($claims["azp"] ?? null) !== $this->clientId) ||
            (isset($claims["azp"]) && $claims["azp"] !== $this->clientId) ||
            !is_string($claims["iss"] ?? null) || !hash_equals($this->issuer, $claims["iss"]) ||
            !is_string($claims["sub"] ?? null) || $claims["sub"] === "" ||
            !is_int($claims["exp"] ?? null) || !is_int($claims["iat"] ?? null) ||
            !is_string($claims["nonce"] ?? null) || !hash_equals($nonce, $claims["nonce"])
        ) {
            throw new \RuntimeException("ID token claims rejected");
        }
        if (isset($claims["at_hash"])) {
            // The left half of the hash the signature algorithm uses: SHA-256 for RS256, SHA-512 for Ed25519.
            $hash = hash($this->algorithm === "EdDSA" ? "sha512" : "sha256", $accessToken, true);
            if (!is_string($claims["at_hash"]) || !hash_equals(JWT::urlsafeB64Encode(substr($hash, 0, intdiv(strlen($hash), 2))), $claims["at_hash"])) {
                throw new \RuntimeException("at_hash mismatch");
            }
        }
        return $claims;
    }

    private function config(string $key): string
    {
        if (isset($this->discovery[$key])) {
            $value = $this->discovery[$key];
        } else {
            $value = $this->metadata()[$key] ?? null;
        }
        if (!is_string($value) || !wp_http_validate_url($value)) {
            throw new Failure("discovery_invalid", "Provider discovery is missing $key.", 502);
        }
        return $value;
    }

    private function metadata(): array
    {
        if ($this->metadata === null) {
            $metadata = $this->json(wp_safe_remote_get(
                untrailingslashit($this->issuer) . "/.well-known/openid-configuration",
                ["timeout" => 10, "redirection" => 0],
            ));
            if (($metadata["issuer"] ?? null) !== $this->issuer) {
                throw new Failure("issuer_mismatch", "Provider discovery issuer does not match.", 502);
            }
            $this->metadata = $metadata;
        }
        return $this->metadata;
    }

    private function json(mixed $response): array
    {
        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new \RuntimeException("provider HTTP $status");
        }
        return $body;
    }

    private static function random(int $bytes = 32): string
    {
        return JWT::urlsafeB64Encode(random_bytes($bytes));
    }
}
