<?php
declare(strict_types=1);

// CLI program run by the SSH bootstrap and the /engine/update management
// route, never a web entrypoint: this guard fails closed under a provider that
// direct-executes arbitrary PHP files. Zip source rides argv[1] (an https URL
// or a local path); SPACEFAST_RUNTIME_ENGINE_MD5 / _REVISION /
// _NATIVE_SHA256 (optional) ride the environment. The JSON receipt on stdout
// is the whole report — there is no callback.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

function fail(string $message): never
{
    static $cleaning = false;
    if (!$cleaning) {
        $cleaning = true;
        cleanup_registered_paths();
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $entries = scandir($path);
    if (!is_array($entries)) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            rrmdir($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function ensure_runtime_storage_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    @chmod($path, 0775);
}

// Directory modes are asserted, not inherited: a provider umask of 077 would
// otherwise leave the engine tree unreadable to php-fpm.
function ensure_install_dir(string $path): bool
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        return false;
    }
    @chmod($path, 0755);
    return true;
}

function unsafe_relative_path(string $p): bool
{
    return $p === '' || str_starts_with($p, '/') || str_contains($p, '..') || str_contains($p, '\\');
}

// Names reserved for site state, the active pointer, immutable releases, and
// installer scratch. A payload may not claim any of them.
function reserved_install_root_name(string $name): bool
{
    return in_array($name, ['storage', 'incoming', 'installer.lock', 'active-release', 'active-release.php', 'loader-version', 'releases', '', '.', '..'], true)
        || str_starts_with($name, 'engine-stage-')
        || str_starts_with($name, 'engine-old-')
        || str_starts_with($name, 'release-stage-')
        || str_starts_with($name, 'runtime-install-');
}

/**
 * @return array{staged:list<array{source:string,path:string,relative:string,executable:bool}>,alias:list<array{source:string,path:string,executable:bool}>}
 */
