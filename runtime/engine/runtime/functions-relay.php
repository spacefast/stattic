<?php

/**
 * The callback surface a dispatched worker uses for brokered database access.
 * Authority travels inside the presented token; this endpoint never looks a
 * grant up. Database frames are answered in-process by the engine's own MySQL
 * broker (shared/db-broker.php), the native executor's byte-for-byte twin,
 * pinned by the db-broker.test.ts corpus. An operation costs neither a process
 * spawn nor a fresh MySQL handshake: the `p:` link outlives the request in
 * PHP's own persistent pool, the only lifetime this host allows. Service frames
 * still run the one-shot `service-broker` executor, whose cost is its outbound
 * HTTPS call and whose identity is per invocation. Every call re-enters this
 * space's PHP-FPM pool while the proxied request still occupies a worker in it,
 * the deadlock hazard the broker's one-operation lifetime bounds.
 */

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/response.php';
// Config reads answer empty until this has run, which for a credential check
// means silently refusing valid tokens.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/runtime-log.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/db-broker.php';
require_once __DIR__ . '/../shared/native-process.php';

const STATTIC_FUNCTIONS_RELAY_AUD = 'spacefast-functions-relay';

// What a frame may be granted, keyed by the broker the outbound gateway named.
// The gateway sets that header outside the isolate, so tenant code cannot
// choose its own lane by forging one. A lane that runs a native subprocess also
// names its `executor`, the env var its grant travels in, and whether it is
// handed the caller's service identity; the database lane names none of the
// three, because the engine answers its frames itself.
const SPACEFAST_FUNCTIONS_RELAY_BROKERS = [
    'database' => [
        'capabilities' => ['db.read', 'db.write'],
    ],
    'services' => [
        'executor' => 'service-broker',
        'capabilities' => ['gravatar.profile', 'spam.check', 'email.send'],
        'grant_env' => 'SPACEFAST_SERVICE_BROKER_GRANT',
        'identity' => true,
    ],
];

// Operations over the executor's 64 KiB limit still transit so the caller gets
// the named in-band refusal instead of an opaque 413.
const STATTIC_FUNCTIONS_RELAY_MAX_BODY_BYTES = 1048576;

// The dispatch proxy gives a worker 30s; a database call that outlives its
// worker has no caller left to answer.
const STATTIC_FUNCTIONS_RELAY_EXECUTOR_TIMEOUT_MS = 30000;
// Above the executor's own 10 MiB result cap plus envelope; hitting this means
// the child is broken, not the query.
const STATTIC_FUNCTIONS_RELAY_EXECUTOR_STDOUT_MAX_BYTES = 16777216;
const STATTIC_FUNCTIONS_RELAY_EXECUTOR_STDERR_MAX_BYTES = 65536;

const STATTIC_FUNCTIONS_LOG_MAX_BODY_BYTES = 262144; // 256 KiB per delivery

// Deliberately silent about why it refused: distinguishing "wrong signature"
// from "wrong version" would confirm which versions exist.
function _stattic_functions_relay_claims(
    string $privateRoot,
    string $spaceId,
    string $token
): ?array {
    return _stattic_runtime_token_claims($privateRoot, $token, STATTIC_FUNCTIONS_RELAY_AUD, [
        'scope_valid' => static function (array $claims) use ($spaceId): bool {
            if (!is_string($claims['space_id'] ?? null) || !hash_equals($spaceId, (string) $claims['space_id'])) {
                return false;
            }
            $versionId = is_string($claims['version_id'] ?? null) ? (string) $claims['version_id'] : '';
            return $versionId !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $versionId) === 1;
        },
        // Any real version of this space, not the live one: an in-flight request
        // still belongs to the version that started it. Revocation rides the
        // isolate identity, not this check.
        'state_valid' => static fn (array $claims): bool => is_dir(
            _stattic_version_root($privateRoot, $spaceId, (string) $claims['version_id'])
        ),
    ]);
}
// Fail closed: an absent or malformed claim yields an empty grant.
function _stattic_functions_relay_grant(array $claims, array $allowed): array
{
    $granted = [];
    foreach (is_array($claims['capabilities'] ?? null) ? $claims['capabilities'] : [] as $capability) {
        if (is_string($capability) && in_array($capability, $allowed, true)) {
            $granted[] = $capability;
        }
    }
    return $granted;
}

