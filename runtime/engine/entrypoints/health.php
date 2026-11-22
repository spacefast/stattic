<?php
declare(strict_types=1);

$engineRoot = dirname(__DIR__);

// No bootstrap-config here: this is the most-polled public path and must not pay
// the persistent-data decrypt.
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/response.php';
$storageRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
_stattic_emit_runtime_identity();

$body = [
    'ok' => true,
    'runtime' => 'stattic-php',
    'schema' => STATTIC_RUNTIME_SCHEMA,
    'engine_version' => SPACEFAST_RUNTIME_ENGINE_VERSION,
    'engine_revision' => SPACEFAST_RUNTIME_ENGINE_REVISION,
    // Where that engine runs from: the site's own release, or the Spacefast chroot.
    'engine_source' => ($GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE'] ?? null) === 'chroot' ? 'chroot' : 'site',
    // Why, from the thin loader's selection, and the revision its channel
    // resolved to: the only outside view of a site that selects the chroot and
    // still runs its own release. A loader older than that rule reports
    // neither.
    'engine_source_reason' => is_string($GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE_REASON'] ?? null)
        ? $GLOBALS['SPACEFAST_RUNTIME_ENGINE_SOURCE_REASON']
        : null,
    'chroot_revision' => is_string($GLOBALS['SPACEFAST_RUNTIME_CHROOT_REVISION'] ?? null)
        ? $GLOBALS['SPACEFAST_RUNTIME_CHROOT_REVISION']
        : null,
    // The contract version of the thin loader that handed this request over;
    // null behind a loader that predates them.
    'loader_version' => is_int($GLOBALS['SPACEFAST_RUNTIME_LOADER_VERSION'] ?? null)
        ? $GLOBALS['SPACEFAST_RUNTIME_LOADER_VERSION']
        : null,
    'site_state' => is_dir($storageRoot) ? 'configured' : 'unconfigured',
    'request_hostname' => _stattic_normalize_host_authority((string) ($_SERVER['HTTP_HOST'] ?? '')),
];

// `?v=<versionId>` also answers whether this hostname serves that version, read
// from the same pointer, host shard and overlay the visitor lane resolves. It
// stops before access and authored routing, so neither a private Space nor a
// redirect-everything rule can hide the answer. Without `v`, health loads no
// serving artifacts at all.
$expectedVersion = $_GET['v'] ?? null;
if ($expectedVersion !== null) {
    if (!is_string($expectedVersion) || !_stattic_id_valid($expectedVersion)) {
        _stattic_problem_response(400, 'invalid_version', 'v must be a version id.', [], ['Cache-Control' => 'no-store']);
    }
    require_once $engineRoot . '/shared/artifacts.php';
    $serving = _stattic_health_serving($storageRoot, $body['request_hostname']);
    // Only a yes/no for the version the caller already names: no response
    // names the version a hostname selects, so health never names it either.
    $body['serving'] = [
        'state' => $serving['state'],
        'version_match' => $serving['version_id'] === $expectedVersion,
        // Health is never cached, so it cannot see the edge; this is inferred
        // from the purge queue. A hostname's record exists from before its
        // serving mutation until the provider accepts its whole-host purge. It
        // does not cover the settle purge 60s later (shared/purge.php), which
        // catches a fill that read the old state just before the mutation.
        // Only for a host that serves something: readiness reads it after the
        // version matches, and on any other host it would tell a recently
        // changed host (a takedown, say) apart from one never deployed.
        'edge_purge_pending' => $serving['state'] === 'serving'
            && _stattic_health_purge_pending($storageRoot, $body['request_hostname']),
    ];
}

_stattic_json_response(200, $body, 'application/json', ['Cache-Control' => 'no-store']);

// The same key shared/purge.php stores under; read without loading the queue.
function _stattic_health_purge_pending(string $privateRoot, string $requestHost): bool
{
    if ($requestHost === '' || preg_match('/\A[a-z0-9.-]{1,253}\z/', $requestHost) !== 1) {
        return false;
    }
    $record = $privateRoot . '/runtime/edge-purges/' . $requestHost . '.json';
    clearstatcache(true, $record);
    // Pending unless the record is verifiably absent: a queue health cannot
    // read must not pass as a settled edge.
    return !_sf_path_verifiably_absent($record);
}

/**
 * What the visitor lane would select for this host, without serving it.
 *
 * @return array{state: 'undeployed'|'unavailable'|'platform'|'denied'|'pending'|'serving', version_id: ?string}
 */
function _stattic_health_serving(string $privateRoot, string $requestHost): array
{
    $none = static fn (string $state): array => ['state' => $state, 'version_id' => null];
    $resolved = _stattic_v4_resolve_host($privateRoot, $requestHost);
    switch ($resolved['kind']) {
        case 'unavailable':
        case 'schema_mismatch':
            return $none('unavailable');
        case 'undeployed':
            return $none('undeployed');
        case 'platform_action':
            // A CSAM tombstone answers byte-for-byte as a never-deployed host
            // (shared/artifacts.php), so health must not tell the two apart.
            return $none(($resolved['action']['page_id'] ?? null) === 'tombstone-csam' ? 'undeployed' : 'platform');
        case 'denied':
            return $none('denied');
        case 'resolved':
            break;
    }
    $versionId = $resolved['version_id'];
    if ($versionId === null) {
        return $none('pending');
    }
    // The Zero generation cordon refuses some selected versions outright. A
    // compiled Zero v2 version is not live until initialized: its app requests
    // refuse it until `ready.json` exists.
    $versionRoot = _stattic_version_root($privateRoot, $resolved['space_id'], $versionId);
    // The same version root the visitor lane loads (serve.php
    // `_stattic_v4_version_root_artifact`): until it reads as a root with
    // tables, visitors get "version pending"; an unreadable or out-of-schema
    // one gets the unavailable or invariant page.
    $rootState = _stattic_health_version_root_state($versionRoot);
    if ($rootState !== 'ready') {
        return $none($rootState);
    }
    $serving = _stattic_v4_legacy_serving($resolved['space_id'], $versionId, $resolved['host_entry'], $resolved['overlay']);
    $generation = is_string($serving['zero_generation'] ?? null) ? $serving['zero_generation'] : 'none';
    $hasSpacepress = is_file($versionRoot . '/.spacepress-compiled/manifest.json');
    $hasZeroCompiled = is_file($versionRoot . '/.zero-compiled/manifest.json');
    $isSpacepress = false;
    if ($hasSpacepress || $hasZeroCompiled) {
        require_once dirname(__DIR__) . '/shared/spacepress.php';
        $spRoot = _stattic_spacepress_version_root($versionRoot);
        if ($spRoot !== null) {
            $isSpacepress = true;
            if (in_array($generation, ['legacy_0_8', 'v2'], true) || !is_file($spRoot . '/ready.json')) {
                return $none('pending');
            }
        }
    }
    if (
        !$isSpacepress
        && ($hasZeroCompiled
            || in_array($generation, ['legacy_0_8', 'v2'], true)
            || is_file($versionRoot . '/zero/config.json')
            || is_file($versionRoot . '/zero/routes.php')
            || is_file($versionRoot . '/zero/capsule.json')
            || is_dir($versionRoot . '/zero/actions')
            || is_file($versionRoot . '/zero/endpoints-index.json')
            || is_file($versionRoot . '/zero/runs-index.json'))
    ) {
        require_once dirname(__DIR__) . '/deprecated/zero-v2/zero-v2.php';
        if (
            _stattic_zero_generation_refusal($versionRoot, $serving) !== null
            || _stattic_zero_v2_version_state($versionRoot) === 'uninitialized'
        ) {
            return $none('pending');
        }
    }
    return ['state' => 'serving', 'version_id' => $versionId];
}

/** @return 'ready'|'pending'|'unavailable' */
function _stattic_health_version_root_state(string $versionRoot): string
{
    $pointerRead = _sf_pointer_read('version-root:' . basename($versionRoot), $versionRoot . '/root.json');
    if ($pointerRead['kind'] === 'unavailable') {
        return 'unavailable';
    }
    $pointer = $pointerRead['value'];
    if (!is_array($pointer) || !is_string($pointer['root'] ?? null)) {
        return 'pending';
    }
    $root = _stattic_v4_include_artifact($versionRoot . '/' . $pointer['root']);
    if ($root === false) {
        return 'unavailable';
    }
    if (!is_array($root) || !is_array($root['tables'] ?? null)) {
        return 'pending';
    }
    return _stattic_v4_schema_valid($root['schema'] ?? null) ? 'ready' : 'unavailable';
}
