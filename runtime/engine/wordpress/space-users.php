<?php
declare(strict_types=1);

/**
 * Space Users is one account system per Space with two storage generations. A Zero v2 binding
 * keeps it in the Space's own tables through the version's zero-identity plugin; every other
 * Space keeps it in the site tables through spacefast-identity. Both expose the same classes,
 * methods and table shapes, so this file names the class through here and never both at once.
 */
function spacefast_space_users_identity(string $class): string
{
    return (spacefast_space_users_scoped() ? '\\Zero\\Identity\\' : '\\Spacefast\\Identity\\') . $class;
}

/** True when the Space's own (Zero v2) tables hold its users: membership is the binding itself. */
function spacefast_space_users_scoped(): bool
{
    return isset($GLOBALS['SPACEFAST_ZERO_V2_ROOT']);
}

/** The identity plugin's hook and REST namespace prefix: `zero_identity` or `spacefast_identity`. */
function spacefast_space_users_hook(string $suffix): string
{
    return (spacefast_space_users_scoped() ? 'zero_identity_' : 'spacefast_identity_') . $suffix;
}

function spacefast_space_users_rest_namespace(): string
{
    return spacefast_space_users_scoped() ? 'zero-identity/v1' : 'spacefast-identity/v1';
}

function spacefast_space_users_cookie_name(string $name): string
{
    return spacefast_space_users_identity('Sessions')::cookieName($name);
}

function spacefast_space_users_error(int $status, string $code, string $message): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** The identity plugin's own refusal, which its REST and portal handlers answer with. */
function spacefast_space_users_failure(string $kind, string $message, int $status): RuntimeException
{
    $class = spacefast_space_users_identity('Failure');
    return new $class($kind, $message, $status);
}

require_once __DIR__ . '/../shared/space-users-parts.php';

function spacefast_space_users_settings(): array
{
    $value = $GLOBALS['SPACEFAST_PAGE_SERVING']['users'] ?? [];
    return is_array($value) ? array_replace([
        'enabled' => false,
        'providers' => ['google' => ['mode' => 'disabled'], 'gravatar' => ['enabled' => false], 'spacefast' => ['enabled' => false]],
    ], $value) : ['enabled' => false, 'providers' => []];
}

