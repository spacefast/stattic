<?php
declare(strict_types=1);

require_once __DIR__ . '/safety.php';

const STATTIC_SERVER_FILE_PREFIX = '/.stattic/storage/';

function _stattic_server_file_uri(string $absolutePath, string $privateRoot): ?string
{
    $root = realpath($privateRoot);
    $path = realpath($absolutePath);
    if (!is_string($root) || !is_string($path) || !is_file($path)) {
        return null;
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    $prefix = $root . '/';
    if ($path === $root || !str_starts_with($path, $prefix)) {
        return null;
    }

    $segments = explode('/', substr($path, strlen($prefix)));
    if (!in_array($segments[0] ?? null, ['runtime', 'spaces'], true)) {
        return null;
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || _stattic_path_is_php_like($segment)) {
            return null;
        }
    }

    return STATTIC_SERVER_FILE_PREFIX
        . implode('/', array_map('rawurlencode', $segments));
}

// False means the caller must use its PHP body fallback. A successful handoff
// terminates after Nginx takes ownership of bytes, validators, Range and HEAD.
function _stattic_send_server_file(
    string $absolutePath,
    string $privateRoot,
    array $headers,
    int $status = 200,
    ?callable $sendHeaders = null
): bool {
    // X-Accel-Redirect is a FastCGI response contract. CLI and php -S have no
    // upstream server to consume it, so they keep the ordinary PHP body path.
    if (PHP_SAPI !== 'fpm-fcgi') {
        return false;
    }

    $uri = _stattic_server_file_uri($absolutePath, $privateRoot);
    if ($uri === null) {
        return false;
    }

    if ($sendHeaders !== null) {
        $sendHeaders($headers);
    } else {
        header_remove('Pragma');
        header_remove('Expires');
        foreach ($headers as $name => $value) {
            header_remove((string) $name);
            header((string) $name . ': ' . (string) $value);
        }
    }
    http_response_code($status);
    header('X-Accel-Redirect: ' . $uri, true);
    exit;
}
