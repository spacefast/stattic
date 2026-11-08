<?php
declare(strict_types=1);

/**
 * Which part of app sign-in is on, from the Users settings an engine stores
 * (`_stattic_runtime_users_config`). Every Users gate asks this, never
 * `enabled` alone:
 * - Zero Users: email codes, passkeys and Google through the Space's own client.
 * - Spacefast Access: Sign in with Spacefast, Google through Spacefast's client
 *   and WordPress.com.
 * Settings stored in the flat shape say only `enabled`, which turns both parts
 * on, exactly as before the two-part shape.
 *
 * @return array{zeroUsers: bool, spacefastAccess: bool}
 */
function _stattic_space_users_parts(mixed $users): array
{
    if (!is_array($users) || ($users['enabled'] ?? false) !== true) {
        return ['zeroUsers' => false, 'spacefastAccess' => false];
    }
    $parts = $users['parts'] ?? null;
    if (!is_array($parts)) return ['zeroUsers' => true, 'spacefastAccess' => true];
    return [
        'zeroUsers' => ($parts['zeroUsers'] ?? false) === true,
        'spacefastAccess' => ($parts['spacefastAccess'] ?? false) === true,
    ];
}

/**
 * The sign-in method a Users request asks for, or null when it asks for none.
 * `zeroUsers` methods need Zero Users on; `spacefastAccess` methods need
 * Spacefast Access on. Direct Google is a Zero Users method even when the
 * selector says "google": the stored Google mode decides which part owns it.
 */
function _stattic_space_users_method_part(array $users, string $method): ?string
{
    return match ($method) {
        'email', 'passkey', 'password' => 'zeroUsers',
        'google' => (($users['providers']['google']['mode'] ?? 'disabled') === 'direct') ? 'zeroUsers' : 'spacefastAccess',
        'spacefast', 'gravatar', 'wpcom' => 'spacefastAccess',
        default => null,
    };
}

/** True when the Users settings offer this sign-in method's part. */
function _stattic_space_users_method_allowed(mixed $users, string $method): bool
{
    if (!is_array($users)) return false;
    $part = _stattic_space_users_method_part($users, $method);
    return $part !== null && _stattic_space_users_parts($users)[$part];
}

/**
 * The identity API operation's sign-in method, for the operations that start a
 * sign-in. Account management on an existing session is not a sign-in.
 */
function _stattic_space_users_operation_method(string $operation): ?string
{
    return match (true) {
        $operation === 'sign-in/email', $operation === 'sign-in/verify' => 'email',
        str_starts_with($operation, 'sign-in/passkey/') => 'passkey',
        $operation === 'sign-in/password' => 'password',
        default => null,
    };
}
