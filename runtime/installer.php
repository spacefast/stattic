<?php
declare(strict_types=1);

function fail(string $message, int $status = 500): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => $message]], JSON_PRETTY_PRINT) . "\n";
    } else {
        fwrite(STDERR, $message . "\n");
    }
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

function unsafe_relative_path(string $p): bool
{
    return $p === '' || str_starts_with($p, '/') || str_contains($p, '..') || str_contains($p, '\\');
}

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
    $entries = array_map(fn (string $file): array => [
        'source' => $file,
        'path' => '.stattic/' . $file,
        'executable' => isset($executables[$file]),
    ], $sourceFiles);
    foreach (($decoded['aliases'] ?? []) as $alias) {
        if (
            !is_array($alias)
            || !is_string($alias['source'] ?? null)
            || !is_string($alias['path'] ?? null)
            || !in_array($alias['source'], $sourceFiles, true)
            || unsafe_relative_path($alias['path'])
        ) {
            fail('runtime_engine_manifest_invalid');
        }
        $entries[] = [
            'source' => $alias['source'],
            'path' => $alias['path'],
            'executable' => isset($executables[$alias['source']]),
        ];
    }
    $installedFiles = array_map(fn (array $entry): string => $entry['path'], $entries);
    if (count(array_unique($installedFiles)) !== count($installedFiles)) {
        fail('runtime_engine_manifest_invalid');
    }
    usort($entries, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    return $entries;
}

/**
 * Run a packaged native self-test without a shell, with separate bounded
 * output streams and a hard deadline.
 *
 * @return array{code:int,stdout:string,stderr:string,failed:bool}
 */
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

