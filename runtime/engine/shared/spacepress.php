<?php
declare(strict_types=1);

// SpacePress core PHP runtime on Atomic's native `$table_prefix`.
//
// 1 Space = 1 Atomic instance: SpacePress production requests follow Atomic's
// injected `$table_prefix` from `wp-config.php` and never strip Jetpack,
// WooCommerce, or other site plugins from `active_plugins`. Legacy `sfz_<24hex>_`
// prefix isolation lives exclusively in `engine/deprecated/zero-v2/` for
// pre-cutover Spaces or boxes carrying an explicit `legacyPrefix` flag.

const SPACEFAST_SPACEPRESS_MANIFEST_FORMAT = 'spacepress.hosted.v1';
const SPACEFAST_TYPEDPRESS_LEGACY_MANIFEST_FORMAT = 'typedpress.hosted.v1';
const SPACEFAST_SPACEPRESS_API_NAMESPACE = 'spacepress/v1';
const SPACEFAST_SPACEPRESS_IDENTITY_API_NAMESPACE = 'spacepress-identity/v1';
const SPACEFAST_SPACEPRESS_API_PREFIX = '/wp-json/spacepress/v1';
const SPACEFAST_SPACEPRESS_IDENTITY_API_PREFIX = '/wp-json/spacepress-identity/v1';
const SPACEFAST_SPACEPRESS_AUTH_PREFIX = '/__spacefast/auth';
const SPACEFAST_SPACEPRESS_ASSETS_URL = '/wp-content/spacepress-assets';

/** Whether `$manifest` is a pre-cutover `typedpress.hosted.v1` build. */
function _stattic_spacepress_is_legacy_typedpress(?array $manifest): bool
{
    return is_array($manifest)
        && ($manifest['format'] ?? null) === SPACEFAST_TYPEDPRESS_LEGACY_MANIFEST_FORMAT;
}

/**
 * Whether this SpacePress Space keeps pre-cutover `sfz_<sha>_` table isolation
 * (migrated `tp_v1`, upgraded `v2`, or legacy `typedpress.hosted.v1` builds).
 */
function _stattic_spacepress_uses_legacy_prefix(?array $manifest, array $serving = []): bool
{
    return !empty($GLOBALS['SPACEFAST_SPACEPRESS_LEGACY_PREFIX'])
        || ($manifest['legacyPrefix'] ?? false) === true
        || _stattic_spacepress_is_legacy_typedpress($manifest)
        || ($serving['legacy_prefix'] ?? false) === true
        || ($serving['zero_generation'] ?? null) === 'tp_v1';
}

/** Parse a compiled SpacePress manifest, or return null if absent/not SpacePress. */
function _stattic_spacepress_manifest(string $compiledRoot): ?array
{
    $manifestPath = $compiledRoot . '/manifest.json';
    if (!is_file($manifestPath)) {
        return null;
    }
    $raw = file_get_contents($manifestPath);
    if (!is_string($raw)) {
        return null;
    }
    $manifest = json_decode($raw, true, 512);
    $format = is_array($manifest) ? ($manifest['format'] ?? null) : null;
    return ($format === SPACEFAST_SPACEPRESS_MANIFEST_FORMAT
        || $format === SPACEFAST_TYPEDPRESS_LEGACY_MANIFEST_FORMAT)
        ? $manifest
        : null;
}

/** Locate the compiled SpacePress root inside a version directory, or null. */
function _stattic_spacepress_version_root(string $versionRoot): ?string
{
    static $cache = [];
    if (array_key_exists($versionRoot, $cache)) {
        return $cache[$versionRoot];
    }
    foreach (['/.spacepress-compiled', '/.zero-compiled'] as $suffix) {
        $candidate = $versionRoot . $suffix;
        if (_stattic_spacepress_manifest($candidate) !== null) {
            return $cache[$versionRoot] = $candidate;
        }
    }
    return $cache[$versionRoot] = null;
}

/** Read the compiled SpacePress manifest for a version directory, or null. */
function _stattic_spacepress_version_manifest(string $versionRoot): ?array
{
    $root = _stattic_spacepress_version_root($versionRoot);
    return $root === null ? null : _stattic_spacepress_manifest($root);
}

/** Define SpacePress host constants before the app loader runs. */
function _stattic_spacepress_define_host_constants(string $privateRoot): void
{
    if (!defined('SP_API_NAMESPACE')) {
        define('SP_API_NAMESPACE', SPACEFAST_SPACEPRESS_API_NAMESPACE);
    }
    if (!defined('SP_IDENTITY_API_NAMESPACE')) {
        define('SP_IDENTITY_API_NAMESPACE', SPACEFAST_SPACEPRESS_IDENTITY_API_NAMESPACE);
    }
    if (!defined('SP_API_PREFIX')) {
        define('SP_API_PREFIX', SPACEFAST_SPACEPRESS_API_PREFIX);
    }
    if (!defined('SP_AUTH_PREFIX')) {
        define('SP_AUTH_PREFIX', SPACEFAST_SPACEPRESS_AUTH_PREFIX);
    }
    if (!defined('SP_ASSETS_DIR')) {
        $wpContent = defined('WP_CONTENT_DIR') && is_string(WP_CONTENT_DIR) && WP_CONTENT_DIR !== ''
            ? rtrim(WP_CONTENT_DIR, '/')
            : dirname($privateRoot, 2) . '/wp-content';
        define('SP_ASSETS_DIR', $wpContent . '/spacepress-assets');
    }
    if (!defined('SP_ASSETS_URL')) {
        define('SP_ASSETS_URL', SPACEFAST_SPACEPRESS_ASSETS_URL);
    }
}

/**
 * Select a SpacePress build for this request before WordPress boots.
 * Returns false when the version does not contain a SpacePress build.
 */
