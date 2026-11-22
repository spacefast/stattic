<?php
declare(strict_types=1);

// The person's Space role inside a TypedPress app, lent on each request and
// never written to WordPress.
//
// A TypedPress Identity session lasts up to a day and nothing re-contacts the
// platform during it, so a role stored on the user would outlive a narrowed
// Grant. Instead the engine reads the live Space session before WordPress
// boots (_stattic_typedpress_resolve_visitor in shared/typedpress.php) and this
// file adds that one role to the row the platform keeps for the session's own
// account, for this request only. No Space session, an ended one, a removed
// Grant, or another account in the app: no lent role on that request.
//
// A lent role only ever reaches a platform row, and a platform row holds no
// WordPress password, application password or WordPress session
// (typedpress.php), so no credential made while a role is lent can carry it:
// a later request without the live Space session sees only the row's own
// stored role, which is none.

require_once __DIR__ . '/../shared/space-roles.php';

/**
 * The role lent to this WordPress user on this request, or null. Only the row
 * the platform keeps for the Space session's own account gets it: an
 * app-local account, or anybody else's row, keeps its own stored role.
 */
function spacefast_space_role_lent(int $userId): ?string
{
    $lent = $GLOBALS['SPACEFAST_SPACE_ROLE'] ?? null;
    if ($userId < 1 || !is_array($lent) || !in_array($lent['role'] ?? null, SPACEFAST_SPACE_ROLES, true)) return null;
    $authority = spacefast_content_principal_user_authority($userId);
    if (!is_array($authority) || ($authority['kind'] ?? null) !== 'user'
        || !hash_equals((string) $lent['issuer'], $authority['issuer'])
        || !hash_equals((string) $lent['subject'], $authority['subject'])) {
        return null;
    }
    return $lent['role'];
}

/** `user_has_cap`: the lent role's WordPress capabilities, added for this request. */
function spacefast_space_role_capabilities(array $capabilities, array $required, array $arguments, mixed $user): array
{
    $role = spacefast_space_role_lent(is_object($user) ? (int) ($user->ID ?? 0) : 0);
    if ($role === null || !function_exists('get_role')) return $capabilities;
    $wordpress = get_role(SPACEFAST_SPACE_ROLE_WORDPRESS_ROLES[$role]);
    foreach (is_object($wordpress) && is_array($wordpress->capabilities ?? null) ? $wordpress->capabilities : [] as $capability => $granted) {
        if (is_string($capability) && $granted === true) $capabilities[$capability] = true;
    }
    return $capabilities;
}
