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
    if (!is_string($installRoot) || !is_string($releaseReal)) {
        return false;
    }
    $pointer = $installRoot . '/active-release';
    if (!is_file($pointer) || is_link($pointer) || (filesize($pointer) ?: 0) > 512) {
        return false;
    }
    $raw = file_get_contents($pointer);
    $target = is_string($raw) ? trim($raw) : '';
    if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1) {
        return false;
    }
    $activeReal = realpath($installRoot . '/' . $target);
    return is_string($activeReal)
        && $activeReal === $releaseReal
        && str_starts_with($releaseReal, $installRoot . '/releases/');
}

function _stattic_engine_file_hash_matches(string $left, string $right): bool
{
    if (!is_file($left) || is_link($left) || !is_file($right) || is_link($right)) {
        return false;
    }
    $leftHash = hash_file('sha256', $left);
    $rightHash = hash_file('sha256', $right);
    return is_string($leftHash) && is_string($rightHash) && hash_equals($leftHash, $rightHash);
}

/** @return null|list<string> */
function _stattic_engine_tree_files(string $root): ?array
{
    if (!is_dir($root) || is_link($root)) {
        return null;
    }
    $files = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return null;
            }
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', substr((string) $item->getPathname(), strlen($root) + 1));
            }
        }
    } catch (Throwable) {
        return null;
    }
    sort($files, SORT_STRING);
    return $files;
}

function _stattic_engine_release_identity(string $releaseRoot): ?string
{
    $files = _stattic_engine_tree_files($releaseRoot);
    if (!is_array($files) || $files === []) {
        return null;
    }
    $digest = hash_init('sha256');
    foreach ($files as $relative) {
        if ($relative === '.payload-identity') {
            continue;
        }
        $path = $releaseRoot . '/' . $relative;
        $size = filesize($path);
        $mode = fileperms($path);
        if (
            !is_int($size)
            || !is_int($mode)
            || !hash_update($digest, $relative . "\0" . ($mode & 07777) . "\0" . $size . "\0")
            || !hash_update_file($digest, $path)
        ) {
            return null;
        }
    }
    return hash_final($digest);
}

