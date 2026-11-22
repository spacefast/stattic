<?php
declare(strict_types=1);

/** Resolve the selected version's sealed model without following editor state. */
function _stattic_content_version_model_revision(string $privateRoot, string $spaceId, string $versionId): ?string
{
    require_once __DIR__ . '/storage.php';
    $catalog = _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
    $entry = $catalog['paths']['_spacefast/pages/documents/model.json']['source'] ?? null;
    $object = _stattic_runtime_catalog_object($entry);
    $sha = $object['sha'] ?? null;
    $length = $object['size'] ?? null;
    if (!is_string($sha) || !is_int($length) || $length < 1 || $length > 256) {
        return null;
    }
    $bytes = _stattic_v4_blob_contents(['private_root' => $privateRoot, 'space_id' => $spaceId], $sha);
    if (!is_string($bytes) || strlen($bytes) !== $length
        || !hash_equals(preg_replace('/^sha256:/', '', $sha), hash('sha256', $bytes))) {
        return null;
    }
    $reference = json_decode($bytes, true);
    $revision = is_array($reference) ? ($reference['revision'] ?? null) : null;
    if (!is_string($revision) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $revision) !== 1) {
        return null;
    }
    $model = $privateRoot . '/spaces/' . $spaceId . '/content-model/releases/' . substr($revision, 7) . '/content-model.php';
    return is_file($model) ? $revision : null;
}

/**
 * Resolve the Space exposure state for a host without serving a version.
 *
 * Content is stored beside WordPress, but its public API still belongs to the
 * Space named by the runtime route pointers. Reusing the serving projection is
 * what makes the existing visitor access engine authoritative here too.
 *
 * `absent` carries `hold`: the platform's own route_action for the host when
 * the platform has taken the host over ('tombstone' / 'platform_error'), null
 * when the host simply names no Space or no version. The two are the same
 * answer to a visitor and very different answers to the editor lane, which
 * serves an unpublished Space on purpose but must never stay editable behind a
 * platform hold.
 *
 * `denied` marks an exposure fence or missing overlay (matching serve.php and
 * health.php) so callers return 403 instead of misclassifying as 503.
 *
 * @return array{kind: 'present', open: bool, space_id: string, version_id: string, serving: array}
 *        |array{kind: 'absent', hold: ?string}
 *        |array{kind: 'denied'}
 *        |array{kind: 'unavailable'}
 */
function _stattic_content_access_target(string $privateRoot, string $requestHost): array
{
    $resolved = _stattic_v4_resolve_host($privateRoot, _stattic_normalize_hostname($requestHost));
    switch ($resolved['kind']) {
        case 'unavailable':
        case 'schema_mismatch':
            return ['kind' => 'unavailable'];
        case 'undeployed':
            return ['kind' => 'absent', 'hold' => null];
        case 'platform_action':
            return ['kind' => 'absent', 'hold' => (string) $resolved['action']['action']];
        case 'denied':
            return ['kind' => 'denied'];
        case 'resolved':
            if ($resolved['version_id'] === null) {
                return ['kind' => 'absent', 'hold' => null];
            }
            return [
                'kind' => 'present',
                'open' => $resolved['open'],
                'space_id' => $resolved['space_id'],
                'version_id' => $resolved['version_id'],
                'serving' => _stattic_v4_legacy_serving(
                    $resolved['space_id'],
                    $resolved['version_id'],
                    $resolved['host_entry'],
                    $resolved['overlay']
                ),
            ];
    }
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

/** A declared application route owns the request before WordPress's default routes. */
function _stattic_content_deployment_claims_path(string $privateRoot, string $host, string $path, string $method, ?array $target = null): bool
{
    require_once __DIR__ . '/../runtime/serve.php';
    $target ??= _stattic_content_access_target($privateRoot, $host);
    if ($target['kind'] !== 'present') {
        return false;
    }
    $versionDir = _stattic_version_root($privateRoot, $target['space_id'], $target['version_id']);
    $root = _stattic_v4_version_root_artifact($versionDir, $target['serving']['route_name'] ?? null);
    if ($root === null) {
        return false;
    }
    if (_stattic_v4_entry($versionDir, $root, $path) !== null) {
        return true;
    }
    if (_stattic_version_has_functions($versionDir)) {
        require_once __DIR__ . '/../runtime/functions-dispatch.php';
        if (_stattic_resolve_functions_route_action($versionDir, ltrim($path, '/'), $method) !== null) {
            return true;
        }
    }
    require_once __DIR__ . '/../runtime/zero-routes.php';
    if (_stattic_legacy_zero_path_has_route($versionDir, $path, $method)) return true;
    $rulesEntry = _stattic_v4_entry($versionDir, $root, STATTIC_RUNTIME_RESPONSE_KEY_RULES);
    $redirects = _stattic_v4_rule_section($rulesEntry, 'redirects');
    if ($redirects !== null) {
        require_once __DIR__ . '/../runtime/redirects.php';
        return _stattic_for_each_ordered_rule($redirects, $path, static function (array $rule, bool $exact) use ($path, $host): ?bool {
            // A generic SPA rewrite is a fallback; an explicit redirect still owns its path.
            if (($rule['action'] ?? 'redirect') === 'rewrite' && ($rule['regex'] ?? '') === '^/(.*)$') {
                return null;
            }
            $matches = [];
            return _stattic_ordered_rule_request_matches($rule, $exact, $path, $host, $matches)
                && _stattic_redirect_query_matches($rule['query'] ?? null, $matches)
                && _stattic_redirect_conditions_match($rule['conditions'] ?? []) ? true : null;
        }) === true;
    }
    return false;
}