function _stattic_functions_relay_bearer(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}

/**
 * The executor's environment is built fresh, never inherited: the labeled
 * database URL, the grant, and the configured result limits are the only
 * things the child may see.
 *
 * The service broker gets the database URL too, because an accepted email is a
 * row in this space's outbox. The grant it receives names services, and an
 * ungranted one is refused inside the broker as well as here.
 */
function _stattic_functions_relay_executor_env(array $claims, string $broker, array $grant): array
{
    $definition = SPACEFAST_FUNCTIONS_RELAY_BROKERS[$broker];
    $env = _stattic_zero_runner_base_env();
    foreach (['SPACEFAST_ZERO_DB_ROWS_MAX', 'SPACEFAST_ZERO_DB_RESULT_BYTES_MAX'] as $name) {
        $value = (string) _stattic_config_value($name);
        if ($value !== '') {
            $env[$name] = $value;
        }
    }
    $env[$definition['grant_env']] = implode(',', $grant);
    if ($definition['identity']) {
        $env += _stattic_service_broker_env([
            'spaceId' => is_string($claims['space_id'] ?? null) ? $claims['space_id'] : '',
            'versionId' => is_string($claims['version_id'] ?? null) ? $claims['version_id'] : '',
            'invocationId' => _stattic_functions_relay_invocation_id(),
        ]);
    }
    return $env;
}

/**
 * The invocation this frame belongs to, as the origin named it when it
 * dispatched. It is the outbox's idempotency key, so a worker must not be able
 * to supply its own. An absent header yields an empty identity, and the broker
 * refuses to enqueue without one rather than inventing a key that would let the
 * same message land twice.
 */
function _stattic_functions_relay_invocation_id(): string
{
    return _stattic_service_invocation_id($_SERVER['HTTP_SF_FX_INVOCATION'] ?? '');
}

/**
 * The broker the gateway selected, restricted to the ones this relay runs.
 * Absent means the database broker: a dispatch from an older host names none.
 */
function _stattic_functions_relay_broker(): ?string
{
    $raw = $_SERVER['HTTP_SF_FX_BROKER'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return 'database';
    }
    return array_key_exists($raw, SPACEFAST_FUNCTIONS_RELAY_BROKERS) ? $raw : null;
}

