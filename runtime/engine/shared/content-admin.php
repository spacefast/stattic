<?php
declare(strict_types=1);

const SPACEFAST_CONTENT_ADMIN_COOKIE = '__Host-spacefast_content_admin';
const SPACEFAST_CONTENT_ADMIN_TICKET_TTL = 60;
const SPACEFAST_CONTENT_ADMIN_SESSION_TTL = 3600;

function _stattic_content_admin_cookie_name(): string
{
    return function_exists('_stattic_cookies_secure') && !_stattic_cookies_secure()
        ? 'spacefast_content_admin'
        : SPACEFAST_CONTENT_ADMIN_COOKIE;
}

function _stattic_content_admin_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

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

function _stattic_content_admin_identity(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $subject = trim((string) ($value['subject'] ?? ''));
    if (preg_match('/^content_[a-f0-9]{64}$/', $subject) !== 1) {
        return null;
    }
    return ['subject' => $subject];
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

function _stattic_content_admin_read_authorization(string $privateRoot, string $spaceId): ?array
{
    if (!_stattic_id_valid($spaceId)) {
        return null;
    }
    $read = _sf_pointer_read(
        'content-admin-authorization:' . $spaceId,
        _stattic_content_admin_authorization_path($privateRoot, $spaceId)
    );
    return $read['kind'] === 'present'
        ? _stattic_content_admin_authorization($read['value'] ?? null)
        : null;
}

function _stattic_content_admin_apply_authorization(
    string $privateRoot,
    mixed $value
): ?array {
    $authorization = _stattic_content_admin_authorization($value);
    if ($authorization === null) {
        return null;
    }
    return _stattic_space_write_lock_with(
        $privateRoot,
        $authorization['space_id'],
        STATTIC_LOCK_WAIT,
        static fn (): null => null,
        static function () use ($privateRoot, $authorization): ?array {
            $current = _stattic_content_admin_read_authorization(
                $privateRoot,
                $authorization['space_id']
            );
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
    );
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

function _stattic_content_admin_mint_ticket(
    string $privateRoot,
    string $host,
    array $identity,
    array $authorization,
    string $frameOrigin,
    ?int $now = null
): ?array {
    $identity = _stattic_content_admin_identity($identity);
    $authorization = _stattic_content_admin_authorization($authorization);
    $frameOrigin = _stattic_content_admin_frame_origin($frameOrigin);
    $host = strtolower(trim($host));
    if ($identity === null || $authorization === null || $frameOrigin === null || $host === '') {
        return null;
    }
    $now ??= time();
    $store = _stattic_content_admin_ticket_store($privateRoot);
    _stattic_record_store_ensure($store);
    _stattic_record_store_sweep($store, $now);
    for ($attempt = 0; $attempt < 3; $attempt += 1) {
        $token = _stattic_content_admin_base64url_encode(random_bytes(32));
        $id = hash('sha256', $token);
        $expiresAt = $now + SPACEFAST_CONTENT_ADMIN_TICKET_TTL;
        if (_stattic_record_store_claim($store, $id, [
            'host' => $host,
            'identity' => $identity,
            'authorization' => $authorization,
            'frame_origin' => $frameOrigin,
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
                || !hash_equals((string) ($record['host'] ?? ''), strtolower(trim($host)))
            ) {
                return null;
            }
            $identity = _stattic_content_admin_identity($record['identity'] ?? null);
            $authorization = _stattic_content_admin_authorization($record['authorization'] ?? null);
            $frameOrigin = _stattic_content_admin_frame_origin($record['frame_origin'] ?? null);
            return $identity === null || $authorization === null || $frameOrigin === null
                ? null
                : [
                    'identity' => $identity,
                    'authorization' => $authorization,
                    'frame_origin' => $frameOrigin,
                ];
        }
    );
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
    array $authorization,
    string $frameOrigin,
    ?int $now = null
): ?array {
    $authorization = _stattic_content_admin_authorization($authorization);
    $frameOrigin = _stattic_content_admin_frame_origin($frameOrigin);
    if ($userId < 1 || trim($host) === '' || $authorization === null || $frameOrigin === null) {
        return null;
    }
    $secret = _stattic_content_admin_session_secret($privateRoot);
    if ($secret === null) {
        return null;
    }
    $now ??= time();
    $expiresAt = $now + SPACEFAST_CONTENT_ADMIN_SESSION_TTL;
    $payload = _stattic_content_admin_base64url_encode((string) json_encode([
        'host' => strtolower(trim($host)),
        'user_id' => $userId,
        'space_id' => $authorization['space_id'],
        'access_generation' => $authorization['access_generation'],
        'frame_origin' => $frameOrigin,
        'expires_at' => $expiresAt,
    ], JSON_UNESCAPED_SLASHES));
    return [
        'token' => $payload . '.' . _stattic_content_admin_base64url_encode(hash_hmac('sha256', $payload, $secret, true)),
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
        || !hash_equals((string) ($claims['host'] ?? ''), strtolower(trim($host)))
    ) {
        return null;
    }
    $authorization = _stattic_content_admin_authorization($claims);
    $frameOrigin = _stattic_content_admin_frame_origin($claims['frame_origin'] ?? null);
    if (
        $authorization === null
        || $frameOrigin === null
        || !_stattic_content_admin_authorization_matches($privateRoot, $authorization)
    ) {
        return null;
    }
    return [
        'user_id' => $claims['user_id'],
        'space_id' => $authorization['space_id'],
        'access_generation' => $authorization['access_generation'],
        'frame_origin' => $frameOrigin,
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
    return $path === '/wp-admin'
        || str_starts_with($path, '/wp-admin/')
        || $path === '/wp-json'
        || str_starts_with($path, '/wp-json/')
        || ($path === '/' && isset($query['rest_route']));
}

/**
 * Establish the WordPress context the content editor runs inside: the frame
 * policy, the request's Space, and the constants that keep a WordPress install
 * from editing or updating itself. Both editor entries — the ticket redemption
 * at /__spacefast/content-admin.php and the session-bound /wp-admin lane in
 * custom-redirects.php — call this and nothing else, so the two can never
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
    ?string $frameOrigin
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
    foreach ([
        'DISALLOW_FILE_EDIT' => true,
        'DISALLOW_FILE_MODS' => true,
        'AUTOMATIC_UPDATER_DISABLED' => true,
        'WP_AUTO_UPDATE_CORE' => false,
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
function _stattic_content_admin_platform_hold(string $privateRoot, string $requestHost): ?string
{
    $target = _stattic_content_access_target($privateRoot, $requestHost);
    $hold = $target['kind'] === 'absent' ? ($target['hold'] ?? null) : null;
    return is_string($hold) ? $hold : null;
}
