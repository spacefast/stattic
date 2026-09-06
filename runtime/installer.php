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
    $rollbackComplete = true;
    if (!$cleaning) {
        $cleaning = true;
        if (($GLOBALS['spacefast_install_phase'] ?? null) === 'publishing') {
            $rollbackComplete = rollback_install_transaction($message);
        } elseif (($GLOBALS['spacefast_install_phase'] ?? null) !== 'committed') {
            cleanup_registered_paths();
        }
    }
    if (!$rollbackComplete) {
        fwrite(STDERR, "runtime_engine_rollback_incomplete\n");
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

function rrmdir(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    if (!is_dir($path) || is_link($path)) {
        return false;
    }
    $entries = scandir($path);
    if (!is_array($entries)) {
        return false;
    }
    $removed = true;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            $removed = rrmdir($child) && $removed;
        } else {
            $removed = unlink_if_present($child) && $removed;
        }
    }
    return $removed && rmdir($path) && !file_exists($path) && !is_link($path);
}

function remove_managed_file(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    return !is_dir($path)
        && unlink_if_present($path)
        && sync_directory(dirname($path));
}

function remove_managed_tree(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    return !is_link($path)
        && rrmdir($path)
        && sync_directory(dirname($path));
}

function ensure_durable_directory_entry(string $path, int $mode): bool
{
    $parent = dirname($path);
    if (!is_dir($parent) || is_link($parent)) {
        return false;
    }
    if (is_link($path) || (file_exists($path) && !is_dir($path))) {
        return false;
    }
    $created = !is_dir($path);
    if ($created && !mkdir($path, $mode)) {
        return false;
    }
    return chmod($path, $mode)
        && sync_directory($path)
        && (!$created || sync_directory($parent));
}

function ensure_runtime_storage_dir(string $installRoot, string $relative): void
{
    if (unsafe_relative_path($relative)) {
        fail('runtime_engine_storage_invalid');
    }
    $root = realpath($installRoot);
    if (!is_string($root)) {
        fail('runtime_engine_storage_invalid');
    }
    $current = $root;
    foreach (explode('/', $relative) as $segment) {
        $current .= '/' . $segment;
        if (!ensure_durable_directory_entry($current, 0775)) {
            fail('runtime_engine_storage_invalid');
        }
    }
    $resolved = realpath($current);
    if (!is_string($resolved) || !str_starts_with($resolved, $root . '/')) {
        fail('runtime_engine_storage_invalid');
    }
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

function create_private_temporary_file(string $parent, string $prefix): ?string
{
    if (!is_dir($parent) || is_link($parent)) {
        return null;
    }
    $temporary = tempnam($parent, $prefix);
    if (!is_string($temporary) || is_link($temporary) || !is_file($temporary)) {
        if (is_string($temporary) && !is_link($temporary)) {
            unlink_if_present($temporary);
        }
        return null;
    }
    return $temporary;
}

function create_exclusive_regular_file(string $path): bool
{
    if (!is_dir(dirname($path)) || is_link(dirname($path))) {
        return false;
    }
    $handle = @fopen($path, 'x+b');
    if (!is_resource($handle)) {
        return false;
    }
    $closed = fclose($handle);
    return $closed && !is_link($path) && is_file($path) && chmod($path, 0600);
}

function sync_regular_file(string $path): bool
{
    if (!is_file($path) || is_link($path)) {
        return false;
    }
    $handle = @fopen($path, 'rb');
    $synced = is_resource($handle) && fsync($handle);
    if (is_resource($handle)) {
        fclose($handle);
    }
    return $synced;
}

function sync_directory(string $path): bool
{
    if (!is_dir($path) || is_link($path)) {
        return false;
    }
    $handle = @fopen($path, 'r');
    $synced = is_resource($handle) && fsync($handle);
    if (is_resource($handle)) {
        fclose($handle);
    }
    return $synced;
}

function sync_tree(string $root): bool
{
    if (!is_dir($root) || is_link($root)) {
        return false;
    }
    $directories = [$root];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $path = (string) $item->getPathname();
            if ($item->isLink()) {
                return false;
            }
            if ($item->isDir()) {
                $directories[] = $path;
            } elseif (!$item->isFile() || !sync_regular_file($path)) {
                return false;
            }
        }
    } catch (Throwable) {
        return false;
    }
    usort($directories, fn (string $left, string $right): int => strlen($right) <=> strlen($left));
    foreach ($directories as $directory) {
        if (!sync_directory($directory)) {
            return false;
        }
    }
    return true;
}

function publish_regular_file(string $target, string $contents, int $mode): bool
{
    $parent = dirname($target);
    if (is_link($target) || is_dir($target) || !is_dir($parent) || is_link($parent)) {
        return false;
    }
    $temporary = create_private_temporary_file($parent, '.spacefast-');
    if (!is_string($temporary)) {
        return false;
    }
    $handle = fopen($temporary, 'wb');
    $written = 0;
    if (is_resource($handle) && flock($handle, LOCK_EX)) {
        $length = strlen($contents);
        while ($written < $length) {
            $chunk = fwrite($handle, substr($contents, $written));
            if (!is_int($chunk) || $chunk === 0) {
                $written = -1;
                break;
            }
            $written += $chunk;
        }
    }
    $synced = is_resource($handle)
        && $written === strlen($contents)
        && fflush($handle)
        && fsync($handle);
    if (is_resource($handle)) {
        fclose($handle);
    }
    $published = $synced
        && chmod($temporary, $mode)
        && !is_link($temporary)
        && is_file($temporary)
        && rename($temporary, $target);
    if ($published) {
        $published = sync_directory($parent);
    }
    if (!$published) {
        unlink_if_present($temporary);
    }
    return $published;
}

function unsafe_relative_path(string $p): bool
{
    if ($p === '' || str_starts_with($p, '/') || str_contains($p, "\0") || str_contains($p, '\\')) {
        return true;
    }
    foreach (explode('/', $p) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return true;
        }
    }
    return false;
}

// Reserved for site state, the active pointer, releases, and installer
// scratch. A payload may not claim any of them.
function reserved_install_root_name(string $name): bool
{
    return in_array($name, [
        'storage',
        'incoming',
        'installer.lock',
        'publication.lock',
        'active-release',
        'loader-version',
        'active-release-proof.json',
        'install-transaction.json',
        'rollback-failure.json',
        'release-authorities',
        'releases',
        '',
        '.',
        '..',
    ], true)
        || str_starts_with($name, 'release-stage-')
        || str_starts_with($name, 'runtime-install-');
}

/**
 * @return array{
 *   staged:list<array{source:string,path:string,relative:string,executable:bool}>,
 *   trees:list<array{source:string,path:string,files:list<string>}>,
 *   alias:list<array{source:string,path:string,executable:bool}>
 * }
 */
