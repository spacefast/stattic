<?php
declare(strict_types=1);

require_once __DIR__ . '/space-roles.php';

const SPACEFAST_CONTENT_ADMIN_COOKIE = '__Host-spacefast_content_admin';
const SPACEFAST_CONTENT_ADMIN_TICKET_TTL = 60;
const SPACEFAST_CONTENT_ADMIN_SESSION_TTL = 3600;

function _stattic_content_admin_cookie_name(): string
{
    return function_exists('_stattic_cookies_secure') && !_stattic_cookies_secure()
        ? 'spacefast_content_admin'
        : SPACEFAST_CONTENT_ADMIN_COOKIE;
}

// Strict where context.php's _stattic_base64url_decode is lenient: a token
// segment outside the url-safe alphabet is a forgery, never a decode to ''.
function _stattic_content_admin_base64url_decode(string $value): ?string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return null;
    }
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    return is_string($decoded) ? $decoded : null;
}

function _stattic_content_admin_ticket_store(string $privateRoot): array
{
    $root = rtrim($privateRoot, '/') . '/runtime/content-admin-tickets';
    return _stattic_record_store($root, [
        'retention' => [
            'field' => 'expires_at',
            'field_seconds' => 0,
            'throttle_seconds' => 60,
            'marker' => rtrim($privateRoot, '/') . '/runtime/content-admin-ticket-sweep',
        ],
    ]);
}

function _stattic_content_admin_frame_origin(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    $origin = _stattic_absolute_url_origin($value);
    return is_string($origin) && hash_equals($origin, $value) ? $origin : null;
}

/**
 * The screen a launch lands on, from a closed set.
 *
 * A screen is both a Zero route and part of the signed access grant. It is a
 * closed set so a ticket cannot be talked into landing on, or authorizing, a
 * screen its issuer did not name.
 */
function _stattic_content_admin_screen(mixed $value): ?string
{
    return is_string($value) && in_array($value, ['collections', 'users'], true) ? $value : null;
}

function _stattic_content_admin_access(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $surface = $value['surface'] ?? null;
    if ($surface === 'wordpress') {
        $path = $value['path'] ?? null;
        if ($path === null) {
            return ['surface' => 'wordpress'];
        }
        if (!is_string($path) || strlen($path) > 4096 || preg_match('/[\\\\#\x00-\x20]/', $path)
            || ($path !== '/wp-admin' && !str_starts_with($path, '/wp-admin/'))) {
            return null;
        }
        $pathname = explode('?', $path, 2)[0];
        for ($i = 0; $i < 4; $i++) {
            $pathname = rawurldecode($pathname);
        }
        if (preg_match('#(?:^|/)\.\.?(?:/|$)#', $pathname) || preg_match('/[\\\\#\x00-\x20]/', $pathname)) {
            return null;
        }
        return ['surface' => 'wordpress', 'path' => $path];
    }
    if ($surface !== 'zero') {
        return null;
    }
    $initialScreen = _stattic_content_admin_screen($value['initialScreen'] ?? $value['initial_screen'] ?? null);
    $requestedScreens = $value['allowedScreens'] ?? $value['allowed_screens'] ?? null;
    if ($initialScreen === null || !is_array($requestedScreens)) {
        return null;
    }
    $allowedScreens = [];
    foreach ($requestedScreens as $screen) {
        $screen = _stattic_content_admin_screen($screen);
        if ($screen === null) {
            return null;
        }
        $allowedScreens[] = $screen;
    }
    if (
        ($allowedScreens !== ['collections'] && $allowedScreens !== ['collections', 'users'])
        || !in_array($initialScreen, $allowedScreens, true)
    ) {
        return null;
    }
    return [
        'surface' => 'zero',
        'initial_screen' => $initialScreen,
        'allowed_screens' => $allowedScreens,
    ];
}

function _stattic_content_admin_authorization(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $spaceId = trim((string) ($value['spaceId'] ?? $value['space_id'] ?? ''));
    $accessGeneration = $value['accessGeneration'] ?? $value['access_generation'] ?? null;
    if (!_stattic_id_valid($spaceId) || !is_int($accessGeneration) || $accessGeneration < 0) {
        return null;
    }
    return ['space_id' => $spaceId, 'access_generation' => $accessGeneration];
}

