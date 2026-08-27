<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/response.php';
require_once __DIR__ . '/../shared/errors.php';
require_once __DIR__ . '/../shared/egress.php';
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/admission.php';

// Country and IP come ONLY from values a client cannot set (contracts §16, live
// probe 2026-08-07). Every header the edge was assumed to own reaches PHP
// attacker-controlled here: CF-Connecting-IP, X-Real-IP, CF-IPCountry,
// X-Forwarded-Host. None is read. `GEOIP_COUNTRY_CODE` is a server-set
// fastcgi_param (no HTTP_ prefix); REMOTE_ADDR is the connecting peer. Neither
// is an authorization input on its own: see _stattic_grant_decision.
function _stattic_access_context(
    array $serving,
    string $requestHost,
    string $requestPath,
    ?string $scopePath = null
): array {
    static $memo = [];
    $memoKey = $requestHost . '|' . $requestPath;
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }
    $path = $scopePath ?? _stattic_scope_path($requestPath);
    if ($path === null) {
        _stattic_render_json_or_deny('access_path_invalid', 'Access path is invalid.');
    }
    return $memo[$memoKey] = [
        'host' => _stattic_canonicalize_host($requestHost),
        'path' => $path,
        // Telemetry for the central exchange's rate limiting, never an admission
        // input: on this platform this is the provider's proxy, not the visitor.
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'country' => strtoupper((string) ($_SERVER['GEOIP_COUNTRY_CODE'] ?? '')),
        'space_id' => _stattic_serving_space_id($serving),
        'serving' => $serving,
    ];
}

function _stattic_canonicalize_host(string $host): string
{
    return rtrim(strtolower(trim($host)), '.');
}

function _stattic_request_is_fetch(): bool
{
    $mode = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')));
    if ($mode === 'cors') {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

// `sfv2_` IS the session: a signed claim set (authorities, the
// [access_gen, session_ver] tuple, an expiry, and the id of the revocation
// record) that the hot path verifies in memory, reading no file. `sfa1_` is the
// authority-less anonymous session.
//
// These two are HMAC-SHA256 over the per-space runtime exchange credential, not
// EdDSA: the runtime holds no signing key, and re-minting on a gen change must
// happen in-process with no round trip. Every token that CROSSES the trust
// boundary (handoff, platform bearer, blob gate) stays EdDSA-verified.
const STATTIC_ACCESS_SESSION_PREFIX = 'sfv2_';
const STATTIC_ANONYMOUS_SESSION_PREFIX = 'sfa1_';
const STATTIC_ANONYMOUS_SESSION_MAX_BYTES = 512;
// A Set-Cookie value has ~4096 bytes for everything, ~115 of them name and
// attributes. A claim set that still does not fit sheds its LRU authority.
const STATTIC_ACCESS_SESSION_MAX_BYTES = 3900;
const STATTIC_ACCESS_SESSION_CLAIM_VERSION = 1;
const STATTIC_ACCESS_SESSION_EXPIRY_DIAGNOSIS_SECONDS = 300;
// Exactly one principal per session. Capabilities come from the union of its
// authorities, never from the principal; identity is never inferred from one.
const STATTIC_SESSION_PRINCIPAL_ANONYMOUS = 'anonymous';
const STATTIC_ACCESS_IDENTITY_CHECK_SECONDS = 21600;
const STATTIC_ACCESS_SESSION_RECORD_MAX_BYTES = 8192;
const STATTIC_ACCESS_SESSION_IDLE_SECONDS = 604800;
const STATTIC_ACCESS_SESSION_ABSOLUTE_SECONDS = 2592000;
// The claim set's own life. Past it a request pays ONE record read and leaves
// with a fresh cookie. That is also how long a revoked (or stolen) cookie keeps
// working, so it is deliberately short.
const STATTIC_ACCESS_SESSION_CLAIM_TTL_SECONDS = 900;
// How often that revalidation writes `lastSeenAt` back to the server record.
const STATTIC_ACCESS_SESSION_TOUCH_INTERVAL_SECONDS = 21600;
// Must match the visitor-token verifier's clock leeway.
const STATTIC_ACCESS_SESSION_CLOCK_SKEW_SECONDS = 300;
const STATTIC_ACCESS_SESSION_SWEEP_THROTTLE_SECONDS = 3600;

function _stattic_page_serving(): array
{
    return is_array($GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null)
        ? $GLOBALS['SPACEFAST_PAGE_SERVING']
        : [];
}

function _stattic_is_anonymous_id(mixed $value): bool
{
    return is_string($value) && preg_match('/\Aanon_[a-f0-9]{32}\z/', $value) === 1;
}

// The visitor's pseudonym, or null when it is missing/malformed. It grants
// nothing, so callers degrade rather than reject. It is NOT a session id: the
// session id rotates whenever a credential is attached, and renaming a visitor
// mid-thread because they opened a share Link would be a bug.
function _stattic_collab_anonymous_id(array $source): ?string
{
    return _stattic_is_anonymous_id($source['anonymousId'] ?? null)
        ? $source['anonymousId']
        : null;
}

function _stattic_collab_mint_anonymous_id(): string
{
    return 'anon_' . bin2hex(random_bytes(16));
}

function _stattic_access_identity_record(?array $identity): ?array
{
    return is_array($identity) && is_array($identity['sessionRecord'] ?? null)
        ? $identity['sessionRecord']
        : null;
}

function _stattic_access_principal_valid(mixed $value): bool
{
    return is_string($value)
        && (
            $value === STATTIC_SESSION_PRINCIPAL_ANONYMOUS
            || preg_match(
                '/\A(?:account|person|external):[A-Za-z0-9_.-]{1,128}\z/',
                $value
            ) === 1
        );
}

// Credential lanes (link, password, machine, claim preview) mint tokens with
// no principal at all.
function _stattic_access_principal_from_claims(array $claims): ?string
{
    $principal = $claims['principal'] ?? null;
    return _stattic_access_principal_valid($principal)
        && $principal !== STATTIC_SESSION_PRINCIPAL_ANONYMOUS
        ? $principal
        : null;
}

function _stattic_access_principal_is_account(string $principal): bool
{
    return str_starts_with($principal, 'account:');
}

function _stattic_access_principal_is_identified(string $principal): bool
{
    return $principal !== STATTIC_SESSION_PRINCIPAL_ANONYMOUS;
}

function _stattic_access_identity_principal(?array $identity): string
{
    if (!is_array($identity)) {
        return STATTIC_SESSION_PRINCIPAL_ANONYMOUS;
    }
    $record = _stattic_access_identity_record($identity);
    if ($record !== null && _stattic_access_principal_valid($record['principal'] ?? null)) {
        return $record['principal'];
    }
    return _stattic_access_principal_from_claims(
        is_array($identity['claims'] ?? null) ? $identity['claims'] : []
    ) ?? STATTIC_SESSION_PRINCIPAL_ANONYMOUS;
}

function _stattic_access_identity_checked_at(?array $identity): ?int
{
    $record = _stattic_access_identity_record($identity);
    return is_int($record['identityCheckedAt'] ?? null) ? $record['identityCheckedAt'] : null;
}

function _stattic_access_identity_requested_path(?array $identity): ?string
{
    $record = _stattic_access_identity_record($identity);
    return is_string($record['accessRequestedPath'] ?? null)
        ? $record['accessRequestedPath']
        : null;
}

function _stattic_access_identity_expiry_live(?array $identity): bool
{
    $record = _stattic_access_identity_record($identity);
    $expiredAt = is_int($record['expiredAt'] ?? null) ? $record['expiredAt'] : null;
    if ($expiredAt === null) {
        return false;
    }
    $now = _stattic_access_session_now();
    return $now - $expiredAt <= STATTIC_ACCESS_SESSION_EXPIRY_DIAGNOSIS_SECONDS;
}

function _stattic_access_session_now(): int
{
    // Test-only switch; production never enables it, so client input cannot
    // influence session lifetime.
    if (_stattic_config_value('SPACEFAST_RUNTIME_TEST_ACCESS_SESSION_CLOCK') === '1') {
        $injected = $_SERVER['HTTP_X_SPACEFAST_TEST_ACCESS_SESSION_NOW'] ?? null;
        if (
            is_string($injected)
            && preg_match('/\A[1-9][0-9]{0,9}\z/', $injected) === 1
        ) {
            return (int) $injected;
        }
    }
    return time();
}

// The session record is the revocation ledger and nothing else: it holds no
// authority, so losing it can only deny. Deleting it (logout) ends that ONE
// session. Null when the space is not resolved yet: no space, no session.
function _stattic_access_session_store(string $privateRoot): ?array
{
    $spaceId = _stattic_serving_space_id(_stattic_page_serving());
    if ($privateRoot === '' || $spaceId === '' || !_stattic_id_valid($spaceId)) {
        return null;
    }
    $root = _stattic_access_session_dir($privateRoot);
    return _stattic_record_store($root, [
        // An idle record is debris: its cookie can no longer be renewed.
        'retention' => [
            'mtime_seconds' => STATTIC_ACCESS_SESSION_IDLE_SECONDS,
            'throttle_seconds' => STATTIC_ACCESS_SESSION_SWEEP_THROTTLE_SECONDS,
            'marker' => $root . '/.last-cleanup',
        ],
    ]);
}

function _stattic_access_session_dir(string $privateRoot): string
{
    $spaceId = _stattic_serving_space_id(_stattic_page_serving());
    // Unresolved is a real bucket, not an error: nothing is ever written to it,
    // and callers that only need a directory name (test probes) still get one.
    return $spaceId !== '' && _stattic_id_valid($spaceId)
        ? _stattic_space_root($privateRoot, $spaceId) . '/sessions'
        : $privateRoot . '/spaces/.unresolved/sessions';
}

function _stattic_access_session_path(string $privateRoot, string $sessionId): string
{
    return _stattic_access_session_dir($privateRoot) . '/' . $sessionId . '.json';
}

function _stattic_access_session_record_json(array $record): ?string
{
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return null;
    }
    $serialized = $json . "\n";
    return strlen($serialized) <= STATTIC_ACCESS_SESSION_RECORD_MAX_BYTES
        ? $serialized
        : null;
}

function _stattic_utf16_length(string $value): ?int
{
    if (preg_match('//u', $value) !== 1) {
        return null;
    }
    $length = 0;
    $characters = [];
    if (preg_match_all('/./us', $value, $characters) === false) {
        return null;
    }
    foreach ($characters[0] as $character) {
        $firstByte = ord($character[0]);
        $length += $firstByte >= 0xf0 ? 2 : 1;
    }
    return $length;
}

function _stattic_access_public_profile(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $profile = [];
    foreach (['name' => 120, 'username' => 160] as $key => $maxLength) {
        $field = $value[$key] ?? null;
        if ($field === null) {
            continue;
        }
        if (!is_string($field)) {
            return null;
        }
        $trimmed = trim($field);
        $length = _stattic_utf16_length($trimmed);
        if ($trimmed === '' || $length === null || $length > $maxLength) {
            return null;
        }
        $profile[$key] = $trimmed;
    }
    $avatar = $value['avatar_url'] ?? null;
    if ($avatar !== null) {
        $parsed = is_string($avatar) && strlen($avatar) <= 2048 ? parse_url($avatar) : false;
        if (
            !is_array($parsed)
            || !in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parsed['host'] ?? null)
            || $parsed['host'] === ''
            || isset($parsed['user'])
            || isset($parsed['pass'])
        ) {
            return null;
        }
        $profile['avatar_url'] = $avatar;
    }
    return $profile !== [] ? $profile : null;
}

// One key per space and per purpose, derived from the space's runtime exchange
// credential: both cookies share that material, so the label is what stops
// either verifying as the other.
function _stattic_access_session_hmac_key(array $serving, string $label): ?string
{
    $exchange = _stattic_access_page_exchange($serving);
    $credential = $exchange !== null && is_string($exchange['credential'] ?? null)
        ? $exchange['credential']
        : '';
    return strlen($credential) >= 32 ? hash_hmac('sha256', $label, $credential) : null;
}

// A claim set is only ever read back from a signature this runtime produced, so
// this validates shape (an engine downgrade, a truncated write), not trust.
function _stattic_access_session_claims_valid(mixed $claims): bool
{
    if (
        !is_array($claims)
        || ($claims['v'] ?? null) !== STATTIC_ACCESS_SESSION_CLAIM_VERSION
        || !_stattic_is_sha256_hex($claims['sid'] ?? null)
        || !_stattic_access_principal_valid($claims['principal'] ?? null)
        || !is_string($claims['spaceId'] ?? null)
        || $claims['spaceId'] === ''
        || !is_string($claims['host'] ?? null)
        || $claims['host'] === ''
        || !is_int($claims['sessionVersion'] ?? null)
        || $claims['sessionVersion'] < 0
        || !is_int($claims['accessGeneration'] ?? null)
        || $claims['accessGeneration'] < 0
        || !is_int($claims['iat'] ?? null)
        || !is_int($claims['exp'] ?? null)
        || !is_array($claims['authorities'] ?? null)
        || count($claims['authorities']) < 1
        || count($claims['authorities']) > 16
    ) {
        return false;
    }
    $references = [];
    foreach ($claims['authorities'] as $entry) {
        if (
            !_stattic_access_session_authority_entry_valid($entry)
            || isset($references[$entry['reference']])
        ) {
            return false;
        }
        $references[$entry['reference']] = true;
    }
    return true;
}

// Wire shape only: entries travel under one-letter keys and expand on the way
// back in, so the rest of the engine never sees it. `e` is omitted when the
// authority carries no verified-email deadline.
const STATTIC_ACCESS_SESSION_AUTHORITY_KEYS = [
    'reference' => 'r',
    'generation' => 'g',
    'emailVerifiedUntil' => 'e',
    'openedAt' => 'o',
    'lastSeenAt' => 's',
];

function _stattic_access_session_pack_authorities(array $entries): array
{
    $packed = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            return [];
        }
        $row = [];
        foreach (STATTIC_ACCESS_SESSION_AUTHORITY_KEYS as $long => $short) {
            if (($entry[$long] ?? null) !== null) {
                $row[$short] = $entry[$long];
            }
        }
        $packed[] = $row;
    }
    return $packed;
}

function _stattic_access_session_unpack_authorities(mixed $packed): array
{
    if (!is_array($packed)) {
        return [];
    }
    $entries = [];
    foreach ($packed as $row) {
        if (!is_array($row)) {
            return [];
        }
        $entry = ['emailVerifiedUntil' => null];
        foreach (STATTIC_ACCESS_SESSION_AUTHORITY_KEYS as $long => $short) {
            if (array_key_exists($short, $row)) {
                $entry[$long] = $row[$short];
            }
        }
        $entries[] = $entry;
    }
    return $entries;
}

// The host is inside the signed message, so a value minted for one hostname
// never verifies on a sibling, and a page script can mint neither cookie.
function _stattic_signed_cookie_encode(
    array $serving,
    string $host,
    string $prefix,
    string $label,
    int $maxBytes,
    array $payload
): ?string {
    $key = _stattic_access_session_hmac_key($serving, $label);
    $canonicalHost = _stattic_canonicalize_host($host);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($key === null || $canonicalHost === '' || !is_string($json)) {
        return null;
    }
    $encoded = _stattic_base64url_encode($json);
    if (strlen($encoded) > $maxBytes) {
        return null;
    }
    return $prefix . $encoded . '.' . hash_hmac('sha256', $canonicalHost . "\0" . $encoded, $key);
}

function _stattic_signed_cookie_decode(
    array $serving,
    string $host,
    string $prefix,
    string $label,
    int $maxBytes,
    string $credential
): ?array {
    if (!str_starts_with($credential, $prefix)) {
        return null;
    }
    $key = _stattic_access_session_hmac_key($serving, $label);
    $canonicalHost = _stattic_canonicalize_host($host);
    if ($key === null || $canonicalHost === '') {
        return null;
    }
    $body = substr($credential, strlen($prefix));
    $separator = strrpos($body, '.');
    if ($separator === false) {
        return null;
    }
    $encoded = substr($body, 0, $separator);
    if (
        preg_match('/\A[A-Za-z0-9_-]{1,' . $maxBytes . '}\z/', $encoded) !== 1
        || !hash_equals(
            hash_hmac('sha256', $canonicalHost . "\0" . $encoded, $key),
            substr($body, $separator + 1)
        )
    ) {
        return null;
    }
    $decoded = json_decode(_stattic_base64url_decode($encoded), true);
    return is_array($decoded) ? $decoded : null;
}

function _stattic_access_session_encode(array $serving, string $host, array $claims): ?string
{
    $claims['authorities'] = _stattic_access_session_pack_authorities(
        is_array($claims['authorities'] ?? null) ? $claims['authorities'] : []
    );
    return _stattic_signed_cookie_encode(
        $serving,
        $host,
        STATTIC_ACCESS_SESSION_PREFIX,
        'spacefast-access-session',
        STATTIC_ACCESS_SESSION_MAX_BYTES,
        $claims
    );
}

// THE hot-path check: one HMAC over at most 3.5 KiB and one json_decode.
function _stattic_access_session_decode(array $serving, string $host, string $credential): ?array
{
    $claims = _stattic_signed_cookie_decode(
        $serving,
        $host,
        STATTIC_ACCESS_SESSION_PREFIX,
        'spacefast-access-session',
        STATTIC_ACCESS_SESSION_MAX_BYTES,
        $credential
    );
    if (is_array($claims)) {
        $claims['authorities'] = _stattic_access_session_unpack_authorities(
            $claims['authorities'] ?? null
        );
    }
    if (!_stattic_access_session_claims_valid($claims)) {
        return null;
    }
    if (array_key_exists('profile', $claims)) {
        $profile = _stattic_access_public_profile($claims['profile']);
        if ($profile === null) {
            unset($claims['profile']);
        } else {
            $claims['profile'] = $profile;
        }
    }
    // A malformed pseudonym still admits; the comments lane stamps a fresh one.
    if (_stattic_collab_anonymous_id($claims) === null) {
        unset($claims['anonymousId']);
    }
    if (!is_int($claims['identityCheckedAt'] ?? null) || $claims['identityCheckedAt'] <= 0) {
        unset($claims['identityCheckedAt']);
    }
    $requestedPath = is_string($claims['accessRequestedPath'] ?? null)
        ? _stattic_scope_path($claims['accessRequestedPath'])
        : null;
    if ($requestedPath === null) {
        unset($claims['accessRequestedPath']);
    } else {
        $claims['accessRequestedPath'] = $requestedPath;
    }
    return $claims;
}

