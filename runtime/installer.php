<?php
declare(strict_types=1);

// Standalone CLI entrypoint: engine context.php is not loaded. Errors go to
// stderr so they cannot corrupt the JSON receipt on stdout.
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
    fwrite(STDERR, 'Spacefast runtime installer requires PHP 8.5; running PHP ' . PHP_VERSION . "\n");
    exit(1);
}

// CLI only, run by the SSH bootstrap and the /engine/update route. This guard
// fails closed under a provider that direct-executes arbitrary PHP files. The
// zip source rides argv[1] (https URL or local path); SPACEFAST_RUNTIME_ENGINE_MD5,
// _REVISION and optional _NATIVE_SHA256 ride the environment. The JSON receipt
// on stdout is the whole report. There is no callback.
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

// Idempotent. Absence is a no-op; a path that exists but cannot be removed
// still reaches PHP's error log.
function unlink_if_present(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return false;
    }
    return unlink($path);
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
            unlink_if_present($child);
        }
    }
    rmdir($path);
}

function ensure_runtime_storage_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    chmod($path, 0775);
}

// Modes are asserted, not inherited: a provider umask of 077 would leave the
// engine tree unreadable to php-fpm.
function ensure_install_dir(string $path): bool
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        return false;
    }
    chmod($path, 0755);
    return true;
}

function unsafe_relative_path(string $p): bool
{
    return $p === '' || str_starts_with($p, '/') || str_contains($p, '..') || str_contains($p, '\\');
}

// Reserved for site state, the active pointer, releases, and installer
// scratch. A payload may not claim any of them.
function reserved_install_root_name(string $name): bool
{
    return in_array($name, ['storage', 'incoming', 'installer.lock', 'active-release', 'loader-version', 'releases', '', '.', '..'], true)
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
    $process = proc_open([$binary, '--self-test'], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
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
            proc_terminate($process, 9);
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
            proc_terminate($process, 9);
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
        if (!copy($source, $target)) {
            fail('runtime_engine_stage_copy_failed:' . $entry['relative']);
        }
        // Fatal on purpose: the live tree is untouched here, and a
        // non-executable binary would surface later as a serving failure.
        if (!chmod($target, $entry['executable'] ? 0755 : 0644)) {
            fail('runtime_engine_stage_chmod_failed:' . $entry['relative']);
        }
    }
}

/**
 * The self-test runs the STAGED binary, not the extracted one: the staged tree
 * is what goes live, modes and all.
 *
 * @param list<array{relative:string}> $staged
 */
function validate_engine_stage(string $stageRoot, array $staged): void
{
    $stagedFiles = [];
    foreach ($staged as $entry) {
        $stagedFiles[$entry['relative']] = true;
    }

    $relative = 'bin/stattic-runtime';
    if (!isset($stagedFiles[$relative])) {
        fail('runtime_native_manifest_missing:' . $relative);
    }
    $result = run_native_self_test($stageRoot . '/' . $relative);
    $probe = json_decode($result['stdout'], true);
    if ($result['failed'] || $result['code'] !== 0 || !is_array($probe) || ($probe['format'] ?? null) !== 'stattic.runtime.self-test.v1') {
        fail('runtime_native_self_test_failed:' . $relative);
    }
}

function active_release_pointer_target(string $installRoot): ?string
{
    $pointer = $installRoot . '/active-release';
    if (!is_file($pointer) || is_link($pointer)) {
        return null;
    }
    clearstatcache(true, $pointer);
    $raw = file_get_contents($pointer, false, null, 0, 256);
    if (!is_string($raw)) {
        return null;
    }
    $target = trim($raw);
    return preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) === 1 ? $target : null;
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
    unlink_if_present($temporary);
    if (
        file_put_contents($temporary, $target . "\n", LOCK_EX) === false
        || !chmod($temporary, 0644)
    ) {
        unlink_if_present($temporary);
        return false;
    }
    if (!rename($temporary, $pointer)) {
        unlink_if_present($temporary);
        return false;
    }
    clearstatcache(true, $pointer);
    return active_release_pointer_target($installRoot) === $target;
}

function remove_active_release_pointer(string $installRoot): bool
{
    return unlink_if_present($installRoot . '/active-release');
}

