<?php
/**
 * Plugin Name: Spacefast Bootstrap
 * Description: Shared helpers for the Spacefast runtime installer: config restore and one break-glass WP-CLI install command.
 * Version: 1.0.0
 * Author: Spacefast
 *
 * The control plane stages this file, restore-config.php and installer.php
 * over SSH at a content-addressed `.stattic/installers/<digest>/` path
 * (boxes/engine-publication.ts) and runs them with PHP CLI. Config travels on
 * SSH stdin into restore-config.php, never through HTTP or provider tasks.
 * Installed as a WordPress plugin it serves no route; the engine removes it
 * once the resident runtime serves (engine/admin/components.php).
 */

if (!defined('ABSPATH') && !defined('WP_CLI')) {
    // Loaded outside WordPress: nothing to do.
    return;
}

if (!defined('SPACEFAST_BOOTSTRAP_INSTALL_TIMEOUT_SECONDS')) {
    define('SPACEFAST_BOOTSTRAP_INSTALL_TIMEOUT_SECONDS', 300);
}
if (!defined('SPACEFAST_BOOTSTRAP_INSTALL_GRACE_SECONDS')) {
    define('SPACEFAST_BOOTSTRAP_INSTALL_GRACE_SECONDS', 5);
}

function spacefast_bootstrap_temp_file(string $parent, string $prefix): string|false
{
    if (!is_dir($parent) || is_link($parent)) {
        return false;
    }
    $temporary = tempnam($parent, $prefix);
    return is_string($temporary) && is_file($temporary) && !is_link($temporary) ? $temporary : false;
}

/** The FPM-writable docroot (/srv/htdocs). ABSPATH is the shared read-only core. */
function spacefast_bootstrap_docroot(): string
{
    return dirname(WP_CONTENT_DIR);
}

function spacefast_bootstrap_is_shared_storage_key(string $key): bool
{
    return str_starts_with($key, 'SPACEFAST_STORAGE_BUCKET') || str_starts_with($key, 'SPACEFAST_BUCKET_');
}

function spacefast_bootstrap_site_config(array $config): array
{
    return array_filter(
        $config,
        static fn (mixed $key): bool => !is_string($key) || !spacefast_bootstrap_is_shared_storage_key($key),
        ARRAY_FILTER_USE_KEY,
    );
}