// Shedding the least recently seen authority when the set overflows the cookie
// budget is the same LRU that bounds it at 16. $claims is updated in place, so
// the caller's copy is exactly what was signed.
function _stattic_access_session_encode_fitting(array $serving, string $host, array &$claims): ?string
{
    for ($attempt = 0; $attempt <= 16; $attempt += 1) {
        $encoded = _stattic_access_session_encode($serving, $host, $claims);
        if ($encoded !== null) {
            return $encoded;
        }
        $entries = is_array($claims['authorities'] ?? null) ? $claims['authorities'] : [];
        if (count($entries) <= 1) {
            return null;
        }
        $oldest = 0;
        foreach ($entries as $index => $entry) {
            if ($entry['lastSeenAt'] < $entries[$oldest]['lastSeenAt']) {
                $oldest = $index;
            }
        }
        array_splice($entries, $oldest, 1);
        $claims['authorities'] = $entries;
    }
    return null;
}

// One name, one lifetime: every lane that hands a browser a visitor session
// writes the same cookie.
function _stattic_access_set_session_cookie(string $value): void
{
    _stattic_set_cookie(
        _stattic_session_cookie_name(),
        $value,
        STATTIC_ACCESS_SESSION_ABSOLUTE_SECONDS
    );
}

function _stattic_access_session_issue(array $serving, string $host, array &$claims): bool
{
    $value = _stattic_access_session_encode_fitting($serving, $host, $claims);
    if ($value === null) {
        return false;
    }
    _stattic_access_set_session_cookie($value);
    return true;
}

// The revocation record. Absent means revoked (or never created), which is a
// denial. It never means "admit anyway".
function _stattic_access_session_record_read(string $privateRoot, string $sessionId): ?array
{
    $store = _stattic_access_session_store($privateRoot);
    if ($store === null || !_stattic_is_sha256_hex($sessionId)) {
        return null;
    }
    $record = _stattic_record_store_get($store, $sessionId);
    if (
        !is_array($record)
        || !is_string($record['spaceId'] ?? null)
        || $record['spaceId'] !== _stattic_serving_space_id(_stattic_page_serving())
        || !is_int($record['openedAt'] ?? null)
        || !is_int($record['lastSeenAt'] ?? null)
    ) {
        return null;
    }
    $now = _stattic_access_session_now();
    if (
        $record['openedAt'] <= $now - STATTIC_ACCESS_SESSION_ABSOLUTE_SECONDS
        || $record['lastSeenAt'] <= $now - STATTIC_ACCESS_SESSION_IDLE_SECONDS
    ) {
        return null;
    }
    return $record;
}

function _stattic_access_session_record_write(string $privateRoot, string $sessionId, array $record): bool
{
    $store = _stattic_access_session_store($privateRoot);
    if ($store === null || !_stattic_is_sha256_hex($sessionId)) {
        return false;
    }
    if (_stattic_access_session_record_json($record) === null) {
        return false;
    }
    _stattic_record_store_ensure($store);
    _stattic_record_store_put($store, $sessionId, $record);
    _stattic_defer(static function () use ($store): void {
        _stattic_record_store_sweep($store);
    });
    return true;
}

function _stattic_access_session_record_delete(string $privateRoot, string $sessionId): bool
{
    $store = _stattic_access_session_store($privateRoot);
    if ($store === null || !_stattic_is_sha256_hex($sessionId)) {
        return false;
    }
    _stattic_record_store_delete($store, $sessionId);
    return true;
}

// $storedRecord receives the claim set that was minted, so a caller deriving a
// second session from the same admission need not decode the cookie back.
function _stattic_access_session_create(
    array $serving,
    array $verified,
    string $host,
    int $sessionVersion,
    array $projection,
    ?array $authorityOverride = null,
    ?array &$storedRecord = null,
    array $inherit = []
): ?string
{
    $privateRoot = _stattic_access_private_root();
    if ($privateRoot === '') {
        return null;
    }
    // $sub is not stored; it is required only because a token that names no
    // subject is a malformed handoff.
    $sub = is_string($verified['sub'] ?? null) ? $verified['sub'] : '';
    $exp = (int) ($verified['exp'] ?? 0);
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    $spaceId = is_string($claims['spaceId'] ?? null) ? $claims['spaceId'] : '';
    $sessionId = is_string($claims['sid'] ?? null) ? $claims['sid'] : '';
    $now = _stattic_access_session_now();
    $authorityEntries = $authorityOverride;
    if ($authorityEntries === null) {
        $authorityEntries = _stattic_merge_authority_lru(
            [],
            is_array($claims['authorities'] ?? null) ? $claims['authorities'] : [],
            $projection,
            (($claims['emailVerified'] ?? false) === true),
            $now
        );
    }
    if (
        $sub === ''
        || $sessionVersion < 0
        || $spaceId === ''
        || $spaceId !== _stattic_serving_space_id($serving)
        || !_stattic_is_sha256_hex($sessionId)
        || !is_array($authorityEntries)
        || $authorityEntries === []
        || count($authorityEntries) > 16
        || $exp <= $now
    ) {
        return null;
    }
    foreach ($authorityEntries as $entry) {
        if (!_stattic_access_session_authority_entry_valid($entry)) {
            return null;
        }
    }
    $profile = _stattic_access_public_profile($claims['profile'] ?? null);
    $session = [
        'v' => STATTIC_ACCESS_SESSION_CLAIM_VERSION,
        'sid' => $sessionId,
        // Every non-account lane says nothing about identity, so an attaching
        // session keeps whoever it already was.
        'principal' => _stattic_access_principal_from_claims($claims)
            ?? (
                _stattic_access_principal_valid($inherit['principal'] ?? null)
                    ? $inherit['principal']
                    : STATTIC_SESSION_PRINCIPAL_ANONYMOUS
            ),
        'authorities' => array_values($authorityEntries),
        'sessionVersion' => $sessionVersion,
        'accessGeneration' => _stattic_projection_generation($serving),
        'spaceId' => $spaceId,
        'host' => strtolower(_stattic_canonicalize_host($host)),
        'iat' => $now,
        'exp' => $now + STATTIC_ACCESS_SESSION_CLAIM_TTL_SECONDS,
        ...($profile !== null ? ['profile' => $profile] : []),
        ..._stattic_access_session_carried_identity($inherit),
    ];
    // The record before the cookie: a cookie whose record never landed would be
    // denied at its first revalidation anyway.
    if (!_stattic_access_session_record_write($privateRoot, $sessionId, [
        'sid' => $sessionId,
        'spaceId' => $spaceId,
        'host' => $session['host'],
        'sessionVersion' => $sessionVersion,
        'openedAt' => $now,
        'lastSeenAt' => $now,
    ])) {
        return null;
    }
    $credential = _stattic_access_session_encode_fitting($serving, $host, $session);
    if ($credential === null) {
        _stattic_access_session_record_delete($privateRoot, $sessionId);
        return null;
    }
    $storedRecord = $session;
    return $credential;
}

// An attach must not re-anonymize a visitor mid-thread or forget that the
// account probe ran; anything malformed starts fresh rather than failing.
function _stattic_access_session_carried_identity(array $inherit): array
{
    $carried = [
        'anonymousId' => _stattic_collab_anonymous_id($inherit)
            ?? _stattic_collab_mint_anonymous_id(),
    ];
    if (is_int($inherit['identityCheckedAt'] ?? null) && $inherit['identityCheckedAt'] > 0) {
        $carried['identityCheckedAt'] = $inherit['identityCheckedAt'];
    }
    $requestedPath = is_string($inherit['accessRequestedPath'] ?? null)
        ? _stattic_scope_path($inherit['accessRequestedPath'])
        : null;
    if ($requestedPath !== null) {
        $carried['accessRequestedPath'] = $requestedPath;
    }
    return $carried;
}

function _stattic_access_session_inheritable(?array $identity): array
{
    $record = _stattic_access_identity_record($identity) ?? [];
    return [
        'principal' => $record['principal'] ?? null,
        'anonymousId' => $record['anonymousId'] ?? null,
        'identityCheckedAt' => $record['identityCheckedAt'] ?? null,
        'accessRequestedPath' => $record['accessRequestedPath'] ?? null,
    ];
}

// The anonymous session carries no authority, so it needs no record and no
// revocation lane. It is re-keyed on the session-version half of the gen tuple,
// never the sum: rotating the session version is the "sign everyone out" lever
// and must reach the stateless session too, while access_gen moves on every
// Grant edit and must NOT reset a visitor's Comments identity.
//
// Nothing here grants anything, so a malformed field degrades to
// "not remembered" instead of rejecting the session.
function _stattic_anonymous_session_payload(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $payload = [];
    // A stateless session still IS a session: it carries the one id that names
    // this browser's Cast session, minted on first write and kept from then on.
    // A recorded session that degrades to this one keeps the id it already had.
    if (_stattic_is_sha256_hex($value['sid'] ?? null)) {
        $payload['sid'] = $value['sid'];
    }
    $anonymousId = _stattic_collab_anonymous_id($value);
    if ($anonymousId !== null) {
        $payload['anonymousId'] = $anonymousId;
    }
    foreach (['identityCheckedAt', 'expiredAt'] as $key) {
        if (is_int($value[$key] ?? null) && $value[$key] > 0) {
            $payload[$key] = $value[$key];
        }
    }
    $requestedPath = is_string($value['accessRequestedPath'] ?? null)
        ? _stattic_scope_path($value['accessRequestedPath'])
        : null;
    if ($requestedPath !== null) {
        $payload['accessRequestedPath'] = $requestedPath;
    }
    return $payload;
}

function _stattic_anonymous_session_encode(
    array $serving,
    string $requestHost,
    array $payload
): ?string {
    $payload['sv'] = _stattic_session_version($serving);
    return _stattic_signed_cookie_encode(
        $serving,
        $requestHost,
        STATTIC_ANONYMOUS_SESSION_PREFIX,
        'spacefast-anonymous-session',
        STATTIC_ANONYMOUS_SESSION_MAX_BYTES,
        $payload
    );
}

function _stattic_anonymous_session_decode(
    array $serving,
    string $requestHost,
    string $credential
): ?array {
    $decoded = _stattic_signed_cookie_decode(
        $serving,
        $requestHost,
        STATTIC_ANONYMOUS_SESSION_PREFIX,
        'spacefast-anonymous-session',
        STATTIC_ANONYMOUS_SESSION_MAX_BYTES,
        $credential
    );
    // Re-keyed on the session version: a rotation retires every stateless
    // session along with every recorded one.
    if ($decoded === null || ($decoded['sv'] ?? null) !== _stattic_session_version($serving)) {
        return null;
    }
    $payload = _stattic_anonymous_session_payload($decoded);
    return $payload === [] ? null : $payload;
}

// Never carries an authority: a stateless session cannot admit anybody.
function _stattic_anonymous_session_identity(array $payload): array
{
    return [
        'authorities' => [],
        'emailVerifiedAuthorities' => [],
        'authorityEntries' => [],
        'sessionRecord' => [
            'principal' => STATTIC_SESSION_PRINCIPAL_ANONYMOUS,
            'authorities' => [],
            ...$payload,
        ],
        'claims' => ['authorities' => [], 'emailVerifiedAuthorities' => []],
        'token' => '',
    ];
}

function _stattic_anonymous_session_set(
    array $serving,
    string $requestHost,
    array $payload
): bool {
    $value = _stattic_anonymous_session_encode($serving, $requestHost, $payload);
    if ($value === null) {
        return false;
    }
    _stattic_access_set_session_cookie($value);
    return true;
}

// NEVER writes over a presented recorded credential: handing that browser a
// fresh empty session would silently drop the authorities its cookie names.
function _stattic_anonymous_session_write(
    array $serving,
    string $requestHost,
    array $fields
): ?array {
    if (_stattic_access_presented_session_id() !== null) {
        return null;
    }
    $payload = _stattic_anonymous_session_payload([
        'sid' => bin2hex(random_bytes(32)),
        ...(_stattic_anonymous_session_decode(
            $serving,
            $requestHost,
            _stattic_visitor_cookie_from_request()
        ) ?? []),
        ...$fields,
    ]);
    return _stattic_anonymous_session_set($serving, $requestHost, $payload) ? $payload : null;
}

// Null means nothing could be remembered; callers must accept that rather than
// mint a second session over the first.
function _stattic_access_session_remember(
    array $serving,
    string $requestHost,
    ?array $identity,
    array $fields
): ?array {
    $claims = is_string($identity['sessionId'] ?? null)
        ? _stattic_access_identity_record($identity)
        : null;
    if ($claims === null) {
        return _stattic_anonymous_session_write($serving, $requestHost, $fields);
    }
    $updated = [...$claims, ...$fields];
    return _stattic_access_session_issue($serving, $requestHost, $updated) ? $updated : null;
}

// Recorded win or lose, so the bounce happens once per staleness window
// instead of on every denial.
function _stattic_access_record_identity_check(
    array $serving,
    string $requestHost,
    ?array $identity
): void {
    _stattic_access_session_remember($serving, $requestHost, $identity, [
        'identityCheckedAt' => _stattic_access_session_now(),
    ]);
}

// A browser hint, not server request state: it only decides which copy the
// access page renders.
function _stattic_access_record_requested_path(
    array $serving,
    string $requestHost,
    ?array $identity,
    string $scope
): void {
    _stattic_access_session_remember($serving, $requestHost, $identity, [
        'accessRequestedPath' => $scope,
    ]);
}

function _stattic_authority_reference_valid(mixed $value): bool
{
    return is_string($value)
        && preg_match('/\A(?:member|person|link|password|machine|claim-preview|external):[A-Za-z0-9_.-]+\z/', $value) === 1;
}

function _stattic_access_session_authority_entry_valid(mixed $value): bool
{
    return is_array($value)
        && _stattic_authority_reference_valid($value['reference'] ?? null)
        && _stattic_is_sha256_hex($value['generation'] ?? null)
        && (
            ($value['emailVerifiedUntil'] ?? null) === null
            || (
                is_int($value['emailVerifiedUntil'])
                && $value['emailVerifiedUntil'] > 0
            )
        )
        && is_int($value['openedAt'] ?? null)
        && $value['openedAt'] > 0
        && is_int($value['lastSeenAt'] ?? null)
        && $value['lastSeenAt'] >= $value['openedAt'];
}

function _stattic_access_session_retained_authority_entries(array $entries, int $now): array
{
    return array_values(array_filter(
        $entries,
        static fn ($entry): bool =>
            _stattic_access_session_authority_entry_valid($entry)
            && $entry['openedAt'] > $now - STATTIC_ACCESS_SESSION_ABSOLUTE_SECONDS
            && $entry['lastSeenAt'] > $now - STATTIC_ACCESS_SESSION_IDLE_SECONDS
    ));
}

function _stattic_access_session_live_authority_entries(array $entries, int $now): array
{
    return array_values(array_filter(
        _stattic_access_session_retained_authority_entries($entries, $now),
        static fn (array $entry): bool =>
            $entry['openedAt'] <= $now + STATTIC_ACCESS_SESSION_CLOCK_SKEW_SECONDS
            && $entry['lastSeenAt'] <= $now + STATTIC_ACCESS_SESSION_CLOCK_SKEW_SECONDS
    ));
}

function _stattic_grant_pattern_segments(mixed $value): ?array
{
    if (
        !is_string($value)
        || strlen($value) > 1024
        || $value === ''
        || $value[0] !== '/'
        || str_starts_with($value, '//')
        || preg_match('/[\x00-\x1F\x7F\\\\?#]/', $value) === 1
        || preg_match('/%(?:2f|5c|2a)/i', $value) === 1
    ) {
        return null;
    }
    $segments = [];
    foreach (explode('/', $value) as $segment) {
        if ($segment === '') {
            continue;
        }
        if (str_contains($segment, '*') && $segment !== '*' && $segment !== '**') {
            return null;
        }
        $segments[] = $segment;
    }
    return $segments;
}

function _stattic_grant_segments_match(array $patternSegments, array $pathSegments): bool
{
    $patternCount = count($patternSegments);
    $pathCount = count($pathSegments);
    // Memo on (patternIndex,pathIndex): without it a run of k consecutive '**'
    // makes the '**' recursion re-explore the same suffix O(pathCount^k) times,
    // run per include/exclude on every visitor request. Compile collapses
    // adjacent '**' (see _stattic_grant_pattern_compiled_segments), so at most
    // one '**' recurses, but the memo keeps a hostile pattern/path pair bounded.
    $memo = [];
    $match = function (int $patternIndex, int $pathIndex) use (
        &$match,
        &$memo,
        $patternSegments,
        $pathSegments,
        $patternCount,
        $pathCount
    ): bool {
        $memoKey = $patternIndex . ',' . $pathIndex;
        if (isset($memo[$memoKey])) {
            return $memo[$memoKey];
        }
        if ($patternIndex >= $patternCount) {
            return $memo[$memoKey] = ($pathIndex === $pathCount);
        }
        $segment = $patternSegments[$patternIndex];
        if ($segment === '**') {
            if ($patternIndex === $patternCount - 1) {
                return $memo[$memoKey] = true;
            }
            for ($next = $pathIndex; $next <= $pathCount; $next += 1) {
                if ($match($patternIndex + 1, $next)) {
                    return $memo[$memoKey] = true;
                }
            }
            return $memo[$memoKey] = false;
        }
        if ($pathIndex >= $pathCount) {
            return $memo[$memoKey] = false;
        }
        if ($segment !== '*' && $segment !== $pathSegments[$pathIndex]) {
            return $memo[$memoKey] = false;
        }
        return $memo[$memoKey] = $match($patternIndex + 1, $pathIndex + 1);
    };
    return $match(0, 0);
}