function spacefast_space_users_install_hooks(): void
{
    $identity = spacefast_space_users_identity('Plugin');
    $namespace = '/' . spacefast_space_users_rest_namespace() . '/';
    add_filter('wp_redirect', static function (string $location) use ($identity): string {
        $portal = home_url('/identity');
        if (isset($_COOKIE[spacefast_space_users_cookie_name('sfi_app_return')]) && str_starts_with($location, $portal . '?factor=')) {
            return $location . '&return_to=' . rawurlencode('/__zero/auth/complete');
        }
        if (!in_array($location, [$portal, home_url('/identity/account')], true) || !isset($GLOBALS['SPACEFAST_SPACE_USERS_AUTHENTICATED_USER_ID'])) return $location;
        $cookie = $_COOKIE[spacefast_space_users_cookie_name('sfi_app_return')] ?? null;
        if (!is_string($cookie)) return $location;
        [$id, $binding] = array_pad(explode('.', $cookie, 2), 2, '');
        try {
            $challenge = $identity::$security->consume($id, 'space_app_return', $binding);
        } catch (\Spacefast\Identity\Failure|\Zero\Identity\Failure $error) {
            return $location;
        }
        setcookie(spacefast_space_users_cookie_name('sfi_app_return'), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $path = $challenge['payload']['path'] ?? '';
        return is_string($path) && preg_match('~\A/(?!/)[^\x00-\x20\\\\]*\z~', $path) === 1 ? home_url($path) : $location;
    });
    add_filter(spacefast_space_users_hook('providers'), 'spacefast_space_users_providers');
    add_filter('rest_post_dispatch', static function (mixed $response, mixed $server, mixed $request) use ($namespace): mixed {
        if ($request->get_route() === $namespace . 'config' && $response->get_status() === 200) {
            $data = $response->get_data();
            $settings = spacefast_space_users_settings();
            $providers = [];
            $availability = spacefast_space_users_provider_configuration()['availability'] ?? [];
            $googleMode = $settings['providers']['google']['mode'] ?? 'disabled';
            if (in_array($googleMode, ['managed', 'direct'], true) && ($availability['google'][$googleMode] ?? false) === true) $providers[] = 'google';
            if (($settings['providers']['gravatar']['enabled'] ?? false) === true && ($availability['gravatar'] ?? false) === true) $providers[] = 'gravatar';
            if (($settings['providers']['spacefast']['enabled'] ?? false) === true) $providers[] = 'spacefast';
            $data['data']['providers'] = $providers;
            $response->set_data($data);
        }
        return $response;
    }, 10, 3);
    add_filter('plugins_url', static function (string $url, string $path, string $plugin): string {
        if (str_ends_with($plugin, '/spacefast-identity/spacefast-identity.php') || str_ends_with($plugin, '/zero-identity/zero-identity.php')) {
            return home_url('/identity/assets/' . $path);
        }
        return $url;
    }, 10, 3);
    add_filter('rest_url', static function (string $url, string $path) use ($namespace): string {
        if (str_starts_with('/' . ltrim($path, '/'), $namespace)) {
            return home_url('/__zero/auth/api/' . substr('/' . ltrim($path, '/'), strlen($namespace)));
        }
        return $url;
    }, 10, 2);
    add_filter('rest_pre_dispatch', static function (mixed $result, mixed $server, mixed $request) use ($namespace): mixed {
        $route = $request->get_route();
        if (!str_starts_with($route, $namespace)) return $result;
        $settings = spacefast_space_users_settings();
        // On a v2 binding identity mounts only while Users is on, so only the part gate applies.
        if (!spacefast_space_users_scoped() && $settings['enabled'] !== true) {
            return spacefast_space_users_error(404, 'space_users_disabled', 'Users is not enabled for this Space.');
        }
        $method = _stattic_space_users_operation_method(substr($route, strlen($namespace)));
        if ($method !== null && !_stattic_space_users_method_allowed($settings, $method)) {
            return spacefast_space_users_error(403, 'space_users_provider_disabled', 'This sign-in method is not enabled for this Space.');
        }
        return $result;
    }, 1, 3);
    add_filter('rest_pre_dispatch', static function (mixed $result, mixed $server, mixed $request) use ($identity, $namespace): mixed {
        if ($result !== null || $request->get_route() !== $namespace . 'providers/link' || $request->get_method() !== 'POST') return $result;
        $provider = $request->get_param('provider');
        if ($provider !== 'spacefast') {
            if (is_string($provider) && function_exists('_stattic_space_users_provider')) $request->set_param('provider', _stattic_space_users_provider($provider));
            return null;
        }
        try {
            spacefast_space_users_identity('Api')::checkOrigin((string) $request->get_header('origin'), true);
            if ((spacefast_space_users_settings()['providers']['spacefast']['enabled'] ?? false) !== true) {
                throw spacefast_space_users_failure('provider_disabled', 'Spacefast sign-in is not enabled.', 403);
            }
            $session = $identity::$sessions->require((string) $request->get_header('x-identity-csrf'));
            $data = $identity::$db->credentialMutation($session, static fn (): array => ['url' => home_url('/identity/provider/link?provider=spacefast')]);
            return new WP_REST_Response(['data' => $data], 200, ['Cache-Control' => 'no-store']);
        } catch (\Spacefast\Identity\Failure|\Zero\Identity\Failure $error) {
            return new WP_REST_Response(['error' => ['code' => $error->kind, 'message' => $error->getMessage()]], $error->status, ['Cache-Control' => 'no-store']);
        }
    }, 2, 3);
    add_action(spacefast_space_users_hook('session_event'), static function (string $type, int $userId): void {
        if ($type !== 'session.created') return;
        $GLOBALS['SPACEFAST_SPACE_USERS_AUTHENTICATED_USER_ID'] = $userId;
        spacefast_space_users_join($userId);
    }, 10, 2);
    if (spacefast_space_users_scoped()) {
        // The Space's tables hold only its own accounts, and the app owns their roles. The
        // session cookie authenticates /zero/v1 too, from the app's own pages only; a first
        // Spacefast sign-in creates the account its platform identity then keeps.
        add_filter('zero_identity_rest_cookie_auth', '__return_true');
        add_filter('zero_identity_provider_account', 'spacefast_space_users_native_account', 10, 4);
        return;
    }
    add_filter('determine_current_user', static function (mixed $current): mixed {
        if (is_array($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null)) return $current;
        $session = spacefast_space_users_live_session();
        if ($session === null) return $current;
        $GLOBALS['SPACEFAST_SPACE_USERS_WP_SESSION_USER'] = (int) $session['wp_user_id'];
        return (int) $session['wp_user_id'];
    }, 2);
    add_filter('user_has_cap', static function (array $caps, array $required, array $args, mixed $user): array {
        if (!is_array($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] ?? null)
            && isset($GLOBALS['SPACEFAST_SPACE_USERS_WP_SESSION_USER'])
            && (int) ($user->ID ?? 0) === $GLOBALS['SPACEFAST_SPACE_USERS_WP_SESSION_USER']) return ['read' => true];
        return $caps;
    }, PHP_INT_MAX, 4);
    add_action('plugins_loaded', static function (): void {
        if (!wp_next_scheduled('spacefast_identity_maintenance')) {
            wp_schedule_event(time() + 60, 'hourly', 'spacefast_identity_maintenance');
        }
    }, 3);
}

