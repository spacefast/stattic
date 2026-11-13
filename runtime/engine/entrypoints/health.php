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
    require_once $engineRoot . '/shared/zero-v2.php';
    $serving = _stattic_health_serving($storageRoot, $body['request_hostname']);
    // Only a yes/no for the version the caller already names: the visitor lane
    // withholds the selected version on redirects and platform answers, so
    // health never names it either.
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
    $routesRead = _sf_pointer_read('routes', $privateRoot . '/routes/current.json');
    if ($routesRead['kind'] === 'unavailable') {
        return $none('unavailable');
    }
    $routes = $routesRead['value'];
    if (!is_array($routes) || !isset($routes['gen'])) {
        return $none('undeployed');
    }
    // The visitor lane checks `gen` before the schema too: a pointer without
    // one is "undeployed" there. An out-of-range schema then gets the
    // invariant error, so nothing is being served to anyone.
    if (!_stattic_health_schema_ok($routes['schema'] ?? null)) {
        return $none('unavailable');
    }
    $host = _stattic_v4_host_lookup($privateRoot, $routes, $requestHost);
    if ($host === false) {
        return $none('unavailable');
    }
    if ($host === null) {
        return $none('undeployed');
    }
    $hostEntry = $host['entry'];
    $hostAction = is_array($hostEntry['route_action'] ?? null) ? $hostEntry['route_action'] : null;
    if (is_array($hostAction) && in_array(($hostAction['action'] ?? null), ['tombstone', 'platform_error'], true)) {
        // A CSAM tombstone answers byte-for-byte as a never-deployed host
        // (shared/artifacts.php), so health must not tell the two apart.
        return $none(($hostAction['page_id'] ?? null) === 'tombstone-csam' ? 'undeployed' : 'platform');
    }
    $spaceId = is_string($hostEntry['space_id'] ?? null) ? $hostEntry['space_id'] : '';
    $space = null;
    if ($spaceId !== '') {
        $spaceRead = _sf_pointer_read('space:' . $spaceId, $privateRoot . '/spaces/' . $spaceId . '/space.json');
        if ($spaceRead['kind'] === 'unavailable') {
            return $none('unavailable');
        }
        $space = $spaceRead['value'];
    }
    // `_stattic_v4_overlay` renders the invariant page for a schema this engine
    // cannot read; health answers that as unavailable instead of exiting.
    if (is_array($space) && is_string($space['overlay'] ?? null) && !_stattic_health_schema_ok($space['schema'] ?? null)) {
        return $none('unavailable');
    }
    $overlay = $spaceId === '' || !is_array($space) || !is_string($space['overlay'] ?? null)
        ? null
        : _stattic_v4_include_artifact(_stattic_space_root($privateRoot, $spaceId) . '/' . $space['overlay']);
    if ($overlay === false) {
        return $none('unavailable');
    }
    if (!is_array($overlay) || !is_bool($overlay['open'] ?? null)) {
        $overlay = null;
    } elseif (!_stattic_health_schema_ok($overlay['schema'] ?? null)) {
        return $none('unavailable');
    }
    // The visitor lane denies before selecting a version here, so no version
    // is being served to anyone.
    if ($overlay === null || ($overlay['fence'] ?? null) === 'exposure') {
        return $none('denied');
    }
    $versionId = _stattic_v4_version_for_host($hostEntry, $overlay);
    if ($versionId === null) {
        return $none('pending');
    }
    // The Zero generation cordon refuses some selected versions outright. A
    // compiled Zero v2 version is not live until initialized: its app requests
    // refuse it until `ready.json` exists (a file check, because the selector
    // itself sets request globals).
    $versionRoot = _stattic_version_root($privateRoot, $spaceId, $versionId);
    // The same version root the visitor lane loads (serve.php
    // `_stattic_v4_version_root_artifact`): until it reads as a root with
    // tables, visitors get "version pending"; an unreadable or out-of-schema
    // one gets the unavailable or invariant page.
    $rootState = _stattic_health_version_root_state($versionRoot);
    if ($rootState !== 'ready') {
        return $none($rootState);
    }
    $serving = _stattic_v4_legacy_serving($spaceId, $versionId, $hostEntry, $overlay);
    if (
        _stattic_zero_generation_refusal($versionRoot, $serving) !== null
        || (is_file($versionRoot . '/.zero-compiled/manifest.json') && !is_file($versionRoot . '/.zero-compiled/ready.json'))
    ) {
        return $none('pending');
    }
    return ['state' => 'serving', 'version_id' => $versionId];
}

function _stattic_health_schema_ok(mixed $schema): bool
{
    return is_int($schema)
        && $schema >= STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN
        && $schema <= STATTIC_RUNTIME_ARTIFACT_SCHEMA_MAX;
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
    return _stattic_health_schema_ok($root['schema'] ?? null) ? 'ready' : 'unavailable';
}
