<?php
declare(strict_types=1);

// The Spacefast must-use plugin for TypedPress apps. The engine loads it for
// every request that selects a compiled app (loader/wordpress.php), before
// normal plugins and before the app's own identity, so there is nothing to
// install or enable. It connects one app to Spacefast and nothing else:
//
// - who the visitor is: one row per person, keyed by the account the platform
//   knows them as. A signed-in Space visitor is that row on every request
//   (shared/typedpress.php resolves it before WordPress boots), and an app
//   that offers Sign in with Spacefast through its own TypedPress Identity
//   (`tp_identity_providers`) signs them in to the same row
//   (`tp_identity_provider_account`);
// - the person's Space role: lent on each request from the live Space session,
//   never written to WordPress (wordpress/space-role.php), so a narrowed or
//   ended Grant takes it away on the next request;
// - what WordPress may never do for anyone: the installation-wide capabilities
//   stay off, and a row the platform keeps holds no WordPress credential;
// - what the control plane may ask: the bridge runs named abilities only, and
//   `spacefast/runtime-report` answers the service identity alone.
//
// Table bindings stay in shared/zero-v2-scope.php. Background work is stock
// WP-Cron and Action Scheduler; this plugin schedules nothing.

require_once __DIR__ . '/../shared/wordpress-scope.php';

const SPACEFAST_TP_REPORT_ABILITY = 'spacefast/runtime-report';
const SPACEFAST_TP_ABILITY_CATEGORY = 'spacefast';
// The only actor the report answers: the control plane's own principal
// (runtimeContentPrincipalAssertion's default service actor).
const SPACEFAST_TP_SERVICE_ACTOR = 'spacefast-control-plane';
// Ability names the bridge may run on a TypedPress Space: core's ability REST
// route and nothing else. The Space Users lane keeps its own allow-list in
// entrypoints/content.php.
const SPACEFAST_TP_BRIDGE_PATH = '~\A/wp-abilities/v1/abilities/([a-z0-9-]+/[a-z0-9-]+)/run(?:\?[^#\s]*)?\z~';
// The provider name in TypedPress Identity: its sign-in starts at
// `/identity/provider/start?provider=spacefast`.
const SPACEFAST_TP_PROVIDER = 'spacefast';
// Where the Space's client sends a provider sign-in back to. A Space client
// accepts only `/__spacefast/oauth/<name>` with no query string
// (apps/control-plane/src/auth/share-oauth-callbacks.ts); shared/typedpress.php
// serves this path as TypedPress Identity's own provider callback.
const SPACEFAST_TP_PROVIDER_CALLBACK_PATH = '/__spacefast/oauth/typedpress';

