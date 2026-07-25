<?php
declare(strict_types=1);

// The shared jti replay cache below uses the private-storage helpers.
require_once __DIR__ . '/storage.php';

// The one JWT verifier for the runtime — access-plan §3.1. Both the browser
// (visitor) lane and the management/upload lane parse, alg-pin, select the key
// by kid, verify the signature, and run their time checks through THIS file.
// Claim profiles are data (the caller passes options); the crypto and the
// base64url codec exist exactly once.
//
// Two key types, one verifier:
//   - Ed25519 (`sodium_crypto_sign_verify_detached`, length-guarded) for the
//     platform issuer and any BYO/external issuers registered on a rule.
//   - A space-local HS256 key (kid `spacefast-local-pw-v1`) whose material is
//     derived from a password secret verifier hash + the space `sessionVersion`
//     and may ONLY ever sign `pw:` grants. The key is never published; the
//     verifier re-derives it. Rotating the password or bumping `sessionVersion`
//     changes the derivation and invalidates every outstanding wall pass.

const SPACEFAST_LOCAL_PW_TOKEN_KID = 'spacefast-local-pw-v1';
// Domain-separation label for the space-local HS256 key derivation. Bound to the
// rule id and the session version so a pass minted for one wall/one session
// version can never satisfy another.
const SPACEFAST_LOCAL_PW_KEY_LABEL = 'spacefast-pw-key:v1';

