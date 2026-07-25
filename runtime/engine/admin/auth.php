<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/jwt.php';

function _stattic_runtime_token_from_request(): string
{
    return _stattic_runtime_bearer_token_from_request() ?? '';
}

function _stattic_runtime_instance_id(): string
{
    return _stattic_config_value('SPACEFAST_RUNTIME_INSTANCE_ID');
}

function _stattic_runtime_api_base_url(): string
{
    $value = rtrim(_stattic_config_value('SPACEFAST_API_BASE_URL'), '/');
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function _stattic_runtime_require_management_jwt(string $privateRoot, string $action, array $required = []): array
{
    $claims = _stattic_runtime_verify_jwt($privateRoot, _stattic_runtime_token_from_request(), 'stattic-runtime-management');
    if (($claims['action'] ?? null) !== $action) {
        _stattic_json_response(403, ['error' => ['code' => 'runtime_action_forbidden', 'message' => 'Runtime action is not allowed by this token (expected action: ' . $action . ').']]);
    }
    if (!is_string($claims['operation_id'] ?? null) || trim((string) $claims['operation_id']) === '') {
        _stattic_json_response(403, ['error' => ['code' => 'runtime_operation_missing', 'message' => 'Runtime management token requires an operation id.']]);
    }
    foreach ($required as $key => $value) {
        if (!isset($claims[$key]) || !is_string($claims[$key]) || !hash_equals($value, $claims[$key])) {
            _stattic_json_response(403, ['error' => ['code' => 'runtime_scope_forbidden', 'message' => 'Runtime token scope does not match this request (claim: ' . $key . ').']]);
        }
    }
    // Management-lane single-use replay guard (fatal on replay) — shared cache.
    _spacefast_jwt_reject_replayed_jti($privateRoot, 'stattic-runtime-management', $claims, time());
    return $claims;
}

function _stattic_runtime_require_upload_jwt(string $privateRoot): array
{
    return _stattic_runtime_verify_jwt($privateRoot, _stattic_runtime_token_from_request(), 'stattic-runtime-upload');
}

// Management/upload claim profile: pinned `aud`, `runtime_instance_id`, exp with
// no leeway, nbf 30s. Shares the parse/alg-pin/Ed25519 primitives with the
// visitor lane (shared/jwt.php); only the claim table differs. JSON failures.
function _stattic_runtime_verify_jwt(string $privateRoot, string $token, string $audience): array
{
    if ($token === '') {
        _stattic_json_response(401, ['error' => ['code' => 'runtime_unauthorized', 'message' => 'Runtime API authentication is required.']]);
    }

    $parsed = _spacefast_jwt_parse($token);
    if ($parsed === null || ($parsed['header']['alg'] ?? null) !== 'EdDSA') {
        _stattic_json_response(401, ['error' => ['code' => 'runtime_token_invalid', 'message' => 'Runtime token is invalid.']]);
    }
    $header = $parsed['header'];
    $claims = $parsed['claims'];

    $now = time();
    if (($claims['aud'] ?? null) !== $audience || !isset($claims['exp']) || (int) $claims['exp'] < $now || (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 30)) {
        _stattic_json_response(401, ['error' => ['code' => 'runtime_token_expired', 'message' => 'Runtime token is expired or not valid yet.']]);
    }

    $runtimeInstanceId = _stattic_runtime_instance_id();
    if ($runtimeInstanceId === '' || !isset($claims['runtime_instance_id']) || !hash_equals($runtimeInstanceId, (string) $claims['runtime_instance_id'])) {
        _stattic_json_response(403, ['error' => ['code' => 'runtime_instance_mismatch', 'message' => 'Runtime token is not scoped to this runtime.']]);
    }

    $jwk = _stattic_runtime_public_jwk($privateRoot, is_string($header['kid'] ?? null) ? $header['kid'] : '');
    $publicKey = _spacefast_base64url_decode((string) ($jwk['x'] ?? ''));
    if (!_spacefast_jwt_ed25519_valid($parsed['signing_input'], $parsed['signature'], $publicKey)) {
        _stattic_json_response(401, ['error' => ['code' => 'runtime_token_bad_signature', 'message' => 'Runtime token signature is invalid.']]);
    }

    return $claims;
}

function _stattic_runtime_public_jwk(string $privateRoot, string $kid): array
{
    $jwks = _stattic_runtime_cached_jwks($privateRoot);
    foreach (($jwks['keys'] ?? []) as $key) {
        if (is_array($key) && ($key['kty'] ?? null) === 'OKP' && ($key['crv'] ?? null) === 'Ed25519' && ($kid === '' || ($key['kid'] ?? null) === $kid)) {
            return $key;
        }
    }
    _stattic_json_response(401, ['error' => ['code' => 'runtime_jwks_key_missing', 'message' => 'Runtime JWT signing key is unavailable.']]);
}

function _stattic_runtime_cached_jwks(string $privateRoot): array
{
    // Prefer a locally provisioned JWKS so the runtime does not need to reach back to
    // the API to verify management tokens. Self-hosted runtimes point
    // SPACEFAST_RUNTIME_JWKS_PATH at a local JWKS file; WP.Cloud pushes a base64
    // JWKS snapshot through Atomic persistent data as SPACEFAST_RUNTIME_JWKS_B64.
    $jwksPath = _stattic_config_value('SPACEFAST_RUNTIME_JWKS_PATH');
    if ($jwksPath !== '' && is_file($jwksPath)) {
        $decoded = _stattic_runtime_read_json($jwksPath);
        if (is_array($decoded) && is_array($decoded['keys'] ?? null)) {
            return $decoded;
        }
    }
    $configuredB64 = _stattic_config_value('SPACEFAST_RUNTIME_JWKS_B64');
    if ($configuredB64 !== '') {
        $raw = base64_decode($configuredB64, true);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded) && is_array($decoded['keys'] ?? null)) {
            return $decoded;
        }
    }
    $cachePath = $privateRoot . '/runtime/jwks.json';
    $cached = _stattic_runtime_read_json($cachePath);
    if (is_array($cached) && isset($cached['fetched_at'], $cached['jwks']) && (time() - (int) $cached['fetched_at']) < 300 && is_array($cached['jwks'])) {
        return $cached['jwks'];
    }

    $apiBaseUrl = _stattic_runtime_api_base_url();
    if ($apiBaseUrl === '') {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_api_base_url_missing', 'message' => 'Runtime API base URL is not configured.']]);
    }
    $raw = @file_get_contents($apiBaseUrl . '/.well-known/spacefast-runtime-jwks.json');
    $jwks = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($jwks)) {
        _stattic_json_response(500, ['error' => ['code' => 'runtime_jwks_fetch_failed', 'message' => 'Runtime JWT signing keys could not be fetched.']]);
    }
    _stattic_runtime_mkdir(dirname($cachePath));
    _stattic_runtime_write_json_atomic($cachePath, ['fetched_at' => time(), 'jwks' => $jwks]);
    return $jwks;
}