/** Keep cold-storage readers and cleanup usable until their data is retired. */
function spacefast_bootstrap_with_storage_retirement_lock(callable $callback): mixed
{
    $root = spacefast_bootstrap_docroot() . '/.stattic/storage';
    $path = $root . '/config.php';
    $existing = is_file($path) ? require $path : [];
    $retiring = is_array($existing) && spacefast_bootstrap_site_config($existing) !== $existing;
    if (class_exists('Atomic_Persistent_Data')) {
        foreach (new Atomic_Persistent_Data() as $key => $value) {
            if (is_string($key) && spacefast_bootstrap_is_shared_storage_key($key)) {
                $retiring = true;
                break;
            }
        }
    }
    // Config restores and ordinary updates do not retire any bucket authority.
    // Old cold-storage records must not prevent repairing an already-clean box.
    if (!$retiring) {
        return $callback();
    }
    $jobs = $root . '/runtime/jobs';
    if (!is_dir($jobs) && !mkdir($jobs, 0755, true) && !is_dir($jobs)) {
        throw new SpacefastBootstrapConfigError('bootstrap_storage_retirement_lock_failed');
    }
    // This is the engine's bulk-lane lock, shared by demotion and bucket reclaim.
    $handle = fopen($jobs . '/lane-bulk.lock', 'c');
    if ($handle === false) {
        throw new SpacefastBootstrapConfigError('bootstrap_storage_retirement_lock_failed');
    }
    try {
        $deadline = microtime(true) + 20.0;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                throw new SpacefastBootstrapConfigError('bootstrap_storage_retirement_busy');
            }
            usleep(50000);
        }
        foreach (['/spaces/*/blobs/*/*.demote', '/runtime/bucket-reclaim/*.json'] as $pattern) {
            $records = glob($root . $pattern, GLOB_ERR);
            if ($records === false || $records !== []) {
                throw new SpacefastBootstrapConfigError('bootstrap_storage_retirement_requires_hot_storage');
            }
        }
        $queued = glob($jobs . '/queue/*.json', GLOB_ERR);
        if ($queued === false) {
            throw new SpacefastBootstrapConfigError('bootstrap_storage_retirement_jobs_unreadable');
        }
        foreach ($queued as $path) {
            $record = json_decode(file_get_contents($path), true);
            if (!is_array($record)) {
                throw new SpacefastBootstrapConfigError('bootstrap_storage_retirement_jobs_unreadable');
            }
            if (($record['type'] ?? null) === 'tier_demote') {
                throw new SpacefastBootstrapConfigError('bootstrap_storage_retirement_demote_pending');
            }
        }
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Write site-scoped config to `<docroot>/.stattic/storage/config.php`. Callers hold the retirement lock. */
function spacefast_bootstrap_write_config_unlocked(array $config, bool $onlyIfMissing = false): bool
{
    $config = spacefast_bootstrap_site_config($config);
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
    $contents = "<?php\n// Written by spacefast-bootstrap; the engine reads these through\n// _stattic_config_value().\nreturn [\n" . implode("\n", $entries) . "\n];\n";
    $tmp = spacefast_bootstrap_temp_file($installRoot, '.spacefast-config-');
    if (!is_string($tmp)) {
        return false;
    }
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

/** Restore missing config or remove shared storage authority from an owned site's existing config. */
function spacefast_bootstrap_reconcile_config(array $config): string
{
    return spacefast_bootstrap_with_storage_retirement_lock(
        static fn (): string => spacefast_bootstrap_reconcile_config_unlocked($config),
    );
}

function spacefast_bootstrap_reconcile_config_unlocked(array $config): string
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
        $sanitized = spacefast_bootstrap_site_config($existing);
        if ($sanitized !== $existing) {
            if (!spacefast_bootstrap_write_config_unlocked($sanitized)) {
                throw new SpacefastBootstrapConfigError('bootstrap_config_sanitize_failed');
            }
            return 'sanitized';
        }
        return 'unchanged';
    }
    // link() publishes the complete file only if no writer created the target
    // meanwhile, so a concurrent restore never overwrites another's config.
    if (!spacefast_bootstrap_write_config_unlocked($config, true)) {
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
    $tmp = spacefast_bootstrap_temp_file($installerDir, '.spacefast-installer-');
    if (!is_string($tmp)) {
        return ['error' => 'installer_stage_failed'];
    }
    if (!copy($bundled, $tmp) || !chmod($tmp, 0644) || !rename($tmp, $resident)) {
        return ['error' => 'installer_stage_failed'];
    }

    $phpCli = PHP_BINDIR . '/php';
    if (!is_file($phpCli)) {
        $phpCli = 'php';
    }
    putenv('SPACEFAST_RUNTIME_ENGINE_MD5=' . strtolower($spec['md5']));
    putenv('SPACEFAST_RUNTIME_ENGINE_REVISION=' . $spec['revision']);
    putenv('SPACEFAST_RUNTIME_ENGINE_EXPECTED_NONCE=' . ($spec['expected_nonce'] ?? ''));
    putenv('SPACEFAST_RUNTIME_ENGINE_COMMAND_ID=' . ($spec['command_id'] ?? ''));
    putenv('SPACEFAST_RUNTIME_ENGINE_EXPECTED_INSTANCE_ID=' . ($spec['runtime_instance_id'] ?? ''));
    if (($spec['native_sha256'] ?? '') !== '') {
        putenv('SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256=' . strtolower($spec['native_sha256']));
    }
    if ((string) getenv('PATH') === '') {
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
    }

    if (
        !function_exists('posix_setsid') || !function_exists('posix_kill') ||
        !defined('SIGTERM') || !defined('SIGKILL')
    ) {
        return ['error' => 'installer_process_isolation_unavailable'];
    }
    $launcher = <<<'PHP'
if (posix_setsid() < 0) { exit(127); }
$installer = $argv[1];
$zip = $argv[2];
$argv = [$installer, $zip];
require $installer;
PHP;
    $command = [$phpCli, '-d', 'auto_prepend_file=', '-d', 'opcache.enable_cli=0', '-r', $launcher, $resident, $spec['zip_url']];
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
    $startedAt = hrtime(true);
    $hardDeadline = $startedAt + (SPACEFAST_BOOTSTRAP_INSTALL_TIMEOUT_SECONDS * 1_000_000_000);
    $softDeadline = $hardDeadline - (SPACEFAST_BOOTSTRAP_INSTALL_GRACE_SECONDS * 1_000_000_000);
    $terminationReason = null;
    $killDeadline = $hardDeadline;
    $processGroup = null;
    for (;;) {
        $stdoutRemaining = max(0, 65536 - strlen($stdout));
        $stderrRemaining = max(0, 65536 - strlen($stderr));
        $stdoutChunk = (string) stream_get_contents($pipes[1], $stdoutRemaining + 1);
        $stderrChunk = (string) stream_get_contents($pipes[2], $stderrRemaining + 1);
        $outputExceeded = strlen($stdoutChunk) > $stdoutRemaining || strlen($stderrChunk) > $stderrRemaining;
        $stdout .= substr($stdoutChunk, 0, $stdoutRemaining);
        $stderr .= substr($stderrChunk, 0, $stderrRemaining);
        $status = proc_get_status($process);
        if ($processGroup === null && is_int($status['pid'] ?? null)) {
            $processGroup = $status['pid'];
        }
        $now = hrtime(true);
        if (!$status['running']) {
            break;
        }
        if ($terminationReason === null && ($outputExceeded || $now >= $softDeadline)) {
            $terminationReason = $outputExceeded ? 'installer_output_limit' : 'installer_timeout';
            $killDeadline = $outputExceeded
                ? $now + (SPACEFAST_BOOTSTRAP_INSTALL_GRACE_SECONDS * 1_000_000_000)
                : $hardDeadline;
            if (is_int($processGroup)) {
                posix_kill(-$processGroup, SIGTERM);
            }
            proc_terminate($process, SIGTERM);
        }
        if ($terminationReason !== null && $now >= $killDeadline) {
            if (is_int($processGroup)) {
                posix_kill(-$processGroup, SIGKILL);
            }
            proc_terminate($process, SIGKILL);
            break;
        }
        usleep(200000);
    }
    $stdout .= substr((string) stream_get_contents($pipes[1]), 0, max(0, 65536 - strlen($stdout)));
    $stderr .= substr((string) stream_get_contents($pipes[2]), 0, max(0, 65536 - strlen($stderr)));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($terminationReason !== null) {
        return ['error' => $terminationReason];
    }

    $jsonStart = strpos($stdout, '{');
    $receipt = $jsonStart === false ? null : json_decode(substr($stdout, $jsonStart), true);
    if ($exitCode !== 0 || !is_array($receipt)) {
        return [
            'error' => 'installer_failed',
            'exit_code' => $exitCode,
            'detail' => substr(trim($stderr !== '' ? $stderr : $stdout), 0, 512),
        ];
    }
    if (
        !in_array($receipt['status'] ?? null, ['installed', 'current'], true) ||
        ($receipt['engine_revision'] ?? null) !== $spec['revision'] ||
        ($receipt['layout'] ?? null) !== 'release'
    ) {
        return [
            'error' => ($receipt['status'] ?? null) === 'busy' ? 'installer_busy' : 'installer_receipt_invalid',
            'receipt' => $receipt,
        ];
    }
    return ['receipt' => $receipt];
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
         * <native-sha256>
         * : Expected sha256 of the native binary.
         *
         * <expected-nonce>
         * : Publication nonce read before choosing the desired release.
         *
         * <command-id>
         * : Stable identity for this publication attempt.
         *
         * <runtime-instance-id>
         * : Expected physical runtime identity.
         */
        public function install(array $args): void
        {
            $spec = [
                'zip_url' => $args[0] ?? '',
                'md5' => strtolower($args[1] ?? ''),
                'revision' => $args[2] ?? '',
                'native_sha256' => strtolower($args[3] ?? ''),
                'expected_nonce' => $args[4] ?? '',
                'command_id' => $args[5] ?? '',
                'runtime_instance_id' => $args[6] ?? '',
            ];
            if (
                !str_starts_with($spec['zip_url'], 'http') ||
                !preg_match('/^[a-f0-9]{32}$/', $spec['md5']) ||
                $spec['revision'] === '' ||
                $spec['expected_nonce'] === '' || $spec['command_id'] === '' || $spec['runtime_instance_id'] === ''
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

        /** Report bootstrap state: engine layout and config file. */
        public function status(): void
        {
            $docroot = spacefast_bootstrap_docroot();
            WP_CLI::log(json_encode([
                'config_file' => is_file($docroot . '/.stattic/storage/config.php'),
                'active_release' => is_file($docroot . '/.stattic/active-release'),
                'resident_installer' => is_file($docroot . '/__spacefast/engine-update.php'),
            ]));
        }
    });
}