/** Installed by the loader for a TypedPress binding. Idempotent. */
function spacefast_tp_install(): void
{
    static $installed = false;
    if ($installed || !function_exists('add_action')) return;
    $installed = true;
    require_once __DIR__ . '/content-principals.php';
    require_once __DIR__ . '/space-role.php';
    // The access layer already decided this request may proceed and who it
    // is (a signed-in Space visitor, a runtime bearer, the dashboard editor,
    // or the control plane on the bridge); that principal becomes the
    // WordPress user, after WordPress's own resolvers, so an account the app
    // signed in itself stays that account. A person who signs in through the
    // Spacefast provider below lands on the same row.
    remove_filter('determine_current_user', 'spacefast_content_principal_current_user', 1);
    remove_action('plugins_loaded', 'spacefast_content_principal_establish_user', 1);
    // Capabilities: a person's come only from the Space role lent on this
    // request (space-role.php), never from their Grants on this path. A
    // service actor and the dashboard editor keep their own mapping.
    remove_filter('user_has_cap', 'spacefast_content_principal_capabilities', 10);
    $principal = $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null;
    if (is_array($principal) && in_array($principal['kind'] ?? null, ['user', 'service'], true)) {
        add_filter('determine_current_user', 'spacefast_tp_current_user', 30);
        add_action('plugins_loaded', 'spacefast_tp_establish_user', 1);
        if (($principal['kind'] ?? null) === 'service' || !empty($GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID']) || !empty($GLOBALS['SPACEFAST_CONTENT_ADMIN_LAUNCH'])) {
            add_filter('user_has_cap', 'spacefast_tp_principal_capabilities', 10, 4);
        }
    }
    // The visitor's platform credentials admitted the request and named its
    // principal. Before any app handler runs (routes and actions dispatch from
    // parse_request, admin screens from admin_init), they leave the request:
    // the app's code reads every request header and sees only the
    // application's own cookies. Not earlier: on wp.cloud the access check
    // runs again from wp_loaded (loader/wordpress.php) and reads them.
    add_filter('do_parse_request', 'spacefast_tp_scrub_platform_credentials', PHP_INT_MIN);
    add_action('admin_init', 'spacefast_tp_scrub_platform_credentials', PHP_INT_MIN);
    // Sign in with Spacefast, through TypedPress Identity's provider hook.
    add_filter('tp_identity_providers', 'spacefast_tp_identity_providers');
    add_filter('tp_identity_provider_account', 'spacefast_tp_identity_provider_account', 10, 3);
    add_filter('home_url', 'spacefast_tp_provider_callback_url', 20, 2);
    // The lent role, from the live Space session on this request.
    add_filter('user_has_cap', 'spacefast_space_role_capabilities', 20, 4);
    // A row the platform keeps authenticates only through the platform: it
    // holds no WordPress password, application password or WordPress session
    // that could outlive the person's Spacefast sign-in or access.
    add_filter('authenticate', 'spacefast_tp_refuse_projected_login', PHP_INT_MAX, 1);
    add_filter('wp_authenticate_user', 'spacefast_tp_refuse_projected_login', PHP_INT_MAX, 1);
    add_filter('check_password', 'spacefast_tp_refuse_projected_password', PHP_INT_MAX, 4);
    add_filter('wp_is_application_passwords_available_for_user', 'spacefast_tp_application_passwords_available', PHP_INT_MAX, 2);
    add_filter('determine_current_user', 'spacefast_tp_refuse_projected_cookie', 25);
    add_filter('wp_pre_insert_user_data', 'spacefast_tp_projected_profile_fields', PHP_INT_MAX, 4);
    add_filter('send_auth_cookies', 'spacefast_tp_refuse_projected_auth_cookie', PHP_INT_MAX, 6);
    add_filter('session_token_manager', 'spacefast_tp_session_token_manager', PHP_INT_MAX);
    add_filter('allow_password_reset', 'spacefast_tp_refuse_projected_password_reset', PHP_INT_MAX, 2);
    add_action('wp_set_password', 'spacefast_tp_undo_projected_password', PHP_INT_MAX, 3);
    add_action('wp_create_application_password', 'spacefast_tp_undo_projected_application_password', PHP_INT_MAX, 2);
    add_filter('rest_request_before_callbacks', 'spacefast_tp_projected_rest_guard', 5, 3);
    // Whoever the user is, installation-wide capabilities stay off: every
    // Space shares one WordPress installation.
    add_filter('user_has_cap', 'spacefast_tp_installation_ceiling', PHP_INT_MAX, 4);
    add_action('wp_abilities_api_categories_init', 'spacefast_tp_register_ability_category');
    add_action('wp_abilities_api_init', 'spacefast_tp_register_abilities');
}

/**
 * Spacefast as an OpenID provider of the app's TypedPress Identity: the
 * Space's own public client (PKCE, no secret) at the issuer the control plane
 * configured for this Space. A Space without Sign in with Spacefast offers
 * nothing; the app keeps its own providers either way.
 */
function spacefast_tp_identity_providers(mixed $providers): mixed
{
    if (!is_array($providers)) return $providers;
    $issuer = spacefast_tp_account_issuer();
    $spaceId = spacefast_content_space_id();
    if ($issuer === null || $spaceId === '') return $providers;
    $providers[SPACEFAST_TP_PROVIDER] = [
        'issuer' => $issuer,
        'client_id' => 'spacefast-share:' . $spaceId,
        'secret' => '',
        'alg' => 'EdDSA',
    ];
    return $providers;
}

/**
 * TypedPress Identity names its provider callback with `home_url()`; for the
 * Spacefast provider that is the Space's reserved app callback on the host
 * this request arrived on, the one address a Space client accepts.
 */
function spacefast_tp_provider_callback_url(mixed $url, mixed $path = ''): mixed
{
    if ($path !== '/identity/provider/callback?provider=' . SPACEFAST_TP_PROVIDER) return $url;
    $origin = spacefast_content_request_origin();
    return $origin === '' ? $url : $origin . SPACEFAST_TP_PROVIDER_CALLBACK_PATH;
}

/** The Space's Sign in with Spacefast issuer, as its route config names it, or null without one. */
function spacefast_tp_account_issuer(): ?string
{
    $serving = $GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null;
    $issuer = is_array($serving) ? ($serving['authorization']['accessPage']['issuer'] ?? null) : null;
    if (!is_string($issuer) || preg_match('~\Ahttps://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/v1/auth\z~', rtrim($issuer, '/')) !== 1) {
        return null;
    }
    return rtrim($issuer, '/');
}

/**
 * The WordPress user a Sign in with Spacefast identity signs in to: the one row
 * the platform keeps for that person on this Space, found or made, so a person
 * is one user whichever way the platform met them. An identity from any other
 * issuer is the app's own matter.
 */
function spacefast_tp_identity_provider_account(mixed $userId, mixed $issuer, mixed $subject): mixed
{
    if ((int) $userId > 0 || !is_string($issuer) || !is_string($subject)) return $userId;
    $expected = spacefast_tp_account_issuer();
    if ($expected === null || !hash_equals($expected, rtrim($issuer, '/'))
        || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $subject) !== 1) {
        return $userId;
    }
    // Keyed as the Space session keys the account (the runtime's configured
    // account issuer), which a split auth host can put on another origin than
    // the provider's issuer: one person, one row, whichever way they came in.
    $account = function_exists('_stattic_runtime_api_base_url') && _stattic_runtime_api_base_url() !== ''
        ? _stattic_runtime_api_base_url() . '/v1/auth'
        : $expected;
    $found = spacefast_content_principal_ensure_user([
        'kind' => 'user',
        'actor_id' => $subject,
        'issuer' => $account,
        'subject' => $subject,
        'principal_id' => spacefast_content_principal_authority($account, $subject),
        'profile' => [],
    ], false);
    return $found > 0 ? $found : $userId;
}