function parse_engine_manifest(string $root): array
{
    $path = $root . '/engine-manifest.json';
    if (!is_file($path)) {
        throw new RuntimeException('runtime_engine_manifest_missing');
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (
        !is_array($decoded)
        || !is_array($decoded['files'] ?? null)
        || (array_key_exists('trees', $decoded) && !is_array($decoded['trees']))
        || !is_array($decoded['aliases'] ?? null)
    ) {
        throw new RuntimeException('runtime_engine_manifest_invalid');
    }
    $sourceFiles = [];
    foreach ($decoded['files'] as $file) {
        if (!is_string($file) || unsafe_relative_path($file)) {
            throw new RuntimeException('runtime_engine_manifest_invalid');
        }
        $sourceFiles[] = $file;
    }
    if (count(array_unique($sourceFiles)) !== count($sourceFiles) || !in_array('engine-manifest.json', $sourceFiles, true)) {
        throw new RuntimeException('runtime_engine_manifest_invalid');
    }
    $executables = [];
    foreach (is_array($decoded['executables'] ?? null) ? $decoded['executables'] : [] as $file) {
        if (!is_string($file) || !in_array($file, $sourceFiles, true)) {
            throw new RuntimeException('runtime_engine_manifest_invalid');
        }
        $executables[$file] = true;
    }

    // Trees are whole directories shipped recursively (build outputs whose
    // exact file list only exists in the payload). Their files expand into the
    // immutable release, but their public path remains one tree row so the
    // installer can publish the complete directory as a unit.
    $treeFiles = [];
    $treeRows = [];
    $stagedTreeFiles = [];
    $treeSources = [];
    $treePaths = [];
    foreach ($decoded['trees'] ?? [] as $tree) {
        if (
            !is_array($tree)
            || !is_string($tree['source'] ?? null)
            || !is_string($tree['path'] ?? null)
            || unsafe_relative_path($tree['source'])
            || unsafe_relative_path($tree['path'])
            || str_ends_with($tree['source'], '/')
            || str_ends_with($tree['path'], '/')
            || in_array($tree['source'], $sourceFiles, true)
            || reserved_install_root_name(explode('/', $tree['source'])[0])
            || str_starts_with($tree['path'], '.stattic/')
            || isset($treeSources[$tree['source']])
            || isset($treePaths[$tree['path']])
        ) {
            throw new RuntimeException('runtime_engine_manifest_invalid');
        }
        $treeSources[$tree['source']] = true;
        $treePaths[$tree['path']] = true;
        $treeRoot = $root . '/' . $tree['source'];
        if (!is_dir($treeRoot) || is_link($treeRoot)) {
            throw new RuntimeException('runtime_engine_manifest_tree_missing:' . $tree['source']);
        }
        $walk = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($treeRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('runtime_engine_manifest_tree_link:' . $tree['source']);
            }
            if (!$item->isFile()) {
                continue;
            }
            $relative = substr((string) $item->getPathname(), strlen($treeRoot) + 1);
            $relative = str_replace('\\', '/', $relative);
            if (unsafe_relative_path($relative)) {
                throw new RuntimeException('runtime_engine_manifest_invalid');
            }
            $walk[] = $relative;
        }
        if ($walk === []) {
            throw new RuntimeException('runtime_engine_manifest_tree_empty:' . $tree['source']);
        }
        sort($walk, SORT_STRING);
        foreach ($walk as $relative) {
            $file = $tree['source'] . '/' . $relative;
            $treeFiles[$file] = true;
            $stagedTreeFiles[] = [
                'source' => $file,
                'path' => '.stattic/' . $file,
                'relative' => $file,
                'executable' => false,
            ];
        }
        $treeRows[] = [
            'source' => $tree['source'],
            'path' => $tree['path'],
            'files' => $walk,
        ];
    }

    $staged = [];
    foreach ($sourceFiles as $file) {
        $topLevel = explode('/', $file)[0];
        if (reserved_install_root_name($topLevel)) {
            throw new RuntimeException('runtime_engine_manifest_invalid');
        }
        $staged[] = [
            'source' => $file,
            'path' => '.stattic/' . $file,
            'relative' => $file,
            'executable' => isset($executables[$file]),
        ];
    }
    $alias = [];
    foreach ($stagedTreeFiles as $entry) {
        $staged[] = $entry;
    }

    foreach ($decoded['aliases'] as $entry) {
        if (
            !is_array($entry)
            || !is_string($entry['source'] ?? null)
            || !is_string($entry['path'] ?? null)
            || (!in_array($entry['source'], $sourceFiles, true) && !isset($treeFiles[$entry['source']]))
            // `.stattic/` is private release/storage state, never an alias
            // destination.
            || unsafe_relative_path($entry['path'])
            || str_starts_with($entry['path'], '.stattic/')
        ) {
            throw new RuntimeException('runtime_engine_manifest_invalid');
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
        array_map(fn (array $entry): string => $entry['path'], $treeRows),
    );
    if (count(array_unique($installedFiles)) !== count($installedFiles)) {
        throw new RuntimeException('runtime_engine_manifest_invalid');
    }
    foreach ($treeRows as $tree) {
        $namespace = dirname($tree['path']) . '/spacefast-tree-releases';
        foreach (array_merge($treeRows, $alias) as $entry) {
            if (
                $entry['path'] === $namespace
                || str_starts_with($entry['path'], $namespace . '/')
                || str_starts_with($namespace, $entry['path'] . '/')
            ) {
                throw new RuntimeException('runtime_engine_manifest_invalid');
            }
        }
    }
    foreach ($treeRows as $tree) {
        foreach ($alias as $entry) {
            if (
                str_starts_with($entry['path'], $tree['path'] . '/')
                || str_starts_with($tree['path'], $entry['path'] . '/')
            ) {
                throw new RuntimeException('runtime_engine_manifest_invalid');
            }
        }
        foreach ($treeRows as $other) {
            if ($tree !== $other && str_starts_with($other['path'], $tree['path'] . '/')) {
                throw new RuntimeException('runtime_engine_manifest_invalid');
            }
        }
    }
    usort($staged, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    usort($treeRows, fn (array $left, array $right): int => strcmp(install_order_key($left['path']), install_order_key($right['path'])));
    usort($alias, fn (array $left, array $right): int => strcmp(install_order_key($left['path']), install_order_key($right['path'])));
    return ['staged' => $staged, 'trees' => $treeRows, 'alias' => $alias];
}

/**
 * Parse a payload that is about to install. Invalid input keeps its stable
 * installer error instead of escaping as an uncaught exception.
 */
function read_engine_manifest(string $root): array
{
    try {
        return parse_engine_manifest($root);
    } catch (RuntimeException $error) {
        fail($error->getMessage());
    }
}

/**
 * A damaged installed release must disable the sync shortcut, not prevent a
 * valid replacement payload from installing.
 *
 * @return null|array{
 *   staged:list<array{source:string,path:string,relative:string,executable:bool}>,
 *   trees:list<array{source:string,path:string,files:list<string>}>,
 *   alias:list<array{source:string,path:string,executable:bool}>
 * }
 */
function installed_engine_manifest(string $root): ?array
{
    try {
        return parse_engine_manifest($root);
    } catch (RuntimeException) {
        return null;
    }
}

/**
 * The order aliases are deposited in, as a sortable string.
 *
 * Aliases land one at a time, so a request can arrive with only a prefix of
 * them installed. A directory must therefore be complete before the file that
 * loads it appears: `wp-content/mu-plugins/zero-admin.php` is what WordPress
 * auto-loads, and it reaches into `wp-content/mu-plugins/zero-admin/`. Plain
 * strcmp puts it first — '.' sorts below '/' — which opens a window where every
 * WordPress request on the box fatals, and leaves it open forever if the
 * install aborts inside it.
 *
 * Ranking the separator below every printable character puts a directory's
 * contents ahead of any sibling whose name extends it, which is that dependency
 * for the only shape the engine ships. It stays a total order over distinct
 * paths, so the install order and the loader identity derived from it are
 * still deterministic.
 */
function install_order_key(string $path): string
{
    return str_replace('/', "\1", $path);
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

/**
 * @return array{state:'absent'|'valid'|'legacy'|'malformed'|'missing'|'corrupt'|'unsupported'|'unknown',target?:string,root?:string}
 */
function active_release_pointer_state(string $installRoot): array
{
    $pointer = $installRoot . '/active-release';
    if (is_link($pointer) || is_dir($pointer)) {
        return ['state' => 'unsupported'];
    }
    if (!file_exists($pointer)) {
        return ['state' => 'absent'];
    }
    if (!is_file($pointer)) {
        return ['state' => 'unsupported'];
    }
    clearstatcache(true, $pointer);
    $size = filesize($pointer);
    if (!is_int($size)) {
        return ['state' => 'unknown'];
    }
    if ($size > 512) {
        return ['state' => 'malformed'];
    }
    $raw = file_get_contents($pointer);
    if (!is_string($raw)) {
        return ['state' => 'unknown'];
    }
    $target = trim($raw);
    if (
        preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) !== 1
        || in_array(substr($target, strlen('releases/')), ['.', '..'], true)
    ) {
        return ['state' => 'malformed'];
    }
    $releasesPath = $installRoot . '/releases';
    $targetPath = $installRoot . '/' . $target;
    if (is_link($releasesPath) || (file_exists($releasesPath) && !is_dir($releasesPath))) {
        return ['state' => 'unsupported'];
    }
    if (is_link($targetPath) || (file_exists($targetPath) && !is_dir($targetPath))) {
        return ['state' => 'unsupported', 'target' => $target];
    }
    if (!file_exists($targetPath)) {
        return ['state' => 'missing', 'target' => $target];
    }
    $releasesRoot = realpath($releasesPath);
    $releaseRoot = realpath($targetPath);
    if (
        !is_string($releasesRoot)
        || !is_string($releaseRoot)
        || !str_starts_with($releaseRoot, $releasesRoot . '/')
        || !release_has_structural_artifacts($releaseRoot)
    ) {
        return ['state' => 'corrupt', 'target' => $target];
    }
    $identityMarker = $releaseRoot . '/.payload-identity';
    if (!file_exists($identityMarker) && !is_link($identityMarker)) {
        return ['state' => 'legacy', 'target' => $target, 'root' => $releaseRoot];
    }
    if (!is_file($identityMarker) || is_link($identityMarker)) {
        return ['state' => 'corrupt', 'target' => $target];
    }
    return installed_release_payload_matches($releaseRoot)
        ? ['state' => 'valid', 'target' => $target, 'root' => $releaseRoot]
        : ['state' => 'dangling', 'target' => $target];
}

/**
 * @param array{state:string,target?:string,root?:string} $left
 * @param array{state:string,target?:string,root?:string} $right
 */
function active_release_pointer_states_match(array $left, array $right): bool
{
    if (($left['state'] ?? null) !== ($right['state'] ?? null)) {
        return false;
    }
    if (($left['state'] ?? null) === 'absent') {
        return true;
    }
    if (($left['state'] ?? null) === 'malformed') {
        return true;
    }
    if (in_array(($left['state'] ?? null), ['missing', 'corrupt', 'dangling', 'unsupported'], true)) {
        return ($left['target'] ?? null) === ($right['target'] ?? null);
    }
    return in_array(($left['state'] ?? null), ['valid', 'legacy'], true)
        && ($left['target'] ?? null) === ($right['target'] ?? null)
        && ($left['root'] ?? null) === ($right['root'] ?? null);
}

/** @param array{state:string,target?:string,root?:string} $expected */
function normalize_active_release_pointer(string $installRoot, array $expected): bool
{
    if (($expected['state'] ?? null) !== 'missing') {
        return false;
    }
    if (!active_release_pointer_states_match(active_release_pointer_state($installRoot), $expected)) {
        return false;
    }
    $pointer = $installRoot . '/active-release';
    return !is_link($pointer)
        && !is_dir($pointer)
        && is_file($pointer)
        && unlink_if_present($pointer)
        && (active_release_pointer_state($installRoot)['state'] ?? null) === 'absent';
}

function active_release_pointer_target(string $installRoot): ?string
{
    $state = active_release_pointer_state($installRoot);
    return in_array($state['state'], ['valid', 'legacy'], true) ? ($state['target'] ?? null) : null;
}

function active_release_root(string $installRoot): ?string
{
    $state = active_release_pointer_state($installRoot);
    return in_array($state['state'], ['valid', 'legacy'], true) ? ($state['root'] ?? null) : null;
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
    if (!publish_regular_file($pointer, $target . "\n", 0644)) {
        return false;
    }
    clearstatcache(true, $pointer);
    return active_release_pointer_target($installRoot) === $target;
}

function release_has_structural_artifacts(string $root): bool
{
    foreach (['engine-manifest.json', 'engine/shared/context.php', 'bin/stattic-runtime'] as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path) || is_link($path)) {
            return false;
        }
    }
    $manifest = installed_engine_manifest($root);
    if (read_engine_revision($root) === null || !is_array($manifest) || !is_executable($root . '/bin/stattic-runtime')) {
        return false;
    }
    foreach ($manifest['staged'] as $entry) {
        $path = $root . '/' . $entry['relative'];
        $mode = fileperms($path);
        if (
            !is_file($path)
            || is_link($path)
            || !is_int($mode)
            || ($mode & 0777) !== ($entry['executable'] ? 0755 : 0644)
        ) {
            return false;
        }
    }
    return true;
}

/**
 * Restore only when both the prior state and the state being replaced are
 * known. Never turn an unreadable or malformed pointer into deletion.
 *
 * @param array{state:string,target?:string,root?:string} $previous
 */
function restore_active_release_pointer(string $installRoot, array $previous, string $newTarget): bool
{
    $current = active_release_pointer_state($installRoot);
    if (active_release_pointer_states_match($current, $previous)) {
        return true;
    }
    if (
        !in_array(($current['state'] ?? null), ['valid', 'legacy', 'missing', 'corrupt'], true)
        || ($current['target'] ?? null) !== $newTarget
    ) {
        return false;
    }
    if (in_array(($previous['state'] ?? null), ['valid', 'legacy'], true)) {
        $target = $previous['target'] ?? null;
        $root = $previous['root'] ?? null;
        if (
            !is_string($target)
            || !is_string($root)
            || !release_has_structural_artifacts($root)
            || (($previous['state'] ?? null) === 'valid' && !installed_release_payload_matches($root))
            || !publish_active_release($installRoot, $target)
        ) {
            return false;
        }
        return active_release_pointer_states_match(active_release_pointer_state($installRoot), $previous);
    }
    if (($previous['state'] ?? null) !== 'absent') {
        return false;
    }
    $pointer = $installRoot . '/active-release';
    if (!file_exists($pointer) && !is_link($pointer)) {
        return true;
    }
    return !is_link($pointer)
        && !is_dir($pointer)
        && is_file($pointer)
        && unlink_if_present($pointer)
        && (active_release_pointer_state($installRoot)['state'] ?? null) === 'absent';
}

function rollback_install_pointer(): bool
{
    $installRoot = $GLOBALS['spacefast_install_root'] ?? null;
    $previous = $GLOBALS['spacefast_previous_release_pointer'] ?? null;
    $newTarget = $GLOBALS['spacefast_new_release_target'] ?? null;
    if (!is_string($installRoot) || !is_array($previous) || !is_string($newTarget)) {
        return true;
    }
    return restore_active_release_pointer($installRoot, $previous, $newTarget);
}

/**
 * Identity of the public WordPress payload this release would install: sha256
 * over every tree file and alias, ordered by served path, as
 * `<served path>\0<byte length>\0<bytes>`. Deriving it from the payload rather
 * than a version number keeps it hermetic. The same zip always answers the
 * same identity, and any change to a plugin tree or loader changes it.
 *
 * @param list<array{source:string,path:string,executable:bool}> $alias
 * @param list<array{source:string,path:string,files:list<string>}> $trees
 */
function loader_payload_identity(string $extractRoot, array $alias, array $trees, bool $strict = true): ?string
{
    $digest = hash_init('sha256');
    foreach ($trees as $tree) {
        foreach ($tree['files'] as $relative) {
            $source = $extractRoot . '/' . $tree['source'] . '/' . $relative;
            $servedPath = $tree['path'] . '/' . $relative;
            $size = is_file($source) ? filesize($source) : false;
            if ($size === false || !hash_update($digest, $servedPath . "\0" . 0644 . "\0" . $size . "\0") || !hash_update_file($digest, $source)) {
                if ($strict) {
                    fail('runtime_engine_loader_source_unreadable:' . $tree['source'] . '/' . $relative);
                }
                return null;
            }
        }
    }
    foreach ($alias as $entry) {
        $source = $extractRoot . '/' . $entry['source'];
        $size = is_file($source) ? filesize($source) : false;
        $mode = $entry['executable'] ? 0755 : 0644;
        if ($size === false || !hash_update($digest, $entry['path'] . "\0" . $mode . "\0" . $size . "\0") || !hash_update_file($digest, $source)) {
            if ($strict) {
                fail('runtime_engine_loader_source_unreadable:' . $entry['source']);
            }
            return null;
        }
    }
    return hash_final($digest);
}