function read_engine_manifest(string $root): array
{
    $path = $root . '/engine-manifest.json';
    if (!is_file($path)) {
        fail('runtime_engine_manifest_missing');
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
        fail('runtime_engine_manifest_invalid');
    }
    $sourceFiles = [];
    foreach ($decoded['files'] as $file) {
        if (!is_string($file) || unsafe_relative_path($file)) {
            fail('runtime_engine_manifest_invalid');
        }
        $sourceFiles[] = $file;
    }
    if (count(array_unique($sourceFiles)) !== count($sourceFiles) || !in_array('engine-manifest.json', $sourceFiles, true)) {
        fail('runtime_engine_manifest_invalid');
    }
    $executables = [];
    foreach (is_array($decoded['executables'] ?? null) ? $decoded['executables'] : [] as $file) {
        if (!is_string($file) || !in_array($file, $sourceFiles, true)) {
            fail('runtime_engine_manifest_invalid');
        }
        $executables[$file] = true;
    }

    $staged = [];
    foreach ($sourceFiles as $file) {
        $topLevel = explode('/', $file)[0];
        if (reserved_install_root_name($topLevel)) {
            fail('runtime_engine_manifest_invalid');
        }
        $staged[] = [
            'source' => $file,
            'path' => '.stattic/' . $file,
            'relative' => $file,
            'executable' => isset($executables[$file]),
        ];
    }

    $alias = [];
    foreach (($decoded['aliases'] ?? []) as $entry) {
        if (
            !is_array($entry)
            || !is_string($entry['source'] ?? null)
            || !is_string($entry['path'] ?? null)
            || !in_array($entry['source'], $sourceFiles, true)
            // `.stattic/` is private release/storage state, never an alias
            // destination.
            || unsafe_relative_path($entry['path'])
            || str_starts_with($entry['path'], '.stattic/')
        ) {
            fail('runtime_engine_manifest_invalid');
        }
        $alias[] = [
            'source' => $entry['source'],
            'path' => $entry['path'],
            'executable' => isset($executables[$entry['source']]),
        ];
    }

    $installedFiles = array_merge(
        array_map(fn (array $entry): string => $entry['path'], $staged),
        array_map(fn (array $entry): string => $entry['path'], $alias),
    );
    if (count(array_unique($installedFiles)) !== count($installedFiles)) {
        fail('runtime_engine_manifest_invalid');
    }
    usort($staged, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    usort($alias, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    return ['staged' => $staged, 'alias' => $alias];
}

/** @return array{code:int,stdout:string,stderr:string,failed:bool} */
function run_native_self_test(string $binary): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = @proc_open([$binary, '--self-test'], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process) || count($pipes) !== 3) {
        return ['code' => -1, 'stdout' => '', 'stderr' => '', 'failed' => true];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $failed = false;
    $exitCode = -1;
    $deadline = microtime(true) + 5.0;
    do {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (strlen($stdout) > 65536 || strlen($stderr) > 65536) {
            $failed = true;
            @proc_terminate($process, 9);
            break;
        }
        $status = proc_get_status($process);
        if (!is_array($status)) {
            $failed = true;
            break;
        }
        if (!($status['running'] ?? false)) {
            if (is_int($status['exitcode'] ?? null)) {
                $exitCode = $status['exitcode'];
            }
            break;
        }
        if (microtime(true) >= $deadline) {
            $failed = true;
            @proc_terminate($process, 9);
            break;
        }
        usleep(10000);
    } while (true);

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedCode = proc_close($process);
    if ($closedCode >= 0) {
        $exitCode = $closedCode;
    }
    return [
        'code' => $exitCode,
        'stdout' => substr($stdout, 0, 65536),
        'stderr' => substr($stderr, 0, 65536),
        'failed' => $failed,
    ];
}

/**
 * Anything the payload carries that the manifest does not declare never reaches
 * the live tree.
 *
 * @param list<array{source:string,relative:string,executable:bool}> $staged
 */
function build_engine_stage(string $extractRoot, string $stageRoot, array $staged): void
{
    if (!ensure_install_dir($stageRoot)) {
        fail('runtime_engine_stage_unavailable');
    }
    foreach ($staged as $entry) {
        $source = $extractRoot . '/' . $entry['source'];
        if (!is_file($source)) {
            fail('runtime_zip_file_missing:' . $entry['source']);
        }
        $target = $stageRoot . '/' . $entry['relative'];
        if (!ensure_install_dir(dirname($target))) {
            fail('runtime_engine_stage_mkdir_failed:' . $entry['relative']);
        }
        if (!@copy($source, $target)) {
            fail('runtime_engine_stage_copy_failed:' . $entry['relative']);
        }
        // Fatal on purpose: the live tree is untouched here, and a
        // non-executable binary would otherwise surface as a serving failure.
        if (!@chmod($target, $entry['executable'] ? 0755 : 0644)) {
            fail('runtime_engine_stage_chmod_failed:' . $entry['relative']);
        }
    }
}

/**
 * The self-test runs the STAGED binary, not the extracted one: the staged tree is
 * what goes live, modes and all.
 *
 * @param list<array{relative:string}> $staged
 */
function validate_engine_stage(string $stageRoot, array $staged): void
{
    $stagedFiles = [];
    foreach ($staged as $entry) {
        $stagedFiles[$entry['relative']] = true;
    }

    $nativeProbes = [
        'bin/stattic-runtime' => 'stattic.runtime.self-test.v1',
    ];
    foreach ($nativeProbes as $relative => $expectedFormat) {
        if (!isset($stagedFiles[$relative])) {
            fail('runtime_native_manifest_missing:' . $relative);
        }
        $result = run_native_self_test($stageRoot . '/' . $relative);
        $probe = json_decode($result['stdout'], true);
        if ($result['failed'] || $result['code'] !== 0 || !is_array($probe) || ($probe['format'] ?? null) !== $expectedFormat) {
            fail('runtime_native_self_test_failed:' . $relative);
        }
    }
}

function active_release_pointer_target(string $installRoot): ?string
{
    foreach (['/active-release' => false, '/active-release.php' => true] as $name => $php) {
        $pointer = $installRoot . $name;
        if (!is_file($pointer) || is_link($pointer)) {
            continue;
        }
        clearstatcache(true, $pointer);
        $raw = file_get_contents($pointer, false, null, 0, 256);
        if (!is_string($raw)) {
            continue;
        }
        $target = $php ? active_release_pointer_php_target($raw) : trim($raw);
        if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) === 1) {
            return $target;
        }
    }
    return null;
}

