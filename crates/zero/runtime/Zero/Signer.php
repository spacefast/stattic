<?php

declare(strict_types=1);

namespace Zero;

/** HMAC-SHA256 signatures for short-lived URLs (phase 2 §7.2): sig = hmac(secret, message), hex. */
final class Signer
{
    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new \LogicException('Signer needs a non-empty secret');
        }
    }

    public function sign(string $message): string
    {
        return hash_hmac('sha256', $message, $this->secret);
    }

    /** Constant-time check; anything but 64 lowercase hex characters is invalid. */
    public function verify(string $message, mixed $sig): bool
    {
        return is_string($sig) && preg_match('/^[0-9a-f]{64}$/D', $sig) === 1 && hash_equals($this->sign($message), $sig);
    }
}
