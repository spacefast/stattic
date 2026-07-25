<?php
declare(strict_types=1);

const STATTIC_BUNDLE_FORMAT = 'stattic.bundle.v1';
const STATTIC_PORTABLE_STATIC_PROFILE = 'portable-static';
const STATTIC_BUNDLE_DESCRIPTOR_MAX_BYTES = 4 * 1024 * 1024;
const STATTIC_BUNDLE_FILE_LIMIT = 100000;

/**
 * Verify and atomically admit a prebuilt bundle without invoking Rust or any
 * build process. Spacefast and self-hosted runtimes share this boundary.
 *
 * @return array<string,mixed>
 */
function stattic_admit_bundle(string $bundleRoot, string $storageRoot): array
{
    $bundleRoot = realpath($bundleRoot) ?: '';
    if ($bundleRoot === '' || !is_dir($bundleRoot)) {
        throw new RuntimeException('bundle_root_invalid');
    }
    $descriptorPath = $bundleRoot . '/bundle.json';
    $descriptorSize = is_file($descriptorPath) ? filesize($descriptorPath) : false;
    if (!is_int($descriptorSize) || $descriptorSize < 2 || $descriptorSize > STATTIC_BUNDLE_DESCRIPTOR_MAX_BYTES) {
        throw new RuntimeException('bundle_descriptor_invalid');
    }
    try {
        $descriptor = json_decode(
            (string) file_get_contents($descriptorPath),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RuntimeException('bundle_descriptor_invalid');
    }
    if (!is_array($descriptor)) {
        throw new RuntimeException('bundle_descriptor_invalid');
    }
    stattic_validate_descriptor($descriptor);

    $payloadRoot = realpath($bundleRoot . '/payload') ?: '';
    if ($payloadRoot === '' || !is_dir($payloadRoot) || !str_starts_with($payloadRoot, $bundleRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('bundle_payload_missing');
    }
    $verifiedArtifacts = stattic_verify_payload($payloadRoot, $descriptor['artifacts']);
    $contentDigest = stattic_content_digest($verifiedArtifacts);
    if (!hash_equals((string) $descriptor['contentDigest'], $contentDigest)) {
        throw new RuntimeException('bundle_content_digest_mismatch');
    }
    $deploymentDigest = stattic_deployment_digest(
        (string) $descriptor['profile'],
        $contentDigest,
        is_string($descriptor['bindingDigest'] ?? null) ? $descriptor['bindingDigest'] : null
    );
    if (!hash_equals((string) $descriptor['deploymentDigest'], $deploymentDigest)) {
        throw new RuntimeException('bundle_deployment_digest_mismatch');
    }

    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0700, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('runtime_storage_unavailable');
    }
    $storageRoot = realpath($storageRoot) ?: '';
    if ($storageRoot === '') {
        throw new RuntimeException('runtime_storage_unavailable');
    }
    $spaceId = (string) $descriptor['spaceId'];
    $versionId = (string) $descriptor['versionId'];
    $versionsRoot = $storageRoot . '/spaces/' . $spaceId . '/versions';
    if (!is_dir($versionsRoot) && !mkdir($versionsRoot, 0700, true) && !is_dir($versionsRoot)) {
        throw new RuntimeException('runtime_storage_unavailable');
    }
    $versionRoot = $versionsRoot . '/' . $versionId;
    if (is_dir($versionRoot)) {
        $existing = stattic_read_receipt($versionRoot . '/bundle-receipt.json');
        if (is_array($existing) && hash_equals(
            (string) ($existing['deploymentDigest'] ?? ''),
            $deploymentDigest
        )) {
            return [
                'status' => 'already_admitted',
                'spaceId' => $spaceId,
                'versionId' => $versionId,
                'contentDigest' => $contentDigest,
                'deploymentDigest' => $deploymentDigest,
                'serverBuildRequired' => false,
                'rustFinalizerRequired' => false,
            ];
        }
        throw new RuntimeException('version_existing_mismatch');
    }

    $stageRoot = $versionsRoot . '/.' . $versionId . '.admitting-' . getmypid() . '-' . bin2hex(random_bytes(6));
    try {
        if (!mkdir($stageRoot, 0700, true) && !is_dir($stageRoot)) {
            throw new RuntimeException('runtime_storage_unavailable');
        }
        foreach ($verifiedArtifacts as $artifact) {
            $path = $artifact['path'];
            $source = $payloadRoot . '/' . $path;
            $target = $stageRoot . '/' . $path;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('runtime_storage_unavailable');
            }
            if (!copy($source, $target)) {
                throw new RuntimeException('bundle_copy_failed');
            }
            $copiedSize = filesize($target);
            $copiedSha = hash_file('sha256', $target);
            if (!is_int($copiedSize)
                || $copiedSize !== $artifact['size']
                || !is_string($copiedSha)
                || !hash_equals($artifact['sha256'], $copiedSha)
            ) {
                throw new RuntimeException('bundle_copy_verification_failed');
            }
        }
        $receipt = [
            'format' => 'stattic.runtime.admission-receipt.v1',
            'profile' => $descriptor['profile'],
            'spaceId' => $spaceId,
            'versionId' => $versionId,
            'contentDigest' => $contentDigest,
            'bindingDigest' => $descriptor['bindingDigest'],
            'deploymentDigest' => $deploymentDigest,
            'serverBuildRequired' => false,
            'rustFinalizerRequired' => false,
            'admittedAt' => gmdate('c'),
        ];
        stattic_write_json($stageRoot . '/bundle-receipt.json', $receipt);
        if (!rename($stageRoot, $versionRoot)) {
            throw new RuntimeException('bundle_admission_commit_failed');
        }
    } catch (Throwable $error) {
        stattic_remove_tree($stageRoot);
        throw $error;
    }

    return [
        'status' => 'admitted',
        'spaceId' => $spaceId,
        'versionId' => $versionId,
        'contentDigest' => $contentDigest,
        'deploymentDigest' => $deploymentDigest,
        'serverBuildRequired' => false,
        'rustFinalizerRequired' => false,
    ];
}

