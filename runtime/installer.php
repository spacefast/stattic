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

// Names inside .stattic/ the swap must never park or replace: site state that
// outlives any engine revision, plus the installer's own scratch space.
function reserved_install_root_name(string $name): bool
{
    return in_array($name, ['storage', 'incoming', 'installer.lock', '', '.', '..'], true)
        || str_starts_with($name, 'engine-stage-')
        || str_starts_with($name, 'engine-old-')
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
            // An alias inside .stattic/ would be clobbered by the swap it lands
            // after.
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

/**
 * Top-level names of the staged tree, ordered so `engine` flips LAST: when the
 * engine modules go live, everything they call is already the new revision.
 *
 * @param list<array{relative:string}> $staged
 * @return list<string>
 */
function engine_swap_names(array $staged): array
{
    $names = [];
    foreach ($staged as $entry) {
        $names[explode('/', $entry['relative'])[0]] = true;
    }
    $ordered = array_keys($names);
    sort($ordered);
    usort($ordered, fn (string $left, string $right): int => ($left === 'engine' ? 1 : 0) <=> ($right === 'engine' ? 1 : 0));
    return $ordered;
}

/** @param list<array{name:string,parked:bool}> $done */
function rollback_engine_swap(string $installRoot, string $stageRoot, string $oldRoot, array $done): void
{
    foreach (array_reverse($done) as $step) {
        $live = $installRoot . '/' . $step['name'];
        @rename($live, $stageRoot . '/' . $step['name']);
        if ($step['parked']) {
            @rename($oldRoot . '/' . $step['name'], $live);
        }
    }
}

/**
 * Every step is a rename within `.stattic/`, so each top-level name is either
 * wholly the old revision or wholly the new one. A failed rename rolls the
 * completed steps back.
 *
 * @param list<string> $names
 */
function swap_engine_tree(string $installRoot, string $stageRoot, string $oldRoot, array $names): void
{
    if (!ensure_install_dir($oldRoot)) {
        fail('runtime_engine_swap_park_unavailable');
    }
    $done = [];
    foreach ($names as $name) {
        $live = $installRoot . '/' . $name;
        $staged = $stageRoot . '/' . $name;
        $parked = $oldRoot . '/' . $name;
        $hadLive = file_exists($live) || is_link($live);
        // Silenced because the boolean is the verdict: a stray warning on stdout
        // would corrupt the JSON receipt the caller parses.
        if ($hadLive && !@rename($live, $parked)) {
            rollback_engine_swap($installRoot, $stageRoot, $oldRoot, $done);
            fail('runtime_engine_swap_park_failed:' . $name);
        }
        if (!@rename($staged, $live)) {
            if ($hadLive) {
                @rename($parked, $live);
            }
            rollback_engine_swap($installRoot, $stageRoot, $oldRoot, $done);
            fail('runtime_engine_swap_failed:' . $name);
        }
        $done[] = ['name' => $name, 'parked' => $hadLive];
    }
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

function installed_native_matches(string $installRoot, string $expectedSha256): bool
{
    $native = $installRoot . '/bin/stattic-runtime';
    $actualSha256 = is_file($native) ? hash_file('sha256', $native) : false;
    return is_string($actualSha256) && hash_equals($expectedSha256, $actualSha256);
}

// Register every temporary artifact the moment it exists: fail() removes them
// all, or a cron retrying a corrupt artifact fills the disk with archives.
function register_cleanup_path(string $path): void
{
    $GLOBALS['spacefast_cleanup_paths'][] = $path;
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
 * directory (the `.stattic/` tree is replaced wholesale by the swap, so it needs
 * no cleaning). The resident copy of this program is never pruned even though
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

// Converge short-circuit: only a caller that states the native digest can prove
// the box is already there — a matching revision string alone does not vouch
// for the binary.
if (
    $expectedNativeSha256 !== ''
    && read_engine_revision($installRoot) === $expectedRevision
    && installed_native_matches($installRoot, $expectedNativeSha256)
) {
    echo json_encode(['status' => 'converged', 'engine_revision' => $expectedRevision], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
$suffix = getmypid() . '-' . bin2hex(random_bytes(4));
$extractRoot = $privateRoot . '/runtime-install-' . $suffix;
$stageRoot = $installRoot . '/engine-stage-' . $suffix;
$oldRoot = $installRoot . '/engine-old-' . $suffix;
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

// Everything up to the swap is disposable: the live tree keeps serving the
// revision it already has.
rrmdir($stageRoot);
register_cleanup_path($stageRoot);
register_cleanup_path($oldRoot);
build_engine_stage($extractRoot, $stageRoot, $manifest['staged']);
validate_engine_stage($stageRoot, $manifest['staged']);
$actualRevision = read_engine_revision($stageRoot, strict: true);
if ($actualRevision !== $expectedRevision) {
    fail('runtime_engine_revision_mismatch');
}

ensure_runtime_storage_dir($installRoot . '/storage');
ensure_runtime_storage_dir($installRoot . '/storage/runtime');
ensure_runtime_storage_dir($installRoot . '/storage/spaces');

swap_engine_tree($installRoot, $stageRoot, $oldRoot, engine_swap_names($manifest['staged']));
rrmdir($stageRoot);
rrmdir($oldRoot);

if ($cleanInstall) {
    prune_alias_dir($publicRoot, $manifest['alias']);
}

foreach ($manifest['alias'] as $entry) {
    $source = $installRoot . '/' . $entry['source'];
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

// The resident copy of this program refreshes itself from the payload it just
// installed, so the updater never needs a second SSH delivery. Best-effort: the
// engine is live either way, and a stale resident is the status quo ante.
$stagedInstaller = $installRoot . '/installer.php';
if (is_file($stagedInstaller) && ensure_install_dir($publicRoot . '/__spacefast')) {
    $resident = $publicRoot . '/__spacefast/engine-update.php';
    $tmp = $resident . '.tmp.' . getmypid();
    if (@copy($stagedInstaller, $tmp) && @chmod($tmp, 0644)) {
        @rename($tmp, $resident);
    } else {
        @unlink($tmp);
    }
}

$installedPhp = [];
foreach ($manifest['staged'] as $entry) {
    if (str_ends_with($entry['relative'], '.php')) {
        $installedPhp[] = $installRoot . '/' . $entry['relative'];
    }
}
foreach ($manifest['alias'] as $entry) {
    if (str_ends_with($entry['path'], '.php')) {
        $installedPhp[] = $publicRoot . '/' . $entry['path'];
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

cleanup_registered_paths();

$fileCount = count($manifest['staged']) + count($manifest['alias']);
$receipt = [
    'file_count' => $fileCount,
    'clean' => $cleanInstall,
    'engine_revision' => $actualRevision,
    'opcache' => $opcacheActive ? 'invalidated' : 'inactive',
];

// The bytes are live and correct either way — the swap stands. But an
// invalidation the runtime asked for and did not get means php-fpm may keep
// serving the previous revision's modules: a failed converge, not a quiet
// success.
if ($staleOpcacheEntries !== []) {
    $receipt['opcache'] = 'stale';
    $receipt['opcache_stale_count'] = count($staleOpcacheEntries);
    echo json_encode(['status' => 'failed', 'reason' => 'opcache_invalidation_failed'] + $receipt, JSON_PRETTY_PRINT) . "\n";
    fail('opcache_invalidation_failed');
}

echo json_encode(['status' => 'installed'] + $receipt, JSON_PRETTY_PRINT) . "\n";