function _stattic_spacepress_select(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    array $authority = [],
    bool $initializing = false
): bool {
    $versionRoot = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId;
    $root = _stattic_spacepress_version_root($versionRoot);
    if ($root === null) {
        return false;
    }
    $generation = is_string($authority['zero_generation'] ?? null) ? $authority['zero_generation'] : 'none';
    if (!$initializing && in_array($generation, ['legacy_0_8', 'v2'], true)) {
        throw new RuntimeException('This Space belongs to a legacy Zero runtime generation.');
    }
    if (!$initializing && !is_file($root . '/ready.json')) {
        throw new RuntimeException('SpacePress initialization is incomplete.');
    }
    if (isset($GLOBALS['SPACEFAST_SPACEPRESS_ROOT']) && $GLOBALS['SPACEFAST_SPACEPRESS_ROOT'] !== $root) {
        throw new RuntimeException('Only one SpacePress version may load in a request.');
    }
    $GLOBALS['SPACEFAST_SPACEPRESS_ROOT'] = $root;
    $GLOBALS['SPACEFAST_SPACEPRESS_CONTEXT'] = [
        'private_root' => $privateRoot,
        'space_id' => $spaceId,
        'version_id' => $versionId,
        'version_root' => $versionRoot,
        'compiled_root' => $root,
        'serving' => $authority,
        'initializing' => $initializing,
    ];
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    return true;
}

/**
 * Bind SpacePress to WordPress on Atomic's native `$table_prefix`.
 *
 * Intentionally preserves `$wpdb->prefix`, `$table_prefix`, and `active_plugins`
 * so Jetpack (VideoPress, Akismet, Monitor, Stats, Mail), WooCommerce, SQLite
 * preview drop-ins (`wp-content/db.php`), and `wp-cli` share one WordPress.
 */
function _stattic_spacepress_bind_wordpress(): void
{
    $root = $GLOBALS['SPACEFAST_SPACEPRESS_ROOT'] ?? null;
    if (!is_string($root)) {
        return;
    }
    require_once __DIR__ . '/wordpress-scope.php';
    $context = is_array($GLOBALS['SPACEFAST_SPACEPRESS_CONTEXT'] ?? null)
        ? $GLOBALS['SPACEFAST_SPACEPRESS_CONTEXT']
        : [];
    $privateRoot = (string) ($context['private_root'] ?? ($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? ''));
    $spaceId = (string) ($context['space_id'] ?? ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? ''));
    $serving = is_array($context['serving'] ?? null) ? $context['serving'] : [];

    _stattic_spacepress_define_host_constants($privateRoot);

    // Opt-in for pre-cutover `tp_v1` / upgraded `v2` boxes carrying legacy
    // `sfz_<sha>_` table isolation (`typedpress.hosted.v1` or `legacy_prefix`).
    $initializing = ($context['initializing'] ?? false) === true;
    $manifest = _stattic_spacepress_manifest($root);
    $legacyPrefix = _stattic_spacepress_uses_legacy_prefix($manifest, $serving);
    if ($legacyPrefix && $spaceId !== '') {
        require_once __DIR__ . '/../deprecated/zero-v2/zero-v2-scope.php';
        _stattic_zero_v2_bind_legacy_prefix($spaceId, $initializing);
    }

    // Two Factor is installed on SpacePress boxes without being globally active
    // (box-plugins.ts): load it directly on `muplugins_loaded` for SpacePress
    // requests without filtering `active_plugins`, so site plugins stay untouched
    // and Two Factor can never be written back as globally active.
    if (function_exists('add_action')) {
        add_action('muplugins_loaded', static function (): void {
            if (defined('TWO_FACTOR_VERSION') || !defined('WP_PLUGIN_DIR')) {
                return;
            }
            $twoFactorFile = WP_PLUGIN_DIR . '/two-factor/two-factor.php';
            if (is_file($twoFactorFile)) {
                require_once $twoFactorFile;
            }
        }, 1);
    }

    if (function_exists('add_filter')) {
        add_filter('sp_api_namespace', static fn (): string => SP_API_NAMESPACE);
        add_filter('sp_identity_api_namespace', static fn (): string => SP_IDENTITY_API_NAMESPACE);
        add_filter('sp_api_prefix', static fn (): string => SP_API_PREFIX);
        add_filter('sp_auth_prefix', static fn (): string => SP_AUTH_PREFIX);
        add_filter('sp_assets_dir', static fn (): string => SP_ASSETS_DIR);
        add_filter('sp_assets_url', static fn (): string => SP_ASSETS_URL);
        add_filter('sp_mail_transport', static fn (mixed $accepted, array $message): bool => _stattic_spacepress_mail_send($message), 10, 2);
        add_filter('tp_mail_transport', static fn (mixed $accepted, array $message): bool => _stattic_spacepress_mail_send($message), 10, 2);
        add_filter('sp_connectors_call', '_stattic_spacepress_connectors_call', 10, 4);

        add_filter('automatic_updater_disabled', '__return_true');
        add_filter('auto_update_core', '__return_false');
        add_filter('auto_update_plugin', '__return_false');
        add_filter('auto_update_theme', '__return_false');
        add_filter('site_url', 'spacefast_content_request_url', 1);
        add_filter('home_url', 'spacefast_content_public_url', 1);
        add_filter('rest_url', 'spacefast_content_request_url', 1);
    }
}

/** Resolve the SpacePress loader class loaded from the runtime store. */
function _stattic_spacepress_loader_class(): string
{
    if (class_exists('\SpacePress\Loader\Loader', false)) {
        return '\SpacePress\Loader\Loader';
    }
    if (class_exists('\TypedPress\Loader\Loader', false)) {
        return '\TypedPress\Loader\Loader';
    }
    throw new RuntimeException('SpacePress runtime loader class is missing.');
}