/** @return null|array{aliases:list<array{source:string,path:string,executable:bool}>,trees:list<array{source:string,path:string,files:list<string>}>} */
function _stattic_engine_public_manifest(string $releaseRoot): ?array
{
    $manifestPath = $releaseRoot . '/engine-manifest.json';
    $raw = is_file($manifestPath) && !is_link($manifestPath) ? file_get_contents($manifestPath) : false;
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest) || !is_array($manifest['aliases'] ?? null) || !is_array($manifest['trees'] ?? null)) {
        return null;
    }
    $executables = is_array($manifest['executables'] ?? null) ? $manifest['executables'] : [];
    $aliases = [];
    foreach ($manifest['aliases'] as $entry) {
        if (!is_array($entry) || !is_string($entry['source'] ?? null) || !is_string($entry['path'] ?? null)) {
            return null;
        }
        $aliases[] = [
            'source' => $entry['source'],
            'path' => $entry['path'],
            'executable' => in_array($entry['source'], $executables, true),
        ];
    }
    $trees = [];
    foreach ($manifest['trees'] as $entry) {
        if (!is_array($entry) || !is_string($entry['source'] ?? null) || !is_string($entry['path'] ?? null)) {
            return null;
        }
        $files = _stattic_engine_tree_files($releaseRoot . '/' . $entry['source']);
        if (!is_array($files)) {
            return null;
        }
        $trees[] = ['source' => $entry['source'], 'path' => $entry['path'], 'files' => $files];
    }
    usort($aliases, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
    usort($trees, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
    return ['aliases' => $aliases, 'trees' => $trees];
}

function _stattic_engine_loader_identity(string $releaseRoot, array $manifest): ?string
{
    $digest = hash_init('sha256');
    foreach ($manifest['trees'] as $tree) {
        foreach ($tree['files'] as $relative) {
            $source = $releaseRoot . '/' . $tree['source'] . '/' . $relative;
            $size = filesize($source);
            if (!is_int($size) || !hash_update($digest, $tree['path'] . '/' . $relative . "\0" . 0644 . "\0" . $size . "\0") || !hash_update_file($digest, $source)) {
                return null;
            }
        }
    }
    foreach ($manifest['aliases'] as $entry) {
        $source = $releaseRoot . '/' . $entry['source'];
        $size = filesize($source);
        $mode = $entry['executable'] ? 0755 : 0644;
        if (!is_int($size) || !hash_update($digest, $entry['path'] . "\0" . $mode . "\0" . $size . "\0") || !hash_update_file($digest, $source)) {
            return null;
        }
    }
    return hash_final($digest);
}

function _stattic_engine_path_has_link_parent(string $publicRoot, string $relative): bool
{
    $current = $publicRoot;
    $segments = explode('/', $relative);
    array_pop($segments);
    foreach ($segments as $segment) {
        $current .= '/' . $segment;
        if (is_link($current)) {
            return true;
        }
    }
    return false;
}

function _stattic_engine_public_payload_matches(string $releaseRoot, string $publicRoot, array $manifest, string $loaderIdentity): bool
{
    foreach ($manifest['aliases'] as $entry) {
        $target = $publicRoot . '/' . $entry['path'];
        $mode = fileperms($target);
        if (
            _stattic_engine_path_has_link_parent($publicRoot, $entry['path'])
            || !_stattic_engine_file_hash_matches($releaseRoot . '/' . $entry['source'], $target)
            || !is_int($mode)
            || ($mode & 0777) !== ($entry['executable'] ? 0755 : 0644)
        ) {
            return false;
        }
    }
    foreach ($manifest['trees'] as $tree) {
        $target = $publicRoot . '/' . $tree['path'];
        $link = is_link($target) ? readlink($target) : false;
        $prefix = 'spacefast-tree-releases/' . basename($tree['path']) . '/' . $loaderIdentity . '-';
        $store = dirname($target) . '/spacefast-tree-releases/' . basename($tree['path']);
        $resolvedTarget = realpath($target);
        $resolvedStore = realpath($store);
        if (
            _stattic_engine_path_has_link_parent($publicRoot, $tree['path'])
            || !is_string($link)
            || !str_starts_with($link, $prefix)
            || str_contains(substr($link, strlen($prefix)), '/')
            || is_link($store)
            || !is_string($resolvedTarget)
            || !is_string($resolvedStore)
            || !str_starts_with($resolvedTarget, $resolvedStore . '/')
            || _stattic_engine_tree_files($resolvedTarget) !== $tree['files']
        ) {
            return false;
        }
        foreach ($tree['files'] as $relative) {
            $publicFile = $resolvedTarget . '/' . $relative;
            $mode = fileperms($publicFile);
            if (!_stattic_engine_file_hash_matches($releaseRoot . '/' . $tree['source'] . '/' . $relative, $publicFile) || !is_int($mode) || ($mode & 0777) !== 0644) {
                return false;
            }
        }
    }
    return true;
}

function _stattic_engine_installation_proven(string $privateRoot, string $revision, string $nativeSha256): bool
{
    if (!_stattic_engine_release_layout_active($privateRoot)) {
        return false;
    }
    $installRoot = realpath(dirname($privateRoot));
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    $releaseRoot = is_string($releaseRoot) ? realpath($releaseRoot) : false;
    if (!is_string($installRoot) || !is_string($releaseRoot)) {
        return false;
    }
    foreach (['install-transaction.json', 'rollback-failure.json'] as $blocked) {
        if (file_exists($installRoot . '/' . $blocked) || is_link($installRoot . '/' . $blocked)) {
            return false;
        }
    }
    $pointer = trim((string) file_get_contents($installRoot . '/active-release'));
    $manifest = _stattic_engine_public_manifest($releaseRoot);
    $releaseIdentity = _stattic_engine_release_identity($releaseRoot);
    $loaderIdentity = is_array($manifest) ? _stattic_engine_loader_identity($releaseRoot, $manifest) : null;
    if (!is_array($manifest) || !is_string($releaseIdentity) || !is_string($loaderIdentity)) {
        return false;
    }
    $marker = trim((string) @file_get_contents($releaseRoot . '/.payload-identity'));
    $loaderMarker = trim((string) @file_get_contents($installRoot . '/loader-version'));
    $authorityPath = $installRoot . '/release-authorities/' . basename($releaseRoot) . '.json';
    $proofPath = $installRoot . '/active-release-proof.json';
    $authority = !is_link($authorityPath) && is_file($authorityPath)
        ? json_decode((string) file_get_contents($authorityPath), true)
        : null;
    $proof = !is_link($proofPath) && is_file($proofPath)
        ? json_decode((string) file_get_contents($proofPath), true)
        : null;
    $native = $releaseRoot . '/bin/stattic-runtime';
    $actualNativeSha256 = is_file($native) && !is_link($native) ? hash_file('sha256', $native) : false;
    return is_array($authority)
        && is_array($proof)
        && $revision === SPACEFAST_RUNTIME_ENGINE_REVISION
        && ($authority['format'] ?? null) === 'spacefast.runtime.release-authority.v1'
        && ($authority['release'] ?? null) === basename($releaseRoot)
        && ($authority['revision'] ?? null) === $revision
        && ($authority['payload_identity'] ?? null) === $releaseIdentity
        && ($authority['loader_identity'] ?? null) === $loaderIdentity
        && ($proof['format'] ?? null) === 'spacefast.runtime.active-release-proof.v1'
        && ($proof['release'] ?? null) === $pointer
        && ($proof['revision'] ?? null) === $revision
        && ($proof['payload_identity'] ?? null) === $releaseIdentity
        && ($proof['loader_identity'] ?? null) === $loaderIdentity
        && ($proof['native_sha256'] ?? null) === $nativeSha256
        && hash_equals($releaseIdentity, $marker)
        && hash_equals($loaderIdentity, $loaderMarker)
        && is_string($actualNativeSha256)
        && hash_equals($nativeSha256, $actualNativeSha256)
        && _stattic_engine_public_payload_matches($releaseRoot, dirname($privateRoot, 2), $manifest, $loaderIdentity);
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
    $paths = array_map(static fn (string $alias): string => $publicRoot . $alias, $aliases);
    // The engine also aliases whole mu-plugin trees (zero-admin, the Zero
    // dashboard) into place under unchanged paths, so their PHP goes stale in
    // FPM's SHM the same way the loader copies do. Walk what is actually on
    // disk rather than re-deriving the manifest: invalidating an unchanged
    // file is a cheap recompile, missing a changed one is a mixed-version
    // plugin.
    $muRoot = $publicRoot . '/wp-content/mu-plugins';
    if (is_dir($muRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($muRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with((string) $item->getPathname(), '.php')) {
                $paths[] = (string) $item->getPathname();
            }
        }
    }
    return $paths;
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
    $body = _stattic_json_body();
    $revision = $body['revision'] ?? null;
    if (!is_string($revision) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $revision) !== 1) {
        _stattic_problem_response(422, 'runtime_engine_update_invalid', 'revision is required.');
    }
    $revision = trim($revision);
    $nativeSha256 = $body['native_sha256'] ?? null;
    if (!is_string($nativeSha256) || preg_match('/^[a-f0-9]{64}$/', $nativeSha256) !== 1) {
        _stattic_problem_response(422, 'runtime_engine_update_invalid', 'native_sha256 is required.');
    }

    // Serialize the proof with installer publication. If this request started
    // on release A while the CLI installer was switching to B, the shared lock
    // waits for that switch and the pointer check below observes B. If the
    // request wins the lock first, the installer cannot switch until after the
    // receipt has left FPM.
    $lock = $GLOBALS['SPACEFAST_RUNTIME_PUBLICATION_LOCK'] ?? null;
    if (!is_resource($lock)) {
        $lockPath = dirname($privateRoot) . '/publication.lock';
        $lock = is_link($lockPath) ? false : fopen($lockPath, 'ce');
    }
    if (!is_resource($lock) || !flock($lock, LOCK_SH | LOCK_NB)) {
        header('Retry-After: 1', true);
        _stattic_problem_response(503, 'runtime_engine_update_busy', 'The runtime release proof is busy.');
    }
    _stattic_engine_update_invalidate_aliases($privateRoot);

    if (_stattic_engine_installation_proven($privateRoot, $revision, $nativeSha256)) {
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
