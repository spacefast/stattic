<?php
declare(strict_types=1);

/** Only the authenticated control plane supplies generation authority. */
function _stattic_zero_generation(array $authority): string
{
    $generation = $authority['zero_generation'] ?? 'none';
    return in_array($generation, ['none', 'legacy_0_8', 'v2'], true) ? $generation : 'none';
}

/** Cordon every selected version, including immutable hosts and mounted paths. */
function _stattic_zero_generation_serve(string $versionRoot, array $serving): void
{
    $generation = _stattic_zero_generation($serving);
    $legacy = is_file($versionRoot . '/zero/config.json')
        || is_file($versionRoot . '/zero/routes.php')
        || is_file($versionRoot . '/zero/capsule.json')
        || is_dir($versionRoot . '/zero/actions')
        || is_file($versionRoot . '/zero/endpoints-index.json')
        || is_file($versionRoot . '/zero/runs-index.json');
    if ($generation === 'v2' && $legacy) {
        _stattic_problem_response(410, 'zero_legacy_retired', 'This Zero 0.8 version is retired. Open the Space live hostname.', [
            'details' => ['live_hostname' => $serving['live_hostname'] ?? null],
        ]);
    }
    if ($generation !== 'v2' && is_file($versionRoot . '/.zero-compiled/manifest.json')) {
        _stattic_problem_response(409, 'zero_generation_locked', 'This Space has not activated Zero v2.');
    }
}

function _stattic_zero_v2_select(string $privateRoot, string $spaceId, string $versionId, array $authority, bool $initializing = false): bool
{
    $root = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId . '/.zero-compiled';
    if (!is_file($root . '/manifest.json')) return false;
    if (!$initializing && _stattic_zero_generation($authority) !== 'v2') throw new RuntimeException('This Space has not activated Zero v2.');
    if (!$initializing && !is_file($root . '/ready.json')) throw new RuntimeException('Zero v2 initialization is incomplete.');
    if (isset($GLOBALS['SPACEFAST_ZERO_V2_ROOT']) && $GLOBALS['SPACEFAST_ZERO_V2_ROOT'] !== $root) {
        throw new RuntimeException('Only one Zero version may load in a request.');
    }
    $GLOBALS['SPACEFAST_ZERO_V2_ROOT'] = $root;
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $GLOBALS['SPACEFAST_ZERO_V2_INITIALIZING'] = $initializing;
    // Space Users is part of the binding: while the Space still has accounts on
    // the site tables it stays off, so no second store of them starts here.
    // A failed lookup pauses Space Users too; it never touches an app's own users.
    $users = ($authority['users']['enabled'] ?? false) === true;
    if ($users) {
        require_once __DIR__ . '/space-users-site.php';
        $users = _stattic_space_users_site_accounts($privateRoot, $spaceId) === false;
    }
    $GLOBALS['SPACEFAST_ZERO_V2_USERS'] = $users;
    return true;
}

/** The Space's current route configuration, for lanes that serve no request. */
function _stattic_zero_v2_space_overlay(string $privateRoot, string $spaceId): array
{
    require_once __DIR__ . '/storage.php';
    require_once __DIR__ . '/artifacts.php';
    $pointer = _stattic_runtime_read_json($privateRoot . '/spaces/' . $spaceId . '/space.json');
    $overlay = is_array($pointer) ? _stattic_v4_overlay($privateRoot, $spaceId, $pointer) : null;
    return is_array($overlay) ? $overlay : [];
}

function _stattic_zero_v2_users_enabled(string $privateRoot, string $spaceId): bool
{
    return (_stattic_zero_v2_space_overlay($privateRoot, $spaceId)['users']['enabled'] ?? false) === true;
}

function _stattic_zero_v2_space_generation(string $privateRoot, string $spaceId): string
{
    return _stattic_zero_generation(_stattic_zero_v2_space_overlay($privateRoot, $spaceId));
}

