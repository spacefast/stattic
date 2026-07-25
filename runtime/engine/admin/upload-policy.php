<?php
declare(strict_types=1);

function _stattic_static_upload_path_violation(string $path): ?array
{
    static $runtimeEngineFiles = null;
    if ($runtimeEngineFiles === null) {
        $runtimeEngineFiles = _stattic_runtime_engine_file_map();
    }
    if (isset($runtimeEngineFiles['*'])) {
        return _stattic_static_path_violation('runtime_engine_manifest_missing', 'Runtime engine manifest is missing.', $path);
    }

    $original = $path;
    if (
        $original === ''
        || trim($original) !== $original
        || str_starts_with($original, '/')
        || str_contains($original, '\\')
        || str_contains($original, '//')
        || str_contains($original, "\0")
    ) {
        return _stattic_static_path_violation('invalid_file_path', 'File path is invalid.', $original);
    }

    $segments = explode('/', $original);
    $normalizedSegments = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
            return _stattic_static_path_violation('invalid_file_path', 'File path is invalid.', $original);
        }
        $normalizedSegments[] = $segment;
    }
    $normalizedPath = implode('/', $normalizedSegments);
    if ($normalizedPath === '') {
        return _stattic_static_path_violation('invalid_file_path', 'File path is invalid.', $original);
    }

    $lowerPath = strtolower($normalizedPath);
    $lowerSegments = explode('/', $lowerPath);
    $name = basename($lowerPath);
    if ($name === '.htaccess' || $name === '.user.ini') {
        return _stattic_static_path_violation('static_control_file_not_supported', 'Static deploys cannot upload execution-control files.', $normalizedPath);
    }
    if (count($lowerSegments) === 1 && isset($runtimeEngineFiles[$name])) {
        return _stattic_static_path_violation('static_runtime_control_path_not_supported', 'Static deploys cannot upload runtime control paths.', $normalizedPath);
    }
    foreach ($lowerSegments as $segment) {
        if (str_starts_with($segment, '__spacefast') || str_starts_with($segment, '__stattic')) {
            return _stattic_static_path_violation('static_runtime_control_path_not_supported', 'Static deploys cannot upload runtime control paths.', $normalizedPath);
        }
    }
    // Both the current and the legacy control-path spellings stay denied (parity
    // with packages/common/src/utils/publish-policy.ts): already-published
    // spaces still serve from the legacy `.stattic/...` layout.
    if (
        $lowerPath === '.spacefast/storage' || str_starts_with($lowerPath, '.spacefast/storage/')
        || $lowerPath === '.spacefast/engine' || str_starts_with($lowerPath, '.spacefast/engine/')
        || $lowerPath === '.stattic/storage' || str_starts_with($lowerPath, '.stattic/storage/')
        || $lowerPath === '.stattic/engine' || str_starts_with($lowerPath, '.stattic/engine/')
        || $lowerPath === '.well-known/spacefast-runtime'
        || $lowerPath === '.well-known/stattic-runtime'
    ) {
        return _stattic_static_path_violation('static_runtime_control_path_not_supported', 'Static deploys cannot upload runtime control paths.', $normalizedPath);
    }

    return null;
}

function _stattic_runtime_engine_file_map(): array
{
    $manifestPath = __DIR__ . '/../../engine-manifest.json';
    if (!is_file($manifestPath)) {
        return ['*' => true];
    }
    $raw = file_get_contents($manifestPath);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
        return ['*' => true];
    }
    $files = [];
    foreach ($decoded['files'] as $file) {
        if (is_string($file) && $file !== '' && strtolower(basename($file)) !== 'index.php') {
            $files[strtolower(basename($file))] = true;
        }
    }
    foreach (($decoded['aliases'] ?? []) as $alias) {
        if (!is_array($alias)) {
            continue;
        }
        foreach (['source', 'path'] as $key) {
            $file = $alias[$key] ?? null;
            if (is_string($file) && $file !== '' && strtolower(basename($file)) !== 'index.php') {
                $files[strtolower(basename($file))] = true;
            }
        }
    }
    $files['installer.php'] = true;
    return $files;
}

function _stattic_static_path_violation(string $code, string $message, string $path): array
{
    return [
        'code' => $code,
        'message' => $message,
        'path' => $path,
    ];
}

function _stattic_runtime_assert_static_upload_path(string $path): void
{
    $violation = _stattic_static_upload_path_violation($path);
    if (is_array($violation)) {
        _stattic_json_response(422, [
            'error' => [
                'code' => $violation['code'],
                'message' => $violation['message'],
                'details' => ['path' => $violation['path']],
            ],
        ]);
    }
}
