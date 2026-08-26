<?php
declare(strict_types=1);

/**
 * Resolve the Space exposure state for a host without serving a version.
 *
 * Content is stored beside WordPress, but its public API still belongs to the
 * Space named by the runtime route pointers. Reusing the serving projection is
 * what makes the existing visitor access engine authoritative here too.
 *
 * @return array{kind: 'present', open: bool, space_id: string, version_id: string, serving: array}|array{kind: 'absent'|'unavailable'}
 */
function _stattic_content_access_target(string $privateRoot, string $requestHost): array
{
    $routesRead = _sf_pointer_read('routes', $privateRoot . '/routes/current.json');
    if ($routesRead['kind'] !== 'present' || !is_array($routesRead['value'])) {
        return ['kind' => $routesRead['kind']];
    }
    $host = _stattic_v4_host_lookup(
        $privateRoot,
        $routesRead['value'],
        _stattic_normalize_hostname($requestHost)
    );
    if ($host === false) {
        return ['kind' => 'unavailable'];
    }
    $hostEntry = is_array($host['entry'] ?? null) ? $host['entry'] : null;
    $spaceId = is_string($hostEntry['space_id'] ?? null) ? $hostEntry['space_id'] : '';
    if ($hostEntry === null || $spaceId === '') {
        return ['kind' => 'absent'];
    }
    $hostAction = is_array($hostEntry['route_action'] ?? null) ? $hostEntry['route_action'] : null;
    if (
        is_array($hostAction)
        && in_array(($hostAction['action'] ?? null), ['tombstone', 'platform_error'], true)
    ) {
        return ['kind' => 'absent'];
    }

    $spaceRead = _sf_pointer_read(
        'space:' . $spaceId,
        $privateRoot . '/spaces/' . $spaceId . '/space.json'
    );
    if ($spaceRead['kind'] !== 'present' || !is_array($spaceRead['value'])) {
        return ['kind' => $spaceRead['kind'] === 'present' ? 'unavailable' : $spaceRead['kind']];
    }
    $overlay = _stattic_v4_overlay($privateRoot, $spaceId, $spaceRead['value']);
    if ($overlay === false || $overlay === null || ($overlay['fence'] ?? null) === 'exposure') {
        return ['kind' => 'unavailable'];
    }
    $versionId = _stattic_v4_version_for_host($hostEntry, $overlay);
    if ($versionId === null) {
        return ['kind' => 'absent'];
    }
    return [
        'kind' => 'present',
        'open' => ($overlay['open'] ?? null) === true,
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'serving' => _stattic_v4_legacy_serving($spaceId, $versionId, $hostEntry, $overlay),
    ];
}

/** Keep only the two host-bound visitor cookies the content access gate reads. */
function _stattic_content_access_cookie_header(string $raw): string
{
    if ($raw === '' || strlen($raw) > 16384) {
        return '';
    }
    $allowed = [
        STATTIC_SESSION_COOKIE,
        STATTIC_SESSION_DEV_COOKIE,
        STATTIC_SYSTEM_VIEW_COOKIE,
        STATTIC_SYSTEM_VIEW_DEV_COOKIE,
    ];
    $forwarded = [];
    foreach (explode(';', $raw) as $part) {
        $pair = explode('=', trim($part), 2);
        $name = $pair[0] ?? '';
        $value = $pair[1] ?? null;
        if (!in_array($name, $allowed, true) || !is_string($value) || strlen($value) > 4096) {
            continue;
        }
        if (preg_match('/[\x00-\x20\x7F;,]/', $value) === 1) {
            continue;
        }
        $forwarded[$name] = $name . '=' . $value;
    }
    return implode('; ', array_values($forwarded));
}

/** Rebuild the platform bearer header after parsing, with a strict size/shape bound. */
function _stattic_content_access_authorization_header(?string $token): string
{
    return is_string($token)
        && strlen($token) >= 16
        && strlen($token) <= 2048
        && preg_match('/^[A-Za-z0-9._~-]+$/D', $token) === 1
        ? 'Bearer ' . $token
        : '';
}
