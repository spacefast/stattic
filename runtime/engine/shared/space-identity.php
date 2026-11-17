<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';

// A Space's one credential toward the control plane (decision record
// docs/decisions/2026-11-14-one-space-credential.md).
//
// The box holds an Ed25519 key per Space in its private root and never sends
// the private half anywhere. The control plane reads the public half over the
// management API (`GET /spaces/{s}/identity`, authenticated by the
// platform-signed management token), stores it on the Space's system OAuth
// client, and only then names that client in the route config
// (`accessPage.identity`). The box then signs an RFC 7523 `private_key_jwt`
// assertion and gets a five-minute RFC 9068 access token through
// `client_credentials`, which every outbound call to the control plane carries.
//
// Backwards compatible both ways. Until the route config names a registered
// key that matches the box's own, the box keeps sending its older credentials,
// so a control plane that never read the key still sees what it knows. A
// control plane keeps accepting those older credentials for a Space until it
// has registered the key on an engine with `runtime.space-identity.v1`.

const STATTIC_SPACE_IDENTITY_KEY_FILE = 'identity/key.json';
const STATTIC_SPACE_IDENTITY_TOKEN_FILE = 'identity/token.json';
const STATTIC_SPACE_SESSION_KEY_FILE = 'identity/session-key.json';
// A token is renewed this long before it expires, so a call never carries one
// about to lapse in flight.
const STATTIC_SPACE_IDENTITY_TOKEN_SKEW_SECONDS = 60;
// A failed token request waits this long before the next try, so a control
// plane that refuses (a revoked client, an outage) is not asked on every visit.
const STATTIC_SPACE_IDENTITY_RETRY_SECONDS = 30;
const STATTIC_SPACE_IDENTITY_ASSERTION_SECONDS = 60;

function _stattic_space_identity_root(string $privateRoot, string $spaceId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/identity';
}

