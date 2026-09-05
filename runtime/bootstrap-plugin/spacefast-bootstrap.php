<?php
/**
 * Plugin Name: Spacefast Bootstrap
 * Description: Installs and confirms the Spacefast runtime engine on a freshly provisioned wp.cloud box. One signed route, one break-glass WP-CLI command.
 * Version: 1.0.0
 * Author: Spacefast
 *
 * Lifecycle: the control plane installs this plugin through the wp.cloud task
 * lane right after create-site, then makes ONE signed HTTP confirm call to
 * /__spacefast/bootstrap carrying the engine spec (zip_url, md5, revision,
 * native_sha256) and the engine's steady-state config as JWT claims. This
 * plugin verifies the platform signature, writes the config file, runs the
 * shared installer sequence (bundled installer.php), and answers with the
 * install receipt. Once the engine's loader gate owns /__spacefast/*, this
 * route is unreachable — exactly the intended lifetime.
 *
 * Trust: the platform JWKS arrives as create-site persistent data. wp.cloud
 * exposes persistent data to PHP ONLY through its Atomic_Persistent_Data
 * class (the provider prepend decrypts it lazily) — never as a define() or an
 * environment variable; the SPACEFAST_RUNTIME_JWKS_B64 constant exists only
 * after install, defined by the engine's own bootstrap-config shim. Rotation
 * is an update of that same persist_data key. The route never accepts an
 * unverified spec — installing an attacker-supplied zip as the serving engine
 * is the one mistake this file must never make.
 */

if (!defined('ABSPATH') && !defined('WP_CLI')) {
    // Loaded outside WordPress: nothing to do.
    return;
}

const SPACEFAST_BOOTSTRAP_PATH = '/__spacefast/bootstrap';
const SPACEFAST_BOOTSTRAP_INSTALL_TIMEOUT_SECONDS = 300;

/** The FPM-writable docroot (/srv/htdocs). ABSPATH is the shared read-only core. */
function spacefast_bootstrap_docroot(): string
{
    return dirname(WP_CONTENT_DIR);
}

function spacefast_bootstrap_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function spacefast_bootstrap_b64url_decode(string $value): string|false
{
    $padded = strtr($value, '-_', '+/');
    $remainder = strlen($padded) % 4;
    if ($remainder !== 0) {
        $padded .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode($padded, true);
}

function spacefast_bootstrap_jwks_b64(): string
{
    // Constant first: the engine's bootstrap-config shim defines it once the
    // engine is installed, and test lanes define it directly.
    if (defined('SPACEFAST_RUNTIME_JWKS_B64')) {
        $constant = (string) constant('SPACEFAST_RUNTIME_JWKS_B64');
        if ($constant !== '') {
            return $constant;
        }
    }
    // Fresh box: the provider exposes persistent data only through this class.
    if (class_exists('Atomic_Persistent_Data')) {
        try {
            $persistent = new Atomic_Persistent_Data();
            $value = $persistent->SPACEFAST_RUNTIME_JWKS_B64;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        } catch (Throwable $error) {
            // Persistent data can contain credentials; log the failure type
            // only, never an exception message that may embed a value.
            error_log('spacefast bootstrap trust anchor read failed type=' . get_debug_type($error));
        }
    }
    return '';
}

/**
 * Verify a compact EdDSA JWS against the trusted platform JWKS and return its
 * claims, or null. Only Ed25519 (OKP) keys are supported — the platform mints
 * nothing else.
 */
function spacefast_bootstrap_verify_jwt(string $token): ?array
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return null;
    }
    // The trust anchor comes from wp.cloud persistent data delivered at
    // create-site and rotated by updating the same key.
    $jwksB64 = spacefast_bootstrap_jwks_b64();
    if ($jwksB64 === '') {
        return null;
    }
    $jwksJson = base64_decode($jwksB64, true);
    $jwks = is_string($jwksJson) ? json_decode($jwksJson, true) : null;
    $keys = is_array($jwks) && isset($jwks['keys']) && is_array($jwks['keys']) ? $jwks['keys'] : [];

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $header = json_decode((string) spacefast_bootstrap_b64url_decode($headerB64), true);
    $payload = json_decode((string) spacefast_bootstrap_b64url_decode($payloadB64), true);
    $signature = spacefast_bootstrap_b64url_decode($signatureB64);
    if (!is_array($header) || !is_array($payload) || !is_string($signature)) {
        return null;
    }
    if (($header['alg'] ?? '') !== 'EdDSA') {
        return null;
    }
    $signed = $headerB64 . '.' . $payloadB64;
    $verified = false;
    foreach ($keys as $key) {
        if (!is_array($key) || ($key['kty'] ?? '') !== 'OKP' || ($key['crv'] ?? '') !== 'Ed25519') {
            continue;
        }
        if (isset($header['kid'], $key['kid']) && $header['kid'] !== $key['kid']) {
            continue;
        }
        $publicKey = spacefast_bootstrap_b64url_decode((string) ($key['x'] ?? ''));
        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            continue;
        }
        if (sodium_crypto_sign_verify_detached($signature, $signed, $publicKey)) {
            $verified = true;
            break;
        }
    }
    if (!$verified) {
        return null;
    }
    $now = time();
    $exp = $payload['exp'] ?? null;
    $nbf = $payload['nbf'] ?? null;
    if (!is_int($exp) && !is_float($exp)) {
        return null;
    }
    if ($now >= (int) $exp) {
        return null;
    }
    if ((is_int($nbf) || is_float($nbf)) && $now + 60 < (int) $nbf) {
        return null;
    }
    if (($payload['aud'] ?? '') !== 'stattic-runtime-management') {
        return null;
    }
    if (($payload['action'] ?? '') !== 'update_engine') {
        return null;
    }
    return $payload;
}