function active_release_pointer_php_target(string $raw): string
{
    return preg_match("#^<\\?php return '([^']*)';$#", trim($raw), $match) === 1 ? $match[1] : '';
}

function active_release_root(string $installRoot): ?string
{
    $target = active_release_pointer_target($installRoot);
    if ($target === null) {
        return null;
    }
    $resolved = realpath($installRoot . '/' . $target);
    $installResolved = realpath($installRoot);
    if (!is_string($resolved) || !is_string($installResolved)) {
        return null;
    }
    if (!str_starts_with($resolved, $installResolved . '/releases/')) {
        return null;
    }
    return $resolved;
}

function publish_active_release(string $installRoot, string $target): bool
{
    if (preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1) {
        return false;
    }
    $pointer = $installRoot . '/active-release';
    if (is_dir($pointer) || is_link($pointer)) {
        return false;
    }
    $temporary = $pointer . '.tmp.' . getmypid();
    @unlink($temporary);
    if (
        @file_put_contents($temporary, $target . "\n", LOCK_EX) === false
        || !@chmod($temporary, 0644)
    ) {
        @unlink($temporary);
        return false;
    }
    if (!@rename($temporary, $pointer)) {
        @unlink($temporary);
        return false;
    }
    clearstatcache(true, $pointer);
    @unlink($installRoot . '/active-release.php');
    return active_release_pointer_target($installRoot) === $target;
}

function remove_active_release_pointer(string $installRoot): bool
{
    $removed = @unlink($installRoot . '/active-release');
    $removed = @unlink($installRoot . '/active-release.php') || $removed;
    return $removed;
}

/**
 * The identity of the public loader this payload would install: sha256 over
 * every alias, ordered by served path (read_engine_manifest sorts them), as
 * `<served path>\0<byte length>\0<bytes>`. Deriving it from the payload rather
 * than from a version number or a timestamp is what makes it hermetic — the
 * same zip always answers the same identity, and any change to what the loader
 * *is* (its bytes, its served path, the set of aliases) changes it.
 *
 * @param list<array{source:string,path:string}> $alias
 */
function loader_payload_identity(string $extractRoot, array $alias): string
{
    $digest = hash_init('sha256');
    foreach ($alias as $entry) {
        $source = $extractRoot . '/' . $entry['source'];
        $size = is_file($source) ? filesize($source) : false;
        if ($size === false || !hash_update($digest, $entry['path'] . "\0" . $size . "\0") || !hash_update_file($digest, $source)) {
            fail('runtime_engine_loader_source_unreadable:' . $entry['source']);
        }
    }
    return hash_final($digest);
}

// The marker holds the identity of the loader that is actually installed. A
// box that installed before this marker became content-derived carries the
// frozen literal it used to be written with; that reads as "no identity", so
// its loader is reinstalled once and the marker converges.
function installed_loader_identity(string $installRoot): ?string
{
    $raw = @file_get_contents($installRoot . '/loader-version', false, null, 0, 128);
    $identity = is_string($raw) ? trim($raw) : '';
    return preg_match('/^[a-f0-9]{64}$/', $identity) === 1 ? $identity : null;
}

function is_remote_zip_source(string $source): bool
{
    return preg_match('#^https?://#', $source) === 1;
}

function download_zip(string $source, string $target): void
{
    if (!is_remote_zip_source($source)) {
        return;
    }
    if (!function_exists('curl_init')) {
        fail('runtime_curl_unavailable');
    }
    $downloaded = download_http_file($source, $target, 120);
    if (!$downloaded || !is_file($target)) {
        fail('runtime_zip_download_failed');
    }
}

