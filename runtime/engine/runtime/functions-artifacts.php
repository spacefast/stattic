<?php

/**
 * Signed reads of a version's compiled worker bundle:
 * /__spacefast/functions/b/<sha256hex>/<jwt>/bundle.json on disk at
 * __spacefast/functions/bundles/<sha256hex>/bundle.json. The token rides in the
 * path, not the query: a query parameter is subject to edge cache-key
 * normalisation, so one signed request could populate an entry an unsigned
 * request then reads.
 */

require_once __DIR__ . '/../shared/artifacts.php';
// Config reads answer empty until this has run, which for a credential check
// means silently refusing valid tokens.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/server-file.php';

const STATTIC_FUNCTIONS_BUNDLE_ROUTE_PREFIX = '/' . STATTIC_FUNCTIONS_BUNDLE_PREFIX;
const STATTIC_FUNCTIONS_BUNDLE_AUD = 'spacefast-functions-bundle';
const STATTIC_FUNCTIONS_SEED_AUD = 'spacefast-functions-seed';

// One signed route serves both artifact kinds; the terminal filename names the
// kind and selects the token audience, so a bundle token can never read a seed
// or vice versa.
const STATTIC_FUNCTIONS_ARTIFACT_KINDS = [
    'bundle.json' => STATTIC_FUNCTIONS_BUNDLE_AUD,
    'seed.json' => STATTIC_FUNCTIONS_SEED_AUD,
];

// The digest is pinned to lowercase hex here rather than downstream because it
// is concatenated into a filesystem path.
function _stattic_artifact_parse_bundle_path(string $requestPath): ?array
{
    if (!str_starts_with($requestPath, STATTIC_FUNCTIONS_BUNDLE_ROUTE_PREFIX)) {
        return null;
    }
    $rest = substr($requestPath, strlen(STATTIC_FUNCTIONS_BUNDLE_ROUTE_PREFIX));
    $segments = explode('/', $rest);
    if (count($segments) !== 3 || !array_key_exists($segments[2], STATTIC_FUNCTIONS_ARTIFACT_KINDS)) {
        return null;
    }
    [$digest, $token] = $segments;
    if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
        return null;
    }
    if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token) !== 1) {
        return null;
    }
    return ['digest' => $digest, 'token' => $token, 'aud' => STATTIC_FUNCTIONS_ARTIFACT_KINDS[$segments[2]]];
}

function _stattic_artifact_bundle_token_valid(
    string $privateRoot,
    string $token,
    string $spaceId,
    string $versionId,
    string $digest,
    string $audience
): bool {
    return _stattic_runtime_token_claims($privateRoot, $token, $audience, [
        // All three bindings are required: dropping one lets a tenant replay a
        // URL against another's bundle.
        'scope_valid' => static fn (array $claims): bool => is_string($claims['space_id'] ?? null) && hash_equals($spaceId, (string) $claims['space_id'])
            && is_string($claims['version_id'] ?? null) && hash_equals($versionId, (string) $claims['version_id'])
            && is_string($claims['sha256'] ?? null) && hash_equals('sha256:' . $digest, (string) $claims['sha256']),
    ]) !== null;
}

// Never returns. Every rejection is the same 404: distinguishing "wrong
// signature" from "no such bundle" would confirm which digests exist.
function _stattic_artifact_serve(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    string $requestPath,
    string $requestMethod
): void {
    if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
        _stattic_artifact_not_found();
    }

    $parsed = _stattic_artifact_parse_bundle_path($requestPath);
    if ($parsed === null) {
        _stattic_artifact_not_found();
    }

    if (!_stattic_artifact_bundle_token_valid($privateRoot, $parsed['token'], $spaceId, $versionId, $parsed['digest'], $parsed['aud'])) {
        _stattic_artifact_not_found();
    }

    // Finalize removes its per-version `files/` workspace after compiling the
    // immutable response tables. The CAS is the only place published bytes
    // live, so the signed route resolves the digest there directly. The token
    // already binds this digest to this Space and version.
    $absolute = _stattic_runtime_blob_path($privateRoot, $spaceId, $parsed['digest']);
    if (!is_file($absolute)) {
        require_once __DIR__ . '/tier.php';
        $absolute = _stattic_tier_promote_blob($privateRoot, $spaceId, $parsed['digest']) ?? '';
    }
    if (!is_file($absolute)) {
        _stattic_artifact_not_found();
    }

    // Immutable is safe because the URL is content-addressed, and the edge may
    // hold it (§16 opt-in): the execution edge re-fetches bundles on cold
    // starts and verifies the digest itself, so a CDN copy of the tokened,
    // content-addressed URL is the point of this policy.
    $headers = _stattic_apply_platform_header_policy([
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Content-Type-Options' => 'nosniff',
    ]);

    if ($requestMethod === 'GET') {
        _stattic_send_server_file(
            $absolute,
            $privateRoot,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers
        );
    }

    $length = filesize($absolute);
    $contents = $requestMethod === 'GET' ? file_get_contents($absolute) : '';
    if ($length === false || $contents === false) {
        _stattic_artifact_not_found();
    }
    _stattic_response_send(
        200,
        $contents,
        'application/json; charset=utf-8',
        $headers + ['Content-Length' => (string) $length]
    );
}

function _stattic_artifact_not_found(): never
{
    _stattic_response_send(404, "Not found.\n", 'text/plain; charset=utf-8', ['Cache-Control' => 'no-store']);
}