// Never returns.
function _stattic_functions_relay_serve(string $privateRoot, string $spaceId, string $requestMethod): void
{
    if ($requestMethod !== 'POST') {
        _stattic_method_not_allowed('POST', ['code' => 'method_not_allowed', 'message' => 'Relay accepts POST.']);
    }
    $claims = _stattic_functions_relay_claims($privateRoot, $spaceId, _stattic_functions_relay_bearer());
    if ($claims === null) {
        _stattic_problem_refused(401, 'relay_unauthorized', 'Relay credential is not valid.');
    }
    $broker = _stattic_functions_relay_broker();
    if ($broker === null) {
        _stattic_problem_refused(403, 'relay_forbidden', 'Relay broker is not recognised.');
    }
    $brokerCapabilities = SPACEFAST_FUNCTIONS_RELAY_BROKERS[$broker]['capabilities'];
    // Narrowed to the selected broker before the grant is checked for
    // emptiness: a version granted only database access must not be able to
    // reach the service executor by naming it, and vice versa.
    $grant = _stattic_functions_relay_grant($claims, $brokerCapabilities);
    if ($grant === []) {
        _stattic_problem_refused(403, 'relay_forbidden', 'Relay credential grants no brokered capability.');
    }
    $body = _stattic_bounded_request_body(STATTIC_FUNCTIONS_RELAY_MAX_BODY_BYTES);
    if ($body === null) {
        _stattic_problem_refused(413, 'relay_payload_too_large', 'Relay frame exceeds the size limit.');
    }

    if ($broker === 'database') {
        // The broker is bound to the same URL resolution every other database
        // lane uses, and to exactly the grant this credential carries; the
        // result limits it enforces read the same configuration names directly.
        // A worker cannot tell this lane from a spawned executor, since the
        // corpus pins the answer bytes against the native engine. The database
        // can: the persistent link makes a handler's Kth query cost a round
        // trip, not a spawn and a handshake.
        $env = _stattic_zero_runner_base_env();
        _stattic_db_broker_bind(
            is_string($env['SPACEFAST_ZERO_DATABASE_URL'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL'] : '',
            is_string($env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] : null
        );
        _stattic_db_broker_grant($grant);
        $answer = _stattic_db_broker_execute($body);
        // A frame must never leave an open transaction behind, holding row
        // locks, so the rollback lands before the answer does.
        _stattic_db_broker_rollback_open_transaction();
        _stattic_response_send(200, $answer, 'application/json; charset=utf-8', [
            'Cache-Control' => 'no-store',
        ]);
    }

    $result = _stattic_runtime_run_subprocess(
        [_stattic_runtime_native_binary(), SPACEFAST_FUNCTIONS_RELAY_BROKERS[$broker]['executor']],
        _stattic_functions_relay_executor_env($claims, $broker, $grant),
        $body,
        null,
        STATTIC_FUNCTIONS_RELAY_EXECUTOR_TIMEOUT_MS,
        STATTIC_FUNCTIONS_RELAY_EXECUTOR_STDOUT_MAX_BYTES,
        STATTIC_FUNCTIONS_RELAY_EXECUTOR_STDERR_MAX_BYTES
    );
    $stdout = trim($result['stdout']);
    if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0 || $stdout === '') {
        _stattic_problem_refused(503, 'relay_unavailable', 'Relay executor is unavailable.');
    }

    _stattic_response_send(200, $stdout, 'application/json; charset=utf-8', [
        'Cache-Control' => 'no-store',
    ]);
}

// Tail events from a dispatched worker, on the relay credential. Delivery is
// best-effort by design: a worker whose logs cannot be stored still serves.
function _stattic_functions_logs_serve(string $privateRoot, string $spaceId, string $requestMethod): void
{
    if ($requestMethod !== 'POST') {
        _stattic_method_not_allowed('POST', ['code' => 'method_not_allowed', 'message' => 'Log intake accepts POST.']);
    }
    $claims = _stattic_functions_relay_claims($privateRoot, $spaceId, _stattic_functions_relay_bearer());
    if ($claims === null) {
        _stattic_problem_refused(401, 'relay_unauthorized', 'Relay credential is not valid.');
    }
    $body = _stattic_bounded_request_body(STATTIC_FUNCTIONS_LOG_MAX_BODY_BYTES);
    if ($body === null) {
        _stattic_problem_refused(413, 'relay_payload_too_large', 'Log delivery exceeds the size limit.');
    }

    _stattic_functions_log_append((string) $claims['version_id'], $body);
    _stattic_response_send(202, '{"ok":true}', 'application/json; charset=utf-8', [
        'Cache-Control' => 'no-store',
    ]);
}

/**
 * A worker's tail delivery, written into the one place its output can be read
 * back from.
 *
 * The worker runs on Cloudflare with no route to the control plane, so it tails
 * to the origin that dispatched it; the origin writes to PHP's error log, which
 * the provider ships and serves back. This is the hop that connects a Functions
 * handler's `console.log` to `sf logs runtime`.
 */
function _stattic_functions_log_append(string $versionId, string $body): void
{
    $decoded = json_decode($body, true);
    foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $record) {
        if (is_array($record)) {
            _stattic_runtime_log_write($record, $versionId);
        }
    }
}