function installer_config_value(string $envName): string
{
    if (defined($envName)) {
        $value = constant($envName);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    $raw = getenv($envName);
    if (is_string($raw) && trim($raw) !== '') {
        return trim($raw);
    }

    return '';
}

// This regex is the drift guard against build-runtime-engine-zip's emitted
// line. Revisions are deterministic (git commit hash, or dev-<sourcehash>), so
// identity is plain string equality. Strict is for a freshly staged payload,
// where a missing constant is fatal; lenient answers "what is installed right
// now", where absent means "not converged yet".
function read_engine_revision(string $root, bool $strict = false): ?string
{
    $path = $root . '/engine/shared/context.php';
    // Read a bounded head first so a converge probe does not slurp the whole
    // module; fall back to a full read only if the regex misses.
    $head = is_file($path) ? file_get_contents($path, false, null, 0, 8192) : false;
    if (is_string($head) && preg_match("/const SPACEFAST_RUNTIME_ENGINE_REVISION = '([^']+)';/", $head, $match) === 1) {
        return $match[1];
    }
    $raw = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($raw)) {
        if ($strict) {
            fail('runtime_engine_context_missing');
        }
        return null;
    }
    if (preg_match("/const SPACEFAST_RUNTIME_ENGINE_REVISION = '([^']+)';/", $raw, $match) !== 1) {
        if ($strict) {
            fail('runtime_engine_revision_missing');
        }
        return null;
    }
    return $match[1];
}

function download_http_file(string $url, string $target, int $timeoutSeconds): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }

    for ($attempt = 0; $attempt < 4; ++$attempt) {
        $output = @fopen($target, 'wb');
        $curl = curl_init($url);
        if (!is_resource($output) || $curl === false) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($target);
            return false;
        }
        $configured = curl_setopt_array($curl, [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR => true,
            CURLOPT_FILE => $output,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        $downloaded = $configured && curl_exec($curl) === true;
        fclose($output);
        if ($downloaded) {
            return true;
        }
        @unlink($target);
        if ($attempt < 3) {
            sleep(2);
        }
    }
    return false;
}

function extract_zip_archive(string $zipPath, string $extractRoot): bool
{
    if (class_exists('ZipArchive')) {
        $archive = new ZipArchive();
        if ($archive->open($zipPath) !== true) {
            return false;
        }
        $extracted = $archive->extractTo($extractRoot);
        $archive->close();
        return $extracted;
    }

    if (!function_exists('escapeshellarg') || !function_exists('exec')) {
        return false;
    }
    $command = 'unzip -qq ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($extractRoot);
    exec($command, $output, $code);
    return $code === 0;
}

function installed_native_matches(string $releaseRoot, string $expectedSha256): bool
{
    $native = $releaseRoot . '/bin/stattic-runtime';
    $actualSha256 = is_file($native) ? hash_file('sha256', $native) : false;
    return is_string($actualSha256) && hash_equals($expectedSha256, $actualSha256);
}

// Register every temporary artifact the moment it exists: fail() removes them
// all, or a cron retrying a corrupt artifact fills the disk with archives.
function register_cleanup_path(string $path): void
{
    $GLOBALS['spacefast_cleanup_paths'][] = $path;
}

function unregister_cleanup_path(string $path): void
{
    $GLOBALS['spacefast_cleanup_paths'] = array_values(array_filter(
        $GLOBALS['spacefast_cleanup_paths'] ?? [],
        fn (string $registered): bool => $registered !== $path,
    ));
}