function _stattic_content_admin_authorization_path(string $privateRoot, string $spaceId): string
{
    return rtrim($privateRoot, '/') . '/spaces/' . $spaceId . '/content-admin-authorization.json';
}

/**
 * The access record stored for this Space, if it is this Space's: a record
 * that doesn't parse, or names another Space, is nobody's.
 */
function _stattic_content_admin_stored_authorization(array $read, string $spaceId): ?array
{
    if ($read['kind'] !== 'present' || !is_string($read['value']['space_id'] ?? $read['value']['spaceId'] ?? null)) {
        return null;
    }
    $stored = _stattic_content_admin_authorization($read['value']);
    return $stored !== null && $stored['space_id'] === $spaceId ? $stored : null;
}

function _stattic_content_admin_read_authorization(string $privateRoot, string $spaceId): ?array
{
    if (!_stattic_id_valid($spaceId)) {
        return null;
    }
    return _stattic_content_admin_stored_authorization(
        _sf_pointer_read(
            'content-admin-authorization:' . $spaceId,
            _stattic_content_admin_authorization_path($privateRoot, $spaceId)
        ),
        $spaceId
    );
}

/**
 * Apply an access generation under the Space's write lock. Returns the
 * generation now on record, null for a value that is not an authorization,
 * 'unavailable' when the lock can't be taken, or 'unreadable' when the stored
 * record can't be read or isn't this Space's (it is left as it is).
 */
function _stattic_content_admin_apply_authorization(
    string $privateRoot,
    mixed $value
): array|string|null {
    $authorization = _stattic_content_admin_authorization($value);
    if ($authorization === null) {
        return null;
    }
    return _stattic_space_write_lock_with(
        $privateRoot,
        $authorization['space_id'],
        STATTIC_LOCK_WAIT,
        static fn (): string => 'unavailable',
        static fn (): array|string|null => _stattic_content_admin_apply_authorization_locked($privateRoot, $authorization)
    );
}

/** Answer a box outage from `_stattic_content_admin_apply_authorization` as retryable. */
function _stattic_content_admin_authorization_outage(array|string|null $applied): void
{
    if ($applied === 'unavailable') {
        _stattic_problem_response(503, 'runtime_write_lock_unavailable', 'Runtime write lock is unavailable.', [
            'details' => ['timeout_ms' => STATTIC_LOCK_DEADLINE_MS],
        ]);
    }
    if ($applied === 'unreadable') {
        _stattic_problem_response(503, 'content_admin_authorization_unavailable', 'The Space access record could not be read.');
    }
}

/**
 * Apply an access generation if it is newer than the one on record. Call only
 * under the Space's write lock. Returns the generation now on record.
 */
function _stattic_content_admin_apply_authorization_locked(string $privateRoot, array $authorization): array|string|null
{
    // An access record that exists but can't be read is never overwritten:
    // the newer generation it may hold would be lost.
    $read = _sf_pointer_read(
        'content-admin-authorization:' . $authorization['space_id'],
        _stattic_content_admin_authorization_path($privateRoot, $authorization['space_id'])
    );
    if ($read['kind'] !== 'present' && $read['kind'] !== 'absent') {
        return 'unreadable';
    }
    $current = _stattic_content_admin_stored_authorization($read, $authorization['space_id']);
    // A record that doesn't parse, or names another Space, is not this
    // Space's: never compared against, never overwritten.
    if ($read['kind'] === 'present' && $current === null) {
        return 'unreadable';
    }
    if (
        $current !== null
        && $current['access_generation'] >= $authorization['access_generation']
    ) {
        return $current;
    }
    _stattic_runtime_write_json_atomic(
        _stattic_content_admin_authorization_path($privateRoot, $authorization['space_id']),
        $authorization
    );
    return $authorization;
}

function _stattic_content_admin_authorization_matches(
    string $privateRoot,
    array $authorization
): bool {
    $current = _stattic_content_admin_read_authorization(
        $privateRoot,
        (string) ($authorization['space_id'] ?? '')
    );
    return $current !== null
        && $current['access_generation'] === ($authorization['access_generation'] ?? null);
}

function _stattic_content_wordpress_role(mixed $value): ?string
{
    return is_string($value) && in_array($value, ['subscriber', 'editor', 'administrator'], true)
        ? $value
        : null;
}

