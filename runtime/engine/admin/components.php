<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/finalizer-protocol.generated.php';

function _stattic_component_canonical_json(mixed $value): string
{
    if (is_array($value)) {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        $encoded = [];
        foreach ($value as $key => $entry) {
            $encoded[$key] = json_decode(_stattic_component_canonical_json($entry), true);
        }
        return json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function _stattic_component_digest(mixed $value): string
{
    return 'sha256:' . hash('sha256', _stattic_component_canonical_json($value));
}

function _stattic_component_tree_digest(string $root): ?string
{
    if (!is_dir($root)) {
        return null;
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile()) {
            $files[] = $entry->getPathname();
        }
    }
    sort($files, SORT_STRING);
    $hash = hash_init('sha256');
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file, strlen(rtrim($root, '/')) + 1));
        $fileHash = hash_file('sha256', $file);
        if (!is_string($fileHash)) {
            return null;
        }
        hash_update($hash, $relative . "\0" . $fileHash . "\0");
    }
    return 'sha256:' . hash_final($hash);
}

function _stattic_component_lock(string $engineRoot): ?array
{
    $raw = @file_get_contents($engineRoot . '/wordpress/components.json');
    $lock = is_string($raw) ? json_decode($raw, true) : null;
    if (
        !is_array($lock)
        || ($lock['format'] ?? null) !== 'spacefast.wordpress-components'
        || ($lock['version'] ?? null) !== 1
        || !is_string($lock['platformRelease'] ?? null)
        || !is_string($lock['digest'] ?? null)
        || !is_array($lock['components'] ?? null)
    ) {
        return null;
    }
    $meaning = $lock;
    unset($meaning['digest']);
    return hash_equals(_stattic_component_digest($meaning), $lock['digest']) ? $lock : null;
}

function _stattic_component_problem(array &$problems, ?string $componentId, string $code, string $detail): void
{
    $problem = ['code' => $code, 'detail' => $detail];
    if (is_string($componentId)) {
        $problem['componentId'] = $componentId;
    }
    $problems[] = $problem;
}

function _stattic_component_expected(array $lock, string $id): ?array
{
    foreach ($lock['components'] as $component) {
        if (is_array($component) && ($component['id'] ?? null) === $id) {
            return $component;
        }
    }
    return null;
}

/**
 * The Space a stage request targets, or null for a warm pool box.
 *
 * A receipt names exactly one row: `spaceId` for a Space, `sitePoolId` for a
 * box nobody owns yet.
 */
function _stattic_component_target_space(array $target): ?string
{
    $spaceId = $target['spaceId'] ?? null;
    return is_string($spaceId) && $spaceId !== '' ? $spaceId : null;
}

function _stattic_component_boot_wordpress(string $privateRoot, array $target, array &$problems): bool
{
    $spaceId = _stattic_component_target_space($target);
    if (is_string($spaceId)) {
        $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    }
    $publicRoot = dirname(dirname($privateRoot));
    $wpLoad = $publicRoot . '/wp-load.php';
    if (!is_file($wpLoad)) {
        _stattic_component_problem($problems, 'wordpress-core', 'wordpress_boot_missing', 'WordPress bootstrap is missing.');
        return false;
    }
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    require_once $wpLoad;
    if (!function_exists('get_option')) {
        _stattic_component_problem($problems, 'wordpress-core', 'wordpress_boot_failed', 'WordPress did not finish booting.');
        return false;
    }
    return true;
}

function _stattic_component_check_embedded(
    array $lock,
    string $id,
    string $path,
    array &$problems,
    bool $tree = false,
): void {
    $expected = _stattic_component_expected($lock, $id);
    $actual = $tree
        ? _stattic_component_tree_digest($path)
        : (is_file($path) ? 'sha256:' . hash_file('sha256', $path) : null);
    if (!is_array($expected) || !is_string($actual) || !hash_equals((string) ($expected['sha256'] ?? ''), $actual)) {
        _stattic_component_problem($problems, $id, 'component_digest_mismatch', 'Installed component bytes do not match the platform lock.');
    }
}

/** The installed plugin whose text domain matches, as `[file, plugin]`. */
function _stattic_component_installed_plugin(array $plugins, string $textDomain): ?array
{
    foreach ($plugins as $file => $plugin) {
        if (is_array($plugin) && ($plugin['TextDomain'] ?? null) === $textDomain && is_string($file)) {
            return [$file, $plugin];
        }
    }
    return null;
}

