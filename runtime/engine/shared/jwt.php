<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/record-store.php';

function _stattic_base64url_decode(string $value): string
{
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $decoded = base64_decode($padded, true);
    return is_string($decoded) ? $decoded : '';
}

function _stattic_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function _stattic_jwt_parse(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
    $header = json_decode(_stattic_base64url_decode($encodedHeader), true);
    $claims = json_decode(_stattic_base64url_decode($encodedClaims), true);
    if (!is_array($header) || !is_array($claims)) {
        return null;
    }
    return [
        'header' => $header,
        'claims' => $claims,
        'signing_input' => $encodedHeader . '.' . $encodedClaims,
        'signature' => _stattic_base64url_decode($encodedSignature),
    ];
}

// Length-guard both key and signature: sodium throws on a wrong-length argument,
// which would crash instead of failing closed.
function _stattic_jwt_ed25519_valid(string $signingInput, string $signatureRaw, string $publicKeyRaw): bool
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return false;
    }
    if (strlen($publicKeyRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return false;
    }
    if (strlen($signatureRaw) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return false;
    }
    return sodium_crypto_sign_verify_detached($signatureRaw, $signingInput, $publicKeyRaw);
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

// Local JWKS first so verification needs no call back to the API: self-hosted
// sets SPACEFAST_RUNTIME_JWKS_PATH; WP.Cloud pushes SPACEFAST_RUNTIME_JWKS_B64
// through Atomic persistent data. `$allowFetch` is the management lane's own
// flag — the public serving lane must never make a visitor request wait on an
// outbound HTTPS round trip to resolve a key, so it reads local/cached only and
// denies when neither answers.
function _stattic_runtime_jwks(string $privateRoot, bool $allowFetch, ?string &$reason = null): ?array
{
    $cachePath = $privateRoot . '/runtime/jwks.json';
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
        if (_stattic_runtime_jwks_usable($decoded)) {
            // Provider persistent data is available on management/bootstrap
            // requests, not on the no-WordPress public serving lane. Persist
            // that trusted configured source so the extensionless blob gate
            // can verify later scan requests without decrypting provider data
            // or fetching a key over the network.
            $cached = _stattic_runtime_read_json($cachePath);
            $persistentRuntimeRoot = str_ends_with(
                rtrim(str_replace('\\', '/', $privateRoot), '/'),
                '/.stattic/storage'
            );
            if (
                $persistentRuntimeRoot
                && (($cached['source'] ?? null) !== 'configured' || ($cached['jwks'] ?? null) !== $decoded)
            ) {
                if (_stattic_runtime_mkdir_soft(dirname($cachePath))) {
                    _stattic_runtime_write_json_atomic($cachePath, [
                        'source' => 'configured',
                        'jwks' => $decoded,
                    ]);
                }
            }
            return $decoded;
        }
    }
    $cached = _stattic_runtime_read_json($cachePath);
    // Only a usable key set is honoured: a doc that was garbage when written must
    // not keep answering 401 for the full cache window.
    if (
        is_array($cached)
        && (
            ($cached['source'] ?? null) === 'configured'
            || (isset($cached['fetched_at']) && (time() - (int) $cached['fetched_at']) < 300)
        )
        && _stattic_runtime_jwks_usable($cached['jwks'] ?? null)
    ) {
        return $cached['jwks'];
    }
    if (!$allowFetch) {
        $reason = 'key_missing';
        return null;
    }

    require_once __DIR__ . '/http.php';

    $apiBaseUrl = _stattic_runtime_api_base_url();
    if ($apiBaseUrl === '') {
        $reason = 'api_base_url_missing';
        return null;
    }
    // https only: the JWKS is the runtime's trust anchor, so a cleartext fetch it
    // could be tampered in flight is never accepted, even if the API base is http.
    $result = _stattic_http_request([
        'url' => $apiBaseUrl . '/.well-known/spacefast-runtime-jwks.json',
        'schemes' => ['https'],
        'max_body_bytes' => 262144,
    ]);
    $jwks = $result['ok'] ? json_decode($result['body'], true) : null;
    // A malformed or empty response is a retryable fetch failure (500), never a
    // doc worth persisting: caching garbage here 401s every privileged request
    // for the next 300s. Only a well-formed Ed25519 OKP key set is cached.
    if (!_stattic_runtime_jwks_usable($jwks)) {
        $reason = 'jwks_fetch_failed';
        return null;
    }
    if (_stattic_runtime_mkdir_soft(dirname($cachePath))) {
        _stattic_runtime_write_json_atomic($cachePath, ['fetched_at' => time(), 'jwks' => $jwks]);
    }
    return $jwks;
}

