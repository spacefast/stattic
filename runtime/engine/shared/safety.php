<?php
declare(strict_types=1);

// GENERATED FILE — DO NOT EDIT.
// Source of truth: packages/common/src/utils/static-runtime-policy.ts
// Regenerate: bun --filter @spacefast/control-plane runtime:codegen-policy
// Parity is enforced by static-runtime-policy.fixtures.json.

const SPACEFAST_PHP_LIKE_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar'];
const SPACEFAST_NON_IMMUTABLE_EXTENSIONS = ['html' => true, 'htm' => true, 'php' => true, 'txt' => true, 'xml' => true];
// Rejected/platform-managed response headers: user _headers rules can never set or
// remove these. The X-Accel-Redirect/X-Sendfile family is here because under
// nginx+php-fpm those headers are a server-side file-disclosure primitive. The
// `x-spacefast-`/`x-stattic-` prefixes are the runtime's own response signature
// (X-Spacefast-Runtime, X-Spacefast-Version, …) and can never be forged by user rules.
// The compilers reject both at publish; the runtime refuses them again at artifact
// validation and apply time. Always ask _stattic_platform_managed_header() — the
// literal map alone is only half the policy.
const SPACEFAST_PLATFORM_MANAGED_HEADERS = [
    'accept-ranges' => true,
    'age' => true,
    'allow' => true,
    'alt-svc' => true,
    'cdn-cache-control' => true,
    'cloudflare-cdn-cache-control' => true,
    'connection' => true,
    'content-encoding' => true,
    'content-length' => true,
    'content-range' => true,
    'cookie' => true,
    'date' => true,
    'host' => true,
    'keep-alive' => true,
    'location' => true,
    'netlify-cdn-cache-control' => true,
    'proxy-authenticate' => true,
    'proxy-authorization' => true,
    'server' => true,
    'set-cookie' => true,
    'strict-transport-security' => true,
    'surrogate-control' => true,
    'te' => true,
    'trailer' => true,
    'transfer-encoding' => true,
    'upgrade' => true,
    'vary' => true,
    'x-accel-buffering' => true,
    'x-accel-charset' => true,
    'x-accel-expires' => true,
    'x-accel-limit-rate' => true,
    'x-accel-redirect' => true,
    'x-lighttpd-send-file' => true,
    'x-lighttpd-sendfile' => true,
    'x-lighttpd-sendfile2' => true,
    'x-reproxy-url' => true,
    'x-sendfile' => true,
];
const SPACEFAST_PLATFORM_MANAGED_HEADER_PREFIXES = ['x-spacefast-', 'x-stattic-'];

// The internal-redirect/sendfile subset of the map above: headers that make the web
// server produce a different response instead of describing this one. The proxy relay
// (runtime/runtime/proxy.php) drops exactly these from an untrusted upstream while still
// relaying ordinary response headers the managed map also lists (location,
// content-encoding, content-range, allow).
const SPACEFAST_INTERNAL_REDIRECT_HEADERS = [
    'x-accel-buffering' => true,
    'x-accel-charset' => true,
    'x-accel-expires' => true,
    'x-accel-limit-rate' => true,
    'x-accel-redirect' => true,
    'x-lighttpd-send-file' => true,
    'x-lighttpd-sendfile' => true,
    'x-lighttpd-sendfile2' => true,
    'x-reproxy-url' => true,
    'x-sendfile' => true,
];

function _stattic_platform_managed_header(string $name): bool
{
    $lower = strtolower(trim($name));
    if (isset(SPACEFAST_PLATFORM_MANAGED_HEADERS[$lower])) {
        return true;
    }
    foreach (SPACEFAST_PLATFORM_MANAGED_HEADER_PREFIXES as $prefix) {
        if (str_starts_with($lower, $prefix)) {
            return true;
        }
    }
    return false;
}

function _stattic_policy_extension(string $path): string
{
    $slash = strrpos($path, '/');
    $base = $slash === false ? $path : substr($path, $slash + 1);
    $dot = strrpos($base, '.');
    return $dot !== false && $dot > 0 ? strtolower(substr($base, $dot + 1)) : '';
}

function _stattic_path_is_php_like(string $path): bool
{
    return in_array(_stattic_policy_extension($path), SPACEFAST_PHP_LIKE_EXTENSIONS, true);
}