function _stattic_grant_valid(mixed $grant): bool
{
    if (
        !is_array($grant)
        || !is_string($grant['id'] ?? null)
        || $grant['id'] === ''
        || !is_array($grant['audience'] ?? null)
        || !in_array($grant['audience']['kind'] ?? null, [
            'public', 'team', 'person', 'link', 'password', 'machine', 'external',
        ], true)
        || !is_array($grant['resources'] ?? null)
        || !is_array($grant['resources']['include'] ?? null)
        || $grant['resources']['include'] === []
        || !is_array($grant['resources']['exclude'] ?? null)
        || !is_array($grant['capabilities'] ?? null)
        || $grant['capabilities'] === []
        || !is_array($grant['constraints'] ?? null)
        || !is_array($grant['target'] ?? null)
        || !in_array($grant['target']['kind'] ?? null, [
            'live', 'version', 'branch', 'all_versions',
        ], true)
        || !is_array($grant['source'] ?? null)
        || !in_array($grant['source']['kind'] ?? null, ['config', 'managed', 'system'], true)
        || !is_int($grant['generation'] ?? null)
        || $grant['generation'] < 1
    ) {
        return false;
    }
    foreach ([...$grant['resources']['include'], ...$grant['resources']['exclude']] as $pattern) {
        if (_stattic_grant_pattern_segments($pattern) === null) {
            return false;
        }
    }
    foreach ($grant['capabilities'] as $capability) {
        if (!in_array($capability, [
            'page.view', 'comments.read', 'comments.write', 'content.publish', 'access.manage',
        ], true)) {
            return false;
        }
    }
    foreach (['notBefore', 'expiresAt'] as $constraint) {
        if (
            isset($grant['constraints'][$constraint])
            && (
                !is_string($grant['constraints'][$constraint])
                || strtotime($grant['constraints'][$constraint]) === false
            )
        ) {
            return false;
        }
    }
    $constraints = $grant['constraints'];
    if (array_diff(array_keys($constraints), [
        'notBefore', 'expiresAt', 'maxUses', 'requireVerifiedEmail', 'network',
    ]) !== []) {
        return false;
    }
    if (
        isset($constraints['maxUses'])
        && (
            !is_int($constraints['maxUses'])
            || $constraints['maxUses'] < 1
            || $constraints['maxUses'] > 1000000
        )
    ) {
        return false;
    }
    if (
        isset($constraints['requireVerifiedEmail'])
        && !is_bool($constraints['requireVerifiedEmail'])
    ) {
        return false;
    }
    if (isset($constraints['network'])) {
        $network = $constraints['network'];
        $networkKeys = is_array($network) ? array_keys($network) : [];
        if (
            !is_array($network)
            || array_diff($networkKeys, [
                'ipCidrs', 'countries', 'excludedCountries', 'excludedUserAgentSubstrings',
            ]) !== []
            || $networkKeys === []
        ) {
            return false;
        }
        $listValid = static function (mixed $list, int $max, callable $item): bool {
            return is_array($list) && $list !== [] && count($list) <= $max && array_all($list, $item);
        };
        // An IP constraint is still a well-formed Grant. It just admits nobody
        // (see _stattic_grant_decision); rejecting it here would null the whole
        // projection and close the Space.
        if (
            isset($network['ipCidrs'])
            && !$listValid($network['ipCidrs'], 50, static fn (mixed $cidr): bool => is_string($cidr) && $cidr !== '')
        ) {
            return false;
        }
        foreach (['countries', 'excludedCountries'] as $countrySelector) {
            if (
                isset($network[$countrySelector])
                && !$listValid($network[$countrySelector], 250, static fn (mixed $country): bool =>
                    is_string($country) && preg_match('/\A[A-Z]{2}\z/', $country) === 1)
            ) {
                return false;
            }
        }
        if (
            isset($network['excludedUserAgentSubstrings'])
            && !$listValid($network['excludedUserAgentSubstrings'], 50, static fn (mixed $needle): bool =>
                is_string($needle) && trim($needle) !== '' && strlen($needle) <= 200)
        ) {
            return false;
        }
    }
    $audienceKind = $grant['audience']['kind'] ?? null;
    if (
        isset($constraints['maxUses'])
        && !in_array($audienceKind, ['link', 'password', 'machine', 'external'], true)
    ) {
        return false;
    }
    if (
        !empty($constraints['requireVerifiedEmail'])
        && in_array($audienceKind, ['public', 'machine'], true)
    ) {
        return false;
    }
    if (
        $audienceKind === 'public'
        && (
            in_array('content.publish', $grant['capabilities'], true)
            || in_array('access.manage', $grant['capabilities'], true)
        )
    ) {
        return false;
    }
    if (
        in_array($audienceKind, ['link', 'password'], true)
        && in_array('access.manage', $grant['capabilities'], true)
    ) {
        return false;
    }
    if (
        $audienceKind === 'link'
        && in_array('content.publish', $grant['capabilities'], true)
        && (
            ($grant['source']['kind'] ?? null) !== 'system'
            || ($grant['source']['reference'] ?? null) !== 'open:preclaim-author'
        )
    ) {
        return false;
    }
    return true;
}

// Must match packages/common's runtime contract.
const STATTIC_AUTHORIZATION_GRANT_LIMIT = 1024;
const STATTIC_AUTHORIZATION_COMPILED_VERSION = 3;

// The segment list the matcher runs against: runs of '**' collapse ('**/**' ==
// '**') so the recursion meets at most one.
function _stattic_grant_pattern_compiled_segments(array $segments): array
{
    $collapsed = [];
    foreach ($segments as $segment) {
        if ($segment === '**' && array_last($collapsed) === '**') {
            continue;
        }
        $collapsed[] = $segment;
    }
    return $collapsed;
}

// An exclude pattern ending in 'index.html' ALSO compiles to the stripped form:
// the URL lane collapses a trailing index.html while the artifact lane keeps
// it, and an exclude naming an index artifact must close the route it serves.
//
// Includes deliberately do NOT get that variant: granting the stripped route
// would hand out access nobody wrote down. The asymmetry IS the rule, and
// packages/common/src/utils/grants.ts reads exclude as widely and include as
// narrowly.
function _stattic_grant_exclude_pattern_variants(array $segments): array
{
    $collapsed = _stattic_grant_pattern_compiled_segments($segments);
    $variants = [$collapsed];
    if (array_last($collapsed) === 'index.html') {
        $variants[] = array_slice($collapsed, 0, -1);
    }
    return $variants;
}

// Fail-closed bucket key for a grant's compiled include list: the single literal
// first segment shared by EVERY include, or null (fallback) when any include is
// root, wildcard/param-leading, or the includes disagree. A grant bucketed to X
// can only match requests whose first path segment is X, so scanning bucket X
// plus fallback loses no grant. Excludes never enter this decision: a missed
// exclude is a silent over-grant.
function _stattic_grant_bucket_key(array $includeVariants): ?string
{
    $key = null;
    foreach ($includeVariants as $segments) {
        $first = $segments[0] ?? null;
        if (!is_string($first) || $first === '*' || $first === '**') {
            return null;
        }
        if ($key === null) {
            $key = $first;
        } elseif ($key !== $first) {
            return null;
        }
    }
    return $key;
}

// The store starts as [] and only grows keys as grants land, so an empty lane
// stays === [] rather than ['fallback'=>[], 'by_first_segment'=>[]].
function _stattic_grant_index_place(array &$store, array $compiled): void
{
    $key = _stattic_grant_bucket_key($compiled['include']);
    if ($key === null) {
        $store['fallback'][] = $compiled;
        return;
    }
    $store['by_first_segment'][$key][] = $compiled;
}

function _stattic_grant_index_bucket_candidates(mixed $store, string $firstSegment): array
{
    if (!is_array($store)) {
        return [];
    }
    $fallback = is_array($store['fallback'] ?? null) ? $store['fallback'] : [];
    $bucket = $firstSegment !== '' && is_array($store['by_first_segment'][$firstSegment] ?? null)
        ? $store['by_first_segment'][$firstSegment]
        : [];
    if ($bucket === []) {
        return $fallback;
    }
    if ($fallback === []) {
        return $bucket;
    }
    return array_merge($fallback, $bucket);
}

function _stattic_compile_authorization_grant_index(array $projection): ?array
{
    $grants = $projection['grants'] ?? null;
    if (
        !is_array($grants)
        || count($grants) > STATTIC_AUTHORIZATION_GRANT_LIMIT
    ) {
        return null;
    }

    $public = [];
    $authorities = [];
    $generationSources = [];
    $lanes = ['password' => [], 'identity' => []];
    // Per target kind: does a public grant admit ANY path, anonymously, with no
    // constraint at all? That is the only shape where skipping the access code
    // matches running it. Computed here alone, so the overlay's `open` decision
    // (admin/generate.php) reads a flag instead of re-deriving grant semantics,
    // and a grant field this compiler learns tomorrow reaches it automatically.
    $unconditionalTargets = ['live' => false, 'all_versions' => false];
    $spaceClaimed = ($projection['spaceClaimed'] ?? false) === true;
    foreach ($grants as $grant) {
        if (!_stattic_grant_valid($grant)) {
            return null;
        }
        $constraints = $grant['constraints'];
        $notBefore = isset($constraints['notBefore'])
            ? strtotime($constraints['notBefore'])
            : null;
        $expiresAt = isset($constraints['expiresAt'])
            ? strtotime($constraints['expiresAt'])
            : null;
        if ($notBefore === false || $expiresAt === false) {
            return null;
        }
        $lane = [
            'target' => $grant['target'],
            'notBefore' => $notBefore,
            'expiresAt' => $expiresAt,
        ];
        $kind = $grant['audience']['kind'] ?? null;
        if ($kind === 'password') {
            $lanes['password'][] = $lane;
        }
        if ($kind === 'external' || $kind === 'person') {
            $lanes['identity'][] = $lane;
        }
        if (($grant['source']['kind'] ?? null) === 'config' && !$spaceClaimed) {
            continue;
        }
        $reference = _stattic_grant_audience_reference($grant['audience']);
        if ($reference === false) {
            return null;
        }
        $include = [];
        foreach ($grant['resources']['include'] as $pattern) {
            $segments = _stattic_grant_pattern_segments($pattern);
            if ($segments === null) {
                return null;
            }
            $include[] = _stattic_grant_pattern_compiled_segments($segments);
        }
        $exclude = [];
        foreach ($grant['resources']['exclude'] as $pattern) {
            $segments = _stattic_grant_pattern_segments($pattern);
            if ($segments === null) {
                return null;
            }
            foreach (_stattic_grant_exclude_pattern_variants($segments) as $variant) {
                $exclude[] = $variant;
            }
        }
        $compiled = [
            // Carried so a decision can name WHICH Grant admitted a request:
            // references collapse (one authority, many Grants), and the shared
            // conformance corpus asserts the rule that fired. Never an
            // authorization input.
            'id' => (string) $grant['id'],
            'reference' => $reference,
            'include' => $include,
            'exclude' => $exclude,
            'capabilities' => $grant['capabilities'],
            'target' => $grant['target'],
            'notBefore' => $notBefore,
            'expiresAt' => $expiresAt,
            'maxUses' => $constraints['maxUses'] ?? null,
            'requireVerifiedEmail' => ($constraints['requireVerifiedEmail'] ?? false) === true,
            'network' => is_array($constraints['network'] ?? null)
                ? $constraints['network']
                : null,
            'sharedCacheable' => $reference === null
                && in_array('page.view', $grant['capabilities'], true)
                && $constraints === [],
        ];
        if ($reference === null) {
            _stattic_grant_index_place($public, $compiled);
            // A Public Grant authorizes anonymous requests without a carried
            // reference. When a visitor signs in, the control plane attaches
            // their account identity to this synthetic authority so the
            // durable session has a revocable/expiring generation anchor.
            // The Grant remains in the public index: identity never becomes
            // the permission, and private Grants still require their own
            // authority. Mirrors authorityGrantGeneration in the control plane.
            $publicAccountReference = 'external:grant.' . (string) $grant['id'];
            $generationSources[$publicAccountReference][] = [
                'source' => (string) $grant['id'] . ':' . (string) $grant['generation'],
                'expiresAt' => $expiresAt,
            ];
            // `$constraints === []` rather than a field list: any constraint,
            // including one that doesn't exist yet, defeats unconditionality.
            // `['**']` as an include variant also forces the fallback bucket
            // (_stattic_grant_bucket_key), so the flag never claims more than a
            // full bucket scan would find.
            $targetKind = $compiled['target']['kind'] ?? null;
            if (
                ($targetKind === 'live' || $targetKind === 'all_versions')
                && $constraints === []
                && $compiled['exclude'] === []
                && in_array('page.view', $compiled['capabilities'], true)
                && in_array(['**'], $compiled['include'], true)
            ) {
                $unconditionalTargets[$targetKind] = true;
            }
        } else {
            $authorities[$reference] ??= [];
            _stattic_grant_index_place($authorities[$reference], $compiled);
            $generationSources[$reference][] = [
                'source' => (string) $grant['id'] . ':' . (string) $grant['generation'],
                'expiresAt' => $expiresAt,
            ];
        }

    }

    $generations = [];
    foreach ($generationSources as $reference => $entries) {
        usort($entries, static fn (array $left, array $right): int =>
            strcmp($left['source'], $right['source']));
        $generations[$reference] = array_map(
            static fn (array $entry): array => [
                'hash' => hash('sha256', $entry['source']),
                'expiresAt' => $entry['expiresAt'],
            ],
            $entries
        );
    }

    return [
        'grantCount' => count($grants),
        'public' => $public,
        'authorities' => $authorities,
        'generations' => $generations,
        'lanes' => $lanes,
        'unconditionalTargets' => $unconditionalTargets,
    ];
}

// The envelope every projection carries, compiled or raw.
function _stattic_authorization_envelope_valid(mixed $value): bool
{
    return is_array($value)
        && is_int($value['generation'] ?? null)
        && $value['generation'] >= 0
        && is_int($value['sessionVersion'] ?? null)
        && $value['sessionVersion'] >= 0
        && in_array($value['fence'] ?? null, ['none', 'ownership', 'exposure'], true)
        && is_string($value['acquireUrl'] ?? null)
        && filter_var($value['acquireUrl'], FILTER_VALIDATE_URL) !== false
        && is_bool($value['spaceClaimed'] ?? null);
}

function _stattic_compile_authorization_projection(mixed $value): ?array
{
    if (!_stattic_authorization_envelope_valid($value)) {
        return null;
    }
    $index = _stattic_compile_authorization_grant_index($value);
    if ($index === null) {
        return null;
    }
    return [
        'compiledVersion' => STATTIC_AUTHORIZATION_COMPILED_VERSION,
        'generation' => $value['generation'],
        'sessionVersion' => $value['sessionVersion'],
        'fence' => $value['fence'],
        'acquireUrl' => $value['acquireUrl'],
        'accessPage' => is_array($value['accessPage'] ?? null) ? $value['accessPage'] : null,
        'spaceClaimed' => $value['spaceClaimed'],
        'grantIndex' => $index,
    ];
}

function _stattic_authorization_projection_compiled(mixed $value): bool
{
    if (
        !_stattic_authorization_envelope_valid($value)
        || ($value['compiledVersion'] ?? null) !== STATTIC_AUTHORIZATION_COMPILED_VERSION
        || !is_array($value['grantIndex'] ?? null)
    ) {
        return false;
    }
    $index = $value['grantIndex'];
    return is_int($index['grantCount'] ?? null)
        && $index['grantCount'] >= 0
        && $index['grantCount'] <= STATTIC_AUTHORIZATION_GRANT_LIMIT
        && is_array($index['public'] ?? null)
        && is_array($index['authorities'] ?? null)
        && is_array($index['generations'] ?? null)
        && is_array($index['lanes'] ?? null)
        && is_array($index['lanes']['password'] ?? null)
        && is_array($index['lanes']['identity'] ?? null)
        && is_bool($index['unconditionalTargets']['live'] ?? null)
        && is_bool($index['unconditionalTargets']['all_versions'] ?? null);
}

function _stattic_authorization_grant_index(array $projection): ?array
{
    return _stattic_authorization_projection_compiled($projection)
        ? $projection['grantIndex']
        : null;
}

// $firstSegment is the request path's first segment (''=root); it selects each
// store's bucket alongside the always-checked fallback, and defaults to '' for
// callers enumerating fallback-only lanes.
function _stattic_grant_candidates(array $projection, array $authorities, string $firstSegment = ''): array
{
    $index = is_array($projection['grantIndex'] ?? null) ? $projection['grantIndex'] : null;
    if ($index === null) {
        return [];
    }
    $candidates = _stattic_grant_index_bucket_candidates($index['public'] ?? null, $firstSegment);
    $seen = [];
    foreach ($authorities as $authority) {
        if (
            !is_string($authority)
            || isset($seen[$authority])
            || !is_array($index['authorities'][$authority] ?? null)
        ) {
            continue;
        }
        $seen[$authority] = true;
        foreach (
            _stattic_grant_index_bucket_candidates($index['authorities'][$authority], $firstSegment) as $grant
        ) {
            $candidates[] = $grant;
        }
    }
    return $candidates;
}

function _stattic_grant_target(array $serving): array
{
    if (!empty($serving['immutable']) && is_string($serving['version_id'] ?? null)) {
        return ['kind' => 'version', 'versionId' => $serving['version_id']];
    }
    $routeName = is_string($serving['route_name'] ?? null) ? $serving['route_name'] : '';
    return $routeName !== '' && $routeName !== 'production'
        ? ['kind' => 'branch', 'branch' => $routeName]
        : ['kind' => 'live'];
}

function _stattic_grant_target_matches(array $selector, array $target): bool
{
    return match ($selector['kind'] ?? null) {
        'live' => ($target['kind'] ?? null) === 'live',
        'version' => ($target['kind'] ?? null) === 'version'
            && ($target['versionId'] ?? null) === ($selector['versionId'] ?? null),
        'branch' => ($target['kind'] ?? null) === 'branch'
            && ($target['branch'] ?? null) === ($selector['branch'] ?? null),
        // Immutable Version hosts only: never the live host, never a branch.
        'all_versions' => ($target['kind'] ?? null) === 'version',
        default => false,
    };
}

// An external audience is (issuer, subject): BOTH, always. `spacefast-membership`
// and `claim-preview` name their issuer in the prefix; every other issuer is a
// Team-configured identity connection, hashed together with the subject so one
// provider's `alice` can never satisfy another provider's Grant. Hashing rather
// than joining keeps the reference inside the authority alphabet
// (_stattic_authority_reference_valid) even though an issuer is an arbitrary
// URL, and keeps it short enough for the session cookie budget.
// Mirrors externalAuthorityReference in
// apps/control-plane/src/access/authority-generation.ts byte for byte.
function _stattic_grant_audience_reference(array $audience): string|false|null
{
    return match ($audience['kind'] ?? null) {
        'public' => null,
        'team' => 'team:' . ($audience['teamId'] ?? ''),
        'person' => 'person:' . ($audience['personId'] ?? ''),
        'link' => 'link:' . ($audience['linkId'] ?? ''),
        'password' => 'password:' . ($audience['credentialId'] ?? ''),
        'machine' => 'machine:' . ($audience['machineId'] ?? ''),
        'external' => match ($audience['issuer'] ?? null) {
            'spacefast-membership' => 'member:' . ($audience['subject'] ?? ''),
            'claim-preview' => 'claim-preview:' . ($audience['subject'] ?? ''),
            default => 'external:' . hash(
                'sha256',
                (string) ($audience['issuer'] ?? '') . "\0" . (string) ($audience['subject'] ?? '')
            ),
        },
        default => false,
    };
}

// Once per request, after the response: a Space whose Grants still carry an IP
// constraint is serving with that Grant closed, and the operator has to see
// that in the journal.
function _stattic_grant_network_ip_unsupported(array $grant): void
{
    static $journaled = false;
    if ($journaled) {
        return;
    }
    $journaled = true;
    $privateRoot = _stattic_access_private_root();
    if ($privateRoot === '') {
        return;
    }
    $reference = is_string($grant['reference'] ?? null) ? $grant['reference'] : 'public';
    _stattic_defer(static function () use ($privateRoot, $reference): void {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'network_grant_ignored',
            'space_id' => _stattic_serving_space_id(_stattic_page_serving()),
            'authority' => $reference,
            'reason' => 'ip_constraints_unenforceable',
        ], false);
    });
}