/**
 * @param array{
 *   trees:list<array{source:string,path:string,files:list<string>}>,
 *   alias:list<array{source:string,path:string,executable:bool}>
 * } $manifest
 */
function release_payload_identity(string $releaseRoot): ?string
{
    if (!is_dir($releaseRoot) || is_link($releaseRoot)) {
        return null;
    }
    $files = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($releaseRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return null;
            }
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr((string) $item->getPathname(), strlen($releaseRoot) + 1));
            if ($relative === '.payload-identity') {
                continue;
            }
            if (unsafe_relative_path($relative)) {
                return null;
            }
            $files[] = $relative;
        }
    } catch (Throwable) {
        return null;
    }
    if ($files === []) {
        return null;
    }
    sort($files, SORT_STRING);
    $digest = hash_init('sha256');
    foreach ($files as $relative) {
        $source = $releaseRoot . '/' . $relative;
        $size = filesize($source);
        $mode = fileperms($source);
        if (
            $size === false
            || !is_int($mode)
            || !hash_update($digest, $relative . "\0" . ($mode & 07777) . "\0" . $size . "\0")
            || !hash_update_file($digest, $source)
        ) {
            return null;
        }
    }
    return hash_final($digest);
}

function installed_release_payload_matches(string $releaseRoot): bool
{
    $marker = $releaseRoot . '/.payload-identity';
    $raw = is_file($marker) && !is_link($marker) ? file_get_contents($marker, false, null, 0, 128) : false;
    $expected = is_string($raw) ? trim($raw) : '';
    $actual = release_payload_identity($releaseRoot);
    return preg_match('/^[a-f0-9]{64}$/', $expected) === 1
        && is_string($actual)
        && hash_equals($expected, $actual);
}

function installed_release_loader_identity(string $releaseRoot): ?string
{
    $manifest = installed_engine_manifest($releaseRoot);
    return is_array($manifest)
        ? loader_payload_identity($releaseRoot, $manifest['alias'], $manifest['trees'], false)
        : null;
}

function installed_files_match(string $expected, string $actual): bool
{
    if (!is_file($expected) || is_link($expected) || !is_file($actual) || is_link($actual)) {
        return false;
    }
    $expectedSize = filesize($expected);
    $actualSize = filesize($actual);
    if (!is_int($expectedSize) || $expectedSize !== $actualSize) {
        return false;
    }
    $expectedHash = hash_file('sha256', $expected);
    $actualHash = hash_file('sha256', $actual);
    return is_string($expectedHash) && is_string($actualHash) && hash_equals($expectedHash, $actualHash);
}