function cleanup_registered_paths(): void
{
    foreach ($GLOBALS['spacefast_cleanup_paths'] ?? [] as $path) {
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    $GLOBALS['spacefast_cleanup_paths'] = [];
}

// Backstop for artifacts a hard-killed process could not clean: only a hard kill
// skips fail()'s cleanup.
function sweep_stale_install_artifacts(string $privateRoot, string $installRoot): void
{
    $cutoff = time() - 3600;
    $staleDirs = array_merge(
        glob($privateRoot . '/runtime-install-*') ?: [],
        glob($installRoot . '/engine-stage-*') ?: [],
        glob($installRoot . '/engine-old-*') ?: [],
        glob($installRoot . '/release-stage-*') ?: [],
    );
    foreach ($staleDirs as $dir) {
        if (is_dir($dir) && !is_link($dir) && (filemtime($dir) ?: 0) < $cutoff) {
            rrmdir($dir);
        }
    }
    $staleFiles = glob($privateRoot . '/incoming/runtime-engine-*.zip') ?: [];
    foreach ($staleFiles as $file) {
        if (is_file($file) && (filemtime($file) ?: 0) < $cutoff) {
            @unlink($file);
        }
    }
}

/** @param list<string> $keep */
function prune_old_releases(string $releasesRoot, array $keep): void
{
    $cutoff = time() - 3600;
    foreach (glob($releasesRoot . '/*') ?: [] as $release) {
        if (
            in_array($release, $keep, true)
            || !is_dir($release)
            || is_link($release)
            || (filemtime($release) ?: 0) >= $cutoff
        ) {
            continue;
        }
        rrmdir($release);
    }
}

function copy_tree(string $source, string $target): bool
{
    if (is_file($source)) {
        return ensure_install_dir(dirname($target))
            && @copy($source, $target)
            && @chmod($target, fileperms($source) & 0777);
    }
    if (!is_dir($source) || !ensure_install_dir($target)) {
        return false;
    }
    foreach (scandir($source) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!copy_tree($source . '/' . $name, $target . '/' . $name)) {
            return false;
        }
    }
    return true;
}

function snapshot_legacy_release(string $installRoot, string $releaseRoot): void
{
    if (!copy_tree($installRoot . '/engine', $releaseRoot . '/engine')) {
        fail('runtime_engine_legacy_snapshot_failed');
    }
    if (is_dir($installRoot . '/bin') && !copy_tree($installRoot . '/bin', $releaseRoot . '/bin')) {
        fail('runtime_engine_legacy_snapshot_failed');
    }
}