/** zero-identity's account for a platform identity on its first Spacefast sign-in, inside its transaction. */
function spacefast_space_users_native_account(int $userId, string $issuer, string $subject, string $name): int
{
    if ($userId > 0 || ($GLOBALS['SPACEFAST_SPACE_USERS_NATIVE_IDENTITY'] ?? null) !== [$issuer, $subject]) return $userId;
    $created = wp_insert_user([
        'user_login' => 'sf_' . bin2hex(random_bytes(16)), 'user_pass' => wp_generate_password(48, true, true),
        'display_name' => $name !== '' ? $name : 'Spacefast user', 'role' => 'subscriber',
    ]);
    if (is_wp_error($created)) throw spacefast_space_users_failure('account_creation_failed', 'Account could not be created.', 409);
    return (int) $created;
}

/** Private desired state exists only in the hosted auth process, never tenant handlers. */
function spacefast_space_users_provider_configuration(): array
{
    $configuration = $GLOBALS['SPACEFAST_SPACE_USERS_PROVIDER_CONFIGURATION'] ?? null;
    return is_array($configuration) ? $configuration : [];
}

function spacefast_space_users_providers(array $providers): array
{
    $configuration = spacefast_space_users_provider_configuration();
    $settings = spacefast_space_users_settings();
    $providers = [];
    $available = $configuration['availability'] ?? [];
    $managed = (($settings['providers']['google']['mode'] ?? '') === 'managed' && ($available['google']['managed'] ?? false) === true)
        || (($settings['providers']['gravatar']['enabled'] ?? false) === true && ($available['gravatar'] ?? false) === true);
    if ($managed && isset($configuration['issuer'], $configuration['clientId'])) {
        $providers['spacefast'] = [
            'issuer' => $configuration['issuer'], 'client_id' => $configuration['clientId'], 'secret' => '', 'alg' => 'EdDSA',
        ];
        $selected = $GLOBALS['SPACEFAST_SPACE_USERS_SELECTED_PROVIDER'] ?? null;
        if (in_array($selected, ['google', 'gravatar'], true)) {
            $providers['spacefast']['discovery'] = ['authorization_endpoint' => $configuration[$selected . 'StartUrl']];
        }
    }
    if (($settings['providers']['google']['mode'] ?? '') === 'direct' && ($available['google']['direct'] ?? false) === true && is_array($configuration['directGoogle'] ?? null)) {
        $providers['google'] = [
            'issuer' => 'https://accounts.google.com',
            'client_id' => $configuration['directGoogle']['clientId'],
            'secret' => $configuration['directGoogle']['clientSecret'],
        ];
    }
    return $providers;
}