/** Mount the selected SpacePress build inside WordPress. */
function _stattic_spacepress_mount(?string $root = null, ?array $manifest = null): void
{
    $root ??= is_string($GLOBALS['SPACEFAST_SPACEPRESS_ROOT'] ?? null)
        ? $GLOBALS['SPACEFAST_SPACEPRESS_ROOT']
        : '';
    if ($root === '') {
        return;
    }
    $manifest ??= _stattic_spacepress_manifest($root);
    if ($manifest === null) {
        throw new RuntimeException('Invalid SpacePress manifest.');
    }
    require_once __DIR__ . '/storage.php';
    if (!in_array($manifest['hostContract'] ?? null, [1, 2], true)) {
        throw new RuntimeException('Unsupported SpacePress host contract.');
    }
    foreach (['build', 'runtimeRevision', 'appHash'] as $key) {
        if (!is_string($manifest[$key] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $manifest[$key]) !== 1) {
            throw new RuntimeException('Invalid SpacePress build reference.');
        }
    }
    global $wp_version;
    if (is_string($manifest['wordpress'] ?? null) && $manifest['wordpress'] !== '') {
        if (!is_string($wp_version) || version_compare($wp_version, $manifest['wordpress'], '<')) {
            throw new RuntimeException('This SpacePress build requires WordPress ' . $manifest['wordpress'] . '.');
        }
    }
    $privateRoot = (string) ($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '');
    _stattic_spacepress_define_host_constants($privateRoot);

    $runtimeDir = $root . '/store/runtime/' . $manifest['runtimeRevision'];
    $loaderFile = is_file($runtimeDir . '/spacepress-loader.php')
        ? $runtimeDir . '/spacepress-loader.php'
        : $runtimeDir . '/typedpress-loader.php';
    if (!is_file($loaderFile)) {
        throw new RuntimeException('SpacePress runtime loader is missing.');
    }
    require_once $loaderFile;

    $variables = is_file($root . '/variables.json')
        ? json_decode((string) file_get_contents($root . '/variables.json'), true, 32, JSON_THROW_ON_ERROR)
        : [];
    if (!is_array($variables)) {
        throw new RuntimeException('Invalid SpacePress variables.');
    }
    $variables += _stattic_spacepress_mail_variables();

    $loaderClass = _stattic_spacepress_loader_class();
    $loadOptions = _stattic_spacepress_is_legacy_typedpress($manifest)
        ? [
            'variables' => $variables,
            'url' => '/__tp/assets',
        ]
        : [
            'variables' => $variables,
            'url' => SP_ASSETS_URL,
            'assetsDir' => SP_ASSETS_DIR,
            'apiNamespace' => SP_API_NAMESPACE,
            'identityApiNamespace' => SP_IDENTITY_API_NAMESPACE,
            'apiPrefix' => SP_API_PREFIX,
            'authPrefix' => SP_AUTH_PREFIX,
        ];
    $loaderClass::load($root . '/store', $manifest['build'], $loadOptions);
}

/** Read the Space overlay for the active SpacePress request. */
function _stattic_spacepress_overlay(): ?array
{
    require_once __DIR__ . '/storage.php';
    require_once __DIR__ . '/artifacts.php';
    $privateRoot = (string) ($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '');
    $spaceId = (string) ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? '');
    if ($privateRoot === '' || !_stattic_id_valid($spaceId)) {
        return null;
    }
    $pointer = _stattic_runtime_read_json($privateRoot . '/spaces/' . $spaceId . '/space.json');
    $overlay = is_array($pointer) ? _stattic_v4_overlay($privateRoot, $spaceId, $pointer) : null;
    return is_array($overlay) ? $overlay : null;
}

/** Host-owned mail variables for SpacePress: the Space's authorized sender domain. */
function _stattic_spacepress_mail_variables(): array
{
    $domain = _stattic_spacepress_overlay()['email']['senderDomain'] ?? null;
    if (!is_string($domain) || $domain === '') {
        return [];
    }
    $normalized = strtolower(trim($domain));
    return [
        'SP_MAIL_SENDER_DOMAIN' => $normalized,
        'TP_MAIL_SENDER_DOMAIN' => $normalized,
    ];
}

/** Hand a SpacePress mail message to the Space's native service-broker email lane. */
function _stattic_spacepress_mail_send(array $message): bool
{
    require_once __DIR__ . '/native-process.php';
    require_once __DIR__ . '/functions-wire.generated.php';
    $overlay = _stattic_spacepress_overlay();
    if ($overlay === null) {
        throw new RuntimeException('Space mail routing is unavailable.');
    }
    $privateRoot = (string) ($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '');
    $spaceId = (string) ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? '');
    if (_stattic_access_private_root() === '' && $privateRoot !== '') {
        _stattic_access_private_root($privateRoot);
    }
    $env = _stattic_service_broker_env(['spaceId' => $spaceId, 'invocationId' => ''], [
        'billing' => is_array($overlay['billing'] ?? null) ? $overlay['billing'] : [],
        'email' => is_array($overlay['email'] ?? null) ? $overlay['email'] : null,
    ]) + [SPACEFAST_FUNCTIONS_SERVICE_BROKER_GRANT_ENV => 'email.send'];
    $frame = json_encode(
        ['service' => 'email', 'operation' => 'send', 'payload' => (object) $message],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $result = _stattic_runtime_run_subprocess(
        [_stattic_runtime_native_binary(), 'service-broker'],
        $env,
        $frame,
        null,
        15000,
        1048576,
        65536
    );
    $answer = json_decode(trim((string) $result['stdout']), true);
    if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0 || !is_array($answer)) {
        throw new RuntimeException('The Space mail broker did not answer.');
    }
    if (($answer['ok'] ?? false) !== true) {
        throw new RuntimeException('Space mail refused: ' . (is_string($answer['code'] ?? null) ? $answer['code'] : 'service_refused'));
    }
    return ($answer['result'] ?? null) === true;
}

