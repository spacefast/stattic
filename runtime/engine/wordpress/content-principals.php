<?php
declare(strict_types=1);

if (function_exists('add_filter')) {
    add_filter('determine_current_user', 'spacefast_content_principal_current_user', 1);
    add_filter('user_has_cap', 'spacefast_content_principal_capabilities', 10, 4);
    add_action('plugins_loaded', 'spacefast_content_principal_establish_user', 1);
}

/**
 * The platform's canonical name for an (issuer, subject) pair — the SAME string
 * the access layer keys visitor sessions and Grant audiences by. Derived
 * identically in apps/control-plane/src/access/authority-generation.ts
 * (externalAuthorityReference) and runtime/engine/runtime/access-rules.php
 * (_stattic_grant_audience_reference); the three must change together, and
 * runtime/tests/content-kernel.test.ts runs the last two side by side.
 *
 * Keying WordPress principals by it is what makes a person one durable user
 * per site: whichever door they came through, if the Grant layer calls them the
 * same authority, WordPress attributes their work to the same account.
 */
function spacefast_content_principal_authority(string $issuer, string $subject): string
{
    if ($issuer === 'spacefast-membership') {
        return 'member:' . $subject;
    }
    if ($issuer === 'claim-preview') {
        return 'claim-preview:' . $subject;
    }
    return 'external:' . hash('sha256', $issuer . "\0" . $subject);
}

/** The role the Grant decision projects for THIS request, or null for none. */
function spacefast_content_principal_role(): ?string
{
    $role = $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] ?? null;
    return in_array($role, ['subscriber', 'editor', 'administrator'], true) ? $role : null;
}

function spacefast_content_principal_identity(): ?array
{
    $principal = $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null;
    if (!is_array($principal)) {
        return null;
    }
    $kind = (string) ($principal['kind'] ?? '');
    if ($kind === 'anonymous') {
        return ['kind' => 'anonymous', 'principal_id' => null];
    }
    if (!in_array($kind, ['user', 'service'], true)) {
        return null;
    }
    // A caller whose Grants earn no WordPress role needs no WordPress user:
    // `comments.write` alone is answered by the platform, not by this site.
    if (spacefast_content_principal_role() === null) {
        return ['kind' => 'anonymous', 'principal_id' => null];
    }
    $actorId = trim((string) ($principal['actor_id'] ?? ''));
    if ($actorId === '') {
        return null;
    }
    if ($kind === 'user') {
        $issuer = trim((string) ($principal['issuer'] ?? ''));
        $subject = trim((string) ($principal['subject'] ?? ''));
        if ($issuer === '' || $subject === '') {
            return null;
        }
    } else {
        // Services are their own issuer: an API key is not a person, and must
        // never collide with an identity provider's subject namespace.
        $issuer = 'spacefast-service';
        $subject = $actorId;
    }
    return [
        'kind' => $kind,
        'actor_id' => $actorId,
        'issuer' => $issuer,
        'subject' => $subject,
        'principal_id' => spacefast_content_principal_authority($issuer, $subject),
        'profile' => is_array($principal['profile'] ?? null) ? $principal['profile'] : [],
    ];
}

function spacefast_content_principal_current_user(mixed $currentUser): mixed
{
    if (is_int($currentUser) && $currentUser > 0) {
        return $currentUser;
    }
    $sessionUserId = $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null;
    if (is_int($sessionUserId) && $sessionUserId > 0) {
        return $sessionUserId;
    }
    $identity = spacefast_content_principal_identity();
    if ($identity === null || $identity['kind'] === 'anonymous') {
        return $currentUser;
    }
    $userId = spacefast_content_principal_ensure_user($identity);
    return $userId > 0 ? $userId : $currentUser;
}

/** The durable authority stamped on a WordPress user, or null for a user that carries none. */
function spacefast_content_principal_user_authority(int $userId): ?array
{
    if ($userId < 1 || !function_exists('get_user_meta')) {
        return null;
    }
    $kind = (string) get_user_meta($userId, '_spacefast_principal_kind', true);
    return $kind === '' ? null : [
        'kind' => $kind,
        'issuer' => (string) get_user_meta($userId, '_spacefast_principal_issuer', true),
        'subject' => (string) get_user_meta($userId, '_spacefast_principal_subject', true),
    ];
}

