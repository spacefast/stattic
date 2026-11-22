<?php
declare(strict_types=1);

require_once __DIR__ . "/../shared/request-origin.php";
require_once __DIR__ . "/share-oauth.php";

require_once __DIR__ . "/../shared/security-telemetry.php";

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

// An alias of THE hostname normalizer (shared/context.php) for this file's
// callers; code outside it calls _stattic_normalize_hostname directly.
function _stattic_canonicalize_host(string $host): string
{
    return _stattic_normalize_hostname($host);
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
// These two are HMAC-SHA256 under the Space's own session key, which only this
// box holds (`_stattic_access_session_hmac_key`), not EdDSA: re-minting on a
// gen change must happen in-process with no round trip. Every token that CROSSES the trust
// boundary (handoff, platform bearer, blob gate) stays EdDSA-verified.
const STATTIC_ACCESS_SESSION_PREFIX = 'sfv2_';
const STATTIC_ANONYMOUS_SESSION_PREFIX = 'sfa1_';
const STATTIC_ANONYMOUS_SESSION_MAX_BYTES = 512;
// A Set-Cookie value has ~4096 bytes for everything, ~115 of them name and
// attributes. A claim set that still does not fit sheds its LRU authority.
const STATTIC_ACCESS_SESSION_MAX_BYTES = 3900;
const STATTIC_ACCESS_SESSION_CLAIM_VERSION = 1;
// A claim set with a capped authority. An engine before the cap reads only
// version 1, so it drops such a session instead of honouring its authorities
// without their cap.
const STATTIC_ACCESS_SESSION_CAPPED_CLAIM_VERSION = 2;
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
// How far in the future a stored authority entry's timestamps may sit and
// still count for this request. A later entry is left out of the live set until
// the clock reaches it; it is not deleted. Visitor-token expiry has its own rule
// (shared/jwt.php) and does not follow this value.
const STATTIC_ACCESS_SESSION_CLOCK_SKEW_SECONDS = 300;
const STATTIC_ACCESS_BROWSER_STATE_COOKIE_PREFIX = '__Host-spacefast_access_state_';
const STATTIC_ACCESS_BROWSER_STATE_DEV_COOKIE_PREFIX = 'spacefast_access_state_dev_';
const STATTIC_ACCESS_BROWSER_STATE_SECONDS = 600;
// Returning accounts. A browser that held an account session on this host may
// renew one without a click: the Comments overlay, about to make it a visitor,
// navigates to this host's account start (`/__spacefast/access/account?silent=1`),
// which runs the Space's own sign-in with `prompt=none`. A top-level navigation, so the
// sign-in host sees only its own first-party cookie, and the session comes back
// through the Space's callback like any sign-in. The marker that allows it is
// one bit, "an account signed in here": no id, no secret. It is host-only and
// script-readable because the overlay reads it, so a script on this host's
// pages can tell, for as long as it lasts and after the session itself has
// lapsed, that an account signed in from this browser; it never says which.
// Only an account sign-in (the callback or a handoff) writes it; logout and
// every silent start spend it, so a renewal that comes back without an account
// never runs again, and a forged marker buys one round trip that ends where it began. The
// collab manifest names it (`returningAccountCookie`), and naming it is what
// tells the overlay this Space renews at all.
const STATTIC_RETURNING_ACCOUNT_COOKIE = '__Host-spacefast_returning';
const STATTIC_RETURNING_ACCOUNT_DEV_COOKIE = 'spacefast_returning_dev';
// Well past the session's own 30 days: the marker is for the visit after that.
const STATTIC_RETURNING_ACCOUNT_SECONDS = 15552000;

function _stattic_page_serving(): array
{
    return is_array($GLOBALS['SPACEFAST_PAGE_SERVING'] ?? null)
        ? $GLOBALS['SPACEFAST_PAGE_SERVING']
        : [];
}

// A guest's per-Space subject: `pg1_` and 32 random bytes in base64url. It is
// the ticket `sub` Cast keys the guest on, so it must be unguessable. A browser
// that already holds an older `anon_` id keeps it, so a guest who commented
// before this engine is still the same guest; new ids are always `pg1_`.
function _stattic_is_anonymous_id(mixed $value): bool
{
    return is_string($value)
        && preg_match('/\A(?:pg1_[A-Za-z0-9_-]{43}|anon_[a-f0-9]{32})\z/', $value) === 1;
}

// What the exchange is told this browser is, for its per-browser rate limits:
// always `anon_` and 32 hex, the one form every control-plane image reads. An
// older `anon_` id goes as it is; a `pg1_` id goes as a keyed hash under a key
// only this box holds, so the guest id itself never leaves the box.
function _stattic_access_browser_header_value(string $browser, string $key): string
{
    return str_starts_with($browser, 'anon_')
        ? $browser
        : 'anon_' . substr(hash_hmac('sha256', 'visitor-browser:' . $browser, $key), 0, 32);
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
    return 'pg1_' . _stattic_base64url_encode(random_bytes(32));
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

/**
 * The Space bar is for an account that belongs to the Space: one whose
 * session holds a live authority the compiled Grant index lists as belonging
 * (_stattic_grant_index_belonging). A projection compiled before that list
 * existed lists none, so it gives no bar until the next route sync.
 */
function _stattic_access_session_shows_bar(array $serving, ?array $identity, string $principal): bool
{
    if (!_stattic_access_principal_is_account($principal) || !is_array($identity)) {
        return false;
    }
    $index = $serving['authorization']['grantIndex'] ?? null;
    $belonging = is_array($index) ? ($index['belonging'] ?? null) : null;
    if (!is_array($belonging) || $belonging === []) {
        return false;
    }
    $entries = is_array($identity['authorityEntries'] ?? null) ? $identity['authorityEntries'] : [];
    foreach ($entries as $entry) {
        $reference = is_array($entry) && is_string($entry['reference'] ?? null) ? $entry['reference'] : '';
        if ($reference === '') {
            continue;
        }
        // A team member holds `member:<membership>`, matched by the Space's
        // team Grant (`team:<teamId>`) the same way admission matches it.
        $teamReference = _stattic_member_authority_team_reference($index, $reference);
        if (
            ($belonging[$reference] ?? false) === true
            || ($teamReference !== null && ($belonging[$teamReference] ?? false) === true)
        ) {
            return true;
        }
    }
    return false;
}

/** Valid only inside an already verified handoff or host-bound session. */
/**
 * The account-identity issuer this runtime accepts.
 *
 * The claim arrives inside a cookie this runtime signed, so this is a shape
 * check, not a trust boundary. It is pinned to the API base the engine is
 * configured against when there is one: the old literal
 * `https://<host>/v1/auth` pattern threw away every session on dev and
 * staging, whose issuers are `http://` or carry a port.
 */
function _stattic_access_identity_issuer_accepted(string $issuer): bool
{
    $configured = function_exists('_stattic_runtime_api_base_url') ? _stattic_runtime_api_base_url() : '';
    if ($configured !== '') {
        return $issuer === rtrim($configured, '/') . '/v1/auth';
    }
    return preg_match('~\Ahttps?://[A-Za-z0-9.-]+(:[0-9]{1,5})?/v1/auth\z~', $issuer) === 1;
}

function _stattic_access_account_identity(array $claims): ?array
{
    $identity = $claims['identity'] ?? null;
    if (!is_array($identity) || array_diff(array_keys($identity), ['issuer', 'subject']) !== []
        || !is_string($identity['issuer'] ?? null) || !is_string($identity['subject'] ?? null)
        || !_stattic_access_identity_issuer_accepted($identity['issuer'])
        || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $identity['subject']) !== 1
        || ($claims['principal'] ?? null) !== 'account:' . $identity['subject']) {
        return null;
    }
    return ['issuer' => $identity['issuer'], 'subject' => $identity['subject']];
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

// A share link or a password admitted this session.
function _stattic_access_identity_holds_credential(?array $identity): bool
{
    $authorities = is_array($identity['authorities'] ?? null) ? $identity['authorities'] : [];
    return array_any($authorities, static fn (mixed $reference): bool =>
        is_string($reference)
        && (str_starts_with($reference, 'link:') || str_starts_with($reference, 'password:')));
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
    // Idle records are kept. Keeping one does not extend its admission
    // lifetime: the idle check reads lastSeenAt, not the file.
    return _stattic_record_store($root);
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
    if (isset($value['email'])) {
        if (!is_string($value['email']) || strlen($value['email']) > 320 || filter_var($value['email'], FILTER_VALIDATE_EMAIL) === false) return null;
        $profile['email'] = $value['email'];
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

// One key per space and per purpose. Every cookie shares the material, so the
// label is what stops one verifying as another.
//
// The material is the Space's own session key, which this box minted at its
// first route sync (`_stattic_space_session_key`) and which never leaves it.
// From then on a cookie signed the older way, under the runtime exchange
// credential the control plane derives from its own secret, verifies nothing:
// one sign-out per box, and a leaked control-plane secret forges no session.
// An overlay written before that sync has no key, so the box keeps the older
// derivation until it syncs. A Space without an exchange holds no session
// either way.
function _stattic_access_session_hmac_key(array $serving, string $label): ?string
{
    $exchange = _stattic_access_page_exchange($serving);
    if ($exchange === null) {
        return null;
    }
    $own = $serving['authorization']['sessionKey'] ?? null;
    if (_stattic_space_session_key_valid($own)) {
        return hash_hmac('sha256', $label, $own);
    }
    // old-engine-compat: S19 visitor sessions under the exchange credential.
    $credential = is_string($exchange['credential'] ?? null) ? $exchange['credential'] : '';
    return strlen($credential) >= 32 ? hash_hmac('sha256', $label, $credential) : null;
}

function _stattic_space_session_key_valid(mixed $key): bool
{
    return is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1;
}

// A claim set is only ever read back from a signature this runtime produced, so
// this validates shape (an engine downgrade, a truncated write), not trust.
function _stattic_access_session_claims_valid(mixed $claims): bool
{
    if (
        !is_array($claims)
        || !in_array($claims['v'] ?? null, [STATTIC_ACCESS_SESSION_CLAIM_VERSION, STATTIC_ACCESS_SESSION_CAPPED_CLAIM_VERSION], true)
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
    'expiresAt' => 'x',
    'capabilities' => 'c',
    'origin' => 'i',
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
    // An authority acquired by this session itself names no origin: that is
    // the session's own id (_stattic_access_session_unended_entries).
    $entries = is_array($claims['authorities'] ?? null) ? $claims['authorities'] : [];
    $claims['v'] = array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && is_array($entry['capabilities'] ?? null)) === []
        ? STATTIC_ACCESS_SESSION_CLAIM_VERSION
        : STATTIC_ACCESS_SESSION_CAPPED_CLAIM_VERSION;
    $claims['authorities'] = _stattic_access_session_pack_authorities(array_map(
        static fn (mixed $entry): mixed => is_array($entry) && ($entry['origin'] ?? null) === ($claims['sid'] ?? null)
            ? array_diff_key($entry, ['origin' => true])
            : $entry,
        $entries
    ));
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
    if (array_key_exists('identity', $claims) && _stattic_access_account_identity($claims) === null) return null;
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
    _stattic_security_session($serving, $claims["spaceId"], $claims["sid"], "renewed");
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
    return true;
}

function _stattic_access_session_record_delete(string $privateRoot, string $sessionId): bool
{
    $store = _stattic_access_session_store($privateRoot);
    if ($store === null || !_stattic_is_sha256_hex($sessionId)) {
        return false;
    }
    $record = _stattic_record_store_get($store, $sessionId);
    _stattic_record_store_delete($store, $sessionId);
    $serving = _stattic_page_serving();
    $spaceId = $record['spaceId'] ?? $serving['space_id'] ?? null;
    if (is_string($spaceId)) {
        $lifecycle = _stattic_record_store_read($store, $sessionId)['state'] === 'absent' ? 'revoked' : 'revocation_failed';
        _stattic_security_session($serving, $spaceId, $sessionId, $lifecycle);
    }
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
        $cap = _stattic_handoff_capability_cap($claims);
        if ($cap === false) return null;
        $authorityEntries = _stattic_merge_authority_lru(
            [],
            _stattic_callback_authorities($verified),
            $projection,
            (($claims['emailVerified'] ?? false) === true),
            $now,
            null,
            $cap,
            is_string($claims['sid'] ?? null) ? $claims['sid'] : null
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
    // A handoff that names nobody keeps the account it attaches to whole:
    // its public profile and sign-in provider stay with its identity.
    $names = _stattic_access_principal_from_claims($claims) !== null;
    $profile = _stattic_access_public_profile($names ? ($claims['profile'] ?? null) : ($inherit['profile'] ?? null));
    $authProvider = $names ? ($claims['authProvider'] ?? null) : ($inherit['authProvider'] ?? null);
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
        'host' => _stattic_canonicalize_host($host),
        'iat' => $now,
        'exp' => $now + STATTIC_ACCESS_SESSION_CLAIM_TTL_SECONDS,
        ...($profile !== null ? ['profile' => $profile] : []),
        ...(in_array($authProvider, ['google', 'gravatar'], true) ? ['authProvider' => $authProvider] : []),
        ..._stattic_access_session_carried_identity($inherit),
    ];
    $principalDeadline = $names
        ? ($claims['authorityExpiresAt'] ?? null)
        : ($inherit['principalExpiresAt'] ?? null);
    if (is_int($principalDeadline)) $session['principalExpiresAt'] = $principalDeadline;
    $accountIdentity = _stattic_access_account_identity($claims)
        ?? (_stattic_access_principal_from_claims($claims) === null ? _stattic_access_account_identity($inherit) : null);
    if ($accountIdentity !== null) $session['identity'] = $accountIdentity;
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
    _stattic_security_session($serving, $spaceId, $sessionId, "issued");
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
        'principalExpiresAt' => $record['principalExpiresAt'] ?? null,
        'identity' => $record['identity'] ?? null,
        'profile' => $record['profile'] ?? null,
        'authProvider' => $record['authProvider'] ?? null,
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

// The one mint for a visitor's stable guest pseudonym, and the reason it has to
// be callable from the DOCUMENT response: a Zero page's client opens auth.get,
// query.subscribe and mutation.run in parallel on first render. Each of those
// requests arrives without a cookie, each mints its own session, and the browser
// keeps whichever Set-Cookie landed last — so the identity the page displays and
// the identity a mutation ran under disagree. Nothing in a request can correlate
// two parallel cookie-less XHRs, so the session must already exist before any of
// them leaves. Capsules bake their client at build time and may never be rebuilt,
// so client-side serialization can only be an optimization on top of this.
//
// Idempotent: a visitor who already carries an anonymous pseudonym keeps it, and
// a presented recorded credential is never written over.
function _stattic_anonymous_session_ensure_anonymous_id(
    array $serving,
    string $requestHost
): ?string {
    $presented = _stattic_anonymous_session_decode(
        $serving,
        $requestHost,
        _stattic_visitor_cookie_from_request()
    );
    $existing = $presented === null ? null : _stattic_collab_anonymous_id($presented);
    if ($existing !== null) {
        return $existing;
    }
    _stattic_guest_session_mint_active(true);
    try {
        $payload = _stattic_anonymous_session_write($serving, $requestHost, [
            'anonymousId' => _stattic_collab_mint_anonymous_id(),
        ]);
    } finally {
        _stattic_guest_session_mint_active(false);
    }
    return is_array($payload) ? _stattic_collab_anonymous_id($payload) : null;
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
        && (($value['expiresAt'] ?? null) === null || (is_int($value['expiresAt']) && $value['expiresAt'] > 0))
        && (($value['capabilities'] ?? null) === null || _stattic_capability_cap_valid($value['capabilities']))
        && (($value['origin'] ?? null) === null || _stattic_is_sha256_hex($value['origin']))
        && is_int($value['openedAt'] ?? null)
        && $value['openedAt'] > 0
        && is_int($value['lastSeenAt'] ?? null)
        && $value['lastSeenAt'] >= $value['openedAt'];
}

// The most an authority may do, whatever its Grants give. A Sign in with
// Spacefast session lives on the Space's own origin, where the publisher's
// script and server code run, so it carries the capabilities a Space-origin
// session may use (view and comment) on each authority it names. The real
// Grants still decide; this only ever narrows them (_stattic_grant_decision).
const STATTIC_GRANT_CAPABILITIES = ['page.view', 'comments.read', 'comments.write', 'content.publish', 'access.manage'];

function _stattic_capability_cap_valid(mixed $value): bool
{
    return is_array($value) && array_is_list($value) && $value !== []
        && count($value) === count(array_unique($value))
        && array_diff($value, STATTIC_GRANT_CAPABILITIES) === [];
}

/**
 * The cap a handoff puts on the authorities it brings: null for none, false
 * for a malformed one (the handoff is refused). A Sign in with Spacefast
 * handoff names its real authorities and their cap in `spaceOrigin`, which an
 * engine before `runtime.real-authority-sessions.v1` does not read: there the
 * consent authority in `authorities` matches no Grant and the sign-in fails
 * closed, instead of opening the real authorities uncapped.
 */
function _stattic_handoff_capability_cap(array $claims): array|false|null
{
    if (!array_key_exists('spaceOrigin', $claims)) return null;
    $origin = $claims['spaceOrigin'];
    if (!is_array($origin) || !_stattic_capability_cap_valid($origin['capabilities'] ?? null)
        || !is_array($origin['authorities'] ?? null) || !array_is_list($origin['authorities'])
        || $origin['authorities'] === [] || count($origin['authorities']) > 16) {
        return false;
    }
    foreach ($origin['authorities'] as $authority) {
        if (!_stattic_authority_reference_valid($authority)) return false;
    }
    return array_values($origin['capabilities']);
}

/** Each capped authority a session holds, with its cap, for _stattic_grant_decision. */
function _stattic_identity_authority_capabilities(?array $identity): array
{
    $caps = is_array($identity['authorityCapabilities'] ?? null) ? $identity['authorityCapabilities'] : [];
    foreach (is_array($identity['authorityEntries'] ?? null) ? $identity['authorityEntries'] : [] as $entry) {
        if (is_array($entry) && is_string($entry['reference'] ?? null) && is_array($entry['capabilities'] ?? null)) {
            $caps[$entry['reference']] = $entry['capabilities'];
        }
    }
    return $caps;
}

/**
 * The session ids the control plane says have ended on this Space: a consent
 * withdrawn, a sign-out of this Space, a banned account. A session whose id
 * is listed loses every authority it acquired, at once, on the hot path; an
 * authority carried into a later session keeps the id of the one that
 * acquired it (`origin`), so it ends too.
 */
function _stattic_authorization_ended_sessions(array $projection): array
{
    $ids = _stattic_ended_session_ids($projection['grantIndex']['endedSessions'] ?? []);
    return $ids === null ? [] : array_fill_keys($ids, true);
}

// When more sessions ended than the list holds, the control plane also names a
// cutoff: every capped (Space-origin) authority opened at or before it ends.
function _stattic_authorization_ended_before(array $projection): ?int
{
    $before = $projection['grantIndex']['endedBefore'] ?? null;
    return is_int($before) && $before > 0 ? $before : null;
}

function _stattic_access_session_unended_entries(array $claims, array $projection): array
{
    $ended = _stattic_authorization_ended_sessions($projection);
    $before = _stattic_authorization_ended_before($projection);
    if ($ended === [] && $before === null) return $claims['authorities'];
    return array_values(array_filter(
        $claims['authorities'],
        static fn (array $entry): bool => !isset($ended[$entry['origin'] ?? $claims['sid']])
            && ($before === null || !is_array($entry['capabilities'] ?? null) || $entry['openedAt'] > $before)
    ));
}

function _stattic_access_session_retained_authority_entries(array $entries, int $now): array
{
    return array_values(array_filter(
        $entries,
        static fn ($entry): bool =>
            _stattic_access_session_authority_entry_valid($entry)
            && (($entry['expiresAt'] ?? null) === null || $entry['expiresAt'] > $now)
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
        || strlen($value) > STATTIC_ACCESS_SCOPE_PATH_MAX_BYTES + 3
        || $value === ''
        || $value[0] !== '/'
        || str_starts_with($value, '//')
        || preg_match('/[\x00-\x1F\x7F\\\\?#]/', $value) === 1
        || str_contains($value, '%')
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
    // Match the shared evaluator: O(patternCount * pathCount) work and
    // O(pathCount) memory, without recursive suffix enumeration.
    $next = array_fill(0, $pathCount + 1, false);
    $next[$pathCount] = true;
    for ($patternIndex = $patternCount - 1; $patternIndex >= 0; $patternIndex--) {
        $segment = $patternSegments[$patternIndex];
        $current = array_fill(0, $pathCount + 1, false);
        for ($pathIndex = $pathCount; $pathIndex >= 0; $pathIndex--) {
            $current[$pathIndex] = $segment === '**'
                ? ($next[$pathIndex] || ($current[$pathIndex + 1] ?? false))
                : ($pathIndex < $pathCount
                    && ($segment === '*' || $segment === $pathSegments[$pathIndex])
                    && $next[$pathIndex + 1]);
        }
        $next = $current;
    }
    return $next[0];
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
        'notBefore', 'expiresAt', 'maxUses', 'requireVerifiedEmail', 'network', 'frameOrigin',
    ]) !== []) {
        return false;
    }
    if (isset($constraints['frameOrigin'])) {
        $origin = _stattic_absolute_url_origin($constraints['frameOrigin']);
        $parts = $origin === null ? false : parse_url($origin);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (
            $origin === null
            || $origin !== $constraints['frameOrigin']
            || ($scheme !== 'https' && !($scheme === 'http' && $loopback))
        ) {
            return false;
        }
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
        isset($constraints['frameOrigin'])
        && (
            $audienceKind !== 'link'
            || !empty($constraints['requireVerifiedEmail'])
            || isset($constraints['network'])
        )
    ) {
        return false;
    }
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
const STATTIC_AUTHORIZATION_COMPILED_VERSION = 5;

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

// An exclude pattern ending in 'index.html' ALSO compiles to every stripped form:
// the URL lane collapses all trailing index.html segments while the artifact lane keeps
// it, and an exclude naming an index artifact must close the route it serves.
//
// A terminal '**' retains its subtree and adds only exact stripped aliases.
// Includes deliberately do NOT get that variant: granting the stripped route
// would hand out access nobody wrote down. The asymmetry IS the rule, and
// packages/common/src/utils/grants.ts reads exclude as widely and include as
// narrowly.
function _stattic_grant_exclude_pattern_variants(array $segments): array
{
    $collapsed = _stattic_grant_pattern_compiled_segments($segments);
    $variants = [$collapsed];
    while (true) {
        if (array_last($collapsed) === '**') {
            array_pop($collapsed);
        }
        if (array_last($collapsed) !== 'index.html') break;
        do {
            array_pop($collapsed);
            $variants[] = $collapsed;
        } while (array_last($collapsed) === 'index.html');
    }
    return $variants;
}

function _stattic_grant_exclude_segments_match(array $pattern, array $resource): bool
{
    // Stored projections retain the original pattern even when an older
    // compiler emitted only its first index alias.
    foreach (_stattic_grant_exclude_pattern_variants($pattern) as $variant) {
        if (_stattic_grant_segments_match($variant, $resource)) return true;
    }
    return false;
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
    // Which authority references mean the account belongs to the Space, for
    // the Space bar (_stattic_access_session_shows_bar). Read at compile time
    // because the raw Grants and their sources are not kept.
    $belonging = _stattic_grant_index_belonging($grants);
    $hasFrameLinks = false;
    // Per target kind: does a public grant admit ANY path, anonymously, with no
    // constraint at all? That is the only shape where skipping the access code
    // matches running it. Computed here alone, so the overlay's `open` decision
    // (admin/generate.php) reads a flag instead of re-deriving grant semantics,
    // and a grant field this compiler learns tomorrow reaches it automatically.
    $unconditionalTargets = ['live' => false, 'all_versions' => false];
    $spaceClaimed = ($projection['spaceClaimed'] ?? false) === true;
    // The owning team, when the control plane names it. Team-shaped Grants for
    // this team are matched by any member authority the control plane signs;
    // team Grants for any other team are inert.
    $teamId = is_string($projection['teamId'] ?? null) && $projection['teamId'] !== ''
        ? $projection['teamId']
        : null;
    $teamReference = $teamId === null ? null : 'team:' . $teamId;
    $membershipEpoch = (int) ($projection['membershipEpoch'] ?? 0);
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
        if ($kind === 'external' || $kind === 'person' || $kind === 'team') {
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
            'generation' => (int) $grant['generation'],
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
            'frameOrigin' => is_string($constraints['frameOrigin'] ?? null)
                ? $constraints['frameOrigin']
                : null,
            'sharedCacheable' => $reference === null
                && in_array('page.view', $grant['capabilities'], true)
                && $constraints === [],
        ];
        if ($compiled['frameOrigin'] !== null) {
            $hasFrameLinks = true;
        }
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
            // A team Grant is matched by member authorities the control plane
            // signs; its digest carries the membership epoch so a reduction
            // retires every member session without naming any member here.
            // Keep the digest byte-for-byte aligned with grantGenerationDigest
            // in apps/control-plane/src/access/authority-generation.ts.
            $generationSources[$reference][] = [
                'source' => $kind === 'team'
                    ? (string) $grant['id'] . ':' . (string) $grant['generation'] . ':' . $membershipEpoch
                    : (string) $grant['id'] . ':' . (string) $grant['generation'],
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
        'hasFrameLinks' => $hasFrameLinks,
        'unconditionalTargets' => $unconditionalTargets,
        'teamReference' => $teamReference,
        'belonging' => $belonging,
    ];
}

/**
 * The authority references that admit an account as someone who belongs to
 * the Space: a Grant to its team or parent team, an invitation, an email
 * domain or a company sign-in. Not the public Grant, a share link, a password,
 * a machine token or a claim preview. A consent from the Space's own sign-in
 * (`share-consent:<consent>:<Grant>`) counts only when projected from a Grant
 * that is not public. A Grant with a start time (`notBefore`) never counts:
 * this list is compiled once per route sync, so it can't tell when that Grant
 * starts to admit anyone.
 *
 * @return array<string, true>
 */
function _stattic_grant_index_belonging(array $grants): array
{
    $publicGrantIds = [];
    foreach ($grants as $grant) {
        if (is_array($grant) && ($grant['audience']['kind'] ?? null) === 'public') {
            $publicGrantIds[(string) ($grant['id'] ?? '')] = true;
        }
    }
    $belonging = [];
    foreach ($grants as $grant) {
        if (!is_array($grant) || !is_array($grant['audience'] ?? null)) {
            continue;
        }
        $kind = $grant['audience']['kind'] ?? null;
        if (!in_array($kind, ['team', 'person', 'external'], true)
            || ($grant['audience']['issuer'] ?? null) === 'claim-preview'
            || isset($grant['constraints']['notBefore'])) {
            continue;
        }
        $source = is_string($grant['source']['reference'] ?? null) ? $grant['source']['reference'] : '';
        if (preg_match('/\Ashare-consent:[^:]+:(.+)\z/', $source, $match) === 1
            && isset($publicGrantIds[$match[1]])) {
            continue;
        }
        $reference = _stattic_grant_audience_reference($grant['audience']);
        if (is_string($reference)) {
            $belonging[$reference] = true;
        }
    }
    return $belonging;
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
        && is_bool($value['spaceClaimed'] ?? null)
        && (!array_key_exists('teamId', $value) || $value['teamId'] === null || is_string($value['teamId']))
        && (!array_key_exists('membershipEpoch', $value)
            || (is_int($value['membershipEpoch']) && $value['membershipEpoch'] >= 0))
        && (!array_key_exists('endedSessions', $value) || _stattic_ended_session_ids($value['endedSessions']) !== null)
        && (!array_key_exists('endedBefore', $value) || (is_int($value['endedBefore']) && $value['endedBefore'] > 0));
}

// The list as the control plane sends it, or the set a compiled projection
// keeps; null when it is neither.
function _stattic_ended_session_ids(mixed $value): ?array
{
    if (!is_array($value) || count($value) > STATTIC_AUTHORIZATION_ENDED_SESSION_LIMIT) return null;
    $ids = array_is_list($value) ? $value : array_keys(array_filter($value, static fn (mixed $held): bool => $held === true));
    if (count($ids) !== count($value)) return null;
    foreach ($ids as $sid) {
        if (!_stattic_is_sha256_hex($sid)) return null;
    }
    return $ids;
}

// RUNTIME_AUTHORIZATION_ENDED_SESSION_LIMIT in packages/common/src/contracts/runtime-api.ts.
const STATTIC_AUTHORIZATION_ENDED_SESSION_LIMIT = 1024;

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
        'teamId' => is_string($value['teamId'] ?? null) && $value['teamId'] !== '' ? $value['teamId'] : null,
        'membershipEpoch' => (int) ($value['membershipEpoch'] ?? 0),
        // The ended sessions ride in the compiled index, which the overlay
        // carries to the serve path whole (admin/generate.php). A set, so the
        // hot path looks a session up instead of scanning.
        'grantIndex' => [
            ...$index,
            'endedSessions' => array_fill_keys(_stattic_ended_session_ids($value['endedSessions'] ?? []) ?? [], true),
            'endedBefore' => is_int($value['endedBefore'] ?? null) ? $value['endedBefore'] : null,
        ],
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
        && is_bool($index['hasFrameLinks'] ?? null)
        && is_bool($index['unconditionalTargets']['live'] ?? null)
        && is_bool($index['unconditionalTargets']['all_versions'] ?? null)
        && array_key_exists('teamReference', $index)
        && ($index['teamReference'] === null || is_string($index['teamReference']));
}

/**
 * The projection this engine can decide with, upgraded on read.
 *
 * A Space that never republishes keeps the overlay its last publish wrote, so
 * the serve path meets projections compiled by older engines indefinitely.
 * Refusing them is a uniform deny of a Space that was serving fine yesterday,
 * which is why the version bump alone must not be an access decision.
 *
 * A recompile is not available here: the overlay stores the compiled grant
 * index, never the raw Grants it came from, and only the management lane ever
 * sees those. Version 4 does not need them. It added `hasFrameLinks` to the
 * index and `frameOrigin`/`generation` to each compiled Grant — and a version-3
 * index predates the `frameOrigin` constraint entirely, so no Grant in it can
 * carry one and the flag is false by construction. Every version-4 reader of
 * the two per-Grant fields already treats an absent one as null. Deriving the
 * flag here therefore yields exactly what recompiling the same Grants would.
 *
 * Version 5 added `teamReference` to the index and `teamId` /
 * `membershipEpoch` to the envelope. A version-4 index predates team-shaped
 * Grants entirely, so no Grant in it was compiled against a team reference
 * and null is exactly what recompiling the same Grants would yield.
 *
 * Anything older stays refused, as it already was before version 4: those
 * shapes are not reconstructible from what the overlay keeps.
 */
function _stattic_authorization_projection_current(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    if (_stattic_authorization_projection_compiled($value)) {
        return $value;
    }
    if (($value['compiledVersion'] ?? null) === 3 && is_array($value['grantIndex'] ?? null)) {
        $value['compiledVersion'] = 4;
        $value['grantIndex']['hasFrameLinks'] = false;
    }
    if (($value['compiledVersion'] ?? null) === 4 && is_array($value['grantIndex'] ?? null)) {
        $value['compiledVersion'] = STATTIC_AUTHORIZATION_COMPILED_VERSION;
        $value['grantIndex']['teamReference'] = null;
    }
    return _stattic_authorization_projection_compiled($value) ? $value : null;
}

function _stattic_authorization_grant_index(array $projection): ?array
{
    $current = _stattic_authorization_projection_current($projection);
    return $current === null ? null : $current['grantIndex'];
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
        if (!is_string($authority) || isset($seen[$authority])) {
            continue;
        }
        $seen[$authority] = true;
        foreach (
            _stattic_grant_index_bucket_candidates($index['authorities'][$authority] ?? null, $firstSegment) as $grant
        ) {
            $candidates[] = $grant;
        }
        $teamReference = _stattic_member_authority_team_reference($index, $authority);
        if ($teamReference === null || !is_array($index['authorities'][$teamReference] ?? null)) {
            continue;
        }
        foreach (
            _stattic_grant_index_bucket_candidates($index['authorities'][$teamReference], $firstSegment) as $grant
        ) {
            // The compiled reference names the team. The decision must name the
            // member authority that selected it: requireVerifiedEmail is checked
            // against the session's verified member entries, and session touch
            // advances only member: entries. A team reference would fail both.
            $candidates[] = [...$grant, 'reference' => $authority];
        }
    }
    return $candidates;
}

// A member authority is matched by the projection's own team Grants as well as
// by any Grant expanded to that exact member (the pre-epoch shape, kept so a
// new engine serves an old control plane during rollout).
function _stattic_member_authority_team_reference(?array $index, string $authority): ?string
{
    if (!is_array($index) || !str_starts_with($authority, 'member:')) {
        return null;
    }
    return is_string($index['teamReference'] ?? null) ? $index['teamReference'] : null;
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
    }, beforeTenant: true);
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
    ?array &$matchedGrantIds = null,
    // Per authority reference, the most its Grants may give it here
    // (_stattic_identity_authority_capabilities). Absent: what the Grant says.
    array $authorityCapabilities = []
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
                && _stattic_grant_exclude_segments_match($patternSegments, $pathSegments)
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
        $granted = is_array($grant['capabilities'] ?? null) ? $grant['capabilities'] : [];
        if (is_string($reference) && is_array($authorityCapabilities[$reference] ?? null)) {
            $granted = array_values(array_intersect($granted, $authorityCapabilities[$reference]));
            if ($granted === []) {
                continue;
            }
        }
        foreach ($granted as $capability) {
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
    $projection = _stattic_authorization_projection_current($projection);
    if ($projection === null) {
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
        && (
            _stattic_system_view_admits($admission['path'])
            || _stattic_frame_session_admits($admission['path'])
        )
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
    // Past here the request needs someone's access. A box that lost the
    // control plane for over an hour holds access rules nobody can revoke, so
    // every lane that admits through here (pages, proxies, WordPress, media)
    // fails closed until it hears again. A path an unconstrained Public Grant
    // admits keeps serving: it has nothing to revoke.
    if (!($anonymousAdmitted && !empty($anonymous['sharedCacheable']))) {
        require_once __DIR__ . '/../shared/box-sync.php';
        if (_stattic_box_sync_stale(_stattic_access_private_root())) {
            if ($probeOnly) {
                return null;
            }
            require_once __DIR__ . '/../shared/errors.php';
            _stattic_render_runtime_unavailable('box_sync_stale', STATTIC_BOX_SYNC_RETRY_AFTER_SECONDS);
        }
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
        && (
            _stattic_system_view_admits($path)
            || _stattic_frame_session_admits($path)
        )
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
        true,
        authorityCapabilities: _stattic_identity_authority_capabilities($identity)
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

// Whether no platform fence holds this Space and its projection is readable.
// A fenced Space is never shared-cacheable as public, whatever its Grants say.
function _stattic_access_unfenced(array $serving, string $requestHost, string $requestPath): bool
{
    $admission = _stattic_scoped_admission_context($serving, $requestHost, $requestPath);
    return ($admission['error'] ?? null) === null
        && ($admission['projection']['fence'] ?? 'none') === 'none';
}

// Whether an unconstrained Public Grant already reaches this path, e.g. on a
// Space that keeps other paths private. A constrained one (expiry, country,
// user agent, frame origin) admits identity-dependently, so it does not count:
// a link unfurler may not be the visitor it admits.
function _stattic_access_anonymous_admits(array $serving, string $requestHost, string $requestPath): bool
{
    $admission = _stattic_scoped_admission_context($serving, $requestHost, $requestPath);
    if (($admission['error'] ?? null) !== null || ($admission['projection']['fence'] ?? 'none') !== 'none') {
        return false;
    }
    $capabilities = $admission['anonymous']['capabilities'] ?? [];
    return is_array($capabilities)
        && in_array('page.view', $capabilities, true)
        && !empty($admission['anonymous']['sharedCacheable']);
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
    string $originalRequestPath,
    bool $allowCrossOriginPlatformCredential = false
): void {
    $serving = _stattic_page_serving();
    $GLOBALS['SPACEFAST_ACCESS_ENFORCED'] = true;

    _stattic_access_apply_system_view_cookie($serving, $requestHost);
    _stattic_access_apply_frame_session_cookie($serving, $requestHost);

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
    // A verified platform credential owns its identity; browser cookies do not.
    $verifiedPlatform = $allowCrossOriginPlatformCredential
        && _stattic_platform_bearer_token_from_request() !== null
        && _stattic_current_session_identity($serving, $requestHost) !== null;
    if ($protected && !$verifiedPlatform && _stattic_private_cross_host_subresource($requestHost)) {
        header('X-Spacefast-Access-Diagnostic: cross-host-private-subresource', true);
        _stattic_render_scoped_deny($serving);
    }
    if ($protected) {
        _stattic_access_private_cache_flag(true);
    }
}

/**
 * THE WP API door's identity step, and the fourth way a WordPress principal
 * comes into existence (after the content API's assertion, the editor session,
 * and the Frame session).
 *
 * Called only AFTER _stattic_access_enforce_v4 has admitted the request, so it
 * decides nothing about access: enforcement already said the caller may reach
 * this path, and this only names who WordPress attributes the work to and how
 * much of WordPress the request may touch while it runs. Returning null is the
 * normal answer, not a failure — WordPress then answers as nobody, which is
 * exactly its own unauthenticated REST behavior.
 *
 * The capabilities are re-derived here rather than read out of enforcement
 * because enforcement deliberately returns early on a Space whose Public Grant
 * already admits the path, without ever evaluating the caller's own Grants. A
 * public Space must still be able to hand an agent an editor's role.
 */
function _stattic_access_wordpress_principal(
    array $serving,
    string $requestHost,
    string $requestPath
): ?array {
    $identity = _stattic_current_session_identity($serving, $requestHost);
    if (!is_array($identity)) {
        return null;
    }
    $admission = _stattic_scoped_admission_context($serving, $requestHost, $requestPath);
    if (($admission['error'] ?? null) !== null) {
        return null;
    }
    $decision = _stattic_grant_decision(
        $admission['projection'],
        $admission['path'],
        $admission['target'],
        is_array($identity['authorities'] ?? null) ? $identity['authorities'] : [],
        is_array($identity['emailVerifiedAuthorities'] ?? null)
            ? $identity['emailVerifiedAuthorities']
            : [],
        (string) $admission['country'],
        (string) $admission['userAgent'],
        false,
        true,
        authorityCapabilities: _stattic_identity_authority_capabilities($identity)
    );
    $role = _stattic_wordpress_role_for_capabilities($decision['capabilities']);
    if ($role === null) {
        return null;
    }
    $actorId = _stattic_access_machine_actor_id($identity);
    if ($actorId === null) {
        $account = _stattic_access_account_identity(_stattic_access_identity_record($identity) ?? []);
        if ($account === null) return null;
        // A TypedPress app sees a signed-in browser account as itself and as
        // its subscriber, on every hostname, whatever its Grants on this path
        // give: the Space's access decides whether the person may open it, and
        // the app never runs them with an elevated WordPress role. Service
        // actors and the dashboard's editor lane keep their own roles.
        if (in_array($serving['zero_generation'] ?? null, ['v2', 'tp_v1'], true)) {
            $role = 'subscriber';
        }
        $profile = is_array($identity['profile'] ?? null) ? $identity['profile'] : [];
        return [
            'kind' => 'user', 'actor_id' => $account['subject'],
            'issuer' => $account['issuer'], 'subject' => $account['subject'],
            'session_version' => _stattic_session_version($serving),
            'access_generation' => _stattic_projection_generation($serving),
            'expires_at' => is_int($identity['exp'] ?? null) ? $identity['exp'] : 0,
            'wordpress_role' => $role,
            'profile' => [
                ...isset($profile['name']) ? ['display_name' => $profile['name']] : [],
                ...isset($profile['avatar_url']) ? ['avatar_url' => $profile['avatar_url']] : [],
            ],
        ];
    }
    return [
        // An API key is not a person: shared/content-principal.php gives every
        // service actor the issuer `spacefast-service` and the actor id as its
        // subject, so this door converges on the same WordPress user the
        // control plane's own service actor would.
        'kind' => 'service',
        'actor_id' => $actorId,
        'session_version' => _stattic_session_version($serving),
        'access_generation' => _stattic_projection_generation($serving),
        'expires_at' => is_int($identity['exp'] ?? null) ? $identity['exp'] : 0,
        'wordpress_role' => $role,
        'profile' => [],
    ];
}

/** The machine credential behind a session, when one authenticated it. */
function _stattic_access_machine_actor_id(array $identity): ?string
{
    foreach (is_array($identity['authorities'] ?? null) ? $identity['authorities'] : [] as $authority) {
        if (is_string($authority) && str_starts_with($authority, 'machine:')) {
            $id = substr($authority, strlen('machine:'));
            return preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $id) === 1 ? $id : null;
        }
    }
    return null;
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
    while ($canonicalizeIndexAlias && array_last($segments) === 'index.html') {
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
        $cap = _stattic_handoff_capability_cap(is_array($verified['claims'] ?? null) ? $verified['claims'] : []);
        if ($cap === false) {
            return null;
        }
        return [
            'credentialSource' => 'platform-bearer',
            'sub' => $verified['sub'],
            'authorities' => $authorities,
            ...($cap !== null ? ['authorityCapabilities' => array_fill_keys($authorities, $cap)] : []),
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
    _stattic_security_session($serving, $record["spaceId"], $record["sid"], "degraded");
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
    $principalExpired = is_int($claims['principalExpiresAt'] ?? null)
        && $claims['principalExpiresAt'] <= $now;
    if ($principalExpired) {
        $claims['principal'] = STATTIC_SESSION_PRINCIPAL_ANONYMOUS;
        unset($claims['principalExpiresAt'], $claims['identity'], $claims['profile'], $claims['authProvider']);
    }
    // An ended session (or an authority an ended session acquired) is gone
    // now, not when the claim set ages out.
    $unended = _stattic_access_session_unended_entries($claims, $projection);
    $ended = count($unended) !== count($claims['authorities']);
    $claims['authorities'] = $unended;
    if ($unended === []) {
        $privateRoot = _stattic_access_private_root();
        if ($privateRoot !== '') {
            _stattic_access_session_record_delete($privateRoot, $claims['sid']);
        }
        return _stattic_access_session_degrade($serving, $host, $claims, $credential);
    }
    // If ending an account's session left only non-account credential lanes
    // (link, password, machine, claim preview), drop the account identity too
    // so a signed-out browser does not remain "signed in as X, with a link".
    if ($ended && _stattic_access_principal_is_account($claims['principal'] ?? null)) {
        $hasAccountAuthority = false;
        foreach ($unended as $entry) {
            if (preg_match('/\A(?:link|password|machine|claim-preview):/', $entry['reference']) !== 1) {
                $hasAccountAuthority = true;
                break;
            }
        }
        if (!$hasAccountAuthority) {
            $claims['principal'] = STATTIC_SESSION_PRINCIPAL_ANONYMOUS;
            unset($claims['principalExpiresAt'], $claims['identity'], $claims['profile'], $claims['authProvider']);
        }
    }
    $retained = _stattic_access_session_retained_authority_entries($claims['authorities'], $now);
    $stale = $ended || $principalExpired || $claims['exp'] <= $now
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
            min($entry['openedAt'] + STATTIC_ACCESS_SESSION_ABSOLUTE_SECONDS, $entry['expiresAt'] ?? PHP_INT_MAX),
        $authorityEntries
    ));
    $profile = _stattic_access_public_profile($claims['profile'] ?? null);
    return [
        'authorities' => $authorities,
        'emailVerifiedAuthorities' => $emailVerifiedAuthorities,
        'authorityEntries' => $authorityEntries,
        'sessionId' => $claims['sid'],
        'sessionRecord' => $claims,
        ...(in_array($claims['authProvider'] ?? null, ['google', 'gravatar'], true) ? ['authProvider' => $claims['authProvider']] : []),
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
    }, beforeTenant: true);
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
    $teamReference = _stattic_member_authority_team_reference($index, $authority);
    if ($teamReference !== null && is_array($index['generations'][$teamReference] ?? null)) {
        $entries = [...$entries, ...$index['generations'][$teamReference]];
    }
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

// The session version the box holds right now. A request that waited on the
// network (the token exchange) may hold a snapshot from before a sign-out of
// everyone landed; a session tagged with that older version would be refused
// on its first use. Reads the Space pointer and overlay again; falls back to
// the snapshot when either cannot be read.
function _stattic_access_current_session_version(array $serving): int
{
    $snapshot = _stattic_session_version($serving);
    $privateRoot = _stattic_access_private_root();
    $spaceId = _stattic_serving_space_id($serving);
    if ($privateRoot === '' || $spaceId === '') {
        return $snapshot;
    }
    $pointer = _sf_pointer_read('space:' . $spaceId, _stattic_space_root($privateRoot, $spaceId) . '/space.json');
    $overlay = $pointer['kind'] === 'present'
        ? _stattic_v4_overlay($privateRoot, $spaceId, $pointer['value'])
        : null;
    return is_array($overlay) && is_int($overlay['session_ver'] ?? null) && $overlay['session_ver'] >= 0
        ? max($snapshot, $overlay['session_ver'])
        : $snapshot;
}

function _stattic_serving_space_id(array $serving): string
{
    return is_string($serving['space_id'] ?? null) ? $serving['space_id'] : '';
}

function _stattic_visitor_verify_options(array $serving, string $host, ?array $issuers = null, array $extra = []): array
{
    $generation = _stattic_projection_generation($serving);
    return array_merge([
        'now' => _stattic_access_session_now(),
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
    // Sign in with Spacefast's issuer, as the control plane configured it. It
    // can sit on another origin than the authorize URL (split auth host, dev relay).
    $issuer = is_string($raw['issuer'] ?? null) && _stattic_platform_destination_allowed($raw['issuer'])
        ? rtrim($raw['issuer'], '/')
        : null;
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
            'commentsMentionableUrl',
            'zeroRealtimeTicketUrl',
            'sellCheckoutUrl',
            'sellProductUrl',
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
    $terms = is_array($raw['previewTerms'] ?? null) ? $raw['previewTerms'] : null;
    $previewTerms = is_array($terms)
        && is_string($terms['url'] ?? null) && _stattic_platform_destination_allowed($terms['url'])
        && is_string($terms['version'] ?? null) && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $terms['version']) === 1
        ? ['url' => $terms['url'], 'version' => $terms['version']]
        : null;
    return $memo[$memoKey] = [
        'displayName' => $displayName !== '' ? substr($displayName, 0, 200) : null,
        'previewTerms' => $previewTerms,
        'accountUrl' => $accountUrl,
        'issuer' => $issuer,
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
    $projection = _stattic_authorization_projection_current($serving['authorization'] ?? null);
    if ($projection === null) {
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
        'serving' => $serving,
        'descriptor' => $descriptor,
        'password' => $passwordGrant && $exchange !== null,
        'account' => $claimed ? $descriptor['accountUrl'] : null,
        'request' => $claimed && $exchange !== null,
        'silent' => $identityBound,
        'exchange' => $exchange,
    ];
    if (
        !$lanes['password']
        && $lanes['account'] === null
        && !$lanes['request']
    ) {
        return null;
    }
    return $lanes;
}

const STATTIC_ACCESS_STATUS_CODES = [
    'checked', 'no-grant', 'invalid-password', 'email-sent', 'email-failed',
    'request-sent', 'request-confirm', 'request-pending', 'request-failed', 'link-expired', 'link-revoked',
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

function _stattic_access_browser_state_cookie_prefix(): string
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1'
        ? STATTIC_ACCESS_BROWSER_STATE_DEV_COOKIE_PREFIX
        : STATTIC_ACCESS_BROWSER_STATE_COOKIE_PREFIX;
}

function _stattic_access_browser_state_cookie_name(string $state): string
{
    return _stattic_access_browser_state_cookie_prefix() . substr($state, 0, 16);
}

function _stattic_access_browser_state_from_request(?string $expected = null): string
{
    if ($expected !== null) {
        $value = $_COOKIE[_stattic_access_browser_state_cookie_name($expected)] ?? '';
        return is_string($value) && hash_equals($expected, $value) ? $value : '';
    }
    $prefix = _stattic_access_browser_state_cookie_prefix();
    foreach ($_COOKIE as $name => $value) {
        if (
            is_string($name)
            && is_string($value)
            && str_starts_with($name, $prefix)
            && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1
            && hash_equals($name, _stattic_access_browser_state_cookie_name($value))
        ) {
            return $value;
        }
    }
    return '';
}

function _stattic_returning_account_cookie_name(): string
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1'
        ? STATTIC_RETURNING_ACCOUNT_DEV_COOKIE
        : STATTIC_RETURNING_ACCOUNT_COOKIE;
}

function _stattic_access_remember_returning_account(): void
{
    // It says something about who is here, so the response that writes it is
    // as private as one that writes the session.
    _stattic_identity_cookie_mutated(true);
    _stattic_set_cookie(
        _stattic_returning_account_cookie_name(),
        '1',
        STATTIC_RETURNING_ACCOUNT_SECONDS,
        false,
        true
    );
}

// Only a browser that holds the marker hears about it: everyone else's
// response stays exactly what it was.
function _stattic_access_forget_returning_account(): void
{
    $name = _stattic_returning_account_cookie_name();
    if (isset($_COOKIE[$name])) {
        _stattic_clear_cookie($name);
    }
}

// Each nonce gets its own short-lived cookie name, so concurrent first-time
// starts cannot overwrite each other before their callbacks return. Sequential
// starts reuse an existing nonce and avoid growing the cookie jar.
function _stattic_access_browser_state_begin(): string
{
    static $state = null;
    if (is_string($state)) {
        return $state;
    }
    $state = _stattic_access_browser_state_from_request();
    if ($state !== '') {
        return $state;
    }
    $state = bin2hex(random_bytes(32));
    _stattic_set_cookie(
        _stattic_access_browser_state_cookie_name($state),
        $state,
        STATTIC_ACCESS_BROWSER_STATE_SECONDS
    );
    return $state;
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
    foreach (STATTIC_ACCESS_FLOW_PARAMS as $flowParam) {
        unset($params[$flowParam]);
    }
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

// The control plane's no-grant bounce names the account it found: a visitor
// without authority gets no runtime session, so this host cannot know who it is.
// The hint has its own purpose, is never redeemable, and opens nothing. Its one
// power beyond display: a verified one marks this browser's invite request for
// that exact address as from a verified address.
const STATTIC_ACCESS_ACCOUNT_HINT_PARAM = 'sf_access_as';
const STATTIC_ACCESS_ACCOUNT_HINT_PURPOSE = 'account-hint';
// The access flow's own params. None of them is ever part of a page a flow
// returns to, and `__` is a durable share-link secret.
const STATTIC_ACCESS_FLOW_PARAMS = [
    'sf_access',
    'sf_access_return',
    STATTIC_ACCESS_ACCOUNT_HINT_PARAM,
    STATTIC_ACCESS_QUERY_TOKEN_PARAM,
];

// A return path as the browser sent it, minus the flow's own params. Every other
// pair keeps its exact spelling, and a return that leaves this origin never
// carries a share-link secret.
function _stattic_access_return_without_flow_params(string $returnPath): string
{
    $fragmentAt = strpos($returnPath, '#');
    $fragment = $fragmentAt === false ? '' : substr($returnPath, $fragmentAt);
    $withoutFragment = $fragmentAt === false ? $returnPath : substr($returnPath, 0, $fragmentAt);
    $queryAt = strpos($withoutFragment, '?');
    if ($queryAt === false) {
        return $returnPath;
    }
    $kept = [];
    foreach (_stattic_runtime_query_pairs(substr($withoutFragment, $queryAt + 1)) as [$name, , $rawPart]) {
        if ($rawPart !== '' && !in_array($name, STATTIC_ACCESS_FLOW_PARAMS, true)) {
            $kept[] = $rawPart;
        }
    }
    return substr($withoutFragment, 0, $queryAt)
        . ($kept === [] ? '' : '?' . implode('&', $kept))
        . $fragment;
}

// The token's browserState claim when this browser holds the matching state
// cookie, else ''.
function _stattic_access_claims_browser_state(array $claims): string
{
    $tokenState = $claims['browserState'] ?? null;
    if (!is_string($tokenState) || preg_match('/\A[a-f0-9]{64}\z/D', $tokenState) !== 1) {
        return '';
    }
    return _stattic_access_browser_state_from_request($tokenState);
}

// ['email' => ..., 'token' => ..., 'browserState' => ...] for a hint that
// verifies here, else null.
function _stattic_access_account_hint(array $serving, string $host, mixed $token): ?array
{
    if (!is_string($token) || $token === '' || strlen($token) > 4096) {
        return null;
    }
    $verified = _stattic_visitor_verify(
        $token,
        _stattic_visitor_verify_options($serving, $host, null, ['requireJti' => false])
    );
    $claims = is_array($verified) && is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    if (($claims['purpose'] ?? null) !== STATTIC_ACCESS_ACCOUNT_HINT_PURPOSE) {
        return null;
    }
    // Bound to the browser that started the sign-in, so a forwarded URL never
    // shows one person's address in someone else's browser.
    $browserState = _stattic_access_claims_browser_state($claims);
    if ($browserState === '') {
        return null;
    }
    $email = is_string($claims['email'] ?? null) ? trim($claims['email']) : '';
    if ($email === '' || strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return ['email' => $email, 'token' => $token, 'browserState' => $browserState];
}

function _stattic_access_status_fragment(string $status, ?string $accountEmail = null): string
{
    if ($status === 'no-grant' && $accountEmail !== null) {
        return '<p class="sf-copy sf-access-status" role="status">You&#039;re signed in as <strong>'
            . _stattic_html_escape($accountEmail)
            . '</strong>, but this space hasn&#039;t let you in yet. Ask for an invite below.</p>';
    }
    // The visitor still has to act on an email link, so these lead with the
    // action instead of reading like a passing notice. Only an older control
    // plane mails a request confirmation (it answers the request lane
    // `pending`); its link lasts 30 minutes.
    $actions = [
        'email-sent' => ['Check your email.', 'Use the confirmation link we sent to finish opening this page.'],
        'request-confirm' => [
            'Check your email to finish your request.',
            'Open the confirmation link we sent. It expires in 30 minutes.',
        ],
    ];
    if (isset($actions[$status])) {
        return '<p class="sf-copy sf-access-status sf-access-status-action" role="status"><strong>'
            . _stattic_html_escape($actions[$status][0]) . '</strong> '
            . _stattic_html_escape($actions[$status][1]) . '</p>';
    }
    $messages = [
        'no-grant' => "You're signed in, but this space hasn't let you in yet. Ask for an invite below.",
        'email-failed' => "Couldn't send that confirmation. Enter the password and try again.",
        // The request is with the owner and nothing is left to confirm. Only
        // an approval is mailed: a denied unverified address hears nothing.
        'request-sent' => "Request sent. We'll email you if the owner lets you in.",
        // An older control plane, with this account's hint: it skips the
        // confirmation unless the account's address is unverified.
        'request-pending' => "Request sent. If we emailed you a confirmation link, open it. We'll email you if the owner lets you in.",
        'request-failed' => "That request didn't go through. Try again in a moment.",
        'link-expired' => 'That link has expired. Ask whoever sent it for a fresh one — or use another way in.',
        'link-revoked' => 'That link was turned off. Use another way in, or ask for a new one.',
        'link-invalid' => "That link doesn't work anymore. Ask whoever sent it for a new one, or use another way in.",
        'open-used' => 'That open link was already used. Use another way in.',
        'session-expired' => 'Your access expired. Continue to check it again.',
        'rate-limited' => 'Too many tries. Give it a minute.',
        'exchange-unavailable' => "Couldn't reach Spacefast to check that. Try again in a moment.",
    ];
    $message = $messages[$status] ?? '';
    if ($message === '') {
        return '';
    }
    return '<p class="sf-copy sf-access-status" role="status">' . _stattic_html_escape($message) . '</p>';
}

function _stattic_access_request_form(
    string $returnPath,
    string $summary,
    bool $open = false,
    ?array $accountHint = null
): string {
    // Custom and older published access templates carry no icon CSS; without
    // intrinsic dimensions the SVG renders at the 300x150 replaced-element default.
    $lock = '<svg width="16" height="16" viewBox="' . STATTIC_PAGE_ICON_VIEW_BOX . '" aria-hidden="true" focusable="false"><path d="'
        . STATTIC_PAGE_ICON_LOCK_CLOSED . '"></path></svg>';
    // Opened for the visitor (a refused account), the form is the page's main
    // action, so it skips the disclosure: a summary would only read as a second
    // button that collapses it.
    return ($open
            ? '<div class="sf-access-request">'
            : '<details class="sf-access-request"><summary>' . $lock . _stattic_html_escape($summary) . '</summary>')
        . '<form method="post" action="' . _stattic_html_escape(STATTIC_ACCESS_REQUEST_PATH) . '">'
        . '<input type="hidden" name="return" value="' . _stattic_html_escape($returnPath) . '">'
        // A signed-in account is named in the status line; its address and
        // the signed hint ride along so the owner sees the address verified.
        . ($accountHint !== null
            ? '<input type="hidden" name="email" value="' . _stattic_html_escape($accountHint['email']) . '">'
                . '<input type="hidden" name="account_hint" value="' . _stattic_html_escape($accountHint['token']) . '">'
            : '<label class="sf-access-field-label" for="sf-access-request-email">Email address</label>'
                . '<input id="sf-access-request-email" class="sf-input" type="email" name="email" required autocomplete="email" placeholder="you@example.com">')
        . '<label class="sf-access-field-label" for="sf-access-request-message">Note to the owner (optional)</label>'
        . '<textarea id="sf-access-request-message" class="sf-input" name="message" maxlength="280" placeholder="A little context goes a long way."></textarea>'
        . '<button class="sf-button sf-button-accent" type="submit">Request access' . _stattic_access_arrow() . '</button>'
        . '<p class="sf-access-hint">The owner will review your request.</p>'
        . '</form>'
        // Another address (a work one, say) drops the hint and reaches the owner unverified.
        . ($accountHint !== null
            ? '<p class="sf-sub"><a href="'
                . _stattic_html_escape(_stattic_access_url_with_params($returnPath, ['sf_access' => 'no-grant']))
                . '">Use a different email</a></p>'
            : '')
        . ($open ? '</div>' : '</details>');
}

// A refused account needs a way to become a different one, same tab (an
// embedded browser can lose both windows to a popup), keeping the refused
// account signed in. With Sign in with Spacefast that is this Space's own
// sign-in asking which account (`prompt=select_account`), so the door shows
// its account chooser. The older account lane takes its add-account sign-in.
function _stattic_access_switch_account_button(string $returnPath, bool $shareOAuth): string
{
    $switchHref = _stattic_access_url_with_params(
        $shareOAuth ? STATTIC_SHARE_OAUTH_START_PATH : STATTIC_ACCESS_ACCOUNT_START_PATH,
        $shareOAuth
            ? ['return' => $returnPath, 'prompt' => 'select_account']
            : ['return' => $returnPath, 'addAccount' => '1']
    );
    return '<a class="sf-button sf-button-quiet sf-access-switch" href="'
        . _stattic_html_escape($switchHref) . '">Use another account</a>';
}

// The page draws one arrow glyph, pointing forward. Sized and turned inline so
// older templates without the current CSS still render it small and the right
// way round.
function _stattic_access_arrow(): string
{
    return '<svg width="18" height="18" style="transform:scaleX(-1)" viewBox="' . STATTIC_PAGE_ICON_VIEW_BOX
        . '" aria-hidden="true" focusable="false"><path d="' . STATTIC_PAGE_ICON_ARROW_LEFT . '"></path></svg>';
}

// Where that account's settings and other accounts live, for the visitor who
// would rather sort it out there.
function _stattic_access_dashboard_fragment(): string
{
    $dashboard = _stattic_dashboard_origin();
    if ($dashboard === '') {
        return '';
    }
    return '<a class="sf-button sf-button-quiet sf-access-dashboard" href="' . _stattic_html_escape($dashboard . '/')
        . '">Open dashboard' . _stattic_access_arrow() . '</a>';
}

function _stattic_access_lanes_fragment(
    array $lanes,
    string $host,
    string $returnPath,
    string $status,
    ?string $emailContinuation,
    ?string $requestedScope,
    ?array $accountHint = null
): string
{
    $html = '<div class="sf-access-lanes">';
    $buttons = '';
    $secondary = '';
    $dashboardLink = '';
    if (is_string($lanes['account'])) {
        $shareOAuth = _stattic_share_oauth_is_account_url($lanes['account']);
        $accountHref = _stattic_access_url_with_params($shareOAuth
            ? STATTIC_SHARE_OAUTH_START_PATH
            : STATTIC_ACCESS_ACCOUNT_START_PATH, [
            'return' => $returnPath,
        ]);
        if ($status === 'no-grant') {
            // The visitor is already signed in with an account this space refused,
            // so "Sign in" only re-ran the same check and looked dead. The check is
            // still worth offering by its real name, same tab, for when the owner
            // grants access later: sf_access stays in the address bar, so a reload
            // alone would keep showing this page. Neither re-check nor switch can
            // let this account in today, so both sit quietly under the invite.
            $secondary = '<div class="sf-actions sf-access-more">'
                . _stattic_access_switch_account_button($returnPath, $shareOAuth)
                . '<a class="sf-button sf-button-quiet sf-access-account" href="'
                . _stattic_html_escape($accountHref) . '">Check access again</a>'
                . '</div>';
            $dashboardLink = _stattic_access_dashboard_fragment();
        } else {
            // Sign in is a top-level redirect; a page is shown only when the
            // visitor has something to decide, and the sign-in host decides that.
            // Older published access templates carry no helper CSS, so the note
            // styles itself instead of falling back to a full paragraph.
            $buttons .= '<a class="sf-button sf-access-account"'
                . ' aria-describedby="sf-account-help" href="'
                . _stattic_html_escape($accountHref) . '">Continue with '
                . _stattic_html_escape(_stattic_brand_value('name')) . '</a>'
                . '<p class="sf-account-help" id="sf-account-help" style="margin:0 0 8px;color:var(--sf-dim);font-size:13px">'
                . 'We&#039;ll use your ' . _stattic_html_escape(_stattic_brand_value('name'))
                . ' account to check whether you have access to this site.</p>';
        }
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
    } elseif ($lanes['password'] && ($csrf = _stattic_unlock_csrf_issue($lanes['serving'], $host)) !== null) {
        if ($buttons !== '') {
            $html .= '<div class="sf-access-or" aria-hidden="true">or enter password</div>';
        }
        // The canonical unlock lane, with its CSRF value; a failure lands on
        // the unlock card for this page.
        $html .= '<form class="sf-access-password" method="post" action="'
            . _stattic_html_escape(STATTIC_ACCESS_UNLOCK_PATH) . '">'
            . '<input type="hidden" name="return" value="' . _stattic_html_escape($returnPath) . '">'
            . '<input type="hidden" name="csrf" value="' . _stattic_html_escape($csrf) . '">'
            // The label is visually hidden rather than dropped: the field is
            // drawn with a placeholder and a key glyph, but it still has to be
            // named for anything that is not looking at it.
            . '<label class="sf-label" for="sf-access-password-input">Password</label>'
            . '<div class="sf-access-password-row">'
            . '<div class="sf-field">'
            // Intrinsic size for templates without the icon CSS (see the request form).
            . '<svg class="sf-field-icon" width="14" height="14" viewBox="' . STATTIC_PAGE_ICON_VIEW_BOX . '" aria-hidden="true" focusable="false"><path d="'
            . STATTIC_PAGE_ICON_KEY . '"></path></svg>'
            . '<input id="sf-access-password-input" class="sf-input" type="password" name="password"'
            . ' autocomplete="current-password" required maxlength="1024" placeholder="Enter password"'
            . ($status === 'invalid-password' ? ' aria-invalid="true" autofocus' : '')
            . '>'
            . '</div>'
            . '<button class="sf-button" type="submit">Continue</button>'
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
        $justRequested = in_array($status, ['request-sent', 'request-pending', 'request-confirm'], true);
        $marked = $status !== 'request-failed'
            && $markedScope !== null
            && $currentScope !== null
            && _stattic_scope_contains($markedScope, $currentScope);
        // A refused account's page asks for an invite, so it keeps the open
        // form: the marker cannot tell a waiting request from a denied one.
        $requested = $justRequested || ($marked && $status !== 'no-grant');
        if ($requested) {
            if (!$justRequested) {
                $scope = $markedScope ?? ($currentScope ?? '/');
                $html .= '<p class="sf-copy sf-access-requested">Access requested for '
                    . _stattic_html_escape($host . ($scope === '/' ? '' : $scope))
                    . '. We\'ll email you if the owner lets you in.</p>';
            }
            // The marker is only a browser hint, so keep a way back into the
            // flow after a denial or expiry.
            if (!$justRequested) {
                $html .= _stattic_access_request_form($returnPath, 'Request again');
            }
        } else {
            // The open form has no summary to set it apart from a lane above it.
            if ($status === 'no-grant' && ($buttons !== '' || $lanes['password'])) {
                $html .= '<div class="sf-access-or" aria-hidden="true">or</div>';
            }
            if ($marked && $status === 'no-grant') {
                $html .= '<p class="sf-copy sf-access-requested">You&#039;ve asked from this browser before.'
                    . ' Asking again updates your request or starts a new one.</p>';
            }
            $html .= _stattic_access_request_form(
                $returnPath,
                'Request access',
                $status === 'no-grant',
                $accountHint
            );
        }
    }
    $html .= $secondary . $dashboardLink . '</div>';
    if ($lanes['password']) {
        // External and optional by contract: script-src 'self' enables the
        // enhancements; stricter policies keep native links and forms usable.
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
    $projection = _stattic_authorization_projection_current($serving['authorization'] ?? null);
    if (
        $projection === null
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
        'message' => 'This Space is private.',
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
    _stattic_access_render_unfurl_card_if_unfurler($serving, $requestHost);
    _stattic_access_clear_public_site_data($serving);
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
    // Only a visitor who holds nothing is probed. Someone who came in with a
    // share link or a password stays a guest here until they choose to sign
    // in: a silent probe would turn a signed-in Spacefast user into their
    // account without a click.
    $principal = _stattic_access_identity_principal($identity);
    $identityCheckedAt = _stattic_access_identity_checked_at($identity);
    if (
        $status === ''
        && $emailContinuation === null
        && ($options['allowRedirect'] ?? true) === true
        && _stattic_request_is_document_navigation()
        && !_stattic_access_principal_is_account($principal)
        && !_stattic_access_identity_holds_credential($identity)
        && (
            $identityCheckedAt === null
            || _stattic_access_session_now() - $identityCheckedAt
                >= STATTIC_ACCESS_IDENTITY_CHECK_SECONDS
        )
        && _stattic_platform_bearer_token_from_request() === null
    ) {
        $redirect = null;
        if ($lanes['silent'] && _stattic_share_oauth_is_account_url($lanes['account'])) {
            // Same rule as below: the probe is recorded before the browser leaves.
            _stattic_access_record_identity_check($serving, $requestHost, $identity);
            _stattic_share_oauth_begin($serving, $host, $returnPath, true, $expired);
        }
        if ($lanes['silent'] && is_string($lanes['account'])) {
            $redirectParams = [
                'silent' => '1',
                'host' => $host,
                'return' => $returnPath,
                'browserState' => _stattic_access_browser_state_begin(),
            ];
            // A silent recovery with no upstream session must still preserve
            // the expiry diagnosis instead of the generic signed-out page.
            if ($expired) {
                $redirectParams['fallback'] = 'session-expired';
            }
            $redirect = _stattic_access_url_with_params($lanes['account'], $redirectParams);
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
    $accountHint = $status === 'no-grant'
        ? _stattic_access_account_hint($serving, $host, $_GET[STATTIC_ACCESS_ACCOUNT_HINT_PARAM] ?? null)
        : null;
    if ($accountHint !== null) {
        // The state cookie may date from an earlier gate render; the invite
        // request re-checks it, so keep it alive as long as the page can be sent.
        _stattic_set_cookie(
            _stattic_access_browser_state_cookie_name($accountHint['browserState']),
            $accountHint['browserState'],
            STATTIC_ACCESS_BROWSER_STATE_SECONDS
        );
    }
    $statusFragment = _stattic_access_status_fragment($status, $accountHint['email'] ?? null);
    $lanesFragment = _stattic_access_lanes_fragment(
        $lanes,
        $host,
        $returnPath,
        $status,
        $emailContinuation,
        _stattic_access_identity_requested_path($identity),
        $accountHint
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

function _stattic_access_exchange_post(string $url, array $fields, array $headers, ?string $jsonBody = null): ?array
{
    // Lazy: only the exchange lanes leave this host, and every other access
    // decision is answered from the projection on disk.
    require_once __DIR__ . '/../shared/http.php';

    // Carry one bounded correlation value across the exchange seam. Both
    // journals hash it under the same domain; neither retains the header.
    $headers = array_values(array_filter($headers, static fn (string $header): bool => stripos($header, 'x-request-id:') !== 0));
    $headers[] = 'X-Request-Id: ' . _stattic_security_request_correlation();

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
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $result = _stattic_http_request([
        'url' => $requestUrl,
        'method' => 'POST',
        'body' => $jsonBody ?? http_build_query($fields),
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
        // old-engine-compat: S13 older Space runtime credentials. An older
        // control plane reads only this; a newer one checks the Space token
        // below first and refuses this once it has registered the box's key.
        'Spacefast-Runtime-Exchange: ' . $exchange['credential'],
    ];
    $serving = is_array($context['serving'] ?? null) ? $context['serving'] : null;
    if ($serving !== null) {
        require_once __DIR__ . '/../shared/space-identity.php';
        $authorization = _stattic_space_identity_authorization($serving);
        if ($authorization !== null) {
            $headers[] = $authorization;
        }
    }
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
    if (_stattic_is_anonymous_id($context['browser'] ?? null)) {
        // The box's own key once it has one. old-engine-compat: S19 until
        // then the exchange credential, exactly as before.
        $own = $serving['authorization']['sessionKey'] ?? null;
        $browserKey = _stattic_space_session_key_valid($own)
            ? hash_hmac('sha256', 'visitor-browser', $own)
            : $exchange['credential'];
        $headers[] = 'Spacefast-Visitor-Browser: '
            . _stattic_access_browser_header_value($context['browser'], $browserKey);
    }
    return $headers;
}

// The exchange meters credential failures per browser, because the IP this
// runtime sees is the provider's proxy and is shared by every visitor. The
// browser is named by the pseudonym its visitor session already carries; it
// authorizes nothing.
function _stattic_access_browser_pseudonym(array $serving, string $requestHost): ?string
{
    $identity = _stattic_visitor_cookie_from_request() !== ''
        ? _stattic_current_session_identity($serving, $requestHost)
        : null;
    return _stattic_collab_anonymous_id(_stattic_access_identity_record($identity) ?? []);
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
    echo '(function(){'
        . 'var password=document.getElementById("sf-access-password-input");'
        . 'if(password){var trimButton=document.createElement("button");'
        . 'trimButton.type="button";trimButton.className="sf-button sf-button-quiet";'
        . 'trimButton.textContent="Remove surrounding whitespace";'
        . 'function updateTrim(){if(password.value===password.value.trim()){trimButton.remove();}'
        . 'else{password.form.appendChild(trimButton);}}updateTrim();'
        . 'password.addEventListener("input",updateTrim);'
        . 'trimButton.addEventListener("click",function(){'
        . 'var trimmed=password.value.trim();password.focus();password.select();'
        . 'document.execCommand("insertText",false,trimmed);updateTrim();'
        . '});}'
        . '})();';
    exit;
}

function _stattic_access_handle_account_start(array $serving, string $requestHost): void
{
    $method = _stattic_runtime_request_method();
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        _stattic_method_not_allowed('GET, HEAD');
    }
    // The return leaves this origin, so it leaves without the flow's own params:
    // a page opened in place by a share link carries its secret in `__`.
    $returnPath = _stattic_access_return_without_flow_params(_stattic_safe_return_path(
        is_string($_GET['return'] ?? null) ? (string) $_GET['return'] : '/'
    ) ?? '/');
    // A silent start is a returning account renewing itself with the Space's
    // own sign-in (`prompt=none`), and it spends the marker that asked for it:
    // only an account sign-in at the callback writes it again, so a renewal
    // that comes back without one never repeats.
    $silent = ($_GET['silent'] ?? null) === '1';
    $descriptor = _stattic_access_page_descriptor($serving);
    $accountUrl = is_array($descriptor) && is_string($descriptor['accountUrl'] ?? null)
        ? $descriptor['accountUrl']
        : '';
    if ($silent) {
        _stattic_access_forget_returning_account();
        // Nobody asked to see this trip, so it never dead-ends, and it never
        // leaves for the sign-in host on behalf of a session that is already an
        // account: whatever that account was refused here, renewing it again
        // will not change.
        $identity = _stattic_visitor_cookie_from_request() !== ''
            ? _stattic_current_session_identity($serving, $requestHost)
            : null;
        if (
            _stattic_share_oauth_url($serving) === null
            || _stattic_access_principal_is_account(_stattic_access_identity_principal($identity))
        ) {
            _stattic_access_redirect($returnPath, 303);
        }
    }
    if ($accountUrl === '') {
        _stattic_render_access_route_not_found();
    }
    if (_stattic_share_oauth_url($serving) !== null) {
        _stattic_share_oauth_begin($serving, _stattic_canonicalize_host($requestHost), $returnPath, $silent);
    }
    $redirect = _stattic_access_url_with_params($accountUrl, [
        'host' => _stattic_canonicalize_host($requestHost),
        'return' => $returnPath,
        'browserState' => _stattic_access_browser_state_begin(),
        // "Use another account": sign in as someone else, not re-check this one.
        // The bar's Switch Account asks with `prompt=select_account`, also on a
        // page still open after Sign in with Spacefast was switched off.
        ...((($_GET['addAccount'] ?? null) === '1' || ($_GET['prompt'] ?? null) === 'select_account')
            ? ['addAccount' => '1'] : []),
    ]);
    _stattic_access_redirect($redirect, 302, STATTIC_CACHE_CONTROL_NO_STORE, true);
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

// `$cardPath` is the page that posted: `/__spacefast/unlock` bounces failures
// back to its own card; the older form bounces them to the page it guarded.
function _stattic_access_handle_password(
    array $serving,
    string $requestHost,
    string $privateRoot,
    ?string $cardPath = null
): void {
    [$lanes, $returnPath] = _stattic_access_post_lane_begin(
        $serving,
        $requestHost,
        $privateRoot,
        'password'
    );
    $bounce = $cardPath === null
        ? $returnPath
        : $cardPath . '?return=' . rawurlencode($returnPath);
    $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
    if ($password === '' || strlen($password) > 1024) {
        _stattic_access_gate_after_post($serving, $requestHost, $bounce, 'invalid-password');
    }
    $host = _stattic_canonicalize_host($requestHost);
    // A first attempt names its browser too. The name is kept only if the
    // attempt fails, so a correct password never writes a second session cookie.
    $presentedBrowser = _stattic_access_browser_pseudonym($serving, $requestHost);
    $browser = $presentedBrowser ?? _stattic_collab_mint_anonymous_id();
    $context = [
        ..._stattic_access_context($serving, $requestHost, '/'),
        'browser' => $browser,
    ];
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
        _stattic_access_gate_after_post($serving, $requestHost, $bounce, 'exchange-unavailable');
    }
    $body = $result['body'];
    $fields = is_array($body) && is_array($body['fields'] ?? null) ? $body['fields'] : null;
    $token = is_array($fields) && is_string($fields['token'] ?? null) ? $fields['token'] : '';
    if ($result['status'] === 200 && $token !== '') {
        // One way in: the browser takes the 60-second, single-use handoff to
        // `/__spacefast/in/`, which sets the session and lands on the clean
        // path. The token rides a same-origin redirect with no Referer and is
        // spent on its first use.
        _stattic_access_redirect(
            STATTIC_ACCESS_IN_PREFIX . rawurlencode($token)
                . '?return=' . rawurlencode($returnPath),
            303,
            STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
            true
        );
    }
    if ($presentedBrowser === null) {
        _stattic_anonymous_session_write($serving, $requestHost, ['anonymousId' => $browser]);
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
    _stattic_access_gate_after_post($serving, $requestHost, $bounce, $status);
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
    // A verified hint marks the address verified for the owner, so only
    // forward one this browser was handed, for exactly the posted address.
    $host = _stattic_canonicalize_host($requestHost);
    $postedHint = _stattic_access_account_hint($serving, $host, $_POST['account_hint'] ?? null);
    $accountHint = $postedHint !== null && $postedHint['email'] === $email ? $postedHint['token'] : '';
    // The exchange meters requests per browser, never by the address this
    // runtime sees (the provider's proxy). A first request names its browser,
    // and the session below keeps the name for the next one.
    $presentedBrowser = _stattic_access_browser_pseudonym($serving, $requestHost);
    $browser = $presentedBrowser ?? _stattic_collab_mint_anonymous_id();
    $context = [
        ..._stattic_access_context($serving, $requestHost, '/'),
        'browser' => $browser,
    ];
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
            $message !== '' ? ['message' => $message] : [],
            $accountHint !== '' ? ['accountHint' => $accountHint] : []
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
    _stattic_access_session_remember(
        $serving,
        $requestHost,
        _stattic_current_session_identity($serving, $requestHost),
        [
            'accessRequestedPath' => $requestedScope,
            ...($presentedBrowser === null ? ['anonymousId' => $browser] : []),
        ]
    );
    // The control plane says whether anything is left to confirm: `sent` means
    // the request is with the owner. An older one answers `pending` and mails
    // an unhinted visitor a confirmation link first (control planes before
    // #5288, such as prod/2026.11.13-1321). old-engine-compat: delete the
    // `pending` reading and the `request-confirm` status once no control-plane
    // image a box can reach, including a rollback target, has the
    // `request_verify` handoff flow.
    $sent = is_array($result['body'] ?? null)
        && (($result['body']['data']['status'] ?? null) === 'sent');
    _stattic_access_gate_after_post(
        $serving,
        $requestHost,
        $returnPath,
        $sent ? 'request-sent' : ($accountHint === '' ? 'request-confirm' : 'request-pending')
    );
}

function _stattic_callback_authorities(array $verified): array
{
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    // A capped handoff's authorities are its `spaceOrigin` ones, never the
    // consent authority it names for older engines.
    $named = _stattic_handoff_capability_cap($claims) === null
        ? ($claims['authorities'] ?? null)
        : ($claims['spaceOrigin']['authorities'] ?? null);
    $authorities = [];
    foreach (is_array($named) ? $named : [] as $authority) {
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
    ?int $now = null,
    ?int $expiresAt = null,
    ?array $capabilityCap = null,
    ?string $origin = null
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
        $previous = null;
        if ($existing !== false) {
            $previous = $ordered[(int) $existing];
            array_splice($ordered, (int) $existing, 1);
        }
        // Never allow an uncapped handoff to strip an existing cap for the same
        // authority; if both carry a cap, keep their intersection.
        $previousCap = is_array($previous) && is_array($previous['capabilities'] ?? null)
            ? $previous['capabilities']
            : null;
        if ($previousCap !== null && $capabilityCap !== null) {
            $effectiveCap = array_values(array_intersect($previousCap, $capabilityCap));
        } elseif ($previousCap !== null) {
            $effectiveCap = $previousCap;
        } else {
            $effectiveCap = $capabilityCap;
        }
        $effectiveOrigin = $origin;
        if ($effectiveOrigin === null && is_array($previous) && is_string($previous['origin'] ?? null)) {
            $effectiveOrigin = $previous['origin'];
        }
        // A callback is fresh proof for the incoming reference only; retained
        // entries keep their own absolute/idle clocks.
        $ordered[] = [
            'reference' => $authority,
            'generation' => $generation,
            'emailVerifiedUntil' => $emailVerified ? $openedAt + 3600 : null,
            'openedAt' => $openedAt,
            'lastSeenAt' => $openedAt,
            ...($expiresAt !== null ? ['expiresAt' => $expiresAt] : []),
            ...($effectiveCap !== null ? ['capabilities' => $effectiveCap] : []),
            ...($effectiveCap !== null && $effectiveOrigin !== null ? ['origin' => $effectiveOrigin] : []),
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

// Verify (host-bound, ≤60s old), attach or replace the browser's authorities, then
// verify AGAIN to consume the single-use jti before writing the record.
// $handoffPrincipal receives the principal the handoff itself names, never one
// the session inherited from the browser, so only a sign-in that proved an
// account counts as one.
function _stattic_access_consume_handoff_token(
    array $serving,
    string $host,
    string $token,
    ?array &$storedRecord = null,
    bool $replaceSession = false,
    ?string $expectedNonce = null,
    ?string &$handoffPrincipal = null
): ?string {
    $handoffPrincipal = null;
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
    if (array_key_exists('identity', $claims) && _stattic_access_account_identity($claims) === null) return null;
    if (array_key_exists('browserState', $claims) && _stattic_access_claims_browser_state($claims) === '') {
        return null;
    }
    // A sign-in this browser started names its own nonce; a handoff for any
    // other authorization request is refused before its jti is spent.
    if ($expectedNonce !== null && (!is_string($claims['nonce'] ?? null) || !hash_equals($expectedNonce, $claims['nonce']))) {
        return null;
    }
    // ...and only that callback may redeem a handoff that names a nonce: every
    // generic redemption path refuses it, before its jti is spent.
    if ($expectedNonce === null && array_key_exists('nonce', $claims)) {
        return null;
    }
    if (!is_string($claims['jti'] ?? null) || trim($claims['jti']) === '') {
        return null;
    }
    $capabilityCap = _stattic_handoff_capability_cap($claims);
    if ($capabilityCap === false) {
        return null;
    }
    // Issued before this Space last signed everyone out: a session opened now
    // would carry the new version, so the sign-out could never end it. It is a
    // failed exchange like any other. A newer version is accepted: its
    // projection is still on the way, and the session ends when it lands. A
    // control plane before this check names none. `$serving` may predate this
    // request's wait on the token endpoint, so the box's version is read again.
    $sessionVersion = _stattic_access_current_session_version($serving);
    if (array_key_exists('sessionVersion', $claims)
        && (!is_int($claims['sessionVersion']) || $claims['sessionVersion'] < $sessionVersion)) {
        return null;
    }
    $authorityExpiresAt = $claims['authorityExpiresAt'] ?? null;
    // The browser's session is read under `$serving`'s version. When a sign-out
    // of everyone landed since, that session is over: nothing it held, neither
    // who it was nor what it could open, crosses into the new one.
    $current = $sessionVersion === _stattic_session_version($serving)
        ? _stattic_current_session_identity($serving, $host)
        : null;
    $currentSid = is_array($current) && is_string($current['sessionId'] ?? null)
        ? $current['sessionId']
        : null;
    $currentAuthorities = !$replaceSession && is_array($current) && is_array($current['authorityEntries'] ?? null)
        ? $current['authorityEntries']
        : [];
    // A carried sign-in authority (the capped ones) keeps the id of the
    // sign-in that acquired it, so ending that sign-in still ends it after the
    // id rotates to the token's. Other lanes end through their own Grants.
    $currentAuthorities = array_map(
        static fn (mixed $entry): mixed => is_array($entry) && $currentSid !== null && is_array($entry['capabilities'] ?? null)
            ? $entry + ['origin' => $currentSid]
            : $entry,
        $currentAuthorities
    );
    $inherit = $replaceSession ? [] : _stattic_access_session_inheritable($current);
    // One session holds one account, on every lane a handoff attaches through
    // (`/__spacefast/in/`, `/__sf/redeem`). A handoff that names someone other
    // than the session's own principal takes nothing a previous account held:
    // a guest keeps only its credential lanes (link, password, machine, claim
    // preview), which say what the holder may do and never who they are, and
    // its pseudonym. A handoff that names nobody (a link, a password) attaches
    // to whoever the session already is.
    $principal = _stattic_access_principal_from_claims($claims);
    $currentPrincipal = _stattic_access_identity_principal($current);
    if (!$replaceSession && $principal !== null && $principal !== $currentPrincipal) {
        $guest = !_stattic_access_principal_is_account($currentPrincipal);
        $currentAuthorities = $guest
            ? array_values(array_filter(
                $currentAuthorities,
                static fn (mixed $entry): bool => is_array($entry)
                    && preg_match('/\A(?:link|password|machine|claim-preview):/', (string) ($entry['reference'] ?? '')) === 1
            ))
            : [];
        $inherit = $guest ? $inherit : [];
    }
    $combinedAuthorities = _stattic_merge_authority_lru(
        $currentAuthorities,
        _stattic_callback_authorities($verified),
        is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [],
        is_array($verified['claims'] ?? null)
            && (($verified['claims']['emailVerified'] ?? false) === true),
        _stattic_access_session_now(),
        $authorityExpiresAt,
        $capabilityCap,
        is_string($claims['sid'] ?? null) ? $claims['sid'] : null
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
        $sessionVersion,
        is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [],
        $combinedAuthorities,
        $storedRecord,
        $inherit
    );
    if ($credential === null) {
        return null;
    }
    if ($currentSid !== null && $currentSid !== (string) ($storedRecord['sid'] ?? '')) {
        _stattic_security_session($serving, (string) ($storedRecord['spaceId'] ?? ''), (string) ($storedRecord['sid'] ?? ''), 'renewed', $currentSid);
    }
    // The session id rotates to the token's; the session being left carried its
    // identity forward and must not outlive the attach, so its revocation
    // record goes with it.
    if ($currentSid !== null && $currentSid !== (string) ($storedRecord['sid'] ?? '')) {
        _stattic_access_session_record_delete(_stattic_access_private_root(), $currentSid);
    }
    $handoffPrincipal = _stattic_access_principal_from_claims(
        is_array($verified['claims'] ?? null) ? $verified['claims'] : []
    );
    return $credential;
}

// Hard-separated from the 60s handoff: neither token can ever pass the
// other's checks, and a system view token never mints a visitor session. Each
// purpose is REQUIRED positively by its own consumer.
const STATTIC_SYSTEM_VIEW_PURPOSE = 'system-view';
const STATTIC_FRAME_SESSION_PURPOSE = 'frame-session';
const STATTIC_RUNTIME_BEARER_PURPOSE = 'runtime-bearer';

function _stattic_frame_session_cookie_name(): string
{
    return _stattic_config_value('SPACEFAST_INSECURE_COOKIES') === '1'
        ? STATTIC_FRAME_SESSION_DEV_COOKIE
        : STATTIC_FRAME_SESSION_COOKIE;
}

function _stattic_frame_session_cookie_from_request(): string
{
    $cookie = $_COOKIE[_stattic_frame_session_cookie_name()] ?? '';
    return is_string($cookie) ? $cookie : '';
}

/**
 * The exact normalized parents admitted for this response. The verifier and
 * the public Link projection are the only writers.
 *
 * @param list<string>|null $set
 * @return list<string>
 */
function _stattic_frame_ancestor_origins(?array $set = null): array
{
    static $origins = [];
    if ($set !== null) {
        $origins = array_values(array_unique(array_filter(
            $set,
            static fn (mixed $origin): bool => is_string($origin) && $origin !== ''
        )));
    }
    return $origins;
}

function _stattic_frame_grant_matches_path(array $grant, string $path): bool
{
    $segments = $path === '/' ? [] : explode('/', substr($path, 1));
    $included = false;
    foreach (is_array($grant['include'] ?? null) ? $grant['include'] : [] as $pattern) {
        if (is_array($pattern) && _stattic_grant_segments_match($pattern, $segments)) {
            $included = true;
            break;
        }
    }
    if (!$included) {
        return false;
    }
    foreach (is_array($grant['exclude'] ?? null) ? $grant['exclude'] : [] as $pattern) {
        if (is_array($pattern) && _stattic_grant_exclude_segments_match($pattern, $segments)) {
            return false;
        }
    }
    return true;
}

function _stattic_frame_grant_active(array $grant, array $target, string $path): bool
{
    $now = _stattic_access_session_now();
    return is_string($grant['frameOrigin'] ?? null)
        && is_array($grant['target'] ?? null)
        && _stattic_grant_target_matches($grant['target'], $target)
        && (!is_int($grant['notBefore'] ?? null) || $grant['notBefore'] <= $now)
        && (!is_int($grant['expiresAt'] ?? null) || $grant['expiresAt'] > $now)
        && in_array('page.view', is_array($grant['capabilities'] ?? null) ? $grant['capabilities'] : [], true)
        && _stattic_frame_grant_matches_path($grant, $path);
}

/** @return list<array> */
function _stattic_frame_link_grants(array $projection, string $linkId, string $path): array
{
    $index = _stattic_authorization_grant_index($projection);
    if ($index === null) {
        return [];
    }
    $authority = 'link:' . $linkId;
    $first = $path === '/' ? '' : (explode('/', substr($path, 1))[0] ?? '');
    return _stattic_grant_index_bucket_candidates(
        $index['authorities'][$authority] ?? null,
        $first
    );
}

function _stattic_frame_session_admits(
    string $path,
    ?array $setGrant = null,
    ?array $setTarget = null
): bool
{
    static $grant = null;
    static $target = null;
    if ($setGrant !== null && $setTarget !== null) {
        $grant = $setGrant;
        $target = $setTarget;
    }
    $canonical = _stattic_scope_path($path);
    return $canonical !== null
        && is_array($grant)
        && is_array($target)
        && _stattic_frame_grant_active($grant, $target, $canonical);
}

function _stattic_access_verify_frame_session_token(
    array $serving,
    string $host,
    string $token,
    ?string $entryPath = null
): bool {
    $verified = _stattic_visitor_verify(
        $token,
        _stattic_visitor_verify_options($serving, $host, null, ['requireJti' => false])
    );
    if ($verified === null) {
        return false;
    }
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    // The Grant decision the platform signed. `frame` is the contract object
    // (packages/common frameSessionClaimsV1Schema); the flat siblings beside it
    // are the bindings _stattic_visitor_verify already checked.
    $frame = is_array($claims['frame'] ?? null) ? $claims['frame'] : [];
    $link = is_array($frame['link'] ?? null) ? $frame['link'] : [];
    $capabilities = is_array($frame['capabilities'] ?? null) ? $frame['capabilities'] : [];
    $origin = _stattic_absolute_url_origin($frame['parentOrigin'] ?? null);
    $path = is_string($claims['path'] ?? null) ? _stattic_scope_path($claims['path']) : null;
    $target = is_array($frame['target'] ?? null) ? $frame['target'] : null;
    $currentTarget = _stattic_grant_target($serving);
    if (
        ($claims['purpose'] ?? null) !== STATTIC_FRAME_SESSION_PURPOSE
        || ($frame['format'] ?? null) !== 'spacefast.frame-session'
        || ($frame['version'] ?? null) !== 1
        // Reading a page is the only thing this proof admits. A writing surface
        // carries content.publish too, but that is projected by the content
        // kernel from the principal assertion, never by this admission check.
        || !in_array('page.view', $capabilities, true)
        || !is_string($link['id'] ?? null)
        || !is_int($link['revision'] ?? null)
        || $link['revision'] < 1
        || $origin === null
        || $origin !== ($frame['parentOrigin'] ?? null)
        || $path === null
        || $target === null
        || !_stattic_grant_target_matches($target, $currentTarget)
        // Rotating either epoch closes every proof minted before it, with no
        // lookup. Both copies must agree: the flat claim the shared verifier
        // reads and the one inside the signed Grant decision.
        || !is_int($claims['sessionVersion'] ?? null)
        || $claims['sessionVersion'] !== _stattic_session_version($serving)
        || ($frame['sessionVersion'] ?? null) !== $claims['sessionVersion']
        || ($frame['accessGeneration'] ?? null) !== ($claims['generation'] ?? null)
    ) {
        return false;
    }
    if ($entryPath !== null && _stattic_scope_path($entryPath) !== $path) {
        return false;
    }
    $matched = null;
    foreach (_stattic_frame_link_grants($serving['authorization'] ?? [], $link['id'], $path) as $grant) {
        if (
            is_array($grant)
            && ($grant['generation'] ?? null) === $link['revision']
            && ($grant['frameOrigin'] ?? null) === $origin
            && _stattic_frame_grant_active($grant, $currentTarget, $path)
        ) {
            $matched = $grant;
            break;
        }
    }
    if ($matched === null) {
        return false;
    }
    // Expiry is decided BEFORE anything is installed. _stattic_frame_session_admits
    // and _stattic_frame_ancestor_origins are side effects the enforcement path
    // reads back on its own; priming them and then returning false would admit
    // the request anyway. Both copies of the lifetime have to agree.
    $expiry = $claims['exp'] ?? null;
    if (
        !is_int($expiry)
        || ($frame['expiresAt'] ?? null) !== $expiry
    ) {
        return false;
    }
    _stattic_frame_session_admits($path, $matched, $currentTarget);
    _stattic_frame_ancestor_origins([$origin]);
    $GLOBALS['SPACEFAST_FRAME_SESSION_EXPIRY'] = $expiry;
    return true;
}

/** @return list<string> */
function _stattic_public_frame_origins(array $serving, string $requestPath): array
{
    $path = _stattic_scope_path($requestPath);
    $projection = is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [];
    $index = _stattic_authorization_grant_index($projection);
    if ($path === null || $index === null || ($index['hasFrameLinks'] ?? false) !== true) {
        return [];
    }
    $target = _stattic_grant_target($serving);
    $first = $path === '/' ? '' : (explode('/', substr($path, 1))[0] ?? '');
    $origins = [];
    foreach ($index['authorities'] as $reference => $store) {
        if (!is_string($reference) || !str_starts_with($reference, 'link:')) {
            continue;
        }
        foreach (_stattic_grant_index_bucket_candidates($store, $first) as $grant) {
            if (is_array($grant) && _stattic_frame_grant_active($grant, $target, $path)) {
                $origins[] = $grant['frameOrigin'];
            }
        }
    }
    return array_values(array_unique($origins));
}

/**
 * Adds only the deterministic CSP policy for public Frame paths. It leaves the
 * body cache key, cache policy, cookies, and Vary untouched.
 */
function _stattic_frame_response_headers(array $serving, string $requestPath, array $headers): array
{
    $origins = _stattic_frame_ancestor_origins();
    if ($origins === []) {
        $origins = _stattic_public_frame_origins($serving, $requestPath);
        _stattic_frame_ancestor_origins($origins);
    }
    if ($origins === []) {
        return $headers;
    }
    $existing = [];
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'content-security-policy') {
            $existing[] = is_scalar($value) ? (string) $value : '';
            unset($headers[$name]);
        }
    }
    $headers['content-security-policy'] = _stattic_private_content_csp_values($existing);
    return $headers;
}
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

/**
 * When the current request's proof expires, as a unix timestamp, or null.
 *
 * The echo cookie is the token, so it must not outlive it: a cookie that
 * survives its own payload is a browser that keeps presenting a dead credential
 * and gets a gate page for every asset.
 */
function _stattic_system_view_expiry(?int $expiresAt = null): ?int
{
    static $admitted = null;
    if ($expiresAt !== null) {
        $admitted = $expiresAt;
    }
    return $admitted;
}

// A system view token is Spacefast reading its own customer's Space (a
// thumbnail fetcher, an operator link, a framed preview). It names no Grant, so
// the Space's access generation has nothing to say about it and is not
// compared: a Grant edit the box has not received yet must not turn every
// thumbnail into a gate page. Revocation is the session version (the emergency
// logout-all lever), expiry, and signing-key rotation.
function _stattic_access_verify_system_view_token(
    array $serving,
    string $host,
    string $token
): bool {
    $verified = _stattic_visitor_verify(
        $token,
        _stattic_visitor_verify_options($serving, $host, null, [
            'requireJti' => false,
            'generation' => null,
        ])
    );
    if ($verified === null) {
        return false;
    }
    $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
    // A handoff must never open a page this way, and a view token must
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
        !is_int($claims['sessionVersion'] ?? null)
        || $claims['sessionVersion'] !== _stattic_session_version($serving)
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
    // The framer, when the mint declared one. Present-but-unreadable fails the
    // whole token exactly like an unreadable scope: a claim the platform signed
    // and this engine cannot honour must never degrade into the default.
    $embed = null;
    if (array_key_exists('embed', $claims)) {
        $embed = _stattic_absolute_url_origin($claims['embed']);
        if ($embed === null) {
            return false;
        }
    }
    $review = $claims['review'] ?? null;
    if (array_key_exists('review', $claims)) {
        if (
            !is_array($review) || !is_string($review['id'] ?? null)
            || preg_match('/\A[0-9a-f-]{36}\z/', $review['id']) !== 1
            || !is_string($review['versionId'] ?? null)
            || empty($serving['immutable']) || $review['versionId'] !== ($serving['version_id'] ?? null)
            || $embed === null || ($review['parentOrigin'] ?? null) !== $embed
            || _stattic_absolute_url_origin($review['parentOrigin'] ?? null) !== $embed
            || !is_array($review['ancestors'] ?? null) || count($review['ancestors']) > 8
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
        ) {
            return false;
        }
        foreach ($review['ancestors'] as $ancestor) {
            if (!is_string($ancestor) || _stattic_absolute_url_origin($ancestor) !== $ancestor) {
                return false;
            }
        }
        _stattic_system_view_review($review);
    }
    _stattic_system_view_scope($scope);
    if ($embed !== null) {
        _stattic_system_view_embed($embed);
    }
    if (isset($claims['exp'])) {
        _stattic_system_view_expiry((int) $claims['exp']);
    }
    return true;
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
        "This link may have expired or already been used.\n",
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
            [
                ..._stattic_access_context($serving, $requestHost, '/'),
                'browser' => _stattic_access_browser_pseudonym($serving, $requestHost),
            ],
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
    _stattic_access_redeem_link_entry($serving, $requestHost, $token);
}

// `/__spacefast`: the door. It shows the Space's gate with every lane this
// Space offers (a password field, Sign in with Spacefast), returning to
// `?return=` once the visitor is in. A visitor who already has access to that
// page goes straight there. The door itself never serves Space content.
function _stattic_access_handle_door(array $serving, string $requestHost): never
{
    if (!in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true)) {
        _stattic_render_method_not_allowed_lazy();
    }
    $returnTo = _stattic_safe_return_path(
        is_string($_GET['return'] ?? null) ? (string) $_GET['return'] : '/'
    ) ?? '/';
    _stattic_access_redirect_if_admitted($serving, $requestHost, $returnTo);
    _stattic_render_access_gate($serving, $requestHost, ['return' => $returnTo]);
}

// Leaves for `$returnTo` when this browser may already open it; a gate in front
// of a page someone can open is a dead end. Probes charge nothing.
function _stattic_access_redirect_if_admitted(array $serving, string $requestHost, string $returnTo): void
{
    $path = parse_url($returnTo, PHP_URL_PATH);
    $admitted = _stattic_enforce_scoped_admission(
        $serving,
        $requestHost,
        is_string($path) && $path !== '' ? $path : '/',
        true
    );
    if ($admitted !== null) {
        _stattic_access_redirect($returnTo, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE);
    }
}

// `/__spacefast/in/<token>?return=<path>`, the one way in by secret and the one
// place a handoff lands. A link token goes the link lane; a handoff (the door's,
// a password's, an Open link's) is consumed here, carrying its own return path. Both answer with the session cookie and a 303 to the
// clean landing path, never content, so the token leaves the address bar on
// the first response and never reaches a Referer.
function _stattic_access_handle_in(array $serving, string $requestPath, string $requestHost): void
{
    $parsed = _stattic_access_in_token($requestPath);
    if ($parsed === null) {
        _stattic_render_access_route_not_found();
    }
    if (!in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true)) {
        _stattic_render_method_not_allowed_lazy();
    }
    [$kind, $token] = $parsed;
    $requestedReturn = is_string($_GET['return'] ?? null)
        ? _stattic_safe_return_path((string) $_GET['return'])
        : null;
    if ($kind === 'link') {
        _stattic_access_redeem_link_entry($serving, $requestHost, $token, $requestedReturn, '/');
    }
    $host = _stattic_canonicalize_host($requestHost);
    $storedRecord = null;
    $handoffPrincipal = null;
    $credential = _stattic_access_consume_handoff_token(
        $serving,
        $host,
        $token,
        $storedRecord,
        handoffPrincipal: $handoffPrincipal
    );
    if ($credential === null) {
        _stattic_render_access_route_not_found();
    }
    _stattic_access_set_session_cookie($credential);
    // The returning-account marker only decides whether the page asks about a
    // bar, so it is written only where this host's page would try one
    // (`_stattic_spacefast_bar_tried`, the SDK's own decision): no marker with
    // the bar switched off, injection off, or on a public host whose
    // projection doesn't offer the bar there.
    require_once __DIR__ . '/spacefast-sdk.php';
    if (
        _stattic_access_principal_is_account($handoffPrincipal ?? '')
        && _stattic_spacefast_bar_tried($serving, $host) !== null
    ) {
        _stattic_access_remember_returning_account();
    }
    _stattic_access_redirect(
        $requestedReturn ?? '/',
        303,
        STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        true
    );
}

// `/__sf/redeem`: the handoff address of a control plane that predates
// `/__spacefast/in/`, with every shape it sends there. A browser follows
// `GET ?sf_token=<handoff>&return=<path>` (with `silent=1` on a renewal nobody
// clicked for, or `display=popup` from a popup), and a released CLI posts the
// JSON handoff's `token` and `return` as a same-origin form. Same consumption
// and session as `/__spacefast/in/`. Delete with compat/old-engine.ts S12 once
// no control plane image or CLI release that sends it can reach a box.
function _stattic_access_handle_legacy_redeem(array $serving, string $requestHost): never
{
    $method = _stattic_runtime_request_method();
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        _stattic_render_method_not_allowed_lazy();
    }
    if (
        $method === 'POST'
        && (
            !_stattic_access_same_origin_post($requestHost)
            || strtolower(trim((string) strstr(($_SERVER['CONTENT_TYPE'] ?? '') . ';', ';', true)))
                !== 'application/x-www-form-urlencoded'
        )
    ) {
        _stattic_render_json_or_deny('access_origin_invalid', 'Cross-origin access submissions are not accepted.');
    }
    $fields = $method === 'POST' ? $_POST : $_GET;
    $tokenField = $method === 'POST' ? 'token' : 'sf_token';
    $token = is_string($fields[$tokenField] ?? null) ? trim((string) $fields[$tokenField]) : '';
    $returnTo = _stattic_safe_return_path(is_string($fields['return'] ?? null) ? (string) $fields['return'] : '/');
    $parsed = $token === '' ? null : _stattic_access_in_token(STATTIC_ACCESS_IN_PREFIX . $token);
    if ($parsed === null || $parsed[0] !== 'handoff' || $returnTo === null) {
        _stattic_render_access_route_not_found();
    }
    $storedRecord = null;
    $credential = _stattic_access_consume_handoff_token(
        $serving,
        _stattic_canonicalize_host($requestHost),
        $parsed[1],
        $storedRecord
    );
    if ($credential === null) {
        if ($method !== 'POST' && ($fields['silent'] ?? null) === '1') {
            _stattic_access_redirect($returnTo, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        }
        _stattic_render_access_route_not_found();
    }
    _stattic_access_set_session_cookie($credential);
    // As it always has: the saved record's principal decides, so a handoff
    // that attaches to a browser already signed in to an account keeps its
    // marker. The canonical route decides from the handoff's own claims.
    if (_stattic_access_principal_is_account((string) ($storedRecord['principal'] ?? ''))) {
        _stattic_access_remember_returning_account();
    }
    if ($method !== 'POST' && ($fields['display'] ?? '') === 'popup') {
        // The session cookie is already set browser-wide: tell the opener
        // (same origin only) and close. No token ever crosses postMessage.
        $nonce = bin2hex(random_bytes(16));
        header('Cache-Control: ' . STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        header('Referrer-Policy: no-referrer', true);
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; base-uri 'none'; frame-ancestors 'none'", true);
        header('Content-Type: text/html; charset=utf-8', true);
        echo '<!doctype html><meta name="referrer" content="no-referrer"><title>Signed in</title>'
            . '<p>You&#039;re in. You can close this window.</p>'
            . '<p><a href="' . _stattic_html_escape($returnTo) . '">Continue</a></p>'
            . '<script nonce="' . $nonce . '">'
            . 'if(window.opener){try{window.opener.postMessage("sf-access-complete",window.location.origin)}catch(e){}}'
            . 'window.close();'
            . '</script>';
        exit;
    }
    _stattic_access_redirect($returnTo, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
}

// `$requestedReturn` is the page a deep link asked for; without one the link's
// own landing path decides. `$deadReturn` is the gate a dead link without one
// shows instead of a bare not-found.
function _stattic_access_redeem_link_entry(
    array $serving,
    string $requestHost,
    string $token,
    ?string $requestedReturn = null,
    ?string $deadReturn = null
): never {
    if (!in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true)) {
        _stattic_render_method_not_allowed_lazy();
    }
    $redeemed = _stattic_access_exchange_link_token($serving, $requestHost, $token);
    // A dead link at the one way in shows the Space's gate for the page it
    // asked for (the front page when it named none) and says why. The exchange
    // answers every dead token alike, so the line never tells a prober whether
    // this one ever opened anything.
    $deadLink = static function () use ($serving, $requestHost, $requestedReturn, $deadReturn, &$redeemed): never {
        $requestedReturn ??= $deadReturn;
        if ($requestedReturn === null) {
            _stattic_render_access_route_not_found();
        }
        $exchangeStatus = is_array($redeemed) ? $redeemed['status'] : 0;
        // The dead token is still in this URL: keep it out of every Referer
        // and every shared cache.
        header('Referrer-Policy: no-referrer', true);
        _stattic_access_private_cache_flag(true);
        // A public page needs no link: open it, as the `?__=` lane does.
        _stattic_access_redirect_if_admitted($serving, $requestHost, $requestedReturn);
        _stattic_render_access_gate($serving, $requestHost, [
            'return' => $requestedReturn,
            'allowRedirect' => false,
            'status' => $exchangeStatus === 429
                ? 'rate-limited'
                : ($exchangeStatus === 0 || $exchangeStatus >= 500 ? 'exchange-unavailable' : 'link-invalid'),
        ]);
    };
    if ($redeemed === null) {
        $deadLink();
    }
    $host = $redeemed['host'];
    $body = $redeemed['body'];
    $fields = $redeemed['fields'];
    $exchange = $redeemed['exchange'];
    $returnPath = $requestedReturn ?? (is_array($fields) && is_string($fields['return'] ?? null)
        ? _stattic_safe_return_path($fields['return'])
        : null);
    if ($redeemed['status'] === 200 && $redeemed['handoff'] !== '' && $returnPath !== null) {
        // A link says what its holder may do, never who they are, and they stay
        // a guest until they choose to sign in: no silent account probe here.
        $credential = _stattic_access_consume_handoff_token($serving, $host, $redeemed['handoff']);
        if ($credential !== null) {
            _stattic_access_set_session_cookie($credential);
            _stattic_access_redirect($returnPath, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        }
    }
    _stattic_access_gate_email_verification(
        $serving,
        $requestHost,
        $body,
        $exchange['emailUrl'] ?? null,
        $requestedReturn ?? '/'
    );
    $deadLink();
}

// The raw access/API token never becomes a cookie, and X-SF-Authorization is
// dropped from every header map handed to tenant code (upstream-relay.php).
function _stattic_access_verify_platform_identity_token(
    array $serving,
    string $host,
    string $token
): bool {
    if (_stattic_access_verify_system_view_token($serving, $host, $token)) {
        return true;
    }
    $verifyOptions = _stattic_visitor_verify_options($serving, $host, null, [
        'requireJti' => false,
    ]);
    $verified = _stattic_visitor_verify($token, $verifyOptions);
    $claims = is_array($verified) && is_array($verified['claims'] ?? null)
        ? $verified['claims']
        : [];
    $purpose = $claims['purpose'] ?? null;
    // Browser binding belongs only to an interactive handoff. A bearer with
    // that claim cannot cross this header boundary under any purpose.
    if (array_key_exists('browserState', $claims)) {
        return false;
    }
    if ($purpose === STATTIC_RUNTIME_BEARER_PURPOSE) {
        // This purpose is the reusable agent credential. Signature, expiry,
        // Space generation and host binding were all checked above; its jti is
        // an identifier, not a one-use redemption nonce.
        return true;
    }
    if ($purpose !== STATTIC_HANDOFF_PURPOSE) {
        return false;
    }
    // A handoff that finishes a Sign in with Spacefast request names that
    // request's nonce and belongs to the OAuth callback alone; refused here
    // before its jti is spent.
    if (array_key_exists('nonce', $claims)) {
        return false;
    }
    // Issued before this Space last signed everyone out: refused, as by the
    // session consumer, before the jti is spent. A control plane before this
    // check names no version.
    if (array_key_exists('sessionVersion', $claims)
        && (!is_int($claims['sessionVersion'])
            || $claims['sessionVersion'] < _stattic_access_current_session_version($serving))) {
        return false;
    }
    // A server-to-server credential exchange mints one short-lived handoff for
    // this request. Consume its jti here so copying that internal result cannot
    // turn it into a reusable platform bearer.
    return _stattic_visitor_verify(
        $token,
        array_merge($verifyOptions, ['requireJti' => true, 'iatMaxAge' => 60])
    ) !== null;
}

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
    $host = _stattic_canonicalize_host($requestHost);
    $landingPath = _stattic_safe_return_path($requestPath) ?? '/';
    $memoKey = $host . "\0" . $landingPath . "\0" . $presented;
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    // Header lane, not the `?__=` lane: an integration presents whatever it was
    // given, and a system view token it already holds is verified here instead
    // of being re-exchanged. Compact serialization is three dot-separated parts.
    if (substr_count($presented, '.') === 2) {
        return $memo[$memoKey] = _stattic_access_verify_platform_identity_token(
            $serving,
            $host,
            $presented
        ) ? $presented : '';
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
        if (!_stattic_access_verify_platform_identity_token($serving, $host, $identityToken)) {
            $identityToken = '';
        }
    }
    return $memo[$memoKey] = $identityToken;
}

// THE `?__=` lane, and the shape of the whole thing: whatever the token proves,
// it proves it for the URL it is sitting on, and that URL answers with its own
// content in this response for agents and fetchers. A successful exchange in a
// top-level browser navigation sets its cookie, then redirects to the clean URL
// before customer scripts can read the token. Only explicit Fetch Metadata
// (navigate + document) opts into that hop; missing metadata keeps one-request
// access. Embedded system previews retain their cookie-and-redirect exchange.
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
        // A browser opening a share link goes through the one way in, carrying
        // the page it asked for; `/__spacefast/in/` sets the session and lands
        // there with a clean URL. Agents and fetchers keep the page in this
        // response (#5429).
        if (
            str_starts_with($token, STATTIC_ACCESS_QUERY_TOKEN_LINK_PREFIX)
            && _stattic_request_is_top_level_navigation()
        ) {
            _stattic_access_redirect(
                STATTIC_ACCESS_IN_PREFIX . rawurlencode($token) . '?return=' . rawurlencode(
                    _stattic_safe_return_path(_stattic_access_return_without_flow_params(
                        _stattic_runtime_effective_request_uri()
                    )) ?? '/'
                ),
                302,
                STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
                true
            );
        }
        _stattic_access_redeem_query_link_token($serving, $requestHost, $token);
        return;
    }
    if ($kind === 'frame-session') {
        $verified = _stattic_access_verify_frame_session_token(
            $serving,
            _stattic_canonicalize_host($requestHost),
            $token,
            $requestPath
        );
        if ($verified) {
            $expiry = $GLOBALS['SPACEFAST_FRAME_SESSION_EXPIRY'] ?? null;
            $seconds = is_int($expiry)
                ? max(1, $expiry - _stattic_access_session_now())
                : 1;
            _stattic_set_cookie(
                _stattic_frame_session_cookie_name(),
                $token,
                $seconds,
                true
            );
            _stattic_access_redirect_query_token();
        }
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
        // An embed proof is framed cross-site, where a Lax cookie is neither
        // stored nor sent, so its echo is partitioned by the top-level site.
        // An embed, or a browser navigation that is about to lose the token
        // from its address bar, keeps the echo as long as the token it
        // carries, so a reload works for the token's whole life. Every reader
        // keeps at least the short same-site echo: a token close to its end
        // must still survive the redirect to the clean URL. The echo is only
        // a carrier; each request re-verifies the JWT inside it.
        $embed = _stattic_system_view_embed();
        $expiresAt = _stattic_system_view_expiry();
        $tokenLifetime = $expiresAt === null
            || ($embed === null && !_stattic_request_is_top_level_navigation())
            ? 0
            : $expiresAt - time();
        _stattic_set_cookie(
            _stattic_system_view_cookie_name(),
            $token,
            max(STATTIC_SYSTEM_VIEW_COOKIE_SECONDS, $tokenLifetime),
            $embed !== null
        );
        _stattic_access_redirect_query_token($embed !== null);
    }
}

function _stattic_request_is_top_level_navigation(): bool
{
    return strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''))) === 'navigate'
        && strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''))) === 'document';
}

// Call only after this response sets the verified token's cookie. Customer
// scripts must see the clean URL on top-level browser navigations and embedded
// system previews. Everyone else keeps the content in this response.
function _stattic_access_redirect_query_token(bool $framedPreview = false): void
{
    if (!$framedPreview && !_stattic_request_is_top_level_navigation()) {
        return;
    }
    _stattic_access_private_cache_flag(true);
    // Keep the page's query bytes and path; sf_access_return is only flow state.
    _stattic_access_redirect(
        _stattic_safe_return_path(_stattic_access_return_without_flow_params(
            _stattic_runtime_effective_request_uri()
        )) ?? '/',
        302,
        STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        true
    );
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
// it server-to-server and set the session cookie on THIS response. A top-level
// browser navigation then drops the token; otherwise enforcement below serves
// the page the visitor actually asked for in this response. The
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
            _stattic_access_redirect_query_token();
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
        // Both namespaces: a dead cookie says nothing about whether it was
        // written for a framed preview, and a partitioned cookie survives an
        // unpartitioned deletion.
        _stattic_clear_cookie(_stattic_system_view_cookie_name());
        _stattic_clear_cookie(_stattic_system_view_cookie_name(), true);
        _stattic_identity_cookie_mutated(true);
    }
}

function _stattic_access_apply_frame_session_cookie(array $serving, string $requestHost): void
{
    if (_stattic_access_query_token_present()) {
        return;
    }
    $token = _stattic_frame_session_cookie_from_request();
    if ($token === '') {
        return;
    }
    if (
        !_stattic_access_verify_frame_session_token(
            $serving,
            _stattic_canonicalize_host($requestHost),
            $token
        )
    ) {
        _stattic_clear_cookie(_stattic_frame_session_cookie_name(), true);
        _stattic_identity_cookie_mutated(true);
    }
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
        _stattic_normalize_host_authority((string) ($_SERVER['HTTP_HOST'] ?? '')),
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

function _stattic_access_revoke_collaboration_session(
    array $serving,
    string $requestHost,
    string $sessionId,
    string $returnTo,
    string $principal
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
            // Whose session this was. The browser's hop through the clear URL
            // records the Space sign-out for that account, so the gate's silent
            // sign-in does not bring it straight back. An older control plane
            // ignores the field.
            'principal' => $principal,
        ],
        _stattic_access_exchange_headers($exchange, ['serving' => $serving], 'application/json')
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

// Same-origin containment boundary: POST is the access lanes' same-origin form
// check (an exact Origin, or same-origin Fetch Metadata behind a no-referrer
// page's opaque Origin); the navigation lane must carry same-origin Fetch
// Metadata. A failed durable removal never reports success and never discards
// the caller's only copy of a still-live bearer.
function _stattic_access_logout_request_allowed(string $method, string $requestHost): bool
{
    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($method === 'POST') {
        $allowed = _stattic_access_same_origin_post($requestHost);
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

function _stattic_access_handle_session(array $serving, string $requestHost): never
{
    $method = _stattic_runtime_request_method();
    $headers = _stattic_private_content_response_headers([
        'Cache-Control' => 'private, no-store',
    ]);
    // Only the canonical path signs out: the older `/access/session` stays
    // the read it always was.
    $canonical = _stattic_runtime_request_path() === STATTIC_SPACE_SESSION_PATH;
    $allow = $canonical ? 'GET, HEAD, DELETE, OPTIONS' : 'GET, HEAD, OPTIONS';
    if ($method === 'OPTIONS') {
        _stattic_response_send(204, '', '', [...$headers, 'Allow' => $allow]);
    }
    if ($canonical && $method === 'DELETE') {
        _stattic_access_handle_session_delete($serving, $requestHost, $headers);
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        _stattic_method_not_allowed($allow, [
            'code' => 'method_not_allowed',
            'message' => $canonical
                ? 'The Space session supports GET, HEAD and DELETE.'
                : 'Access sessions support GET and HEAD.',
            'headers' => $headers,
        ]);
    }

    $identity = _stattic_current_session_identity($serving, $requestHost);
    $principal = _stattic_access_identity_principal($identity);
    $session = ['state' => 'anonymous'];
    // Passwords, share links and collaboration pseudonyms can authorize a
    // visitor without identifying a person. Only the verified Access principal
    // supplies identity, independently of whichever grants still admit it.
    if (_stattic_access_principal_is_identified($principal)) {
        $profile = _stattic_access_public_profile($identity['profile'] ?? null);
        $session = [
            'state' => 'authenticated',
            'principal' => $principal,
            'displayName' => $profile['name'] ?? null,
        ];
        // Whether this session gets the Space bar, decided here from what the
        // session holds and never by page script. Said only where the control
        // plane projected a bar (its capability and `share.sign_in` on).
        require_once __DIR__ . '/../shared/bootstrap-config.php';
        require_once __DIR__ . '/spacefast-sdk.php';
        if (_stattic_spacefast_bar_projected($serving)) {
            $session['bar'] = _stattic_access_session_shows_bar($serving, $identity, $principal);
        }
    }
    // The returning-account marker only says "ask the session". Once the
    // session says nobody is signed in with an account, asking again on every
    // page is wasted: the marker goes, and the next account sign-in writes it.
    if (!_stattic_access_principal_is_account($principal)) {
        _stattic_access_forget_returning_account();
    }
    _stattic_json_response(
        200,
        ['data' => $session],
        'application/json',
        $headers
    );
}

// `DELETE /__spacefast/session`: the page's own sign-out, the same end as the
// logout form with a JSON answer instead of a redirect. A DELETE is never a
// simple request, so another origin can only send one after a preflight this
// path never grants; the Origin check below still refuses one that tries.
// `next` is where the browser goes: the control plane's clear hop when there
// is one, else the page it asked to return to.
function _stattic_access_handle_session_delete(array $serving, string $requestHost, array $headers): never
{
    if (!_stattic_access_same_origin_post($requestHost)) {
        _stattic_render_json_or_deny('access_origin_invalid', 'Cross-origin sign-out is not accepted.');
    }
    $returnTo = _stattic_safe_return_path(is_string($_GET['return'] ?? null) ? (string) $_GET['return'] : '/') ?? '/';
    $clearUrl = _stattic_access_end_session($serving, $requestHost, $returnTo);
    if ($clearUrl === false) {
        _stattic_access_logout_unavailable();
    }
    _stattic_json_response(
        200,
        ['data' => ['state' => 'anonymous', 'next' => $clearUrl ?? $returnTo]],
        'application/json',
        [...$headers, 'Referrer-Policy' => 'no-referrer']
    );
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
    $returnRaw = isset($_POST['return']) && is_string($_POST['return'])
        ? (string) $_POST['return']
        : (isset($_GET['return']) && is_string($_GET['return']) ? (string) $_GET['return'] : '/');
    $returnTo = _stattic_safe_return_path($returnRaw) ?? '/';
    if (!_stattic_access_logout_request_allowed($method, $requestHost)) {
        // A request that cannot prove it came from this Space's own pages (a
        // browser without Fetch Metadata, a no-referrer page's opaque Origin,
        // a link or form elsewhere) ends nothing. It asks, and the button posts
        // back here with this origin: `same-origin` keeps that Origin real.
        if ($method === 'GET' || $method === 'POST') {
            // A refusal of the request as it came, whatever the page offers.
            require_once __DIR__ . '/../shared/errors.php';
            _stattic_refusal_header_mark('access_origin_invalid');
            _stattic_serve_page('sign-out', [
                'status' => 403,
                'headers' => [
                    'Cache-Control' => 'no-store',
                    'Referrer-Policy' => 'same-origin',
                    'Content-Security-Policy' => "frame-ancestors 'none'",
                    STATTIC_REFUSAL_HEADER => 'access_origin_invalid',
                ],
                'message' => 'Sign out of this Space?',
                'code' => 'access_origin_invalid',
                'fragment' => '<form method="post" action="' . _stattic_html_escape(STATTIC_ACCESS_LOGOUT_PATH)
                    . '"><input type="hidden" name="return" value="' . _stattic_html_escape($returnTo)
                    . '"><div class="sf-actions"><button class="sf-button" type="submit">Sign Out</button>'
                    . '<a class="sf-button sf-button-quiet" href="' . _stattic_html_escape($returnTo) . '">Stay Signed In</a></div></form>',
            ]);
            exit;
        }
    }
    $clearUrl = _stattic_access_end_session(_stattic_page_serving(), $requestHost, $returnTo);
    if ($clearUrl === false) {
        _stattic_access_logout_unavailable();
    }
    // The clearUrl is cross-origin: the return= param in this URL must not
    // leak as a Referer.
    _stattic_access_redirect($clearUrl ?? $returnTo, 303);
}

/**
 * Ends this browser's session on this Space: its Cast session, its record and
 * every platform cookie that opens pages here. The control plane's clear hop
 * (a URL the browser must visit to record the sign-out), null when there is
 * none, or false when the end could not be made durable.
 */
function _stattic_access_end_session(array $serving, string $requestHost, string $returnTo): string|false|null
{
    $sessionId = _stattic_access_presented_session_id();
    // Ending the session ends its Cast session: one id names both. The stateless
    // lane keeps its id in the cookie payload rather than a record, so read it
    // from the resolved identity, never from the cookie value itself, which must
    // never travel to the control plane or into a Cast room.
    $identity = _stattic_current_session_identity($serving, $requestHost);
    $comments = _stattic_access_identity_comments($identity);
    $collaborationSessionId = $comments['sessionId'] ?? $sessionId;
    $clearUrl = null;
    if ($collaborationSessionId !== null) {
        $collaborationRevoke = _stattic_access_revoke_collaboration_session(
            $serving,
            $requestHost,
            $collaborationSessionId,
            $returnTo,
            _stattic_access_identity_principal($identity)
        );
        if ($collaborationRevoke === null) {
            return false;
        }
        $clearUrl = $collaborationRevoke['clearUrl'];
    }
    if (!_stattic_access_revoke_presented_session($sessionId)) {
        return false;
    }
    _stattic_clear_cookie(_stattic_session_cookie_name());
    // A system-view or frame proof is not a session, but it opens pages from
    // this browser just the same, and a browser navigation keeps an operator's
    // for as long as its token lives. Signing out drops both namespaces of each.
    _stattic_clear_cookie(_stattic_system_view_cookie_name());
    _stattic_clear_cookie(_stattic_system_view_cookie_name(), true);
    _stattic_clear_cookie(_stattic_frame_session_cookie_name(), true);
    // Signing out here means staying out: no silent renewal on the next visit.
    _stattic_access_forget_returning_account();
    return $clearUrl;
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
    require_once __DIR__ . '/../shared/errors.php';
    _stattic_refusal_header_mark($code);
    if (_stattic_request_is_fetch()) {
        _stattic_problem_response(401, $code, '', [], _stattic_private_content_response_headers([
            'Cache-Control' => 'private, no-store',
            STATTIC_REFUSAL_HEADER => $code,
        ]));
    }
    _stattic_response_send(403, $message . "\n", 'text/plain; charset=utf-8', _stattic_private_content_response_headers([
        'Cache-Control' => 'private, no-store',
        STATTIC_REFUSAL_HEADER => $code,
    ]));
}

// ---- `/__spacefast/unlock`: the unlock card on the Space's own hostname ----
//
// Served before any of the Space's own HTML or JavaScript runs, so a password
// is typed into a page only Spacefast wrote, under this host's origin (the
// password manager files it per Space). The password itself is checked
// server-to-server by the control plane; nothing here knows it.
//
// One card for every lane: "Continue with Spacefast" on top when the Space
// lets accounts in, "Or enter password" below when it has a password.

const STATTIC_UNLOCK_CSRF_COOKIE_PREFIX = '__Host-spacefast_unlock_';
const STATTIC_UNLOCK_CSRF_DEV_COOKIE_PREFIX = 'spacefast_unlock_dev_';
const STATTIC_UNLOCK_CSRF_SECONDS = 1800;
// Set while one silent `prompt=none` check is out: a refresh of the card that
// it falls back to renders the card instead of trying again.
const STATTIC_UNLOCK_SILENT_TRIED_COOKIE_PREFIX = '__Host-spacefast_unlock_tried_';
const STATTIC_UNLOCK_SILENT_TRIED_DEV_COOKIE_PREFIX = 'spacefast_unlock_tried_dev_';
const STATTIC_UNLOCK_SILENT_TRIED_SECONDS = 300;

function _stattic_unlock_cookie_name(array $serving, string $prefix, string $devPrefix): string
{
    $spaceId = preg_replace('/[^A-Za-z0-9_-]/', '', _stattic_serving_space_id($serving)) ?? '';
    return (_stattic_cookies_secure() ? $prefix : $devPrefix) . substr($spaceId, 0, 64);
}

/**
 * The unlock form's CSRF value: random, signed under this Space's key and
 * bound to `(spaceId, host)`, so a value minted on one hostname or for one
 * Space never verifies on another. The cookie holds the signed value and the
 * form carries its random half; a POST must present both.
 */
function _stattic_unlock_csrf_issue(array $serving, string $host): ?string
{
    $name = _stattic_unlock_cookie_name($serving, STATTIC_UNLOCK_CSRF_COOKIE_PREFIX, STATTIC_UNLOCK_CSRF_DEV_COOKIE_PREFIX);
    $existing = _stattic_unlock_csrf_from_cookie($serving, $host);
    if ($existing !== null) {
        return $existing;
    }
    $nonce = bin2hex(random_bytes(16));
    $cookie = _stattic_signed_cookie_encode($serving, $host, 'sfu1_', 'unlock-csrf', 256, [
        'space' => _stattic_serving_space_id($serving),
        'nonce' => $nonce,
    ]);
    if ($cookie === null) {
        return null;
    }
    _stattic_set_cookie($name, $cookie, STATTIC_UNLOCK_CSRF_SECONDS);
    return $nonce;
}

function _stattic_unlock_csrf_from_cookie(array $serving, string $host): ?string
{
    $name = _stattic_unlock_cookie_name($serving, STATTIC_UNLOCK_CSRF_COOKIE_PREFIX, STATTIC_UNLOCK_CSRF_DEV_COOKIE_PREFIX);
    $cookie = $_COOKIE[$name] ?? null;
    if (!is_string($cookie) || $cookie === '') {
        return null;
    }
    $payload = _stattic_signed_cookie_decode($serving, $host, 'sfu1_', 'unlock-csrf', 256, $cookie);
    $nonce = is_array($payload) ? ($payload['nonce'] ?? null) : null;
    return is_array($payload)
        && ($payload['space'] ?? null) === _stattic_serving_space_id($serving)
        && is_string($nonce)
        && preg_match('/\A[a-f0-9]{32}\z/D', $nonce) === 1
        ? $nonce
        : null;
}

function _stattic_unlock_csrf_valid(array $serving, string $host, mixed $presented): bool
{
    $expected = _stattic_unlock_csrf_from_cookie($serving, $host);
    return $expected !== null && is_string($presented) && hash_equals($expected, $presented);
}

/** Headers every unlock card carries: no Referer out, no framing, no index. */
function _stattic_unlock_response_headers(array $extra = []): array
{
    return _stattic_private_content_response_headers([
        'Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Content-Security-Policy' => "frame-ancestors 'none'",
        ...$extra,
    ]);
}

/**
 * `GET /__spacefast/unlock?return=<path>`: the unlock card, or a redirect.
 *
 *  - Already in: straight to the page.
 *  - Hybrid (accounts and a password), returning account (the marker): 302 to
 *    `/__spacefast/sign-in`, zero clicks.
 *  - Hybrid, first visit, document navigation: one silent `prompt=none` check,
 *    marked with a five-minute cookie so the fallback back here renders the
 *    card instead of trying again.
 *  - Otherwise the card.
 *
 * `POST /__spacefast/unlock`: the password form, checked server-to-server.
 */
function _stattic_access_handle_unlock(array $serving, string $requestHost, string $privateRoot): never
{
    $method = _stattic_runtime_request_method();
    if ($method === 'POST') {
        _stattic_access_handle_unlock_post($serving, $requestHost, $privateRoot);
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        _stattic_method_not_allowed('GET, HEAD, POST');
    }
    $host = _stattic_canonicalize_host($requestHost);
    $returnTo = _stattic_safe_return_path(
        is_string($_GET['return'] ?? null) ? (string) $_GET['return'] : '/'
    ) ?? '/';
    _stattic_access_redirect_if_admitted($serving, $requestHost, $returnTo);
    $lanes = _stattic_access_page_lanes($serving);
    if ($lanes === null) {
        _stattic_render_unclaimed_notice($serving);
    }
    $status = _stattic_access_status_code();
    $accounts = is_string($lanes['account']) && _stattic_share_oauth_is_account_url($lanes['account']);
    $hybrid = $accounts && $lanes['password'];
    // `method=password` asks for the card itself (its "Use a password" link),
    // and `silent=tried` is the silent check coming back: either one draws the
    // card. The marker rides the URL, so a browser that keeps no cookies
    // still lands on the card instead of looping through the silent check.
    $asked = ($_GET['method'] ?? null) === 'password' || ($_GET['silent'] ?? null) === 'tried';
    if ($hybrid && !$asked && $status === '' && $method === 'GET' && _stattic_request_is_document_navigation()) {
        $signIn = STATTIC_SHARE_OAUTH_START_PATH . '?return=' . rawurlencode($returnTo);
        // A browser an account signed in to before: back to Spacefast, no click.
        if (isset($_COOKIE[_stattic_returning_account_cookie_name()])) {
            _stattic_access_redirect($signIn, 302, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        }
        // First visit: one silent check whether this browser is already signed
        // in to an account that may open the Space. It falls back here.
        $triedName = _stattic_unlock_cookie_name(
            $serving,
            STATTIC_UNLOCK_SILENT_TRIED_COOKIE_PREFIX,
            STATTIC_UNLOCK_SILENT_TRIED_DEV_COOKIE_PREFIX
        );
        if (!isset($_COOKIE[$triedName]) && $lanes['silent']) {
            _stattic_set_cookie($triedName, '1', STATTIC_UNLOCK_SILENT_TRIED_SECONDS);
            _stattic_share_oauth_begin(
                $serving,
                $host,
                STATTIC_ACCESS_UNLOCK_PATH . '?return=' . rawurlencode($returnTo) . '&silent=tried',
                true
            );
        }
    }
    $csrf = _stattic_unlock_csrf_issue($serving, $host);
    if ($csrf === null) {
        _stattic_render_scoped_deny($serving);
    }
    _stattic_access_render_unlock_card($serving, $lanes, $returnTo, $status, $csrf, $accounts);
}

function _stattic_access_render_unlock_card(
    array $serving,
    array $lanes,
    string $returnTo,
    string $status,
    string $csrf,
    bool $accounts
): never {
    require_once __DIR__ . '/../shared/brand.php';
    $descriptor = $lanes['descriptor'];
    $html = '<div class="sf-access-lanes sf-unlock">';
    if ($accounts) {
        $html .= '<a class="sf-button sf-access-account" href="'
            . _stattic_html_escape(STATTIC_SHARE_OAUTH_START_PATH . '?return=' . rawurlencode($returnTo))
            . '">Continue with ' . _stattic_html_escape(_stattic_brand_value('name')) . '</a>';
    }
    if ($lanes['password']) {
        if ($accounts) {
            $html .= '<div class="sf-access-or" aria-hidden="true">or enter password</div>';
        }
        $html .= '<form class="sf-access-password" method="post" action="'
            . _stattic_html_escape(STATTIC_ACCESS_UNLOCK_PATH) . '">'
            . '<input type="hidden" name="return" value="' . _stattic_html_escape($returnTo) . '">'
            . '<input type="hidden" name="csrf" value="' . _stattic_html_escape($csrf) . '">'
            . '<label class="sf-label" for="sf-access-password-input">Password</label>'
            . '<div class="sf-access-password-row"><div class="sf-field">'
            . '<input id="sf-access-password-input" class="sf-input" type="password" name="password"'
            . ' autocomplete="current-password" required maxlength="1024" placeholder="Enter password"'
            . ($status === 'invalid-password' ? ' aria-invalid="true" autofocus' : '')
            . '></div><button class="sf-button" type="submit">Unlock</button></div>'
            . ($status === 'invalid-password'
                ? '<p class="sf-error" role="alert">That password didn&#039;t work.</p>'
                : '')
            . '</form>';
    }
    if ($lanes['request'] && !$lanes['password']) {
        $html .= _stattic_access_request_form($returnTo, 'Request access');
    }
    $html .= '</div>';
    $statusFragment = $status === 'invalid-password' ? '' : _stattic_access_status_fragment($status);
    _stattic_serve_page('access', [
        'status' => 401,
        'headers' => _stattic_unlock_response_headers(),
        'private' => true,
        'message' => 'This Space is locked.',
        'code' => 'access_locked',
        'customizable' => true,
        'serving' => $serving,
        'fragment' => $statusFragment . $html,
        'status_fragment' => $statusFragment,
        'lanes_fragment' => $html,
        'title' => is_string($descriptor['displayName']) ? $descriptor['displayName'] : '',
        'request_path' => parse_url($returnTo, PHP_URL_PATH) ?: '/',
    ]);
    exit;
}

function _stattic_access_handle_unlock_post(array $serving, string $requestHost, string $privateRoot): never
{
    $host = _stattic_canonicalize_host($requestHost);
    $returnTo = _stattic_safe_return_path(
        is_string($_POST['return'] ?? null) ? (string) $_POST['return'] : '/'
    ) ?? '/';
    // A share link from the `/__#<token>` trampoline: the same exchange the
    // query lane uses, here from a POST body so the token never sat in a URL.
    $link = is_string($_POST['link'] ?? null) ? trim((string) $_POST['link']) : '';
    if ($link !== '') {
        _stattic_visitor_lane_begin($privateRoot);
        if (!_stattic_access_same_origin_post($requestHost)) {
            _stattic_render_json_or_deny('access_origin_invalid', 'Cross-origin access submissions are not accepted.');
        }
        if (preg_match('/\Asfl_[A-Za-z0-9_-]{16,512}\z/D', $link) !== 1) {
            _stattic_render_access_route_not_found();
        }
        // Trampoline links carry their own return: the link's landing path
        // decides when the page named none beyond `/`.
        $requested = ($_POST['return'] ?? null) === null ? null : $returnTo;
        _stattic_access_redeem_link_entry_post($serving, $requestHost, $link, $requested);
    }
    if (!_stattic_unlock_csrf_valid($serving, $host, $_POST['csrf'] ?? null)) {
        _stattic_render_json_or_deny('access_csrf_invalid', 'Reload the page and try again.');
    }
    // The rest is the password lane, unchanged: same exchange, same handoff.
    $_POST['return'] = $returnTo;
    _stattic_access_handle_password($serving, $requestHost, $privateRoot, STATTIC_ACCESS_UNLOCK_PATH);
}

// ---- `/__` and `/__#<token>`: the share-link trampoline ----
//
// A share link is `https://<host>/__#sfl_…`. The token never leaves the
// browser in a request line, a Referer or a CDN log; mail scanners and link
// unfurlers fetch `/__` and get the same small, cacheable page as everyone
// else, so a limited link is never spent by a robot. The page reads the
// fragment, scrubs it from the address bar, and POSTs the token to
// `/__spacefast/unlock`. A link with a use limit or an email check (`!` after
// the token, `/__#sfl_…!`) waits for one click on Continue instead.
function _stattic_access_handle_link_trampoline(): never
{
    $method = _stattic_runtime_request_method();
    if ($method !== 'GET' && $method !== 'HEAD') {
        _stattic_method_not_allowed('GET, HEAD');
    }
    $script = '(function(){'
        . 'var h=location.hash.slice(1),m=/^(sfl_[A-Za-z0-9_-]{16,512})(!?)(?:\?r=([^#]*))?$/.exec(h);'
        . 'try{history.replaceState(null,"",location.pathname)}catch(e){}'
        . 'var f=document.getElementById("sf-link"),c=document.getElementById("sf-link-continue"),n=document.getElementById("sf-link-none");'
        . 'if(!m||!f){if(n)n.hidden=false;return;}'
        . 'f.elements.link.value=m[1];'
        . 'var r="/";try{r=decodeURIComponent(m[3]||"/")}catch(e){}'
        . 'if(/^\/(?![\/\\\\])/.test(r))f.elements["return"].value=r;else f.elements["return"].disabled=true;'
        . 'if(m[3]===undefined)f.elements["return"].disabled=true;'
        . 'if(m[2]==="!"){c.hidden=false;return;}'
        . 'f.submit();'
        . '})();';
    $hash = base64_encode(hash('sha256', $script, true));
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">'
        . '<title>Opening…</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#fff;color:#111}'
        . '@media(prefers-color-scheme:dark){body{background:#111;color:#eee}}'
        . 'main{max-width:22rem;padding:24px;text-align:center}button{font:inherit;padding:12px 20px;border:0;border-radius:999px;background:#1efe57;color:#030303;cursor:pointer;width:100%}</style>'
        . '</head><body><main>'
        . '<form id="sf-link" method="post" action="' . STATTIC_ACCESS_UNLOCK_PATH . '">'
        . '<input type="hidden" name="link" value=""><input type="hidden" name="return" value="/">'
        . '<p id="sf-link-continue" hidden>Someone shared a private link with you.<br><br><button type="submit">Continue</button></p>'
        . '</form>'
        . '<p id="sf-link-none" hidden>This link is incomplete. Ask whoever sent it for a new one.</p>'
        . '<noscript><p>Turn on JavaScript to open this link.</p></noscript>'
        . '</main><script>' . $script . '</script></body></html>';
    _stattic_response_send(200, $method === 'HEAD' ? '' : $html, 'text/html; charset=utf-8', [
        // The same bytes for every visitor and every link: the token is never
        // part of the request, so the edge may keep one copy.
        'Cache-Control' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL,
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; script-src 'sha256-" . $hash . "'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
    ]);
}

/**
 * The link lane from a POST body: the exchange, session cookie and clean
 * redirect `_stattic_access_redeem_link_entry` gives a GET, without the GET's
 * method check.
 */
function _stattic_access_redeem_link_entry_post(
    array $serving,
    string $requestHost,
    string $token,
    ?string $requestedReturn
): never {
    $redeemed = _stattic_access_exchange_link_token($serving, $requestHost, $token);
    $returnPath = $requestedReturn ?? (is_array($redeemed) && is_array($redeemed['fields'] ?? null)
        && is_string($redeemed['fields']['return'] ?? null)
        ? _stattic_safe_return_path($redeemed['fields']['return'])
        : null) ?? '/';
    if (is_array($redeemed) && $redeemed['status'] === 200 && $redeemed['handoff'] !== '') {
        $credential = _stattic_access_consume_handoff_token($serving, $redeemed['host'], $redeemed['handoff']);
        if ($credential !== null) {
            _stattic_access_set_session_cookie($credential);
            _stattic_access_redirect($returnPath, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
        }
    }
    if (is_array($redeemed)) {
        _stattic_access_gate_email_verification(
            $serving,
            $requestHost,
            $redeemed['body'],
            $redeemed['exchange']['emailUrl'] ?? null,
            $returnPath
        );
    }
    $exchangeStatus = is_array($redeemed) ? $redeemed['status'] : 0;
    _stattic_access_redirect(
        _stattic_access_url_with_params(STATTIC_ACCESS_UNLOCK_PATH, [
            'return' => $returnPath,
            'sf_access' => $exchangeStatus === 429
                ? 'rate-limited'
                : ($exchangeStatus === 0 || $exchangeStatus >= 500 ? 'exchange-unavailable' : 'link-invalid'),
        ]),
        303,
        STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        true
    );
}

// ---- Link previews of a private Space ----
//
// A chat app that unfurls a link to a private page gets a small card instead
// of the wall: the public face the owner chose for the Space (its public title,
// description and image), else the hostname, else, with public details hidden,
// "Private Space on Spacefast". Never the Space's private name, a page path,
// a screenshot or any of the page's body. Everyone else keeps the 403.

// Matched case-insensitively against the User-Agent. Each one fetches a link
// to draw its preview and never runs it in a browser.
const STATTIC_UNFURLER_AGENTS = [
    'slackbot-linkexpanding', 'slack-imgproxy', 'discordbot', 'facebookexternalhit',
    'twitterbot', 'whatsapp', 'telegrambot', 'linkedinbot', 'applebot',
];

function _stattic_request_is_unfurler(): bool
{
    $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $agent !== '' && array_any(
        STATTIC_UNFURLER_AGENTS,
        static fn (string $needle): bool => str_contains($agent, $needle)
    );
}

/**
 * The public face a link preview may show, from `accessPage.publicPreview`
 * (what the owner set as public: title, description, image). Only an https
 * image is kept. `hideDetails` replaces all of it with the opaque card.
 *
 * @return array{title: string, description: ?string, image: ?string}
 */
function _stattic_access_public_preview(array $serving, string $host): array
{
    $projection = is_array($serving['authorization'] ?? null) ? $serving['authorization'] : [];
    $raw = is_array($projection['accessPage']['publicPreview'] ?? null)
        ? $projection['accessPage']['publicPreview']
        : [];
    if (($raw['hideDetails'] ?? false) === true) {
        require_once __DIR__ . '/../shared/brand.php';
        // The platform's own name, a partner's white label included.
        return ['title' => 'Private Space on ' . _stattic_brand_value('name'), 'description' => null, 'image' => null];
    }
    $text = static function (mixed $value, int $max): ?string {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value));
        return $clean === '' ? null : mb_substr($clean, 0, $max);
    };
    // A path on this host is made absolute here: a preview needs a full URL.
    $rawImage = is_string($raw['image'] ?? null) ? $raw['image'] : '';
    if (preg_match('~\A/(?![/\\\\])\S*\z~', $rawImage) === 1) {
        $rawImage = 'https://' . $host . $rawImage;
    }
    $image = str_starts_with($rawImage, 'https://') && filter_var($rawImage, FILTER_VALIDATE_URL) !== false
        ? substr($rawImage, 0, 2048)
        : null;
    return [
        'title' => $text($raw['title'] ?? null, 200) ?? $host,
        'description' => $text($raw['description'] ?? null, 500),
        'image' => $image,
    ];
}

function _stattic_access_render_unfurl_card_if_unfurler(array $serving, string $requestHost): void
{
    $method = _stattic_runtime_request_method();
    if (
        ($method !== 'GET' && $method !== 'HEAD')
        || !_stattic_request_is_unfurler()
        || _stattic_visitor_cookie_from_request() !== ''
        || _stattic_platform_bearer_token_from_request() !== null
    ) {
        return;
    }
    require_once __DIR__ . '/../shared/brand.php';
    $host = _stattic_canonicalize_host($requestHost);
    $preview = _stattic_access_public_preview($serving, $host);
    $meta = static fn (string $property, string $content): string =>
        '<meta property="' . $property . '" content="' . _stattic_html_escape($content) . '">';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . _stattic_html_escape($preview['title']) . '</title>'
        . $meta('og:title', $preview['title'])
        . $meta('og:site_name', _stattic_brand_value('name'))
        . $meta('og:type', 'website')
        . ($preview['description'] === null ? '' : $meta('og:description', $preview['description'])
            . '<meta name="description" content="' . _stattic_html_escape($preview['description']) . '">')
        . ($preview['image'] === null
            ? '<meta name="twitter:card" content="summary">'
            : $meta('og:image', $preview['image']) . '<meta name="twitter:card" content="summary_large_image">')
        . '</head><body></body></html>';
    _stattic_response_send(200, $method === 'HEAD' ? '' : $html, 'text/html; charset=utf-8', [
        // A different answer per User-Agent: never a shared copy.
        'Cache-Control' => STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        'Vary' => 'User-Agent',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
    ]);
}

// A Space that went from public to private keeps its public copies in every
// browser that read it: the HTTP cache, storage the page wrote, a service
// worker. For a week after the switch, the gate tells each browser that
// arrives to drop them. The gate is already uncacheable, so the header reaches
// only the browser that asked, and only on this host.
const STATTIC_PUBLIC_TO_PRIVATE_CLEAR_SECONDS = 7 * 86400;

function _stattic_access_clear_public_site_data(array $serving): void
{
    $since = $serving['private_since'] ?? null;
    if (
        !is_int($since)
        || time() - $since > STATTIC_PUBLIC_TO_PRIVATE_CLEAR_SECONDS
        || _stattic_visitor_cookie_from_request() !== ''
    ) {
        return;
    }
    // Not "cookies": a browser arriving here carries no session, and an
    // unrelated cookie (the returning-account marker) must keep working. Not
    // "executionContexts" either: reloading every tab of the host is more than
    // dropping its copies.
    header('Clear-Site-Data: "cache", "storage"', true);
}

// ---- An unclaimed preview's Terms ----
//
// The first browser to open an anonymous publish agrees to the Terms on a
// platform page (`previewTerms.url`), which comes back with a short-lived
// signed acceptance (`?sf_terms=`). The engine checks it and remembers, in a
// cookie bound to this Space and host, which Terms version this browser agreed
// to. When the Terms change, every browser is asked again.

const STATTIC_TERMS_ACCEPTANCE_QUERY = 'sf_terms';
const STATTIC_TERMS_ACCEPTANCE_PURPOSE = 'terms-acceptance';
const STATTIC_TERMS_COOKIE_PREFIX = '__Host-spacefast_terms_';
const STATTIC_TERMS_DEV_COOKIE_PREFIX = 'spacefast_terms_dev_';
const STATTIC_TERMS_COOKIE_SECONDS = 31536000;

/** The Terms version this browser agreed to on this Space and host, or null. */
function _stattic_preview_terms_accepted(array $serving, string $host): ?string
{
    $name = _stattic_unlock_cookie_name($serving, STATTIC_TERMS_COOKIE_PREFIX, STATTIC_TERMS_DEV_COOKIE_PREFIX);
    $cookie = $_COOKIE[$name] ?? null;
    $payload = is_string($cookie)
        ? _stattic_signed_cookie_decode($serving, $host, 'sft1_', 'preview-terms', 256, $cookie)
        : null;
    return is_array($payload) && is_string($payload['v'] ?? null) ? $payload['v'] : null;
}

/**
 * Called once a page is admitted. Returns when this browser agreed to the
 * current Terms (or the Space has none); otherwise it takes the acceptance
 * the Terms page sent back, or sends a page navigation there. Subresources
 * pass, so a page that is being agreed to never breaks its own assets.
 */
function _stattic_access_enforce_preview_terms(array $serving, string $requestHost): void
{
    $descriptor = _stattic_access_page_descriptor($serving);
    $terms = is_array($descriptor) ? ($descriptor['previewTerms'] ?? null) : null;
    if (!is_array($terms)) {
        return;
    }
    $host = _stattic_canonicalize_host($requestHost);
    $token = $_GET[STATTIC_TERMS_ACCEPTANCE_QUERY] ?? null;
    if (is_string($token) && $token !== '') {
        $verified = _stattic_visitor_verify(
            $token,
            _stattic_visitor_verify_options($serving, $host, null, [
                'requireJti' => true,
                'generation' => null,
                'spaceIdClaim' => false,
            ])
        );
        $claims = is_array($verified['claims'] ?? null) ? $verified['claims'] : [];
        $clean = _stattic_access_clean_return_path_without(STATTIC_TERMS_ACCEPTANCE_QUERY);
        if (
            ($claims['purpose'] ?? null) === STATTIC_TERMS_ACCEPTANCE_PURPOSE
            && ($claims['termsVersion'] ?? null) === $terms['version']
        ) {
            $cookie = _stattic_signed_cookie_encode($serving, $host, 'sft1_', 'preview-terms', 256, ['v' => $terms['version']]);
            if ($cookie !== null) {
                _stattic_set_cookie(
                    _stattic_unlock_cookie_name($serving, STATTIC_TERMS_COOKIE_PREFIX, STATTIC_TERMS_DEV_COOKIE_PREFIX),
                    $cookie,
                    STATTIC_TERMS_COOKIE_SECONDS
                );
            }
        }
        // Accepted or not, the token leaves the address bar now.
        _stattic_access_redirect($clean, 303, STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, true);
    }
    if (
        _stattic_preview_terms_accepted($serving, $host) === $terms['version']
        || !_stattic_request_is_document_navigation()
    ) {
        return;
    }
    // `host` brings the browser back to this host (a version host included);
    // the control plane checks it against the Space's own hosts.
    // The Terms page takes a return of up to 2048 characters; a longer one
    // would be refused there, so the browser comes back to the Space's root.
    $return = _stattic_access_clean_return_path();
    _stattic_access_redirect(
        _stattic_access_url_with_params($terms['url'], [
            'return' => strlen($return) <= 2048 ? $return : '/',
            'host' => $host,
        ]),
        302,
        STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
        true
    );
}

/** This request's own path and query, minus one parameter. */
function _stattic_access_clean_return_path_without(string $param): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $params);
    unset($params[$param]);
    $query = http_build_query($params);
    return _stattic_safe_return_path((is_string($path) && $path !== '' ? $path : '/') . ($query !== '' ? '?' . $query : '')) ?? '/';
}