/**
 * Resolve the current viewer subject for SpacePress connector calls.
 *
 * Prefers an authenticated WordPress user's Spacefast subject, then Space
 * Users / access cookie identity; returns null when anonymous.
 *
 * @return array{subject: string, email?: string}|null
 */
function _stattic_spacepress_connector_viewer(array $serving, string $host, string $spaceId): ?array
{
    if (function_exists('spacefast_space_users_auth')) {
        $auth = spacefast_space_users_auth();
        if (is_array($auth) && is_string($auth['userId'] ?? null) && $auth['userId'] !== '') {
            return ['subject' => $auth['userId']];
        }
    }
    if (function_exists('wp_get_current_user')) {
        $user = wp_get_current_user();
        if (is_object($user) && isset($user->ID) && (int) $user->ID > 0) {
            $userId = (int) $user->ID;
            $storedSubject = function_exists('get_option')
                ? get_option('spacefast_app_subject_' . $userId . '_' . $spaceId, '')
                : '';
            $subject = is_string($storedSubject) && $storedSubject !== ''
                ? $storedSubject
                : 'wp:' . $spaceId . ':' . $userId;
            $viewer = ['subject' => $subject];
            if (is_string($user->user_email ?? null) && $user->user_email !== '') {
                $viewer['email'] = $user->user_email;
            }
            return $viewer;
        }
    }
    if ($serving !== [] && $host !== '' && function_exists('_stattic_connectors_visitor')) {
        return _stattic_connectors_visitor($serving, $host);
    }
    return null;
}

/**
 * `sp_connectors_call` filter callback: bridges SpacePress server actions to
 * Spacefast's control-plane connector relay with viewer subject + signed Space
 * runtime credential.
 *
 * Supports both positional `(null, $role, $tool, $args)` and structured
 * `(null, ['role' => ..., 'tool' => ..., 'args' => ..., 'handler' => ...])`
 * invocations.
 *
 * @param array<string, mixed>|string $roleOrRequest
 * @param array<string, mixed> $args
 */
function _stattic_spacepress_connectors_call(
    mixed $result,
    string|array $roleOrRequest,
    ?string $tool = null,
    array $args = []
): mixed {
    if ($result !== null) {
        return $result;
    }

    if (is_array($roleOrRequest)) {
        $role = is_string($roleOrRequest['role'] ?? null) ? trim($roleOrRequest['role']) : '';
        $toolName = is_string($roleOrRequest['tool'] ?? null) ? trim($roleOrRequest['tool']) : '';
        $callArgs = is_array($roleOrRequest['args'] ?? null) ? $roleOrRequest['args'] : [];
        $handler = is_array($roleOrRequest['handler'] ?? null) ? $roleOrRequest['handler'] : [];
        $requestId = is_string($roleOrRequest['requestId'] ?? null) ? trim($roleOrRequest['requestId']) : '';
        $explicitVisitor = is_array($roleOrRequest['visitor'] ?? null) ? $roleOrRequest['visitor'] : null;
    } else {
        $role = trim($roleOrRequest);
        $toolName = is_string($tool) ? trim($tool) : '';
        $callArgs = $args;
        $handler = [];
        $requestId = '';
        $explicitVisitor = null;
    }

    if ($role === '' || $toolName === '') {
        return [
            'ok' => false,
            'state' => 'invalid_request',
            'code' => 'connector_call_invalid',
            'message' => 'Connector role and tool name are required.',
        ];
    }

    $context = is_array($GLOBALS['SPACEFAST_SPACEPRESS_CONTEXT'] ?? null)
        ? $GLOBALS['SPACEFAST_SPACEPRESS_CONTEXT']
        : [];
    $privateRoot = (string) ($context['private_root'] ?? ($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? ''));
    $spaceId = (string) ($context['space_id'] ?? ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? ''));
    $versionId = (string) ($context['version_id'] ?? '');
    $versionRoot = (string) ($context['version_root'] ?? ($privateRoot !== '' && $spaceId !== '' && $versionId !== ''
        ? _stattic_version_root($privateRoot, $spaceId, $versionId)
        : ''));
    $serving = is_array($context['serving'] ?? null) && $context['serving'] !== []
        ? $context['serving']
        : (is_array($GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null) ? $GLOBALS['SPACEFAST_PAGE_SERVING'] : []);
    if ($spaceId !== '' && !isset($serving['space_id'])) {
        $serving['space_id'] = $spaceId;
    }
    if ($versionId !== '' && !isset($serving['version_id'])) {
        $serving['version_id'] = $versionId;
    }
    if ($privateRoot !== '' && _stattic_access_private_root() === '') {
        _stattic_access_private_root($privateRoot);
    }

    require_once __DIR__ . '/../runtime/connectors.php';
    $token = $versionRoot !== '' ? _stattic_connectors_token($versionRoot) : '';
    $apiUrl = rtrim(_stattic_config_value('SPACEFAST_API_BASE_URL'), '/');
    if (!_stattic_platform_destination_allowed($apiUrl)) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'code' => 'connectors_unavailable',
            'message' => 'Connections are unavailable. Try again.',
        ];
    }
    $hasSpaceIdentity = $spaceId !== '' && $versionId !== '' && is_file($privateRoot . '/runtime/space-identity-key.json');
    if ($token === '' && !$hasSpaceIdentity) {
        return [
            'ok' => false,
            'state' => 'unbound',
            'code' => 'connector_role_unknown',
            'message' => 'This Space has no connector credential configured.',
        ];
    }

    $host = _stattic_normalize_host_authority((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $visitor = $explicitVisitor;
    if ($visitor === null) {
        $visitor = _stattic_spacepress_connector_viewer($serving, $host, $spaceId);
    } elseif (!is_string($visitor['subject'] ?? null) || trim($visitor['subject']) === '') {
        $visitor = null;
    }

    $handlerName = is_string($handler['name'] ?? null) && $handler['name'] !== '' ? $handler['name'] : 'spacepress';
    $handlerMode = ($handler['mode'] ?? null) === 'read' ? 'read' : 'write';
    $callIndex = is_int($handler['callIndex'] ?? null) && $handler['callIndex'] >= 0 ? $handler['callIndex'] : 0;

    $call = [
        'surface' => 'zero',
        'role' => $role,
        'tool' => $toolName,
        'args' => (object) $callArgs,
        'visitor' => $visitor,
        'handler' => [
            'name' => $handlerName,
            'mode' => $handlerMode,
            'callIndex' => $callIndex,
        ],
        'acceptsSharedReads' => true,
    ];
    if ($requestId !== '' && strlen($requestId) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $requestId) !== 1) {
        $call['requestId'] = $requestId;
    }

    $response = _stattic_connectors_post(
        $apiUrl . '/v1/runtime/connectors/calls',
        $token,
        $call,
        STATTIC_CONNECTORS_CALL_TIMEOUT_SECONDS,
        $serving
    );
    $answer = json_decode($response['body'], true);
    if ($response['error'] !== null || !is_array($answer) || $response['status'] === 401 || $response['status'] >= 500) {
        return [
            'ok' => false,
            'state' => 'unavailable',
            'code' => 'connectors_unavailable',
            'status' => (int) ($response['status'] ?? 502),
            'message' => 'Connections are unavailable. Try again.',
        ];
    }
    if ($response['status'] >= 400) {
        return [
            'ok' => false,
            'state' => 'error',
            'code' => is_string($answer['code'] ?? null) ? $answer['code'] : 'connectors_unavailable',
            'status' => (int) $response['status'],
            'message' => is_string($answer['detail'] ?? null) ? $answer['detail'] : '',
        ];
    }

    $state = is_array($answer['state'] ?? null) ? $answer['state'] : [];
    $code = is_string($state['code'] ?? null) ? $state['code'] : '';
    if ($code === 'ok') {
        $rawResult = $answer['result'] ?? ($answer['run']['result'] ?? null);
        $out = ['ok' => true, 'state' => 'ok', 'data' => $rawResult];
        if (is_array($rawResult) && ($rawResult['ok'] ?? null) === true && array_key_exists('data', $rawResult)) {
            $out['data'] = $rawResult['data'];
            if (is_array($rawResult['http'] ?? null)) {
                $out['http'] = $rawResult['http'];
            }
        }
        if (is_string($state['cachedAt'] ?? null)) {
            $out['cachedAt'] = $state['cachedAt'];
        }
        return $out;
    }

    if ($code === 'approval_pending' && ($state['pendingReason'] ?? null) === 'running') {
        $code = 'running';
    }
    $out = [
        'ok' => false,
        'state' => $code !== '' ? $code : 'unavailable',
        'message' => (string) ($state['message'] ?? ''),
    ];
    foreach (['connectUrl', 'approvalUrl'] as $link) {
        if (is_string($state[$link] ?? null) && $state[$link] !== '') {
            $out[$link] = $state[$link];
        }
    }
    return $out;
}