/**
 * The platform principal as the WordPress user, after WordPress's own
 * resolvers: a user WordPress already identified (an app-local account) wins.
 */
function spacefast_tp_current_user(mixed $currentUser): mixed
{
    if (is_int($currentUser) && $currentUser > 0) return $currentUser;
    return spacefast_content_principal_current_user($currentUser);
}

/** The platform principal's own role (its Grant capabilities), for its own row only. */
function spacefast_tp_principal_capabilities(array $capabilities, array $required, array $arguments, mixed $user): array
{
    $userId = is_object($user) ? (int) ($user->ID ?? 0) : 0;
    $identity = spacefast_content_principal_identity();
    $authority = spacefast_content_principal_user_authority($userId);
    if (!is_array($identity) || ($identity['kind'] ?? null) === 'anonymous' || !is_array($authority)
        || !hash_equals((string) $identity['issuer'], $authority['issuer'])
        || !hash_equals((string) $identity['subject'], $authority['subject'])) {
        return $capabilities;
    }
    return spacefast_content_principal_capabilities($capabilities, $required, $arguments, $user);
}

/** Resolve the WordPress user now, then take the platform's credentials out of the request. */
function spacefast_tp_scrub_platform_credentials(mixed $passthrough = null): mixed
{
    if (function_exists('wp_get_current_user')) wp_get_current_user();
    if (function_exists('_stattic_typedpress_scrub_platform_credentials')) _stattic_typedpress_scrub_platform_credentials();
    return $passthrough;
}

/** As content-principals.php's establish step, after WordPress's own resolvers. */
function spacefast_tp_establish_user(): void
{
    if (!function_exists('get_current_user_id') || get_current_user_id() > 0) return;
    $userId = spacefast_tp_current_user(0);
    if (is_int($userId) && $userId > 0 && function_exists('wp_set_current_user')) wp_set_current_user($userId);
}

