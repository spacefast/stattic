<?php

declare(strict_types=1);

namespace Zero\Auth;

/**
 * The issuer's half of feature 4 (§6.2): zero-identity, when it is installed. Token responses are
 * `['accessToken', 'refreshToken', 'expiresIn', 'user' => User, 'clientId']`; sign-in, refresh and
 * guest upgrades happen on the issuer's own endpoints and screens.
 */
interface Issuer
{
    /** `GET /auth/config`. */
    public function config(bool $guests): array;

    /** A new guest user (role `guest`) signed in. */
    public function guest(): array;

    /** End the current session's sign-in. */
    public function signOut(array $user): void;

    /** True where emails are verified by the issuer (then members can't set their own directly). */
    public function verifiesEmail(): bool;
}