/** Write private SpacePress runtime variables at finalize. */
function _stattic_spacepress_write_variables(string $privateRoot, string $spaceId, string $versionId, mixed $variables): void
{
    $versionRoot = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId;
    $root = _stattic_spacepress_version_root($versionRoot);
    if ($root === null) {
        return;
    }
    if (!is_array($variables)) {
        throw new RuntimeException('Invalid SpacePress variables.');
    }
    foreach ($variables as $name => $value) {
        if (!is_string($name) || !is_string($value)) {
            throw new RuntimeException('Invalid SpacePress variables.');
        }
    }
    _stattic_runtime_write_json_atomic($root . '/variables.json', $variables);
}

/** Initialize a finalized SpacePress version against the Space's WordPress. */
function _stattic_spacepress_initialize(string $privateRoot, string $spaceId, string $versionId, array $authority = []): void
{
    if (!isset($authority['legacy_prefix'])) {
        require_once __DIR__ . '/storage.php';
        require_once __DIR__ . '/artifacts.php';
        $pointer = _stattic_runtime_read_json($privateRoot . '/spaces/' . $spaceId . '/space.json');
        $overlay = is_array($pointer) ? _stattic_v4_overlay($privateRoot, $spaceId, $pointer) : null;
        if (
            is_array($overlay)
            && (
                ($overlay['legacy_prefix'] ?? false) === true
                || in_array($overlay['zero_generation'] ?? null, ['v2', 'tp_v1'], true)
            )
        ) {
            $authority['legacy_prefix'] = true;
        }
    }
    if (!_stattic_spacepress_select($privateRoot, $spaceId, $versionId, $authority, true)) {
        return;
    }
    $root = (string) $GLOBALS['SPACEFAST_SPACEPRESS_ROOT'];
    if (is_file($root . '/ready.json')) {
        return;
    }
    if (function_exists('did_action') && did_action('plugins_loaded') > 0) {
        throw new RuntimeException('WordPress booted before the candidate SpacePress version was selected.');
    }
    $bootstrap = dirname($privateRoot, 2) . '/wp-load.php';
    if (!is_file($bootstrap)) {
        throw new RuntimeException('WordPress is required for SpacePress.');
    }
    (static function (string $file): void {
        require_once $file;
    })($bootstrap);
    $manifest = _stattic_spacepress_manifest($root);
    if ($manifest === null) {
        throw new RuntimeException('SpacePress manifest is missing.');
    }
    $loaderClass = _stattic_spacepress_loader_class();
    $loaderClass::initialize($root . '/store', $manifest['build']);
    _stattic_runtime_write_json_atomic($root . '/ready.json', ['version_id' => $versionId]);
    _stattic_runtime_write_json_atomic($privateRoot . '/spaces/' . $spaceId . '/spacepress-current.json', ['version_id' => $versionId]);
}