function spacefast_space_users_live_session(): ?array
{
    if (!spacefast_space_users_available() || spacefast_space_users_settings()['enabled'] !== true) return null;
    $sessions = spacefast_space_users_identity('Sessions');
    $cookie = $_COOKIE[$sessions::cookieName($sessions::COOKIE)] ?? null;
    if (!is_string($cookie)) return null;
    $session = spacefast_space_users_identity('Plugin')::$sessions->find($cookie);
    return $session !== null && spacefast_space_users_member((int) $session['wp_user_id']) ? $session : null;
}

/**
 * A Space User of this Space. On the site tables: a user marked as this Space's
 * app user. In a Zero v2 Space's own tables: any saved account, but not a guest
 * and not the WordPress user a dashboard or agent principal acts as.
 */
function spacefast_space_users_member(int $userId): bool
{
    if ($userId < 1) return false;
    if (spacefast_space_users_scoped()) {
        // An identity account is what makes a Space User: only zero-identity's own
        // sign-in writes one (email, a provider, or native Sign in with Spacefast).
        // The WordPress user a dashboard or agent principal acts as has none.
        $database = spacefast_space_users_identity('Plugin')::$db;
        return !spacefast_space_users_identity('Accounts')::isGuest($userId)
            && $database->row("SELECT 1 AS member FROM {$database->prefix}accounts WHERE wp_user_id=%d", $userId) !== null;
    }
    return get_user_meta($userId, '_spacefast_app_user', true) === spacefast_content_space_id() && spacefast_content_users_in_space($userId);
}

/** The list query's form of spacefast_space_users_member(), on `u` and with one `%s` for the Space id. */
function spacefast_space_users_member_sql(): string
{
    $wp = spacefast_space_users_identity('Plugin')::$db->wp;
    if (spacefast_space_users_scoped()) {
        $accounts = spacefast_space_users_identity('Plugin')::$db->prefix . 'accounts';
        return "%s<>'' AND EXISTS (SELECT 1 FROM {$accounts} ia WHERE ia.wp_user_id=u.ID)"
            . " AND NOT EXISTS (SELECT 1 FROM {$wp->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='{$wp->prefix}capabilities' AND m.meta_value LIKE '%%\"zero_guest\"%%')";
    }
    return "EXISTS (SELECT 1 FROM {$wp->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='_spacefast_app_user' AND m.meta_value=%s)";
}

/** This identifies the app account; it carries no platform authority or capability. */
function spacefast_space_users_auth(): ?array
{
    $session = spacefast_space_users_live_session();
    if ($session === null) return null;
    $user = get_userdata((int) $session['wp_user_id']);
    if (!$user) return null;
    // An app member who signed in before Users was on gets the Space-scoped id now.
    if (spacefast_space_users_scoped()) spacefast_space_users_join((int) $user->ID);
    $subject = spacefast_space_users_subject((int) $user->ID);
    return [
        'user' => ['id' => $subject, 'displayName' => $user->display_name],
        'userId' => $subject, 'displayName' => $user->display_name,
        'provider' => 'space-users', 'isGuest' => false, 'isAuthenticated' => true,
    ];
}

function spacefast_space_users_available(): bool
{
    return class_exists(spacefast_space_users_identity('Plugin'), false);
}

function spacefast_space_users_subject_key(int $userId): string
{
    return 'spacefast_app_subject_' . $userId . '_' . spacefast_content_space_id();
}

function spacefast_space_users_join(int $userId): void
{
    if ($userId < 1 || spacefast_content_space_id() === '') throw new RuntimeException('space_users_identity_scope_missing');
    // A guest an app signed in is not a saved account until it verifies an email.
    if (spacefast_space_users_scoped() && !spacefast_space_users_member($userId)) return;
    // option_name is unique: concurrent first logins converge on the first random ID.
    add_option(spacefast_space_users_subject_key($userId), 'usr_' . bin2hex(random_bytes(32)), '', false);
    if (spacefast_space_users_scoped()) return;
    spacefast_content_users_join_space($userId);
    update_user_meta($userId, '_spacefast_app_user', spacefast_content_space_id());
}