function validate_engine_payload(string $root, array $entries): void
{
    $sourceFiles = [];
    foreach ($entries as $entry) {
        $sourceFiles[$entry['source']] = true;
    }
    foreach (array_keys($sourceFiles) as $source) {
        if (!is_file($root . '/' . $source)) {
            fail('runtime_zip_file_missing:' . $source);
        }
    }

    $nativeProbes = [
        'bin/stattic-runtime-compiler' => 'stattic.runtime.compiler.self-test.v1',
        'bin/stattic-zero-runner' => 'stattic.zero.runner.self-test.v1',
    ];
    foreach ($nativeProbes as $source => $expectedFormat) {
        if (!isset($sourceFiles[$source])) {
            fail('runtime_native_manifest_missing:' . $source);
        }
        $binary = $root . '/' . $source;
        @chmod($binary, 0755);
        $result = run_native_self_test($binary);
        $probe = json_decode($result['stdout'], true);
        if ($result['failed'] || $result['code'] !== 0 || !is_array($probe) || ($probe['format'] ?? null) !== $expectedFormat) {
            fail('runtime_native_self_test_failed:' . $source);
        }
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
    if (!function_exists('escapeshellarg')) {
        fail('escapeshellarg_unavailable');
    }

    $downloaded = false;
    if (is_executable('/usr/bin/curl') || is_executable('/bin/curl')) {
        $command = 'curl -fsSL --retry 3 --connect-timeout 10 --max-time 120 -o ' .
            escapeshellarg($target) . ' ' . escapeshellarg($source);
        exec($command, $output, $code);
        $downloaded = $code === 0;
    }
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

    if (class_exists('Atomic_Persistent_Data')) {
        try {
            $persistent = new Atomic_Persistent_Data();
            if (isset($persistent->{$envName}) && is_string($persistent->{$envName}) && trim($persistent->{$envName}) !== '') {
                return trim($persistent->{$envName});
            }
        } catch (Throwable) {
            // Fall through to JSON fallback below.
        }
    }

    $atomicJson = getenv('SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON');
    $decoded = is_string($atomicJson) && $atomicJson !== '' ? json_decode($atomicJson, true) : null;
    if (is_array($decoded)) {
        $value = $decoded[$envName] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
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

function authorize_http_installer(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $expected = installer_config_value('SPACEFAST_RUNTIME_ENGINE_INSTALL_TOKEN');
    $actual = isset($_GET['token']) && is_string($_GET['token'])
        ? trim($_GET['token'])
        : (isset($_SERVER['HTTP_X_SPACEFAST_INSTALL_TOKEN']) && is_string($_SERVER['HTTP_X_SPACEFAST_INSTALL_TOKEN'])
            ? trim($_SERVER['HTTP_X_SPACEFAST_INSTALL_TOKEN'])
            : '');
    if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
        fail('runtime_engine_installer_unauthorized', 403);
    }
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

function read_engine_revision(string $root): string
{
    $path = $root . '/engine/shared/context.php';
    $raw = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($raw)) {
        fail('runtime_engine_context_missing');
    }
    if (preg_match("/const SPACEFAST_RUNTIME_ENGINE_REVISION = '([^']+)';/", $raw, $match) !== 1) {
        fail('runtime_engine_revision_missing');
    }
    return $match[1];
}

function clean_engine_install_root(string $publicRoot): void
{
    rrmdir($publicRoot . '/.stattic/bin');
    rrmdir($publicRoot . '/.stattic/engine');
    @unlink($publicRoot . '/.stattic/engine-manifest.json');
    rrmdir($publicRoot . '/__spacefast');
}

function quarantine_legacy_public_index(string $publicRoot, string $incomingRoot): ?string
{
    $source = $publicRoot . '/index.html';
    if (!is_file($source) || is_link($source)) {
        return null;
    }

    $digest = hash_file('sha256', $source);
    if (!is_string($digest) || $digest === '') {
        fail('runtime_legacy_public_index_hash_failed');
    }

    $target = $incomingRoot . '/legacy-public-index-' . $digest . '.html';
    if (is_link($target)) {
        fail('runtime_legacy_public_index_quarantine_collision');
    }
    if (is_file($target)) {
        $targetDigest = hash_file('sha256', $target);
        if (!is_string($targetDigest) || !hash_equals($digest, $targetDigest)) {
            fail('runtime_legacy_public_index_quarantine_collision');
        }
        if (!@unlink($source) && is_file($source)) {
            fail('runtime_legacy_public_index_remove_failed');
        }
    } elseif (!@rename($source, $target)) {
        fail('runtime_legacy_public_index_quarantine_failed');
    }

    return basename($target);
}

authorize_http_installer();

$args = PHP_SAPI === 'cli' ? array_slice($argv, 1) : [];
$cleanInstall = false;
foreach ($args as $index => $arg) {
    if ($arg === '--clean') {
        $cleanInstall = true;
        unset($args[$index]);
    }
}
$args = array_values($args);
$zipSource = $args[0] ?? installer_config_value('SPACEFAST_RUNTIME_ENGINE_DOWNLOAD_URL');
if (!is_string($zipSource) || $zipSource === '') {
    fail('missing_runtime_zip_source');
}
$expectedMd5 = installer_config_value('SPACEFAST_RUNTIME_ENGINE_MD5');
if ($expectedMd5 === '') {
    fail('runtime_engine_md5_missing');
}
$expectedRevision = installer_config_value('SPACEFAST_RUNTIME_ENGINE_REVISION');
if ($expectedRevision === '') {
    fail('runtime_engine_revision_expected_missing');
}
if (PHP_SAPI !== 'cli') {
    $cleanInstall = isset($_GET['clean']) && is_string($_GET['clean']) && $_GET['clean'] === '1';
}

[$privateRoot, $publicRoot] = installer_roots();
$incomingRoot = $privateRoot . '/incoming';
$extractRoot = $privateRoot . '/runtime-install-' . getmypid() . '-' . bin2hex(random_bytes(4));
$downloadedZip = is_remote_zip_source($zipSource);
$zipPath = $downloadedZip
    ? $incomingRoot . '/runtime-engine-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.zip'
    : $zipSource;

rrmdir($extractRoot);
mkdir($extractRoot, 0700, true);
if (!is_dir($incomingRoot)) {
    mkdir($incomingRoot, 0700, true);
}
download_zip($zipSource, $zipPath);
if (!is_file($zipPath)) {
    fail('runtime_zip_missing:' . $zipPath);
}
validate_expected_md5($zipPath, $expectedMd5);
if (!function_exists('escapeshellarg')) {
    fail('escapeshellarg_unavailable');
}

$command = 'unzip -qq ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($extractRoot);
exec($command, $output, $code);
if ($code !== 0) {
    rrmdir($extractRoot);
    fail('runtime_zip_unzip_failed');
}

$entries = read_engine_manifest($extractRoot);
validate_engine_payload($extractRoot, $entries);
$actualRevision = read_engine_revision($extractRoot);
if (!hash_equals($expectedRevision, $actualRevision)) {
    rrmdir($extractRoot);
    fail('runtime_engine_revision_mismatch');
}

ensure_runtime_storage_dir($publicRoot . '/.stattic/storage');
ensure_runtime_storage_dir($publicRoot . '/.stattic/storage/runtime');
ensure_runtime_storage_dir($publicRoot . '/.stattic/storage/spaces');
if ($cleanInstall) {
    clean_engine_install_root($publicRoot);
}
$quarantinedLegacyPublicIndex = quarantine_legacy_public_index($publicRoot, $incomingRoot);

foreach ($entries as $entry) {
    $source = $extractRoot . '/' . $entry['source'];
    $target = $publicRoot . '/' . $entry['path'];
    $targetDir = dirname($target);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $tmp = $target . '.tmp.' . getmypid();
    copy($source, $tmp);
    @chmod($tmp, !empty($entry['executable']) ? 0755 : 0644);
    rename($tmp, $target);
}

if (function_exists('opcache_invalidate')) {
    foreach ($entries as $entry) {
        @opcache_invalidate($publicRoot . '/' . $entry['path'], true);
    }
}

rrmdir($extractRoot);
if ($downloadedZip) {
    @unlink($zipPath);
}

echo json_encode([
    'status' => 'installed',
    'file_count' => count($entries),
    'clean' => $cleanInstall,
    'engine_revision' => $actualRevision,
    'quarantined_legacy_public_index' => $quarantinedLegacyPublicIndex,
], JSON_PRETTY_PRINT) . "\n";
