<?php
declare(strict_types=1);

// The one Space role vocabulary on the runtime side: viewer, commenter,
// editor, manager (ACCESS_ROLES in packages/common/src/contracts/access-core.ts,
// generated into space-roles.generated.php with their capabilities and the
// WordPress role each runs as).

require_once __DIR__ . '/space-roles.generated.php';

/** A role's place, weakest first. */
function _stattic_space_role_rank(string $role): int
{
    $rank = array_search($role, SPACEFAST_SPACE_ROLES, true);
    return is_int($rank) ? $rank : -1;
}

/**
 * The Space role a set of Grant capabilities amounts to: the strongest role it
 * reaches, never more (accessRoleForCapabilities in access-core.ts). A role is
 * reached when its strongest capability is held.
 */
function _stattic_space_role_for_capabilities(array $capabilities): string
{
    $role = SPACEFAST_SPACE_ROLES[0];
    foreach (SPACEFAST_SPACE_ROLES as $candidate) {
        if (in_array(array_last(SPACEFAST_SPACE_ROLE_CAPABILITIES[$candidate]), $capabilities, true)) {
            $role = $candidate;
        }
    }
    return $role;
}

/**
 * The runtime half of THE grant→WordPress-role mapping. The other half is
 * wordpressRoleForGrantCapabilities in
 * packages/common/src/contracts/principal-assertion.ts. Both read the one role
 * table (shared/space-roles.generated.php is generated from it), and
 * runtime/tests/content-admin.test.ts runs them side by side.
 *
 * Two callers exist because the two lanes learn the capabilities at different
 * moments. The control plane knows them when it composes a principal assertion
 * for the content API and the editor launch. The WP API door and SpacePress
 * visitor lane have no control plane in the request at all: the capabilities
 * come from the Grant decision the runtime just made for THIS path, so the
 * runtime has to map them itself.
 */
function _stattic_wordpress_role_for_capabilities(array $capabilities): ?string
{
    $role = _stattic_space_role_for_capabilities($capabilities);
    $reads = in_array('page.view', $capabilities, true) || in_array('comments.read', $capabilities, true);
    if (_stattic_space_role_rank($role) <= _stattic_space_role_rank('commenter') && !$reads) {
        return null;
    }
    return SPACEFAST_SPACE_ROLE_WORDPRESS_ROLES[$role];
}