/** Durable app identity survives domain changes and credential/signing-key rotation. */
function spacefast_space_users_subject(int $userId): string
{
    if (spacefast_content_space_id() === '' || $userId < 1) throw new RuntimeException('space_users_identity_scope_missing');
    $subject = get_option(spacefast_space_users_subject_key($userId), null);
    if (!is_string($subject) || preg_match('/\Ausr_[a-f0-9]{64}\z/', $subject) !== 1) {
        throw new RuntimeException('space_users_identity_unavailable');
    }
    return $subject;
}

function spacefast_space_users_account(mixed $input): mixed
{
    if (!spacefast_space_users_available()) {
        return spacefast_space_users_error(503, 'space_users_unavailable', 'Users is not installed on this Space yet.');
    }
    $userId = (int) (spacefast_content_users_input($input)['id'] ?? 0);
    $user = $userId > 0 ? get_userdata($userId) : false;
    if (!$user || !spacefast_space_users_member($userId)) {
        return spacefast_space_users_error(404, 'zero_wp_users_not_found', 'No such user belongs to this Space.');
    }
    $database = spacefast_space_users_identity('Plugin')::$db;
    $wp = $database->wp;
    $rows = $database->rows(
        "SELECT u.ID,u.display_name,a.status,a.primary_email_id,o.option_value AS subject FROM {$wp->users} u JOIN {$database->prefix}accounts a ON a.wp_user_id=u.ID JOIN {$wp->options} o ON o.option_name=%s WHERE u.ID=%d",
        spacefast_space_users_subject_key((int) $user->ID), (int) $user->ID
    );
    return $rows === []
        ? spacefast_space_users_error(404, 'zero_wp_users_not_found', 'No such app account belongs to this Space.')
        : spacefast_space_users_project_accounts($rows)[0];
}

function spacefast_space_users_list(mixed $input): mixed
{
    if (!spacefast_space_users_available()) {
        return spacefast_space_users_error(503, 'space_users_unavailable', 'Users is not installed on this Space yet.');
    }
    $input = spacefast_content_users_input($input);
    $page = (int) ($input['page'] ?? 1);
    $perPage = (int) ($input['perPage'] ?? 20);
    if ($page < 1 || $perPage < 1 || $perPage > 100) {
        return spacefast_space_users_error(400, 'zero_wp_users_page_invalid', 'The user page request is out of range.');
    }
    $database = spacefast_space_users_identity('Plugin')::$db;
    $wp = $database->wp;
    $search = '%' . $wp->esc_like(trim((string) ($input['search'] ?? ''))) . '%';
    $rows = $database->rows(
        "SELECT u.ID,u.display_name,a.status,a.primary_email_id,o.option_value AS subject FROM {$wp->users} u JOIN {$database->prefix}accounts a ON a.wp_user_id=u.ID JOIN {$wp->options} o ON o.option_name=CONCAT('spacefast_app_subject_',u.ID,'_',%s) WHERE " . spacefast_space_users_member_sql() . " AND a.status<>'deleted' AND (u.display_name LIKE %s OR EXISTS (SELECT 1 FROM {$database->prefix}emails e WHERE e.wp_user_id=u.ID AND e.normalized LIKE %s)) ORDER BY u.ID DESC LIMIT %d OFFSET %d",
        spacefast_content_space_id(), spacefast_content_space_id(), $search, $search, $perPage, ($page - 1) * $perPage
    );
    return ['page' => $page, 'perPage' => $perPage, 'users' => spacefast_space_users_project_accounts($rows)];
}