// Usable = at least one Ed25519 OKP signing key with a public component. The
// local-file and base64 lanes are operator-provided and keep their looser
// is_array(keys) check; this gate is for the untrusted network doc.
function _stattic_runtime_jwks_usable(mixed $jwks): bool
{
    if (!is_array($jwks) || !is_array($jwks['keys'] ?? null) || $jwks['keys'] === []) {
        return false;
    }
    foreach ($jwks['keys'] as $key) {
        if (
            is_array($key)
            && ($key['kty'] ?? null) === 'OKP'
            && ($key['crv'] ?? null) === 'Ed25519'
            && is_string($key['x'] ?? null)
            && trim($key['x']) !== ''
        ) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<array> every Ed25519 key for this kid; a kid-less token matches
 *         them all — the caller tries each and stops at the first that verifies.
 */
function _stattic_runtime_signing_jwks(string $privateRoot, string $kid, bool $allowFetch, ?string &$reason = null): array
{
    $jwks = _stattic_runtime_jwks($privateRoot, $allowFetch, $reason);
    $candidates = [];
    foreach (($jwks['keys'] ?? []) as $key) {
        if (is_array($key) && ($key['kty'] ?? null) === 'OKP' && ($key['crv'] ?? null) === 'Ed25519' && ($kid === '' || ($key['kid'] ?? null) === $kid)) {
            $candidates[] = $key;
        }
    }
    if ($candidates === []) {
        $reason ??= 'key_missing';
    }
    return $candidates;
}

// The ONE control-plane-token verifier, for every aud. It returns claims or null
// and never writes a response, so the serving lane cannot leak which spaces,
// versions and digests exist; the admin lane maps `$rejection` through its own
// vocabulary (admin/auth.php).
//
// `$profile` is the lane's data: `claims` (ordered claim => ['equals' => …] |
// ['present' => true] | ['absent' => true]), `scope_valid`, `state_valid`,
// `allow_jwks_fetch`, `instance_pinned`.
//
// Check order is load-bearing. `scope_valid` runs before the runtime pin so an
// invalid public request never reaches mounted storage, and `state_valid` before
// the key lookup so a bad token costs zero lookups; both compare only
// caller-supplied values against the token's own, so neither is an oracle. The
// `claims` profile runs LAST, after the signature: pinning a claim is only
// meaningful once the token is proven authentic.
function _stattic_runtime_token_claims(
    string $privateRoot,
    string $token,
    string $aud,
    array $profile = [],
    ?array &$rejection = null
): ?array {
    $reject = static function (string $reason, string $claim = '', string $rule = '') use (&$rejection): ?array {
        $rejection = ['reason' => $reason, 'claim' => $claim, 'rule' => $rule];
        return null;
    };
    if ($token === '') {
        return $reject('token_missing');
    }
    $parsed = _stattic_jwt_parse($token);
    if ($parsed === null || ($parsed['header']['alg'] ?? null) !== 'EdDSA') {
        return $reject('malformed');
    }
    $claims = $parsed['claims'];
    if (($claims['aud'] ?? null) !== $aud) {
        return $reject('audience');
    }
    $scopeValid = $profile['scope_valid'] ?? null;
    if (is_callable($scopeValid) && $scopeValid($claims) !== true) {
        return $reject('scope');
    }

    // Pinned to this runtime so a token lifted from one origin cannot be replayed
    // against another; a space move re-runs finalize and re-mints. Opting out is
    // for a lane whose token names its own Space and resolves nothing outside it
    // (the blob gate): such a token is already only usable on the host holding
    // that Space, and the minter has no placement to pin at mint time.
    $runtimeInstanceId = _stattic_runtime_instance_id();
    if (
        ($profile['instance_pinned'] ?? true) === true
        && (
            $runtimeInstanceId === ''
            || !is_string($claims['runtime_instance_id'] ?? null)
            || !hash_equals($runtimeInstanceId, (string) $claims['runtime_instance_id'])
        )
    ) {
        return $reject('instance');
    }

    // `exp` is a backstop (the scope claims are the boundary) but stays required
    // so an unbounded token cannot be minted by omission.
    $now = time();
    if (!isset($claims['exp']) || (int) $claims['exp'] < $now) {
        return $reject('expired');
    }
    if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 30) {
        return $reject('expired');
    }
    $stateValid = $profile['state_valid'] ?? null;
    if (is_callable($stateValid) && $stateValid($claims) !== true) {
        return $reject('state');
    }

    $keyReason = null;
    $jwks = _stattic_runtime_signing_jwks(
        $privateRoot,
        is_string($parsed['header']['kid'] ?? null) ? $parsed['header']['kid'] : '',
        ($profile['allow_jwks_fetch'] ?? false) === true,
        $keyReason,
    );
    if ($jwks === []) {
        return $reject((string) $keyReason);
    }
    $signatureValid = false;
    foreach ($jwks as $jwk) {
        $publicKey = _stattic_base64url_decode((string) ($jwk['x'] ?? ''));
        if (_stattic_jwt_ed25519_valid($parsed['signing_input'], $parsed['signature'], $publicKey)) {
            $signatureValid = true;
            break;
        }
    }
    if (!$signatureValid) {
        return $reject('signature');
    }

    foreach (is_array($profile['claims'] ?? null) ? $profile['claims'] : [] as $claim => $rule) {
        if (($rule['absent'] ?? false) === true) {
            if (array_key_exists($claim, $claims)) {
                return $reject('claim', (string) $claim, 'absent');
            }
            continue;
        }
        $value = $claims[$claim] ?? null;
        if (($rule['present'] ?? false) === true) {
            if (!is_string($value) || trim($value) === '') {
                return $reject('claim', (string) $claim, 'present');
            }
            continue;
        }
        $expected = (string) ($rule['equals'] ?? '');
        if (!is_string($value) || !hash_equals($expected, $value)) {
            return $reject('claim', (string) $claim, 'equals');
        }
    }

    return $claims;
}

// The blob token gate's audience entry (contracts §7).
//
// Claim schema, minted by the control plane with EdDSA. `aud`, `space_id` and
// `exp` are always required; the rest select EXACTLY ONE of four resolvers:
//
//   record lane   record + sha256          an uploads-store object; the record's
//                                          own sha must equal the claim's
//   upload lane   upload + sha256          a blob an OPEN publish session has
//                                          accepted, for a version whose catalog
//                                          does not exist yet
//   sha lane      version_id + sha256      a blob the version's manifest lists,
//                 [+ route_name]           optionally that of a variant channel
//   path lane     version_id + path + view a path the version's CATALOG carries
//                                          in `source` or `served`
//
//   exp        control-plane TTLs: download 10 min, scan 60 min
//
// The path lane deliberately carries no `route_name`: per-channel bytes are a
// content-addressed concern (the scanner enumerates a channel and fetches by
// sha), and no customer-facing read names a channel.
//
// Returns the claims or null; never writes a response — every rejection is the
// caller's uniform 404.
function _stattic_runtime_blob_gate_claims(string $privateRoot, string $token): ?array
{
    $claims = _stattic_runtime_token_claims($privateRoot, $token, 'spacefast-blob-gate', [
        // Its own Space is the entire scope, so there is nothing to pin a
        // placement against; see _stattic_runtime_token_claims.
        'instance_pinned' => false,
        // The serving lane never waits on an outbound JWKS fetch.
        'allow_jwks_fetch' => false,
        'scope_valid' => static function (array $claims): bool {
            $nonEmpty = static fn (mixed $value): bool => is_string($value) && $value !== '';
            if (!$nonEmpty($claims['space_id'] ?? null)) {
                return false;
            }
            $record = $claims['record'] ?? null;
            $upload = $claims['upload'] ?? null;
            $versionId = $claims['version_id'] ?? null;
            $path = $claims['path'] ?? null;
            $hasSha = $nonEmpty($claims['sha256'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', strtolower((string) $claims['sha256'])) === 1;
            // Exactly one resolver: two would let a deleted record be laundered
            // through a version, or a path claim borrow a sha claim's answer.
            $lanes = (int) $nonEmpty($record) + (int) $nonEmpty($upload) + (int) $nonEmpty($path);
            if ($lanes > 1) {
                return false;
            }
            if ($nonEmpty($record) || $nonEmpty($upload)) {
                // A record resolves in the uploads store and an upload id in the
                // open publish session; a version scope, a channel or a view on
                // either would name something neither can answer.
                return $hasSha
                    && $versionId === null
                    && !array_key_exists('route_name', $claims)
                    && !array_key_exists('view', $claims);
            }
            if (!$nonEmpty($versionId)) {
                return false;
            }
            if ($nonEmpty($path)) {
                // The gate never receives a filesystem path: `path` is a version
                // path, normalized and looked up by the shared resolver.
                return !$hasSha
                    && !array_key_exists('sha256', $claims)
                    && !array_key_exists('route_name', $claims)
                    && in_array($claims['view'] ?? null, STATTIC_RUNTIME_VERSION_FILE_VIEWS, true);
            }
            $routeName = $claims['route_name'] ?? null;
            if ($routeName !== null && (!$nonEmpty($routeName) || strlen($routeName) > 128)) {
                return false;
            }
            return $hasSha && !array_key_exists('view', $claims);
        },
    ]);
    return is_array($claims) ? $claims : null;
}

// Only pure outcomes may be memoized: time-dependent and stateful checks
// (exp/nbf, aud, jti consumption) stay outside in _stattic_visitor_verify.
// Never APCu — a signature verdict must not outlive its request, and per-space
// key material must not land in pool-shared storage.

const STATTIC_JWT_VERIFY_MEMO_MAX = 256;

function &_stattic_jwt_verify_memo(): array
{
    static $memo = [];
    return $memo;
}

function _stattic_jwt_verify_memo_reserve(array &$memo): void
{
    if (count($memo) >= STATTIC_JWT_VERIFY_MEMO_MAX) {
        $memo = [];
    }
}

function _stattic_jwt_parse_memo(string $token): ?array
{
    $memo = &_stattic_jwt_verify_memo();
    $key = 'parse:' . hash('sha256', $token);
    if (!array_key_exists($key, $memo)) {
        _stattic_jwt_verify_memo_reserve($memo);
        $memo[$key] = _stattic_jwt_parse($token);
    }
    return $memo[$key];
}

// `$keyFingerprint` is part of the memo key so a rotation mid-request forces a
// fresh verification.
function _stattic_jwt_signature_valid_memo(string $keyFingerprint, string $token, callable $verify): bool
{
    $memo = &_stattic_jwt_verify_memo();
    $key = 'sig:' . hash('sha256', $keyFingerprint . "\0" . $token);
    if (!array_key_exists($key, $memo)) {
        _stattic_jwt_verify_memo_reserve($memo);
        $memo[$key] = $verify() === true;
    }
    return $memo[$key] === true;
}

// Every issuer a token with this kid could have been signed by. A kid-less token
// (or issuer) matches every key, so a rotation cannot sign a visitor out.
function _stattic_jwt_issuers_for_kid(array $issuers, string $kid): array
{
    $candidates = [];
    foreach ($issuers as $issuer) {
        if (!is_array($issuer) || ($issuer['alg'] ?? 'EdDSA') !== 'EdDSA') {
            continue;
        }
        $issuerKid = is_string($issuer['kid'] ?? null) ? $issuer['kid'] : '';
        if ($kid === '' || $issuerKid === '' || $issuerKid === $kid) {
            $candidates[] = $issuer;
        }
    }
    return $candidates;
}

function _stattic_jwt_issuer_fingerprint(array $issuer): string
{
    return hash('sha256', json_encode([
        'alg' => is_string($issuer['alg'] ?? null) ? (string) $issuer['alg'] : 'EdDSA',
        'kid' => is_string($issuer['kid'] ?? null) ? (string) $issuer['kid'] : '',
        'publicKey' => is_string($issuer['publicKey'] ?? null) ? (string) $issuer['publicKey'] : '',
    ], JSON_UNESCAPED_SLASHES));
}

function _stattic_visitor_verify(string $token, array $options): ?array
{
    $parsed = _stattic_jwt_parse_memo($token);
    if ($parsed === null) {
        return null;
    }
    $header = $parsed['header'];
    $claims = $parsed['claims'];
    $alg = $header['alg'] ?? null;
    $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';

    $now = time();
    $claimExp = isset($claims['exp']) ? (int) $claims['exp'] : null;
    if ($claimExp === null || $claimExp < $now - 300) {
        return null;
    }
    if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 300) {
        return null;
    }
    // The audience names the Space, so a handoff keeps its identity across
    // claim, rename and custom domains. Host binding did not go away with it:
    // the mint records the serving host in its own claim and it must equal the
    // host this request arrived on, so a token lifted from one origin is still
    // refused on every other.
    $host = strtolower((string) ($options['host'] ?? ''));
    $expectedSpaceId = is_string($options['spaceId'] ?? null) ? $options['spaceId'] : '';
    $audience = is_string($claims['aud'] ?? null) ? $claims['aud'] : '';
    if ($host === '' || $audience === '' || $expectedSpaceId === '') {
        return null;
    }
    if (!hash_equals($expectedSpaceId, $audience)) {
        return null;
    }
    $hostClaim = is_string($claims['host'] ?? null) ? strtolower($claims['host']) : '';
    if ($hostClaim === '' || !hash_equals($host, $hostClaim)) {
        return null;
    }
    $expectedIssuer = is_string($options['issuer'] ?? null) ? $options['issuer'] : '';
    if (
        $expectedIssuer === ''
        || !is_string($claims['iss'] ?? null)
        || !hash_equals($expectedIssuer, $claims['iss'])
    ) {
        return null;
    }
    $generation = (int) ($options['generation'] ?? 0);
    $tokenGeneration = isset($claims['generation']) ? (int) $claims['generation'] : -1;
    if ($tokenGeneration !== $generation) {
        return null;
    }
    $spaceId = is_string($options['spaceId'] ?? null) ? $options['spaceId'] : '';
    if (
        $spaceId === ''
        || !is_string($claims['spaceId'] ?? null)
        || !hash_equals($spaceId, $claims['spaceId'])
    ) {
        return null;
    }

    if ($alg !== 'EdDSA') {
        return null;
    }
    $issuers = is_array($options['issuers'] ?? null) ? $options['issuers'] : [];
    $candidates = _stattic_jwt_issuers_for_kid($issuers, $kid);
    if ($candidates === []) {
        return null;
    }
    $signatureValid = false;
    foreach ($candidates as $issuer) {
        $issuerFingerprint = _stattic_jwt_issuer_fingerprint($issuer);
        $publicKey = _stattic_base64url_decode((string) ($issuer['publicKey'] ?? ''));
        if (_stattic_jwt_signature_valid_memo(
            $issuerFingerprint,
            $token,
            static fn (): bool => _stattic_jwt_ed25519_valid($parsed['signing_input'], $parsed['signature'], $publicKey),
        )) {
            $signatureValid = true;
            break;
        }
    }
    if (!$signatureValid) {
        return null;
    }
    if (!empty($options['requireJti'])) {
        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || trim($jti) === '') {
            return null;
        }
        $iat = isset($claims['iat']) ? (int) $claims['iat'] : null;
        $iatMaxAge = (int) ($options['iatMaxAge'] ?? 300);
        if ($iat === null || $iat < $now - $iatMaxAge || $iat > $now + 300) {
            return null;
        }
        $privateRoot = is_string($options['privateRoot'] ?? null) ? $options['privateRoot'] : '';
        if ($privateRoot === '') {
            return null;
        }
        // Fail closed: a replayed OR unstorable jti both reject — no access is
        // handed out when the replay guard cannot record the id.
        if (_stattic_jwt_consume_jti($privateRoot, 'visitor', $jti, $claimExp, $now) !== 'ok') {
            return null;
        }
    }

    return [
        'sub' => is_string($claims['sub'] ?? null) ? $claims['sub'] : '',
        'exp' => $claimExp,
        'claims' => $claims,
        'issuerFingerprint' => $issuerFingerprint,
    ];
}

// Replay markers expire at the token's own exp, which the claim stamps as the
// marker's mtime; the sweep is throttled because unthrottled it would run
// inside every token consume — quadratic across a deploy burst. Markers also
// reclaim lazily on collision, so the cadence bounds disk usage only, never
// correctness.
function _stattic_jwt_replay_store(string $privateRoot): array
{
    $root = $privateRoot . '/runtime/jti';
    return _stattic_record_store($root, [
        'retention' => [
            'mtime_seconds' => 0,
            'field' => 'exp',
            'throttle_seconds' => 300,
            'marker' => $root . '/.last-cleanup',
        ],
    ]);
}

// Returns 'ok' (first to claim), 'replayed' (live marker exists), or
// 'unavailable' (the write failed with no marker on disk). A replay verdict
// requires evidence — reporting a storage outage as a replay turns a full disk
// into a permanent-looking auth failure; callers answer retryable instead.
// `$namespace` keeps the visitor and management id spaces from colliding.
function _stattic_jwt_consume_jti(string $privateRoot, string $namespace, string $jti, int $exp, int $now): string
{
    // Soft mkdir, NOT _stattic_runtime_mkdir: that helper hard-fails 500, which
    // would hide a storage outage behind a generic runtime error.
    if (!_stattic_runtime_mkdir_soft($privateRoot . '/runtime/jti')) {
        return 'unavailable';
    }
    $store = _stattic_jwt_replay_store($privateRoot);
    _stattic_defer(static function () use ($store, $now): void {
        _stattic_record_store_sweep($store, $now);
    });

    $id = hash('sha256', $namespace . ':' . $jti);
    $record = ['ns' => $namespace, 'jti' => $jti, 'exp' => $exp];
    if (_stattic_record_store_claim($store, $id, $record, $exp)) {
        return 'ok';
    }

    $path = _stattic_record_store_path($store, $id);
    $mtime = @filemtime($path);
    if ($mtime === false || $mtime < $now) {
        $existing = _stattic_record_store_get($store, $id);
        if ($existing !== null && isset($existing['exp']) && (int) $existing['exp'] < $now) {
            _stattic_record_store_delete($store, $id);
            if (_stattic_record_store_claim($store, $id, $record, $exp)) {
                return 'ok';
            }
        }
    }

    if (!file_exists($path)) {
        return 'unavailable';
    }
    return 'replayed';
}

function _stattic_jwt_reject_replayed_jti(string $privateRoot, string $audience, array $claims, int $now): void
{
    $jti = $claims['jti'] ?? null;
    if (!is_string($jti) || trim($jti) === '') {
        _stattic_problem_response(403, 'runtime_jti_missing', 'Runtime token id is required.');
    }
    $exp = isset($claims['exp']) ? (int) $claims['exp'] : $now;
    $status = _stattic_jwt_consume_jti($privateRoot, $audience, $jti, $exp, $now);
    if ($status === 'unavailable') {
        // Storage outage, not a replay: retryable, never a permanent auth failure.
        _stattic_problem_response(503, 'runtime_replay_guard_unavailable', 'Runtime token replay guard storage is unavailable (disk full or not writable).');
    }
    if ($status !== 'ok') {
        _stattic_problem_response(403, 'runtime_jti_replayed', 'Runtime token id was already used.');
    }
}