/**
 * Reconcile the locked WordPress plugins and report the on-demand ones running.
 *
 * The always-on plugins are pinned to an exact version and activated here.
 * Jetpack is not: wp.cloud installs it from its own managed track, only for
 * Spaces whose team carries the feature, so its absence is a normal state and
 * its version moves under us. It is observed and reported, never activated —
 * activating it here would connect a site the licensing lane never approved.
 */
function _stattic_component_plugins(array $lock, array &$problems): array
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = function_exists('get_plugins') ? get_plugins() : [];
    $wanted = [
        'secure-custom-fields' => 'secure-custom-fields',
        'redirection' => 'redirection',
        'block-transformer' => 'blocks-engine-php-transformer',
    ];
    foreach ($wanted as $componentId => $textDomain) {
        $expected = _stattic_component_expected($lock, $componentId);
        $installed = _stattic_component_installed_plugin($plugins, $textDomain);
        if (!is_array($expected) || !is_array($installed)) {
            _stattic_component_problem($problems, $componentId, 'component_missing', 'The locked WordPress plugin is not installed.');
            continue;
        }
        [$pluginFile, $match] = $installed;
        if (!hash_equals((string) $expected['version'], (string) ($match['Version'] ?? ''))) {
            _stattic_component_problem($problems, $componentId, 'component_version_mismatch', 'The installed WordPress plugin version differs from the platform lock.');
            continue;
        }
        if (!is_plugin_active($pluginFile)) {
            $activated = activate_plugin($pluginFile, '', false, true);
            if ((function_exists('is_wp_error') && is_wp_error($activated)) || !is_plugin_active($pluginFile)) {
                _stattic_component_problem($problems, $componentId, 'component_inactive', 'The locked WordPress plugin could not be activated.');
            }
        }
    }

    $onDemandActive = [];
    $jetpack = _stattic_component_installed_plugin($plugins, 'jetpack');
    if (is_array($jetpack) && is_plugin_active($jetpack[0])) {
        $onDemandActive[] = 'jetpack';
    }
    return $onDemandActive;
}

function _stattic_component_routing_readiness(string $privateRoot, array $target, array &$problems): array
{
    $spaceId = _stattic_component_target_space($target);
    if ($spaceId === null) {
        $spaceRoots = _stattic_runtime_space_roots_strict($privateRoot);
        if ($spaceRoots !== []) {
            _stattic_component_problem($problems, null, 'pool_not_empty', 'Warm inventory contains Space state.');
        }
        return ['snapshotRevision' => _stattic_component_digest([]), 'inventoryDigest' => _stattic_component_digest([])];
    }
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $routes = [];
    $inventories = [];
    foreach (_stattic_runtime_directory_entries_strict($spaceRoot . '/routes') as $pointerPath) {
        if (!is_file($pointerPath) || !str_ends_with($pointerPath, '.json')) {
            continue;
        }
        $pointer = _stattic_runtime_read_json_strict($pointerPath);
        if (!is_array($pointer) || !is_string($pointer['route_name'] ?? null) || !is_string($pointer['version_id'] ?? null)) {
            _stattic_component_problem($problems, null, 'routing_snapshot_invalid', 'A runtime route pointer is invalid.');
            continue;
        }
        $routes[$pointer['route_name']] = $pointer;
        $metadata = _stattic_runtime_read_json_strict(
            _stattic_version_root($privateRoot, $spaceId, $pointer['version_id']) . '/metadata.json'
        );
        if (is_array($metadata) && is_array($metadata['routeInventory'] ?? null)) {
            $inventories[$pointer['version_id']] = $metadata['routeInventory'];
        }
    }
    ksort($routes, SORT_STRING);
    ksort($inventories, SORT_STRING);
    return [
        'snapshotRevision' => _stattic_component_digest($routes),
        'inventoryDigest' => _stattic_component_digest($inventories),
    ];
}