function _stattic_zero_v2_mount(): void
{
    $root = $GLOBALS['SPACEFAST_ZERO_V2_ROOT'] ?? null;
    if (!is_string($root)) return;
    $manifest = json_decode(file_get_contents($root . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $name = $manifest['app'] ?? '';
    if (!is_string($name) || preg_match('/\A[a-z0-9-]+\z/D', $name) !== 1) throw new RuntimeException('Invalid compiled Zero manifest.');
    $variables = is_file($root . '/variables.json') ? _stattic_runtime_read_json_strict($root . '/variables.json') : [];
    if (!is_array($variables)) throw new RuntimeException('Invalid managed Zero variables.');
    // mail.send without `from` uses noreply@ZERO_MAIL_SENDER_DOMAIN. Name the route's sending domain
    // (the one the broker requires), so the default holds on every host the Space answers on.
    $variables += _stattic_zero_v2_mail_variables();
    // Restrict lookup before feature registration, schema loading, or app boot.
    // An older version without a snapshot gets an empty scope, never host values.
    require_once $root . '/plugins/zero-runtime/zero/Env.php';
    \Zero\Env::isolate($variables);
    // Load the version's autoloader only. Runtime::mount performs boot after the
    // provider's platform plugins load, with automatic migrations disabled.
    require_once $root . '/plugins/zero-runtime/zero/bootstrap.php';
    $schema = \Zero\Schema::load($root . '/plugins/zero-app-' . $name . '/zero/app/schema.php');
    require_once __DIR__ . '/zero-db-scope.php';
    foreach ($schema->collections() as $definition) {
        if (isset($definition['existingTable']) && $definition['existingTable'] !== _stattic_zero_db_expected_physical_name($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'], $definition['key'])) {
            throw new RuntimeException('A migrated Zero table must belong to this Space and collection.');
        }
    }
    // Space Users on a v2 Space is this version's zero-identity on the Space's
    // tables: the same accounts whether or not the app declares a user collection.
    // Space Users binds onto zero-identity only while it isn't paused (paused:
    // the Space still has accounts on the site tables). An app's own user
    // collection is the app's sign-in, not Space Users: it mounts identity
    // either way, as before, but without the Space Users hooks routing onto it.
    $users = ($GLOBALS['SPACEFAST_ZERO_V2_USERS'] ?? false) === true;
    if ($users || ($schema->auth()['collection'] ?? null) !== null) {
        if ($users) spacefast_space_users_install_hooks();
        require_once $root . '/plugins/zero-identity/zero-identity.php';
        // Managed apps mount without WordPress activation hooks. Keep identity's
        // normal maintenance schedule in this Space's scoped cron option.
        if (!wp_next_scheduled('zero_identity_maintenance')) {
            $scheduled = wp_schedule_event(time() + 60, 'hourly', 'zero_identity_maintenance', [], true);
            if ($scheduled !== true) throw new RuntimeException('Cannot schedule scoped Zero identity maintenance.');
        }
    }
    \Zero\Wp\Runtime::mount($root . '/plugins/zero-runtime/zero-runtime.php', $root . '/plugins/zero-app-' . $name, $variables);
    _stattic_zero_v2_configure_spacefast_provider($root);
    require_once __DIR__ . '/../runtime/zero-v2-realtime.php';
    _stattic_zero_v2_register_realtime($root);
    // A managed app's mail.send refuses until the host supplies its transport.
    add_filter('zero_mail_transport', static fn (mixed $accepted, array $message): bool => _stattic_zero_v2_mail_send($message), 10, 2);
}

/** The Space overlay for the selected Space, or null when routing is unavailable. */
function _stattic_zero_v2_overlay(): ?array
{
    require_once __DIR__ . '/artifacts.php';
    $privateRoot = (string) ($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '');
    $spaceId = (string) ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? '');
    if ($privateRoot === '' || !_stattic_id_valid($spaceId)) return null;
    $overlay = _stattic_v4_overlay($privateRoot, $spaceId, _stattic_runtime_read_json_strict($privateRoot . '/spaces/' . $spaceId . '/space.json'));
    return is_array($overlay) ? $overlay : null;
}

/** Host-owned Zero variables for mail: the route's sending domain, when one is set. */
function _stattic_zero_v2_mail_variables(): array
{
    $domain = _stattic_zero_v2_overlay()['email']['senderDomain'] ?? null;
    return is_string($domain) && $domain !== '' ? ['ZERO_MAIL_SENDER_DOMAIN' => strtolower(trim($domain))] : [];
}

/**
 * Hand one queued mail.send message to the Space's mail path: the same service
 * broker frame sf_email() sends. The broker checks the sender's domain, and
 * space-mail.php reserves the daily recipient allowance before wp_mail().
 * The route's email and billing settings are read at send time, so a demoted
 * domain or a lowered allowance applies to messages already queued.
 */
function _stattic_zero_v2_mail_send(array $message): bool
{
    require_once __DIR__ . '/native-process.php';
    require_once __DIR__ . '/functions-wire.generated.php';
    $overlay = _stattic_zero_v2_overlay();
    if ($overlay === null) throw new RuntimeException('Space mail routing is unavailable.');
    $privateRoot = (string) $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'];
    $spaceId = (string) $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'];
    // The broker environment names the usage and WordPress roots from this binding.
    if (_stattic_access_private_root() === '') _stattic_access_private_root($privateRoot);
    $env = _stattic_service_broker_env(['spaceId' => $spaceId, 'invocationId' => ''], [
        'billing' => is_array($overlay['billing'] ?? null) ? $overlay['billing'] : [],
        'email' => is_array($overlay['email'] ?? null) ? $overlay['email'] : null,
    ]) + [SPACEFAST_FUNCTIONS_SERVICE_BROKER_GRANT_ENV => 'email.send'];
    $frame = json_encode(['service' => 'email', 'operation' => 'send', 'payload' => (object) $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $result = _stattic_runtime_run_subprocess([_stattic_runtime_native_binary(), 'service-broker'], $env, $frame, null, 15000, 1048576, 65536);
    $answer = json_decode(trim((string) $result['stdout']), true);
    if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0 || !is_array($answer)) {
        throw new RuntimeException('The Space mail broker did not answer.');
    }
    // A refusal names its code in the job's lastError; false is a transport or allowance decline.
    if (($answer['ok'] ?? false) !== true) {
        throw new RuntimeException('Space mail refused: ' . (is_string($answer['code'] ?? null) ? $answer['code'] : 'service_refused'));
    }
    return ($answer['result'] ?? null) === true;
}

/** Connector integration supplies Providers\Spacefast::configure here after mount, before handlers. */
function _stattic_zero_v2_configure_spacefast_provider(string $compiledRoot): void
{
    // Deliberate no-op until the connectors runtime and its host provider land.
}

/** Authenticated finalize values are private immutable inputs, including empty overrides. */
function _stattic_zero_v2_write_variables(string $privateRoot, string $spaceId, string $versionId, mixed $variables): void
{
    $root = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId . '/.zero-compiled';
    if (!is_file($root . '/manifest.json')) return;
    if (!is_array($variables)) throw new RuntimeException('Invalid managed Zero variables.');
    foreach ($variables as $name => $value) {
        if (!is_string($name) || !is_string($value)) throw new RuntimeException('Invalid managed Zero variables.');
    }
    _stattic_runtime_write_json_atomic($root . '/variables.json', $variables);
}

function _stattic_zero_v2_initialize(string $privateRoot, string $spaceId, string $versionId, array $authority): void
{
    $authority['users'] = ['enabled' => _stattic_zero_v2_users_enabled($privateRoot, $spaceId)];
    if (!_stattic_zero_v2_select($privateRoot, $spaceId, $versionId, $authority, true)) return;
    $root = $GLOBALS['SPACEFAST_ZERO_V2_ROOT'];
    if (is_file($root . '/ready.json')) return;
    if (function_exists('did_action') && did_action('plugins_loaded') > 0) throw new RuntimeException('WordPress booted before the candidate version was selected.');
    $bootstrap = dirname($privateRoot, 2) . '/wp-load.php';
    if (!is_file($bootstrap)) throw new RuntimeException('WordPress is required for Zero v2.');
    (static function (string $file): void { require_once $file; })($bootstrap);
    if (!class_exists(\Zero\Wp\Runtime::class, false) || !\Zero\Wp\Runtime::managed()) throw new RuntimeException('The managed Zero runtime did not load.');
    \Zero\Wp\Runtime::initialize();
    if (is_dir($root . '/seed')) {
        // In-process, inside the WordPress this finalize already booted. Finalize runs in a
        // php-fpm worker on the box, whose namespace has no `wp` or `php` to spawn.
        try {
            $receipt = \Zero\Sync\Seed::run(\Zero\Wp\Runtime::plugin(), $root . '/seed');
        } catch (Throwable $failure) {
            $error = \Zero\App::problem($failure);
            _stattic_problem_response(422, 'zero_v2_seed_failed', 'The version content seed failed. Correct the source record and publish again.', ['pointer' => $error['pointer'] ?? '/seed', 'details' => $error]);
        }
        _stattic_runtime_write_json_atomic($root . '/seed-receipt.json', $receipt);
        try { _stattic_zero_v2_seed_receipt(dirname($root)); }
        catch (Throwable) { _stattic_problem_response(422, 'zero_v2_seed_failed', 'The seed command returned invalid collection counts.', ['pointer' => '/seed']); }
    }
    _stattic_runtime_write_json_atomic($root . '/ready.json', ['version_id' => $versionId]);
    _stattic_runtime_write_json_atomic($privateRoot . '/spaces/' . $spaceId . '/zero-v2-current.json', ['version_id' => $versionId]);
}

/** The generated app bytes always come from the selected immutable version. */
function _stattic_zero_v2_client(string $versionRoot, string $path, string $method): never
{
    if (!in_array($method, ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
    $root = $versionRoot . '/.zero-compiled';
    $types = $path === STATTIC_ZERO_V2_CLIENT_TYPES_PATH;
    $file = $root . '/client/' . ($types ? 'client.d.ts' : 'client.js');
    if (!is_file($root . '/ready.json') || !is_file($file)) {
        _stattic_problem_response(404, 'not_found', 'This version has no generated Zero client.');
    }
    $body = file_get_contents($file);
    if (!is_string($body)) throw new RuntimeException('Cannot read the generated Zero client.');
    if (!$types) {
        // Live updates: the Cast transport registers with every Zero client on the page.
        $body .= "\n" . 'if (typeof document !== "undefined") import(' . json_encode(STATTIC_ZERO_V2_REALTIME_PATH, JSON_UNESCAPED_SLASHES) . ').catch(() => {});' . "\n";
    }
    _stattic_response_send(200, $body,
        $types ? 'application/typescript; charset=utf-8' : 'text/javascript; charset=utf-8',
        ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'ETag' => '"' . hash('sha256', $body) . '"']);
}

/** Serialize a Space's baseline selection, compilation and migration as one operation. */
function _stattic_zero_v2_finalize_lock(string $privateRoot, string $spaceId, array $session): mixed
{
    $files = array_merge($session['manifest'] ?? [], $session['retained_files'] ?? []);
    $reusable = $session['reusable_version_id'] ?? null;
    $inheritsZero = ($session['retention'] ?? null) === 'all' && is_string($reusable)
        && is_file($privateRoot . '/spaces/' . $spaceId . '/versions/' . $reusable . '/.zero-compiled/manifest.json');
    if (!$inheritsZero && !array_any($files, static fn ($file): bool => is_array($file) && (($file['path'] ?? '') === 'zero.config.ts' || str_starts_with((string) ($file['path'] ?? ''), '.zero-source/')))) return null;
    return _stattic_zero_v2_lock($privateRoot, $spaceId);
}

function _stattic_zero_v2_lock(string $privateRoot, string $spaceId): mixed
{
    $file = $privateRoot . '/spaces/' . $spaceId . '/zero-v2-finalize.lock';
    $handle = fopen($file, 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) fclose($handle);
        _stattic_problem_response(409, 'zero_v2_finalize_busy', 'Another Zero publication is initializing this Space. Retry after it completes.');
    }
    return $handle;
}

/** WP-CLI bootstrap for the scheduled worker, which runs from cron in the CLI namespace. */
function _stattic_zero_v2_wp_bootstrap(string $privateRoot, string $spaceId, string $versionId, bool $initializing = false): string
{
    $authority = ['zero_generation' => 'v2', 'users' => ['enabled' => _stattic_zero_v2_users_enabled($privateRoot, $spaceId)]];
    return 'require_once ' . var_export(__DIR__ . '/context.php', true) . ';'
        . 'require_once ' . var_export(__DIR__ . '/storage.php', true) . ';'
        . 'require_once ' . var_export(__FILE__, true) . ';'
        . '_stattic_zero_v2_select(' . var_export($privateRoot, true) . ',' . var_export($spaceId, true) . ',' . var_export($versionId, true) . ',' . var_export($authority, true) . ',' . ($initializing ? 'true' : 'false') . ');';
}

function _stattic_zero_v2_seed_receipt(string $versionRoot): ?array
{
    $path = $versionRoot . '/.zero-compiled/seed-receipt.json';
    if (!is_dir($versionRoot . '/.zero-compiled/seed')) return null;
    if (!is_file($path)) throw new RuntimeException('Missing Zero seed receipt.');
    $receipt = _stattic_runtime_read_json_strict($path);
    if (!is_array($receipt)) throw new RuntimeException('Invalid Zero seed receipt.');
    foreach ($receipt as $counts) {
        foreach (['created', 'unchanged', 'skipped_existing', 'updated', 'trashed_skipped'] as $name) {
            if (!is_array($counts) || !is_int($counts[$name] ?? null) || $counts[$name] < 0) throw new RuntimeException('Invalid Zero seed counts.');
        }
    }
    return ['zero_seed' => (object) $receipt];
}