/** @param array<string,mixed> $descriptor */
function stattic_validate_descriptor(array $descriptor): void
{
    if (($descriptor['format'] ?? null) !== STATTIC_BUNDLE_FORMAT) {
        throw new RuntimeException('bundle_format_invalid');
    }
    if (($descriptor['profile'] ?? null) !== STATTIC_PORTABLE_STATIC_PROFILE) {
        throw new RuntimeException('bundle_profile_invalid');
    }
    stattic_validate_id($descriptor['spaceId'] ?? null, 'space_id');
    stattic_validate_id($descriptor['versionId'] ?? null, 'version_id');
    if (!is_string($descriptor['contentDigest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/', $descriptor['contentDigest']) !== 1
        || !is_string($descriptor['deploymentDigest'] ?? null)
        || preg_match('/^sha256:[a-f0-9]{64}$/', $descriptor['deploymentDigest']) !== 1
        || ($descriptor['bindingDigest'] ?? null) !== null
    ) {
        throw new RuntimeException('bundle_digest_invalid');
    }
    $requirements = $descriptor['requirements'] ?? null;
    if (!is_array($requirements)
        || ($requirements['runtimeAbi'] ?? null) !== 'static-runtime-v2'
        || ($requirements['serverBuild'] ?? null) !== false
        || ($requirements['rustFinalizer'] ?? null) !== false
        || ($requirements['zeroRunner'] ?? null) !== false
    ) {
        throw new RuntimeException('bundle_requirements_invalid');
    }
    if (!is_array($descriptor['artifacts'] ?? null)
        || count($descriptor['artifacts']) > STATTIC_BUNDLE_FILE_LIMIT
    ) {
        throw new RuntimeException('bundle_artifact_manifest_invalid');
    }
}

/**
 * @param mixed $manifest
 * @return list<array{path:string,size:int,sha256:string}>
 */
function stattic_verify_payload(string $payloadRoot, mixed $manifest): array
{
    if (!is_array($manifest)) {
        throw new RuntimeException('bundle_artifact_manifest_invalid');
    }
    $declared = [];
    foreach ($manifest as $artifact) {
        if (!is_array($artifact)
            || !is_string($artifact['path'] ?? null)
            || !is_int($artifact['size'] ?? null)
            || $artifact['size'] < 0
            || !is_string($artifact['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $artifact['sha256']) !== 1
        ) {
            throw new RuntimeException('bundle_artifact_manifest_invalid');
        }
        $path = $artifact['path'];
        stattic_validate_path($path);
        if (isset($declared[$path])) {
            throw new RuntimeException('bundle_artifact_manifest_invalid');
        }
        $declared[$path] = [
            'path' => $path,
            'size' => $artifact['size'],
            'sha256' => $artifact['sha256'],
        ];
    }

    $actual = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($payloadRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || !$entry->isFile()) {
            throw new RuntimeException('bundle_payload_entry_unsupported');
        }
        $absolute = $entry->getPathname();
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($payloadRoot) + 1));
        stattic_validate_path($path);
        $size = $entry->getSize();
        $sha = hash_file('sha256', $absolute);
        if (!is_string($sha)) {
            throw new RuntimeException('bundle_payload_unreadable');
        }
        $actual[$path] = ['path' => $path, 'size' => $size, 'sha256' => $sha];
    }
    ksort($declared, SORT_STRING);
    ksort($actual, SORT_STRING);
    if ($declared !== $actual) {
        throw new RuntimeException('bundle_artifact_manifest_mismatch');
    }
    return array_values($actual);
}