/** @return null|list<string> */
function installed_tree_files(string $root): ?array
{
    if (!is_dir($root) || is_link($root)) {
        return null;
    }
    $files = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return null;
            }
            if (!$item->isFile()) {
                continue;
            }
            $relative = substr((string) $item->getPathname(), strlen($root) + 1);
            $files[] = str_replace('\\', '/', $relative);
        }
    } catch (Throwable) {
        return null;
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Check the public WordPress bytes before accepting the no-op fast path.
 * Regular aliases must stay regular files. Tree paths must be relative links
 * into their public version store, never private release paths.
 *
 * @param array{
 *   trees:list<array{source:string,path:string,files:list<string>}>,
 *   alias:list<array{source:string,path:string,executable:bool}>
 * } $manifest
 */
function installed_public_payload_matches(string $releaseRoot, string $publicRoot, array $manifest): bool
{
    foreach ($manifest['alias'] as $entry) {
        if (public_path_has_symlink_parent($publicRoot, $entry['path'])) {
            return false;
        }
        if (!installed_files_match(
            $releaseRoot . '/' . $entry['source'],
            $publicRoot . '/' . $entry['path'],
        )) {
            return false;
        }
        $mode = fileperms($publicRoot . '/' . $entry['path']);
        if (!is_int($mode) || ($mode & 0777) !== ($entry['executable'] ? 0755 : 0644)) {
            return false;
        }
    }
    $loaderIdentity = installed_release_loader_identity($releaseRoot);
    if (!is_string($loaderIdentity)) {
        return false;
    }
    foreach ($manifest['trees'] as $tree) {
        $target = $publicRoot . '/' . $tree['path'];
        if (public_path_has_symlink_parent($publicRoot, $tree['path']) || !is_link($target)) {
            return false;
        }
        $link = readlink($target);
        $relativePrefix = 'spacefast-tree-releases/' . basename($tree['path']) . '/' . $loaderIdentity . '-';
        $expectedPrefix = dirname($target) . '/spacefast-tree-releases/' . basename($tree['path']);
        $resolvedTarget = realpath($target);
        $resolvedPrefix = realpath($expectedPrefix);
        if (
            !is_string($link)
            || !str_starts_with($link, $relativePrefix)
            || str_contains(substr($link, strlen($relativePrefix)), '/')
            || is_link(dirname($target) . '/spacefast-tree-releases')
            || is_link($expectedPrefix)
            || !is_string($resolvedTarget)
            || !is_string($resolvedPrefix)
            || !str_starts_with($resolvedTarget, $resolvedPrefix . '/')
        ) {
            return false;
        }
        $publicFiles = installed_tree_files($resolvedTarget);
        if ($publicFiles !== $tree['files']) {
            return false;
        }
        foreach ($tree['files'] as $relative) {
            $mode = fileperms($resolvedTarget . '/' . $relative);
            if (!installed_files_match(
                $releaseRoot . '/' . $tree['source'] . '/' . $relative,
                $resolvedTarget . '/' . $relative,
            ) || !is_int($mode) || ($mode & 0777) !== 0644) {
                return false;
            }
        }
    }
    return true;
}

function legacy_public_payload_matches(string $releaseRoot, string $publicRoot, array $manifest): bool
{
    foreach ($manifest['alias'] as $entry) {
        if (
            public_path_has_symlink_parent($publicRoot, $entry['path'])
            || !public_alias_matches_owner($publicRoot . '/' . $entry['path'], [[
                'release' => $releaseRoot,
                'source' => $entry['source'],
                'executable' => $entry['executable'],
            ]])
        ) {
            return false;
        }
    }
    foreach ($manifest['trees'] as $tree) {
        $target = $publicRoot . '/' . $tree['path'];
        if (
            public_path_has_symlink_parent($publicRoot, $tree['path'])
            || !is_dir($target)
            || is_link($target)
            || !public_tree_matches_owner($target, [[
                'release' => $releaseRoot,
                'source' => $tree['source'],
                'files' => $tree['files'],
            ]])
        ) {
            return false;
        }
    }
    return true;
}

/** @return null|array{release:string,payload_identity:string,loader_identity:string,revision:string} */
function release_authority(string $installRoot, string $releaseRoot): ?array
{
    $basename = basename($releaseRoot);
    if (preg_match('/^release-[A-Za-z0-9._-]+$/', $basename) !== 1) {
        return null;
    }
    $authoritiesRoot = $installRoot . '/release-authorities';
    $record = $authoritiesRoot . '/' . $basename . '.json';
    if (is_link($authoritiesRoot) || !is_dir($authoritiesRoot) || is_link($record) || !is_file($record)) {
        return null;
    }
    $raw = file_get_contents($record, false, null, 0, 2048);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (
        !is_array($decoded)
        || ($decoded['format'] ?? null) !== 'spacefast.runtime.release-authority.v1'
        || ($decoded['release'] ?? null) !== $basename
        || preg_match('/^[a-f0-9]{64}$/', $decoded['payload_identity'] ?? '') !== 1
        || preg_match('/^[a-f0-9]{64}$/', $decoded['loader_identity'] ?? '') !== 1
        || !is_string($decoded['revision'] ?? null)
    ) {
        return null;
    }
    $marker = $releaseRoot . '/.payload-identity';
    $payloadIdentity = is_file($marker) && !is_link($marker) ? trim((string) file_get_contents($marker)) : '';
    $loaderIdentity = installed_release_loader_identity($releaseRoot);
    if (
        !hash_equals($decoded['payload_identity'], $payloadIdentity)
        || !is_string($loaderIdentity)
        || !hash_equals($decoded['loader_identity'], $loaderIdentity)
        || read_engine_revision($releaseRoot) !== $decoded['revision']
    ) {
        return null;
    }
    return [
        'release' => $basename,
        'payload_identity' => $decoded['payload_identity'],
        'loader_identity' => $decoded['loader_identity'],
        'revision' => $decoded['revision'],
    ];
}

function write_release_authority(
    string $installRoot,
    string $releaseRoot,
    string $revision,
    string $payloadIdentity,
    string $loaderIdentity,
): bool {
    $authoritiesRoot = $installRoot . '/release-authorities';
    if (is_link($authoritiesRoot) || !ensure_install_dir($authoritiesRoot)) {
        return false;
    }
    $resolved = realpath($authoritiesRoot);
    if (!is_string($resolved) || $resolved !== $authoritiesRoot || !chmod($authoritiesRoot, 0700)) {
        return false;
    }
    $payload = json_encode([
        'format' => 'spacefast.runtime.release-authority.v1',
        'release' => basename($releaseRoot),
        'payload_identity' => $payloadIdentity,
        'loader_identity' => $loaderIdentity,
        'revision' => $revision,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $record = $authoritiesRoot . '/' . basename($releaseRoot) . '.json';
    return is_string($payload)
        && publish_regular_file($record, $payload . "\n", 0600)
        && release_authority($installRoot, $releaseRoot) !== null;
}

/** @return list<string> */
function trusted_release_roots(string $releasesRoot, ?string $excludeRoot = null): array
{
    if (is_link($releasesRoot)) {
        return [];
    }
    $resolvedRoot = realpath($releasesRoot);
    if (!is_string($resolvedRoot)) {
        return [];
    }
    $excluded = is_string($excludeRoot) ? realpath($excludeRoot) : false;
    $trusted = [];
    foreach (glob($releasesRoot . '/release-*', GLOB_ONLYDIR) ?: [] as $candidate) {
        $resolved = realpath($candidate);
        if (
            is_link($candidate)
            || !is_string($resolved)
            || $resolved === $excluded
            || !str_starts_with($resolved, $resolvedRoot . '/')
            || !installed_release_payload_matches($resolved)
            || !is_array(installed_engine_manifest($resolved))
            || release_authority(dirname($releasesRoot), $resolved) === null
        ) {
            continue;
        }
        $trusted[] = $resolved;
    }
    return $trusted;
}

/**
 * @return array{
 *   aliases:array<string,list<array{release:string,source:string,executable:bool}>>,
 *   trees:array<string,list<array{release:string,source:string,files:list<string>}>>
 * }
 */
function historical_public_owners(
    string $releasesRoot,
    string $publicRoot,
    array $previousPointer,
    ?string $excludeRoot = null,
    array $extraRoots = [],
): array {
    $roots = array_merge(trusted_release_roots($releasesRoot, $excludeRoot), $extraRoots);
    if (($previousPointer['state'] ?? null) === 'legacy' && is_string($previousPointer['root'] ?? null)) {
        $legacy = $previousPointer['root'];
        $resolvedReleases = realpath($releasesRoot);
        $resolvedLegacy = realpath($legacy);
        $manifest = is_string($resolvedLegacy) ? installed_engine_manifest($resolvedLegacy) : null;
        if (
            is_string($resolvedReleases)
            && is_string($resolvedLegacy)
            && !is_link($legacy)
            && str_starts_with($resolvedLegacy, $resolvedReleases . '/')
            && release_has_structural_artifacts($resolvedLegacy)
            && is_array($manifest)
            && (
                installed_public_payload_matches($resolvedLegacy, $publicRoot, $manifest)
                || legacy_public_payload_matches($resolvedLegacy, $publicRoot, $manifest)
            )
        ) {
            $roots[] = $resolvedLegacy;
        }
    }
    $owners = ['aliases' => [], 'trees' => []];
    foreach (array_values(array_unique($roots)) as $root) {
        $manifest = installed_engine_manifest($root);
        if (!is_array($manifest)) {
            continue;
        }
        foreach ($manifest['alias'] as $alias) {
            $owners['aliases'][$alias['path']][] = [
                'release' => $root,
                'source' => $alias['source'],
                'executable' => $alias['executable'],
            ];
        }
        foreach ($manifest['trees'] as $tree) {
            $owners['trees'][$tree['path']][] = [
                'release' => $root,
                'source' => $tree['source'],
                'files' => $tree['files'],
            ];
        }
    }
    return $owners;
}

function public_paths_overlap(string $left, string $right): bool
{
    return $left === $right
        || str_starts_with($left, $right . '/')
        || str_starts_with($right, $left . '/');
}

/** @param array{trees:list<array{path:string}>,alias:list<array{path:string}>} $manifest */
function validate_public_path_transitions(string $publicRoot, array $manifest, array $owners): void
{
    foreach ($manifest['alias'] as $alias) {
        foreach (array_keys($owners['trees']) as $treePath) {
            if (public_paths_overlap($alias['path'], $treePath)) {
                $target = $publicRoot . '/' . $treePath;
                if (file_exists($target) || is_link($target)) {
                    fail('runtime_engine_public_path_transition_invalid:' . $alias['path']);
                }
            }
        }
    }
    foreach ($manifest['trees'] as $tree) {
        $target = $publicRoot . '/' . $tree['path'];
        $aliasesOwnTree = public_tree_matches_alias_owners($publicRoot, $tree['path'], $owners['aliases'])
            || public_tree_matches_owner(realpath($target) ?: $target, $owners['trees'][$tree['path']] ?? []);
        foreach (array_keys($owners['aliases']) as $aliasPath) {
            if ($aliasesOwnTree && str_starts_with($aliasPath, $tree['path'] . '/')) {
                continue;
            }
            if (public_paths_overlap($tree['path'], $aliasPath)) {
                $target = $publicRoot . '/' . $aliasPath;
                if (file_exists($target) || is_link($target)) {
                    fail('runtime_engine_public_path_transition_invalid:' . $tree['path']);
                }
            }
        }
        foreach (array_keys($owners['trees']) as $treePath) {
            if ($tree['path'] !== $treePath && public_paths_overlap($tree['path'], $treePath)) {
                $target = $publicRoot . '/' . $treePath;
                if (file_exists($target) || is_link($target)) {
                    fail('runtime_engine_public_path_transition_invalid:' . $tree['path']);
                }
            }
        }
    }
}

/** @param list<array{release:string,source:string,files:list<string>}> $owners */
function public_tree_matches_owner(string $target, array $owners): bool
{
    $actualFiles = installed_tree_files($target);
    if (!is_array($actualFiles)) {
        return false;
    }
    foreach ($owners as $owner) {
        if ($actualFiles !== $owner['files']) {
            continue;
        }
        $matches = true;
        foreach ($owner['files'] as $relative) {
            $mode = fileperms($target . '/' . $relative);
            if (
                !installed_files_match($owner['release'] . '/' . $owner['source'] . '/' . $relative, $target . '/' . $relative)
                || !is_int($mode)
                || ($mode & 0777) !== 0644
            ) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }
    }
    return false;
}

function public_tree_matches_alias_owners(string $publicRoot, string $treePath, array $owners): bool
{
    $target = $publicRoot . '/' . $treePath;
    if (public_path_has_symlink_parent($publicRoot, $treePath) || is_link($target)) {
        return false;
    }
    $files = installed_tree_files($target);
    if (!is_array($files) || $files === []) {
        return false;
    }
    foreach ($files as $relative) {
        $path = $treePath . '/' . $relative;
        if (!public_alias_matches_owner($publicRoot . '/' . $path, $owners[$path] ?? [])) {
            return false;
        }
    }
    return true;
}

/** @param list<array{release:string,source:string,executable:bool}> $owners */
function public_alias_matches_owner(string $target, array $owners): bool
{
    if (!is_file($target) || is_link($target)) {
        return false;
    }
    $mode = fileperms($target);
    foreach ($owners as $owner) {
        if (
            is_int($mode)
            && ($mode & 0777) === ($owner['executable'] ? 0755 : 0644)
            && installed_files_match($owner['release'] . '/' . $owner['source'], $target)
        ) {
            return true;
        }
    }
    return false;
}

function public_path_has_symlink_parent(string $publicRoot, string $relative): bool
{
    $current = rtrim($publicRoot, '/');
    $segments = explode('/', $relative);
    array_pop($segments);
    foreach ($segments as $segment) {
        $current .= '/' . $segment;
        if (is_link($current)) {
            return true;
        }
    }
    return false;
}

/** @param array{trees:list<array{path:string}>,alias:list<array{path:string}>} $manifest */
function remove_retired_public_paths(string $publicRoot, array $manifest, array $owners, string $suffix): void
{
    $currentAliases = array_fill_keys(array_map(fn (array $row): string => $row['path'], $manifest['alias']), true);
    $currentTrees = array_fill_keys(array_map(fn (array $row): string => $row['path'], $manifest['trees']), true);

    // Trees first. Removing a nested alias while its parent is a live tree link
    // would write through the immutable public version.
    foreach (array_diff_key($owners['trees'], $currentTrees) as $path => $pathOwners) {
        $target = $publicRoot . '/' . $path;
        if (!file_exists($target) && !is_link($target)) {
            continue;
        }
        if (is_link($target)) {
            if (public_path_has_symlink_parent($publicRoot, $path)) {
                fail('runtime_engine_retired_tree_invalid:' . $path);
            }
            $link = readlink($target);
            $store = dirname($target) . '/spacefast-tree-releases/' . basename($path);
            $resolved = realpath($target);
            $resolvedStore = realpath($store);
            if (
                !is_string($link)
                || !str_starts_with($link, 'spacefast-tree-releases/' . basename($path) . '/')
                || str_contains(substr($link, strlen('spacefast-tree-releases/' . basename($path) . '/')), '/')
                || !is_string($resolved)
                || !is_string($resolvedStore)
                || !str_starts_with($resolved, $resolvedStore . '/')
                || !public_tree_matches_owner($resolved, $pathOwners)
            ) {
                fail('runtime_engine_retired_tree_invalid:' . $path);
            }
            $GLOBALS['spacefast_tree_swaps'][] = [
                'target' => $target,
                'kind' => 'symlink',
                'previous' => $link,
                'retired_version' => $resolved,
                'retired_store' => $store,
            ];
            if (!unlink_if_present($target)) {
                fail('runtime_engine_retired_tree_remove_failed:' . $path);
            }
            if (!sync_directory(dirname($target))) {
                fail('runtime_engine_retired_tree_sync_failed:' . $path);
            }
            continue;
        }
        if (
            public_path_has_symlink_parent($publicRoot, $path)
            || !is_dir($target)
            || !public_tree_matches_owner($target, $pathOwners)
        ) {
            fail('runtime_engine_retired_tree_invalid:' . $path);
        }
        $backup = $target . '.previous.' . $suffix;
        $GLOBALS['spacefast_tree_swaps'][] = [
            'target' => $target,
            'kind' => 'directory',
            'backup' => $backup,
        ];
        register_cleanup_path($backup);
        if (!rename($target, $backup)) {
            fail('runtime_engine_retired_tree_backup_failed:' . $path);
        }
        if (!sync_directory(dirname($target))) {
            fail('runtime_engine_retired_tree_sync_failed:' . $path);
        }
    }

    foreach (array_diff_key($owners['aliases'], $currentAliases) as $path => $pathOwners) {
        foreach (array_keys($currentTrees) as $treePath) {
            if ($path === $treePath || str_starts_with($path, $treePath . '/')) {
                continue 2;
            }
        }
        $target = $publicRoot . '/' . $path;
        if (!file_exists($target) && !is_link($target)) {
            continue;
        }
        if (public_path_has_symlink_parent($publicRoot, $path) || !public_alias_matches_owner($target, $pathOwners)) {
            fail('runtime_engine_retired_alias_invalid:' . $path);
        }
        register_file_swap($target, $suffix);
        if (!unlink_if_present($target)) {
            fail('runtime_engine_retired_alias_remove_failed:' . $path);
        }
        if (!sync_directory(dirname($target))) {
            fail('runtime_engine_retired_alias_sync_failed:' . $path);
        }
    }
}

/** @param array{trees:list<array{path:string}>,alias:list<array{path:string}>} $manifest */
function retired_public_paths_absent(string $publicRoot, array $manifest, array $owners): bool
{
    $currentAliases = array_fill_keys(array_map(fn (array $row): string => $row['path'], $manifest['alias']), true);
    $currentTrees = array_fill_keys(array_map(fn (array $row): string => $row['path'], $manifest['trees']), true);
    foreach (array_keys(array_diff_key($owners['trees'], $currentTrees)) as $path) {
        if (file_exists($publicRoot . '/' . $path) || is_link($publicRoot . '/' . $path)) {
            return false;
        }
    }
    foreach (array_keys(array_diff_key($owners['aliases'], $currentAliases)) as $path) {
        $ownedByCurrentTree = false;
        foreach (array_keys($currentTrees) as $treePath) {
            if ($path === $treePath || str_starts_with($path, $treePath . '/')) {
                $ownedByCurrentTree = true;
                break;
            }
        }
        if (!$ownedByCurrentTree && (file_exists($publicRoot . '/' . $path) || is_link($publicRoot . '/' . $path))) {
            return false;
        }
    }
    return true;
}

// The marker holds the identity of the installed loader. A box that installed
// before the marker became content-derived carries a literal that fails this
// regex, so it reads as no identity and its loader is reinstalled once.
function installed_loader_identity(string $installRoot): ?string
{
    $marker = $installRoot . '/loader-version';
    $raw = is_file($marker) && !is_link($marker) ? file_get_contents($marker, false, null, 0, 128) : false;
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

function installer_test_pause(string $phase): void
{
    if (
        installer_config_value('SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE') !== $phase
        || ($GLOBALS['spacefast_test_pause_reached'] ?? false) === true
    ) {
        return;
    }
    $marker = installer_config_value('SPACEFAST_RUNTIME_INSTALLER_TEST_PAUSE_FILE');
    if ($marker === '' || file_put_contents($marker, "ready\n") === false) {
        fail('runtime_engine_test_pause_failed');
    }
    $GLOBALS['spacefast_test_pause_reached'] = true;
    while (true) {
        usleep(10000);
    }
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

function cleanup_registered_paths(): bool
{
    $clean = true;
    foreach ($GLOBALS['spacefast_cleanup_paths'] ?? [] as $path) {
        if (is_dir($path) && !is_link($path)) {
            $clean = remove_managed_tree($path) && $clean;
        } else {
            $clean = remove_managed_file($path) && $clean;
        }
    }
    if ($clean) {
        $GLOBALS['spacefast_cleanup_paths'] = [];
    }
    return $clean;
}

/**
 * Remember the live file that an atomic rename is about to replace.
 *
 * Regular files are copied aside so the live path itself never disappears.
 * Symlinks only need their target recorded. Directories and other invalid
 * targets are deliberately left alone: the publication rename will fail, and
 * there is then no successful swap to undo.
 */
function register_file_swap(string $target, string $suffix): void
{
    if (is_link($target)) {
        $previous = readlink($target);
        if (!is_string($previous)) {
            fail('runtime_engine_file_readlink_failed:' . $target);
        }
        $GLOBALS['spacefast_file_swaps'][] = [
            'target' => $target,
            'kind' => 'symlink',
            'previous' => $previous,
        ];
        return;
    }
    if (is_file($target)) {
        $backup = $target . '.previous.' . $suffix;
        unlink_if_present($backup);
        register_cleanup_path($backup);
        $mode = fileperms($target);
        if (
            !copy($target, $backup)
            || !is_int($mode)
            || !chmod($backup, $mode & 0777)
            || !sync_regular_file($backup)
            || !sync_directory(dirname($backup))
        ) {
            unlink_if_present($backup);
            fail('runtime_engine_file_backup_failed:' . $target);
        }
        $GLOBALS['spacefast_file_swaps'][] = [
            'target' => $target,
            'kind' => 'file',
            'backup' => $backup,
        ];
        return;
    }
    if (!file_exists($target)) {
        $GLOBALS['spacefast_file_swaps'][] = ['target' => $target, 'kind' => 'absent'];
    }
}

/** @param array{target:string,kind:'absent'|'file'|'symlink',backup?:string,previous?:string} $swap */
function rollback_file_swap(array $swap): bool
{
    $target = $swap['target'];
    if (installer_config_value('SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE') === 'file_rollback') {
        error_log('runtime_engine_file_rollback_failed:' . $target);
        return false;
    }
    if ($swap['kind'] === 'file') {
        $backup = $swap['backup'] ?? '';
        if ($backup === '' || !is_file($backup) || !rename($backup, $target)) {
            error_log('runtime_engine_file_rollback_failed:' . $target);
            return false;
        }
        unregister_cleanup_path($backup);
        return sync_directory(dirname($target));
    }
    if ($swap['kind'] === 'absent') {
        if (is_dir($target) && !is_link($target)) {
            error_log('runtime_engine_file_rollback_failed:' . $target);
            return false;
        }
        if (is_file($target) || is_link($target)) {
            if (!unlink_if_present($target)) {
                error_log('runtime_engine_file_rollback_failed:' . $target);
                return false;
            }
        }
        return sync_directory(dirname($target));
    }
    $temporary = $target . '.rollback.' . getmypid();
    unlink_if_present($temporary);
    if (!symlink((string) ($swap['previous'] ?? ''), $temporary) || !rename($temporary, $target)) {
        unlink_if_present($temporary);
        error_log('runtime_engine_file_rollback_failed:' . $target);
        return false;
    }
    return is_link($target)
        && readlink($target) === ($swap['previous'] ?? null)
        && sync_directory(dirname($target));
}

function rollback_registered_file_swaps(): bool
{
    $swaps = array_reverse($GLOBALS['spacefast_file_swaps'] ?? []);
    $failed = [];
    foreach ($swaps as $swap) {
        if (!rollback_file_swap($swap)) {
            $failed[] = $swap;
        }
    }
    $GLOBALS['spacefast_file_swaps'] = array_reverse($failed);
    return $failed === [];
}

function commit_registered_file_swaps(): bool
{
    $committed = true;
    $remaining = [];
    foreach ($GLOBALS['spacefast_file_swaps'] ?? [] as $swap) {
        $backup = $swap['backup'] ?? null;
        if (is_string($backup) && !remove_managed_file($backup)) {
            $remaining[] = $swap;
            $committed = false;
        } elseif (is_string($backup)) {
            unregister_cleanup_path($backup);
        }
    }
    $GLOBALS['spacefast_file_swaps'] = $remaining;
    return $committed;
}

/**
 * Copy one manifest tree without following links.
 *
 * The payload walk rejects links before staging, but checking again keeps this
 * helper safe if another caller is added later.
 */
function copy_manifest_tree(string $sourceRoot, string $targetRoot): void
{
    if (!is_dir($sourceRoot) || is_link($sourceRoot) || !ensure_install_dir($targetRoot)) {
        fail('runtime_engine_tree_source_invalid');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            fail('runtime_engine_tree_link');
        }
        $relative = substr((string) $item->getPathname(), strlen($sourceRoot) + 1);
        $target = $targetRoot . '/' . str_replace('\\', '/', $relative);
        if ($item->isDir()) {
            if (!ensure_install_dir($target)) {
                fail('runtime_engine_tree_copy_mkdir_failed:' . $relative);
            }
            continue;
        }
        if (!$item->isFile() || !ensure_install_dir(dirname($target)) || !copy((string) $item->getPathname(), $target) || !chmod($target, 0644)) {
            fail('runtime_engine_tree_copy_failed:' . $relative);
        }
    }
}

function ensure_public_directory(string $publicRoot, string $relative): ?string
{
    $resolvedRoot = realpath($publicRoot);
    if (!is_string($resolvedRoot)) {
        return null;
    }
    if ($relative === '.' || $relative === '') {
        return $resolvedRoot;
    }
    if (unsafe_relative_path($relative)) {
        return null;
    }
    $current = $resolvedRoot;
    foreach (explode('/', $relative) as $segment) {
        $current .= '/' . $segment;
        // Provider-owned WordPress parents are writable by the site group,
        // but their ownership and permission bits belong to the provider.
        if (is_dir($current) && !is_link($current)) {
            continue;
        }
        if (!ensure_durable_directory_entry($current, 0755)) {
            return null;
        }
    }
    $resolved = realpath($current);
    return is_string($resolved) && str_starts_with($resolved, $resolvedRoot . '/') ? $resolved : null;
}

/**
 * Restore public plugin trees when a later install step fails.
 *
 * The new public version stays registered for ordinary cleanup until commit,
 * so rollback only has to restore the stable path.
 *
 * @param array{target:string,kind:'absent'|'symlink'|'directory',version:string,releases:string,previous?:string,backup?:string} $swap
 */
function rollback_tree_swap(array $swap): bool
{
    $target = $swap['target'];
    if (installer_config_value('SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE') === 'tree_rollback') {
        error_log('runtime_engine_tree_rollback_failed:' . $target);
        return false;
    }
    if ($swap['kind'] === 'directory') {
        $backup = $swap['backup'] ?? '';
        if ($backup !== '' && !file_exists($backup) && !is_link($backup) && is_dir($target) && !is_link($target)) {
            return true;
        }
        if ($backup === '' || !is_dir($backup) || is_link($backup)) {
            error_log('runtime_engine_tree_rollback_failed:' . $target);
            return false;
        }
        if (is_link($target)) {
            if (!unlink_if_present($target)) {
                error_log('runtime_engine_tree_rollback_failed:' . $target);
                return false;
            }
        } elseif (is_dir($target)) {
            rrmdir($target);
            if (is_dir($target)) {
                error_log('runtime_engine_tree_rollback_failed:' . $target);
                return false;
            }
        }
        if (!rename($backup, $target)) {
            error_log('runtime_engine_tree_rollback_failed:' . $target);
            return false;
        }
        return sync_directory(dirname($target));
    }
    if ($swap['kind'] === 'absent') {
        if (is_link($target)) {
            if (!unlink_if_present($target)) {
                error_log('runtime_engine_tree_rollback_failed:' . $target);
                return false;
            }
        } elseif (is_dir($target)) {
            rrmdir($target);
        }
        return !file_exists($target)
            && !is_link($target)
            && sync_directory(dirname($target));
    }
    $temporary = $target . '.rollback.' . getmypid();
    unlink_if_present($temporary);
    if (!symlink((string) ($swap['previous'] ?? ''), $temporary) || !rename($temporary, $target)) {
        unlink_if_present($temporary);
        error_log('runtime_engine_tree_rollback_failed:' . $target);
        return false;
    }
    return is_link($target)
        && readlink($target) === ($swap['previous'] ?? null)
        && sync_directory(dirname($target));
}

function rollback_registered_tree_swaps(): bool
{
    $swaps = array_reverse($GLOBALS['spacefast_tree_swaps'] ?? []);
    $failed = [];
    foreach ($swaps as $swap) {
        if (!rollback_tree_swap($swap)) {
            $failed[] = $swap;
        }
    }
    $GLOBALS['spacefast_tree_swaps'] = array_reverse($failed);
    return $failed === [];
}

function preserve_registered_rollback_artifacts(): void
{
    foreach ($GLOBALS['spacefast_file_swaps'] ?? [] as $swap) {
        if (is_string($swap['backup'] ?? null)) {
            unregister_cleanup_path($swap['backup']);
        }
    }
    foreach ($GLOBALS['spacefast_tree_swaps'] ?? [] as $swap) {
        foreach (['backup', 'version'] as $key) {
            if (is_string($swap[$key] ?? null)) {
                unregister_cleanup_path($swap[$key]);
            }
        }
    }
    foreach ($GLOBALS['spacefast_public_versions'] ?? [] as $version) {
        unregister_cleanup_path($version);
    }
    $releaseRoot = $GLOBALS['spacefast_committed_release_root'] ?? null;
    if (is_string($releaseRoot)) {
        unregister_cleanup_path($releaseRoot);
    }
}

/** @param array{pointer:bool,trees:bool,files:bool} $result */
function write_rollback_failure_journal(string $reason, array $result): void
{
    $installRoot = $GLOBALS['spacefast_install_root'] ?? null;
    if (!is_string($installRoot) || !ensure_install_dir($installRoot)) {
        return;
    }
    $journal = $installRoot . '/rollback-failure.json';
    $payload = json_encode([
        'format' => 'spacefast.runtime.rollback-failure.v1',
        'reason' => $reason,
        'result' => $result,
        'release' => basename((string) ($GLOBALS['spacefast_committed_release_root'] ?? '')),
        'failed_file_targets' => array_values(array_map(
            fn (array $swap): string => $swap['target'],
            $GLOBALS['spacefast_file_swaps'] ?? [],
        )),
        'failed_tree_targets' => array_values(array_map(
            fn (array $swap): string => $swap['target'],
            $GLOBALS['spacefast_tree_swaps'] ?? [],
        )),
        'retained_backups' => array_values(array_filter(array_merge(
            array_map(fn (array $swap): ?string => $swap['backup'] ?? null, $GLOBALS['spacefast_file_swaps'] ?? []),
            array_map(fn (array $swap): ?string => $swap['backup'] ?? null, $GLOBALS['spacefast_tree_swaps'] ?? []),
        ), 'is_string')),
        'retained_public_versions' => array_values($GLOBALS['spacefast_public_versions'] ?? []),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || !publish_regular_file($journal, $payload . "\n", 0600)) {
        error_log('runtime_engine_rollback_journal_failed');
    }
}

function clear_rollback_failure_journal(string $installRoot): void
{
    $journal = $installRoot . '/rollback-failure.json';
    if (file_exists($journal) && !is_link($journal)) {
        unlink_if_present($journal);
    }
}

function install_transaction_release_root(string $installRoot, array $record): ?string
{
    $target = $record['release'] ?? null;
    $identity = $record['payload_identity'] ?? null;
    if (
        !is_string($target)
        || preg_match('#^releases/release-[0-9]+-[a-f0-9]{8}$#', $target) !== 1
        || !is_string($identity)
        || preg_match('/^[a-f0-9]{64}$/', $identity) !== 1
    ) {
        return null;
    }
    $releasesRoot = realpath($installRoot . '/releases');
    $release = $installRoot . '/' . $target;
    $resolved = realpath($release);
    $marker = is_string($resolved) ? $resolved . '/.payload-identity' : '';
    $storedIdentity = $marker !== '' && is_file($marker) && !is_link($marker)
        ? trim((string) file_get_contents($marker))
        : '';
    return is_string($releasesRoot)
        && is_string($resolved)
        && !is_link($release)
        && str_starts_with($resolved, $releasesRoot . '/')
        && hash_equals($identity, $storedIdentity)
        && installed_release_payload_matches($resolved)
            ? $resolved
            : null;
}

/** @return null|array<string,mixed> */
function read_install_transaction(string $installRoot): ?array
{
    $path = $installRoot . '/install-transaction.json';
    if (!file_exists($path) && !is_link($path)) {
        return null;
    }
    if (is_link($path) || !is_file($path)) {
        fail('runtime_engine_transaction_invalid');
    }
    $raw = file_get_contents($path, false, null, 0, 16384);
    $transaction = is_string($raw) ? json_decode($raw, true) : null;
    if (
        !is_array($transaction)
        || ($transaction['format'] ?? null) !== 'spacefast.runtime.install-transaction.v2'
        || !is_string($transaction['revision'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/', $transaction['loader_identity'] ?? '') !== 1
        || !is_array($transaction['recoveries'] ?? null)
        || count($transaction['recoveries']) > 32
        || install_transaction_release_root($installRoot, $transaction) === null
    ) {
        fail('runtime_engine_transaction_invalid');
    }
    foreach ($transaction['recoveries'] as $record) {
        if (!is_array($record) || install_transaction_release_root($installRoot, $record) === null) {
            fail('runtime_engine_transaction_invalid');
        }
    }
    return $transaction;
}

/** @return list<array{release:string,payload_identity:string}> */
function install_transaction_recovery_records(?array $transaction): array
{
    if (!is_array($transaction)) {
        return [];
    }
    $records = array_merge($transaction['recoveries'], [[
        'release' => $transaction['release'],
        'payload_identity' => $transaction['payload_identity'],
    ]]);
    $unique = [];
    foreach ($records as $record) {
        $unique[$record['release']] = $record;
    }
    return array_values($unique);
}

/** @return list<array{release:string,payload_identity:string}> */
function compact_install_transaction_recoveries(array $records, string $primaryIdentity): array
{
    $byIdentity = [];
    foreach ($records as $record) {
        $identity = $record['payload_identity'] ?? null;
        if (!is_string($identity) || hash_equals($primaryIdentity, $identity)) {
            continue;
        }
        // One verified release is enough to prove ownership for identical
        // public bytes. Scratch from every superseded suffix was removed under
        // the old WAL before this compacted record is published.
        $byIdentity[$identity] = $record;
    }
    $compacted = array_values($byIdentity);
    if (count($compacted) > 32) {
        fail('runtime_engine_recovery_history_full');
    }
    return $compacted;
}

/** @return list<array{path:string,kind:'file'|'link'}> */
function install_transaction_scratch_artifacts(
    string $installRoot,
    string $publicRoot,
    array $records,
): array {
    $artifacts = [];
    foreach ($records as $record) {
        $releaseRoot = install_transaction_release_root($installRoot, $record);
        $manifest = is_string($releaseRoot) ? installed_engine_manifest($releaseRoot) : null;
        $release = $record['release'] ?? null;
        if (!is_array($manifest) || !is_string($release)) {
            fail('runtime_engine_transaction_invalid');
        }
        $basename = basename($release);
        if (!str_starts_with($basename, 'release-')) {
            fail('runtime_engine_transaction_invalid');
        }
        $suffix = substr($basename, strlen('release-'));
        foreach ($manifest['alias'] as $entry) {
            $target = $publicRoot . '/' . $entry['path'];
            $artifacts[] = [
                'path' => dirname($target) . '/.spacefast-alias-' . $suffix . '-'
                    . substr(hash('sha256', $entry['path']), 0, 16),
                'kind' => 'file',
            ];
        }
        foreach ($manifest['trees'] as $tree) {
            $artifacts[] = [
                'path' => $publicRoot . '/' . $tree['path'] . '.next.' . $suffix,
                'kind' => 'link',
            ];
        }
    }
    return $artifacts;
}

function cleanup_install_transaction_scratch(
    string $installRoot,
    string $publicRoot,
    array $records,
): bool {
    $clean = true;
    foreach (install_transaction_scratch_artifacts($installRoot, $publicRoot, $records) as $artifact) {
        $path = $artifact['path'];
        if (!file_exists($path) && !is_link($path)) {
            continue;
        }
        $expectedKind = $artifact['kind'];
        if (
            ($expectedKind === 'file' && (!is_file($path) || is_link($path)))
            || ($expectedKind === 'link' && !is_link($path))
        ) {
            $clean = false;
            continue;
        }
        $clean = remove_managed_file($path) && $clean;
    }
    return $clean;
}

function cleanup_recovery_artifacts(string $publicRoot, array $owners, bool $recovering): bool
{
    $clean = true;
    foreach ($owners['aliases'] as $path => $pathOwners) {
        if (public_path_has_symlink_parent($publicRoot, $path)) {
            $clean = false;
            continue;
        }
        $target = $publicRoot . '/' . $path;
        foreach (glob($target . '.previous.*') ?: [] as $backup) {
            if (
                preg_match('/^' . preg_quote($target, '/') . '\.previous\.[0-9]+-[a-f0-9]{8}$/', $backup) === 1
                && public_alias_matches_owner($backup, $pathOwners)
            ) {
                $clean = remove_managed_file($backup) && $clean;
            }
        }
    }
    foreach ($owners['trees'] as $path => $pathOwners) {
        if (public_path_has_symlink_parent($publicRoot, $path)) {
            $clean = false;
            continue;
        }
        $target = $publicRoot . '/' . $path;
        foreach (glob($target . '.previous.*', GLOB_ONLYDIR) ?: [] as $backup) {
            if (
                preg_match('/^' . preg_quote($target, '/') . '\.previous\.[0-9]+-[a-f0-9]{8}$/', $backup) === 1
                && !is_link($backup)
                && public_tree_matches_owner($backup, $pathOwners)
            ) {
                $clean = remove_managed_tree($backup) && $clean;
            }
        }
        $store = dirname($target) . '/spacefast-tree-releases/' . basename($path);
        if (public_path_has_symlink_parent($publicRoot, dirname($path) . '/spacefast-tree-releases/' . basename($path))) {
            $clean = false;
            continue;
        }
        $resolvedStore = realpath($store);
        if (!is_string($resolvedStore) || is_link($store)) {
            continue;
        }
        $activeVersion = is_link($target) ? realpath($target) : false;
        foreach (glob($store . '/*', GLOB_ONLYDIR) ?: [] as $version) {
            $resolvedVersion = realpath($version);
            if (
                $recovering
                && !is_link($version)
                && preg_match('/^[a-f0-9]{64}-[0-9]+-[a-f0-9]{8}\.tmp$/', basename($version)) === 1
            ) {
                $clean = remove_managed_tree($version) && $clean;
                continue;
            }
            if (
                !is_string($resolvedVersion)
                || $resolvedVersion === $activeVersion
                || is_link($version)
                || !str_starts_with($resolvedVersion, $resolvedStore . '/')
                || preg_match('/^[a-f0-9]{64}-[0-9]+-[a-f0-9]{8}$/', basename($version)) !== 1
            ) {
                continue;
            }
            $clean = remove_managed_tree($version) && $clean;
        }
    }
    return $clean;
}

function cleanup_failed_transaction_release(string $installRoot, ?string $target, string $activeTarget): bool
{
    if ($target === null || $target === $activeTarget) {
        return true;
    }
    $release = $installRoot . '/' . $target;
    $releasesRoot = realpath($installRoot . '/releases');
    $resolved = realpath($release);
    if (
        is_string($releasesRoot)
        && is_string($resolved)
        && !is_link($release)
        && str_starts_with($resolved, $releasesRoot . '/')
        && release_authority($installRoot, $resolved) === null
        && installed_release_payload_matches($resolved)
    ) {
        return remove_managed_tree($resolved);
    }
    return !file_exists($release) && !is_link($release);
}

function rollback_install_transaction(string $reason): bool
{
    if (($GLOBALS['spacefast_install_phase'] ?? null) !== 'publishing') {
        return true;
    }
    if (is_bool($GLOBALS['spacefast_rollback_complete'] ?? null)) {
        return $GLOBALS['spacefast_rollback_complete'];
    }
    if (($GLOBALS['spacefast_recovery_in_progress'] ?? false) === true) {
        // The live paths may already contain residue from an earlier hard
        // crash. Treating that residue as this retry's rollback baseline would
        // clear the request gate while restoring a mixed release. Keep every
        // possible live artifact and require a later retry to converge fully.
        preserve_registered_rollback_artifacts();
        write_rollback_failure_journal($reason, [
            'pointer' => false,
            'trees' => false,
            'files' => false,
        ]);
        cleanup_registered_paths();
        $GLOBALS['spacefast_rollback_complete'] = false;
        return false;
    }
    $pointerRolledBack = rollback_install_pointer();
    $treesRolledBack = $pointerRolledBack && rollback_registered_tree_swaps();
    // Some legacy loaders still resolve code through the public tree. Do not
    // restore their files unless every tree first returned to the same release.
    $filesRolledBack = $treesRolledBack && rollback_registered_file_swaps();
    $complete = $pointerRolledBack && $treesRolledBack && $filesRolledBack;
    $installRoot = $GLOBALS['spacefast_install_root'] ?? null;
    if ($complete && is_string($installRoot)) {
        $complete = clear_regular_state_file($installRoot . '/install-transaction.json');
    }
    if (!$complete) {
        preserve_registered_rollback_artifacts();
        write_rollback_failure_journal($reason, [
            'pointer' => $pointerRolledBack,
            'trees' => $treesRolledBack,
            'files' => $filesRolledBack,
        ]);
    }
    cleanup_registered_paths();
    $GLOBALS['spacefast_rollback_complete'] = $complete;
    return $complete;
}

function commit_registered_tree_swaps(): bool
{
    $committed = true;
    $remaining = [];
    foreach ($GLOBALS['spacefast_tree_swaps'] ?? [] as $swap) {
        $backup = $swap['backup'] ?? null;
        if (is_string($backup) && !remove_managed_tree($backup)) {
            $remaining[] = $swap;
            $committed = false;
            continue;
        }
        $version = $swap['version'] ?? null;
        $releases = $swap['releases'] ?? null;
        if (is_string($version)) {
            unregister_cleanup_path($version);
        }
        if (is_string($version) && is_string($releases)) {
            prune_old_releases($releases, [realpath($version) ?: $version]);
        }
        $retiredVersion = $swap['retired_version'] ?? null;
        $retiredStore = $swap['retired_store'] ?? null;
        if (
            is_string($retiredVersion)
            && is_string($retiredStore)
            && !is_link($retiredStore)
            && !is_link($retiredVersion)
            && realpath($retiredVersion) === $retiredVersion
            && realpath($retiredStore) === $retiredStore
            && str_starts_with($retiredVersion, $retiredStore . '/')
        ) {
            if (!remove_managed_tree($retiredVersion)) {
                $remaining[] = $swap;
                $committed = false;
                continue;
            }
            if (is_dir($retiredStore)) {
                @rmdir($retiredStore);
                sync_directory(dirname($retiredStore));
            }
        }
    }
    $GLOBALS['spacefast_tree_swaps'] = $remaining;
    if ($committed) {
        $GLOBALS['spacefast_public_versions'] = [];
    }
    return $committed;
}

/** @param array{source:string,path:string,files:list<string>} $tree */
function publish_manifest_tree(
    string $releaseRoot,
    string $publicRoot,
    array $tree,
    string $identity,
    string $suffix,
    array $historicalOwners,
): void
{
    $source = $releaseRoot . '/' . $tree['source'];
    $resolvedSource = realpath($source);
    $resolvedRelease = realpath($releaseRoot);
    if (
        !is_string($resolvedSource)
        || !is_string($resolvedRelease)
        || !str_starts_with($resolvedSource, $resolvedRelease . '/')
    ) {
        fail('runtime_engine_tree_source_invalid:' . $tree['source']);
    }

    $target = $publicRoot . '/' . $tree['path'];
    $targetParent = ensure_public_directory($publicRoot, dirname($tree['path']));
    if (!is_string($targetParent)) {
        fail('runtime_engine_tree_mkdir_failed:' . $tree['path']);
    }

    // WordPress derives plugin asset URLs from resolved PHP paths. Keep the
    // immutable tree under the public plugin parent, then atomically swap a
    // stable relative link to it.
    $versionStore = ensure_public_directory(
        $publicRoot,
        dirname($tree['path']) . '/spacefast-tree-releases/' . basename($tree['path']),
    );
    if (!is_string($versionStore)) {
        fail('runtime_engine_tree_mkdir_failed:' . $tree['path']);
    }
    $relativeVersion = 'spacefast-tree-releases/' . basename($tree['path']) . '/' . $identity . '-' . $suffix;
    $version = $targetParent . '/' . $relativeVersion;
    $versionStage = $version . '.tmp';
    rrmdir($versionStage);
    register_cleanup_path($versionStage);
    copy_manifest_tree($resolvedSource, $versionStage);
    if (!sync_tree($versionStage)) {
        fail('runtime_engine_tree_sync_failed:' . $tree['path']);
    }
    register_cleanup_path($version);
    $GLOBALS['spacefast_public_versions'][] = $version;
    if (!rename($versionStage, $version)) {
        fail('runtime_engine_tree_commit_failed:' . $tree['path']);
    }
    if (!sync_directory($versionStore)) {
        fail('runtime_engine_tree_sync_failed:' . $tree['path']);
    }
    unregister_cleanup_path($versionStage);

    $temporary = $target . '.next.' . $suffix;
    if (file_exists($temporary) || is_link($temporary)) {
        fail('runtime_engine_tree_stage_collision:' . $tree['path']);
    }
    if (!symlink($relativeVersion, $temporary)) {
        fail('runtime_engine_tree_stage_failed:' . $tree['path']);
    }
    register_cleanup_path($temporary);

    if (is_link($target)) {
        $previous = readlink($target);
        if (!is_string($previous)) {
            fail('runtime_engine_tree_readlink_failed:' . $tree['path']);
        }
        $GLOBALS['spacefast_tree_swaps'][] = [
            'target' => $target,
            'kind' => 'symlink',
            'previous' => $previous,
            'version' => $version,
            'releases' => dirname($version),
        ];
    } elseif (is_dir($target)) {
        // One-time migration for boxes that predate tree-level publication.
        if (
            !public_tree_matches_owner($target, $historicalOwners['trees'][$tree['path']] ?? [])
            && !public_tree_matches_alias_owners($publicRoot, $tree['path'], $historicalOwners['aliases'])
        ) {
            fail('runtime_engine_tree_target_unowned:' . $tree['path']);
        }
        $backup = $target . '.previous.' . $suffix;
        rrmdir($backup);
        $GLOBALS['spacefast_tree_swaps'][] = [
            'target' => $target,
            'kind' => 'directory',
            'backup' => $backup,
            'version' => $version,
            'releases' => dirname($version),
        ];
        register_cleanup_path($backup);
        if (!rename($target, $backup)) {
            fail('runtime_engine_tree_backup_failed:' . $tree['path']);
        }
        if (!sync_directory(dirname($target))) {
            fail('runtime_engine_tree_sync_failed:' . $tree['path']);
        }
    } elseif (file_exists($target)) {
        fail('runtime_engine_tree_target_invalid:' . $tree['path']);
    } else {
        $GLOBALS['spacefast_tree_swaps'][] = [
            'target' => $target,
            'kind' => 'absent',
            'version' => $version,
            'releases' => dirname($version),
        ];
    }

    if (!rename($temporary, $target)) {
        fail('runtime_engine_tree_install_failed:' . $tree['path']);
    }
    if (!sync_directory(dirname($target))) {
        fail('runtime_engine_tree_sync_failed:' . $tree['path']);
    }
    unregister_cleanup_path($temporary);
    clearstatcache(true, $target);
    if (realpath($target) !== realpath($version)) {
        fail('runtime_engine_tree_verify_failed:' . $tree['path']);
    }
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
    $installRoot = dirname($releasesRoot);
    $cutoff = time() - 3600;
    foreach (glob($releasesRoot . '/release-*', GLOB_ONLYDIR) ?: [] as $release) {
        if (
            in_array($release, $keep, true)
            || !is_dir($release)
            || is_link($release)
            || release_authority($installRoot, $release) === null
            || (filemtime($release) ?: 0) >= $cutoff
        ) {
            continue;
        }
        rrmdir($release);
        if (!file_exists($release) && !is_link($release)) {
            clear_regular_state_file($installRoot . '/release-authorities/' . basename($release) . '.json');
        }
    }
}

/**
 * One update at a time per site; overlapping runs exit quietly.
 *
 * @return resource
 */
function prepare_install_root(string $publicRoot): string
{
    $installRoot = $publicRoot . '/.stattic';
    if (is_link($installRoot) || (file_exists($installRoot) && !is_dir($installRoot))) {
        fail('runtime_engine_install_root_invalid');
    }
    if (!ensure_durable_directory_entry($installRoot, 0755)) {
        fail('runtime_engine_install_root_invalid');
    }
    $resolved = realpath($installRoot);
    if (!is_string($resolved) || $resolved !== $installRoot) {
        fail('runtime_engine_install_root_invalid');
    }
    return $installRoot;
}

function acquire_installer_lock(string $installRoot)
{
    $lockPath = $installRoot . '/installer.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
        fail('runtime_installer_lock_unavailable');
    }
    $handle = fopen($lockPath, 'ce');
    if ($handle === false) {
        fail('runtime_installer_lock_unavailable');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        echo json_encode(['status' => 'busy'], JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    return $handle;
}

/** @return resource */
function acquire_publication_lock(string $installRoot)
{
    $lockPath = $installRoot . '/publication.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
        fail('runtime_engine_publication_lock_unavailable');
    }
    $handle = fopen($lockPath, 'ce');
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        fail('runtime_engine_publication_lock_unavailable');
    }
    return $handle;
}

function write_install_transaction(
    string $installRoot,
    string $newTarget,
    string $revision,
    string $payloadIdentity,
    string $loaderIdentity,
    array $recoveries,
): bool {
    $payload = json_encode([
        'format' => 'spacefast.runtime.install-transaction.v2',
        'release' => $newTarget,
        'revision' => $revision,
        'payload_identity' => $payloadIdentity,
        'loader_identity' => $loaderIdentity,
        'recoveries' => $recoveries,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($payload)
        && publish_regular_file($installRoot . '/install-transaction.json', $payload . "\n", 0600);
}

function clear_regular_state_file(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }
    return !is_link($path)
        && is_file($path)
        && unlink_if_present($path)
        && sync_directory(dirname($path));
}

function write_active_release_proof(
    string $installRoot,
    string $target,
    string $revision,
    string $payloadIdentity,
    string $loaderIdentity,
    string $nativeSha256,
): bool {
    $payload = json_encode([
        'format' => 'spacefast.runtime.active-release-proof.v1',
        'release' => $target,
        'revision' => $revision,
        'payload_identity' => $payloadIdentity,
        'loader_identity' => $loaderIdentity,
        'native_sha256' => $nativeSha256,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($payload)
        && publish_regular_file($installRoot . '/active-release-proof.json', $payload . "\n", 0600);
}

function active_release_proof_matches(
    string $installRoot,
    string $target,
    string $revision,
    string $payloadIdentity,
    string $loaderIdentity,
    string $nativeSha256,
): bool {
    $path = $installRoot . '/active-release-proof.json';
    $raw = is_file($path) && !is_link($path) ? file_get_contents($path, false, null, 0, 2048) : false;
    $proof = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($proof)
        && ($proof['format'] ?? null) === 'spacefast.runtime.active-release-proof.v1'
        && ($proof['release'] ?? null) === $target
        && ($proof['revision'] ?? null) === $revision
        && ($proof['payload_identity'] ?? null) === $payloadIdentity
        && ($proof['loader_identity'] ?? null) === $loaderIdentity
        && ($proof['native_sha256'] ?? null) === $nativeSha256;
}

function installer_roots(): array
{
    $configuredPublicRoot = installer_config_value('SPACEFAST_RUNTIME_PUBLIC_ROOT');
    if ($configuredPublicRoot !== '') {
        $resolvedPublicRoot = realpath($configuredPublicRoot);
        if (!is_string($resolvedPublicRoot) || !is_dir($resolvedPublicRoot)) {
            fail('runtime_public_root_invalid');
        }
        return [$resolvedPublicRoot . '/.stattic', $resolvedPublicRoot];
    }
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
$installRoot = prepare_install_root($publicRoot);
$installerLock = acquire_installer_lock($installRoot);
$GLOBALS['spacefast_install_phase'] = 'staging';
$recoveryTransaction = read_install_transaction($installRoot);
$recoveryRecords = install_transaction_recovery_records($recoveryTransaction);
$recoveryReleaseRoots = array_values(array_map(
    fn (array $record): string => (string) install_transaction_release_root($installRoot, $record),
    $recoveryRecords,
));
$recoveryReleaseTargets = array_values(array_map(
    fn (array $record): string => $record['release'],
    $recoveryRecords,
));
$GLOBALS['spacefast_recovery_in_progress'] = is_array($recoveryTransaction);
$GLOBALS['spacefast_installer_running'] = true;
register_shutdown_function(static function (): void {
    if (($GLOBALS['spacefast_install_phase'] ?? null) === 'publishing') {
        rollback_install_transaction('runtime_engine_install_interrupted');
    }
});
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, static fn (): never => fail('runtime_engine_install_interrupted'));
    }
}
sweep_stale_install_artifacts($privateRoot, $installRoot);
$incomingRoot = $installRoot . '/incoming';
if (
    is_link($incomingRoot)
    || (file_exists($incomingRoot) && !is_dir($incomingRoot))
    || (!is_dir($incomingRoot) && !mkdir($incomingRoot, 0700))
    || realpath($incomingRoot) !== $incomingRoot
    || !chmod($incomingRoot, 0700)
) {
    fail('runtime_engine_incoming_invalid');
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

// Keep malformed, missing, and corrupt regular targets in place until a fully
// staged replacement is ready. Links and special filesystem objects fail
// closed because publication cannot safely establish what they mean.
$installedPointer = active_release_pointer_state($installRoot);
if (in_array($installedPointer['state'], ['unsupported', 'unknown'], true)) {
    fail('runtime_engine_active_pointer_invalid');
}
if (
    is_link($installRoot . '/releases')
    || (file_exists($installRoot . '/releases') && !is_dir($installRoot . '/releases'))
) {
    fail('runtime_engine_releases_invalid');
}
$installedReleaseRoot = in_array($installedPointer['state'], ['valid', 'legacy'], true)
    ? ($installedPointer['root'] ?? null)
    : null;
$installedReleaseTarget = in_array($installedPointer['state'], ['valid', 'legacy'], true)
    ? ($installedPointer['target'] ?? null)
    : null;
$installedManifest = is_string($installedReleaseRoot) ? installed_engine_manifest($installedReleaseRoot) : null;
$installedReleasePayloadMatches = ($installedPointer['state'] ?? null) === 'valid'
    && is_string($installedReleaseRoot)
    && installed_release_payload_matches($installedReleaseRoot);
$installedReleaseLoaderIdentity = $installedReleasePayloadMatches && is_string($installedReleaseRoot)
    ? installed_release_loader_identity($installedReleaseRoot)
    : null;
$installedLoaderIdentity = installed_loader_identity($installRoot);
$installedPublicPayloadMatches = $installedReleasePayloadMatches
    && is_array($installedManifest)
    && is_string($installedReleaseLoaderIdentity)
    && $installedReleaseLoaderIdentity === $installedLoaderIdentity
    && installed_public_payload_matches($installedReleaseRoot, $publicRoot, $installedManifest);
$installedHistoricalOwners = historical_public_owners(
    $installRoot . '/releases',
    $publicRoot,
    $installedPointer,
    is_string($installedReleaseRoot) ? $installedReleaseRoot : null,
    $recoveryReleaseRoots,
);
$rollbackJournalPresent = is_file($installRoot . '/rollback-failure.json') || is_link($installRoot . '/rollback-failure.json');
$installTransactionPresent = is_file($installRoot . '/install-transaction.json') || is_link($installRoot . '/install-transaction.json');
$installedPayloadIdentity = is_string($installedReleaseRoot)
    ? trim((string) @file_get_contents($installedReleaseRoot . '/.payload-identity'))
    : '';
$installedAuthority = is_string($installedReleaseRoot)
    ? release_authority($installRoot, $installedReleaseRoot)
    : null;
if (
    $installedPublicPayloadMatches
    && !$rollbackJournalPresent
    && !$installTransactionPresent
    && $expectedNativeSha256 !== ''
    && $installedReleaseTarget !== null
    && is_string($installedReleaseRoot)
    && read_engine_revision($installedReleaseRoot) === $expectedRevision
    && installed_native_matches($installedReleaseRoot, $expectedNativeSha256)
    && retired_public_paths_absent($publicRoot, $installedManifest, $installedHistoricalOwners)
    && is_array($installedAuthority)
    && active_release_proof_matches(
        $installRoot,
        $installedReleaseTarget,
        $expectedRevision,
        $installedPayloadIdentity,
        $installedReleaseLoaderIdentity,
        $expectedNativeSha256,
    )
) {
    $GLOBALS['spacefast_installer_running'] = false;
    echo json_encode(['status' => 'current', 'engine_revision' => $expectedRevision, 'layout' => 'release'], JSON_PRETTY_PRINT) . "\n";
    if (defined('SPACEFAST_RUNTIME_INSTALLER_EMBEDDED') && SPACEFAST_RUNTIME_INSTALLER_EMBEDDED === true) {
        return;
    }
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
$loaderIdentity = loader_payload_identity($extractRoot, $manifest['alias'], $manifest['trees']);
if (!is_string($loaderIdentity)) {
    fail('runtime_engine_loader_identity_failed');
}
$installAliases = is_array($recoveryTransaction)
    || !$installedPublicPayloadMatches
    || $installedReleaseLoaderIdentity !== $loaderIdentity;

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

ensure_runtime_storage_dir($installRoot, 'storage');
ensure_runtime_storage_dir($installRoot, 'storage/runtime');
ensure_runtime_storage_dir($installRoot, 'storage/spaces');

$releasesRoot = $installRoot . '/releases';
if (is_link($releasesRoot) || !ensure_install_dir($releasesRoot)) {
    fail('runtime_engine_releases_unavailable');
}
$resolvedReleasesRoot = realpath($releasesRoot);
if (!is_string($resolvedReleasesRoot) || $resolvedReleasesRoot !== $releasesRoot) {
    fail('runtime_engine_releases_invalid');
}
$releaseRoot = $releasesRoot . '/release-' . $suffix;
register_cleanup_path($releaseRoot);
if (!rename($stageRoot, $releaseRoot)) {
    fail('runtime_engine_release_commit_failed');
}
$GLOBALS['spacefast_committed_release_root'] = $releaseRoot;
$newReleaseTarget = 'releases/' . basename($releaseRoot);
$previousReleasePointer = $installedPointer;
$previousReleaseRoot = is_string($installedReleaseRoot) ? $installedReleaseRoot : null;
$GLOBALS['spacefast_install_root'] = $installRoot;
$GLOBALS['spacefast_previous_release_pointer'] = $previousReleasePointer;
$GLOBALS['spacefast_new_release_target'] = $newReleaseTarget;

$releaseIdentity = release_payload_identity($releaseRoot);
$releaseIdentityMarker = $releaseRoot . '/.payload-identity';
if (
    !is_string($releaseIdentity)
    || !publish_regular_file($releaseIdentityMarker, $releaseIdentity . "\n", 0644)
    || !installed_release_payload_matches($releaseRoot)
) {
    fail('runtime_engine_release_identity_failed');
}
if ($expectedNativeSha256 !== '' && !installed_native_matches($releaseRoot, $expectedNativeSha256)) {
    fail('runtime_engine_native_sha256_mismatch');
}
if (!sync_tree($releaseRoot) || !sync_directory($releasesRoot)) {
    fail('runtime_engine_release_sync_failed');
}

$publicationLock = acquire_publication_lock($installRoot);
$recoveryScratchClean = cleanup_install_transaction_scratch(
    $installRoot,
    $publicRoot,
    $recoveryRecords,
);
if (!$recoveryScratchClean) {
    fail('runtime_engine_recovery_scratch_invalid');
}
$GLOBALS['spacefast_install_phase'] = 'publishing';
if (!write_install_transaction(
    $installRoot,
    $newReleaseTarget,
    $actualRevision,
    $releaseIdentity,
    $loaderIdentity,
    compact_install_transaction_recoveries($recoveryRecords, $releaseIdentity),
)) {
    fail('runtime_engine_transaction_write_failed');
}

$historicalOwners = historical_public_owners(
    $releasesRoot,
    $publicRoot,
    $previousReleasePointer,
    $releaseRoot,
    $recoveryReleaseRoots,
);
validate_public_path_transitions($publicRoot, $manifest, $historicalOwners);

foreach ($manifest['trees'] as $tree) {
    publish_manifest_tree(
        $releaseRoot,
        $publicRoot,
        $tree,
        $loaderIdentity,
        $suffix,
        $historicalOwners,
    );
    installer_test_pause('pause_after_first_tree');
}

if ($installAliases) {
    foreach ($manifest['alias'] as $entry) {
        $source = $releaseRoot . '/' . $entry['source'];
        $target = $publicRoot . '/' . $entry['path'];
        if (!is_string(ensure_public_directory($publicRoot, dirname($entry['path'])))) {
            fail('runtime_engine_alias_mkdir_failed:' . $entry['path']);
        }
        $tmp = dirname($target) . '/.spacefast-alias-' . $suffix . '-'
            . substr(hash('sha256', $entry['path']), 0, 16);
        if (!create_exclusive_regular_file($tmp)) {
            fail('runtime_engine_alias_copy_failed:' . $entry['path']);
        }
        register_cleanup_path($tmp);
        if (!copy($source, $tmp) || !chmod($tmp, $entry['executable'] ? 0755 : 0644)) {
            unlink_if_present($tmp);
            fail('runtime_engine_alias_copy_failed:' . $entry['path']);
        }
        if (!sync_regular_file($tmp)) {
            unlink_if_present($tmp);
            fail('runtime_engine_alias_sync_failed:' . $entry['path']);
        }
        register_file_swap($target, $suffix);
        if (!rename($tmp, $target)) {
            unlink_if_present($tmp);
            fail('runtime_engine_alias_install_failed:' . $entry['path']);
        }
        if (!sync_directory(dirname($target))) {
            fail('runtime_engine_alias_sync_failed:' . $entry['path']);
        }
        unregister_cleanup_path($tmp);
    }
}

// No opcache work here, deliberately. CLI opcache is a different SHM from
// FPM's, so invalidating it changes nothing a visitor sees (and wp.cloud has
// opcache.enable_cli off). FPM loads each release from
// a fresh path opcache has never seen, and the rewritten-in-place alias files
// are dropped from FPM's SHM by _stattic_engine_update_invalidate_aliases in
// the engine-update receipt route.
$treeFileCount = array_sum(array_map(
    fn (array $tree): int => count($tree['files']),
    $manifest['trees'],
));
$fileCount = count($manifest['staged']) + $treeFileCount + count($manifest['alias']);
$receipt = [
    'file_count' => $fileCount,
    'engine_revision' => $actualRevision,
    'layout' => 'release',
    'loader' => $installAliases ? 'installed' : 'current',
];

if ($installAliases) {
    $loaderMarker = $installRoot . '/loader-version';
    register_file_swap($loaderMarker, $suffix);
    if (!publish_regular_file($loaderMarker, $loaderIdentity . "\n", 0644)) {
        fail('runtime_engine_loader_marker_failed');
    }
}

$injectedPointerFailure = installer_config_value('SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE') === 'pointer_publication';
if ($injectedPointerFailure || !publish_active_release($installRoot, $newReleaseTarget)) {
    fail('runtime_engine_pointer_publication_failed');
}

remove_retired_public_paths($publicRoot, $manifest, $historicalOwners, $suffix);

$publishedPointer = active_release_pointer_state($installRoot);
$publishedReleaseRoot = ($publishedPointer['state'] ?? null) === 'valid' ? ($publishedPointer['root'] ?? null) : null;
$publishedManifest = is_string($publishedReleaseRoot) ? installed_engine_manifest($publishedReleaseRoot) : null;
$publishedLoaderIdentity = is_string($publishedReleaseRoot)
    ? installed_release_loader_identity($publishedReleaseRoot)
    : null;
$injectedPostcheckFailure = in_array(
    installer_config_value('SPACEFAST_RUNTIME_INSTALLER_TEST_FAILURE'),
    ['post_publication_check', 'tree_rollback', 'file_rollback'],
    true,
);
if (
    $injectedPostcheckFailure
    || $publishedReleaseRoot !== realpath($releaseRoot)
    || ($publishedPointer['target'] ?? null) !== $newReleaseTarget
    || !installed_release_payload_matches($publishedReleaseRoot ?? '')
    || $publishedLoaderIdentity !== $loaderIdentity
    || installed_loader_identity($installRoot) !== $loaderIdentity
    || !is_array($publishedManifest)
    || !installed_public_payload_matches($publishedReleaseRoot ?? '', $publicRoot, $publishedManifest)
    || !retired_public_paths_absent($publicRoot, $publishedManifest ?? ['alias' => [], 'trees' => []], $historicalOwners)
    || read_engine_revision($publishedReleaseRoot ?? '') !== $actualRevision
    || ($expectedNativeSha256 !== '' && !installed_native_matches($publishedReleaseRoot ?? '', $expectedNativeSha256))
) {
    $rolledBack = rollback_install_transaction('runtime_engine_post_publication_check_failed');
    echo json_encode([
        'status' => 'failed',
        'reason' => 'runtime_engine_post_publication_check_failed',
        'engine_revision' => $actualRevision,
        'rolled_back' => $rolledBack,
    ], JSON_PRETTY_PRINT) . "\n";
    fail('runtime_engine_post_publication_check_failed');
}

$GLOBALS['spacefast_install_phase'] = 'committed';
$GLOBALS['spacefast_installer_running'] = false;
if (function_exists('pcntl_signal')) {
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, SIG_DFL);
    }
}
unregister_cleanup_path($releaseRoot);
unset($GLOBALS['spacefast_committed_release_root']);
if (!write_release_authority(
    $installRoot,
    $releaseRoot,
    $actualRevision,
    $releaseIdentity,
    $loaderIdentity,
)) {
    fail('runtime_engine_release_authority_failed');
}
if (!write_active_release_proof(
    $installRoot,
    $newReleaseTarget,
    $actualRevision,
    $releaseIdentity,
    $loaderIdentity,
    $expectedNativeSha256,
)) {
    fail('runtime_engine_release_proof_failed');
}
$fileSwapsCommitted = commit_registered_file_swaps();
$treeSwapsCommitted = commit_registered_tree_swaps();
$committedOwners = historical_public_owners(
    $releasesRoot,
    $publicRoot,
    $publishedPointer,
    null,
    $recoveryReleaseRoots,
);
$recoveryArtifactsClean = cleanup_recovery_artifacts(
    $publicRoot,
    $committedOwners,
    is_array($recoveryTransaction),
);
$registeredPathsClean = cleanup_registered_paths();
$rollbackJournalCleared = clear_regular_state_file($installRoot . '/rollback-failure.json');
if (
    !$fileSwapsCommitted
    || !$treeSwapsCommitted
    || !$recoveryArtifactsClean
    || !$registeredPathsClean
    || !$rollbackJournalCleared
) {
    fail('runtime_engine_recovery_cleanup_failed');
}
// The durable transaction unlink is the publication commit point. Every
// release named by the WAL must remain intact until this succeeds, otherwise a
// kill can leave an unreadable WAL and keep visitor traffic gated forever.
if (!clear_regular_state_file($installRoot . '/install-transaction.json')) {
    fail('runtime_engine_recovery_cleanup_failed');
}
foreach ($recoveryReleaseTargets as $recoveryReleaseTarget) {
    cleanup_failed_transaction_release($installRoot, $recoveryReleaseTarget, $newReleaseTarget);
}
prune_old_releases($releasesRoot, array_values(array_filter([
    realpath($releaseRoot) ?: null,
    $previousReleaseRoot,
], 'is_string')));

echo json_encode(['status' => 'installed'] + $receipt, JSON_PRETTY_PRINT) . "\n";