/** WP-CLI bootstrap snippet for scheduled worker runs on a SpacePress version. */
function _stattic_spacepress_wp_bootstrap(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    bool $initializing = false,
    bool $legacyPrefix = false
): string {
    $authority = ['zero_generation' => 'spacepress'];
    if ($legacyPrefix) {
        $authority['legacy_prefix'] = true;
    }
    return 'require_once ' . var_export(__DIR__ . '/context.php', true) . ';'
        . 'require_once ' . var_export(__DIR__ . '/storage.php', true) . ';'
        . 'require_once ' . var_export(__FILE__, true) . ';'
        . '_stattic_spacepress_select(' . var_export($privateRoot, true) . ',' . var_export($spaceId, true) . ',' . var_export($versionId, true) . ',' . var_export($authority, true) . ',' . ($initializing ? 'true' : 'false') . ');';
}

/**
 * Whether `$path` is claimed by this SpacePress build:
 * - `/wp-json/spacepress/v1/*`
 * - `/wp-json/spacepress-identity/v1/*`
 * - `/__spacefast/auth/*`
 * - `/wp-admin/new/*`
 * - declared `pagePaths` and `routes` from `manifest.json`
 *
 * Deliberately does NOT claim `/identity` or `/identity/*` on `spacepress.hosted.v1`:
 * browser auth flows through `wp-login.php` and `/__spacefast/auth/*`.
 * Pre-cutover `typedpress.hosted.v1` manifests retain their legacy framework paths
 * (`/identity*`, `/typedpress-admin*`, `/__tp/*`, `/wp-json/typedpress-identity/*`,
 * `/wp-json/typedpress/v1/*`) so already-deployed Spaces stay reachable.
 */
function _stattic_spacepress_path_claimed(string $path, array $manifest): bool
{
    if (
        $path === SPACEFAST_SPACEPRESS_API_PREFIX
        || str_starts_with($path, SPACEFAST_SPACEPRESS_API_PREFIX . '/')
        || $path === SPACEFAST_SPACEPRESS_IDENTITY_API_PREFIX
        || str_starts_with($path, SPACEFAST_SPACEPRESS_IDENTITY_API_PREFIX . '/')
        || $path === SPACEFAST_SPACEPRESS_AUTH_PREFIX
        || str_starts_with($path, SPACEFAST_SPACEPRESS_AUTH_PREFIX . '/')
        || $path === '/wp-admin/new'
        || str_starts_with($path, '/wp-admin/new/')
    ) {
        return true;
    }
    if (
        _stattic_spacepress_is_legacy_typedpress($manifest)
        && (
            in_array($path, ['/identity', '/typedpress-admin', '/__tp', '/wp-json/typedpress-identity', '/wp-json/typedpress/v1'], true)
            || str_starts_with($path, '/identity/')
            || str_starts_with($path, '/typedpress-admin/')
            || str_starts_with($path, '/__tp/')
            || str_starts_with($path, '/wp-json/typedpress-identity/')
            || str_starts_with($path, '/wp-json/typedpress/v1/')
        )
    ) {
        return true;
    }

    $patterns = array_merge(
        is_array($manifest['pagePaths'] ?? null) ? $manifest['pagePaths'] : [],
        is_array($manifest['routes'] ?? null) ? array_column($manifest['routes'], 'path') : []
    );
    foreach ($patterns as $pattern) {
        if (!is_string($pattern) || $pattern === '') {
            continue;
        }
        $trimmed = trim($pattern, '/');
        if ($trimmed === '') {
            if ($path === '/') {
                return true;
            }
            continue;
        }
        $segments = explode('/', $trimmed);
        $regex = implode('/', array_map(
            static fn (string $part): string => str_starts_with($part, ':')
                ? '[^/]+'
                : (str_starts_with($part, '*') ? '.*' : preg_quote($part, '~')),
            $segments
        ));
        if (preg_match('~^/' . $regex . '/?$~D', $path) === 1) {
            return true;
        }
    }
    return false;
}

/** Serve a SpacePress static asset under `SP_ASSETS_URL` (or `/__tp/assets/` for legacy TypedPress builds). */
function _stattic_spacepress_try_serve_asset(array $context, string $root, array $manifest, string $path, string $method): bool
{
    $spacepressPrefix = SPACEFAST_SPACEPRESS_ASSETS_URL . '/';
    $legacyAssetPrefix = '/__tp/assets/';
    $isLegacyAsset = _stattic_spacepress_is_legacy_typedpress($manifest)
        && str_starts_with($path, $legacyAssetPrefix);
    if (!str_starts_with($path, $spacepressPrefix) && !$isLegacyAsset) {
        return false;
    }
    $prefix = $isLegacyAsset ? $legacyAssetPrefix : $spacepressPrefix;
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD');
    }
    $relative = substr($path, strlen($prefix));
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $types = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'css' => 'text/css',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    if (!isset($types[$extension]) || str_contains($relative, "\0")) {
        _stattic_problem_response(404, 'not_found', 'No such SpacePress asset.');
    }

    $resolved = null;
    $wpAssetsDir = realpath(dirname((string) $context['private_root'], 2) . '/wp-content/spacepress-assets');
    if (is_string($wpAssetsDir)) {
        $candidate = realpath($wpAssetsDir . '/' . $relative);
        if (is_string($candidate) && str_starts_with($candidate, $wpAssetsDir . '/') && is_file($candidate)) {
            $resolved = $candidate;
        }
    }
    if ($resolved === null) {
        $store = realpath($root . '/store');
        $candidate = realpath($root . '/store/' . $relative);
        $appHash = is_string($manifest['appHash'] ?? null) ? $manifest['appHash'] : '';
        $runtimeRev = is_string($manifest['runtimeRevision'] ?? null) ? $manifest['runtimeRevision'] : '';
        $allowed = ($appHash !== '' && str_starts_with($relative, 'app/' . $appHash . '/plugins/'))
            || ($runtimeRev !== '' && str_starts_with($relative, 'runtime/' . $runtimeRev . '/'));
        if ($allowed && is_string($store) && is_string($candidate)
            && str_starts_with($candidate, $store . '/') && is_file($candidate)) {
            $resolved = $candidate;
        }
    }
    if ($resolved === null) {
        _stattic_problem_response(404, 'not_found', 'No such SpacePress asset.');
    }

    if (function_exists('_stattic_v4_content_type_policy_check')) {
        $policy = _stattic_v4_content_type_policy_check($context, ['content-type' => $types[$extension]]);
        if ($policy !== null) {
            _stattic_render_content_type_blocked($policy);
        }
    }
    _stattic_response_send(
        200,
        (string) file_get_contents($resolved),
        $types[$extension],
        ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
    );
}