// There is no `$ipAddress`: IP constraints are unenforceable on this platform
// (contracts §16, the forwarding headers are attacker-controlled), so a Grant
// carrying one admits nobody rather than everybody who can set a header.
// `$country` comes from the server-set fastcgi_param only, and is empty when the
// provider sets none, which fails every country selector closed.
function _stattic_grant_decision(
    array $projection,
    string $path,
    array $target,
    array $authorities,
    array $verifiedEmailAuthorities = [],
    string $country = '',
    string $userAgent = '',
    bool $artifactResource = false,
    bool $pathCanonical = false,
    // Test seams, both inert on the serve path: $nowOverride pins the clock for
    // expiry-boundary assertions, $matchedGrantIds collects the Grants that
    // fired. The returned decision shape is the same either way.
    ?int $nowOverride = null,
    ?array &$matchedGrantIds = null
): array {
    // True means $path already came from _stattic_scope_path or
    // _stattic_artifact_scope_path; raw request paths must leave this false.
    $canonicalPath = $pathCanonical
        ? $path
        : ($artifactResource
            ? _stattic_artifact_scope_path($path)
            : _stattic_scope_path($path));
    if ($canonicalPath === null) {
        return ['capabilities' => [], 'references' => [], 'sharedCacheable' => false];
    }
    $pathSegments = $canonicalPath === '/' ? [] : explode('/', substr($canonicalPath, 1));
    $firstSegment = $pathSegments[0] ?? '';
    $capabilities = [];
    $references = [];
    $sharedCacheable = false;
    $now = $nowOverride ?? time();
    foreach (_stattic_grant_candidates($projection, $authorities, $firstSegment) as $grant) {
        if (
            !is_array($grant)
            || (is_int($grant['notBefore'] ?? null) && $grant['notBefore'] > $now)
            || (is_int($grant['expiresAt'] ?? null) && $grant['expiresAt'] <= $now)
            || !is_array($grant['target'] ?? null)
            || !_stattic_grant_target_matches($grant['target'], $target)
        ) {
            continue;
        }
        $included = false;
        foreach (is_array($grant['include'] ?? null) ? $grant['include'] : [] as $patternSegments) {
            if (
                is_array($patternSegments)
                && _stattic_grant_segments_match($patternSegments, $pathSegments)
            ) {
                $included = true;
                break;
            }
        }
        if (!$included) {
            continue;
        }
        foreach (is_array($grant['exclude'] ?? null) ? $grant['exclude'] : [] as $patternSegments) {
            if (
                is_array($patternSegments)
                && _stattic_grant_segments_match($patternSegments, $pathSegments)
            ) {
                continue 2;
            }
        }
        $reference = is_string($grant['reference'] ?? null) ? $grant['reference'] : null;
        // maxUses is consumed at authority acquisition; a public Grant has no
        // acquisition boundary, so it fails closed.
        if (is_int($grant['maxUses'] ?? null) && !is_string($reference)) {
            continue;
        }
        if (
            ($grant['requireVerifiedEmail'] ?? false) === true
            && (
                !is_string($reference)
                || !in_array($reference, $verifiedEmailAuthorities, true)
            )
        ) {
            continue;
        }
        $network = is_array($grant['network'] ?? null) ? $grant['network'] : null;
        if ($network !== null) {
            // An IP allowlist cannot be honoured: no header reaching PHP here
            // names the visitor, so "matches the CIDR" would mean "sent the
            // right X-Real-IP". Fail closed and say so once.
            if (isset($network['ipCidrs'])) {
                _stattic_grant_network_ip_unsupported($grant);
                continue;
            }
            if (
                isset($network['countries'])
                && !in_array(strtoupper($country), $network['countries'], true)
            ) {
                continue;
            }
            if (
                isset($network['excludedCountries'])
                && (
                    $country === ''
                    || in_array(strtoupper($country), $network['excludedCountries'], true)
                )
            ) {
                continue;
            }
            if (isset($network['excludedUserAgentSubstrings'])) {
                if ($userAgent === '') {
                    continue;
                }
                foreach ($network['excludedUserAgentSubstrings'] as $needle) {
                    if (stripos($userAgent, $needle) !== false) {
                        continue 2;
                    }
                }
            }
        }
        foreach (is_array($grant['capabilities'] ?? null) ? $grant['capabilities'] : [] as $capability) {
            if (is_string($capability)) {
                $capabilities[$capability] = true;
            }
        }
        if (($grant['sharedCacheable'] ?? false) === true) {
            $sharedCacheable = true;
        }
        if ($matchedGrantIds !== null && is_string($grant['id'] ?? null)) {
            $matchedGrantIds[] = $grant['id'];
        }
        if (is_string($reference)) {
            $references[$reference] = true;
        }
    }
    return [
        'capabilities' => array_keys($capabilities),
        'references' => array_keys($references),
        'sharedCacheable' => $sharedCacheable,
    ];
}

// Resolved artifacts keep their own memo key, and therefore their own
// authorization boundary.
function _stattic_scoped_admission_context(
    array $serving,
    string $requestHost,
    string $requestPath,
    bool $artifactResource = false
): array {
    static $memo = [];
    $projection = $serving['authorization'] ?? null;
    $generation = is_array($projection) && is_int($projection['generation'] ?? null)
        ? $projection['generation']
        : -1;
    $memoKey = implode("\0", [
        $artifactResource ? 'artifact' : 'url',
        $requestHost,
        $requestPath,
        (string) ($serving['space_id'] ?? ''),
        (string) ($serving['version_id'] ?? ''),
        (string) ($serving['route_name'] ?? ''),
        !empty($serving['immutable']) ? 'immutable' : 'mutable',
        (string) $generation,
    ]);
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    if (!_stattic_authorization_projection_compiled($projection)) {
        return $memo[$memoKey] = ['error' => 'projection'];
    }
    $path = $artifactResource
        ? _stattic_artifact_scope_path($requestPath)
        : _stattic_scope_path($requestPath);
    if ($path === null) {
        return $memo[$memoKey] = ['error' => 'path'];
    }
    $target = _stattic_grant_target($serving);
    $accessContext = _stattic_access_context(
        $serving,
        $requestHost,
        $requestPath,
        // The artifact lane canonicalized with the other normalizer; only the
        // URL lane's $path is what access context would compute itself.
        $artifactResource ? null : $path
    );
    $country = (string) $accessContext['country'];
    $userAgent = (string) $accessContext['agent'];
    return $memo[$memoKey] = [
        'error' => null,
        'projection' => $projection,
        'path' => $path,
        'target' => $target,
        'country' => $country,
        'userAgent' => $userAgent,
        'anonymous' => _stattic_grant_decision(
            $projection,
            $path,
            $target,
            [],
            [],
            $country,
            $userAgent,
            $artifactResource,
            true
        ),
    ];
}

// Returns false when an unconstrained Public route is URL-stable, including
// requests with a valid but irrelevant browser session; identity-dependent
// admissions stay private-cache pinned. Denials terminate.
function _stattic_enforce_scoped_admission(
    array $serving,
    string $requestHost,
    string $requestPath,
    bool $probeOnly = false,
    bool $artifactResource = false
): ?bool {
    $admission = _stattic_scoped_admission_context(
        $serving,
        $requestHost,
        $requestPath,
        $artifactResource
    );
    if (($admission['error'] ?? null) === 'projection') {
        if ($probeOnly) {
            return null;
        }
        _stattic_render_scoped_deny($serving);
    }
    if (($admission['error'] ?? null) === 'path') {
        if ($probeOnly) {
            return null;
        }
        _stattic_render_json_or_deny('access_path_invalid', 'Access path is invalid.');
    }
    if (!empty($serving['immutable'])) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    $projection = $admission['projection'];
    if (
        ($projection['fence'] ?? 'none') === 'none'
        && _stattic_system_view_admits($admission['path'])
    ) {
        // Stateless proof admits this request and nothing else: nothing minted
        // here can be replayed. A fenced Space stays closed to it. Internal
        // viewing is not an exception to a platform hold.
        _stattic_access_private_cache_flag(true);
        return true;
    }
    $path = $admission['path'];
    $target = $admission['target'];
    $country = $admission['country'];
    $userAgent = $admission['userAgent'];
    $anonymous = $admission['anonymous'];
    $anonymousAdmitted =
        $projection['fence'] !== 'exposure'
        && in_array('page.view', $anonymous['capabilities'], true);
    $bearerPresented = _stattic_platform_bearer_token_from_request() !== null;
    $cookiePresented = _stattic_visitor_cookie_from_request() !== '';
    if ($anonymousAdmitted && !$bearerPresented && !$cookiePresented) {
        return empty($anonymous['sharedCacheable']);
    }

    // Contract A1, charged HERE: the request is now known to need identity work,
    // and this is still before any credential is resolved and before any matching
    // rule (bcrypt included) can render a decision, so a credential-less POST
    // flood at a protected path is bounded. A URL an unconstrained Public Grant
    // already admitted returned above and costs no slot. Probes charge nothing.
    if (!$probeOnly) {
        _stattic_admission_acquire_access_lane(_stattic_access_private_root(), $serving);
    }

    $identity = _stattic_current_session_identity($serving, $requestHost);
    if (
        ($projection['fence'] ?? 'none') === 'none'
        && _stattic_system_view_admits($path)
    ) {
        // A Spacefast API token is exchanged on first use, so its scoped
        // system-view proof only exists after resolving the request identity.
        _stattic_access_private_cache_flag(true);
        return true;
    }
    if ($bearerPresented && !is_array($identity)) {
        if ($probeOnly) {
            return null;
        }
        _stattic_render_scoped_deny($serving);
    }
    // A valid browser session cannot change bytes on a path an unconstrained
    // Public Grant already admits, so keep it shared-cacheable. Bearer stays
    // private: it is explicit machine authority and WP Cloud bypasses it.
    if (
        is_array($identity)
        && !$bearerPresented
        && $anonymousAdmitted
        && !empty($anonymous['sharedCacheable'])
    ) {
        return false;
    }
    // A Public Grant still admits the route when a browser carried a globally
    // invalid cookie, but this one Set-Cookie response stays private/no-store.
    // An invalid Bearer stays a hard denial: machine callers never fall back.
    if (
        $anonymousAdmitted
        && $projection['fence'] !== 'ownership'
        && (!$bearerPresented || is_array($identity))
    ) {
        return true;
    }
    $authorities = is_array($identity) && is_array($identity['authorities'] ?? null)
        ? $identity['authorities']
        : [];
    if ($projection['fence'] === 'ownership') {
        if ($probeOnly) {
            return null;
        }
        _stattic_render_scoped_deny($serving);
    }
    $decision = _stattic_grant_decision(
        $projection,
        $path,
        $target,
        $authorities,
        is_array($identity) && is_array($identity['emailVerifiedAuthorities'] ?? null)
            ? $identity['emailVerifiedAuthorities']
            : [],
        $country,
        $userAgent,
        $artifactResource,
        true
    );
    $admitted = in_array('page.view', $decision['capabilities'], true);
    if ($projection['fence'] === 'exposure') {
        $admitted = $admitted && in_array('access.manage', $decision['capabilities'], true);
    }
    if ($admitted) {
        _stattic_touch_session_authorities($identity, $decision['references']);
        return true;
    }
    if ($probeOnly) {
        return null;
    }
    _stattic_render_access_gate($serving, $requestHost);
}

// THE protected-space enforcement call on the serve path (contracts §7): exits
// on deny, returns on admit.
//
// Two paths, deliberately. $requestPath is what is about to be sent (after any
// rewrite), $originalRequestPath what the visitor asked for, and when they
// differ BOTH must be admitted: enforcing only the effective path lets a rewrite
// bypass the requested URL's Grants, enforcing only the original serves bytes
// nobody was admitted to.
function _stattic_access_enforce_v4(
    string $requestHost,
    string $requestPath,
    string $originalRequestPath
): void {
    $serving = _stattic_page_serving();
    $GLOBALS['SPACEFAST_ACCESS_ENFORCED'] = true;

    _stattic_access_apply_system_view_cookie($serving, $requestHost);

    $protected = _stattic_enforce_scoped_admission($serving, $requestHost, $requestPath, false, false);
    if ($originalRequestPath !== $requestPath) {
        // Denials terminate inside, so reaching here means both were admitted;
        // either one being identity-dependent pins the response private.
        $protected = _stattic_enforce_scoped_admission(
            $serving,
            $requestHost,
            $originalRequestPath,
            false,
            false
        ) || $protected;
    }
    if ($protected && _stattic_private_cross_host_subresource($requestHost)) {
        header('X-Spacefast-Access-Diagnostic: cross-host-private-subresource', true);
        _stattic_render_scoped_deny($serving);
    }
    if ($protected) {
        _stattic_access_private_cache_flag(true);
    }
}

function _stattic_scope_path(string $path, bool $canonicalizeIndexAlias = true): ?string
{
    if (
        $path === ''
        || $path[0] !== '/'
        || str_starts_with($path, '//')
        || preg_match('/[\x00-\x1F\x7F\\\\?#]/', $path) === 1
    ) {
        return null;
    }
    if (preg_match('//u', $path) !== 1) {
        return null;
    }
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                return null;
            }
            array_pop($segments);
            continue;
        }
        if (preg_match('/[^\x00-\x7F]/', $segment) === 1) {
            $normalized = _stattic_nfc_string($segment);
            if (!is_string($normalized)) {
                return null;
            }
            $segment = $normalized;
        }
        $segments[] = $segment;
    }
    if ($canonicalizeIndexAlias && array_last($segments) === 'index.html') {
        array_pop($segments);
    }
    return $segments === [] ? '/' : '/' . implode('/', $segments);
}

function _stattic_artifact_scope_path(string $path): ?string
{
    return _stattic_scope_path($path, false);
}

function _stattic_scope_contains(string $scope, string $path): bool
{
    return $scope === '/'
        || $path === $scope
        || str_starts_with($path, $scope . '/');
}

function _stattic_private_cross_host_subresource(string $requestHost): bool
{
    $destination = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
    $mode = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')));
    if ($destination === 'document' || $destination === 'iframe' || $mode === 'navigate') {
        return false;
    }
    $source = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($source === '') {
        $source = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    }
    if ($source === '') {
        return false;
    }
    $sourceHost = parse_url($source, PHP_URL_HOST);
    if (!is_string($sourceHost) || $sourceHost === '') {
        return true;
    }
    return _stattic_canonicalize_host($sourceHost) !== _stattic_canonicalize_host($requestHost);
}

function _stattic_current_session_identity(array $serving, string $host): ?array
{
    static $memo = [];
    $platformBearer = _stattic_platform_bearer_token_from_request();
    $bearer = $platformBearer === null
        ? null
        : _stattic_platform_identity_token(
            $serving,
            $host,
            _stattic_runtime_request_path()
        );
    $credential = $bearer === null ? _stattic_visitor_cookie_from_request() : $bearer;
    $projection = is_array($serving['authorization'] ?? null)
        ? $serving['authorization']
        : [];
    $canonicalHost = _stattic_canonicalize_host($host);
    $memoKey = implode("\0", [
        $bearer === null ? 'cookie' : 'platform-bearer',
        $credential,
        $canonicalHost,
        _stattic_serving_space_id($serving),
        (string) ($projection['generation'] ?? -1),
        (string) _stattic_session_version($serving),
        (string) ($serving['version_id'] ?? ''),
        (string) ($serving['route_name'] ?? ''),
    ]);
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    return $memo[$memoKey] = _stattic_current_session_identity_uncached(
        $serving,
        $canonicalHost,
        $bearer,
        $credential
    );
}

function _stattic_current_session_identity_uncached(
    array $serving,
    string $canonicalHost,
    ?string $bearer,
    string $credential
): ?array {
    if ($bearer !== null) {
        if ($bearer === '') {
            return null;
        }
        $verified = _stattic_visitor_verify(
            $bearer,
            _stattic_visitor_verify_options($serving, $canonicalHost, null, [
                'requireJti' => false,
            ])
        );
        if (!is_array($verified)) {
            return null;
        }
        $projection = is_array($serving['authorization'] ?? null)
            ? $serving['authorization']
            : [];
        $authorities = array_values(array_filter(
            _stattic_callback_authorities($verified),
            static fn (string $reference): bool =>
                _stattic_authority_ref_exists($projection, $reference)
        ));
        if ($authorities === []) {
            return null;
        }
        return [
            'sub' => $verified['sub'],
            'authorities' => $authorities,
            'emailVerifiedAuthorities' =>
                is_array($verified['claims'] ?? null)
                && (($verified['claims']['emailVerified'] ?? false) === true)
                    ? $authorities
                    : [],
            'exp' => $verified['exp'],
            'claims' => $verified['claims'],
            'token' => $bearer,
        ];
    }
    $cookieName = _stattic_session_cookie_name();
    if (!array_key_exists($cookieName, $_COOKIE)) {
        return null;
    }
    // The authority-less form first: no authority, no storage, and the common
    // case on a Space with a Public Grant.
    $anonymous = _stattic_anonymous_session_decode($serving, $canonicalHost, $credential);
    if ($anonymous !== null) {
        return _stattic_anonymous_session_identity($anonymous);
    }
    if (str_starts_with($credential, STATTIC_ANONYMOUS_SESSION_PREFIX)) {
        // Tampered, signed for another host, or retired by a session-version
        // rotation; it never carried access, so drop it silently instead of
        // reporting a sign-out.
        _stattic_clear_cookie($cookieName);
        return null;
    }
    $verificationFailure = 'invalid';
    $identity = $credential === ''
        ? null
        : _stattic_access_session_verify(
            $credential,
            $serving,
            $canonicalHost,
            $verificationFailure
        );
    if (is_array($identity)) {
        return $identity;
    }

    // Clear only a globally missing, expired, or malformed session: storage that
    // could not answer must not become a global logout.
    if ($verificationFailure === 'invalid') {
        _stattic_invalid_access_cookie_cleared(true);
        // Replace rather than clear, so the NEXT navigation can still say why
        // they are signed out; when it cannot be minted, the cookie goes away.
        if (!_stattic_anonymous_session_set($serving, $canonicalHost, [
            'expiredAt' => _stattic_access_session_now(),
        ])) {
            _stattic_clear_cookie($cookieName);
        }
    }
    return null;
}

function _stattic_access_session_matching_retained_authorities(
    array $record,
    array $projection,
    int $now
): array {
    $authorityEntries = [];
    foreach (
        _stattic_access_session_retained_authority_entries($record['authorities'], $now)
        as $entry
    ) {
        if (_stattic_authority_generation_matches(
            $projection,
            $entry['reference'],
            $entry['generation']
        )) {
            $authorityEntries[] = $entry;
        }
    }
    return $authorityEntries;
}