function _spacefast_base64url_decode(string $value): string
{
    $padded = strtr($value, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $decoded = base64_decode($padded, true);
    return is_string($decoded) ? $decoded : '';
}

function _spacefast_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

// Splits and JSON-decodes a compact JWS. Returns null on any structural fault.
function _spacefast_jwt_parse(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
    $header = json_decode(_spacefast_base64url_decode($encodedHeader), true);
    $claims = json_decode(_spacefast_base64url_decode($encodedClaims), true);
    if (!is_array($header) || !is_array($claims)) {
        return null;
    }
    return [
        'header' => $header,
        'claims' => $claims,
        'signing_input' => $encodedHeader . '.' . $encodedClaims,
        'signature' => _spacefast_base64url_decode($encodedSignature),
    ];
}

// Ed25519 detached verify, length-guarded on BOTH the key and the signature:
// sodium throws on a wrong-length argument, which would crash instead of
// failing closed, so an attacker-supplied malformed token must be rejected here.
function _spacefast_jwt_ed25519_valid(string $signingInput, string $signatureRaw, string $publicKeyRaw): bool
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

function _spacefast_jwt_hs256_valid(string $signingInput, string $signatureRaw, string $key): bool
{
    if ($key === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $signingInput, $key, true);
    return hash_equals($expected, $signatureRaw);
}

// ---------------------------------------------------------------------------
// Per-request verification memo. A multi-rule access policy verifies the same
// Bearer token once per matched rule — repeated Ed25519 verification of
// identical input dominates that cost, and its outcome is pure per
// (token, verifying key material). Only the PURE outcomes are memoized here:
// the compact-JWS parse (keyed by token) and the signature verdict (keyed by
// token + the key's fingerprint). Everything time-dependent or stateful —
// exp/nbf against time(), aud/sv checks, revocation lookups, the
// _spacefast_revocations_unavailable_flag path, and jti consumption — stays
// OUTSIDE the memo in _spacefast_visitor_verify and runs on every call.
// Function-static per request (house style: _spacefast_revocation_state_memo
// in shared/storage.php); deliberately NOT APCu — a signature verdict must
// never outlive the request that computed it, and key material derived per
// space must not land in pool-shared storage.
// ---------------------------------------------------------------------------

const SPACEFAST_JWT_VERIFY_MEMO_MAX = 256;

function &_spacefast_jwt_verify_memo(): array
{
    static $memo = [];
    return $memo;
}

// Pathological guard only (a request presenting hundreds of distinct tokens):
// dropping the memo re-verifies, never mis-verifies.
function _spacefast_jwt_verify_memo_reserve(array &$memo): void
{
    if (count($memo) >= SPACEFAST_JWT_VERIFY_MEMO_MAX) {
        $memo = [];
    }
}

function _spacefast_jwt_parse_memo(string $token): ?array
{
    $memo = &_spacefast_jwt_verify_memo();
    $key = 'parse:' . hash('sha256', $token);
    if (!array_key_exists($key, $memo)) {
        _spacefast_jwt_verify_memo_reserve($memo);
        $memo[$key] = _spacefast_jwt_parse($token);
    }
    return $memo[$key];
}

// Memoized signature verdict. `$keyFingerprint` identifies the verifying key
// material — the issuer fingerprint for Ed25519, the derived-key hash for the
// space-local HS256 lane — so the same token checked against two different
// keys can never share a verdict (and a key rotation mid-request changes the
// fingerprint, forcing a fresh verify). Both verdicts memoize: a forged token
// is rejected exactly as many times as it is presented, just cheaper.
function _spacefast_jwt_signature_valid_memo(string $keyFingerprint, string $token, callable $verify): bool
{
    $memo = &_spacefast_jwt_verify_memo();
    $key = 'sig:' . hash('sha256', $keyFingerprint . "\0" . $token);
    if (!array_key_exists($key, $memo)) {
        _spacefast_jwt_verify_memo_reserve($memo);
        $memo[$key] = $verify() === true;
    }
    return $memo[$key] === true;
}

// Derives the space-local HS256 key for one password wall. `$secret` is the
// resolved verifier hash (or shared secret) the acquire's ref points at. The
// session version folds in so logout-all (an `sv` bump) re-keys every wall.
function _spacefast_local_pw_key(string $ruleId, string $secret, int $sessionVersion): string
{
    if ($ruleId === '' || $secret === '') {
        return '';
    }
    return hash_hmac(
        'sha256',
        SPACEFAST_LOCAL_PW_KEY_LABEL . ':' . $ruleId . ':' . $sessionVersion,
        $secret,
        true
    );
}

// Mints a space-local HS256 visitor token carrying exactly `pw:{ruleId}`.
function _spacefast_mint_local_pw_token(string $ruleId, string $key, string $host, int $sessionVersion, int $ttlSeconds): string
{
    $now = time();
    $header = _spacefast_base64url_encode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT',
        'kid' => SPACEFAST_LOCAL_PW_TOKEN_KID,
    ], JSON_UNESCAPED_SLASHES));
    $claims = _spacefast_base64url_encode(json_encode([
        'sub' => 'pw:anon',
        'grants' => ['pw:' . $ruleId],
        'aud' => strtolower($host),
        'sv' => $sessionVersion,
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $ttlSeconds,
    ], JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $claims;
    $signature = _spacefast_base64url_encode(hash_hmac('sha256', $signingInput, $key, true));
    return $signingInput . '.' . $signature;
}

// Selects an Ed25519 issuer by kid. A rule may register several issuer keys
// (mixed audiences / BYO); an empty kid or empty issuer kid matches the first
// Ed25519 issuer so a single-issuer rule needs no kid pinning.
function _spacefast_jwt_issuer_for_kid(array $issuers, string $kid): ?array
{
    foreach ($issuers as $issuer) {
        if (!is_array($issuer) || ($issuer['alg'] ?? 'EdDSA') !== 'EdDSA') {
            continue;
        }
        $issuerKid = is_string($issuer['kid'] ?? null) ? $issuer['kid'] : '';
        if ($kid === '' || $issuerKid === '' || $issuerKid === $kid) {
            return $issuer;
        }
    }
    return null;
}

// Drops every grant not prefixed by one of the signing key's namespaces — the
// blast-radius bound for both BYO keys and the space-local key.
function _spacefast_jwt_filter_grants(array $grants, array $namespaces): array
{
    $kept = [];
    foreach ($grants as $grant) {
        if (!is_string($grant) || $grant === '') {
            continue;
        }
        foreach ($namespaces as $namespace) {
            if (is_string($namespace) && $namespace !== '' && str_starts_with($grant, $namespace)) {
                $kept[] = $grant;
                break;
            }
        }
    }
    return $kept;
}

function _spacefast_jwt_issuer_fingerprint(array $issuer): string
{
    return hash('sha256', json_encode([
        'alg' => is_string($issuer['alg'] ?? null) ? (string) $issuer['alg'] : 'EdDSA',
        'kid' => is_string($issuer['kid'] ?? null) ? (string) $issuer['kid'] : '',
        'publicKey' => is_string($issuer['publicKey'] ?? null) ? (string) $issuer['publicKey'] : '',
        'grantNamespaces' => array_values(is_array($issuer['grantNamespaces'] ?? null) ? $issuer['grantNamespaces'] : []),
    ], JSON_UNESCAPED_SLASHES));
}

function _spacefast_jwt_issuer_requires_audience(array $namespaces): bool
{
    $hasNamespace = false;
    foreach ($namespaces as $namespace) {
        if (!is_string($namespace) || $namespace === '') {
            continue;
        }
        $hasNamespace = true;
        if (!str_starts_with($namespace, 'ext:')) {
            return false;
        }
    }
    return $hasNamespace;
}

// THE visitor verify. Returns ['sub','grants','exp','claims'] on success, null
// on any failure (fail closed). Options:
//   issuers          Ed25519 issuer keys for this rule (default []).
//   host             request host for optional `aud` pinning.
//   sessionVersion   the policy session version; `sv` (absent = 0) must equal it.
//   localPwResolver  callable(string $ruleId): ?string returning the derived
//                    HS256 key, or null. Enables the space-local `pw:` lane.
//   requireJti       when true (callback handoffs, X-29), `jti` + `iat` are
//                    required, `iat` must be within `iatMaxAge`, and the jti is
//                    consumed single-use in the shared replay cache.
//   iatMaxAge        max age (seconds) of `iat` when requireJti (default 300).
//   privateRoot      private storage root for the jti replay cache (requireJti).
//                    Also used with `spaceId` to load visitor revocation tombstones.
//   spaceId          current space id for local revocation tombstones.
//   revocations      optional preloaded ['grants' => set, 'subs' => set].
// Sticky "revocation state was unavailable while a presented token was being
// verified" fault flag, owned here next to the verifier that raises it. The
// enforcer (access-rules.php) resets it once per evaluation and polls it at
// both loop levels to hard-deny the whole request; only token-presenting
// verifications ever raise it, so public/no-token requests are unaffected.
// Pass a bool to set, no argument to read.
function _spacefast_revocations_unavailable_flag(?bool $set = null): bool
{
    static $unavailable = false;
    if ($set !== null) {
        $unavailable = $set;
    }
    return $unavailable;
}

function _spacefast_visitor_verify(string $token, array $options): ?array
{
    $parsed = _spacefast_jwt_parse_memo($token);
    if ($parsed === null) {
        return null;
    }
    $header = $parsed['header'];
    $claims = $parsed['claims'];
    $alg = $header['alg'] ?? null;
    $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';

    $now = time();
    $claimExp = isset($claims['exp']) ? (int) $claims['exp'] : null;
    if ($claimExp !== null && $claimExp < $now - 300) {
        return null; // expired (±5-min leeway).
    }
    if (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 300) {
        return null; // not yet valid.
    }
    $host = strtolower((string) ($options['host'] ?? ''));
    if (isset($claims['aud']) && strtolower((string) $claims['aud']) !== $host) {
        return null; // audience-bound token targets another host.
    }
    $sessionVersion = (int) ($options['sessionVersion'] ?? 0);
    $sv = isset($claims['sv']) ? (int) $claims['sv'] : 0;
    if ($sv !== $sessionVersion) {
        return null; // logout-all / password rotation revoked this token.
    }

    $rawGrants = is_array($claims['grants'] ?? null) ? $claims['grants'] : [];
    $issuerFingerprint = null;

    if ($alg === 'EdDSA') {
        $issuers = is_array($options['issuers'] ?? null) ? $options['issuers'] : [];
        $issuer = _spacefast_jwt_issuer_for_kid($issuers, $kid);
        if ($issuer === null) {
            return null;
        }
        // Fingerprint first (pure, derived from the issuer row alone) so it can
        // key the memoized signature verdict.
        $issuerFingerprint = _spacefast_jwt_issuer_fingerprint($issuer);
        $publicKey = _spacefast_base64url_decode((string) ($issuer['publicKey'] ?? ''));
        $signatureValid = _spacefast_jwt_signature_valid_memo(
            $issuerFingerprint,
            $token,
            static fn (): bool => _spacefast_jwt_ed25519_valid($parsed['signing_input'], $parsed['signature'], $publicKey),
        );
        if (!$signatureValid) {
            return null;
        }
        $namespaces = is_array($issuer['grantNamespaces'] ?? null) ? $issuer['grantNamespaces'] : [];
        if (_spacefast_jwt_issuer_requires_audience($namespaces) && !isset($claims['aud'])) {
            return null;
        }
    } elseif ($alg === 'HS256' && $kid === SPACEFAST_LOCAL_PW_TOKEN_KID) {
        $resolver = $options['localPwResolver'] ?? null;
        if (!is_callable($resolver)) {
            return null;
        }
        // Extract the rule id from the (still-unverified) grant. If an attacker
        // rewrites it, the derived key won't match the signature; an unknown
        // rule id resolves to null and fails closed.
        $ruleId = _spacefast_pw_grant_rule_id($rawGrants);
        if ($ruleId === null) {
            return null;
        }
        $key = $resolver($ruleId);
        if (!is_string($key) || $key === '') {
            return null;
        }
        // The derived-key hash keys the memo, so a password rotation or `sv`
        // bump (both change the derivation) always forces a fresh verify.
        $issuerFingerprint = 'local-pw:' . hash('sha256', $ruleId . "\0" . $key);
        $signatureValid = _spacefast_jwt_signature_valid_memo(
            $issuerFingerprint,
            $token,
            static fn (): bool => _spacefast_jwt_hs256_valid($parsed['signing_input'], $parsed['signature'], $key),
        );
        if (!$signatureValid) {
            return null;
        }
        // The space-local key is namespace-locked to `pw:` — it can never sign
        // team:/user:/email: grants.
        $namespaces = ['pw:'];
    } else {
        return null; // unknown alg/kid.
    }

    $grants = _spacefast_jwt_filter_grants($rawGrants, $namespaces);
    $sub = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
    // A durable share URL is the sole visitor credential allowed to omit exp.
    // Decide only after signature verification + issuer namespace filtering:
    // the token must be audience-bound, replayable (no jti), and carry exactly
    // one `link:` grant identical to its `link:` subject. Every other visitor
    // token remains fail-closed on a missing exp.
    $durableShare = $claimExp === null
        && $alg === 'EdDSA'
        && is_string($claims['aud'] ?? null)
        && trim((string) $claims['aud']) !== ''
        && !isset($claims['jti'])
        && str_starts_with($sub, 'link:')
        && count($grants) === 1
        && ($grants[0] ?? null) === $sub;
    if ($claimExp === null && !$durableShare) {
        return null;
    }
    // The raw durable token never expires, but its browser cookie remains a
    // bounded 30-day session. Reusing the URL trades it for a fresh cookie;
    // structural grant removal or an sv bump revokes both immediately.
    $verifiedExp = $claimExp ?? ($now + 2592000);
    $revocations = _spacefast_visitor_revocations($options);
    if (($revocations['available'] ?? true) === false) {
        // Revocation state unavailable (corrupt/unreadable revocations.json —
        // the read layer already journaled the engine-health event). Never
        // honor a token whose revoked status we could not confirm: fail
        // closed. The enforcement side (access-rules.php: _spacefast_apply_rule,
        // directly after the only verification call) reads this flag to
        // hard-deny the whole request — no later rule and no fall-through may
        // override it — instead of an ordinary re-challenge.
        _spacefast_revocations_unavailable_flag(true);
        return null;
    }
    if ($sub !== '' && isset($revocations['subs'][$sub])) {
        return null;
    }
    if ($grants !== [] && $revocations['grants'] !== []) {
        $grants = array_values(array_filter($grants, static fn (string $grant): bool => !isset($revocations['grants'][$grant])));
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
        // Fail closed on the visitor lane: a replayed OR unstorable jti both
        // reject verification, which re-challenges the visitor (they bounce back
        // through authorize and mint a fresh token). No access is handed out when
        // the replay guard cannot record the id.
        if (_spacefast_jwt_consume_jti($privateRoot, 'visitor', $jti, $verifiedExp, $now) !== 'ok') {
            return null; // replayed or unstorable callback token.
        }
    }

    return [
        'sub' => $sub,
        'grants' => $grants,
        'exp' => $verifiedExp,
        'claims' => $claims,
        'issuerFingerprint' => $issuerFingerprint,
    ];
}

function _spacefast_visitor_revocations(array $options): array
{
    $empty = ['grants' => [], 'subs' => [], 'available' => true];
    if (is_array($options['revocations'] ?? null)) {
        $revocations = $options['revocations'];
        return [
            'grants' => is_array($revocations['grants'] ?? null) ? $revocations['grants'] : [],
            'subs' => is_array($revocations['subs'] ?? null) ? $revocations['subs'] : [],
            'available' => ($revocations['available'] ?? true) !== false,
        ];
    }
    $privateRoot = is_string($options['privateRoot'] ?? null) ? $options['privateRoot'] : '';
    $spaceId = is_string($options['spaceId'] ?? null) ? $options['spaceId'] : '';
    if ($privateRoot === '' || $spaceId === '') {
        return $empty;
    }
    return _spacefast_load_revocations($privateRoot, $spaceId);
}

function _spacefast_pw_grant_rule_id(array $grants): ?string
{
    foreach ($grants as $grant) {
        if (is_string($grant) && str_starts_with($grant, 'pw:') && strlen($grant) > 3) {
            return substr($grant, 3);
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Shared single-use replay cache (file-based, private storage). The management
// lane (admin/auth.php) and the visitor callback (X-29) both consume `jti` here
// so a lifted token is dead after first use with zero request-path network.
// ---------------------------------------------------------------------------

// Consumes a jti. Returns one of:
//   'ok'          — this call is the first to claim the jti.
//   'replayed'    — a live marker already exists (genuine replay).
//   'unavailable' — the marker write itself failed with no marker on disk
//                   (disk quota, permissions, read-only mount). A replay verdict
//                   requires evidence: reporting a storage outage as "replayed"
//                   turns a full disk into a permanent-looking auth failure and
//                   hides the real problem (and blocks the rescue ops that would
//                   free the disk). Callers translate this into a retryable
//                   status rather than a hard deny. `$namespace` separates the
//                   visitor and management caches so their id spaces never collide.
//
// Exclusive-create claim of one marker: the ONLY writer of these files, so the
// record shape and the mtime-as-exp invariant the cleanup sweep reads live here.
// Returns false when the marker could not be claimed (already present, or the
// write itself failed) — callers fall through to the on-disk evidence check.
function _spacefast_jwt_claim_jti_marker(string $path, array $record, int $exp): bool
{
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        return false;
    }
    fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
    fclose($handle);
    @touch($path, $exp);
    return true;
}

function _spacefast_jwt_consume_jti(string $privateRoot, string $namespace, string $jti, int $exp, int $now): string
{
    $dir = $privateRoot . '/runtime/jti';
    // Soft mkdir, NOT _stattic_runtime_mkdir: that helper hard-fails 500 on a
    // missing directory, but the replay guard's whole contract is that its own
    // storage failing must surface as 'unavailable' (-> retryable 503), never
    // as a generic runtime error a caller can't distinguish from an outage.
    if (!_stattic_runtime_mkdir_soft($dir)) {
        return 'unavailable';
    }
    // Housekeeping only — expired markers are also reclaimed inline on
    // collision below, so the sweep can run post-response.
    _spacefast_defer(static function () use ($dir, $now): void {
        _spacefast_jwt_cleanup_replay_cache($dir, $now);
    });

    $path = $dir . '/' . hash('sha256', $namespace . ':' . $jti) . '.json';
    _stattic_runtime_assert_private_path($path);
    $record = ['ns' => $namespace, 'jti' => $jti, 'exp' => $exp];
    if (_spacefast_jwt_claim_jti_marker($path, $record, $exp)) {
        return 'ok';
    }

    // The file exists: a live record is a replay, an expired one may be reclaimed.
    $mtime = @filemtime($path);
    if ($mtime === false || $mtime < $now) {
        $existing = _stattic_runtime_read_json($path);
        if (is_array($existing) && isset($existing['exp']) && (int) $existing['exp'] < $now) {
            @unlink($path);
            if (_spacefast_jwt_claim_jti_marker($path, $record, $exp)) {
                return 'ok';
            }
        }
    }

    // No marker on disk means the write failed, not that the id was replayed.
    if (!file_exists($path)) {
        return 'unavailable';
    }
    return 'replayed';
}

function _spacefast_jwt_cleanup_replay_cache(string $dir, int $now): void
{
    // At most once per 5 minutes per box: the full glob+stat sweep is
    // O(markers seen within the exp window) and otherwise runs inside every
    // single token consume — quadratic across a deploy burst. Markers reclaim
    // lazily on collision anyway (see the expired-record path in
    // _spacefast_jwt_consume_jti), so cleanup cadence only bounds disk usage,
    // not correctness.
    if (!_spacefast_marker_throttle($dir . '/.last-cleanup', 300, $now)) {
        return;
    }
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        _stattic_runtime_assert_private_path($path);
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime >= $now) {
            continue;
        }
        $record = _stattic_runtime_read_json($path);
        if (!is_array($record) || !isset($record['exp']) || (int) $record['exp'] < $now) {
            @unlink($path);
        }
    }
}

// Management-lane jti replay guard: same cache, fatal on replay (the management
// entrypoints answer JSON). Preserves the admin lane's error contract.
function _spacefast_jwt_reject_replayed_jti(string $privateRoot, string $audience, array $claims, int $now): void
{
    $jti = $claims['jti'] ?? null;
    if (!is_string($jti) || trim($jti) === '') {
        _stattic_json_response(403, ['error' => ['code' => 'runtime_jti_missing', 'message' => 'Runtime token id is required.']]);
    }
    $exp = isset($claims['exp']) ? (int) $claims['exp'] : $now;
    $status = _spacefast_jwt_consume_jti($privateRoot, $audience, $jti, $exp, $now);
    if ($status === 'unavailable') {
        // Storage outage, not a replay — answer retryable so a full disk does not
        // masquerade as a permanent auth failure (and block the rescue ops).
        _stattic_json_response(503, ['error' => ['code' => 'runtime_replay_guard_unavailable', 'message' => 'Runtime token replay guard storage is unavailable (disk full or not writable).']]);
    }
    if ($status !== 'ok') {
        _stattic_json_response(403, ['error' => ['code' => 'runtime_jti_replayed', 'message' => 'Runtime token id was already used.']]);
    }
}