/**
 * Route SpacePress claimed paths, declared pages, and API routes.
 *
 * Returns true ONLY when the request is deferred to the document root's front
 * controller (`SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY`); exits when served;
 * returns false when this version is not SpacePress or does not claim `$path`.
 */
function _stattic_spacepress_try_serve(array $context, string $path, string $method): bool
{
    $versionRoot = _stattic_version_root(
        (string) $context['private_root'],
        (string) $context['space_id'],
        (string) $context['version_id']
    );
    $root = _stattic_spacepress_version_root($versionRoot);
    if ($root === null) {
        return false;
    }
    $manifest = _stattic_spacepress_manifest($root);
    if ($manifest === null) {
        return false;
    }

    _stattic_spacepress_try_serve_asset($context, $root, $manifest, $path, $method);

    // Sign in with Spacefast returns to the Space's reserved app callback
    // (wordpress/typedpress.php names it); it is SpacePress / TypedPress
    // Identity's own provider callback, for the Spacefast provider.
    if ($path === '/__spacefast/oauth/typedpress' || $path === '/__spacefast/oauth/spacepress') {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            _stattic_method_not_allowed('GET, HEAD');
        }
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $_SERVER['QUERY_STRING'] = 'provider=spacefast' . ($query === '' ? '' : '&' . $query);
        $_GET = ['provider' => 'spacefast'] + $_GET;
        $path = _stattic_spacepress_is_legacy_typedpress($manifest)
            ? '/identity/provider/callback'
            : SPACEFAST_SPACEPRESS_AUTH_PREFIX . '/provider/callback';
    }

    if (!_stattic_spacepress_path_claimed($path, $manifest)) {
        return false;
    }

    _stattic_spacepress_select(
        (string) $context['private_root'],
        (string) $context['space_id'],
        (string) $context['version_id'],
        is_array($context['serving'] ?? null) ? $context['serving'] : []
    );
    require_once __DIR__ . '/wordpress-scope.php';

    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $_SERVER['REQUEST_URI'] = $path . ($query === '' ? '' : '?' . $query);
    if (isset($_SERVER['PATH_INFO'])) {
        $_SERVER['PATH_INFO'] = $path;
    }
    // Who the visitor is and which Space role they hold, before WordPress
    // boots here or in the provider's front controller: the Spacefast plugin
    // (wordpress/typedpress.php) makes that person the app's WordPress user
    // and lends the role for this request only.
    _stattic_spacepress_resolve_visitor(
        is_array($context['serving'] ?? null) ? $context['serving'] : [],
        (string) ($context['host'] ?? ''),
        $path,
        $method
    );
    // A page whose admission needed a Grant, or that runs as a platform
    // principal, is never a shared-cache copy, whatever the app's render cache
    // sets (shared/response.php applies it when the headers leave PHP).
    if ((!empty($context['private_cache']) || isset($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL']))
        && function_exists('_stattic_authenticated_response')) {
        _stattic_authenticated_response(true);
    }
    if (!empty($GLOBALS['SPACEFAST_RUNTIME_DOCUMENT_ROOT_REENTRY'])) {
        return true;
    }

    $isRest = $path === SPACEFAST_SPACEPRESS_API_PREFIX
        || str_starts_with($path, SPACEFAST_SPACEPRESS_API_PREFIX . '/')
        || $path === SPACEFAST_SPACEPRESS_IDENTITY_API_PREFIX
        || str_starts_with($path, SPACEFAST_SPACEPRESS_IDENTITY_API_PREFIX . '/')
        || (
            _stattic_spacepress_is_legacy_typedpress($manifest)
            && (
                $path === '/wp-json/typedpress-identity'
                || str_starts_with($path, '/wp-json/typedpress-identity/')
                || $path === '/wp-json/typedpress/v1'
                || str_starts_with($path, '/wp-json/typedpress/v1/')
            )
        );
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', !$isRest);
    }
    require_once dirname((string) $context['private_root'], 2) . '/wp-load.php';
    _stattic_wordpress_lane_refuse_page_cache();
    wp();
    _stattic_document_isolation_stream_begin();
    if ($isRest) {
        $restRoute = substr($path, strlen('/wp-json'));
        $restRequest = new WP_REST_Request($method === 'HEAD' ? 'GET' : $method, $restRoute);
        $restRequest->set_query_params($_GET);
        $rawBody = _stattic_bounded_request_body(1048576);
        if (is_string($rawBody)) {
            $restRequest->set_body($rawBody);
        }
        $server = rest_get_server();
        $response = $server->dispatch($restRequest);
        $server->send_headers($response->get_headers());
        http_response_code($response->get_status());
        header('Content-Type: application/json; charset=utf-8', true);
        _stattic_enforce_document_isolation();
        if ($method !== 'HEAD') {
            echo json_encode($server->response_to_data($response, false), JSON_UNESCAPED_SLASHES);
        }
        ob_end_flush();
        exit;
    }
    require ABSPATH . WPINC . '/template-loader.php';
    _stattic_enforce_document_isolation();
    ob_end_flush();
    exit;
}