function spacefast_space_users_project_accounts(array $rows): array
{
    if ($rows === []) return [];
    $database = spacefast_space_users_identity('Plugin')::$db;
    $ids = implode(',', array_map(static fn (array $row): int => (int) $row['ID'], $rows));
    $emails = $database->rows("SELECT id,wp_user_id,normalized,verified_at FROM {$database->prefix}emails WHERE wp_user_id IN ($ids) ORDER BY id");
    $providers = $database->rows("SELECT id,wp_user_id,issuer FROM {$database->prefix}providers WHERE wp_user_id IN ($ids) ORDER BY id");
    return array_map(static fn (array $row): array => [
        'id' => (int) $row['ID'], 'subject' => $row['subject'],
        'displayName' => $row['display_name'], 'status' => $row['status'],
        'emails' => array_values(array_map(static fn (array $email): array => [
            'id' => (string) $email['id'], 'email' => $email['normalized'],
            'primary' => (string) $row['primary_email_id'] === (string) $email['id'],
            'verifiedAt' => (int) $email['verified_at'],
        ], array_filter($emails, static fn (array $email): bool => (int) $email['wp_user_id'] === (int) $row['ID']))),
        'providers' => array_values(array_map(static fn (array $provider): array => [
            'id' => (string) $provider['id'], 'issuer' => $provider['issuer'],
        ], array_filter($providers, static fn (array $provider): bool => (int) $provider['wp_user_id'] === (int) $row['ID']))),
    ], $rows);
}

function spacefast_space_users_sessions(mixed $input): mixed
{
    $account = spacefast_space_users_account($input);
    if (is_wp_error($account)) return $account;
    $database = spacefast_space_users_identity('Plugin')::$db;
    return ['sessions' => array_map(static fn (array $row): array => [
        'id' => $row['id'],
        'label' => $row['label'],
        'createdAt' => (int) $row['created_at'],
        'expiresAt' => (int) $row['expires_at'],
        'revokedAt' => $row['revoked_at'] === null ? null : (int) $row['revoked_at'],
    ], $database->rows(
        "SELECT id,label,created_at,expires_at,revoked_at FROM {$database->prefix}sessions WHERE wp_user_id=%d ORDER BY created_at DESC,id DESC LIMIT 100",
        $account['id']
    ))];
}

function spacefast_space_users_revoke(mixed $input): mixed
{
    $account = spacefast_space_users_account($input);
    if (is_wp_error($account)) return $account;
    spacefast_space_users_identity('Plugin')::$sessions->revoke($account['id'], $input['sessionId'] ?? null);
    return ['revoked' => true];
}

function spacefast_space_users_suspend(mixed $input): mixed
{
    $account = spacefast_space_users_account($input);
    if (is_wp_error($account)) return $account;
    if (!is_bool($input['suspended'] ?? null)) {
        return spacefast_space_users_error(400, 'space_users_status_invalid', 'suspended must be a boolean.');
    }
    return spacefast_space_users_identity('Plugin')::$db->transaction(static function () use ($account, $input): mixed {
        $database = spacefast_space_users_identity('Plugin')::$db;
        $row = $database->row("SELECT status FROM {$database->prefix}accounts WHERE wp_user_id=%d FOR UPDATE", $account['id']);
        if (!$row || !in_array($row['status'], ['active', 'suspended'], true)) {
            return spacefast_space_users_error(409, 'space_users_deletion_pending', 'An account awaiting or completing deletion cannot be reactivated.');
        }
        spacefast_space_users_identity('Plugin')::$accounts->suspend($account['id'], $input['suspended']);
        return spacefast_space_users_account($input);
    });
}

function spacefast_space_users_complete_deletion(mixed $input): mixed
{
    $account = spacefast_space_users_account($input);
    if (is_wp_error($account)) return $account;
    try {
        (new (spacefast_space_users_identity('Privacy'))(spacefast_space_users_identity('Plugin')::$db, spacefast_space_users_identity('Plugin')::$security))->erase($account['id']);
    } catch (\Spacefast\Identity\Failure|\Zero\Identity\Failure $error) {
        return spacefast_space_users_error($error->status, $error->kind, $error->getMessage());
    }
    return ['deleted' => true];
}