/**
 * Find the WordPress user already established for an authority, or 0 for none.
 * Read-only: the link option is the durable pointer, and a login-keyed lookup
 * recovers an insert that committed before its option did. Lets a caller decide
 * how to treat an existing row before ensure_user would find-or-create it.
 */
function spacefast_content_principal_find_user(string $principalId, string $issuer, string $subject): int
{
    if (!function_exists('get_user_by')) {
        return 0;
    }
    $link = function_exists('get_option')
        ? get_option('spacefast_principal_' . substr(hash('sha256', $principalId), 0, 40), null)
        : null;
    $linkedUserId = is_array($link)
        && hash_equals($principalId, (string) ($link['principal_id'] ?? ''))
        && hash_equals($issuer, (string) ($link['issuer'] ?? ''))
        && hash_equals($subject, (string) ($link['subject'] ?? ''))
        ? (int) ($link['user_id'] ?? 0)
        : 0;
    $user = $linkedUserId > 0 ? get_user_by('id', $linkedUserId) : false;
    if (!is_object($user)) {
        $user = get_user_by('login', 'spacefast_' . substr(hash('sha256', $principalId), 0, 32));
    }
    return is_object($user) ? (int) ($user->ID ?? 0) : 0;
}

/**
 * THE place a WordPress user comes into existence for an authority, and the
 * reason one person is one account here: the (issuer, subject) pair keys the
 * lookup, so the request-time lane above and the users feature's create
 * Ability (engine/wordpress/content-users.php) converge on the same row rather
 * than opening two doors to two accounts.
 *
 * `$syncProfile` is the request-time lane's licence to refresh an established
 * user's display name from the identity provider it just authenticated. The
 * create Ability passes false: it may name a user it inserts, but it never
 * rewrites the profile of a row that already exists.
 *
 * Returns 0 when no user could be established; callers keep their own fallback.
 */
function spacefast_content_principal_ensure_user(array $identity, bool $syncProfile = true): int
{
    if (!function_exists('get_user_by') || !function_exists('wp_insert_user')) {
        return 0;
    }
    $principalId = (string) $identity['principal_id'];
    $login = 'spacefast_' . substr(hash('sha256', $principalId), 0, 32);
    $linkOption = 'spacefast_principal_' . substr(hash('sha256', $principalId), 0, 40);
    $profile = is_array($identity['profile'] ?? null) ? $identity['profile'] : [];
    // A caller-supplied name and the synthesized default are kept apart: the
    // default names a user only at insert, and never overwrites an established
    // user's name (a create call that omits displayName must not reset it).
    $providedName = trim((string) ($profile['display_name'] ?? ''));
    $displayName = $providedName !== ''
        ? $providedName
        : ($identity['kind'] === 'service' ? 'Spacefast service' : 'Spacefast user');
    $existingId = spacefast_content_principal_find_user(
        $principalId,
        (string) $identity['issuer'],
        (string) $identity['subject']
    );
    $user = $existingId > 0 ? get_user_by('id', $existingId) : false;
    if (!is_object($user)) {
        $created = wp_insert_user([
            'user_login' => $login,
            'user_pass' => bin2hex(random_bytes(32)),
            'display_name' => $displayName,
            // WordPress otherwise assigns default_role (normally subscriber).
            // Durable identity stores no authorization role.
            'role' => '',
        ]);
        if (function_exists('is_wp_error') && is_wp_error($created)) {
            $code = method_exists($created, 'get_error_code')
                ? preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $created->get_error_code())
                : '';
            error_log('spacefast content principal unavailable code=' . ($code === '' ? 'unknown' : $code));
            return 0;
        }
        $user = get_user_by('id', (int) $created);
    }
    if (!is_object($user) || (int) ($user->ID ?? 0) < 1) {
        return 0;
    }
    if (
        function_exists('wp_update_user')
        && $syncProfile
        && $providedName !== ''
        && ($user->display_name ?? '') !== $providedName
    ) {
        wp_update_user([
            'ID' => (int) $user->ID,
            'display_name' => $providedName,
        ]);
    }
    $userId = (int) $user->ID;
    if (function_exists('update_user_meta')) {
        update_user_meta($userId, '_spacefast_principal_id', $principalId);
        update_user_meta($userId, '_spacefast_principal_kind', $identity['kind']);
        update_user_meta($userId, '_spacefast_principal_issuer', $identity['issuer']);
        update_user_meta($userId, '_spacefast_principal_subject', $identity['subject']);
        if (isset($profile['avatar_url'])) {
            update_user_meta($userId, '_spacefast_principal_avatar_url', $profile['avatar_url']);
        } elseif (function_exists('delete_user_meta')) {
            delete_user_meta($userId, '_spacefast_principal_avatar_url');
        }
    }
    if (function_exists('update_option')) {
        update_option($linkOption, [
            'version' => 1,
            'principal_id' => $principalId,
            'kind' => $identity['kind'],
            'issuer' => $identity['issuer'],
            'subject' => $identity['subject'],
            'user_id' => $userId,
        ], false);
    }
    // The durable principal exists for every Space, always; the users feature
    // is what makes this person one of THIS Space's users. Guarded because the
    // substrate must keep working with the feature absent.
    if (function_exists('spacefast_content_users_join_space')) {
        spacefast_content_users_join_space($userId);
    }
    return $userId;
}