/**
 * The runtime half of THE grant→WordPress-role mapping. The other half is
 * wordpressRoleForGrantCapabilities in
 * packages/common/src/contracts/principal-assertion.ts. Both read the one role
 * table (shared/space-roles.generated.php is generated from it), and
 * runtime/tests/content-admin.test.ts runs them side by side.
 *
 * Two callers exist because the two lanes learn the capabilities at different
 * moments. The control plane knows them when it composes a principal assertion
 * for the content API and the editor launch. The WP API door has no control
 * plane in the request at all: the capabilities come from the Grant decision
 * the runtime just made for THIS path, so the runtime has to map them itself.
 */
function _stattic_wordpress_role_for_capabilities(array $capabilities): ?string
{
    $role = _stattic_space_role_for_capabilities($capabilities);
    $reads = in_array('page.view', $capabilities, true) || in_array('comments.read', $capabilities, true);
    if (_stattic_space_role_rank($role) <= _stattic_space_role_rank('commenter') && !$reads) {
        return null;
    }
    return SPACEFAST_SPACE_ROLE_WORDPRESS_ROLES[$role];
}

function _stattic_content_admin_mint_ticket(
    string $privateRoot,
    string $host,
    array $principal,
    array $authorization,
    string $wordpressRole,
    string $frameOrigin,
    mixed $access,
    string $publicOrigin,
    ?int $now = null
): ?array {
    $principal = isset($principal['kind']) ? $principal : null;
    $authorization = _stattic_content_admin_authorization($authorization);
    $wordpressRole = _stattic_content_wordpress_role($wordpressRole) ?? '';
    $frameOrigin = _stattic_content_admin_frame_origin($frameOrigin);
    $publicOrigin = _stattic_content_admin_frame_origin($publicOrigin);
    $access = _stattic_content_admin_access($access);
    if (
        !is_array($principal)
        || $authorization === null
        || $wordpressRole === ''
        || $frameOrigin === null
        || $publicOrigin === null
        || $access === null
        || $host === ''
    ) {
        return null;
    }
    $now ??= time();
    $store = _stattic_content_admin_ticket_store($privateRoot);
    _stattic_record_store_ensure($store);
    _stattic_record_store_sweep($store, $now);
    for ($attempt = 0; $attempt < 3; $attempt += 1) {
        $token = _stattic_base64url_encode(random_bytes(32));
        $id = hash('sha256', $token);
        $expiresAt = $now + SPACEFAST_CONTENT_ADMIN_TICKET_TTL;
        if (_stattic_record_store_claim($store, $id, [
            'host' => $host,
            'principal' => $principal,
            'authorization' => $authorization,
            'wordpress_role' => $wordpressRole,
            'frame_origin' => $frameOrigin,
            'public_origin' => $publicOrigin,
            'access' => $access,
            'expires_at' => $expiresAt,
        ], $expiresAt)) {
            return ['token' => $token, 'expires_at' => $expiresAt];
        }
    }
    return null;
}

function _stattic_content_admin_consume_ticket(
    string $privateRoot,
    string $token,
    string $host,
    ?int $now = null
): ?array {
    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
        return null;
    }
    $now ??= time();
    $store = _stattic_content_admin_ticket_store($privateRoot);
    return _stattic_record_store_mutate(
        $store,
        hash('sha256', $token),
        static function (?array $record) use ($store, $token, $host, $now): ?array {
            if ($record !== null) {
                _stattic_record_store_delete($store, hash('sha256', $token));
            }
            if (
                $record === null
                || !is_int($record['expires_at'] ?? null)
                || $record['expires_at'] <= $now
                || !hash_equals((string) ($record['host'] ?? ''), $host)
            ) {
                return null;
            }
            $principal = is_array($record['principal'] ?? null) ? $record['principal'] : null;
            $authorization = _stattic_content_admin_authorization($record['authorization'] ?? null);
            $wordpressRole = _stattic_content_wordpress_role($record['wordpress_role'] ?? null);
            $frameOrigin = _stattic_content_admin_frame_origin($record['frame_origin'] ?? null);
            $publicOrigin = _stattic_content_admin_frame_origin($record['public_origin'] ?? null);
            $access = _stattic_content_admin_access($record['access'] ?? null);
            return $principal === null || $authorization === null || $wordpressRole === null || $frameOrigin === null || $publicOrigin === null || $access === null
                ? null
                : [
                    'principal' => $principal,
                    'authorization' => $authorization,
                    'wordpress_role' => $wordpressRole,
                    'frame_origin' => $frameOrigin,
                    'public_origin' => $publicOrigin,
                    'access' => $access,
                ];
        }
    );
}

