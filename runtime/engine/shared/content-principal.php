<?php
declare(strict_types=1);

function _stattic_content_principal_profile(mixed $value): ?array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || array_diff(array_keys($value), ['displayName', 'avatarUrl']) !== []) {
        return null;
    }
    $profile = [];
    if (array_key_exists('displayName', $value)) {
        $displayName = trim((string) $value['displayName']);
        if ($displayName === '' || strlen($displayName) > 255) {
            return null;
        }
        $profile['display_name'] = $displayName;
    }
    if (array_key_exists('avatarUrl', $value)) {
        $avatarUrl = trim((string) $value['avatarUrl']);
        if (
            $avatarUrl === ''
            || strlen($avatarUrl) > 2000
            || filter_var($avatarUrl, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }
        $profile['avatar_url'] = $avatarUrl;
    }
    return $profile;
}

/**
 * Validate the authenticated transport's short-lived principal statement.
 * Authorization stays in the management JWT / Grant decision; this statement
 * names only the actor WordPress attributes durable work to.
 */
function _stattic_content_principal_assertion(
    mixed $value,
    string $spaceId,
    string $requestHost,
    ?int $now = null
): ?array {
    if (!is_array($value)) {
        return null;
    }
    $now ??= time();
    $allowed = [
        'format', 'version', 'audience', 'sessionVersion', 'accessGeneration',
        'nonce', 'issuedAt', 'expiresAt', 'wordpressRole', 'actor', 'subject', 'profile',
    ];
    if (array_diff(array_keys($value), $allowed) !== []) {
        return null;
    }
    if (!array_key_exists('wordpressRole', $value)) {
        return null;
    }
    $wordpressRole = $value['wordpressRole'];
    if (
        $wordpressRole !== null
        && !in_array($wordpressRole, ['subscriber', 'editor', 'administrator'], true)
    ) {
        return null;
    }
    $audience = $value['audience'] ?? null;
    $actor = $value['actor'] ?? null;
    if (!is_array($audience) || !is_array($actor)) {
        return null;
    }
    if (
        array_diff(array_keys($audience), ['spaceId', 'runtimeInstanceId', 'host']) !== []
        || array_diff(array_keys($actor), ['kind', 'id']) !== []
    ) {
        return null;
    }
    $issuedAt = $value['issuedAt'] ?? null;
    $expiresAt = $value['expiresAt'] ?? null;
    $sessionVersion = $value['sessionVersion'] ?? null;
    $accessGeneration = $value['accessGeneration'] ?? null;
    $nonce = trim((string) ($value['nonce'] ?? ''));
    $runtimeInstanceId = function_exists('_stattic_runtime_instance_id')
        ? _stattic_runtime_instance_id()
        : '';
    $scheme = function_exists('_stattic_cookies_secure') && !_stattic_cookies_secure()
        ? 'http'
        : 'https';
    $expectedOrigin = $scheme . '://' . strtolower(trim($requestHost));
    if (
        ($value['format'] ?? null) !== 'spacefast.principal'
        || ($value['version'] ?? null) !== 1
        || !is_int($issuedAt)
        || !is_int($expiresAt)
        || !is_int($sessionVersion)
        || $sessionVersion < 0
        || !is_int($accessGeneration)
        || $accessGeneration < 0
        || strlen($nonce) < 16
        || strlen($nonce) > 512
        || $issuedAt > $now + 30
        || $expiresAt <= $now
        || $expiresAt <= $issuedAt
        || $expiresAt - $issuedAt > 300
        || !hash_equals($spaceId, (string) ($audience['spaceId'] ?? ''))
        || $runtimeInstanceId === ''
        || !hash_equals($runtimeInstanceId, (string) ($audience['runtimeInstanceId'] ?? ''))
        || !hash_equals($expectedOrigin, (string) ($audience['host'] ?? ''))
    ) {
        return null;
    }
    $kind = (string) ($actor['kind'] ?? '');
    $id = trim((string) ($actor['id'] ?? ''));
    $profile = _stattic_content_principal_profile($value['profile'] ?? null);
    if ($profile === null) {
        return null;
    }
    if ($kind === 'anonymous') {
        return $id === ''
            && $wordpressRole === null
            && !array_key_exists('subject', $value)
            && !array_key_exists('profile', $value)
            ? [
                'kind' => 'anonymous',
                'session_version' => $sessionVersion,
                'access_generation' => $accessGeneration,
                'nonce' => $nonce,
                'expires_at' => $expiresAt,
                'wordpress_role' => null,
            ]
            : null;
    }
    if (!in_array($kind, ['user', 'service'], true) || $id === '' || strlen($id) > 255) {
        return null;
    }
    $principal = [
        'kind' => $kind,
        'actor_id' => $id,
        'session_version' => $sessionVersion,
        'access_generation' => $accessGeneration,
        'nonce' => $nonce,
        'expires_at' => $expiresAt,
        // Derived from the caller's Grant capabilities by the control plane
        // (wordpressRoleForGrantCapabilities); null means this caller needs no
        // WordPress user at all.
        'wordpress_role' => $wordpressRole,
        'profile' => $profile,
    ];
    if ($kind === 'service') {
        return array_key_exists('subject', $value) ? null : $principal;
    }
    $subject = $value['subject'] ?? null;
    if (!is_array($subject) || array_diff(array_keys($subject), ['issuer', 'subject']) !== []) {
        return null;
    }
    $issuer = trim((string) ($subject['issuer'] ?? ''));
    $subjectId = trim((string) ($subject['subject'] ?? ''));
    if (
        $issuer === ''
        || strlen($issuer) > 255
        || $subjectId === ''
        || strlen($subjectId) > 512
    ) {
        return null;
    }
    $principal['issuer'] = $issuer;
    $principal['subject'] = $subjectId;
    return $principal;
}