/** Write the engine's config file where the engine reads it: `<docroot>/.stattic/storage/config.php`. */
function spacefast_bootstrap_write_config(array $config, bool $onlyIfMissing = false): bool
{
    $installRoot = spacefast_bootstrap_docroot() . '/.stattic/storage';
    if (!is_dir($installRoot) && !mkdir($installRoot, 0755, true) && !is_dir($installRoot)) {
        return false;
    }
    $entries = [];
    foreach ($config as $name => $value) {
        if (!is_string($name) || !is_string($value) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            return false;
        }
        $entries[] = '    ' . var_export($name, true) . ' => ' . var_export($value, true) . ',';
    }
    $contents = "<?php\n// Written by spacefast-bootstrap; the engine reads these through\n// _stattic_config_value(). Regenerated by every signed confirm call.\nreturn [\n" . implode("\n", $entries) . "\n];\n";
    $tmp = $installRoot . '/config.php.tmp.' . getmypid();
    if (file_put_contents($tmp, $contents) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if ($onlyIfMissing) {
        $created = link($tmp, $installRoot . '/config.php');
        unlink($tmp);
        return $created;
    }
    return rename($tmp, $installRoot . '/config.php');
}

class SpacefastBootstrapConfigError extends RuntimeException {}

/** Restore only a missing config after the control plane verifies provider ownership. */
function spacefast_bootstrap_restore_missing_config(array $config): string
{
    if (!class_exists('Atomic_Persistent_Data')) {
        throw new SpacefastBootstrapConfigError('bootstrap_config_provider_context_missing');
    }
    $expectedId = $config['SPACEFAST_RUNTIME_INSTANCE_ID'] ?? '';
    if (!is_string($expectedId) || $expectedId === '') {
        throw new SpacefastBootstrapConfigError('bootstrap_config_runtime_id_missing');
    }
    $path = spacefast_bootstrap_docroot() . '/.stattic/storage/config.php';
    $existing = is_file($path) ? require $path : [];
    $persistent = class_exists('Atomic_Persistent_Data') ? new Atomic_Persistent_Data() : null;
    $atomicJson = getenv('SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON');
    $atomic = is_string($atomicJson) ? json_decode($atomicJson, true) : null;
    $identities = [
        is_array($existing) ? ($existing['SPACEFAST_RUNTIME_INSTANCE_ID'] ?? '') : '',
        defined('SPACEFAST_RUNTIME_INSTANCE_ID') ? constant('SPACEFAST_RUNTIME_INSTANCE_ID') : '',
        $persistent !== null ? $persistent->SPACEFAST_RUNTIME_INSTANCE_ID : '',
        getenv('SPACEFAST_RUNTIME_INSTANCE_ID'),
        is_array($atomic) ? ($atomic['SPACEFAST_RUNTIME_INSTANCE_ID'] ?? '') : '',
    ];
    foreach ($identities as $identity) {
        if (is_string($identity) && trim($identity) !== '' && !hash_equals($expectedId, trim($identity))) {
            throw new SpacefastBootstrapConfigError('bootstrap_config_runtime_id_conflict');
        }
    }
    if (file_exists($path)) {
        if (!is_array($existing) || ($existing['SPACEFAST_RUNTIME_INSTANCE_ID'] ?? '') !== $expectedId) {
            throw new SpacefastBootstrapConfigError('bootstrap_config_existing_identity_missing');
        }
        return 'unchanged';
    }
    // link() publishes the complete file only if no writer created the target
    // meanwhile. A concurrent confirm can never have its config overwritten.
    if (!spacefast_bootstrap_write_config($config, true)) {
        throw new SpacefastBootstrapConfigError('bootstrap_config_restore_failed');
    }
    return 'restored';
}

/**
 * Run the bundled shared installer (the same sequence every engine install
 * uses): download zip, md5 verify, native self-test, atomic active-release
 * publish. Returns the installer's JSON receipt or an error tuple.
 */
function spacefast_bootstrap_run_installer(array $spec): array
{
    $docroot = spacefast_bootstrap_docroot();
    $installerDir = $docroot . '/__spacefast';
    if (!is_dir($installerDir) && !mkdir($installerDir, 0755, true) && !is_dir($installerDir)) {
        return ['error' => 'installer_dir_unwritable'];
    }
    $bundled = __DIR__ . '/installer.php';
    $resident = $installerDir . '/engine-update.php';
    $tmp = $resident . '.tmp.' . getmypid();
    if (!copy($bundled, $tmp) || !chmod($tmp, 0644) || !rename($tmp, $resident)) {
        return ['error' => 'installer_stage_failed'];
    }

    $phpCli = PHP_BINDIR . '/php';
    if (!is_file($phpCli)) {
        $phpCli = 'php';
    }
    putenv('SPACEFAST_RUNTIME_ENGINE_MD5=' . strtolower($spec['md5']));
    putenv('SPACEFAST_RUNTIME_ENGINE_REVISION=' . $spec['revision']);
    if (($spec['native_sha256'] ?? '') !== '') {
        putenv('SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256=' . strtolower($spec['native_sha256']));
    }
    if ((string) getenv('PATH') === '') {
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
    }

    $command = [$phpCli, '-d', 'auto_prepend_file=', '-d', 'opcache.enable_cli=0', $resident, $spec['zip_url']];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $docroot);
    if (!is_resource($process)) {
        return ['error' => 'installer_spawn_failed'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = time() + SPACEFAST_BOOTSTRAP_INSTALL_TIMEOUT_SECONDS;
    for (;;) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (time() > $deadline) {
            proc_terminate($process, 9);
            proc_close($process);
            return ['error' => 'installer_timeout'];
        }
        usleep(200000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $jsonStart = strpos($stdout, '{');
    $receipt = $jsonStart === false ? null : json_decode(substr($stdout, $jsonStart), true);
    if ($exitCode !== 0 || !is_array($receipt)) {
        return [
            'error' => 'installer_failed',
            'exit_code' => $exitCode,
            'detail' => substr(trim($stderr !== '' ? $stderr : $stdout), 0, 512),
        ];
    }
    return ['receipt' => $receipt];
}

function spacefast_bootstrap_handle_confirm(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        spacefast_bootstrap_json(405, ['code' => 'method_not_allowed', 'error' => 'method_not_allowed']);
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authorization, 'Bearer ')) {
        spacefast_bootstrap_json(401, ['code' => 'bootstrap_token_missing', 'error' => 'bootstrap_token_missing']);
    }
    $claims = spacefast_bootstrap_verify_jwt(substr($authorization, 7));
    if ($claims === null) {
        // Includes "trust anchor not visible yet" — persistent data lands
        // asynchronously right after create, and the confirm retries.
        spacefast_bootstrap_json(403, ['code' => 'bootstrap_token_rejected', 'error' => 'bootstrap_token_rejected']);
    }

    $spec = [
        'revision' => $claims['engine_revision'] ?? '',
        'zip_url' => $claims['engine_zip_url'] ?? '',
        'md5' => $claims['engine_md5'] ?? '',
        'native_sha256' => $claims['engine_native_sha256'] ?? '',
    ];
    if (
        !is_string($spec['revision']) || $spec['revision'] === '' ||
        !is_string($spec['zip_url']) || !str_starts_with($spec['zip_url'], 'http') ||
        !is_string($spec['md5']) || !preg_match('/^[a-fA-F0-9]{32}$/', $spec['md5'])
    ) {
        spacefast_bootstrap_json(422, ['code' => 'bootstrap_spec_invalid', 'error' => 'bootstrap_spec_invalid']);
    }

    $config = $claims['engine_config'] ?? null;
    if (is_array($config) && !spacefast_bootstrap_write_config($config)) {
        spacefast_bootstrap_json(500, ['code' => 'bootstrap_config_write_failed', 'error' => 'bootstrap_config_write_failed']);
    }

    // Never spawn here: wp.cloud FPM cannot exec PHP CLI (proven live), and
    // once an engine serves, this route is unreachable anyway. The engine
    // binary installs through `wp spacefast install` on the task lane; the
    // install receipt comes from the engine's own receipt-only update route.
    spacefast_bootstrap_json(200, ['status' => 'config_written', 'revision' => $spec['revision']]);
}

// Intercept the bootstrap route before WordPress routing. Once the engine's
// loader gate owns /__spacefast/*, requests never reach WordPress here.
if (defined('ABSPATH')) {
    add_action('plugins_loaded', static function (): void {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($path === SPACEFAST_BOOTSTRAP_PATH) {
            spacefast_bootstrap_handle_confirm();
        }
    }, 0);
}

// Break-glass: the tasks API denies `wp eval`, so a custom command is the
// provider-sanctioned escape hatch. Keep it minimal.
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('spacefast', new class {
        /**
         * Install the engine from its immutable release zip. Runs in real CLI
         * (the wp.cloud task lane), the one process namespace that can exec
         * the installer — FPM cannot.
         *
         * ## OPTIONS
         *
         * <zip-url>
         * : Immutable engine release zip URL.
         *
         * <md5>
         * : Expected md5 of the zip.
         *
         * <revision>
         * : Engine revision being installed.
         *
         * [<native-sha256>]
         * : Expected sha256 of the native binary.
         */
        public function install(array $args): void
        {
            $spec = [
                'zip_url' => $args[0] ?? '',
                'md5' => strtolower($args[1] ?? ''),
                'revision' => $args[2] ?? '',
                'native_sha256' => strtolower($args[3] ?? ''),
            ];
            if (
                !str_starts_with($spec['zip_url'], 'http') ||
                !preg_match('/^[a-f0-9]{32}$/', $spec['md5']) ||
                $spec['revision'] === ''
            ) {
                WP_CLI::error('install_spec_invalid');
            }
            $outcome = spacefast_bootstrap_run_installer($spec);
            if (!isset($outcome['receipt'])) {
                WP_CLI::error(json_encode($outcome) ?: 'installer_failed');
            }
            WP_CLI::log(json_encode($outcome['receipt']) ?: '{}');
            WP_CLI::success('installed');
        }

        /** Report bootstrap state: trust anchor, engine layout, config file. */
        public function status(): void
        {
            $docroot = spacefast_bootstrap_docroot();
            WP_CLI::log(json_encode([
                'trusted' => spacefast_bootstrap_jwks_b64() !== '',
                'config_file' => is_file($docroot . '/.stattic/storage/config.php'),
                'active_release' => is_file($docroot . '/.stattic/active-release'),
                'resident_installer' => is_file($docroot . '/__spacefast/engine-update.php'),
            ]));
        }
    });
}