/** The signed launch's audience: the editor door on one box, never a management call. */
const SPACEFAST_CONTENT_ADMIN_LAUNCH_AUDIENCE = 'spacefast-content-admin-launch';

/**
 * The control plane's signed editor launch, verified on this box with no call
 * back. The platform key signs it; it names this box (`runtime_instance_id`)
 * and host, lives at most 60 s, and carries the same principal assertion
 * `content.admin.launch` takes, checked by the same validator. Its `jti` is
 * burned in the management lane's replay store, so one launch opens one
 * editor: a second tab asks the control plane for a fresh launch.
 *
 * Returns what a redeemed ticket returns. `$verify` and `$now` are test seams;
 * production passes neither, so the signature check is the platform verifier.
 *
 * @param string $requestAuthority the request's Host, port included: the
 *        principal's audience is an origin
 */
function _stattic_content_admin_consume_launch(
    string $privateRoot,
    string $token,
    string $host,
    string $requestAuthority,
    ?int $now = null,
    ?callable $verify = null
): ?array {
    $now ??= time();
    $rejection = null;
    $verify ??= static function (string $candidate, array $profile) use ($privateRoot, &$rejection): ?array {
        return _stattic_runtime_token_claims(
            $privateRoot,
            $candidate,
            SPACEFAST_CONTENT_ADMIN_LAUNCH_AUDIENCE,
            $profile,
            $rejection
        );
    };
    $claims = $token === '' ? null : $verify($token, [
        // A launch signed under a key this box has not seen yet (a platform
        // key rotation) reads the published set once; the platform key store
        // waits a minute between reads, so a person's click is the most
        // that ever waits on one.
        'allow_jwks_fetch' => true,
        // The platform signer names what the token is for and who issued it
        // (runtime/auth.ts signRuntimeJwt); a platform token for anything
        // else is not a launch, whatever its audience.
        'claims' => [
            'purpose' => ['equals' => 'content-admin-launch'],
            'iss' => ['equals' => 'stattic-api'],
            'jti' => ['present' => true],
        ],
        'scope_valid' => static fn (array $claims): bool => is_int($claims['exp'] ?? null)
            && is_int($claims['iat'] ?? null)
            && $claims['exp'] > $claims['iat']
            && $claims['exp'] - $claims['iat'] <= SPACEFAST_CONTENT_ADMIN_TICKET_TTL
            && is_string($claims['host'] ?? null)
            && $host !== ''
            && hash_equals(strtolower($claims['host']), $host),
    ]);
    if (!is_array($claims)) {
        // The box couldn't read the platform keys: the launch may be valid,
        // so answer retryable rather than call the link spent.
        $unverifiable = [
            'jwks_fetch_failed' => ['runtime_jwks_fetch_failed', 'Runtime JWT signing keys could not be fetched.'],
            'api_base_url_missing' => ['runtime_api_base_url_missing', 'Runtime API base URL is not configured.'],
        ][$rejection['reason'] ?? ''] ?? null;
        if ($unverifiable !== null) {
            _stattic_problem_response(500, $unverifiable[0], $unverifiable[1]);
        }
        return null;
    }
    $authorization = _stattic_content_admin_authorization($claims);
    $principal = $authorization === null ? null : _stattic_content_principal_assertion(
        $claims['principal'] ?? null,
        $authorization['space_id'],
        $requestAuthority,
        $now
    );
    $wordpressRole = _stattic_content_wordpress_role($claims['wordpress_role'] ?? null);
    $frameOrigin = _stattic_content_admin_frame_origin($claims['frame_origin'] ?? null);
    $publicOrigin = _stattic_content_admin_frame_origin($claims['public_origin'] ?? null);
    $access = _stattic_content_admin_access($claims['access'] ?? null);
    // The editor frame is for a person holding a WordPress role, the one the
    // principal itself names.
    if (
        ($principal['kind'] ?? null) !== 'user'
        || $wordpressRole === null
        || ($principal['wordpress_role'] ?? null) !== $wordpressRole
        || $principal['access_generation'] !== $authorization['access_generation']
        || $frameOrigin === null
        || $publicOrigin === null
        || $access === null
    ) {
        return null;
    }
    // A launch already spent is refused before anything is written.
    // A marker that can't be read may be a spent launch's: answer retryable,
    // writing nothing, rather than treat it as unspent.
    $spent = _stattic_jwt_jti_spent($privateRoot, SPACEFAST_CONTENT_ADMIN_LAUNCH_AUDIENCE, (string) $claims['jti'], $now);
    if ($spent === 'unavailable') {
        _stattic_problem_response(503, 'runtime_replay_guard_unavailable', 'Runtime token replay guard storage is unavailable.', [
            'details' => ['phase' => 'read', 'operation' => 'replay_marker', 'reason' => 'unreadable'],
        ]);
    }
    if ($spent === 'spent') {
        return null;
    }
    // The launch carries the platform's access generation: apply it and check
    // this launch is the current one before the launch is spent, so an access
    // change refuses it and a lock the box can't take leaves it redeemable.
    $applied = _stattic_content_admin_apply_authorization($privateRoot, $authorization);
    _stattic_content_admin_authorization_outage($applied);
    if ($applied !== $authorization) {
        return null;
    }
    // Last, after every other check, so a refused launch burns nothing. A
    // replay is a spent link; a replay store that can't be written is the
    // box's outage, answered retryable, as the management lane answers it.
    $consumed = _stattic_jwt_consume_jti(
        $privateRoot,
        SPACEFAST_CONTENT_ADMIN_LAUNCH_AUDIENCE,
        (string) $claims['jti'],
        (int) $claims['exp'],
        $now,
        $failure
    );
    if ($consumed === 'storage_full') {
        _stattic_problem_response(503, 'runtime_storage_full', "The site's disk is full.", ['details' => $failure]);
    }
    if ($consumed === 'unavailable') {
        _stattic_problem_response(503, 'runtime_replay_guard_unavailable', 'Runtime token replay guard storage is unavailable.', ['details' => $failure]);
    }
    if ($consumed !== 'ok') {
        return null;
    }
    // The lock and the key read may have waited: a launch that expired in the
    // meantime does not open, even though its claim was the first.
    if ((int) $claims['exp'] <= time() || (int) ($principal['expires_at'] ?? 0) <= time()) {
        return null;
    }
    return [
        'principal' => $principal,
        'authorization' => $authorization,
        'wordpress_role' => $wordpressRole,
        'frame_origin' => $frameOrigin,
        'public_origin' => $publicOrigin,
        'access' => $access,
    ];
}