/** @param list<array{relative:string}> $staged */
function prune_unreferenced_legacy_release(string $installRoot, array $staged): void
{
    $names = [];
    foreach ($staged as $entry) {
        $names[explode('/', $entry['relative'])[0]] = true;
    }
    foreach (array_keys($names) as $name) {
        $path = $installRoot . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
}

/**
 * One converge at a time per site; overlapping runs exit quietly.
 *
 * @return resource
 */
function acquire_installer_lock(string $privateRoot)
{
    if (!is_dir($privateRoot)) {
        mkdir($privateRoot, 0755, true);
    }
    $handle = fopen($privateRoot . '/installer.lock', 'c');
    if ($handle === false) {
        fail('runtime_installer_lock_unavailable');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        echo json_encode(['status' => 'busy'], JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    return $handle;
}

function installer_roots(): array
{
    $scriptDir = __DIR__;
    if (basename($scriptDir) === '__spacefast') {
        $publicRoot = dirname($scriptDir);
        return [$publicRoot . '/.stattic', $publicRoot];
    }
    return [$scriptDir, dirname($scriptDir)];
}

function validate_expected_md5(string $zipPath, string $expectedMd5): void
{
    if (!preg_match('/^[a-fA-F0-9]{32}$/', $expectedMd5)) {
        fail('runtime_engine_md5_invalid');
    }
    $actual = md5_file($zipPath);
    if (!is_string($actual) || !hash_equals(strtolower($expectedMd5), strtolower($actual))) {
        fail('runtime_engine_md5_mismatch');
    }
}

/**
 * `--clean` prunes what the current manifest no longer declares from the alias
 * directory. The resident copy of this program is never pruned even though
 * no manifest alias declares it: deleting engine-update.php severs the box's
 * only self-update path.
 *
 * @param list<array{path:string}> $alias
 */
function prune_alias_dir(string $publicRoot, array $alias): void
{
    $keep = ['engine-update.php' => true];
    foreach ($alias as $entry) {
        if (str_starts_with($entry['path'], '__spacefast/')) {
            $keep[substr($entry['path'], strlen('__spacefast/'))] = true;
        }
    }
    $aliasDir = $publicRoot . '/__spacefast';
    if (!is_dir($aliasDir)) {
        return;
    }
    foreach (scandir($aliasDir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || isset($keep[$name])) {
            continue;
        }
        $path = $aliasDir . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
}

// opcache_invalidate() returns false both when a file could not be invalidated
// and when the extension is simply not active in this process — and it is not
// active in a CLI run with opcache.enable_cli=0. Separating the two is what
// makes D56's "report failure" a real signal instead of a permanent alarm.
function opcache_active(): bool
{
    if (!function_exists('opcache_invalidate') || !function_exists('opcache_get_status')) {
        return false;
    }
    $status = @opcache_get_status(false);
    return is_array($status) && ($status['opcache_enabled'] ?? false) === true;
}

$args = array_slice($argv, 1);
$cleanInstall = false;
foreach ($args as $index => $arg) {
    if ($arg === '--clean') {
        $cleanInstall = true;
        unset($args[$index]);
    }
}
$args = array_values($args);
[$privateRoot, $publicRoot] = installer_roots();
$installRoot = $publicRoot . '/.stattic';
$installerLock = acquire_installer_lock($privateRoot);
sweep_stale_install_artifacts($privateRoot, $installRoot);
$incomingRoot = $privateRoot . '/incoming';
if (!is_dir($incomingRoot)) {
    mkdir($incomingRoot, 0700, true);
}

$zipSource = is_string($args[0] ?? null) && $args[0] !== ''
    ? $args[0]
    : installer_config_value('SPACEFAST_RUNTIME_ENGINE_ZIP_URL');
if ($zipSource === '') {
    fail('runtime_engine_zip_source_missing');
}
// Remote bytes travel over TLS only (loopback excepted, for dev and tests);
// the checksums arrive out of band through the caller (management JWT or the
// SSH session), so https plus md5 is the integrity story.
if (is_remote_zip_source($zipSource) && !str_starts_with($zipSource, 'https://')) {
    $zipHost = parse_url($zipSource, PHP_URL_HOST);
    if (!in_array($zipHost, ['127.0.0.1', 'localhost', '::1'], true)) {
        fail('runtime_engine_zip_url_insecure');
    }
}
$expectedMd5 = installer_config_value('SPACEFAST_RUNTIME_ENGINE_MD5');
if ($expectedMd5 === '') {
    fail('runtime_engine_md5_missing');
}
$expectedRevision = installer_config_value('SPACEFAST_RUNTIME_ENGINE_REVISION');
if ($expectedRevision === '') {
    fail('runtime_engine_revision_expected_missing');
}
$expectedNativeSha256 = strtolower(installer_config_value('SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256'));

// Converge short-circuit: only the pointer-based layout can converge without a
// reinstall. A legacy real `.stattic/engine` must take the migration path even
// when its bytes already match, and so must a box whose loader marker predates
// the content identity — its loader may be older than the revision it reports.
$installedReleaseRoot = active_release_root($installRoot);
$installedReleaseTarget = active_release_pointer_target($installRoot);
$installedLoaderIdentity = installed_loader_identity($installRoot);
if (
    $installedLoaderIdentity !== null
    && $expectedNativeSha256 !== ''
    && $installedReleaseTarget !== null
    && is_string($installedReleaseRoot)
    && read_engine_revision($installedReleaseRoot) === $expectedRevision
    && installed_native_matches($installedReleaseRoot, $expectedNativeSha256)
) {
    echo json_encode(['status' => 'converged', 'engine_revision' => $expectedRevision, 'layout' => 'release'], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
$suffix = getmypid() . '-' . bin2hex(random_bytes(4));
$extractRoot = $privateRoot . '/runtime-install-' . $suffix;
$stageRoot = $installRoot . '/release-stage-' . $suffix;
$downloadedZip = is_remote_zip_source($zipSource);
$zipPath = $downloadedZip
    ? $incomingRoot . '/runtime-engine-' . $suffix . '.zip'
    : $zipSource;

rrmdir($extractRoot);
mkdir($extractRoot, 0700, true);
register_cleanup_path($extractRoot);
if ($downloadedZip) {
    register_cleanup_path($zipPath);
}
download_zip($zipSource, $zipPath);
if (!is_file($zipPath)) {
    fail('runtime_zip_missing:' . $zipPath);
}
validate_expected_md5($zipPath, $expectedMd5);
if (!extract_zip_archive($zipPath, $extractRoot)) {
    fail('runtime_zip_unzip_failed');
}

$manifest = read_engine_manifest($extractRoot);
// The loader is reinstalled exactly when the bytes it would be installed from
// differ from the bytes installed last time, so a loader fix reaches a box that
// converged years ago and an unchanged loader never rewrites a live file.
$loaderIdentity = loader_payload_identity($extractRoot, $manifest['alias']);
$installAliases = $installedLoaderIdentity !== $loaderIdentity;
$legacyReleasePresent = is_dir($installRoot . '/engine') && !is_link($installRoot . '/engine');

// Everything up to pointer publication is disposable: the active release keeps
// serving throughout staging and validation.
rrmdir($stageRoot);
register_cleanup_path($stageRoot);
build_engine_stage($extractRoot, $stageRoot, $manifest['staged']);
validate_engine_stage($stageRoot, $manifest['staged']);
$actualRevision = read_engine_revision($stageRoot, strict: true);
if ($actualRevision !== $expectedRevision) {
    fail('runtime_engine_revision_mismatch');
}

ensure_runtime_storage_dir($installRoot . '/storage');
ensure_runtime_storage_dir($installRoot . '/storage/runtime');
ensure_runtime_storage_dir($installRoot . '/storage/spaces');

$releasesRoot = $installRoot . '/releases';
if (!ensure_install_dir($releasesRoot)) {
    fail('runtime_engine_releases_unavailable');
}
$releaseRoot = $releasesRoot . '/release-' . $suffix;
if (!@rename($stageRoot, $releaseRoot)) {
    fail('runtime_engine_release_commit_failed');
}
register_cleanup_path($releaseRoot);

// One-time migration: snapshot the still-live legacy tree, publish that
// immutable snapshot, then replace the public aliases. Old aliases keep using
// the untouched legacy tree; new aliases use the byte-identical snapshot.
if (active_release_pointer_target($installRoot) === null && $legacyReleasePresent) {
    $legacyRoot = $releasesRoot . '/legacy-' . $suffix;
    register_cleanup_path($legacyRoot);
    snapshot_legacy_release($installRoot, $legacyRoot);
    $legacyTarget = 'releases/' . basename($legacyRoot);
    if (!publish_active_release($installRoot, $legacyTarget)) {
        fail('runtime_engine_legacy_pointer_failed');
    }
    unregister_cleanup_path($legacyRoot);
}
$previousReleaseTarget = active_release_pointer_target($installRoot);
$previousReleaseRoot = active_release_root($installRoot);

if ($installAliases) {
    foreach ($manifest['alias'] as $entry) {
        $source = $releaseRoot . '/' . $entry['source'];
        $target = $publicRoot . '/' . $entry['path'];
        if (!ensure_install_dir(dirname($target))) {
            fail('runtime_engine_alias_mkdir_failed:' . $entry['path']);
        }
        $tmp = $target . '.tmp.' . getmypid();
        if (!@copy($source, $tmp) || !@chmod($tmp, $entry['executable'] ? 0755 : 0644)) {
            @unlink($tmp);
            fail('runtime_engine_alias_copy_failed:' . $entry['path']);
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            fail('runtime_engine_alias_install_failed:' . $entry['path']);
        }
    }
}

$installedPhp = [];
foreach ($manifest['staged'] as $entry) {
    if (str_ends_with($entry['relative'], '.php')) {
        $installedPhp[] = $releaseRoot . '/' . $entry['relative'];
    }
}
if ($installAliases) {
    foreach ($manifest['alias'] as $entry) {
        if (str_ends_with($entry['path'], '.php')) {
            $installedPhp[] = $publicRoot . '/' . $entry['path'];
        }
    }
}
$opcacheActive = opcache_active();
$staleOpcacheEntries = [];
if ($opcacheActive) {
    foreach ($installedPhp as $path) {
        if (!@opcache_invalidate($path, true)) {
            $staleOpcacheEntries[] = $path;
        }
    }
}

$fileCount = count($manifest['staged']) + count($manifest['alias']);
$receipt = [
    'file_count' => $fileCount,
    'clean' => $cleanInstall,
    'engine_revision' => $actualRevision,
    'layout' => 'release',
    'loader' => $installAliases ? 'installed' : 'current',
    'opcache' => $opcacheActive ? 'invalidated' : 'inactive',
];

// Invalidate before publication. If it fails, the pointer still selects the
// complete previous release.
if ($staleOpcacheEntries !== []) {
    $receipt['opcache'] = 'stale';
    $receipt['opcache_stale_count'] = count($staleOpcacheEntries);
    $receipt['rolled_back'] = $previousReleaseTarget !== null;
    echo json_encode(['status' => 'failed', 'reason' => 'opcache_invalidation_failed'] + $receipt, JSON_PRETTY_PRINT) . "\n";
    fail('opcache_invalidation_failed');
}

if ($installAliases) {
    $loaderMarker = $installRoot . '/loader-version';
    $temporaryMarker = $loaderMarker . '.tmp.' . getmypid();
    if (
        @file_put_contents($temporaryMarker, $loaderIdentity . "\n", LOCK_EX) === false
        || !@chmod($temporaryMarker, 0644)
        || !@rename($temporaryMarker, $loaderMarker)
    ) {
        @unlink($temporaryMarker);
        fail('runtime_engine_loader_marker_failed');
    }
}

if ($cleanInstall) {
    prune_alias_dir($publicRoot, $manifest['alias']);
}

$newReleaseTarget = 'releases/' . basename($releaseRoot);
if (
    installer_config_value('SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE') === 'pointer_publication'
    || !publish_active_release($installRoot, $newReleaseTarget)
) {
    fail('runtime_engine_pointer_publication_failed');
}

$publishedReleaseRoot = active_release_root($installRoot);
if (
    $publishedReleaseRoot !== realpath($releaseRoot)
    || read_engine_revision($publishedReleaseRoot ?? '') !== $actualRevision
    || ($expectedNativeSha256 !== '' && !installed_native_matches($publishedReleaseRoot ?? '', $expectedNativeSha256))
) {
    $rolledBack = $previousReleaseTarget !== null
        ? publish_active_release($installRoot, $previousReleaseTarget)
        : remove_active_release_pointer($installRoot);
    if (!$rolledBack) {
        unregister_cleanup_path($releaseRoot);
    }
    echo json_encode([
        'status' => 'failed',
        'reason' => 'runtime_engine_post_publication_check_failed',
        'engine_revision' => $actualRevision,
        'rolled_back' => $rolledBack,
    ], JSON_PRETTY_PRINT) . "\n";
    fail('runtime_engine_post_publication_check_failed');
}

// Refresh the resident installer only after the release has passed its
// publication check. It is not part of the serving transaction.
$stagedInstaller = $releaseRoot . '/installer.php';
if (is_file($stagedInstaller) && ensure_install_dir($publicRoot . '/__spacefast')) {
    $resident = $publicRoot . '/__spacefast/engine-update.php';
    $tmp = $resident . '.tmp.' . getmypid();
    if (@copy($stagedInstaller, $tmp) && @chmod($tmp, 0644)) {
        @rename($tmp, $resident);
    } else {
        @unlink($tmp);
    }
}

unregister_cleanup_path($releaseRoot);
if ($cleanInstall && !$legacyReleasePresent) {
    prune_unreferenced_legacy_release($installRoot, $manifest['staged']);
}
prune_old_releases($releasesRoot, array_values(array_filter([
    realpath($releaseRoot) ?: null,
    $previousReleaseRoot,
], 'is_string')));
cleanup_registered_paths();

echo json_encode(['status' => 'installed'] + $receipt, JSON_PRETTY_PRINT) . "\n";