/**
 * Identity of the public loader this payload would install: sha256 over every
 * alias, ordered by served path (read_engine_manifest sorts them), as
 * `<served path>\0<byte length>\0<bytes>`. Deriving it from the payload rather
 * than a version number keeps it hermetic. The same zip always answers the same
 * identity, and any change to the loader's bytes, served path, or alias set
 * changes it.
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

// The marker holds the identity of the installed loader. A box that installed
// before the marker became content-derived carries a literal that fails this
// regex, so it reads as no identity and its loader is reinstalled once.
function installed_loader_identity(string $installRoot): ?string
{
    $marker = $installRoot . '/loader-version';
    $raw = is_file($marker) ? file_get_contents($marker, false, null, 0, 128) : false;
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

// The regex is the drift guard against build-runtime-engine-zip's emitted line.
// Revisions are deterministic (git commit hash, or dev-<sourcehash>), so
// comparison is string equality. Strict is for a freshly staged payload, where
// a missing constant is fatal; lenient answers what is installed right now.
function read_engine_revision(string $root, bool $strict = false): ?string
{
    $path = $root . '/engine/shared/context.php';
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
        $output = fopen($target, 'wb');
        $curl = curl_init($url);
        if (!is_resource($output) || $curl === false) {
            if (is_resource($output)) {
                fclose($output);
            }
            unlink_if_present($target);
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
        unlink_if_present($target);
        if ($attempt < 3) {
            sleep(2);
        }
    }
    return false;
}

// ext-zip is part of the runtime's PHP contract. No shell fallback.
function extract_zip_archive(string $zipPath, string $extractRoot): bool
{
    if (!class_exists('ZipArchive')) {
        fail('runtime_zip_extension_unavailable');
    }
    $archive = new ZipArchive();
    if ($archive->open($zipPath) !== true) {
        return false;
    }
    $extracted = $archive->extractTo($extractRoot);
    $archive->close();
    return $extracted;
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
            unlink_if_present($path);
        }
    }
    $GLOBALS['spacefast_cleanup_paths'] = [];
}

// Backstop for artifacts a hard-killed process left behind; only a hard kill
// skips fail()'s cleanup.
function sweep_stale_install_artifacts(string $privateRoot, string $installRoot): void
{
    $cutoff = time() - 3600;
    $staleDirs = array_merge(
        glob($privateRoot . '/runtime-install-*') ?: [],
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
            unlink_if_present($file);
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

/**
 * One update at a time per site; overlapping runs exit quietly.
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

[$privateRoot, $publicRoot] = installer_roots();
$installRoot = $publicRoot . '/.stattic';
$installerLock = acquire_installer_lock($privateRoot);
sweep_stale_install_artifacts($privateRoot, $installRoot);
$incomingRoot = $privateRoot . '/incoming';
if (!is_dir($incomingRoot)) {
    mkdir($incomingRoot, 0700, true);
}

$zipSource = is_string($argv[1] ?? null) && $argv[1] !== ''
    ? $argv[1]
    : installer_config_value('SPACEFAST_RUNTIME_ENGINE_ZIP_URL');
if ($zipSource === '') {
    fail('runtime_engine_zip_source_missing');
}
// Remote bytes travel over TLS only, loopback excepted for dev and tests. The
// checksums arrive out of band from the caller (management JWT or SSH session),
// so https plus md5 is the integrity story.
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

// Sync short-circuit. A box whose loader marker predates the content
// identity must reinstall: its loader may be older than the revision it
// reports.
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
    echo json_encode(['status' => 'current', 'engine_revision' => $expectedRevision, 'layout' => 'release'], JSON_PRETTY_PRINT) . "\n";
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
// Reinstall the loader exactly when its bytes differ from the ones installed
// last time: a loader fix reaches a box that was already current, and an unchanged loader
// never rewrites a live file.
$loaderIdentity = loader_payload_identity($extractRoot, $manifest['alias']);
$installAliases = $installedLoaderIdentity !== $loaderIdentity;

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
if (!rename($stageRoot, $releaseRoot)) {
    fail('runtime_engine_release_commit_failed');
}
register_cleanup_path($releaseRoot);

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
        if (!copy($source, $tmp) || !chmod($tmp, $entry['executable'] ? 0755 : 0644)) {
            unlink_if_present($tmp);
            fail('runtime_engine_alias_copy_failed:' . $entry['path']);
        }
        if (!rename($tmp, $target)) {
            unlink_if_present($tmp);
            fail('runtime_engine_alias_install_failed:' . $entry['path']);
        }
    }
}

// No opcache work here, deliberately. CLI opcache is a different SHM from
// FPM's, so invalidating it changes nothing a visitor sees (and wp.cloud has
// opcache.enable_cli off). FPM loads each release from
// a fresh path opcache has never seen, and the rewritten-in-place alias files
// are dropped from FPM's SHM by _stattic_engine_update_invalidate_aliases in
// the engine-update receipt route.
$fileCount = count($manifest['staged']) + count($manifest['alias']);
$receipt = [
    'file_count' => $fileCount,
    'engine_revision' => $actualRevision,
    'layout' => 'release',
    'loader' => $installAliases ? 'installed' : 'current',
];

if ($installAliases) {
    $loaderMarker = $installRoot . '/loader-version';
    $temporaryMarker = $loaderMarker . '.tmp.' . getmypid();
    if (
        file_put_contents($temporaryMarker, $loaderIdentity . "\n", LOCK_EX) === false
        || !chmod($temporaryMarker, 0644)
        || !rename($temporaryMarker, $loaderMarker)
    ) {
        unlink_if_present($temporaryMarker);
        fail('runtime_engine_loader_marker_failed');
    }
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
    if (copy($stagedInstaller, $tmp) && chmod($tmp, 0644)) {
        rename($tmp, $resident);
    } else {
        unlink_if_present($tmp);
    }
}

unregister_cleanup_path($releaseRoot);
prune_old_releases($releasesRoot, array_values(array_filter([
    realpath($releaseRoot) ?: null,
    $previousReleaseRoot,
], 'is_string')));
cleanup_registered_paths();

echo json_encode(['status' => 'installed'] + $receipt, JSON_PRETTY_PRINT) . "\n";
