<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/response.php';

// POST /engine/update is a receipt lane retained at its established internal
// URL. FPM cannot launch PHP CLI inside wp.cloud's process namespace. The
// provider's site-scoped WP-CLI task owns installation; this route only proves
// which immutable release FPM is serving.

function _stattic_engine_release_layout_active(string $privateRoot): bool
{
    $installRoot = realpath(dirname($privateRoot));
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    $releaseReal = is_string($releaseRoot) ? realpath($releaseRoot) : false;
    return is_string($installRoot)
        && is_string($releaseReal)
        && str_starts_with($releaseReal, $installRoot . '/releases/');
}

// The alias files are the ONLY engine bytes reinstalled under an unchanged
// path: the loader copies (custom-redirects.php, index.php, the entrypoint
// aliases) plus the resident installer. Releases get fresh directories opcache
// has never seen. Absolute box paths, derived from the private root the route
// already holds.
//
// @return list<string>
function _stattic_engine_update_alias_paths(string $privateRoot): array
{
    $publicRoot = dirname($privateRoot, 2);
    $aliases = [
        '/custom-redirects.php',
        '/index.php',
        '/wp-content/mu-plugins/spacefast-content.php',
    ];
    foreach (array_keys(SPACEFAST_RUNTIME_ENTRYPOINT_PATHS) as $entrypoint) {
        $aliases[] = $entrypoint;
    }
    return array_map(static fn (string $alias): string => $publicRoot . $alias, $aliases);
}

// Drop the rewritten-in-place aliases from THIS process's opcache. The fleet
// runs opcache.validate_timestamps=Off, so FPM keeps executing an alias's OLD
// compiled module forever unless something inside FPM invalidates it. A CLI
// invalidation cannot, since CLI opcache is a different SHM. This request
// IS inside FPM (it is the receipt lane the control plane calls after every
// update), so it invalidates here. Idempotent and cheap; invalidating an
// unchanged alias just recompiles ~one file.
function _stattic_engine_update_invalidate_aliases(string $privateRoot): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    foreach (_stattic_engine_update_alias_paths($privateRoot) as $path) {
        opcache_invalidate($path, true);
    }
}

function _stattic_engine_update_route(string $privateRoot, array $_claims): void
{
    _stattic_engine_update_invalidate_aliases($privateRoot);
    $body = _stattic_json_body();
    $revision = $body['revision'] ?? null;
    if (!is_string($revision) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $revision) !== 1) {
        _stattic_problem_response(422, 'runtime_engine_update_invalid', 'revision is required.');
    }
    $revision = trim($revision);

    if ($revision === SPACEFAST_RUNTIME_ENGINE_REVISION && _stattic_engine_release_layout_active($privateRoot)) {
        _stattic_json_response(200, [
            'status' => 'current',
            'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
            'layout' => 'release',
        ]);
    }

    _stattic_problem_response(409, 'runtime_engine_update_required', 'The requested runtime release is not active.', [
        'details' => [
            'active_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
            'layout' => _stattic_engine_release_layout_active($privateRoot) ? 'release' : 'legacy',
        ],
    ]);
}
