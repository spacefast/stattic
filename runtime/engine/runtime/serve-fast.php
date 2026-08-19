<?php
declare(strict_types=1);

// The visitor lane's entry: only dependency-light modules belong here.
require_once __DIR__ . '/../shared/pointers.php';
require_once __DIR__ . '/../shared/finalizer-protocol.generated.php';
require_once __DIR__ . '/../shared/server-file.php';

function _sf_serve_fast(
    string $privateRoot,
    string $requestMethod,
    string $requestUri,
    string $requestPath,
    string $requestHost
): never {
    _sf_load_generated_config($privateRoot);
    require_once __DIR__ . '/serve.php';
    _stattic_serve_request($privateRoot, $requestMethod, $requestUri, $requestPath, $requestHost);
    exit;
}

function _sf_load_generated_config(string $privateRoot): void
{
    static $attempted = false;
    if ($attempted) {
        return;
    }
    $attempted = true;
    $path = $privateRoot . '/config.generated.php';
    if (is_file($path)) {
        require_once $path;
    }
}

function _sf_cache_control(string $cacheClass, bool $open): string
{
    if (!$open) {
        return STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE;
    }
    return match ($cacheClass) {
        // A document URL changes when its live route moves. Keep it
        // revalidating: provider purges accelerate convergence, but correctness
        // must not depend on every Accept-Encoding cache variant being evicted.
        STATTIC_RUNTIME_CACHE_CLASS_HTML => 'public, max-age=0, must-revalidate',
        STATTIC_RUNTIME_CACHE_CLASS_IMMUTABLE => 'public, max-age=31536000, immutable',
        default => 'public, max-age=0, must-revalidate',
    };
}

function _sf_promote_blob(string $privateRoot, string $spaceId, string $blobRelativePath): string
{
    require_once __DIR__ . '/tier.php';
    $localPath = _stattic_tier_promote_blob($privateRoot, $spaceId, basename($blobRelativePath));
    if (!is_string($localPath) || $localPath === '') {
        require_once __DIR__ . '/../shared/errors.php';
        _stattic_render_tier_fetch_unavailable(5);
        exit;
    }
    return $localPath;
}

// One header seam owns publisher filtering and wp.cloud edge activation for
// both PHP bodies and server-file handoffs.
function _sf_send_headers(array $headers): void
{
    header_remove('Pragma');
    header_remove('Expires');
    $headers = _stattic_apply_platform_header_policy($headers);
    foreach ($headers as $name => $value) {
        if (!is_string($name) || !is_scalar($value)) {
            continue;
        }
        if (strtolower($name) === 'set-cookie') {
            header($name . ': ' . (string) $value, false);
            continue;
        }
        header_remove($name);
        header($name . ': ' . (string) $value);
    }
}