/** @param list<array{path:string,size:int,sha256:string}> $artifacts */
function stattic_content_digest(array $artifacts): string
{
    usort($artifacts, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    $context = hash_init('sha256');
    hash_update($context, "stattic.bundle.content.v1\0");
    foreach ($artifacts as $artifact) {
        hash_update($context, $artifact['path'] . "\0");
        hash_update($context, (string) $artifact['size'] . "\0");
        hash_update($context, $artifact['sha256'] . "\0");
    }
    return 'sha256:' . hash_final($context);
}

function stattic_deployment_digest(string $profile, string $contentDigest, ?string $bindingDigest): string
{
    $context = hash_init('sha256');
    hash_update($context, "stattic.bundle.deployment.v1\0");
    hash_update($context, $profile . "\0");
    hash_update($context, $contentDigest . "\0");
    hash_update($context, ($bindingDigest ?? ''));
    return 'sha256:' . hash_final($context);
}

function stattic_validate_id(mixed $value, string $field): void
{
    if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $value) !== 1) {
        throw new RuntimeException('bundle_' . $field . '_invalid');
    }
}

function stattic_validate_path(string $path): void
{
    if ($path === ''
        || strlen($path) > 4096
        || str_contains($path, "\0")
        || str_contains($path, '\\')
        || str_starts_with($path, '/')
    ) {
        throw new RuntimeException('bundle_artifact_path_invalid');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('bundle_artifact_path_invalid');
        }
    }
}

/** @return array<string,mixed>|null */
function stattic_read_receipt(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    try {
        $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($value) ? $value : null;
}

/** @param array<string,mixed> $value */
function stattic_write_json(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('runtime_storage_unavailable');
    }
}

function stattic_remove_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
}

function stattic_admit_main(array $arguments): int
{
    $bundle = null;
    $storage = null;
    for ($index = 1; $index < count($arguments); $index++) {
        if ($arguments[$index] === '--help' || $arguments[$index] === '-h') {
            fwrite(STDOUT, "Usage: php runtime/bin/admit.php --bundle <bundle-directory> --storage <runtime-storage>\n");
            return 0;
        } elseif ($arguments[$index] === '--bundle') {
            $bundle = $arguments[++$index] ?? null;
        } elseif ($arguments[$index] === '--storage') {
            $storage = $arguments[++$index] ?? null;
        } else {
            fwrite(STDERR, "Unknown argument: {$arguments[$index]}\n");
            return 2;
        }
    }
    if (!is_string($bundle) || !is_string($storage)) {
        fwrite(STDERR, "Usage: php runtime/bin/admit.php --bundle <bundle-directory> --storage <runtime-storage>\n");
        return 2;
    }
    try {
        fwrite(STDOUT, json_encode(stattic_admit_bundle($bundle, $storage), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, "stattic-runtime: {$error->getMessage()}\n");
        return 1;
    }
}

if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    exit(stattic_admit_main($argv));
}
