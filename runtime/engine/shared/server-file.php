<?php
declare(strict_types=1);

require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/response.php';

const STATTIC_SERVER_FILE_PREFIX = '/.stattic/storage/';

function _stattic_server_file_uri(string $absolutePath, string $privateRoot): ?string
{
    // The resolved private root cannot change within a worker: memoize the
    // successful answer, this runs on every X-Accel response.
    static $roots = [];
    $root = $roots[$privateRoot] ?? null;
    if (!is_string($root)) {
        $root = realpath($privateRoot);
        if (is_string($root)) {
            $roots[$privateRoot] = $root;
        }
    }
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
    int $status = 200
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

    _stattic_send_response_headers($headers);
    http_response_code($status);
    header('X-Accel-Redirect: ' . $uri, true);
    exit;
}