/**
 * The one per-request principal resolver for a SpacePress request: the account
 * the live Space session names becomes `SPACEFAST_CONTENT_PRINCIPAL` (as the
 * WordPress REST door resolves it in loader/request.php), and the Space role it
 * holds now becomes `SPACEFAST_SPACE_ROLE`. Enforcement already admitted the
 * request; this only names who WordPress attributes it to. An open Space loads
 * access code only for a browser holding an account-format session cookie. An
 * unsafe method acts as the person only from this Space's own origin, so a
 * cross-site form never acts as them. Idempotent.
 */
function _stattic_spacepress_resolve_visitor(array $serving, string $host, string $path, string $method): void
{
    if (array_key_exists('SPACEFAST_SPACE_ROLE', $GLOBALS)) {
        return;
    }
    $GLOBALS['SPACEFAST_SPACE_ROLE'] = null;
    if (!function_exists('_stattic_session_cookie_name')) {
        require_once __DIR__ . '/../runtime/access-rules.php';
    }
    $cookie = $_COOKIE[_stattic_session_cookie_name()] ?? null;
    if (!is_string($cookie) || !str_starts_with($cookie, 'sfv2_') || $host === '') {
        return;
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        require_once __DIR__ . '/request-origin.php';
        if (!_stattic_access_same_origin_post($host)) {
            return;
        }
    }
    require_once __DIR__ . '/../runtime/access-rules.php';
    if (!isset($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'])) {
        $principal = _stattic_access_wordpress_principal($serving, $host, $path);
        if ($principal !== null) {
            $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $principal;
            $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = $principal['wordpress_role'];
        }
    }
    $GLOBALS['SPACEFAST_SPACE_ROLE'] = _stattic_spacepress_space_role($serving, $host);
}

/**
 * The account the live Space session names, and the strongest role a single
 * whole-Space Grant gives it now. A Grant on one folder or one preview is
 * access to that folder or preview, not a role in the app, so only a Grant on
 * every path (`/**`, no excludes), on the live target, with no use limit,
 * network rule or frame origin counts. Capabilities never combine across
 * Grants. Null for an anonymous visitor, a runtime bearer, or a preview or
 * version host.
 *
 * @return array{role: string, issuer: string, subject: string}|null
 */
function _stattic_spacepress_space_role(array $serving, string $host): ?array
{
    if (_stattic_platform_bearer_token_from_request() !== null || _stattic_grant_target($serving) !== ['kind' => 'live']) {
        return null;
    }
    $identity = _stattic_current_session_identity($serving, $host);
    $account = is_array($identity)
        ? _stattic_access_account_identity(_stattic_access_identity_record($identity) ?? [])
        : null;
    $projection = _stattic_authorization_projection_current($serving['authorization'] ?? null);
    if ($account === null || $projection === null) {
        return null;
    }
    $role = _stattic_whole_space_role(
        $projection,
        is_array($identity['authorities'] ?? null) ? $identity['authorities'] : [],
        is_array($identity['emailVerifiedAuthorities'] ?? null) ? $identity['emailVerifiedAuthorities'] : []
    );
    return $role === null ? null : ['role' => $role] + $account;
}

/**
 * The strongest Space role one Grant on the whole live Space gives these
 * authorities now, or null.
 */
function _stattic_whole_space_role(array $projection, array $authorities, array $verifiedAuthorities): ?string
{
    require_once __DIR__ . '/space-roles.php';
    $best = null;
    foreach (_stattic_grant_candidates($projection, $authorities) as $grant) {
        if (
            !is_array($grant)
            || ($grant['include'] ?? null) !== [['**']]
            || ($grant['exclude'] ?? null) !== []
            || ($grant['target'] ?? null) !== ['kind' => 'live']
            || ($grant['maxUses'] ?? null) !== null
            || ($grant['network'] ?? null) !== null
            || ($grant['frameOrigin'] ?? null) !== null
        ) {
            continue;
        }
        $alone = ['grantIndex' => ['public' => ['fallback' => [$grant]], 'authorities' => []]] + $projection;
        $capabilities = _stattic_grant_decision($alone, '/', ['kind' => 'live'], [], $verifiedAuthorities, '', '', false, true)['capabilities'];
        if (!in_array('page.view', $capabilities, true)) {
            continue;
        }
        $role = _stattic_space_role_for_capabilities($capabilities);
        if ($best === null || _stattic_space_role_rank($role) > _stattic_space_role_rank($best)) {
            $best = $role;
        }
    }
    return $best;
}

/**
 * Before the app mounts, the platform's own credentials leave the request: the
 * Space session cookie and the platform bearer header. They admitted the
 * request and named its principal above; the app's code (its route handlers
 * read every request header) sees only the application's own cookies.
 */
function _stattic_spacepress_scrub_platform_credentials(): void
{
    require_once __DIR__ . '/upstream-relay.php';
    foreach (STATTIC_PLATFORM_CREDENTIAL_REQUEST_HEADERS as $header) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        unset($_SERVER[$key], $_SERVER['REDIRECT_' . $key]);
    }
    $cookie = is_string($_SERVER['HTTP_COOKIE'] ?? null) ? _stattic_cookie_header_without_platform_cookies($_SERVER['HTTP_COOKIE']) : '';
    unset($_SERVER['HTTP_COOKIE']);
    if ($cookie !== '') {
        $_SERVER['HTTP_COOKIE'] = $cookie;
    }
    foreach (array_keys($_COOKIE) as $name) {
        if (_stattic_platform_cookie_name((string) $name)) {
            unset($_COOKIE[$name]);
        }
    }
}
