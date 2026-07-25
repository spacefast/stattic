<?php
declare(strict_types=1);

// GENERATED FILE — DO NOT EDIT.
// Source of truth: packages/common/src/utils/static-runtime-policy.ts
// Regenerate: bun --filter @spacefast/control-plane runtime:codegen-policy
// Parity is enforced by static-runtime-policy.fixtures.json.

const SPACEFAST_PHP_LIKE_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar'];
const SPACEFAST_NON_IMMUTABLE_EXTENSIONS = ['html' => true, 'htm' => true, 'php' => true, 'txt' => true, 'xml' => true];
// Build-output dirs where the framework guarantees every emitted asset is content-hashed
// (Next `_next/static/`, SvelteKit `_app/immutable/`, Nuxt `_nuxt/`, Astro `_astro/`).
// Anything under these is immutable regardless of filename. These prefixes and user
// `_headers` Cache-Control rules are the ONLY immutable signals — there is deliberately
// no filename-entropy guessing (bundler hashes and short human names are
// indistinguishable; see the TS source of truth for the full rationale).
const SPACEFAST_GUARANTEED_IMMUTABLE_PREFIXES = ['_next/static/', '_app/immutable/', '_nuxt/', '_astro/'];

// Rejected/platform-managed response headers: user _headers rules can never set or
// remove these. The X-Accel-Redirect/X-Sendfile family is here because under
// nginx+php-fpm those headers are a server-side file-disclosure primitive. The native
// Rust compiler filters them at publish; the runtime refuses them again at artifact
// validation and apply time.
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
    'location' => true,
    'netlify-cdn-cache-control' => true,
    'server' => true,
    'set-cookie' => true,
    'strict-transport-security' => true,
    'surrogate-control' => true,
    'trailer' => true,
    'transfer-encoding' => true,
    'upgrade' => true,
    'vary' => true,
    'x-accel-buffering' => true,
    'x-accel-redirect' => true,
    'x-lighttpd-send-file' => true,
    'x-sendfile' => true,
];

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

function _stattic_safe_content_type(string $path, string $mime): string
{
    if (!_stattic_path_is_php_like($path)) {
        return $mime;
    }
    return _stattic_policy_extension($path) === 'phar'
        ? 'application/octet-stream'
        : 'text/plain; charset=utf-8';
}

function _stattic_runtime_is_under_guaranteed_immutable_prefix(string $path): bool
{
    $normalized = $path !== '' && $path[0] === '/' ? substr($path, 1) : $path;
    foreach (SPACEFAST_GUARANTEED_IMMUTABLE_PREFIXES as $prefix) {
        if (str_starts_with($normalized, $prefix)) {
            return true;
        }
    }
    return false;
}

function _stattic_runtime_is_immutable_asset_path(string $path): bool
{
    if (isset(SPACEFAST_NON_IMMUTABLE_EXTENSIONS[_stattic_policy_extension($path)])) {
        return false;
    }
    // Path-convention layer: assets under a guaranteed-content-hashed framework build
    // dir are immutable regardless of filename. Extension gate above still applies.
    return _stattic_runtime_is_under_guaranteed_immutable_prefix($path);
}