// Every authority pruned away: what the visitor MAY DO is gone, WHO they are is
// not, so the identity moves onto the stateless session rather than being
// destroyed (which would rename them mid-thread in Comments). `expiredAt` is
// kept so they are told their access expired. The cookie is replaced only when
// the cookie is what presented this credential.
function _stattic_access_session_degrade(
    array $serving,
    string $host,
    array $record,
    string $credential
): ?array {
    $payload = [
        ..._stattic_anonymous_session_payload($record),
        'expiredAt' => _stattic_access_session_now(),
    ];
    if (
        _stattic_visitor_cookie_from_request() === $credential
        && !_stattic_anonymous_session_set($serving, $host, $payload)
    ) {
        return null;
    }
    return _stattic_anonymous_session_identity($payload);
}

// Hot lane: verify the cookie's signature, compare its [access_gen,
// session_ver] tuple with the overlay's, hand the grant evaluator the
// authorities it names. No file is read. Cold lane (claims aged out, a gen
// moved, an authority aged out) is the ONLY place the server record is
// consulted: present means re-mint against the current gens, absent means this
// exact session was revoked and no other session is touched.
function _stattic_access_session_verify(
    string $credential,
    array $serving,
    string $host,
    ?string &$failure = null
): ?array {
    $failure = 'invalid';
    $claims = _stattic_access_session_decode($serving, $host, $credential);
    if ($claims === null) {
        return null;
    }
    if (
        $claims['spaceId'] !== _stattic_serving_space_id($serving)
        || $claims['sessionVersion'] !== _stattic_session_version($serving)
    ) {
        return null;
    }
    $now = _stattic_access_session_now();
    $accessGeneration = _stattic_projection_generation($serving);
    $projection = is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [];
    $retained = _stattic_access_session_retained_authority_entries($claims['authorities'], $now);
    $stale = $claims['exp'] <= $now
        || $claims['accessGeneration'] !== $accessGeneration
        || count($retained) !== count($claims['authorities']);

    if ($stale) {
        $privateRoot = _stattic_access_private_root();
        if ($privateRoot === '' || !is_dir($privateRoot)) {
            $failure = 'unavailable';
            return null;
        }
        $record = _stattic_access_session_record_read($privateRoot, $claims['sid']);
        if ($record === null) {
            // Revoked, aged out, or never landed: exactly this session ends.
            return _stattic_access_session_degrade($serving, $host, $claims, $credential);
        }
        // A gen moved, so the generation hashes the cookie carries are re-checked
        // against the compiled index. An authority whose Grant was edited or
        // withdrawn does not survive the re-mint.
        $matching = _stattic_access_session_matching_retained_authorities(
            $claims,
            $projection,
            $now
        );
        if ($matching === []) {
            _stattic_access_session_record_delete($privateRoot, $claims['sid']);
            return _stattic_access_session_degrade($serving, $host, $claims, $credential);
        }
        $claims['authorities'] = $matching;
        $claims['accessGeneration'] = $accessGeneration;
        $claims['iat'] = $now;
        $claims['exp'] = $now + STATTIC_ACCESS_SESSION_CLAIM_TTL_SECONDS;
        if (!_stattic_access_session_issue($serving, $host, $claims)) {
            $failure = 'unavailable';
            return null;
        }
        _stattic_access_session_touch_record($privateRoot, $claims['sid'], $now, $record);
        $retained = $matching;
    }

    $authorityEntries = _stattic_access_session_live_authority_entries($retained, $now);
    if ($authorityEntries === []) {
        // A jump past the bounded skew fails closed without destroying the
        // future-dated session; it recovers when the clock catches up.
        $failure = 'unavailable';
        return null;
    }
    $authorities = array_column($authorityEntries, 'reference');
    $emailVerifiedAuthorities = array_values(array_map(
        static fn (array $entry): string => $entry['reference'],
        array_filter(
            $authorityEntries,
            static fn (array $entry): bool =>
                is_int($entry['emailVerifiedUntil'] ?? null)
                && $entry['emailVerifiedUntil'] > $now
        )
    ));
    $exp = max(array_map(
        static fn (array $entry): int =>
            $entry['openedAt'] + STATTIC_ACCESS_SESSION_ABSOLUTE_SECONDS,
        $authorityEntries
    ));
    $profile = _stattic_access_public_profile($claims['profile'] ?? null);
    return [
        'authorities' => $authorities,
        'emailVerifiedAuthorities' => $emailVerifiedAuthorities,
        'authorityEntries' => $authorityEntries,
        'sessionId' => $claims['sid'],
        'sessionRecord' => $claims,
        'exp' => $exp,
        ...($profile !== null ? ['profile' => $profile] : []),
        'claims' => [
            'authorities' => $authorities,
            'emailVerifiedAuthorities' => $emailVerifiedAuthorities,
            'exp' => $exp,
            ...($profile !== null ? ['profile' => $profile] : []),
        ],
        'token' => '',
    ];
}

// Liveness is a write, so it is throttled to the touch interval and deferred
// past the response: a read-mostly session costs one record read per claim TTL
// and one record write per six hours.
function _stattic_access_session_touch_record(
    string $privateRoot,
    string $sessionId,
    int $now,
    ?array $record = null
): void {
    $record ??= _stattic_access_session_record_read($privateRoot, $sessionId);
    if (
        $record === null
        || $now - (int) $record['lastSeenAt'] < STATTIC_ACCESS_SESSION_TOUCH_INTERVAL_SECONDS
    ) {
        return;
    }
    _stattic_defer(static function () use ($privateRoot, $sessionId, $record, $now): void {
        _stattic_access_session_record_write($privateRoot, $sessionId, [
            ...$record,
            'lastSeenAt' => $now,
        ]);
    });
}

// Use moves an authority forward so idle expiry measures real use, not the
// moment it was acquired. The claims are the record, so the update is a re-mint
// of this response's cookie.
function _stattic_touch_session_authorities(?array &$identity, array $usedReferences): void
{
    if (
        !is_array($identity)
        || !is_array($identity['sessionRecord'] ?? null)
        || !is_string($identity['sessionId'] ?? null)
        || $usedReferences === []
    ) {
        return;
    }
    $used = array_values(array_unique(array_filter(
        $usedReferences,
        '_stattic_authority_reference_valid'
    )));
    if ($used === []) {
        return;
    }
    $claims = $identity['sessionRecord'];
    $now = _stattic_access_session_now();
    $touched = false;
    foreach ($claims['authorities'] as $index => $entry) {
        if (
            !is_array($entry)
            || !in_array($entry['reference'] ?? null, $used, true)
            || $now < $entry['lastSeenAt'] + STATTIC_ACCESS_SESSION_TOUCH_INTERVAL_SECONDS
        ) {
            continue;
        }
        $claims['authorities'][$index]['lastSeenAt'] = max((int) $entry['openedAt'], $now);
        $touched = true;
    }
    if (!$touched) {
        return;
    }
    $serving = _stattic_page_serving();
    if (!_stattic_access_session_issue($serving, (string) $claims['host'], $claims)) {
        return;
    }
    $identity['sessionRecord'] = $claims;
    _stattic_access_session_touch_record(
        _stattic_access_private_root(),
        (string) $claims['sid'],
        $now
    );
}

function _stattic_authority_ref_exists(array $projection, string $authority): bool
{
    return _stattic_authority_generation($projection, $authority) !== null;
}

function _stattic_authority_generation_candidates(array $projection, string $authority): array
{
    $index = _stattic_authorization_grant_index($projection);
    $entries = is_array($index) && is_array($index['generations'][$authority] ?? null)
        ? $index['generations'][$authority]
        : [];
    $now = time();
    $candidates = [];
    foreach ($entries as $entry) {
        if (
            is_array($entry)
            && _stattic_is_sha256_hex($entry['hash'] ?? null)
            && (!is_int($entry['expiresAt'] ?? null) || $entry['expiresAt'] > $now)
        ) {
            $candidates[] = $entry['hash'];
        }
    }
    return $candidates;
}

// A digest over EVERY live candidate hash: any change to what admits this
// authority (rotation, edit, withdrawal, an added Grant) drops it from the
// session at the next re-mint. Fails closed. Deliberately not memoized: the
// candidate set is expiry-dependent, and a worker-lifetime static would keep an
// expired candidate's generation verifying.
function _stattic_authority_generation(array $projection, string $authority): ?string
{
    $candidates = _stattic_authority_generation_candidates($projection, $authority);
    if ($candidates === []) {
        return null;
    }
    sort($candidates, SORT_STRING);
    return hash('sha256', implode(':', $candidates));
}

function _stattic_authority_generation_matches(
    array $projection,
    string $authority,
    string $expected
): bool {
    $generation = _stattic_authority_generation($projection, $authority);
    return $generation !== null && hash_equals($generation, $expected);
}

function _stattic_visitor_cookie_from_request(): string
{
    $cookie = $_COOKIE[_stattic_session_cookie_name()] ?? '';
    return is_string($cookie) ? $cookie : '';
}

// The lane-less pre-auth boundary stays identical across claim state, route
// existence, Grant reasons and available authorities: none of that private
// metadata may reach crawlers. The compiled frame may carry partner branding;
// the runtime-owned denial fragment and status stay uniform.
function _stattic_render_scoped_deny(array $serving): never
{
    $message = 'This page is private.';
    _stattic_serve_page('denied', [
        'status' => 403,
        'headers' => ['Cache-Control' => 'private, no-store'],
        'private' => true,
        'message' => $message,
        'code' => 'access_denied',
        'customizable' => true,
        'serving' => $serving,
        'fragment' => '<p class="sf-copy">' . _stattic_html_escape($message) . '</p>',
    ]);
}

function _stattic_render_json_unauthenticated(string $code): never
{
    _stattic_problem_response(401, $code, '', [], _stattic_private_content_response_headers([
        'Cache-Control' => 'private, no-store',
    ]));
}

function _stattic_safe_return_path(string $value): ?string
{
    if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//')) {
        return null;
    }
    for ($i = 0, $length = strlen($value); $i < $length; $i += 1) {
        $ord = ord($value[$i]);
        if ($value[$i] === '\\' || $ord < 0x20 || $ord === 0x7f) {
            return null;
        }
    }
    return $value;
}

function _stattic_visitor_issuers(array $serving): array
{
    $issuers = [];
    $jwks = is_array($serving['visitor_jwks'] ?? null) ? $serving['visitor_jwks'] : [];
    foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $key) {
        $publicKey = is_array($key) && is_string($key['x'] ?? null)
            ? _stattic_base64url_decode($key['x'])
            : null;
        if (
            !is_array($key)
            || ($key['kty'] ?? null) !== 'OKP'
            || ($key['crv'] ?? null) !== 'Ed25519'
            || ($key['alg'] ?? null) !== 'EdDSA'
            || !is_string($key['kid'] ?? null)
            || $key['kid'] === ''
            || !is_string($key['x'] ?? null)
            || !is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            continue;
        }
        $issuers[] = [
            'kid' => $key['kid'],
            'alg' => 'EdDSA',
            'publicKey' => $key['x'],
        ];
    }
    return $issuers;
}

function _stattic_projection_generation(array $serving): int
{
    return is_int($serving['projection_generation'] ?? null)
        && $serving['projection_generation'] >= 0
        ? $serving['projection_generation']
        : 0;
}

function _stattic_session_version(array $serving): int
{
    $projection = is_array($serving['authorization'] ?? null)
        ? $serving['authorization']
        : [];
    return is_int($projection['sessionVersion'] ?? null)
        && $projection['sessionVersion'] >= 0
        ? $projection['sessionVersion']
        : -1;
}

function _stattic_serving_space_id(array $serving): string
{
    return is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
}

function _stattic_visitor_verify_options(array $serving, string $host, ?array $issuers = null, array $extra = []): array
{
    $generation = _stattic_projection_generation($serving);
    return array_merge([
        'issuers' => $issuers ?? _stattic_visitor_issuers($serving),
        'issuer' => is_string($serving['visitor_issuer'] ?? null)
            ? $serving['visitor_issuer']
            : '',
        'host' => $host,
        'generation' => $generation,
        'privateRoot' => _stattic_access_private_root(),
        'spaceId' => _stattic_serving_space_id($serving),
    ], $extra);
}

// The descriptor's exchange block, or null. The one extraction every exchange
// caller shares, so a descriptor without the key can never fatal a lane.
function _stattic_access_page_exchange(array $serving): ?array
{
    $descriptor = _stattic_access_page_descriptor($serving);
    $exchange = is_array($descriptor) ? ($descriptor['exchange'] ?? null) : null;
    return is_array($exchange) ? $exchange : null;
}

function _stattic_access_page_descriptor(array $serving): ?array
{
    static $memo = [];
    $projection = is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [];
    $memoKey = ((string) ($projection['generation'] ?? -1)) . ':' . _stattic_serving_space_id($serving);
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    $raw = $projection['accessPage'] ?? null;
    if (!is_array($raw)) {
        return $memo[$memoKey] = null;
    }
    $displayName = is_string($raw['displayName'] ?? null) ? trim($raw['displayName']) : '';
    $accountUrl = is_string($raw['accountUrl'] ?? null)
        && _stattic_platform_destination_allowed($raw['accountUrl'])
        ? $raw['accountUrl']
        : null;
    $connections = [];
    foreach (is_array($raw['connections'] ?? null) ? $raw['connections'] : [] as $connection) {
        if (
            !is_array($connection)
            || !is_string($connection['id'] ?? null)
            || $connection['id'] === ''
            || !is_string($connection['label'] ?? null)
            || trim($connection['label']) === ''
            || !is_string($connection['startUrl'] ?? null)
            || !_stattic_platform_destination_allowed($connection['startUrl'])
        ) {
            continue;
        }
        $connections[] = [
            'id' => $connection['id'],
            'label' => substr(trim($connection['label']), 0, 200),
            'startUrl' => $connection['startUrl'],
        ];
    }
    $exchange = null;
    $rawExchange = $raw['exchange'] ?? null;
    if (is_array($rawExchange)) {
        $urls = [];
        $usable = is_string($rawExchange['credential'] ?? null)
            && strlen($rawExchange['credential']) >= 32;
        // Required, and present-but-invalid closes the whole exchange: without a
        // usable destination the lane it belongs to cannot be completed at all.
        // tokenUrl is absent on historical projections written before
        // browser-token exchange.
        foreach (['passwordUrl', 'tokenUrl', 'requestUrl', 'linkUrl', 'emailUrl'] as $field) {
            $value = $rawExchange[$field] ?? null;
            if (
                ($field === 'tokenUrl' || $field === 'linkUrl' || $field === 'emailUrl')
                && !isset($rawExchange[$field])
            ) {
                $urls[$field] = null;
                continue;
            }
            if (!is_string($value) || !_stattic_platform_destination_allowed($value)) {
                $usable = false;
                break;
            }
            $urls[$field] = $value;
        }
        // Optional: an unusable destination only closes its own lane.
        foreach ([
            'logoutUrl',
            'commentsTicketUrl',
            'commentsVersionUrlsUrl',
            'zeroRealtimeTicketUrl',
        ] as $field) {
            $value = $rawExchange[$field] ?? null;
            $urls[$field] = is_string($value) && _stattic_platform_destination_allowed($value)
                ? $value
                : null;
        }
        if ($usable) {
            $exchange = [...$urls, 'credential' => $rawExchange['credential']];
        }
    }
    return $memo[$memoKey] = [
        'displayName' => $displayName !== '' ? substr($displayName, 0, 200) : null,
        'accountUrl' => $accountUrl,
        'connections' => $connections,
        'exchange' => $exchange,
    ];
}

function _stattic_access_lane_active(array $projection, string $kind, array $target, int $now): bool
{
    $entries = $projection['grantIndex']['lanes'][$kind] ?? null;
    if (!is_array($entries)) {
        return false;
    }
    return array_any($entries, static fn (mixed $entry): bool =>
        is_array($entry)
        && (!is_int($entry['notBefore'] ?? null) || $entry['notBefore'] <= $now)
        && (!is_int($entry['expiresAt'] ?? null) || $entry['expiresAt'] > $now)
        && is_array($entry['target'] ?? null)
        && _stattic_grant_target_matches($entry['target'], $target));
}

// Null means "render the uniform deny page": no descriptor, no usable lanes,
// or a serving fence in force.
function _stattic_access_page_lanes(array $serving): ?array
{
    $projection = is_array($serving['authorization'] ?? null) ? $serving['authorization'] : null;
    if ($projection === null || !_stattic_authorization_projection_compiled($projection)) {
        return null;
    }
    if (($projection['fence'] ?? 'none') !== 'none') {
        return null;
    }
    $descriptor = _stattic_access_page_descriptor($serving);
    if ($descriptor === null) {
        return null;
    }
    $target = _stattic_grant_target($serving);
    $now = time();
    $passwordGrant = _stattic_access_lane_active($projection, 'password', $target, $now);
    $identityBound = _stattic_access_lane_active($projection, 'identity', $target, $now);
    $claimed = ($projection['spaceClaimed'] ?? false) === true;
    $exchange = $descriptor['exchange'];
    $lanes = [
        'descriptor' => $descriptor,
        'password' => $passwordGrant && $exchange !== null,
        'account' => $claimed ? $descriptor['accountUrl'] : null,
        'connections' => $claimed ? $descriptor['connections'] : [],
        'request' => $claimed && $exchange !== null,
        'silent' => $identityBound,
        'exchange' => $exchange,
    ];
    if (
        !$lanes['password']
        && $lanes['account'] === null
        && $lanes['connections'] === []
        && !$lanes['request']
    ) {
        return null;
    }
    return $lanes;
}

const STATTIC_ACCESS_STATUS_CODES = [
    'checked', 'no-grant', 'invalid-password', 'email-sent', 'email-failed',
    'request-pending', 'request-failed', 'link-expired', 'link-revoked',
    'link-invalid', 'open-used', 'session-expired', 'rate-limited',
    'exchange-unavailable',
];

function _stattic_access_status_code(): string
{
    $value = $_GET['sf_access'] ?? '';
    return is_string($value) && in_array($value, STATTIC_ACCESS_STATUS_CODES, true)
        ? $value
        : '';
}

function _stattic_access_url_with_params(string $url, array $params): string
{
    $query = http_build_query($params);
    if ($query === '') {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . $query;
}

// Flow-state params are stripped so redirects and forms never loop.
function _stattic_access_clean_return_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $query = parse_url($uri, PHP_URL_QUERY);
    $path = is_string($path) && $path !== '' ? $path : '/';
    parse_str(is_string($query) ? $query : '', $params);
    $explicitReturn = is_string($params['sf_access_return'] ?? null)
        ? _stattic_safe_return_path((string) $params['sf_access_return'])
        : null;
    // A share-link token that did not open the page is spent: never carry it
    // into the gate's forms or the redirect they bounce through.
    unset($params['sf_access'], $params['sf_access_return'], $params[STATTIC_ACCESS_QUERY_TOKEN_PARAM]);
    if ($explicitReturn !== null) {
        return $explicitReturn;
    }
    $rebuilt = http_build_query($params);
    return _stattic_safe_return_path($path . ($rebuilt !== '' ? '?' . $rebuilt : '')) ?? '/';
}