/** Whether a WordPress user id is a row the platform keeps for a person or service. */
function spacefast_tp_is_projected(int $userId): bool
{
    return $userId > 0 && spacefast_content_principal_user_authority($userId) !== null;
}

/** Any WordPress password sign-in (wp-login, XML-RPC, wp_authenticate, app Identity's password lane) refuses a platform row. */
function spacefast_tp_refuse_projected_login(mixed $user): mixed
{
    if ($user instanceof WP_User && spacefast_tp_is_projected((int) $user->ID)) {
        return new WP_Error('spacefast_projected_account', 'Sign in to this Space with Spacefast.', ['status' => 403]);
    }
    return $user;
}

/** A password check against a platform row never succeeds, whoever asks. */
function spacefast_tp_refuse_projected_password(mixed $check, mixed $password = null, mixed $hash = null, mixed $userId = null): mixed
{
    return is_numeric($userId) && spacefast_tp_is_projected((int) $userId) ? false : $check;
}

function spacefast_tp_refuse_projected_auth_cookie(mixed $send, mixed $expire = 0, mixed $expiration = 0, mixed $userId = 0): bool
{
    return (bool) $send && !(is_numeric($userId) && spacefast_tp_is_projected((int) $userId));
}

/**
 * WordPress keeps no session for a platform row: its session store holds none,
 * so any WordPress auth cookie for that row validates for nobody. The person's
 * session is TypedPress Identity's, which signs out with them. The platform's
 * editor session (content-admin.php) mints its own WordPress session for the
 * row it signs in, and keeps the stock store.
 */