/**
 * Where a redeemed launch lands: an explicit classic path, a Zero screen, or
 * the classic dashboard. Every one is a page the editor session opens on the
 * box host the launch was redeemed at.
 */
function _stattic_content_admin_landing(array $access): string
{
    if (is_string($access['path'] ?? null)) {
        $path = $access['path'];
        return $path . (str_contains($path, '?') ? '&' : '?') . 'classic=1';
    }
    if ($access['surface'] === 'zero') {
        return '/zero-admin' . ($access['initial_screen'] === 'users' ? '/users' : '/collections');
    }
    return '/wp-admin/index.php?classic=1';
}

function _stattic_content_admin_session_secret(string $privateRoot): ?string
{
    $hex = _stattic_lazy_minted_secret($privateRoot, 'content-admin-session-key', 32);
    if (!is_string($hex)) {
        return null;
    }
    $secret = hex2bin($hex);
    return is_string($secret) ? $secret : null;
}

function _stattic_content_admin_mint_session(
    string $privateRoot,
    string $host,
    int $userId,
    array $principal,
    array $authorization,
    string $wordpressRole,
    string $frameOrigin,
    array $access,
    string $publicOrigin,
    ?int $now = null
): ?array {
    $authorization = _stattic_content_admin_authorization($authorization);
    $principal = isset($principal['kind']) ? $principal : null;
    $wordpressRole = _stattic_content_wordpress_role($wordpressRole);
    $frameOrigin = _stattic_content_admin_frame_origin($frameOrigin);
    $publicOrigin = _stattic_content_admin_frame_origin($publicOrigin);
    $access = _stattic_content_admin_access($access);
    if (
        $userId < 1
        || trim($host) === ''
        || !is_array($principal)
        || $authorization === null
        || $wordpressRole === null
        || $frameOrigin === null
        || $publicOrigin === null
        || $access === null
    ) {
        return null;
    }
    $secret = _stattic_content_admin_session_secret($privateRoot);
    if ($secret === null) {
        return null;
    }
    $now ??= time();
    $expiresAt = $now + SPACEFAST_CONTENT_ADMIN_SESSION_TTL;
    $payload = _stattic_base64url_encode((string) json_encode([
        'host' => $host,
        'user_id' => $userId,
        'principal' => $principal,
        'space_id' => $authorization['space_id'],
        'access_generation' => $authorization['access_generation'],
        'wordpress_role' => $wordpressRole,
        'frame_origin' => $frameOrigin,
        'public_origin' => $publicOrigin,
        'access' => $access,
        'expires_at' => $expiresAt,
    ], JSON_UNESCAPED_SLASHES));
    return [
        'token' => $payload . '.' . _stattic_base64url_encode(hash_hmac('sha256', $payload, $secret, true)),
        'expires_at' => $expiresAt,
    ];
}