function _stattic_request_is_document_navigation(): bool
{
    if (_stattic_runtime_request_method() !== 'GET' || _stattic_request_is_fetch()) {
        return false;
    }
    $destination = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
    // A cross-origin account bounce is safe and useful only when the browser
    // positively identifies the request as a top-level document. Treat missing
    // metadata as unknown: a proxy or embedded browser that drops Sec-Fetch-Dest
    // must get the usable access page, not an attempted escape from its frame.
    return $destination === 'document';
}

function _stattic_access_status_fragment(string $status): string
{
    $messages = [
        'no-grant' => "You're signed in, but this space hasn't let you in yet. Ask for an invite below.",
        'email-sent' => 'Check your email. Use the confirmation link we sent to finish opening this page.',
        'email-failed' => "Couldn't send that confirmation. Enter the password and try again.",
        'request-pending' => "Request sent. You'll get an email when someone approves it.",
        'request-failed' => "That request didn't go through. Try again in a moment.",
        'link-expired' => 'That link has expired. Ask whoever sent it for a fresh one — or use another way in.',
        'link-revoked' => 'That link was turned off. Use another way in, or ask for a new one.',
        'link-invalid' => "That link doesn't work anymore.",
        'open-used' => 'That open link was already used. Use another way in.',
        'session-expired' => 'Your access expired. Sign in again to continue.',
        'rate-limited' => 'Too many tries. Give it a minute.',
        'exchange-unavailable' => "Couldn't reach Spacefast to check that. Try again in a moment.",
    ];
    $message = $messages[$status] ?? '';
    if ($message === '') {
        return '';
    }
    return '<p class="sf-copy sf-access-status" role="status">' . _stattic_html_escape($message) . '</p>';
}

function _stattic_access_request_form(string $returnPath, string $summary, bool $open = false): string
{
    return '<details class="sf-access-request"' . ($open ? ' open' : '') . '>'
        . '<summary>' . _stattic_html_escape($summary) . '</summary>'
        . '<form method="post" action="' . _stattic_html_escape(STATTIC_ACCESS_REQUEST_PATH) . '">'
        . '<input type="hidden" name="return" value="' . _stattic_html_escape($returnPath) . '">'
        . '<input class="sf-input" type="email" name="email" required autocomplete="email" placeholder="you@example.com">'
        . '<textarea class="sf-input" name="message" maxlength="280" placeholder="Anything the owner should know (optional)"></textarea>'
        . '<button class="sf-button" type="submit">Request an invite</button>'
        . '</form></details>';
}

function _stattic_access_lanes_fragment(
    array $lanes,
    string $host,
    string $returnPath,
    string $status,
    ?string $emailContinuation,
    ?string $requestedScope
): string
{
    $html = '<div class="sf-access-lanes">';
    $buttons = '';
    $hasPopup = false;
    if (is_string($lanes['account'])) {
        $hasPopup = true;
        $accountHref = _stattic_access_url_with_params($lanes['account'], [
            'host' => $host,
            'return' => $returnPath,
        ]);
        $buttons .= '<a class="sf-button sf-access-account" data-sf-access-popup href="'
            . _stattic_html_escape($accountHref) . '">Continue with Spacefast</a>';
    }
    foreach ($lanes['connections'] as $connection) {
        $ssoHref = _stattic_access_url_with_params($connection['startUrl'], [
            'host' => $host,
            'return' => $returnPath,
        ]);
        $buttons .= '<a class="sf-button sf-access-sso" href="' . _stattic_html_escape($ssoHref)
            . '">Continue with ' . _stattic_html_escape($connection['label']) . '</a>';
    }
    $html .= $buttons;
    if (
        $emailContinuation !== null
        && $lanes['exchange'] !== null
        && is_string($lanes['exchange']['emailUrl'])
    ) {
        // Keep every injected form same-origin. The runtime relays this sealed
        // continuation to the central mail authority server-to-server.
        $html .= '<form class="sf-access-verify-email" method="post" action="'
            . _stattic_html_escape(STATTIC_ACCESS_EMAIL_PATH) . '">'
            . '<p class="sf-copy">This password wants a verified email. Enter yours to get a confirmation link.</p>'
            . '<input type="hidden" name="return" value="' . _stattic_html_escape($returnPath) . '">'
            . '<input type="hidden" name="continuation" value="' . _stattic_html_escape($emailContinuation) . '">'
            . '<input class="sf-input" type="email" name="email" required maxlength="320" autocomplete="email" placeholder="you@example.com">'
            . '<button class="sf-button" type="submit">Send confirmation link</button>'
            . '</form>';
    } elseif ($lanes['password']) {
        if ($buttons !== '') {
            $html .= '<div class="sf-access-or" aria-hidden="true">or</div>';
        }
        $html .= '<form class="sf-access-password" method="post" action="'
            . _stattic_html_escape(STATTIC_ACCESS_PASSWORD_PATH) . '">'
            . '<input type="hidden" name="return" value="' . _stattic_html_escape($returnPath) . '">'
            . '<label class="sf-label" for="sf-access-password-input">Password</label>'
            . '<div class="sf-access-password-row">'
            . '<input id="sf-access-password-input" class="sf-input" type="password" name="password"'
            . ' autocomplete="current-password" required maxlength="1024"'
            . ($status === 'invalid-password' ? ' aria-invalid="true" autofocus' : '')
            . '>'
            . '<button class="sf-button" type="submit">Open</button>'
            . '</div>'
            . ($status === 'invalid-password'
                ? '<p class="sf-error" role="alert">That password didn&#039;t work.</p>'
                : '')
            . '</form>';
    }
    if ($lanes['request']) {
        $returnPathOnly = parse_url($returnPath, PHP_URL_PATH);
        $currentScope = _stattic_scope_path(
            is_string($returnPathOnly) && $returnPathOnly !== '' ? $returnPathOnly : '/'
        );
        $markedScope = $requestedScope;
        $requested = $status === 'request-pending'
            || (
                $status !== 'request-failed'
                && $markedScope !== null
                && $currentScope !== null
                && _stattic_scope_contains($markedScope, $currentScope)
            );
        if ($requested) {
            if ($status !== 'request-pending') {
                $html .= '<p class="sf-copy sf-access-requested">Invite requested for '
                    . _stattic_html_escape($markedScope ?? ($currentScope ?? '/'))
                    . '. You\'ll get an email when someone approves it.</p>';
            }
            // The marker is only a browser hint, so keep a way back into the
            // flow after a denial or expiry.
            if ($status !== 'request-pending') {
                $html .= _stattic_access_request_form($returnPath, 'Request again');
            }
        } else {
            $html .= _stattic_access_request_form(
                $returnPath,
                'Need access? Request an invite',
                $status === 'no-grant'
            );
        }
    }
    $html .= '</div>';
    if ($hasPopup) {
        // External and optional by contract: script-src 'self' enables the
        // popup; stricter policies keep the complete same-tab link.
        $html .= '<script src="' . _stattic_html_escape(STATTIC_ACCESS_CLIENT_SCRIPT_PATH)
            . '" defer></script>';
    }
    return $html;
}

// An unclaimed Space has no lane by construction, but its author does hold the
// open link from the publish receipt: name it instead of dead-ending on the
// uniform deny, which stays for fence and unusable-projection cases.
function _stattic_render_unclaimed_notice(array $serving): never
{
    $projection = is_array($serving['authorization'] ?? null) ? $serving['authorization'] : null;
    if (
        $projection === null
        || !_stattic_authorization_projection_compiled($projection)
        || ($projection['fence'] ?? 'none') !== 'none'
        || ($projection['spaceClaimed'] ?? true) !== false
    ) {
        _stattic_render_scoped_deny($serving);
    }
    $descriptor = _stattic_access_page_descriptor($serving);
    $displayName = is_array($descriptor) && is_string($descriptor['displayName'] ?? null)
        ? $descriptor['displayName']
        : null;
    $notice = '<p class="sf-copy">Ask your agent or check your logs for the link you need to '
        . 'unlock this Space.</p>';
    _stattic_serve_access_denied_page(
        $serving,
        $displayName !== null ? $displayName . ' is private' : '',
        '',
        $notice,
        _stattic_access_clean_return_path()
    );
}

// The one 403 access page: the gate and the unclaimed notice differ only in
// title and fragments.
function _stattic_serve_access_denied_page(
    array $serving,
    string $title,
    string $statusFragment,
    string $lanesFragment,
    string $returnPath
): never {
    _stattic_serve_page('access', [
        'status' => 403,
        'headers' => ['Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE],
        'private' => true,
        'message' => 'This space is private.',
        'code' => 'access_denied',
        'customizable' => true,
        'serving' => $serving,
        'fragment' => $statusFragment . $lanesFragment,
        'status_fragment' => $statusFragment,
        'lanes_fragment' => $lanesFragment,
        'title' => $title,
        'request_path' => parse_url($returnPath, PHP_URL_PATH) ?: '/',
    ]);
    exit;
}

// The deny surface for spaces WITH visitor lanes; two invisible redirects
// (silent SSO probe, sole-lane SSO) skip it entirely when they can.
function _stattic_render_access_gate(array $serving, string $requestHost, array $options = []): never
{
    $lanes = _stattic_access_page_lanes($serving);
    if ($lanes === null) {
        _stattic_render_unclaimed_notice($serving);
    }
    $host = _stattic_canonicalize_host($requestHost);
    $status = is_string($options['status'] ?? null) ? $options['status'] : _stattic_access_status_code();
    $returnPath = is_string($options['return'] ?? null)
        ? (_stattic_safe_return_path($options['return']) ?? '/')
        : _stattic_access_clean_return_path();
    $emailContinuation = is_string($options['emailContinuation'] ?? null)
        ? $options['emailContinuation']
        : null;
    $identity = _stattic_visitor_cookie_from_request() !== ''
        ? _stattic_current_session_identity($serving, $requestHost)
        : null;
    // The expiry diagnosis waits until after the silent probe below: someone
    // whose central account can replace the dead session must not be stopped by
    // an expired-session wall first.
    $expired = _stattic_invalid_access_cookie_cleared()
        || _stattic_access_identity_expiry_live($identity);
    // Who they are decides the probe, not what they hold: a share link proves a
    // capability and says nothing about identity, so its holder is probed once.
    $principal = _stattic_access_identity_principal($identity);
    $identityCheckedAt = _stattic_access_identity_checked_at($identity);
    if (
        $status === ''
        && $emailContinuation === null
        && ($options['allowRedirect'] ?? true) === true
        && _stattic_request_is_document_navigation()
        && !_stattic_access_principal_is_account($principal)
        && (
            $identityCheckedAt === null
            || _stattic_access_session_now() - $identityCheckedAt
                >= STATTIC_ACCESS_IDENTITY_CHECK_SECONDS
        )
        && _stattic_platform_bearer_token_from_request() === null
    ) {
        $redirect = null;
        if ($lanes['silent'] && is_string($lanes['account'])) {
            $redirectParams = [
                'silent' => '1',
                'host' => $host,
                'return' => $returnPath,
            ];
            // A silent recovery with no upstream session must still preserve
            // the expiry diagnosis instead of the generic signed-out page.
            if ($expired) {
                $redirectParams['fallback'] = 'session-expired';
            }
            $redirect = _stattic_access_url_with_params($lanes['account'], $redirectParams);
        } elseif (
            count($lanes['connections']) === 1
            && !$lanes['password']
            && $lanes['account'] === null
            && !$lanes['request']
        ) {
            $redirect = _stattic_access_url_with_params($lanes['connections'][0]['startUrl'], [
                'host' => $host,
                'return' => $returnPath,
            ]);
        }
        if ($redirect !== null) {
            // Win or lose, the probe ran: recording it before leaving is what
            // stops a bounce that finds nothing from looping. That hands this
            // browser a stateless session, so the bounce cannot be cacheable.
            _stattic_access_record_identity_check($serving, $requestHost, $identity);
            _stattic_access_redirect($redirect, 302, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE);
        }
    }
    if ($status === '' && $expired) {
        $status = 'session-expired';
    }
    $descriptor = $lanes['descriptor'];
    $statusFragment = _stattic_access_status_fragment($status);
    $lanesFragment = _stattic_access_lanes_fragment(
        $lanes,
        $host,
        $returnPath,
        $status,
        $emailContinuation,
        _stattic_access_identity_requested_path($identity)
    );
    $title = is_string($descriptor['displayName'])
        ? $descriptor['displayName'] . ' is private'
        : '';
    _stattic_serve_access_denied_page($serving, $title, $statusFragment, $lanesFragment, $returnPath);
}

function _stattic_access_test_connect_origin(): string
{
    if (getenv('SPACEFAST_RUNTIME_TEST_MODE') !== '1') {
        return '';
    }
    return trim((string) getenv('SPACEFAST_ACCESS_EXCHANGE_TEST_CONNECT_ORIGIN'));
}

function _stattic_access_exchange_post(string $url, array $fields, array $headers): ?array
{
    // Lazy: only the exchange lanes leave this host, and every other access
    // decision is answered from the projection on disk.
    require_once __DIR__ . '/../shared/http.php';

    if (!_stattic_platform_destination_allowed($url)) {
        return null;
    }
    $requestUrl = $url;
    // Test-only: preserve the logical access-origin Host while dialing the
    // control-plane over its private compose address. Production has no
    // override and connects to the projected HTTPS URL directly.
    $testConnectOrigin = _stattic_access_test_connect_origin();
    if ($testConnectOrigin !== '') {
        $logical = parse_url($url);
        $connect = parse_url($testConnectOrigin);
        if (
            !is_array($logical)
            || !is_array($connect)
            || !in_array(strtolower((string) ($connect['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($connect['host'] ?? null)
            || trim((string) $connect['host']) === ''
            || isset($connect['user'])
            || isset($connect['pass'])
            || isset($connect['query'])
            || isset($connect['fragment'])
            || !in_array((string) ($connect['path'] ?? ''), ['', '/'], true)
        ) {
            return null;
        }
        $connectAuthority = (string) $connect['host'];
        if (isset($connect['port'])) {
            $connectAuthority .= ':' . (int) $connect['port'];
        }
        $requestUrl = strtolower((string) $connect['scheme']) . '://' . $connectAuthority
            . (string) ($logical['path'] ?? '/');
        if (isset($logical['query'])) {
            $requestUrl .= '?' . (string) $logical['query'];
        }
        $logicalAuthority = (string) ($logical['host'] ?? '');
        if (isset($logical['port'])) {
            $logicalAuthority .= ':' . (int) $logical['port'];
        }
        if ($logicalAuthority === '') {
            return null;
        }
        $headers[] = 'Host: ' . $logicalAuthority;
    }
    $responseCookies = [];
    $result = _stattic_http_request([
        'url' => $requestUrl,
        'method' => 'POST',
        'body' => http_build_query($fields),
        'headers' => $headers,
        'connect_timeout' => 3,
        'timeout' => 5,
        'schemes' => ['https', 'http'],
        'max_body_bytes' => 65536,
        'on_headers' => static function (int $status, array $headerPairs) use (&$responseCookies): void {
            foreach ($headerPairs as [$name, $value]) {
                if (
                    strtolower($name) === 'set-cookie'
                    && $value !== ''
                    && strlen($value) <= 4096
                    && count($responseCookies) < 4
                    && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1
                ) {
                    $responseCookies[] = $value;
                }
            }
        },
    ]);
    if ($result['error'] !== null) {
        return null;
    }
    $decoded = json_decode($result['body'], true);
    return [
        'status' => $result['status'],
        'body' => is_array($decoded) ? $decoded : null,
        'cookies' => $responseCookies,
    ];
}

// Forwarded visitor context becomes literal header lines for libcurl, so a
// value carrying CR/LF must never reach the header list verbatim.
function _stattic_access_header_value(mixed $value, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }
    $sanitized = preg_replace('/[\x00-\x1f\x7f]+/', '', $value);
    return is_string($sanitized) ? substr(trim($sanitized), 0, $maxLength) : '';
}

function _stattic_access_exchange_headers(array $exchange, array $context, string $accept): array
{
    $headers = [
        'Accept: ' . $accept,
        'Spacefast-Runtime-Exchange: ' . $exchange['credential'],
    ];
    $ip = _stattic_access_header_value($context['ip'] ?? null, 64);
    if ($ip !== '') {
        $headers[] = 'Spacefast-Visitor-Ip: ' . $ip;
    }
    $country = _stattic_access_header_value($context['country'] ?? null, 8);
    if ($country !== '') {
        $headers[] = 'Spacefast-Visitor-Country: ' . $country;
    }
    $agent = _stattic_access_header_value($context['agent'] ?? null, 512);
    if ($agent !== '') {
        $headers[] = 'Spacefast-Visitor-User-Agent: ' . $agent;
    }
    return $headers;
}

// Every browser that can reach these forms sends Origin on a form POST, so a
// submission proving nothing is refused rather than trusted: no Origin, no
// same-origin fetch metadata.
function _stattic_access_same_origin_post(string $requestHost): bool
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site === 'cross-site' || $site === 'same-site') {
        return false;
    }
    // Chromium sends the opaque Origin "null" for same-origin form posts from a
    // no-referrer document. Safe only alongside same-origin fetch metadata:
    // same-site is not enough, another origin on the site can produce it.
    if ($origin === 'null') {
        return $site === 'same-origin';
    }
    if ($origin !== '') {
        $parsed = parse_url($origin);
        return is_array($parsed)
            && _stattic_canonicalize_host((string) ($parsed['host'] ?? ''))
                === _stattic_canonicalize_host($requestHost);
    }
    return $site === 'same-origin';
}

// Kept in a same-origin external resource so a normal `script-src 'self'`
// policy works without unsafe-inline, a hash, or a nonce placeholder. If a
// publisher blocks scripts, the top-level link still completes the same flow.
function _stattic_access_handle_client_script(): void
{
    $method = _stattic_runtime_request_method();
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD');
    }
    header('Content-Type: application/javascript; charset=utf-8', true);
    header('Cache-Control: no-store', true);
    header('Cross-Origin-Resource-Policy: same-origin', true);
    header('X-Content-Type-Options: nosniff', true);
    if ($method === 'HEAD') {
        exit;
    }
    echo '(function(){var popup=null;'
        . 'var links=document.querySelectorAll("[data-sf-access-popup]");'
        . 'function openPopup(event){var href=event.currentTarget.getAttribute("href");'
        . 'popup=window.open(href+(href.indexOf("?")<0?"?":"&")+"display=popup","sf-access","popup=yes,width=480,height=640");'
        . 'if(popup){event.preventDefault();}}'
        . 'for(var i=0;i<links.length;i++){links[i].addEventListener("click",openPopup);}'
        . 'window.addEventListener("message",function(event){'
        . 'if(event.source===popup&&event.origin===window.location.origin&&event.data==="sf-access-complete"){window.location.reload();}});'
        . 'window.addEventListener("focus",function(){if(!popup){return;}'
        . 'fetch(window.location.href,{method:"HEAD"}).then(function(response){if(response.ok){window.location.reload();}}).catch(function(){});});'
        . '})();';
    exit;
}

function _stattic_access_post_lane_begin(
    array $serving,
    string $requestHost,
    string $privateRoot,
    string $laneFlag,
    bool $requireEmailUrl = false
): array {
    _stattic_visitor_lane_begin($privateRoot);
    if (_stattic_runtime_request_method() !== 'POST') {
        _stattic_method_not_allowed('POST');
    }
    if (!_stattic_access_same_origin_post($requestHost)) {
        _stattic_render_json_or_deny('access_origin_invalid', 'Cross-origin access submissions are not accepted.');
    }
    $lanes = _stattic_access_page_lanes($serving);
    if (
        $lanes === null
        || !$lanes[$laneFlag]
        || $lanes['exchange'] === null
        || ($requireEmailUrl && !is_string($lanes['exchange']['emailUrl']))
    ) {
        _stattic_render_scoped_deny($serving);
    }
    $returnPath = _stattic_safe_return_path(
        is_string($_POST['return'] ?? null) ? (string) $_POST['return'] : '/'
    ) ?? '/';
    return [$lanes, $returnPath];
}

function _stattic_access_handle_password(array $serving, string $requestHost, string $privateRoot): void
{
    [$lanes, $returnPath] = _stattic_access_post_lane_begin(
        $serving,
        $requestHost,
        $privateRoot,
        'password'
    );
    $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
    if ($password === '' || strlen($password) > 1024) {
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'invalid-password');
    }
    $host = _stattic_canonicalize_host($requestHost);
    $context = _stattic_access_context($serving, $requestHost, '/');
    $landingPath = parse_url($returnPath, PHP_URL_PATH);
    $result = _stattic_access_exchange_post(
        $lanes['exchange']['passwordUrl'],
        [
            'host' => $host,
            'landingPath' => is_string($landingPath) && $landingPath !== '' ? $landingPath : '/',
            'password' => $password,
            'requestedRole' => 'viewer',
        ],
        _stattic_access_exchange_headers(
            $lanes['exchange'],
            $context,
            'application/vnd.spacefast.access-handoff+json'
        )
    );
    if ($result === null) {
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'exchange-unavailable');
    }
    $body = $result['body'];
    $fields = is_array($body) && is_array($body['fields'] ?? null) ? $body['fields'] : null;
    $token = is_array($fields) && is_string($fields['token'] ?? null) ? $fields['token'] : '';
    if ($result['status'] === 200 && $token !== '') {
        $sessionToken = _stattic_access_consume_handoff_token($serving, $host, $token);
        if ($sessionToken !== null) {
            _stattic_access_set_session_cookie($sessionToken);
            _stattic_access_redirect($returnPath, 303);
        }
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'exchange-unavailable');
    }
    _stattic_access_gate_email_verification(
        $serving,
        $requestHost,
        $body,
        $lanes['exchange']['emailUrl'] ?? null,
        $returnPath
    );
    $errorCode = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : '';
    $status = match (true) {
        $errorCode === 'rate_limited', $result['status'] === 429 => 'rate-limited',
        $errorCode === 'invalid_password' => 'invalid-password',
        default => $result['status'] >= 500 || $body === null ? 'exchange-unavailable' : 'invalid-password',
    };
    _stattic_access_gate_after_post($serving, $requestHost, $returnPath, $status);
}