/**
 * Project the already-admitted Grant decision into WordPress for this request.
 * No stored WordPress role is authority, and the principal assertion carries
 * no capability names.
 */
function spacefast_content_principal_capabilities(
    array $allCapabilities,
    array $requiredCapabilities,
    array $arguments,
    mixed $user
): array {
    // WordPress runs this filter for whichever user's capabilities are being
    // tested, not only the request's own. The Grant decision projected here
    // belongs to THIS request's principal, so applying it to another user would
    // hand that user the caller's capabilities. `spacefast_content_principal_
    // establish_user` makes the principal the current user on plugins_loaded,
    // so identity is the check; before that the current user is 0 and declining
    // to project is the safe direction. Unreachable inside today's screen jail,
    // but an authorization bug the moment a screen tests a second user's caps.
    $subjectId = is_object($user) ? (int) ($user->ID ?? 0) : 0;
    if (
        $subjectId < 1
        || !function_exists('get_current_user_id')
        || $subjectId !== get_current_user_id()
    ) {
        return $allCapabilities;
    }
    $roleName = spacefast_content_principal_role();
    if ($roleName === null || !function_exists('get_role')) {
        return $allCapabilities;
    }
    $role = get_role($roleName);
    $capabilities = is_object($role) && is_array($role->capabilities ?? null)
        ? $role->capabilities
        : [];
    foreach ($capabilities as $capability => $granted) {
        if (is_string($capability) && $granted === true) {
            $allCapabilities[$capability] = true;
        }
    }
    return $allCapabilities;
}

function spacefast_content_principal_establish_user(): int
{
    $userId = spacefast_content_principal_current_user(0);
    if (!is_int($userId) || $userId < 1 || !function_exists('wp_set_current_user')) {
        return 0;
    }
    $user = wp_set_current_user($userId);
    return is_object($user) && (int) ($user->ID ?? 0) === $userId ? $userId : 0;
}

function spacefast_content_admin_auth_cookies(int $userId, int $ttlSeconds): ?array
{
    if (
        $userId < 1
        || $ttlSeconds < 1
        || !defined('SECURE_AUTH_COOKIE')
        || !defined('LOGGED_IN_COOKIE')
        || !class_exists('WP_Session_Tokens')
        || !function_exists('wp_generate_auth_cookie')
    ) {
        return null;
    }
    $expiration = time() + $ttlSeconds;
    $manager = WP_Session_Tokens::get_instance($userId);
    $token = is_object($manager) && method_exists($manager, 'create')
        ? $manager->create($expiration)
        : '';
    if (!is_string($token) || $token === '') {
        return null;
    }
    $secure = wp_generate_auth_cookie($userId, $expiration, 'secure_auth', $token);
    $loggedIn = wp_generate_auth_cookie($userId, $expiration, 'logged_in', $token);
    if (!is_string($secure) || $secure === '' || !is_string($loggedIn) || $loggedIn === '') {
        return null;
    }
    return [
        ['name' => SECURE_AUTH_COOKIE, 'value' => $secure],
        ['name' => LOGGED_IN_COOKIE, 'value' => $loggedIn],
    ];
}