function _stattic_content_admin_verify_session(
    string $privateRoot,
    string $token,
    string $host,
    ?int $now = null
): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$payload, $signature] = $parts;
    $secret = _stattic_content_admin_session_secret($privateRoot);
    $provided = _stattic_content_admin_base64url_decode($signature);
    if ($secret === null || $provided === null) {
        return null;
    }
    $expected = hash_hmac('sha256', $payload, $secret, true);
    if (!hash_equals($expected, $provided)) {
        return null;
    }
    $json = _stattic_content_admin_base64url_decode($payload);
    $claims = is_string($json) ? json_decode($json, true) : null;
    $now ??= time();
    if (
        !is_array($claims)
        || !is_int($claims['user_id'] ?? null)
        || $claims['user_id'] < 1
        || !is_int($claims['expires_at'] ?? null)
        || $claims['expires_at'] <= $now
        || !hash_equals((string) ($claims['host'] ?? ''), $host)
    ) {
        return null;
    }
    $authorization = _stattic_content_admin_authorization($claims);
    $principal = is_array($claims['principal'] ?? null) ? $claims['principal'] : null;
    $wordpressRole = _stattic_content_wordpress_role($claims['wordpress_role'] ?? null);
    $frameOrigin = _stattic_content_admin_frame_origin($claims['frame_origin'] ?? null);
    $publicOrigin = _stattic_content_admin_frame_origin($claims['public_origin'] ?? null);
    $access = _stattic_content_admin_access($claims['access'] ?? null);
    if (
        $authorization === null
        || $principal === null
        || $wordpressRole === null
        || $frameOrigin === null
        || $publicOrigin === null
        || $access === null
        || !_stattic_content_admin_authorization_matches($privateRoot, $authorization)
    ) {
        return null;
    }
    return [
        'user_id' => $claims['user_id'],
        'principal' => $principal,
        'space_id' => $authorization['space_id'],
        'access_generation' => $authorization['access_generation'],
        'wordpress_role' => $wordpressRole,
        'frame_origin' => $frameOrigin,
        'public_origin' => $publicOrigin,
        'access' => $access,
        'expires_at' => $claims['expires_at'],
    ];
}

/**
 * THE editor-lane request predicate. `/wp-json` is in it because WordPress's
 * own admin screens call the REST API for everything they save, and
 * `/?rest_route=` because that is where the REST API answers when pretty
 * permalinks are off — the three are one lane, and whatever gate answers one
 * has to answer all of them. A second, narrower spelling elsewhere is how one
 * of them ends up ungated.
 *
 * `$query` is the request's query parameters ($_GET at the gate). It stays a
 * parameter rather than a global read so the predicate is still a pure
 * function of the request.
 */
function _stattic_content_admin_request_path(string $path, array $query = []): bool
{
    return $path === '/zero-admin'
        || str_starts_with($path, '/zero-admin/')
        || $path === '/wp-login.php'
        || $path === '/wp-admin'
        || str_starts_with($path, '/wp-admin/')
        || _stattic_content_rest_request_path($path, $query);
}