// Every access bounce leaves the same way: uncacheable, and carrying no Referer
// into whatever it points at.
function _stattic_access_redirect(
    string $location,
    int $status,
    string $cacheControl = STATTIC_CACHE_CONTROL_NO_STORE,
    bool $noindex = false
): never {
    header('Cache-Control: ' . $cacheControl, true);
    header('Referrer-Policy: no-referrer', true);
    if ($noindex) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    header('Location: ' . $location, true, $status);
    exit;
}

// A lane that admits only verified addresses cannot open anything until the
// visitor proves one. The continuation is too large for a redirect param, so
// the email gate renders inline on this response; anything else returns and
// leaves the caller's own answer standing.
function _stattic_access_gate_email_verification(
    array $serving,
    string $requestHost,
    mixed $body,
    mixed $emailUrl,
    string $returnPath
): void {
    if (!is_string($emailUrl)) {
        return;
    }
    if (!is_array($body) || ($body['code'] ?? null) !== 'email_verification_required') {
        return;
    }
    $details = is_array($body['details'] ?? null) ? $body['details'] : [];
    if (!is_string($details['continuation'] ?? null)) {
        return;
    }
    _stattic_render_access_gate($serving, $requestHost, [
        'status' => '',
        'return' => $returnPath,
        'emailContinuation' => $details['continuation'],
        'allowRedirect' => false,
    ]);
}

// Post/redirect/get with an allowlisted state code, never a secret, so a
// refresh re-renders cleanly.
function _stattic_access_gate_after_post(
    array $serving,
    string $requestHost,
    string $returnPath,
    string $status
): never {
    _stattic_access_redirect(
        _stattic_access_url_with_params($returnPath, ['sf_access' => $status]),
        303
    );
}

function _stattic_access_handle_email_verification(
    array $serving,
    string $requestHost,
    string $privateRoot
): void {
    [$lanes, $returnPath] = _stattic_access_post_lane_begin(
        $serving,
        $requestHost,
        $privateRoot,
        'password',
        true
    );
    $continuation = is_string($_POST['continuation'] ?? null)
        ? trim((string) $_POST['continuation'])
        : '';
    $email = is_string($_POST['email'] ?? null) ? trim((string) $_POST['email']) : '';
    if (
        $continuation === ''
        || strlen($continuation) > 16384
        || $email === ''
        || strlen($email) > 320
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'email-failed');
    }
    $context = _stattic_access_context($serving, $requestHost, '/');
    $result = _stattic_access_exchange_post(
        $lanes['exchange']['emailUrl'],
        ['continuation' => $continuation, 'email' => $email],
        _stattic_access_exchange_headers($lanes['exchange'], $context, 'application/json')
    );
    if ($result === null) {
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'exchange-unavailable');
    }
    $body = $result['body'];
    $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : null;
    if (
        $result['status'] === 202
        && is_array($data)
        && ($data['status'] ?? null) === 'pending'
    ) {
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'email-sent');
    }
    $status = $result['status'] === 429
        ? 'rate-limited'
        : ($result['status'] >= 500 || $body === null ? 'exchange-unavailable' : 'email-failed');
    _stattic_access_gate_after_post($serving, $requestHost, $returnPath, $status);
}

function _stattic_access_handle_request_invite(array $serving, string $requestHost, string $privateRoot): void
{
    [$lanes, $returnPath] = _stattic_access_post_lane_begin(
        $serving,
        $requestHost,
        $privateRoot,
        'request'
    );
    $email = is_string($_POST['email'] ?? null) ? trim((string) $_POST['email']) : '';
    $message = is_string($_POST['message'] ?? null)
        ? (function_exists('mb_substr')
            ? mb_substr(trim((string) $_POST['message']), 0, 280, 'UTF-8')
            : substr(trim((string) $_POST['message']), 0, 280))
        : '';
    if ($email === '' || strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'request-failed');
    }
    $host = _stattic_canonicalize_host($requestHost);
    $context = _stattic_access_context($serving, $requestHost, '/');
    $landingPath = parse_url($returnPath, PHP_URL_PATH);
    $result = _stattic_access_exchange_post(
        $lanes['exchange']['requestUrl'],
        array_merge(
            [
                'host' => $host,
                'landingPath' => is_string($landingPath) && $landingPath !== '' ? $landingPath : '/',
                'email' => $email,
                'requestedRole' => 'viewer',
            ],
            $message !== '' ? ['message' => $message] : []
        ),
        _stattic_access_exchange_headers($lanes['exchange'], $context, 'application/json')
    );
    if ($result === null || $result['status'] >= 400) {
        $status = $result !== null && $result['status'] === 429 ? 'rate-limited' : 'request-failed';
        _stattic_access_gate_after_post($serving, $requestHost, $returnPath, $status);
    }
    $requestedScope = _stattic_scope_path(
        is_string($landingPath) && $landingPath !== '' ? $landingPath : '/'
    ) ?? '/';
    _stattic_access_record_requested_path(
        $serving,
        $requestHost,
        _stattic_current_session_identity($serving, $requestHost),
        $requestedScope
    );
    _stattic_access_gate_after_post($serving, $requestHost, $returnPath, 'request-pending');
}

function _stattic_callback_authorities(array $verified): array
{
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    $authorities = [];
    foreach (is_array($claims['authorities'] ?? null) ? $claims['authorities'] : [] as $authority) {
        if (_stattic_authority_reference_valid($authority)) {
            $authorities[$authority] = true;
        }
    }
    return array_keys($authorities);
}

function _stattic_merge_authority_lru(
    array $current,
    array $incoming,
    array $projection,
    bool $emailVerified,
    ?int $now = null
): array
{
    $openedAt = $now ?? _stattic_access_session_now();
    $ordered = [];
    foreach ($current as $entry) {
        if (!_stattic_access_session_authority_entry_valid($entry)) {
            continue;
        }
        $existing = array_search(
            $entry['reference'],
            array_column($ordered, 'reference'),
            true
        );
        if ($existing !== false) {
            array_splice($ordered, (int) $existing, 1);
        }
        $ordered[] = $entry;
    }
    foreach ($incoming as $authority) {
        if (!_stattic_authority_reference_valid($authority)) {
            continue;
        }
        $generation = _stattic_authority_generation($projection, $authority);
        if (!is_string($generation)) {
            continue;
        }
        $existing = array_search($authority, array_column($ordered, 'reference'), true);
        if ($existing !== false) {
            array_splice($ordered, (int) $existing, 1);
        }
        // A callback is fresh proof for the incoming reference only; retained
        // entries keep their own absolute/idle clocks.
        $ordered[] = [
            'reference' => $authority,
            'generation' => $generation,
            'emailVerifiedUntil' => $emailVerified ? $openedAt + 3600 : null,
            'openedAt' => $openedAt,
            'lastSeenAt' => $openedAt,
        ];
    }
    while (count($ordered) > 16) {
        // Acquisition and use move a proof to the end, so array order is the
        // recency tie-break when second-resolution clocks match.
        $lruIndex = 0;
        for ($index = 1, $count = count($ordered); $index < $count; $index++) {
            $candidate = $ordered[$index];
            $least = $ordered[$lruIndex];
            if ($candidate['lastSeenAt'] < $least['lastSeenAt']) {
                $lruIndex = $index;
            }
        }
        array_splice($ordered, $lruIndex, 1);
    }
    return array_values($ordered);
}

// Verify (host-bound, ≤60s old), merge the browser's existing authorities, then
// verify AGAIN to consume the single-use jti before writing the record.
function _stattic_access_consume_handoff_token(
    array $serving,
    string $host,
    string $token,
    ?array &$storedRecord = null
): ?string {
    $verifyOptions = _stattic_visitor_verify_options($serving, $host, null, [
        'requireJti' => false,
        'iatMaxAge' => 60,
    ]);
    $verified = _stattic_visitor_verify($token, $verifyOptions);
    if ($verified === null) {
        return null;
    }
    // Positively require the handoff purpose: only a token minted for
    // redemption may consume the jti and become a durable session. Rejecting
    // known-bad purposes (system-view) is not enough. Any other purpose the
    // platform key signs, present or future, must fail here by default.
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    if (($claims['purpose'] ?? null) !== STATTIC_HANDOFF_PURPOSE) {
        return null;
    }
    $current = _stattic_current_session_identity($serving, $host);
    $currentSid = is_array($current) && is_string($current['sessionId'] ?? null)
        ? $current['sessionId']
        : null;
    $currentAuthorities = is_array($current) && is_array($current['authorityEntries'] ?? null)
        ? $current['authorityEntries']
        : [];
    $combinedAuthorities = _stattic_merge_authority_lru(
        $currentAuthorities,
        _stattic_callback_authorities($verified),
        is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [],
        is_array($verified['claims'] ?? null)
            && (($verified['claims']['emailVerified'] ?? false) === true),
        _stattic_access_session_now()
    );
    $verified = _stattic_visitor_verify(
        $token,
        array_merge($verifyOptions, ['requireJti' => true])
    );
    if ($verified === null) {
        return null;
    }
    $credential = _stattic_access_session_create(
        $serving,
        $verified,
        $host,
        _stattic_session_version($serving),
        is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [],
        $combinedAuthorities,
        $storedRecord,
        _stattic_access_session_inheritable($current)
    );
    if ($credential === null) {
        return null;
    }
    // The session id rotates to the token's; the session being left carried its
    // identity forward and must not outlive the attach, so its revocation
    // record goes with it.
    if ($currentSid !== null && $currentSid !== (string) ($storedRecord['sid'] ?? '')) {
        _stattic_access_session_record_delete(_stattic_access_private_root(), $currentSid);
    }
    return $credential;
}

// Hard-separated from the 60s redeem handoff: neither token can ever pass the
// other's checks, and a system view token never mints a visitor session. Each
// purpose is REQUIRED positively by its own consumer.
const STATTIC_SYSTEM_VIEW_PURPOSE = 'system-view';
const STATTIC_HANDOFF_PURPOSE = 'handoff';

function _stattic_system_view_cookie_name(): string
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1'
        ? STATTIC_SYSTEM_VIEW_DEV_COOKIE
        : STATTIC_SYSTEM_VIEW_COOKIE;
}

function _stattic_system_view_cookie_from_request(): string
{
    $cookie = $_COOKIE[_stattic_system_view_cookie_name()] ?? '';
    return is_string($cookie) ? $cookie : '';
}

function _stattic_system_view_scope(?string $scope = null): ?string
{
    static $admitted = null;
    if ($scope !== null) {
        $admitted = $scope;
    }
    return $admitted;
}

function _stattic_system_view_admits(string $scopePath): bool
{
    $scope = _stattic_system_view_scope();
    return $scope !== null && _stattic_scope_contains($scope, $scopePath);
}

function _stattic_access_verify_system_view_token(
    array $serving,
    string $host,
    string $token
): bool {
    $verified = _stattic_visitor_verify(
        $token,
        _stattic_visitor_verify_options($serving, $host, null, ['requireJti' => false])
    );
    if ($verified === null) {
        return false;
    }
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    // A redeem handoff must never open a page this way, and a view token must
    // never mint a session: one claim decides both directions.
    if (($claims['purpose'] ?? null) !== STATTIC_SYSTEM_VIEW_PURPOSE) {
        return false;
    }
    if (($claims['capabilities'] ?? null) !== ['page.view']) {
        return false;
    }
    // Revocation for a stateless token: rotating the Space's session version
    // invalidates every token minted before it.
    if (
        array_key_exists('sessionVersion', $claims)
        && (
            !is_int($claims['sessionVersion'])
            || $claims['sessionVersion'] !== _stattic_session_version($serving)
        )
    ) {
        return false;
    }
    $scope = '/';
    if (array_key_exists('scope', $claims)) {
        $candidate = is_string($claims['scope']) ? _stattic_scope_path($claims['scope']) : null;
        if ($candidate === null) {
            return false;
        }
        $scope = $candidate;
    }
    _stattic_system_view_scope($scope);
    return true;
}

// A link proves a capability and says nothing about who holds it, so when the
// Space knows accounts and this browser has not been probed inside the staleness
// window, chain exactly ONE silent account bounce before the clean URL.
function _stattic_access_link_entry_identity_bounce(
    array $serving,
    string $host,
    ?array $record,
    string $returnPath
): ?string {
    if (
        !is_array($record)
        || _stattic_access_principal_is_account(
            _stattic_access_principal_valid($record['principal'] ?? null)
                ? $record['principal']
                : STATTIC_SESSION_PRINCIPAL_ANONYMOUS
        )
    ) {
        return null;
    }
    $now = _stattic_access_session_now();
    $checkedAt = is_int($record['identityCheckedAt'] ?? null) ? $record['identityCheckedAt'] : null;
    if ($checkedAt !== null && $now - $checkedAt < STATTIC_ACCESS_IDENTITY_CHECK_SECONDS) {
        return null;
    }
    $lanes = _stattic_access_page_lanes($serving);
    if (!is_array($lanes) || !$lanes['silent'] || !is_string($lanes['account'])) {
        return null;
    }
    // The claim set this response is about to hand the browser is the record:
    // stamp the probe onto it and re-issue, so the bounce happens exactly once.
    $record['identityCheckedAt'] = $now;
    if (!_stattic_access_session_issue($serving, $host, $record)) {
        return null;
    }
    return _stattic_access_url_with_params($lanes['account'], [
        'silent' => '1',
        'host' => $host,
        'return' => $returnPath,
    ]);
}

function _stattic_render_access_route_not_found(): never
{
    _stattic_render_platform_page_lazy(
        'access-route-not-found',
        404,
        [
            'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
            'Referrer-Policy' => 'no-referrer',
        ],
        "Not found.\n",
        true
    );
    exit;
}

/**
 * Redeem a link token (or space key) against the exchange's linkUrl. Null when
 * the Space has no link exchange; otherwise the exchange verdict with the
 * handoff extracted. Every refusal disposition stays with the caller.
 *
 * @return array{status:int,body:?array,fields:?array,handoff:string,exchange:array,host:string}|null
 */
function _stattic_access_exchange_link_token(array $serving, string $requestHost, string $token): ?array
{
    $exchange = _stattic_access_page_exchange($serving);
    if ($exchange === null || !is_string($exchange['linkUrl'] ?? null)) {
        return null;
    }
    $host = _stattic_canonicalize_host($requestHost);
    $result = _stattic_access_exchange_post(
        $exchange['linkUrl'],
        ['host' => $host, 'token' => $token],
        _stattic_access_exchange_headers(
            $exchange,
            _stattic_access_context($serving, $requestHost, '/'),
            'application/vnd.spacefast.access-handoff+json'
        )
    );
    $body = is_array($result) && is_array($result['body'] ?? null) ? $result['body'] : null;
    $fields = is_array($body) && is_array($body['fields'] ?? null) ? $body['fields'] : null;
    return [
        'status' => (int) ($result['status'] ?? 0),
        'body' => $body,
        'fields' => $fields,
        'handoff' => is_array($fields) && is_string($fields['token'] ?? null) ? $fields['token'] : '',
        'exchange' => $exchange,
        'host' => $host,
    ];
}