function spacefast_space_users_abilities(): array
{
    $object = 'spacefast_content_users_object_schema';
    $id = ['type' => 'integer', 'minimum' => 1];
    $account = $object([
        'id' => $id,
        'subject' => ['type' => 'string'],
        'displayName' => ['type' => 'string'],
        'status' => ['type' => 'string', 'enum' => ['active', 'suspended', 'deletion_requested', 'deleted']],
        'emails' => ['type' => 'array', 'items' => $object([
            'id' => ['type' => 'string'], 'email' => ['type' => 'string'],
            'primary' => ['type' => 'boolean'], 'verifiedAt' => ['type' => 'integer'],
        ], ['id', 'email', 'primary', 'verifiedAt'])],
        'providers' => ['type' => 'array', 'items' => $object([
            'id' => ['type' => 'string'], 'issuer' => ['type' => 'string'],
        ], ['id', 'issuer'])],
    ], ['id', 'subject', 'displayName', 'status', 'emails', 'providers']);
    $read = ['readonly' => true, 'destructive' => false, 'idempotent' => true];
    $write = ['readonly' => false, 'destructive' => true, 'idempotent' => true];
    $manage = static fn (): bool => spacefast_content_users_may('spacefast_manage_content');
    $view = static fn (): bool => spacefast_content_users_may('list_users');
    return [
        'zero/wp-users-deletion-complete' => [
            'label' => 'Complete app account deletion', 'description' => 'Completes a user-requested erasure while preserving anonymized authorship.',
            'annotations' => $write, 'input_schema' => $object(['id' => $id], ['id']),
            'output_schema' => $object(['deleted' => ['type' => 'boolean']], ['deleted']),
            'permission_callback' => $manage, 'execute_callback' => 'spacefast_space_users_complete_deletion',
        ],
        'zero/wp-users-account-list' => [
            'label' => 'List app accounts', 'description' => 'Searches app accounts by name or any verified email in this Space.',
            'annotations' => $read,
            'input_schema' => $object([
                'page' => ['type' => 'integer', 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'search' => ['type' => 'string', 'maxLength' => 254],
            ]),
            'output_schema' => $object([
                'page' => ['type' => 'integer'], 'perPage' => ['type' => 'integer'],
                'users' => ['type' => 'array', 'items' => $account],
            ], ['page', 'perPage', 'users']),
            'permission_callback' => $view, 'execute_callback' => 'spacefast_space_users_list',
        ],
        'zero/wp-users-account-get' => [
            'label' => 'Inspect an app account',
            'description' => 'Returns verified emails, connections and account status for a user in this Space.',
            'annotations' => $read, 'input_schema' => $object(['id' => $id], ['id']),
            'output_schema' => $account, 'permission_callback' => $view,
            'execute_callback' => 'spacefast_space_users_account',
        ],
        'zero/wp-users-sessions-list' => [
            'label' => 'List app sessions', 'description' => 'Lists session metadata without credentials for a user in this Space.',
            'annotations' => $read, 'input_schema' => $object(['id' => $id], ['id']),
            'output_schema' => $object(['sessions' => ['type' => 'array', 'items' => $object([
                'id' => ['type' => 'string'], 'label' => ['type' => 'string'],
                'createdAt' => ['type' => 'integer'], 'expiresAt' => ['type' => 'integer'],
                'revokedAt' => ['type' => ['integer', 'null']],
            ], ['id', 'label', 'createdAt', 'expiresAt', 'revokedAt'])]], ['sessions']),
            'permission_callback' => $view, 'execute_callback' => 'spacefast_space_users_sessions',
        ],
        'zero/wp-users-sessions-revoke' => [
            'label' => 'Revoke app sessions', 'description' => 'Revokes one app session or every app session for this Space user.',
            'annotations' => $write,
            'input_schema' => $object(['id' => $id, 'sessionId' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$']], ['id']),
            'output_schema' => $object(['revoked' => ['type' => 'boolean']], ['revoked']),
            'permission_callback' => $manage, 'execute_callback' => 'spacefast_space_users_revoke',
        ],
        'zero/wp-users-suspend' => [
            'label' => 'Set app account status', 'description' => 'Suspends or reactivates app access in this Space without changing platform grants.',
            'annotations' => $write,
            'input_schema' => $object(['id' => $id, 'suspended' => ['type' => 'boolean']], ['id', 'suspended']),
            'output_schema' => $account, 'permission_callback' => $manage,
            'execute_callback' => 'spacefast_space_users_suspend',
        ],
    ];
}

/** Reconcile an explicitly linked native identity before the next Access login finds its author. */
function spacefast_space_users_bind_native_account(string $issuer, string $subject, int $userId): void
{
    $principalId = spacefast_content_principal_authority($issuer, $subject);
    $existing = spacefast_content_principal_find_user($principalId, $issuer, $subject);
    if ($existing > 0 && $existing !== $userId) {
        throw spacefast_space_users_failure('runtime_identity_conflict', 'This Spacefast identity already owns another account. Sign in to that account to manage it.', 409);
    }
    add_option('spacefast_principal_' . substr(hash('sha256', $principalId), 0, 40), [
        'version' => 1, 'principal_id' => $principalId, 'kind' => 'user',
        'issuer' => $issuer, 'subject' => $subject, 'user_id' => $userId,
    ], '', false);
    if (spacefast_content_principal_find_user($principalId, $issuer, $subject) !== $userId) {
        throw spacefast_space_users_failure('runtime_identity_conflict', 'This identity is already linked to another account.', 409);
    }
}

/** Native author creation and explicit linking serialize the same issuer/subject tuple. */
function spacefast_space_users_identity_lock(string $issuer, string $subject, callable $operation): mixed
{
    $database = spacefast_space_users_identity('Plugin')::$db;
    $key = 'sfi-native-' . substr(hash('sha256', DB_NAME . "\0" . $issuer . "\0" . $subject), 0, 48);
    $locked = $database->row('SELECT GET_LOCK(%s, 5) AS acquired', $key);
    if ((int) ($locked['acquired'] ?? 0) !== 1) throw spacefast_space_users_failure('identity_busy', 'This identity is being linked. Try again.', 503);
    try {
        return $operation();
    } finally {
        $database->row('SELECT RELEASE_LOCK(%s) AS released', $key);
    }
}

function spacefast_space_users_link_native(string $issuer, string $subject, string $name, array $session): void
{
    spacefast_space_users_identity_lock($issuer, $subject, static fn () => spacefast_space_users_identity('Plugin')::$db->credentialMutation($session, static function () use ($issuer, $subject, $name, $session): void {
        $linked = spacefast_space_users_identity('Plugin')::$accounts->provider($issuer, $subject, null, $name, (int) $session['wp_user_id'], $session['id']);
        // Access logins attribute content through the site's principal map; the Space's own
        // (Zero v2) tables keep the link in identity's provider table alone.
        if (!spacefast_space_users_scoped()) spacefast_space_users_bind_native_account($issuer, $subject, $linked);
    }));
}

/** Sign a verified Spacefast account in to its app account, creating it on first use. */
function spacefast_space_users_native_sign_in(string $issuer, string $subject, string $name): array
{
    $plugin = spacefast_space_users_identity('Plugin');
    return spacefast_space_users_identity_lock($issuer, $subject, static function () use ($plugin, $issuer, $subject, $name): array {
        if (spacefast_space_users_scoped()) {
            // zero-identity asks spacefast_space_users_native_account() for this identity's account.
            $GLOBALS['SPACEFAST_SPACE_USERS_NATIVE_IDENTITY'] = [$issuer, $subject];
            try {
                $userId = $plugin::$accounts->provider($issuer, $subject, null, $name);
            } finally {
                unset($GLOBALS['SPACEFAST_SPACE_USERS_NATIVE_IDENTITY']);
            }
            return $plugin::$api->providerLogin($userId);
        }
        $userId = spacefast_content_principal_ensure_user([
            'kind' => 'user', 'issuer' => $issuer, 'subject' => $subject,
            'principal_id' => spacefast_content_principal_authority($issuer, $subject),
            'profile' => ['display_name' => $name],
        ]);
        if ($userId < 1) throw spacefast_space_users_failure('space_users_account_unavailable', 'The app account is unavailable.', 503);
        $resolved = $plugin::$accounts->provider($issuer, $subject, null, $name);
        if ($resolved !== $userId) throw spacefast_space_users_failure('space_users_identity_conflict', 'This identity belongs to a different account.', 409);
        return $plugin::$api->providerLogin($userId);
    });
}