/**
 * The REST subset of the editor lane — WordPress's own API, in all three
 * spellings. It lives here, immediately beside the lane predicate and expressed
 * as a term of it, because the failure the lane predicate warns about is two
 * copies drifting apart: this one can only ever be narrower by exactly
 * `/wp-admin`, and that is visible in one screen.
 *
 * The distinction is real, not a convenience. `/wp-admin` is the editor's HTML
 * surface and stays cookie-only. The REST paths are the API, and they are the
 * ones an agent reaches with a Space credential.
 */
function _stattic_content_rest_request_path(string $path, array $query = []): bool
{
    return $path === '/wp-json'
        || str_starts_with($path, '/wp-json/')
        || !empty($query['rest_route']);
}

/** Resolve the same request selector that WordPress uses before deriving a role. */
function _stattic_content_rest_access_path(string $path, array $query = []): string
{
    if ($path === '/zero-admin' || str_starts_with($path, '/zero-admin/') || empty($query['rest_route'])) {
        return $path;
    }
    $restRoute = $query['rest_route'];
    // Nonempty invalid selectors reach WordPress's REST validation; empty
    // selectors leave WordPress on its ordinary page or feed path.
    return is_string($restRoute) && $restRoute !== '' && $restRoute !== '/'
        ? '/wp-json/' . ltrim($restRoute, '/')
        : '/wp-json';
}

/**
 * Establish the WordPress context the content editor runs inside: the frame
 * policy, the request's Space, and the constants that keep a WordPress install
 * from editing or updating itself. Both editor entries — the ticket redemption
 * at /__spacefast/content-admin.php and the session-bound /wp-admin lane in
 * loader/request.php — call this and nothing else, so the two can never
 * establish subtly different contexts.
 *
 * ONE frame-origin source, in priority order: the per-request origin the
 * launch carried (ticket, then the session minted from it), because that is
 * the dashboard that actually opened this editor and it is signed. The box
 * env (_stattic_dashboard_origin) is the fallback for a request whose launch
 * origin did not survive — it is box-wide configuration, not a fact about
 * this request, so it never overrides one.
 */
function _stattic_content_admin_enter_wordpress(
    string $privateRoot,
    string $spaceId,
    ?string $frameOrigin,
    array $access,
    ?string $publicOrigin = null
): void {
    $origin = is_string($frameOrigin) && $frameOrigin !== ''
        ? $frameOrigin
        : (function_exists('_stattic_dashboard_origin') ? _stattic_dashboard_origin() : '');
    header(
        "Content-Security-Policy: frame-ancestors 'self'" . ($origin === '' ? '' : ' ' . $origin),
        true
    );
    // shared/cache-policy.php is the one Cache-Control author; the editor is
    // per-session by construction.
    header('Cache-Control: ' . (string) _stattic_cache_policy(['private' => true])['cache_control'], true);
    header('Referrer-Policy: no-referrer', true);
    $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $privateRoot;
    $GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] = $origin;
    $GLOBALS['SPACEFAST_CONTENT_ADMIN_ACCESS'] = $access;
    $GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] = $publicOrigin;
    foreach ([
        'DISALLOW_FILE_EDIT' => true,
        'DISALLOW_FILE_MODS' => true,
        'AUTOMATIC_UPDATER_DISABLED' => true,
    ] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

/**
 * The platform's own answer for this host, when it has one.
 *
 * The editor lane consults the SAME facts the visitor lane does
 * (_stattic_content_access_target), because a Space the platform has taken over
 * — tombstoned, or held behind the platform error page — must not stay editable
 * for the remainder of a session TTL while every visitor gets the platform's
 * answer. Only the platform hold refuses: an `absent` target with no hold is the
 * normal unpublished Space the editor exists to fill.
 *
 * The exposure fence is deliberately NOT consulted. The visitor lane fails
 * closed on it because it is about to serve bytes under a policy that is
 * mid-commit. An editor session is bound to an accessGeneration
 * (_stattic_content_admin_verify_session), and the same runtime sync that
 * commits the new exposure projection pushes the new generation
 * (control-plane runtime-sync `contentAuthorization`), so the session the fence
 * would have blocked is revoked by the commit the fence was announcing. Fencing
 * here would only close the editor during every routine access change.
 */
function _stattic_content_admin_platform_hold(string $privateRoot, string $requestHost, ?array $target = null): ?string
{
    $target ??= _stattic_content_access_target($privateRoot, $requestHost);
    $hold = $target['kind'] === 'absent' ? ($target['hold'] ?? null) : null;
    return is_string($hold) ? $hold : null;
}