// The credential lives in one reserved path for one request only: exchange it,
// set the host-only session cookie, then move to the clean landing URL. Customer
// bytes are never served from a credential path.
function _stattic_access_handle_link_entry(
    array $serving,
    string $requestPath,
    string $requestHost
): void {
    $token = _stattic_access_entry_token($requestPath);
    if ($token === null) {
        return;
    }
    if (!in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true)) {
        _stattic_render_method_not_allowed_lazy();
    }
    $redeemed = _stattic_access_exchange_link_token($serving, $requestHost, $token);
    if ($redeemed === null) {
        _stattic_render_access_route_not_found();
    }
    $host = $redeemed['host'];
    $body = $redeemed['body'];
    $fields = $redeemed['fields'];
    $exchange = $redeemed['exchange'];
    $returnPath = is_array($fields) && is_string($fields['return'] ?? null)
        ? _stattic_safe_return_path($fields['return'])
        : null;
    if ($redeemed['status'] === 200 && $redeemed['handoff'] !== '' && $returnPath !== null) {
        $storedRecord = null;
        $credential = _stattic_access_consume_handoff_token(
            $serving,
            $host,
            $redeemed['handoff'],
            $storedRecord
        );
        if ($credential !== null) {
            // The bounce re-issues the cookie with its probe stamped on, so it
            // decides first and only a declined bounce sets the plain one.
            $bounce = _stattic_access_link_entry_identity_bounce(
                $serving,
                $host,
                $storedRecord,
                $returnPath
            );
            if ($bounce === null) {
                _stattic_access_set_session_cookie($credential);
            }
            _stattic_access_redirect(
                $bounce ?? $returnPath,
                303,
                STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
                true
            );
        }
    }
    _stattic_access_gate_email_verification(
        $serving,
        $requestHost,
        $body,
        $exchange['emailUrl'] ?? null,
        '/'
    );
    _stattic_render_access_route_not_found();
}

// The raw access/API token never becomes a cookie and never reaches tenant code.
function _stattic_platform_identity_token(
    array $serving,
    string $requestHost,
    string $requestPath
): ?string {
    static $memo = [];
    $presented = _stattic_platform_bearer_token_from_request();
    if ($presented === null) {
        return null;
    }
    if ($presented === '') {
        return '';
    }
    // Header lane, not the `?__=` lane: an integration presents whatever it was
    // given, and a system view token it already holds is verified here instead
    // of being re-exchanged. Compact serialization is three dot-separated parts.
    if (substr_count($presented, '.') === 2) {
        _stattic_access_verify_system_view_token($serving, _stattic_canonicalize_host($requestHost), $presented);
        return $presented;
    }
    $host = _stattic_canonicalize_host($requestHost);
    $landingPath = _stattic_safe_return_path($requestPath) ?? '/';
    $memoKey = $host . "\0" . $landingPath . "\0" . $presented;
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    $exchange = _stattic_access_page_exchange($serving);
    if ($exchange === null || !is_string($exchange['tokenUrl'] ?? null)) {
        return $memo[$memoKey] = '';
    }
    $result = _stattic_access_exchange_post(
        $exchange['tokenUrl'],
        ['host' => $host, 'landingPath' => $landingPath, 'token' => $presented],
        _stattic_access_exchange_headers(
            $exchange,
            _stattic_access_context($serving, $requestHost, $landingPath),
            'application/vnd.spacefast.access-handoff+json'
        )
    );
    $body = is_array($result) ? $result['body'] : null;
    $fields = is_array($body) && is_array($body['fields'] ?? null) ? $body['fields'] : null;
    $identityToken = (
        ($result['status'] ?? 0) === 200
        && is_array($fields)
        && is_string($fields['token'] ?? null)
    ) ? $fields['token'] : '';
    if ($identityToken !== '') {
        _stattic_access_verify_system_view_token($serving, $host, $identityToken);
    }
    return $memo[$memoKey] = $identityToken;
}

// THE `?__=` lane, and the shape of the whole thing: whatever the token proves,
// it proves it for the URL it is sitting on, and that URL answers with its own
// content in this response. Nothing here redirects: a link an agent cannot
// follow with one request is not a link.
//
// Which lane runs is the token's own declaration (its prefix), never a guess at
// its shape. A token naming no lane is refused by name rather than dropped on
// the floor for the gate to answer.
function _stattic_access_apply_query_token(
    array $serving,
    string $requestHost,
    string $requestPath
): void {
    if (!_stattic_access_query_token_present()) {
        return;
    }
    $kind = _stattic_access_query_token_kind();
    if ($kind === null) {
        _stattic_render_access_query_token_unrecognized();
    }
    // Redemption is a GET/HEAD act. Any other method keeps whatever the request
    // already presented: a form posting back to the URL it was opened from still
    // carries the token, and the cookie that first request set is what admits it.
    if (!in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true)) {
        return;
    }
    // Contract A1, charged once a lane has claimed the token and real work is
    // about to happen. A refusal above costs no slot, so appending nonsense to a
    // public URL cannot spend the Space's access lane.
    _stattic_admission_acquire_access_lane(_stattic_access_private_root(), $serving);
    $token = (string) _stattic_access_query_token();
    if ($kind === 'link') {
        _stattic_access_redeem_query_link_token($serving, $requestHost, $token);
        return;
    }
    // The JWT remains the authority. Echo it briefly into a separate host-only
    // cookie so the renderer's CSS, images, fonts and scripts can present the
    // same proof without rewriting customer bytes. There is no session record,
    // and every subrequest re-verifies the JWT locally.
    $verified = _stattic_access_verify_system_view_token(
        $serving,
        _stattic_canonicalize_host($requestHost),
        $token
    );
    if ($verified && _stattic_system_view_admits($requestPath)) {
        _stattic_set_cookie(
            _stattic_system_view_cookie_name(),
            $token,
            STATTIC_SYSTEM_VIEW_COOKIE_SECONDS
        );
    }
}

// Named, uniform, and content-free: the operator can tell a mis-shaped token
// from a dead one, and the visitor learns nothing about the Space either way.
function _stattic_render_access_query_token_unrecognized(): never
{
    _stattic_render_platform_page_lazy(
        'access-route-not-found',
        400,
        [
            'Cache-Control' => STATTIC_CACHE_CONTROL_NO_STORE,
            'Referrer-Policy' => 'no-referrer',
            'X-Spacefast-Access-Diagnostic' => 'query-token-unrecognized',
        ],
        "That link is not in a form this site can open.\n",
        true
    );
    exit;
}

// A customer share link (or a space key) on the URL it is meant to open: redeem
// it server-to-server, set the session cookie on THIS response, and return so
// the enforcement below serves the page the visitor actually asked for. The
// link's own stored landing path is not consulted: the request path is the
// landing path, which is what makes a deep link a deep link.
//
// A token that does not open anything returns silently: enforcement then answers
// exactly as if the parameter had been absent, so a dead link shows the Space's
// gate rather than telling a prober that this token once existed.
function _stattic_access_redeem_query_link_token(
    array $serving,
    string $requestHost,
    string $token
): void {
    $redeemed = _stattic_access_exchange_link_token($serving, $requestHost, $token);
    if ($redeemed === null) {
        return;
    }
    $body = $redeemed['body'];
    $exchange = $redeemed['exchange'];
    if ($redeemed['status'] === 200 && $redeemed['handoff'] !== '') {
        $storedRecord = null;
        $credential = _stattic_access_consume_handoff_token($serving, $redeemed['host'], $redeemed['handoff'], $storedRecord);
        if ($credential !== null) {
            // The cookie is the whole point: the token drops out of every URL
            // the visitor navigates to next, and the page's own subresources
            // present the session instead of the secret. `_stattic_set_cookie`
            // updates $_COOKIE too, so the enforcement below this call resolves
            // the identity this response just handed out.
            _stattic_access_set_session_cookie($credential);
        }
        return;
    }
    // One lane still has to be answered rather than served.
    _stattic_access_gate_email_verification(
        $serving,
        $requestHost,
        $body,
        $exchange['emailUrl'] ?? null,
        _stattic_access_clean_return_path()
    );
}

function _stattic_access_apply_system_view_cookie(array $serving, string $requestHost): void
{
    // An explicit query token is authoritative for this request. Never let an
    // older cookie turn an invalid or out-of-scope URL into a successful one.
    if (_stattic_access_query_token_present()) {
        return;
    }
    $token = _stattic_system_view_cookie_from_request();
    if ($token === '') {
        return;
    }
    if (
        !_stattic_access_verify_system_view_token(
            $serving,
            _stattic_canonicalize_host($requestHost),
            $token
        )
    ) {
        _stattic_clear_cookie(_stattic_system_view_cookie_name());
        _stattic_identity_cookie_mutated(true);
    }
}

// Every central callback is host-bound, at most one minute old, and single-use;
// the browser receives only a local opaque session id. A runtime token in a GET
// query is safe on exactly the properties this lane preserves: one-use jti, 60s
// TTL, no-referrer, no-store.
function _stattic_access_handle_callback(array $serving, string $requestHost, string $privateRoot): void
{
    _stattic_visitor_lane_begin($privateRoot);
    $requestMethod = _stattic_runtime_request_method();
    if ($requestMethod !== 'POST' && $requestMethod !== 'GET') {
        _stattic_method_not_allowed('GET, POST');
    }
    $fields = $requestMethod === 'POST' ? $_POST : $_GET;
    $tokenField = $requestMethod === 'POST' ? 'token' : 'sf_token';
    $token = is_string($fields[$tokenField] ?? null) ? trim((string) $fields[$tokenField]) : '';
    $returnRaw = is_string($fields['return'] ?? null) ? (string) $fields['return'] : '/';
    $returnTo = _stattic_safe_return_path($returnRaw);
    if ($token === '' || $returnTo === null) {
        _stattic_render_json_or_deny('access_handoff_invalid', 'Access token handoff is invalid.');
    }

    $host = _stattic_canonicalize_host($requestHost);
    $sessionToken = _stattic_access_consume_handoff_token($serving, $host, $token);
    if ($sessionToken === null) {
        _stattic_render_json_or_deny('access_handoff_invalid', 'Access token handoff is invalid.');
    }
    _stattic_access_set_session_cookie($sessionToken);
    header('Cache-Control: no-store', true);
    header('Referrer-Policy: no-referrer', true);
    if (($fields['display'] ?? '') === 'popup') {
        // The session cookie is already set browser-wide: tell the opener
        // (same-origin only) and close. No token ever crosses postMessage.
        $nonce = bin2hex(random_bytes(16));
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; base-uri 'none'; frame-ancestors 'none'", true);
        header('Content-Type: text/html; charset=utf-8', true);
        echo '<!doctype html><meta name="referrer" content="no-referrer"><title>Access granted</title>'
            . '<p>You&#039;re in. You can close this window.</p>'
            . '<p><a href="' . _stattic_html_escape($returnTo) . '">Continue</a></p>'
            . '<script nonce="' . $nonce . '">'
            . 'if(window.opener){try{window.opener.postMessage("sf-access-complete",window.location.origin)}catch(e){}}'
            . 'window.close();'
            . '</script>';
        exit;
    }
    header('Location: ' . $returnTo, true, 303);
    exit;
}

// The id is inside the signed claim set, so naming a session to revoke requires
// a cookie this runtime actually minted for this host.
function _stattic_access_presented_session_id(): ?string
{
    $credential = _stattic_visitor_cookie_from_request();
    if ($credential === '' || !str_starts_with($credential, STATTIC_ACCESS_SESSION_PREFIX)) {
        return null;
    }
    $claims = _stattic_access_session_decode(
        _stattic_page_serving(),
        _stattic_canonicalize_host(
            _stattic_normalize_hostname((string) ($_SERVER['HTTP_HOST'] ?? ''))
        ),
        $credential
    );
    return is_array($claims) ? (string) $claims['sid'] : null;
}

// Exact revocation (D85): deleting THIS session's record denies its cookie at
// its next revalidation and touches no other session. Nothing global moves.
function _stattic_access_revoke_presented_session(?string $sessionId = null): bool
{
    $sessionId = $sessionId ?? _stattic_access_presented_session_id();
    if ($sessionId === null) {
        return true;
    }
    $privateRoot = _stattic_access_private_root();
    if ($privateRoot === '') {
        return false;
    }
    if (!_stattic_is_sha256_hex($sessionId)) {
        // Not a session id the store could hold: nothing to revoke.
        return true;
    }
    _stattic_access_session_record_delete($privateRoot, $sessionId);
    $path = _stattic_access_session_path($privateRoot, $sessionId);
    clearstatcache(true, $path);
    return !file_exists($path);
}

// https ALWAYS, exactly like _stattic_cookies_secure: X-Forwarded-Proto reaches
// PHP attacker-controlled (contracts §16), and this scheme builds the Origin the
// logout CSRF check compares against. A caller who could set it would be
// choosing its own expected origin. The dev/test flag is the only escape.
function _stattic_request_scheme(): string
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1' ? 'http' : 'https';
}

function _stattic_access_revoke_collaboration_session(
    array $serving,
    string $requestHost,
    string $sessionId,
    string $returnTo
): ?array {
    $exchange = _stattic_access_page_exchange($serving);
    $spaceId = _stattic_serving_space_id($serving);
    $logoutUrl = $exchange !== null && is_string($exchange['logoutUrl'] ?? null)
        ? $exchange['logoutUrl']
        : null;
    // No endpoint: the local session must still be removable.
    if ($logoutUrl === null || $spaceId === '') {
        return ['clearUrl' => null];
    }
    $runtimeHost = trim($requestHost);
    $returnUrl = _stattic_request_scheme() . '://' . $runtimeHost . $returnTo;
    $result = _stattic_access_exchange_post(
        $logoutUrl,
        [
            'spaceId' => $spaceId,
            'sessionId' => $sessionId,
            'runtimeHost' => $runtimeHost,
            'returnUrl' => $returnUrl,
        ],
        _stattic_access_exchange_headers($exchange, [], 'application/json')
    );
    $clearUrl = is_array($result)
        && $result['status'] === 200
        && is_array($result['body'] ?? null)
        && is_array($result['body']['data'] ?? null)
        && is_string($result['body']['data']['clearUrl'] ?? null)
        ? $result['body']['data']['clearUrl']
        : null;
    // Sessions created before central registration legitimately have no row;
    // their local bearer and cookie must still be cleared.
    if (is_array($result) && $result['status'] === 404) {
        return ['clearUrl' => null];
    }
    if ($clearUrl === null || !_stattic_platform_destination_allowed($clearUrl)) {
        return null;
    }
    $logoutOrigin = parse_url($logoutUrl);
    $clearOrigin = parse_url($clearUrl);
    if (
        !is_array($logoutOrigin)
        || !is_array($clearOrigin)
        || strtolower((string) ($logoutOrigin['scheme'] ?? '')) !== strtolower((string) ($clearOrigin['scheme'] ?? ''))
        || strtolower((string) ($logoutOrigin['host'] ?? '')) !== strtolower((string) ($clearOrigin['host'] ?? ''))
        || (int) ($logoutOrigin['port'] ?? 0) !== (int) ($clearOrigin['port'] ?? 0)
    ) {
        return null;
    }
    return ['clearUrl' => $clearUrl];
}

// Same-origin containment boundary: POST verifies Origin, the navigation lane
// must carry same-origin Fetch Metadata. A failed durable removal never reports
// success and never discards the caller's only copy of a still-live bearer.
function _stattic_access_logout_request_allowed(string $method, string $requestHost): bool
{
    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($method === 'POST') {
        $origin = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));
        $expectedOrigin = strtolower(_stattic_request_scheme() . '://' . trim($requestHost));
        $allowed = $origin !== ''
            && hash_equals($expectedOrigin, $origin)
            && ($fetchSite === '' || $fetchSite === 'same-origin');
    } elseif ($method === 'GET') {
        $fetchMode = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')));
        $fetchDest = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
        $allowed = $fetchSite === 'same-origin'
            && $fetchMode === 'navigate'
            && $fetchDest === 'document';
    } else {
        return false;
    }
    return $allowed;
}

function _stattic_access_logout_unavailable(): never
{
    _stattic_response_send(
        503,
        "Could not log out safely. Try again.\n",
        'text/plain; charset=utf-8',
        ['Cache-Control' => 'no-store', 'Retry-After' => '1']
    );
}

function _stattic_access_handle_logout(string $requestHost): void
{
    $method = _stattic_runtime_request_method();
    if ($method !== 'GET' && $method !== 'POST') {
        _stattic_method_not_allowed('GET, POST');
    }
    if (!_stattic_access_logout_request_allowed($method, $requestHost)) {
        http_response_code(403);
        header('Cache-Control: no-store', true);
        exit;
    }
    $returnRaw = isset($_POST['return']) && is_string($_POST['return'])
        ? (string) $_POST['return']
        : (isset($_GET['return']) && is_string($_GET['return']) ? (string) $_GET['return'] : '/');
    $returnTo = _stattic_safe_return_path($returnRaw) ?? '/';
    $sessionId = _stattic_access_presented_session_id();
    $serving = _stattic_page_serving();
    // Ending the session ends its Cast session: one id names both. The stateless
    // lane keeps its id in the cookie payload rather than a record, so read it
    // from the resolved identity, never from the cookie value itself, which must
    // never travel to the control plane or into a Cast room.
    $comments = _stattic_access_identity_comments(
        _stattic_current_session_identity($serving, $requestHost)
    );
    $collaborationSessionId = $comments['sessionId'] ?? $sessionId;
    $clearUrl = null;
    if ($collaborationSessionId !== null) {
        $collaborationRevoke = _stattic_access_revoke_collaboration_session(
            $serving,
            $requestHost,
            $collaborationSessionId,
            $returnTo
        );
        if ($collaborationRevoke === null) {
            _stattic_access_logout_unavailable();
        }
        $clearUrl = $collaborationRevoke['clearUrl'];
    }
    if (!_stattic_access_revoke_presented_session($sessionId)) {
        _stattic_access_logout_unavailable();
    }
    _stattic_clear_cookie(_stattic_session_cookie_name());
    // The clearUrl is cross-origin: the return= param in this URL must not
    // leak as a Referer.
    _stattic_access_redirect($clearUrl ?? $returnTo, 303);
}

// Comments has no session of its own: the visitor session's id names the Cast
// session, and the pseudonym rides the same record. Both halves come from one
// place, so revoking the session revokes Comments by construction.
function _stattic_access_identity_comments(?array $identity): ?array
{
    $record = _stattic_access_identity_record($identity);
    if (
        $record === null
        || !_stattic_is_sha256_hex($record['sid'] ?? null)
        || !_stattic_is_anonymous_id($record['anonymousId'] ?? null)
    ) {
        return null;
    }
    return [
        'sessionId' => $record['sid'],
        'anonymousId' => $record['anonymousId'],
    ];
}

// The seam runtime/zero.php, runtime/serve.php and runtime/spacefast-sdk.php
// resolve a cookie identity through.
function _stattic_verify_cookie_identity(array $serving, string $requestHost): ?array
{
    return _stattic_current_session_identity($serving, $requestHost);
}

function _stattic_render_json_or_deny(string $code, string $message): never
{
    if (_stattic_request_is_fetch()) {
        _stattic_render_json_unauthenticated($code);
    }
    _stattic_response_send(403, $message . "\n", 'text/plain; charset=utf-8', _stattic_private_content_response_headers([
        'Cache-Control' => 'private, no-store',
    ]));
}
