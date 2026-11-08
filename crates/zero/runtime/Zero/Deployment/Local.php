<?php

declare(strict_types=1);

namespace Zero\Deployment;

/**
 * Offline local WordPress workspaces, never a web host or a hot code updater.
 * Every supported process is synchronous under the workspace lock. An operation with
 * an uncertain outcome blocks mutation; no stale lease is treated as process drain.
 */
final class Local
{
    private const ADAPTER = 'zero-offline-wordpress-v1';
    private static mixed $lock = null;

    public static function main(array $arguments): int
    {
        umask(0077);
        try {
            Files::require(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80200 && class_exists(\SQLite3::class) && class_exists(\ZipArchive::class),
                'missing_tools', 'Local deployment needs CLI PHP 8.2+, SQLite3 and ZipArchive.');
            [$mode, $options, $wp] = self::arguments($arguments);
            $data = match ($mode) {
                'inspect' => self::inspect($options),
                'publish' => self::publish($options),
                'init' => self::init($options),
                'status' => self::status($options),
                'wp' => self::wp($options, $wp),
                'backup' => self::backup($options),
                'restore' => self::restore($options),
                default => throw new Failure('usage', 'Use inspect, publish, init, status, wp, backup or restore. See docs/deployment.md.'),
            };
            echo \Zero\Json::encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        } catch (Failure $error) {
            echo \Zero\Json::encode(['error' => ['code' => $error->problem, 'message' => $error->getMessage(), ...$error->context]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            return 1;
        } catch (\Throwable) {
            // Native errors can include SQL values, config contents or provider credentials.
            echo "{\"error\":{\"code\":\"local_operation_failed\",\"message\":\"Local operation failed; inspect the private workspace and preserve any incomplete operation.\"}}\n";
            return 1;
        } finally {
            if (is_resource(self::$lock)) {
                flock(self::$lock, LOCK_UN);
                fclose(self::$lock);
                self::$lock = null;
            }
        }
    }

    private static function arguments(array $args): array
    {
        $mode = array_shift($args) ?? '';
        $allowed = match ($mode) {
            'inspect', 'publish' => ['artifact', 'baseline'],
            'init' => ['workspace', 'wordpress', 'sqlite', 'wp-cli', 'artifact'],
            'status', 'wp' => ['workspace'],
            'backup' => ['workspace', 'output'],
            'restore' => ['workspace', 'archive', 'wp-cli'],
            default => [],
        };
        $options = [];
        while ($args !== []) {
            $arg = array_shift($args);
            if ($arg === '--' && $mode === 'wp') {
                return [$mode, $options, $args];
            }
            if ($arg === '--json') {
                continue;
            }
            Files::require(str_starts_with($arg, '--') && in_array(substr($arg, 2), $allowed, true)
                && $args !== [] && !str_starts_with($args[0], '--') && !isset($options[substr($arg, 2)]), 'usage', 'Unknown, duplicate or incomplete option. See docs/deployment.md.');
            $options[substr($arg, 2)] = array_shift($args);
        }
        return [$mode, $options, []];
    }

    private static function option(array $options, string $name): string
    {
        return $options[$name] ?? throw new Failure('usage', 'Required option: --' . $name);
    }

    private static function inspect(array $options): array
    {
        $artifact = Artifact::inspect(self::option($options, 'artifact'));
        if (isset($options['baseline'])) {
            $baseline = Artifact::inspect($options['baseline']);
            Files::require($baseline['app'] === $artifact['app'], 'wrong_app', 'Baseline and candidate belong to different apps.');
            $previous = array_column($baseline['migrations'], 'sha256', 'id');
            $candidate = array_column($artifact['migrations'], 'sha256', 'id');
            $artifact['migrationHistoryChanged'] = array_keys(array_filter($previous, static fn ($sha, $id) => ($candidate[$id] ?? null) !== $sha, ARRAY_FILTER_USE_BOTH));
            $artifact['pendingMigrations'] = array_values(array_filter($artifact['migrations'], static fn ($migration) => !isset($previous[$migration['id']])));
            $artifact['schemaChanged'] = $artifact['schemaDigest'] === null || $baseline['schemaDigest'] === null ? 'unknown' : $artifact['schemaDigest'] !== $baseline['schemaDigest'];
        } else {
            $artifact['migrationHistoryChanged'] = [];
            $artifact['pendingMigrations'] = $artifact['migrations'];
        }
        $artifact['migrationPreflight'] = $artifact['migrationHistoryChanged'] !== [] ? 'history_changed'
            : ($artifact['pendingMigrations'] === [] ? 'no_changes' : 'review_required');
        $artifact['missingEnvironment'] = array_column(array_filter($artifact['environment'], static fn ($env) => !$env['present']), 'name');
        return $artifact;
    }

    private static function publish(array $options): never
    {
        $report = self::inspect($options);
        throw new Failure('host_capability_missing', 'Artifact preflight completed. Publishing is unavailable until a qualified host adapter supplies the required capabilities.', ['preflight' => $report]);
    }

    private static function executable(string $path): string
    {
        Files::directory(dirname($path));
        Files::require(is_file($path) && !is_link($path), 'missing_wp_cli', 'Pass a trusted local WP-CLI phar with --wp-cli.');
        return realpath($path);
    }

    private static function createWorkspace(string $path, string $wpCli): array
    {
        $root = Files::fresh($path);
        Files::require(mkdir($root, 0700), 'write_failed', 'Could not create workspace.');
        $owner = ['version' => 1, 'adapter' => self::ADAPTER, 'id' => bin2hex(random_bytes(16)), 'path' => $root, 'wpCli' => $wpCli, 'phase' => 'initializing'];
        Files::writeJson($root . '/owner.json', $owner);
        self::claim($root);
        return [$root, $owner];
    }

    private static function claim(string $root): void
    {
        Files::require(!is_link($root . '/lock'), 'unsafe_path', 'Workspace lock cannot be a symbolic link.');
        self::$lock = fopen($root . '/lock', 'c+');
        Files::require(self::$lock !== false && flock(self::$lock, LOCK_EX | LOCK_NB), 'workspace_busy', 'Another local process owns this workspace.');
    }

    private static function workspace(array $options, bool $mutating = false): array
    {
        $root = Files::directory(self::option($options, 'workspace'));
        Files::require((fileperms($root) & 0077) === 0, 'insecure_workspace', 'Workspace must be private (0700).');
        $owner = Files::json($root . '/owner.json');
        Files::require(($owner['version'] ?? null) === 1 && ($owner['adapter'] ?? null) === self::ADAPTER && ($owner['path'] ?? null) === $root,
            'unowned_workspace', 'Only an offline workspace created by this adapter is supported. Existing Studio and hosted sites are never adopted.');
        self::claim($root);
        if ($mutating) {
            Files::require(($owner['phase'] ?? null) === 'ready', 'workspace_incomplete', 'Initialization or restore is incomplete; preserve it for inspection and use a fresh destination.');
            if (is_file($root . '/operation.json')) {
                $operation = Files::json($root . '/operation.json');
                Files::require($operation['state'] === 'complete', 'operation_uncertain', 'Previous operation has no completion receipt. Do not replay it; inspect the private logs or restore a prior backup to a fresh workspace.');
            }
        }
        return [$root, $owner];
    }

    private static function operation(string $root, string $kind): array
    {
        $record = ['id' => bin2hex(random_bytes(12)), 'kind' => $kind, 'state' => 'running', 'startedAt' => gmdate('c')];
        Files::writeJson($root . '/operation.json', $record);
        return $record;
    }

    private static function complete(string $root, array $operation): void
    {
        $operation['state'] = 'complete';
        $operation['completedAt'] = gmdate('c');
        Files::writeJson($root . '/operation.json', $operation);
    }

    /** Native output stays in restricted files; never interpolate native errors into receipts. */
    private static function native(string $root, array $owner, array $args): string
    {
        $log = $root . '/native-' . bin2hex(random_bytes(10)) . '.log';
        // Isolate WP-CLI's user configuration, package loading and cache from other sites.
        if (!is_file($root . '/wp-cli.yml')) {
            file_put_contents($root . '/wp-cli.yml', "color: false\n");
        }
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'WP_CLI_')) {
                unset($environment[$name]);
            }
        }
        $environment['WP_CLI_CONFIG_PATH'] = $root . '/wp-cli.yml';
        $environment['WP_CLI_PACKAGES_DIR'] = $root . '/wp-cli-packages';
        $environment['WP_CLI_CACHE_DIR'] = $root . '/wp-cli-cache';
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=8191', $owner['wpCli'], '--path=' . $root . '/site', '--no-color', '--skip-packages', ...$args];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.error', 'w'], 3 => self::$lock], $pipes, $root, $environment);
        Files::require(is_resource($process), 'wp_cli_failed', 'Could not launch WP-CLI.');
        $started = hrtime(true);
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((hrtime(true) - $started) / 1e9 > 120) {
                proc_terminate($process);
                throw new Failure('wp_cli_timeout', 'WP-CLI exceeded 120 seconds. Operation remains uncertain; no automatic replay.');
            }
            // Wait for child state, not an arbitrary readiness delay.
            time_nanosleep(0, 20_000_000);
        } while (true);
        $code = $status['exitcode'];
        $closed = proc_close($process);
        Files::require(($code === -1 ? $closed : $code) === 0, 'wp_cli_failed', 'WP-CLI failed. Inspect the restricted native logs; its output was redacted.');
        return $log;
    }

    private static function init(array $options): array
    {
        $wpCli = self::executable(self::option($options, 'wp-cli'));
        $wordpress = Files::directory(self::option($options, 'wordpress'));
        $sqlite = Files::directory(self::option($options, 'sqlite'));
        Files::require(is_file($wordpress . '/wp-load.php') && is_file($sqlite . '/db.copy'), 'invalid_source', 'Provide trusted unpacked WordPress core and SQLite Database Integration sources.');
        $artifact = isset($options['artifact']) ? Artifact::inspect($options['artifact']) : null;
        [$root, $owner] = self::createWorkspace(self::option($options, 'workspace'), $wpCli);
        $operation = self::operation($root, 'initialize');
        mkdir($root . '/site', 0700);
        foreach (['wp-admin', 'wp-includes'] as $directory) {
            Files::copy($wordpress . '/' . $directory, $root . '/site/' . $directory);
        }
        foreach (glob($wordpress . '/*') as $file) {
            if (is_file($file) && !is_link($file) && basename($file) !== 'wp-config.php') {
                copy($file, $root . '/site/' . basename($file));
                chmod($root . '/site/' . basename($file), 0600);
            }
        }
        mkdir($root . '/site/wp-content/plugins', 0700, true);
        mkdir($root . '/site/wp-content/uploads', 0700);
        mkdir($root . '/site/wp-content/database', 0700);
        Files::copy($sqlite, $root . '/site/wp-content/plugins/sqlite-database-integration');
        $dropin = str_replace(['{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '{SQLITE_PLUGIN}'], ['__ZERO_RELOCATABLE_SQLITE__', 'sqlite-database-integration/load.php'], file_get_contents($sqlite . '/db.copy'));
        file_put_contents($root . '/site/wp-content/db.php', $dropin);
        $config = "<?php\n";
        foreach (['DB_NAME' => 'wordpress', 'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => 'localhost', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '',
            'WP_ENVIRONMENT_TYPE' => 'local', 'DISABLE_WP_CRON' => true, 'ZERO_IDENTITY_DEV_HTTP' => true, 'WP_DEBUG_DISPLAY' => false] as $key => $value) {
            $config .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
        }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) {
            $config .= 'define(' . var_export($key, true) . ', ' . var_export(bin2hex(random_bytes(32)), true) . ");\n";
        }
        $config .= "define('DB_DIR', __DIR__ . '/wp-content/database/');\ndefine('DB_FILE', '.ht.sqlite');\n\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
        file_put_contents($root . '/site/wp-config.php', $config);
        $password = bin2hex(random_bytes(24));
        Files::writeJson($root . '/credentials.json', ['user' => 'admin', 'password' => $password, 'scope' => 'this disposable offline local WordPress site only']);
        self::native($root, $owner, ['core', 'install', '--url=http://zero-offline.invalid', '--title=Zero offline workspace', '--admin_user=admin', '--admin_password=' . $password, '--admin_email=admin@example.invalid', '--skip-email']);
        if ($artifact !== null) {
            $source = Files::directory($options['artifact']);
            $slugs = $artifact['plugins'];
            foreach ($slugs as $slug) {
                Files::require(!file_exists($root . '/site/wp-content/plugins/' . $slug), 'plugin_collision', 'Artifact collides with the native SQLite integration.');
                Files::copy($source . '/plugins/' . $slug, $root . '/site/wp-content/plugins/' . $slug);
            }
            // Native plugin dependencies remain authoritative; activate each in a fresh bootstrap.
            $order = [...array_values(array_filter($slugs, static fn ($slug) => !str_starts_with($slug, 'zero-'))),
                ...array_values(array_filter(['zero-runtime', 'zero-identity', 'zero-admin', 'zero-app-' . $artifact['app']], static fn ($slug) => in_array($slug, $slugs, true)))];
            foreach ($order as $slug) {
                self::native($root, $owner, ['plugin', 'activate', $slug]);
            }
            Files::writeJson($root . '/artifact.json', $artifact);
        }
        $owner['phase'] = 'ready';
        Files::writeJson($root . '/owner.json', $owner);
        self::complete($root, $operation);
        return ['workspace' => $root, 'state' => 'ready', 'serving' => false, 'adapter' => self::ADAPTER];
    }

    private static function db(string $root): \SQLite3
    {
        $path = $root . '/site/wp-content/database/.ht.sqlite';
        Files::require(is_file($path) && !is_link($path), 'database_missing', 'The managed SQLite database is missing.');
        $db = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        return $db;
    }

    private static function rows(\SQLite3 $db, string $sql): array
    {
        $result = $db->query($sql);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function diagnostics(string $root): array
    {
        $db = self::db($root);
        try {
            $tables = array_column(self::rows($db, "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"), 'name');
            $data = ['sqliteIntegrity' => $db->querySingle('PRAGMA quick_check') === 'ok', 'tableCount' => count($tables),
                'contentCount' => (int) $db->querySingle('SELECT COUNT(*) FROM wp_posts'), 'userCount' => (int) $db->querySingle('SELECT COUNT(*) FROM wp_users'),
                'migrations' => [], 'jobs' => ['owner' => 'native Zero jobs / Action Scheduler', 'states' => []], 'failures' => [], 'uploads' => ['externalReferences' => 0]];
            if (in_array('wp_zero__migrations', $tables, true)) {
                $data['migrations'] = self::rows($db, 'SELECT app,id FROM wp_zero__migrations ORDER BY app,id');
            }
            $artifact = is_file($root . '/artifact.json') ? Files::json($root . '/artifact.json') : null;
            $data['migrationReadiness'] = ['state' => 'unknown', 'pending' => [], 'partialSteps' => count(array_filter($data['migrations'], static fn ($row) => str_contains($row['id'], ':')))];
            if ($artifact !== null) {
                $applied = array_column(array_filter($data['migrations'], static fn ($row) => $row['app'] === $artifact['app']), 'id');
                $data['migrationReadiness']['pending'] = array_values(array_diff(array_column($artifact['migrations'], 'id'), $applied));
                $data['migrationReadiness']['state'] = $data['migrationReadiness']['pending'] === [] && $data['migrationReadiness']['partialSteps'] === 0 ? 'applied' : 'needs_reconciliation';
            }
            foreach (['wp_zero__jobs' => 'state', 'wp_actionscheduler_actions' => 'status'] as $table => $state) {
                if (in_array($table, $tables, true)) {
                    $data['jobs']['states'][$table] = self::rows($db, "SELECT $state AS state,COUNT(*) AS count FROM $table GROUP BY $state");
                }
            }
            if (in_array('wp_zero__jobs', $tables, true)) {
                // Report counts and redact arbitrary exception strings and payloads.
                $data['failures']['jobsWithError'] = (int) $db->querySingle("SELECT COUNT(*) FROM wp_zero__jobs WHERE lastError IS NOT NULL AND lastError <> ''");
            }
            if (in_array('wp_zero__files', $tables, true)) {
                $data['uploads']['externalReferences'] += (int) $db->querySingle("SELECT COUNT(*) FROM wp_zero__files WHERE path LIKE 's3:%'");
            }
            $data['uploads']['externalReferences'] += (int) $db->querySingle("SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_zero_uploads' AND meta_value NOT IN ('','a:0:{}')");
            $data['uploads']['providerNamespacePresent'] = (int) $db->querySingle("SELECT COUNT(*) FROM wp_options WHERE option_name='zero_upload_namespace'") > 0;
            return $data;
        } finally {
            $db->close();
        }
    }

    private static function status(array $options): array
    {
        [$root, $owner] = self::workspace($options);
        $operation = is_file($root . '/operation.json') ? Files::json($root . '/operation.json') : null;
        return ['adapter' => self::ADAPTER, 'phase' => $owner['phase'], 'serving' => false, 'publishReady' => false,
            'operation' => $operation, 'diagnostics' => is_file($root . '/site/wp-content/database/.ht.sqlite') ? self::diagnostics($root) : ['state' => 'database_unavailable'],
            'recovery' => ($operation['state'] ?? null) === 'complete' ? 'none' : 'inspect_private_logs_or_restore_prior_backup_to_fresh_workspace'];
    }

    private static function wp(array $options, array $arguments): array
    {
        Files::require($arguments !== [], 'usage', 'Pass native WP-CLI arguments after --.');
        foreach ($arguments as $argument) {
            Files::require(!preg_match('/^--(path|url|ssh|http)(=|$)/', $argument) && !str_starts_with($argument, '@'),
                'unsupported_wp_target', 'Native commands must target this one offline workspace; global target overrides and aliases are refused.');
        }
        Files::require(preg_match('/^[a-z][a-z0-9_-]*$/i', $arguments[0]) === 1
            && !in_array(strtolower($arguments[0]), ['server', 'shell'], true),
            'unsupported_wp_process', 'Put the native command before its options. Offline workspaces do not start servers or interactive shells.');
        [$root, $owner] = self::workspace($options, true);
        $operation = self::operation($root, 'wp');
        $log = self::native($root, $owner, $arguments);
        self::complete($root, $operation);
        return ['state' => 'complete', 'resultFile' => $log, 'output' => '[redacted; private local file]'];
    }

    private static function backup(array $options): array
    {
        [$root] = self::workspace($options, true);
        $output = Files::fresh(self::option($options, 'output'));
        Files::require(!str_starts_with($output, $root . '/'), 'unsafe_path', 'Backups must be outside the workspace.');
        $diagnostics = self::diagnostics($root);
        Files::require($diagnostics['sqliteIntegrity'], 'database_corrupt', 'SQLite integrity check failed.');
        Files::require($diagnostics['uploads']['externalReferences'] === 0 && !$diagnostics['uploads']['providerNamespacePresent'], 'provider_backup_unavailable', 'Provider-backed uploads require a qualified object snapshot adapter; a database-only backup would omit owned bytes.');
        $operation = self::operation($root, 'backup');
        $scratch = dirname($output) . '/.zero-backup-' . bin2hex(random_bytes(12));
        mkdir($scratch, 0700);
        try {
            Files::copy($root . '/site', $scratch . '/site');
            $database = $scratch . '/site/wp-content/database/.ht.sqlite';
            foreach ([$database, $database . '-wal', $database . '-shm', $database . '-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $source = self::db($root);
            $destination = new \SQLite3($database);
            try {
                Files::require($source->backup($destination) && $destination->querySingle('PRAGMA quick_check') === 'ok', 'snapshot_failed', 'SQLite backup failed integrity verification.');
            } finally {
                $source->close();
                $destination->close();
            }
            $files = Files::inventory($scratch . '/site');
            $metadata = ['version' => 1, 'adapter' => self::ADAPTER, 'createdAt' => gmdate('c'), 'siteIdentity' => Files::json($root . '/owner.json')['id'],
                'scope' => 'offline WordPress SQLite database, whole installed code/config and local uploads; process environment excluded',
                'files' => $files, 'diagnostics' => $diagnostics, 'artifact' => is_file($root . '/artifact.json') ? Files::json($root . '/artifact.json') : null];
            $encoded = \Zero\Json::encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            Files::require(strlen($encoded) <= 16_777_216, 'metadata_limit', 'Backup inventory exceeds the supported metadata limit.');
            $archive = $scratch . '/snapshot.zip';
            $zip = new \ZipArchive();
            Files::require($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::EXCL) === true, 'archive_failed', 'Could not create backup.');
            $zip->addFromString('backup.json', $encoded);
            foreach ($files as $name => $record) {
                $zip->addFile($scratch . '/site/' . $name, 'site/' . $name);
            }
            Files::require($zip->close(), 'archive_failed', 'Could not finalize backup.');
            chmod($archive, 0600);
            // Hard-link publication is atomic and refuses a racing existing destination.
            Files::require(@link($archive, $output), 'destination_exists', 'Backup destination appeared during publication; it was preserved.');
            $digest = hash_file('sha256', $output);
            self::complete($root, $operation);
            return ['state' => 'complete', 'archive' => $output, 'sha256' => $digest, 'containsSecrets' => true, 'mode' => '0600', 'fileCount' => count($files), 'providerObjects' => 'none'];
        } finally {
            Files::remove($scratch);
        }
    }

    private static function restore(array $options): array
    {
        $archive = self::option($options, 'archive');
        Files::directory(dirname($archive));
        Files::require(is_file($archive) && !is_link($archive) && (fileperms($archive) & 0077) === 0, 'insecure_archive', 'Backups may contain secrets; require a regular local archive with mode 0600.');
        $destination = Files::fresh(self::option($options, 'workspace'));
        $wpCli = self::executable(self::option($options, 'wp-cli'));
        $zip = new \ZipArchive();
        Files::require($zip->open($archive, \ZipArchive::RDONLY) === true, 'invalid_archive', 'Backup is not a readable ZIP archive.');
        $open = true;
        $scratch = dirname($destination) . '/.zero-restore-' . bin2hex(random_bytes(12));
        try {
            Files::require($zip->numFiles <= Files::MAX_FILES + 1, 'size_limit', 'Backup contains too many entries.');
            $raw = $zip->getFromName('backup.json', 16_777_217);
            Files::require(is_string($raw) && strlen($raw) <= 16_777_216, 'invalid_archive', 'Backup metadata is missing or too large.');
            try {
                $metadata = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new Failure('invalid_archive', 'Backup metadata is invalid JSON.');
            }
            Files::require(is_array($metadata) && ($metadata['version'] ?? null) === 1 && ($metadata['adapter'] ?? null) === self::ADAPTER && is_array($metadata['files'] ?? null), 'invalid_archive', 'Unsupported backup format.');
            $seen = [];
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                Files::relative($name);
                $zip->getExternalAttributesIndex($i, $system, $attributes);
                $type = ($attributes >> 16) & 0170000;
                Files::require(!isset($seen[$name]) && in_array($type, [0, 0100000], true), 'unsafe_archive', 'Duplicate, linked or non-regular archive entry.');
                $seen[$name] = true;
                $bytes += $entry['size'];
                Files::require($bytes <= Files::MAX_BYTES, 'size_limit', 'Backup exceeds the expanded size limit.');
                if ($name === 'backup.json') {
                    continue;
                }
                Files::require(str_starts_with($name, 'site/'), 'unsafe_archive', 'Archive entry is outside the site payload.');
                $record = $metadata['files'][substr($name, 5)] ?? null;
                Files::require(is_array($record) && ($record['size'] ?? null) === $entry['size'] && is_string($record['sha256'] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/D', $record['sha256']) === 1, 'invalid_archive', 'Archive entry is not described by the inventory.');
            }
            Files::require(count($seen) === count($metadata['files']) + 1, 'invalid_archive', 'Backup inventory is incomplete.');
            mkdir($scratch, 0700);
            mkdir($scratch . '/site', 0700);
            foreach ($metadata['files'] as $name => $record) {
                Files::relative($name);
                $target = $scratch . '/site/' . $name;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                $stream = $zip->getStream('site/' . $name);
                Files::require(is_resource($stream), 'invalid_archive', 'Backup entry cannot be opened.');
                $file = fopen($target, 'x');
                try {
                    $copied = stream_copy_to_stream($stream, $file, $record['size'] + 1);
                } finally {
                    fclose($stream);
                    fclose($file);
                }
                Files::require($copied === $record['size'] && hash_file('sha256', $target) === $record['sha256'], 'digest_mismatch', 'Backup entry failed digest verification.');
            }
            $zip->close();
            $open = false;
            Files::require(self::diagnostics($scratch)['sqliteIntegrity'], 'database_corrupt', 'Restored database failed integrity verification.');
            // mkdir reserves the destination without overwriting a racing file/directory.
            [$root, $owner] = self::createWorkspace($destination, $wpCli);
            $operation = self::operation($root, 'restore');
            rename($scratch . '/site', $root . '/site');
            if (is_array($metadata['artifact'] ?? null)) {
                Files::writeJson($root . '/artifact.json', $metadata['artifact']);
            }
            $owner['restoredFrom'] = hash_file('sha256', $archive);
            $owner['originalSiteIdentity'] = $metadata['siteIdentity'] ?? null;
            // Offline clones preserve WP identities but get a distinct workspace owner.
            $owner['phase'] = 'ready';
            Files::writeJson($root . '/owner.json', $owner);
            self::complete($root, $operation);
            return ['state' => 'complete', 'workspace' => $root, 'serving' => false, 'wordpressBootstrap' => 'not_executed; verify trusted backup using wp', 'diagnostics' => self::diagnostics($root)];
        } finally {
            if ($open) {
                $zip->close();
            }
            Files::remove($scratch);
        }
    }
}