function _stattic_component_provision_application_tables(object $wpdb, array &$problems): bool
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS _spacefast_application_journal (entry_id VARCHAR(512) NOT NULL PRIMARY KEY, space_id VARCHAR(128) NOT NULL, operation_id VARCHAR(64) NOT NULL, effect_ordinal SMALLINT UNSIGNED NOT NULL, kind VARCHAR(32) NOT NULL, message_id VARCHAR(80) NULL, payload_digest VARCHAR(71) NOT NULL, payload_json MEDIUMBLOB NOT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY uniq_operation_effect (space_id,operation_id,effect_ordinal), KEY idx_space_created (space_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS _spacefast_application_deliveries (entry_id VARCHAR(512) NOT NULL, sink VARCHAR(160) NOT NULL, state VARCHAR(24) NOT NULL, attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0, available_at DATETIME(6) NOT NULL, lease_id VARCHAR(80) NULL, lease_expires_at DATETIME(6) NULL, downstream_receipt VARCHAR(2000) NULL, last_problem_json TEXT NULL, delivered_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY(entry_id,sink), CONSTRAINT fk_application_delivery_entry FOREIGN KEY(entry_id) REFERENCES _spacefast_application_journal(entry_id) ON DELETE CASCADE, KEY idx_due(state,available_at,lease_expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($statements as $statement) {
        if ($wpdb->query($statement) === false) {
            _stattic_component_problem($problems, null, 'application_tables_provision_failed', 'Managed application journal tables could not be provisioned.');
            return false;
        }
    }
    foreach (['_spacefast_application_journal', '_spacefast_application_deliveries'] as $table) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            _stattic_component_problem($problems, null, 'application_tables_missing', 'A managed application journal table is missing.');
            return false;
        }
    }
    return true;
}

function _stattic_runtime_stage_components(string $privateRoot, array $claims): void
{
    $body = _stattic_json_body();
    $target = is_array($body['target'] ?? null) ? $body['target'] : [];
    $expectedDigest = is_string($body['expectedInventoryDigest'] ?? null) ? $body['expectedInventoryDigest'] : '';
    $operationId = is_string($body['operationId'] ?? null) ? $body['operationId'] : '';
    $routingExpected = is_array($body['routing'] ?? null) ? $body['routing'] : [];
    $spaceId = _stattic_component_target_space($target);
    $sitePoolId = $target['sitePoolId'] ?? null;
    $claimRuntime = is_string($claims['runtime_instance_id'] ?? null) ? $claims['runtime_instance_id'] : '';
    // A receipt binds to exactly one row, so exactly one of the two ids is set.
    $boundRows = ($spaceId !== null ? 1 : 0) + (is_string($sitePoolId) && $sitePoolId !== '' ? 1 : 0);
    if (
        ($body['format'] ?? null) !== 'spacefast.component-stage'
        || ($body['version'] ?? null) !== 1
        || $operationId === ''
        || $boundRows !== 1
        || !is_string($target['runtimeInstanceId'] ?? null)
        || !hash_equals($claimRuntime, $target['runtimeInstanceId'])
        || !is_string($target['siteId'] ?? null)
        || $expectedDigest === ''
    ) {
        _stattic_problem_response(422, 'component_stage_invalid', 'Component stage target is invalid.');
    }
    if ($spaceId !== null && $spaceId !== ($claims['space_id'] ?? null)) {
        _stattic_problem_response(403, 'component_stage_scope_mismatch', 'Component stage Space scope is invalid.');
    }

    $engineRoot = dirname(__DIR__);
    $lock = _stattic_component_lock($engineRoot);
    $problems = [];
    if (!is_array($lock) || !hash_equals((string) ($lock['digest'] ?? ''), $expectedDigest)) {
        _stattic_component_problem($problems, null, 'component_lock_mismatch', 'The active runtime does not carry the requested platform component lock.');
    }
    if (!is_array($lock)) {
        $lock = ['platformRelease' => SPACEFAST_RUNTIME_ENGINE_REVISION, 'components' => []];
    }
    if (!hash_equals((string) $lock['platformRelease'], SPACEFAST_RUNTIME_ENGINE_REVISION)) {
        _stattic_component_problem($problems, 'runtime-engine', 'platform_release_mismatch', 'The component lock does not belong to the active runtime release.');
    }

    $publicRoot = dirname(dirname($privateRoot));
    _stattic_component_check_embedded($lock, 'runtime-engine', dirname($engineRoot) . '/bin/stattic-runtime', $problems);
    _stattic_component_check_embedded($lock, 'immutable-loader', $publicRoot . '/wp-content/mu-plugins/spacefast-content.php', $problems);
    _stattic_component_check_embedded($lock, 'content-kernel', $engineRoot . '/wordpress/content-kernel.php', $problems);
    _stattic_component_check_embedded($lock, 'managed-theme', $publicRoot . '/wp-content/themes/spacefast-managed', $problems, true);
    $quickjs = _stattic_component_expected($lock, 'quickjs-abi');
    if (!is_array($quickjs) || ($quickjs['version'] ?? null) !== STATTIC_RUNTIME_ZERO_QUICKJS_ABI) {
        _stattic_component_problem($problems, 'quickjs-abi', 'quickjs_abi_mismatch', 'The execution ABI differs from the platform lock.');
    }

    $wordpressReady = _stattic_component_boot_wordpress($privateRoot, $target, $problems);
    $tables = ['state' => 'blocked'];
    $onDemandActive = [];
    if ($wordpressReady) {
        $core = _stattic_component_expected($lock, 'wordpress-core');
        $wpVersion = $GLOBALS['wp_version'] ?? null;
        if (!is_array($core) || !is_string($wpVersion) || !hash_equals((string) $core['version'], $wpVersion)) {
            _stattic_component_problem($problems, 'wordpress-core', 'wordpress_version_mismatch', 'WordPress core differs from the platform lock.');
        }
        $databaseReady = isset($GLOBALS['wpdb'])
            && is_object($GLOBALS['wpdb'])
            && method_exists($GLOBALS['wpdb'], 'check_connection')
            && $GLOBALS['wpdb']->check_connection(false);
        if (!$databaseReady) {
            _stattic_component_problem($problems, null, 'wordpress_database_unavailable', 'WordPress cannot reach its database.');
        }
        $onDemandActive = _stattic_component_plugins($lock, $problems);
        if (function_exists('get_stylesheet') && get_stylesheet() !== 'spacefast-managed' && function_exists('switch_theme')) {
            switch_theme('spacefast-managed');
        }
        if (!function_exists('get_stylesheet') || get_stylesheet() !== 'spacefast-managed') {
            _stattic_component_problem($problems, 'managed-theme', 'managed_theme_inactive', 'The managed WordPress theme is not active.');
        }
        if (function_exists('spacefast_content_component_observation')) {
            $content = spacefast_content_component_observation(
                $privateRoot,
                $spaceId,
            );
            $tables = is_array($content['tables'] ?? null) ? $content['tables'] : ['state' => 'blocked'];
        }
        if (($tables['state'] ?? null) !== 'ready') {
            _stattic_component_problem($problems, null, 'wordpress_tables_unavailable', 'Managed WordPress tables are not ready.');
        }
        if ($databaseReady) {
            _stattic_component_provision_application_tables($GLOBALS['wpdb'], $problems);
        }
    }

    $routing = _stattic_component_routing_readiness($privateRoot, $target, $problems);
    if (
        !hash_equals((string) ($routingExpected['snapshotRevision'] ?? ''), $routing['snapshotRevision'])
        || !hash_equals((string) ($routingExpected['inventoryDigest'] ?? ''), $routing['inventoryDigest'])
    ) {
        _stattic_component_problem($problems, null, 'routing_receipt_stale', 'Runtime routing does not match the requested snapshot and inventory.');
    }
    $application = function_exists('_stattic_application_substrate_readiness')
        ? _stattic_application_substrate_readiness($privateRoot)
        : ['mail' => ['state' => 'blocked'], 'journal' => ['state' => 'blocked']];
    foreach (['mail', 'journal'] as $service) {
        if (($application[$service]['state'] ?? null) !== 'ready') {
            _stattic_component_problem($problems, null, $service . '_unavailable', ucfirst($service) . ' delivery substrate is not ready.');
        }
    }

    $base = [
        'format' => 'spacefast.component-placement',
        'version' => 1,
        'operationId' => $operationId,
        'target' => $target,
        'platformRelease' => (string) $lock['platformRelease'],
        'expectedInventoryDigest' => $expectedDigest,
    ];
    if ($problems !== []) {
        _stattic_json_response(200, [...$base, 'status' => 'blocked', 'problems' => $problems]);
    }
    _stattic_json_response(200, [
        ...$base,
        'status' => 'ready',
        'observedInventoryDigest' => $expectedDigest,
        'substrate' => [
            'wordpress' => ['boot' => 'ready', 'database' => 'ready', 'tables' => 'ready'],
            'routing' => [
                'state' => 'ready',
                'snapshotRevision' => $routing['snapshotRevision'],
                'inventoryDigest' => $routing['inventoryDigest'],
            ],
            'mail' => ['state' => 'ready'],
            'journal' => ['state' => 'ready'],
            'onDemandActive' => $onDemandActive,
        ],
    ]);
}

function _stattic_runtime_stage_space_components(
    string $privateRoot,
    string $spaceId,
    array $claims,
): void {
    _stattic_runtime_stage_components($privateRoot, $claims);
}