function spacefast_tp_session_token_manager(mixed $manager): mixed
{
    if (!empty($GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID']) || !empty($GLOBALS['SPACEFAST_CONTENT_ADMIN_LAUNCH'])) return $manager;
    require_once __DIR__ . '/typedpress-session-tokens.php';
    return 'Spacefast_TP_Session_Tokens';
}

function spacefast_tp_refuse_projected_password_reset(mixed $allow, mixed $userId): mixed
{
    return is_numeric($userId) && spacefast_tp_is_projected((int) $userId)
        ? new WP_Error('spacefast_projected_account', 'This account signs in with Spacefast.')
        : $allow;
}

/** A platform row keeps the random password it was created with: any other is replaced at once. */
function spacefast_tp_undo_projected_password(mixed $password, mixed $userId): void
{
    static $undoing = false;
    if ($undoing || !is_numeric($userId) || !spacefast_tp_is_projected((int) $userId)) return;
    $undoing = true;
    wp_set_password(bin2hex(random_bytes(32)), (int) $userId);
    $undoing = false;
}

function spacefast_tp_undo_projected_application_password(mixed $userId, mixed $item): void
{
    if (!is_numeric($userId) || !spacefast_tp_is_projected((int) $userId) || !class_exists('WP_Application_Passwords')) return;
    WP_Application_Passwords::delete_all_application_passwords((int) $userId);
}

function spacefast_tp_application_passwords_available(mixed $available, mixed $user): bool
{
    return (bool) $available && !($user instanceof WP_User && spacefast_tp_is_projected((int) $user->ID));
}

/**
 * A WordPress auth cookie names a platform row only when the platform minted
 * it for this request's own principal (the content-admin editor session
 * resolves before this). Otherwise the row is not this request's user.
 */
function spacefast_tp_refuse_projected_cookie(mixed $currentUser): mixed
{
    if (!is_int($currentUser) || $currentUser < 1 || !spacefast_tp_is_projected($currentUser)) return $currentUser;
    if (($GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null) === $currentUser) return $currentUser;
    $identity = spacefast_content_principal_identity();
    $authority = spacefast_content_principal_user_authority($currentUser);
    return is_array($identity) && is_array($authority) && ($identity['kind'] ?? null) !== 'anonymous'
        && hash_equals((string) $identity['issuer'], $authority['issuer'])
        && hash_equals((string) $identity['subject'], $authority['subject'])
        ? $currentUser : 0;
}

/**
 * A platform row's own profile edits keep its platform-controlled fields:
 * password, email, login and nicename stay what they are. Display fields
 * remain editable. A new local row may not take the platform's login prefix.
 */
function spacefast_tp_projected_profile_fields(array $data, bool $update = false, mixed $userId = null, array $userdata = []): array
{
    $id = is_numeric($userId) ? (int) $userId : 0;
    // `spacefast_` logins belong to platform rows (content-principals.php
    // inserts them with that login and the principal meta right after). Any
    // other new account, an app's own sign-up included, gets another login.
    if (!$update && empty($GLOBALS['SPACEFAST_TP_INSERTING_PRINCIPAL'])
        && str_starts_with(strtolower((string) ($data['user_login'] ?? '')), 'spacefast_')) {
        $data['user_login'] = 'tp_' . substr((string) $data['user_login'], strlen('spacefast_'));
    }
    if ($update && spacefast_tp_is_projected($id) && function_exists('get_userdata')) {
        $stored = get_userdata($id);
        if ($stored instanceof WP_User) {
            foreach (['user_pass', 'user_email', 'user_login', 'user_nicename'] as $field) {
                if (array_key_exists($field, $data)) $data[$field] = $stored->data->{$field};
            }
        }
    }
    return $data;
}

/**
 * The users REST endpoint, for a caller on a platform row: no password or
 * email change on any account (core reports success otherwise and the insert
 * filter above keeps the old value), and no searching or ordering by email,
 * which would confirm which local account holds an address.
 */
function spacefast_tp_projected_rest_guard(mixed $response, mixed $handler, mixed $request): mixed
{
    if (!$request instanceof WP_REST_Request || !function_exists('get_current_user_id')
        || !spacefast_tp_is_projected(get_current_user_id())
        || preg_match('#^/wp/v2/users(?:/|$)#i', $request->get_route()) !== 1) {
        return $response;
    }
    if (in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)
        && ($request->get_param('password') !== null || $request->get_param('email') !== null)) {
        return new WP_Error('spacefast_projected_credentials', 'This account signs in with Spacefast; its password and email are not changed here.', ['status' => 403]);
    }
    if (strtolower((string) $request->get_param('orderby')) === 'email') $request->set_param('orderby', 'name');
    $search = $request->get_param('search');
    if (is_string($search) && $search !== '') {
        // Without columns, WordPress searches user_email too for a caller with
        // `list_users`. Name the non-email columns explicitly.
        $columns = $request->get_param('search_columns');
        $allowed = array_values(array_diff(is_array($columns) && $columns !== [] ? $columns : ['id', 'username', 'slug', 'name'], ['email']));
        $request->set_param('search_columns', $allowed === [] ? ['name'] : $allowed);
    }
    return $response;
}

/**
 * Installation-wide capabilities stay off for every TypedPress user. One
 * exception: an account the app made itself keeps `manage_options` from its
 * own stored role, which TypedPress reads as its app administrator
 * (WpAuth::map) and which in this binding reaches only the Space's own options
 * table. A lent manager role grants `manage_options` the same way
 * (space-role.php). Everything else on the list is off for everyone.
 *
 * A platform row also never holds `edit_users`, `unfiltered_html`,
 * `edit_files` or `unfiltered_upload`, whatever role reaches this filter:
 * editing another account is changing its email and password, and raw HTML or
 * uploads would run as script on the Space's origin.
 */
function spacefast_tp_installation_ceiling(array $capabilities, array $required = [], array $arguments = [], mixed $user = null): array
{
    $userId = is_object($user) ? (int) ($user->ID ?? 0) : 0;
    $appAdministrator = ($capabilities['manage_options'] ?? false) === true
        && (spacefast_space_role_lent($userId) === 'manager' || (!spacefast_tp_is_projected($userId) && $userId > 0));
    $capabilities = spacefast_content_principal_scope_capabilities($capabilities);
    if ($userId < 1 || spacefast_tp_is_projected($userId)) {
        $capabilities['edit_users'] = false;
        $capabilities['unfiltered_html'] = false;
        $capabilities['edit_files'] = false;
        $capabilities['unfiltered_upload'] = false;
    }
    if ($appAdministrator) $capabilities['manage_options'] = true;
    return $capabilities;
}

/** The ability name a bridge path runs, or null for any other REST path. */
function spacefast_tp_bridge_ability(mixed $path): ?string
{
    return is_string($path) && preg_match(SPACEFAST_TP_BRIDGE_PATH, $path, $match) === 1 ? $match[1] : null;
}

function spacefast_tp_register_ability_category(): void
{
    if (!function_exists('wp_register_ability_category') || (function_exists('wp_has_ability_category') && wp_has_ability_category(SPACEFAST_TP_ABILITY_CATEGORY))) return;
    wp_register_ability_category(SPACEFAST_TP_ABILITY_CATEGORY, [
        'label' => 'Spacefast',
        'description' => 'What the Spacefast platform reads from this Space.',
    ]);
}

function spacefast_tp_register_abilities(): void
{
    if (!function_exists('wp_register_ability')) return;
    wp_register_ability(SPACEFAST_TP_REPORT_ABILITY, [
        'label' => 'Runtime report',
        'description' => 'The selected build, its readiness and the engine serving it. No app data.',
        'category' => SPACEFAST_TP_ABILITY_CATEGORY,
        'output_schema' => [
            'type' => 'object',
            'required' => ['space_id', 'version_id', 'app', 'ready', 'engine_revision'],
            'properties' => [
                'space_id' => ['type' => 'string'],
                'version_id' => ['type' => ['string', 'null']],
                'app' => ['type' => ['string', 'null']],
                'compiler' => ['type' => ['string', 'null']],
                'ready' => ['type' => 'boolean'],
                'engine_revision' => ['type' => 'string'],
                'realtime' => ['type' => 'boolean'],
            ],
        ],
        'permission_callback' => 'spacefast_tp_report_permitted',
        'execute_callback' => 'spacefast_tp_report',
        // Not public: no MCP adapter or app client lists it. The bridge names it.
        'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true], 'show_in_rest' => true],
    ]);
}

/** The report answers the control plane's service actor on the bridge, and nobody else. */
function spacefast_tp_report_permitted(): bool
{
    $principal = $GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null;
    return ($GLOBALS['SPACEFAST_TP_BRIDGE_ABILITY'] ?? null) === SPACEFAST_TP_REPORT_ABILITY
        && is_array($principal)
        && ($principal['kind'] ?? null) === 'service'
        && ($principal['actor_id'] ?? null) === SPACEFAST_TP_SERVICE_ACTOR;
}

/** Build identity and readiness, read from the selected immutable version. */
function spacefast_tp_report(): array
{
    $root = (string) ($GLOBALS['SPACEFAST_ZERO_V2_ROOT'] ?? '');
    $manifest = is_file($root . '/manifest.json') ? json_decode((string) file_get_contents($root . '/manifest.json'), true) : null;
    $ready = is_file($root . '/ready.json') ? json_decode((string) file_get_contents($root . '/ready.json'), true) : null;
    $metadata = is_file(dirname($root) . '/metadata.json') ? json_decode((string) file_get_contents(dirname($root) . '/metadata.json'), true) : null;
    return [
        'space_id' => spacefast_content_space_id(),
        'version_id' => is_array($ready) && is_string($ready['version_id'] ?? null) ? $ready['version_id'] : null,
        'app' => is_array($manifest) && is_string($manifest['app'] ?? null) ? $manifest['app'] : null,
        'compiler' => is_array($metadata) && is_string($metadata['zeroV2']['compiler'] ?? null) ? $metadata['zeroV2']['compiler'] : null,
        'ready' => is_array($ready),
        'engine_revision' => defined('SPACEFAST_RUNTIME_ENGINE_REVISION') ? (string) SPACEFAST_RUNTIME_ENGINE_REVISION : 'unknown',
        // A `tp_v1` build registers no change publisher, and the control plane
        // fans out only `v2` notices, so its pages poll.
        'realtime' => _stattic_typedpress_manifest($root) === null && spacefast_tp_realtime_named($root),
    ];
}

/** Whether the version names a live-update room. The report reads nothing else of it. */
function spacefast_tp_realtime_named(string $compiledRoot): bool
{
    $file = $compiledRoot . '/realtime.json';
    $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($config)) return false;
    foreach (['url', 'cast_resource_key', 'cast_ws_url'] as $field) {
        if (!is_string($config[$field] ?? null) || $config[$field] === '') return false;
    }
    return true;
}