function _stattic_space_identity_key_valid(mixed $key): bool
{
    return is_array($key)
        && is_string($key['kid'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $key['kid']) === 1
        && is_string($key['secret'] ?? null) && strlen((string) base64_decode($key['secret'], true)) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
        && is_string($key['public'] ?? null) && strlen((string) base64_decode($key['public'], true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
}

/**
 * The Space's key, minted on first use under the site write lock. A failed or
 * malformed read never mints: a new key would orphan the one the control plane
 * registered. Null when the key is unavailable this instant.
 *
 * @return array{kid: string, secret: string, public: string}|null
 */
function _stattic_space_identity_key(string $privateRoot, string $spaceId): ?array
{
    if ($privateRoot === '' || !_stattic_id_valid($spaceId)) return null;
    $path = _stattic_space_root($privateRoot, $spaceId) . '/' . STATTIC_SPACE_IDENTITY_KEY_FILE;
    // array = the key; null = verified absent; false = unavailable.
    $read = static function () use ($path): array|null|false {
        $record = _sf_pointer_read('space-identity-key', $path);
        if ($record['kind'] === 'absent') return null;
        return _stattic_space_identity_key_valid($record['value'] ?? null) ? $record['value'] : false;
    };
    $state = $read();
    if (is_array($state)) return $state;
    if ($state === false || !_stattic_runtime_mkdir_soft(dirname($path))) return null;
    $minted = _stattic_lock_with(
        $privateRoot . '/runtime/write.lock',
        STATTIC_LOCK_WAIT,
        null,
        static function () use ($read, $path): ?array {
            $existing = $read();
            if ($existing !== null) return is_array($existing) ? $existing : null;
            $key = _stattic_space_identity_mint();
            _sf_json_write($path, $key);
            return $key;
        },
    );
    return is_array($minted) ? $minted : null;
}

/** A new Ed25519 key pair with a fresh kid. */
function _stattic_space_identity_mint(): array
{
    $pair = sodium_crypto_sign_keypair();
    $key = [
        'kid' => _stattic_base64url_encode(random_bytes(16)),
        'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        'created_at' => gmdate('c'),
    ];
    sodium_memzero($pair);
    return $key;
}

/**
 * The Space's visitor-session key: 32 random bytes, hex, minted by this box on
 * its first route sync and never sent anywhere. Every `sfv2_`, `sfa1_` and
 * `sfo2_` cookie is signed under it (access-rules.php
 * `_stattic_access_session_hmac_key`), so nothing the control plane holds can
 * forge a visitor session. The engine declares `runtime.session-keys.v1`.
 *
 * Only the overlay sync calls this, always under routes/index.lock, so two
 * syncs never mint at once. A key that exists but cannot be read throws: a new
 * key would sign every visitor out, and dropping it would put the box back on
 * the older derivation.
 */
function _stattic_space_session_key(string $privateRoot, string $spaceId): string
{
    $path = _stattic_space_root($privateRoot, $spaceId) . '/' . STATTIC_SPACE_SESSION_KEY_FILE;
    $record = _sf_pointer_read('space-session-key', $path);
    $key = is_array($record['value'] ?? null) ? ($record['value']['key'] ?? null) : null;
    if ($record['kind'] === 'present' && is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1) {
        return $key;
    }
    if ($record['kind'] !== 'absent') {
        throw new RuntimeException('space session key is unreadable: ' . $path);
    }
    $key = bin2hex(random_bytes(32));
    _sf_json_write($path, ['key' => $key, 'created_at' => gmdate('c')]);
    return $key;
}

/** The public JWK the control plane registers. Never carries the private half. */
function _stattic_space_identity_public_jwk(array $key): array
{
    return [
        'kty' => 'OKP',
        'crv' => 'Ed25519',
        'x' => _stattic_base64url_encode((string) base64_decode($key['public'], true)),
        'kid' => $key['kid'],
        'alg' => 'EdDSA',
        'use' => 'sig',
    ];
}

/** Management route: `GET /spaces/{s}/identity`. */
function _stattic_runtime_space_identity_route(string $privateRoot, string $spaceId, array $claims): never
{
    unset($claims);
    $key = _stattic_space_identity_key($privateRoot, $spaceId);
    if ($key === null) {
        _stattic_problem_response(503, 'space_identity_unavailable', 'The Space key is unavailable. Try again.');
    }
    _stattic_json_response(200, [
        'space_id' => $spaceId,
        'jwk' => _stattic_space_identity_public_jwk($key),
    ]);
}

/**
 * The route config's identity block, when the control plane has registered
 * this box's current key. Anything else (no block, another key, a malformed
 * URL) is null, and the caller keeps its older credentials.
 */
function _stattic_space_identity_config(array $serving, string $privateRoot, string $spaceId): ?array
{
    $projection = is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [];
    $raw = is_array($projection['accessPage']['identity'] ?? null) ? $projection['accessPage']['identity'] : null;
    if ($raw === null) return null;
    foreach (['clientId', 'tokenUrl', 'resource', 'kid', 'scope'] as $field) {
        if (!is_string($raw[$field] ?? null) || $raw[$field] === '') return null;
    }
    require_once __DIR__ . '/egress.php';
    if (!_stattic_platform_destination_allowed($raw['tokenUrl'])) return null;
    $key = _stattic_space_identity_key($privateRoot, $spaceId);
    // Registered against another key (a restored backup the control plane has
    // not read yet): the older credentials still work until the next sync.
    if ($key === null || !hash_equals($key['kid'], $raw['kid'])) return null;
    return [...$raw, 'key' => $key];
}

/** A signed RFC 7523 client assertion for the token endpoint. */
function _stattic_space_identity_assertion(array $config, int $now): string
{
    $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $config['key']['kid']];
    $payload = [
        'iss' => $config['clientId'],
        'sub' => $config['clientId'],
        'aud' => $config['tokenUrl'],
        'iat' => $now,
        'exp' => $now + STATTIC_SPACE_IDENTITY_ASSERTION_SECONDS,
        'jti' => _stattic_base64url_encode(random_bytes(18)),
    ];
    $input = _stattic_base64url_encode((string) json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . _stattic_base64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    $secret = (string) base64_decode($config['key']['secret'], true);
    $signature = sodium_crypto_sign_detached($input, $secret);
    sodium_memzero($secret);
    return $input . '.' . _stattic_base64url_encode($signature);
}

/**
 * A live access token for the Space, from the cache or a fresh
 * `client_credentials` request. Null when the route config names no registered
 * key, or the control plane refused: the caller then sends its older
 * credential, which a control plane that has not registered this key accepts.
 */
function _stattic_space_identity_token(array $serving, string $privateRoot = '', ?string $spaceId = null): ?string
{
    $privateRoot = $privateRoot !== '' ? $privateRoot : _stattic_access_private_root();
    $spaceId ??= is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    $config = _stattic_space_identity_config($serving, $privateRoot, $spaceId);
    if ($config === null) return null;
    $path = _stattic_space_root($privateRoot, $spaceId) . '/' . STATTIC_SPACE_IDENTITY_TOKEN_FILE;
    $now = time();
    $cached = _stattic_runtime_read_json($path);
    $cached = is_array($cached) ? $cached : [];
    // A token minted for other scopes (a route config that since gained the
    // Functions scope) is renewed, not reused. A cache written before the
    // scope was recorded matches nothing and renews once.
    $sameGrant = ($cached['kid'] ?? null) === $config['kid']
        && ($cached['client_id'] ?? null) === $config['clientId']
        && ($cached['scope'] ?? null) === $config['scope'];
    if (
        is_string($cached['token'] ?? null)
        && $sameGrant
        && is_int($cached['expires_at'] ?? null)
        && $cached['expires_at'] - STATTIC_SPACE_IDENTITY_TOKEN_SKEW_SECONDS > $now
    ) {
        return $cached['token'];
    }
    // A token still inside its lifetime outlives a failed renewal: a rate
    // limit or an outage must not drop the box to a credential the control
    // plane no longer accepts once it has seen this token.
    $live = is_string($cached['token'] ?? null)
        && $sameGrant
        && is_int($cached['expires_at'] ?? null)
        && $cached['expires_at'] > $now
        ? $cached['token'] : null;
    $wait = is_int($cached['retry_after'] ?? null) ? $cached['retry_after'] : STATTIC_SPACE_IDENTITY_RETRY_SECONDS;
    if (is_int($cached['failed_at'] ?? null) && $now - $cached['failed_at'] < $wait) {
        return $live;
    }
    require_once __DIR__ . '/http.php';
    $result = _stattic_http_request([
        'url' => $config['tokenUrl'],
        'method' => 'POST',
        'headers' => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $config['clientId'],
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => _stattic_space_identity_assertion($config, $now),
            'scope' => $config['scope'],
            'resource' => $config['resource'],
        ]),
        'connect_timeout' => 3,
        'timeout' => 5,
        'schemes' => ['https', 'http'],
        'max_body_bytes' => 16384,
    ]);
    $body = $result['ok'] ? json_decode($result['body'], true) : null;
    $token = is_array($body) && is_string($body['access_token'] ?? null) ? $body['access_token'] : null;
    $expiresIn = is_array($body) && is_int($body['expires_in'] ?? null) ? $body['expires_in'] : 0;
    if (!_stattic_runtime_mkdir_soft(dirname($path))) return $token;
    if ($token === null || $expiresIn <= STATTIC_SPACE_IDENTITY_TOKEN_SKEW_SECONDS || strlen($token) > 4096) {
        $status = (int) ($result['status'] ?? 0);
        $failure = ['failed_at' => $now, 'status' => $status];
        // A rate limit names its wait; honor it within a minute.
        if ($status === 429) {
            $retryAfter = _stattic_space_identity_retry_after($result['headers'] ?? []);
            if ($retryAfter !== null) $failure['retry_after'] = $retryAfter;
        }
        if ($live !== null) {
            $failure += ['token' => $live, 'expires_at' => $cached['expires_at'], 'kid' => $config['kid'], 'client_id' => $config['clientId'], 'scope' => $config['scope']];
        }
        _sf_atomic_put($path, json_encode($failure, JSON_UNESCAPED_SLASHES) . "\n", false);
        error_log('[spacefast] Space token request refused (HTTP ' . $status . '); ' . ($live !== null ? 'keeping the current token.' : 'using the older credential.'));
        return $live;
    }
    _sf_atomic_put($path, json_encode([
        'token' => $token,
        'expires_at' => $now + $expiresIn,
        'kid' => $config['kid'],
        'client_id' => $config['clientId'],
        'scope' => $config['scope'],
    ], JSON_UNESCAPED_SLASHES) . "\n", false);
    return $token;
}

/** A 429's Retry-After in seconds, between one and sixty, or null. */
function _stattic_space_identity_retry_after(array $headers): ?int
{
    foreach ($headers as $header) {
        if (!is_array($header) || strtolower((string) ($header[0] ?? '')) !== 'retry-after') continue;
        $value = trim((string) ($header[1] ?? ''));
        if (preg_match('/\A\d{1,6}\z/', $value) !== 1) return null;
        return max(1, min(60, (int) $value));
    }
    return null;
}

/** `Authorization: Bearer <Space token>`, or null while the Space keeps its older credentials. */
function _stattic_space_identity_authorization(array $serving, string $privateRoot = '', ?string $spaceId = null): ?string
{
    $token = _stattic_space_identity_token($serving, $privateRoot, $spaceId);
    return $token === null ? null : 'Authorization: Bearer ' . $token;
}

/**
 * Stop offering the Space token for a while: the control plane refused it.
 * That happens when it was rolled back to an image that never registered the
 * key, while this box still holds a route config naming it. The older
 * credential works there, so the caller retries with it.
 */
function _stattic_space_identity_refused(string $privateRoot, string $spaceId, int $status): void
{
    if ($privateRoot === '' || !_stattic_id_valid($spaceId)) return;
    $path = _stattic_space_root($privateRoot, $spaceId) . '/' . STATTIC_SPACE_IDENTITY_TOKEN_FILE;
    if (!_stattic_runtime_mkdir_soft(dirname($path))) return;
    _sf_atomic_put($path, json_encode(['failed_at' => time(), 'status' => $status]) . "\n", false);
}

/**
 * Send one control-plane call with the Space token, and once more with the
 * older credential if that token is refused (`$refused` names the statuses
 * that mean "not this credential" for the route). `$send` receives the
 * Authorization header line to add, or null for the older credential.
 *
 * @param callable(?string): array $send returns an `_stattic_http_request` result
 * @param list<int> $refused
 */
function _stattic_space_identity_send(array $serving, callable $send, array $refused = [401], string $privateRoot = '', ?string $spaceId = null): array
{
    $privateRoot = $privateRoot !== '' ? $privateRoot : _stattic_access_private_root();
    $spaceId ??= is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
    $authorization = _stattic_space_identity_authorization($serving, $privateRoot, $spaceId);
    if ($authorization === null) return $send(null);
    $result = $send($authorization);
    $status = (int) ($result['status'] ?? 0);
    if (!in_array($status, $refused, true)) return $result;
    _stattic_space_identity_refused($privateRoot, $spaceId, $status);
    return $send(null);
}

/**
 * A serving-shaped view of the Space's overlay, for callers outside the access
 * lane (Zero callbacks and replay). Only the access-page descriptor is read.
 */
function _stattic_space_identity_serving(string $privateRoot, string $spaceId): array
{
    if ($privateRoot === '' || !_stattic_id_valid($spaceId)) return ['space_id' => $spaceId];
    require_once __DIR__ . '/artifacts.php';
    $pointer = _stattic_runtime_read_json(_stattic_space_root($privateRoot, $spaceId) . '/space.json');
    try {
        $overlay = _stattic_v4_overlay($privateRoot, $spaceId, $pointer);
    } catch (Throwable) {
        $overlay = null;
    }
    return [
        'space_id' => $spaceId,
        'authorization' => [
            'accessPage' => is_array($overlay) && is_array($overlay['access_page'] ?? null) ? $overlay['access_page'] : null,
        ],
    ];
}
