<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/record-store.php';

const STATTIC_RUNTIME_JOB_LANES = ['bulk'];

const STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS = 5;
const STATTIC_RUNTIME_JOB_HEARTBEAT_TIMEOUT_SECONDS = 120;
const STATTIC_RUNTIME_JOB_TIME_STOP_SECONDS = 8 * 3600;
const STATTIC_RUNTIME_JOB_BACKOFF_MIN_SECONDS = 10;
const STATTIC_RUNTIME_JOB_BACKOFF_MULTIPLIER = 1.5;
const STATTIC_RUNTIME_JOB_BACKOFF_MAX_SECONDS = 15 * 60;
const STATTIC_RUNTIME_JOB_DEAD_LETTER_RETENTION_SECONDS = 14 * 86400;
// How long a finished job stays readable and holds its idempotency key.
const STATTIC_RUNTIME_JOB_COMPLETE_RETENTION_SECONDS = 86400;
const STATTIC_RUNTIME_JOB_DEFAULT_BUDGET_MS = 50000;
const STATTIC_RUNTIME_JOB_MAX_BUDGET_MS = 600000;
const STATTIC_RUNTIME_JOB_EXECUTION_TIMEOUT_MARGIN_SECONDS = 30;

// How long a contended transition sleeps before re-trying the job lock.
const STATTIC_RUNTIME_JOB_LOCK_POLL_US = 2000;
// Admission carries no tick budget of its own, so this is the whole time a
// create may spend waiting behind a running job's transitions.
const STATTIC_RUNTIME_JOB_CREATE_BUDGET_MS = 2000;
// A tick that reaches its budget still has to land the state it just produced.
// Terminal and yield writes get this much past the budget so a contended lock
// costs a re-run at worst, never a lost completion.
const STATTIC_RUNTIME_JOB_FINALIZE_GRACE_MS = 2000;
// A transition deadline already in the past: _stattic_runtime_job_transition
// takes the lock TRY-first and only re-tries until the deadline, so this is one
// attempt and no wait. Box-wide reaping uses it — see
// _stattic_runtime_job_housekeeping_reap.
const STATTIC_RUNTIME_JOB_NO_WAIT_DEADLINE = 0.0;
// The signed payload is a JWT claim, so it is bounded before it is parsed.
const STATTIC_RUNTIME_JOB_MAX_PAYLOAD_BYTES = 4096;
const STATTIC_RUNTIME_JOB_ATTESTATION_VERSION = 'spacefast.job.v1';

// Retry backs off and re-queues until max_attempts/time-stop; Fatal dead-letters
// immediately; any other Throwable is treated like an unclassified Retry.
class StatticJobRetry extends RuntimeException
{
    public function __construct(string $code, public readonly ?int $delayHintSeconds = null)
    {
        parent::__construct($code);
    }
}

class StatticJobFatal extends RuntimeException
{
    public function __construct(string $code)
    {
        parent::__construct($code);
    }
}

function _stattic_runtime_jobs_root(string $privateRoot): string
{
    return $privateRoot . '/runtime/jobs';
}

function _stattic_runtime_jobs_queue_dir(string $privateRoot): string
{
    return _stattic_runtime_jobs_root($privateRoot) . '/queue';
}

function _stattic_runtime_jobs_dead_dir(string $privateRoot): string
{
    return _stattic_runtime_jobs_root($privateRoot) . '/dead';
}

function _stattic_runtime_jobs_ensure_root(string $privateRoot): void
{
    _stattic_runtime_mkdir(_stattic_runtime_jobs_root($privateRoot));
    _stattic_runtime_mkdir(_stattic_runtime_jobs_queue_dir($privateRoot));
    _stattic_runtime_mkdir(_stattic_runtime_jobs_dead_dir($privateRoot));
}

function _stattic_runtime_jobs_queue_store(string $privateRoot): array
{
    return _stattic_record_store(_stattic_runtime_jobs_queue_dir($privateRoot), [
        'retention' => [
            'mtime_seconds' => STATTIC_RUNTIME_JOB_COMPLETE_RETENTION_SECONDS,
            'statuses' => ['complete'],
        ],
    ]);
}

function _stattic_runtime_jobs_dead_store(string $privateRoot): array
{
    return _stattic_record_store(_stattic_runtime_jobs_dead_dir($privateRoot), [
        'retention' => ['mtime_seconds' => STATTIC_RUNTIME_JOB_DEAD_LETTER_RETENTION_SECONDS],
    ]);
}

function _stattic_runtime_job_path(string $privateRoot, string $jobId): string
{
    return _stattic_record_store_path(_stattic_runtime_jobs_queue_store($privateRoot), $jobId);
}

function _stattic_runtime_job_dead_path(string $privateRoot, string $jobId): string
{
    return _stattic_record_store_path(_stattic_runtime_jobs_dead_store($privateRoot), $jobId);
}

function _stattic_runtime_job_lane_lock_path(string $privateRoot, string $lane): string
{
    return _stattic_runtime_jobs_root($privateRoot) . '/lane-' . $lane . '.lock';
}

// ONE lock per job id, spanning BOTH stores: a create and a dead-letter for the
// same identity can never run concurrently, which is what closes the window
// where a record moving to terminal storage was invisible to both halves of a
// search and a duplicate got admitted.
function _stattic_runtime_job_lock_path(string $privateRoot, string $jobId): string
{
    return _stattic_lock_stripe_path(_stattic_runtime_jobs_root($privateRoot), $jobId, 'job-');
}

function _stattic_runtime_job_lane_for_type(string $type): string
{
    $lane = _stattic_runtime_job_type_registry()[$type]['lane'] ?? null;
    if (!is_string($lane)) {
        throw new StatticJobFatal('unknown_job_type');
    }
    return $lane;
}

function _stattic_runtime_job_backoff_delay_seconds(int $attempt): float
{
    $attempt = max(1, $attempt);
    $delay = STATTIC_RUNTIME_JOB_BACKOFF_MIN_SECONDS * (STATTIC_RUNTIME_JOB_BACKOFF_MULTIPLIER ** ($attempt - 1));
    return min($delay, (float) STATTIC_RUNTIME_JOB_BACKOFF_MAX_SECONDS);
}

function _stattic_runtime_job_time_stopped(?string $firstFailedAtIso, int $nowEpoch): bool
{
    if ($firstFailedAtIso === null) {
        return false;
    }
    $firstFailedEpoch = strtotime($firstFailedAtIso);
    if ($firstFailedEpoch === false) {
        return false;
    }
    return ($nowEpoch - $firstFailedEpoch) >= STATTIC_RUNTIME_JOB_TIME_STOP_SECONDS;
}

/**
 * Admission identity is DERIVED, never searched for. One job per (type, Space,
 * idempotency key) lives at one path in both stores, so admission is a single
 * locked read of a known id instead of a scan over a directory another writer
 * is moving records out of.
 *
 * This is a BUCKET, not the whole scope: the operation is deliberately absent
 * so one identity keeps one path. What makes a reuse legitimate is
 * _stattic_runtime_job_admission_scope, which reuse compares in full.
 */
function _stattic_runtime_job_identity(string $type, ?string $spaceId, string $idempotencyKey): string
{
    return 'job_' . hash('sha256', implode("\0", [$type, $spaceId ?? '', $idempotencyKey]));
}

/**
 * The immutable half of a job: everything admission stamped and no transition
 * may change — the same fields the attestation covers, minus the ones derived
 * from them. Two requests that disagree on ANY of it are two different requests,
 * whatever identity bucket they land in, so this is what reuse compares.
 *
 * @return array<string,?string>
 */
function _stattic_runtime_job_admission_scope(array $record): array
{
    return [
        'type' => (string) ($record['type'] ?? ''),
        'space_id' => _stattic_runtime_job_scope_string($record['space_id'] ?? null),
        'operation_id' => _stattic_runtime_job_scope_string($record['operation_id'] ?? null),
        'idempotency_key' => (string) ($record['idempotency_key'] ?? ''),
        'payload' => json_encode($record['payload'] ?? [], JSON_UNESCAPED_SLASHES),
    ];
}

/**
 * The attested envelope, stamped ONCE at create over the fields that can never
 * change and carried verbatim by every later transition. Nothing recomputes it,
 * so a record that appeared on disk without passing the verified create route
 * can never acquire authority — and an accepted job outlives the JWT that
 * admitted it, across expiry and JWKS rotation.
 */
function _stattic_runtime_job_attestation(string $privateRoot, array $record): string
{
    $key = _stattic_lazy_minted_secret($privateRoot, 'job-attestation-key', 32);
    if ($key === null) {
        throw new StatticJobRetry('job_attestation_key_unavailable');
    }
    return hash_hmac('sha256', implode("\0", [
        STATTIC_RUNTIME_JOB_ATTESTATION_VERSION,
        (string) ($record['id'] ?? ''),
        (string) ($record['type'] ?? ''),
        (string) ($record['lane'] ?? ''),
        (string) ($record['space_id'] ?? ''),
        (string) ($record['operation_id'] ?? ''),
        (string) ($record['idempotency_key'] ?? ''),
        json_encode($record['payload'] ?? [], JSON_UNESCAPED_SLASHES),
    ]), $key);
}

function _stattic_runtime_job_attested(string $privateRoot, array $record): bool
{
    $claimed = $record['attestation'] ?? null;
    return is_string($claimed)
        && hash_equals(_stattic_runtime_job_attestation($privateRoot, $record), $claimed);
}

/**
 * THE job read: TERMINAL FIRST. A job that reached dead/ is final, and
 * preferring queue/ hands back the snapshot a losing writer left behind.
 * Unreadable state on either side is `unavailable`, never `absent`: "no such
 * job" is a conclusion, not a failed read.
 *
 * @return array{state:'absent'|'present'|'unavailable', record:?array, terminal:bool}
 */
function _stattic_runtime_job_read(string $privateRoot, string $jobId): array
{
    $stores = [
        [true, _stattic_runtime_jobs_dead_store($privateRoot)],
        [false, _stattic_runtime_jobs_queue_store($privateRoot)],
    ];
    foreach ($stores as [$terminal, $store]) {
        $read = _stattic_record_store_read($store, $jobId);
        if ($read['state'] === 'absent') {
            continue;
        }
        // A record that does not name itself is not this job.
        $usable = $read['state'] === 'present' && ($read['record']['id'] ?? null) === $jobId;
        return [
            'state' => $usable ? 'present' : 'unavailable',
            'record' => $usable ? $read['record'] : null,
            'terminal' => $terminal,
        ];
    }
    return ['state' => 'absent', 'record' => null, 'terminal' => false];
}

/**
 * THE state transition. Every job write in this file goes through it; there are
 * no independent snapshot writers left.
 *
 * The lock is taken TRY-first and re-tried only until $deadline, so contention
 * is spent against the CALLER's clock rather than _stattic_lock_acquire's own
 * fixed wait. That is what bounds a whole tick: an unbounded WAIT taken inside
 * the lane lock is how one contended job could hold the lane past its budget.
 *
 * $decide sees the current terminal-first read and returns the next record, or
 * null to leave it alone. `generation` is the CAS: a caller that passes the
 * generation it observed cannot overwrite a record that moved on since, which
 * is what stops a stale runner resurrecting canceled or completed work. An
 * unattested record is refused outright, so a hand-written one never moves.
 *
 * @return array{outcome:'applied'|'noop'|'conflict'|'unavailable'|'unattested'|'contended', record:?array}
 */
function _stattic_runtime_job_transition(
    string $privateRoot,
    string $jobId,
    ?int $expectedGeneration,
    float $deadline,
    callable $decide
): array {
    _stattic_runtime_jobs_ensure_root($privateRoot);
    $lockPath = _stattic_runtime_job_lock_path($privateRoot, $jobId);
    $handle = _stattic_lock_acquire($lockPath, STATTIC_LOCK_TRY);
    while ($handle === false) {
        if (microtime(true) >= $deadline) {
            return ['outcome' => 'contended', 'record' => null];
        }
        usleep(STATTIC_RUNTIME_JOB_LOCK_POLL_US);
        $handle = _stattic_lock_acquire($lockPath, STATTIC_LOCK_TRY);
    }
    try {
        $read = _stattic_runtime_job_read($privateRoot, $jobId);
        if ($read['state'] === 'unavailable') {
            return ['outcome' => 'unavailable', 'record' => null];
        }
        $current = $read['record'];
        if ($current !== null && !_stattic_runtime_job_attested($privateRoot, $current)) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'job_attestation_invalid',
                'job_id' => $jobId,
            ]);
            return ['outcome' => 'unattested', 'record' => null];
        }
        if ($expectedGeneration !== null && (int) ($current['generation'] ?? 0) !== $expectedGeneration) {
            return ['outcome' => 'conflict', 'record' => $current];
        }
        $next = $decide($read);
        if ($next === null) {
            return ['outcome' => 'noop', 'record' => $current];
        }
        $next['generation'] = (int) ($current['generation'] ?? 0) + 1;
        $next['updated_at'] = gmdate('c');
        // Only a failure leaves the queue. `complete` stays where admission and
        // the retention window can still read it.
        if (($next['status'] ?? null) === 'failed') {
            _stattic_record_store_put(_stattic_runtime_jobs_dead_store($privateRoot), $jobId, $next);
            if (is_file(_stattic_runtime_job_path($privateRoot, $jobId))) {
                _stattic_record_store_delete(_stattic_runtime_jobs_queue_store($privateRoot), $jobId);
            }
        } else {
            _stattic_record_store_put(_stattic_runtime_jobs_queue_store($privateRoot), $jobId, $next);
        }
        return ['outcome' => 'applied', 'record' => $next];
    } finally {
        _stattic_lock_release($handle);
    }
}

// A tick reports why it did nothing. `superseded` is the stale-runner outcome:
// something else owns this job now and this process wrote nothing.
function _stattic_runtime_job_tick_status(string $outcome): string
{
    return match ($outcome) {
        'conflict' => 'superseded',
        'contended' => 'contended',
        'noop' => 'idle',
        default => 'unavailable',
    };
}

// Terminal and yield writes may run a little past the budget; see the constant.
function _stattic_runtime_job_finalize_deadline(float $deadline): float
{
    return max($deadline, microtime(true)) + (STATTIC_RUNTIME_JOB_FINALIZE_GRACE_MS / 1000);
}

function _stattic_runtime_job_scope_string(mixed $value): ?string
{
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

// Never expose the attestation or internal fencing state: the control plane
// consumes lifecycle, not the engine's proof that it admitted this record.
function _stattic_runtime_job_public_response(array $record): array
{
    unset($record['attestation'], $record['generation']);
    return $record;
}

// Lifecycle events carry the job's OWN verified operation id. The record used
// to stash the create request's entire claim set under payload._claims, which
// made a bearer token part of the job payload and let a persisted record carry
// authority it was never granted.
function _stattic_runtime_job_event_claims(array $job): array
{
    $operationId = _stattic_runtime_job_scope_string($job['operation_id'] ?? null);
    return $operationId === null ? [] : ['operation_id' => $operationId];
}

/**
 * Admission. Identity is derived from the signed scope, so the only question
 * this answers under the lock is "is there already a job here".
 *
 * @param array{type:string,idempotency_key:string,space_id:?string,operation_id:?string,payload:array} $scope
 * @return array{outcome:'created'|'existing'|'conflict'|'unavailable'|'contended', job:?array}
 */
function _stattic_runtime_job_create(string $privateRoot, array $scope, float $deadline): array
{
    $type = trim((string) ($scope['type'] ?? ''));
    $lane = _stattic_runtime_job_lane_for_type($type);
    $idempotencyKey = trim((string) ($scope['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
        throw new StatticJobFatal('idempotency_key_required');
    }
    $spaceId = _stattic_runtime_job_scope_string($scope['space_id'] ?? null);
    $operationId = _stattic_runtime_job_scope_string($scope['operation_id'] ?? null);
    $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
    $jobId = _stattic_runtime_job_identity($type, $spaceId, $idempotencyKey);

    $conflict = false;
    $now = gmdate('c');
    $result = _stattic_runtime_job_transition(
        $privateRoot,
        $jobId,
        null,
        $deadline,
        static function (array $read) use (
            &$conflict,
            $privateRoot,
            $jobId,
            $type,
            $lane,
            $spaceId,
            $operationId,
            $idempotencyKey,
            $payload,
            $now
        ): ?array {
            if ($read['state'] === 'present') {
                // Same identity, different request: a refusal, not somebody
                // else's job handed back as if it were this request's. The
                // WHOLE immutable scope is compared — a shared idempotency key
                // under a different operation is a different request, and
                // returning this job would hand it work it never signed.
                $conflict = _stattic_runtime_job_admission_scope($read['record'] ?? []) !== [
                    'type' => $type,
                    'space_id' => $spaceId,
                    'operation_id' => $operationId,
                    'idempotency_key' => $idempotencyKey,
                    'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                ];
                return null;
            }
            $record = [
                'id' => $jobId,
                'type' => $type,
                'lane' => $lane,
                'space_id' => $spaceId,
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'attempt' => 0,
                'max_attempts' => STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS,
                'first_failed_at' => null,
                'not_before' => null,
                'heartbeat' => null,
                'generation' => 0,
                'cursor' => (object) [],
                'progress' => ['done' => 0, 'total' => 0],
                'payload' => $payload,
                'result' => null,
                'error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $record['attestation'] = _stattic_runtime_job_attestation($privateRoot, $record);
            return $record;
        }
    );

    if ($result['outcome'] === 'applied') {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'job_created',
            'job_id' => $jobId,
            'type' => $type,
            'lane' => $lane,
            'space_id' => $spaceId,
            'operation_id' => $operationId,
        ]);
        return ['outcome' => 'created', 'job' => $result['record']];
    }
    if ($result['outcome'] === 'noop') {
        return $conflict
            ? ['outcome' => 'conflict', 'job' => null]
            : ['outcome' => 'existing', 'job' => $result['record']];
    }
    return [
        'outcome' => $result['outcome'] === 'contended' ? 'contended' : 'unavailable',
        'job' => null,
    ];
}

function _stattic_runtime_job_emit_callback(string $privateRoot, array $job, array $entry): void
{
    _stattic_runtime_record_management_event($privateRoot, _stattic_runtime_job_event_claims($job), array_merge(
        _stattic_runtime_job_callback_fields($job),
        ['job_id' => $job['id']],
        $entry
    ));
}

// runtimeCallbackEventSchema types space_id as string-or-absent: omit it rather
// than send an explicit null, which the control plane's parse rejects outright.
function _stattic_runtime_job_callback_fields(array $job): array
{
    $fields = ['job_type' => $job['type'], 'lane' => $job['lane']];
    if (is_string($job['space_id'] ?? null) && $job['space_id'] !== '') {
        $fields['space_id'] = $job['space_id'];
    }
    return $fields;
}

function _stattic_runtime_job_bump_attempt(array $job, int $now): array
{
    $job['attempt'] = max(0, (int) ($job['attempt'] ?? 0)) + 1;
    if (($job['first_failed_at'] ?? null) === null) {
        $job['first_failed_at'] = gmdate('c', $now);
    }
    return $job;
}

// Precondition: $job['attempt']/['first_failed_at'] already reflect this failure.
function _stattic_runtime_job_schedule_retry(array $job, ?int $delayHintSeconds, int $now): array
{
    $attempt = max(0, (int) ($job['attempt'] ?? 0));
    $maxAttempts = max(1, (int) ($job['max_attempts'] ?? STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS));
    if (_stattic_runtime_job_time_stopped($job['first_failed_at'] ?? null, $now) || $attempt >= $maxAttempts) {
        $job['status'] = 'failed';
        $job['heartbeat'] = null;
        return $job;
    }
    $delay = $delayHintSeconds ?? (int) round(_stattic_runtime_job_backoff_delay_seconds($attempt));
    $job['status'] = 'pending';
    $job['not_before'] = gmdate('c', $now + max(0, $delay));
    $job['heartbeat'] = null;
    return $job;
}

/**
 * THE failure path: retry scheduling, dead-lettering and their announcements.
 *
 * $transitionDeadline is the WHOLE time this may spend waiting for the job's
 * lock, grace included. A runner landing its own job's failure adds
 * _stattic_runtime_job_finalize_deadline so a contended lock costs a re-run
 * rather than a lost transition; box-wide reaping passes
 * STATTIC_RUNTIME_JOB_NO_WAIT_DEADLINE, because a bystander it could not lock
 * on the first attempt is the next pass's work — waiting for it is time taken
 * from inside the lane lock, and taken from every abandoned job behind it.
 */
function _stattic_runtime_job_record_failure(
    string $privateRoot,
    array $job,
    string $code,
    ?int $delayHintSeconds,
    bool $fatal,
    int $now,
    float $transitionDeadline
): string {
    $result = _stattic_runtime_job_transition(
        $privateRoot,
        (string) $job['id'],
        (int) ($job['generation'] ?? 0),
        $transitionDeadline,
        static function (array $read) use ($code, $delayHintSeconds, $fatal, $now): ?array {
            $next = _stattic_runtime_job_bump_attempt($read['record'] ?? [], $now);
            $next['error'] = ['code' => $code, 'message' => $code];
            if ($fatal) {
                $next['status'] = 'failed';
                $next['heartbeat'] = null;
                return $next;
            }
            return _stattic_runtime_job_schedule_retry($next, $delayHintSeconds, $now);
        }
    );
    if ($result['outcome'] !== 'applied') {
        return _stattic_runtime_job_tick_status($result['outcome']);
    }
    $next = $result['record'];
    if (($next['status'] ?? null) === 'failed') {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'job_dead_lettered',
            'job_id' => $next['id'],
            'type' => $next['type'],
            'code' => $code,
        ]);
        _stattic_runtime_job_emit_callback($privateRoot, $next, [
            'event' => 'job_failed',
            'error' => ['code' => $code, 'message' => $code],
        ]);
        return 'dead_letter';
    }
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'job_retry_scheduled',
        'job_id' => $next['id'],
        'type' => $next['type'],
        'attempt' => $next['attempt'],
        'code' => $code,
        'not_before' => $next['not_before'],
    ]);
    return 'retry_scheduled';
}

/** Claim this exact job, or say why not. Dispatch validates the envelope here. */
function _stattic_runtime_job_claim(
    string $privateRoot,
    string $jobId,
    string $lane,
    int $now,
    float $deadline
): array {
    return _stattic_runtime_job_transition(
        $privateRoot,
        $jobId,
        null,
        $deadline,
        static function (array $read) use ($lane, $now): ?array {
            $job = $read['record'];
            if ($job === null || ($job['lane'] ?? null) !== $lane || ($job['status'] ?? null) !== 'pending') {
                return null;
            }
            $notBefore = is_string($job['not_before'] ?? null) ? strtotime($job['not_before']) : false;
            if ($notBefore !== false && $notBefore > $now) {
                return null;
            }
            $job['status'] = 'running';
            $job['heartbeat'] = $now;
            return $job;
        }
    );
}

/** Persist the runner's in-flight state under the generation it claimed. */
function _stattic_runtime_job_persist(string $privateRoot, array $job, float $deadline): array
{
    return _stattic_runtime_job_transition(
        $privateRoot,
        (string) $job['id'],
        (int) ($job['generation'] ?? 0),
        $deadline,
        static fn (array $read): ?array => $job,
    );
}

/**
 * Recover THIS job's abandoned run, and only this one. Box-wide reaping is the
 * maintenance pass's work (_stattic_runtime_job_housekeeping_reap): a tick
 * signed for one job has no authority over the rest of the lane.
 *
 * Null means "nothing to recover"; the claim that follows reports the rest.
 */
function _stattic_runtime_job_reap_one(string $privateRoot, string $jobId, int $now, float $deadline): ?string
{
    $read = _stattic_runtime_job_read($privateRoot, $jobId);
    if ($read['state'] !== 'present' || ($read['record']['status'] ?? null) !== 'running') {
        return null;
    }
    $heartbeat = is_numeric($read['record']['heartbeat'] ?? null) ? (int) $read['record']['heartbeat'] : 0;
    if (($now - $heartbeat) <= STATTIC_RUNTIME_JOB_HEARTBEAT_TIMEOUT_SECONDS) {
        return 'running_elsewhere';
    }
    return _stattic_runtime_job_record_failure(
        $privateRoot,
        $read['record'],
        'heartbeat_timeout',
        null,
        false,
        $now,
        _stattic_runtime_job_finalize_deadline($deadline)
    );
}

// Lane is derived from type, never chosen by the caller.
function _stattic_runtime_job_type_registry(): array
{
    return [
        'maintenance_tick' => ['lane' => 'bulk', 'stepper' => '_stattic_runtime_job_step_maintenance_tick'],
        'tier_demote' => ['lane' => 'bulk', 'stepper' => '_stattic_runtime_job_step_tier_demote'],
    ];
}

/**
 * The pass is only DONE when every hook finished. A hook that threw, and one
 * that could not take its own lock, are both unfinished work: completing the
 * engine job anyway hands the control plane a clean-looking result for a sweep
 * that never ran, and — because the caller's idempotency key is derived from
 * its durable operation — that same completed record is what its retry gets
 * back, forever. Yielding instead keeps the pass resumable under the operation
 * that asked for it.
 */
function _stattic_runtime_job_step_maintenance_tick(string $privateRoot, array $job, float $deadline): array
{
    $pass = _stattic_runtime_job_maintenance_tick($privateRoot, _stattic_runtime_job_event_claims($job), $deadline);
    return [
        'done' => $pass['complete'],
        'yield' => !$pass['complete'],
        'cursor' => ['complete' => $pass['complete']],
        'progress' => [
            'done' => count($pass['steps']),
            'total' => count(_stattic_runtime_job_maintenance_steps()),
        ],
        // The honest result, persisted on every step so an unfinished pass says
        // what it skipped and what threw rather than sitting pending in silence.
        'result' => $pass,
    ];
}

function _stattic_runtime_job_invoke_stepper(string $privateRoot, array $job, float $deadline): array
{
    $type = (string) ($job['type'] ?? '');
    $stepper = _stattic_runtime_job_type_registry()[$type]['stepper'] ?? null;
    if (!is_callable($stepper)) {
        throw new StatticJobFatal('unknown_job_type');
    }
    $result = $stepper($privateRoot, $job, $deadline);
    if (!is_array($result) || !array_key_exists('done', $result)) {
        throw new StatticJobFatal('invalid_stepper_result');
    }
    return $result;
}

function _stattic_runtime_job_run_claimed(string $privateRoot, array $job, float $deadline): string
{
    while (true) {
        $now = time();
        $failureDeadline = _stattic_runtime_job_finalize_deadline($deadline);
        try {
            $result = _stattic_runtime_job_invoke_stepper($privateRoot, $job, $deadline);
        } catch (StatticJobFatal $error) {
            return _stattic_runtime_job_record_failure($privateRoot, $job, $error->getMessage(), null, true, $now, $failureDeadline);
        } catch (StatticJobRetry $error) {
            return _stattic_runtime_job_record_failure($privateRoot, $job, $error->getMessage(), $error->delayHintSeconds, false, $now, $failureDeadline);
        } catch (Throwable $error) {
            error_log(sprintf(
                'spacefast job failed type=%s message=%s',
                get_debug_type($error),
                $error->getMessage(),
            ));
            return _stattic_runtime_job_record_failure($privateRoot, $job, 'unknown_error', null, false, $now, $failureDeadline);
        }

        $job['cursor'] = $result['cursor'] ?? $job['cursor'];
        if (is_array($result['progress'] ?? null)) {
            $job['progress'] = [
                'done' => max(0, (int) ($result['progress']['done'] ?? 0)),
                'total' => max(0, (int) ($result['progress']['total'] ?? 0)),
            ];
        }
        $job['heartbeat'] = time();

        $yielding = !empty($result['yield']) || microtime(true) >= $deadline;
        // A stepper's report is persisted whenever it produced one, not only at
        // completion: a job that yields with work left is exactly the one whose
        // report an operator needs.
        if (array_key_exists('result', $result)) {
            $job['result'] = $result['result'];
        }
        if ((bool) ($result['done'] ?? false)) {
            $job['status'] = 'complete';
        }
        // A budget yield is not a failure: attempt/backoff stay untouched so a
        // long job keeps progressing across ticks.
        $persisted = _stattic_runtime_job_persist(
            $privateRoot,
            $job,
            $yielding || ($job['status'] === 'complete')
                ? _stattic_runtime_job_finalize_deadline($deadline)
                : $deadline
        );
        if ($persisted['outcome'] !== 'applied') {
            // Something else owns this job now, or its state is unreadable.
            // Write nothing: that is exactly how a stale runner resurrects
            // canceled or completed work.
            return _stattic_runtime_job_tick_status($persisted['outcome']);
        }
        $job = $persisted['record'];

        if ($job['status'] === 'complete') {
            _stattic_runtime_append_journal($privateRoot, ['event' => 'job_complete', 'job_id' => $job['id'], 'type' => $job['type']]);
            _stattic_runtime_job_emit_callback($privateRoot, $job, [
                'event' => 'job_complete',
                'result' => $job['result'],
            ]);
            return 'complete';
        }
        if ($yielding) {
            $yielded = _stattic_runtime_job_transition(
                $privateRoot,
                (string) $job['id'],
                (int) $job['generation'],
                _stattic_runtime_job_finalize_deadline($deadline),
                static function (array $read): ?array {
                    $next = $read['record'];
                    $next['status'] = 'pending';
                    return $next;
                }
            );
            if ($yielded['outcome'] !== 'applied') {
                return _stattic_runtime_job_tick_status($yielded['outcome']);
            }
            _stattic_runtime_append_journal($privateRoot, ['event' => 'job_yielded', 'job_id' => $job['id'], 'type' => $job['type']]);
            return 'yielded';
        }
    }
}

/**
 * THE maintenance pass. The ORDER is load-bearing: reaping returns abandoned
 * runs to the queue before anything walks it, retention shrinks the set later
 * steps walk, blob GC collects bytes no remaining declaration names, and the
 * disk report runs last so it measures what the pass left.
 */
function _stattic_runtime_job_maintenance_steps(): array
{
    return [
        'job_reap' => '_stattic_runtime_job_housekeeping_reap',
        'retention' => '_stattic_runtime_job_housekeeping_retention',
        'blob_gc' => '_stattic_runtime_job_housekeeping_local_blob_gc',
        'route_shard_gc' => '_stattic_runtime_job_housekeeping_route_shard_gc',
        'disk_report' => '_stattic_runtime_job_housekeeping_disk_report',
    ];
}

/**
 * Every hook answers whether it FINISHED. `true` is the only clean answer: a
 * hook that returned false left work behind (its own try-lock was held, its
 * scan could not enumerate what it had to walk, the tick's budget ran out), and
 * that is not a step this pass may claim it ran. A throw is the other kind of
 * unfinished.
 *
 * $deadline is the tick's OWN deadline, carried in rather than re-derived, so
 * the whole pass is bounded by the budget the caller asked for. Selection stops
 * when it expires; the remaining steps are reported skipped, not silently
 * dropped.
 *
 * Checking it only HERE bounded which hooks ran and nothing else: a hook that
 * started inside the budget then walked a whole queue, staging backlog, CAS
 * tree or Space to the end, hundreds of milliseconds inside the bulk lane lock,
 * and the steps behind it were reported skipped as if the time had been saved.
 * Every hook consults it while walking now, stops at a bounded unit of work,
 * advances its own cadence marker only for a pass that finished, and answers
 * false so the job stays resumable.
 *
 * @return array{steps:list<string>, skipped:list<array{step:string,reason:string}>, failed:list<array{step:string,error:string}>, complete:bool}
 */
function _stattic_runtime_job_maintenance_tick(string $privateRoot, array $claims, float $deadline): array
{
    // Loaded here, not at the top: retention.php requires this file back, and
    // the cron/CLI entry into the tick does not go through management.php.
    require_once __DIR__ . '/retention.php';
    require_once __DIR__ . '/tier.php';
    require_once __DIR__ . '/generate.php';

    $ran = [];
    $skipped = [];
    $failed = [];
    foreach (_stattic_runtime_job_maintenance_steps() as $name => $hook) {
        if (microtime(true) >= $deadline) {
            $skipped[] = ['step' => $name, 'reason' => 'deadline'];
            continue;
        }
        try {
            if ($hook($privateRoot, $claims, $deadline) === true) {
                $ran[] = $name;
                continue;
            }
            $skipped[] = ['step' => $name, 'reason' => 'unavailable'];
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'maintenance_step_skipped',
                'step' => $name,
                'reason' => 'unavailable',
            ]);
        } catch (Throwable $error) {
            $failed[] = ['step' => $name, 'error' => get_debug_type($error)];
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'maintenance_step_failed',
                'step' => $name,
                'error' => get_debug_type($error),
                'message' => $error->getMessage(),
            ]);
        }
    }
    return [
        'steps' => $ran,
        'skipped' => $skipped,
        'failed' => $failed,
        'complete' => $failed === [] && $skipped === [],
    ];
}

/**
 * Box-wide recovery: the ONLY place that touches jobs it was not asked about.
 * It runs first in the pass, so the maintenance job carrying it still holds the
 * fresh heartbeat its own claim just stamped; were that ever not true, the
 * generation CAS stops the runner rather than letting it resurrect itself.
 *
 * It walks the WHOLE queue, so it is the step that has to spend the tick's
 * clock rather than one of its own. Every stale job used to get a fresh
 * admission budget plus finalization grace — about four seconds each, taken
 * inside the lane lock, whatever budget the tick was given. The pass deadline
 * bounds the WALK: selection stops when it expires and the pass reports itself
 * unfinished instead of claiming a sweep it did not make.
 *
 * A bystander's lock is taken NO-WAIT, not against the pass deadline. Spending
 * the pass's whole remaining clock on one held lock bounded the tick but starved
 * everything behind it: the queue is walked in the same order every pass and no
 * cursor moves past the obstruction, so an unlocked orphan later in the walk got
 * no recovery at all until an unrelated holder released. One attempt per
 * bystander is what makes the sweep fair as well as bounded.
 */
function _stattic_runtime_job_housekeeping_reap(string $privateRoot, array $claims, float $deadline): bool
{
    $now = time();
    $complete = true;
    $store = _stattic_runtime_jobs_queue_store($privateRoot);
    // Ids first, records one at a time: materializing every queue record before
    // the first deadline check made the whole queue a cost the budget could not
    // refuse, on a store whose whole point is that it may be large.
    foreach (_stattic_record_store_ids($store) as $id) {
        if (microtime(true) >= $deadline) {
            // Whatever is left is the next pass's, and this one says so.
            return false;
        }
        $job = _stattic_record_store_get($store, $id);
        // A record that carries no id is not a job; the store key alone does
        // not make one.
        if ($job === null || !is_string($job['id'] ?? null)) {
            continue;
        }
        if (($job['status'] ?? null) !== 'running') {
            continue;
        }
        $heartbeat = is_numeric($job['heartbeat'] ?? null) ? (int) $job['heartbeat'] : 0;
        if (($now - $heartbeat) <= STATTIC_RUNTIME_JOB_HEARTBEAT_TIMEOUT_SECONDS) {
            continue;
        }
        $recovered = _stattic_runtime_job_record_failure(
            $privateRoot,
            $job,
            'heartbeat_timeout',
            null,
            false,
            $now,
            STATTIC_RUNTIME_JOB_NO_WAIT_DEADLINE
        );
        // Recovery is the retry or the dead letter; anything else — a lock
        // somebody else held at this instant, unreadable state, a record that
        // moved on — left this job running. Keep sweeping the rest, because one
        // stuck bystander must not starve every other abandoned run, and report
        // the pass unfinished rather than claiming a sweep it did not make.
        if ($recovered !== 'retry_scheduled' && $recovered !== 'dead_letter') {
            $complete = false;
        }
    }
    return $complete;
}

function _stattic_runtime_job_housekeeping_route_shard_gc(string $privateRoot, array $claims, float $deadline): bool
{
    // Null is a pass that examined nothing — the index lock held elsewhere, or
    // a reference pointer it could not read — so this is a skipped step rather
    // than "nothing was reclaimable".
    $deleted = _stattic_runtime_route_shard_gc($privateRoot, $deadline);
    if ($deleted === null) {
        return false;
    }
    if ($deleted > 0) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'route_shards_reclaimed',
            'deleted' => $deleted,
        ]);
    }
    return true;
}

/**
 * One tick runs ONE signed job. There is no "next eligible in the lane" path
 * and no box-wide work: a token minted for one Space's job never reaps another
 * Space's, and housekeeping is a job of its own with a caller that scheduled it.
 */
function _stattic_runtime_job_tick(
    string $privateRoot,
    string $lane,
    string $jobId,
    int $budgetMs = STATTIC_RUNTIME_JOB_DEFAULT_BUDGET_MS
): array {
    if (!in_array($lane, STATTIC_RUNTIME_JOB_LANES, true)) {
        throw new StatticJobFatal('invalid_lane');
    }
    _stattic_runtime_jobs_ensure_root($privateRoot);

    // Try-once: a lane already ticking makes this a no-op, never a queued wait,
    // unlike _stattic_runtime_with_write_lock's blocking+503 semantics.
    $handle = _stattic_lock_acquire(_stattic_runtime_job_lane_lock_path($privateRoot, $lane), STATTIC_LOCK_TRY);
    if ($handle === false) {
        return ['lane' => $lane, 'ranJobId' => null, 'status' => 'lane_locked'];
    }

    try {
        $budgetMs = max(0, min($budgetMs, STATTIC_RUNTIME_JOB_MAX_BUDGET_MS));
        // The lane lock is an OS flock, so a wedged worker would pin the lane
        // forever. Bound the tick past its cooperative budget so PHP tears the
        // worker down and the heartbeat reaper recovers.
        $executionTimeoutSeconds = max(
            1,
            (int) ceil($budgetMs / 1000) + STATTIC_RUNTIME_JOB_EXECUTION_TIMEOUT_MARGIN_SECONDS
        );
        set_time_limit($executionTimeoutSeconds);
        $deadline = microtime(true) + ($budgetMs / 1000);
        $now = time();
        $envelope = ['executionTimeoutSeconds' => $executionTimeoutSeconds, 'lane' => $lane];

        $reaped = _stattic_runtime_job_reap_one($privateRoot, $jobId, $now, $deadline);
        if ($reaped !== null) {
            return $envelope + ['ranJobId' => null, 'status' => $reaped];
        }

        $claim = _stattic_runtime_job_claim($privateRoot, $jobId, $lane, $now, $deadline);
        if ($claim['outcome'] !== 'applied') {
            $status = $claim['outcome'] === 'noop'
                ? ($claim['record'] === null ? 'not_found' : 'not_claimable')
                : _stattic_runtime_job_tick_status($claim['outcome']);
            return $envelope + ['ranJobId' => null, 'status' => $status];
        }
        $job = $claim['record'];
        _stattic_runtime_append_journal($privateRoot, ['event' => 'job_claimed', 'job_id' => $job['id'], 'type' => $job['type']]);

        return $envelope + [
            'ranJobId' => $job['id'],
            'status' => _stattic_runtime_job_run_claimed($privateRoot, $job, $deadline),
        ];
    } finally {
        _stattic_lock_release($handle);
    }
}

function _stattic_runtime_jobs_tick_lane_param(): string
{
    $lane = isset($_GET['lane']) && is_string($_GET['lane']) ? trim($_GET['lane']) : '';
    if (!in_array($lane, STATTIC_RUNTIME_JOB_LANES, true)) {
        _stattic_problem_response(422, 'invalid_lane', 'lane must be one of: ' . implode(', ', STATTIC_RUNTIME_JOB_LANES) . '.');
    }
    return $lane;
}

function _stattic_runtime_jobs_tick_budget_ms_param(): int
{
    $raw = $_GET['budget_ms'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        $body = _stattic_json_body();
        $raw = $body['budget_ms'] ?? null;
    }
    if ($raw === null || $raw === '') {
        return STATTIC_RUNTIME_JOB_DEFAULT_BUDGET_MS;
    }
    if (!is_numeric($raw) || (int) $raw < 0) {
        _stattic_problem_response(422, 'invalid_budget_ms', 'budget_ms must be a non-negative integer.');
    }
    return (int) $raw;
}

/**
 * The signed create scope. Type, idempotency key and payload arrive as VERIFIED
 * claims, never as a request body: one signature covers the resource tuple and
 * the exact bytes this engine will execute, so a valid token cannot be replayed
 * against a different payload, Space, operation or job type.
 */
function _stattic_runtime_jobs_create_scope(array $claims): array
{
    $scope = $claims['job_scope'] ?? null;
    if (!is_array($scope)) {
        _stattic_problem_response(403, 'runtime_job_scope_missing', 'Runtime job token carries no signed job scope.');
    }
    $type = is_string($scope['type'] ?? null) ? trim($scope['type']) : '';
    $idempotencyKey = is_string($scope['idempotency_key'] ?? null) ? trim($scope['idempotency_key']) : '';
    $payloadJson = is_string($scope['payload'] ?? null) ? $scope['payload'] : '';
    if ($type === '' || $idempotencyKey === '' || $payloadJson === '') {
        _stattic_problem_response(403, 'runtime_job_scope_invalid', 'Runtime job scope must sign type, idempotency_key and payload.');
    }
    if (strlen($payloadJson) > STATTIC_RUNTIME_JOB_MAX_PAYLOAD_BYTES) {
        _stattic_problem_response(413, 'runtime_job_payload_too_large', 'Runtime job payload exceeds ' . STATTIC_RUNTIME_JOB_MAX_PAYLOAD_BYTES . ' bytes.');
    }
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        _stattic_problem_response(403, 'runtime_job_scope_invalid', 'Runtime job scope payload is not a JSON object.');
    }
    return [
        'type' => $type,
        'idempotency_key' => $idempotencyKey,
        'payload' => $payload,
        'space_id' => _stattic_runtime_job_scope_string($claims['space_id'] ?? null),
        'operation_id' => _stattic_runtime_job_scope_string($claims['operation_id'] ?? null),
    ];
}

function _stattic_runtime_jobs_create_route(string $privateRoot, array $claims): void
{
    $scope = _stattic_runtime_jobs_create_scope($claims);
    try {
        $result = _stattic_runtime_job_create(
            $privateRoot,
            $scope,
            microtime(true) + (STATTIC_RUNTIME_JOB_CREATE_BUDGET_MS / 1000)
        );
    } catch (StatticJobFatal $error) {
        _stattic_problem_response(422, $error->getMessage(), 'Job could not be created.');
    }
    match ($result['outcome']) {
        'conflict' => _stattic_problem_response(409, 'runtime_job_idempotency_conflict', 'A different job already holds this idempotency key.'),
        'contended' => _stattic_problem_response(503, 'runtime_job_admission_contended', 'Job admission is busy; retry.'),
        'unavailable' => _stattic_problem_response(503, 'runtime_job_state_unavailable', 'Job state could not be read.'),
        default => _stattic_json_response(201, ['job' => _stattic_runtime_job_public_response($result['job'])]),
    };
}

/**
 * The targeted job comes from the VERIFIED claims, so a tick token is a
 * capability for ONE job and nothing else. The tick route has no path capture
 * to pin, so this is where that binding is made.
 */
function _stattic_runtime_jobs_scoped_job_id(array $claims): string
{
    $jobId = _stattic_runtime_job_scope_string($claims['job_id'] ?? null);
    if ($jobId === null) {
        _stattic_problem_response(403, 'runtime_job_scope_missing', 'Runtime job token must name the job it may act on.');
    }
    return $jobId;
}

/**
 * Read the targeted job, refusing a token minted for a different Space or a
 * different operation.
 *
 * The operation is half of what admission bound this record to, and every
 * lifecycle event the job emits carries it (_stattic_runtime_job_event_claims).
 * Checking only the Space let a differently scoped request drive another
 * operation's work and announce it under the operation that admitted it.
 */
function _stattic_runtime_jobs_read_scoped(string $privateRoot, array $claims, string $jobId): array
{
    $read = _stattic_runtime_job_read($privateRoot, $jobId);
    if ($read['state'] === 'unavailable') {
        _stattic_problem_response(503, 'runtime_job_state_unavailable', 'Job state could not be read.');
    }
    if ($read['state'] !== 'present') {
        return $read;
    }
    if (_stattic_runtime_job_scope_string($claims['space_id'] ?? null)
        !== _stattic_runtime_job_scope_string($read['record']['space_id'] ?? null)) {
        _stattic_problem_response(403, 'runtime_job_space_mismatch', 'Runtime job token is not scoped to this job\'s Space.');
    }
    if (_stattic_runtime_job_scope_string($claims['operation_id'] ?? null)
        !== _stattic_runtime_job_scope_string($read['record']['operation_id'] ?? null)) {
        _stattic_problem_response(403, 'runtime_job_operation_mismatch', 'Runtime job token is not scoped to this job\'s operation.');
    }
    return $read;
}

function _stattic_runtime_jobs_tick_route(string $privateRoot, array $claims = []): void
{
    $lane = _stattic_runtime_jobs_tick_lane_param();
    $budgetMs = _stattic_runtime_jobs_tick_budget_ms_param();
    $jobId = _stattic_runtime_jobs_scoped_job_id($claims);
    // Refuse a cross-Space capability BEFORE the lane lock and any work.
    _stattic_runtime_jobs_read_scoped($privateRoot, $claims, $jobId);
    try {
        $result = _stattic_runtime_job_tick($privateRoot, $lane, $jobId, $budgetMs);
    } catch (StatticJobFatal $error) {
        _stattic_problem_response(422, $error->getMessage(), 'Job tick could not run.');
    }
    // Report the job's CURRENT state, claimed this tick or not: the caller is
    // polling one job, and a terminal record it could not claim is the answer.
    $read = _stattic_runtime_jobs_read_scoped($privateRoot, $claims, $jobId);
    _stattic_json_response(200, [
        'lane' => $result['lane'],
        'job' => $read['state'] === 'present' ? _stattic_runtime_job_public_response($read['record']) : null,
        'execution_timeout_seconds' => $result['executionTimeoutSeconds'] ?? null,
        'tick_status' => $result['status'],
    ]);
}

// api.php already pinned the path capture into the verified claims, so $jobId
// IS the signed job here; only the Space binding is left to check.
function _stattic_runtime_jobs_get_route(string $privateRoot, string $jobId, array $claims = []): void
{
    $read = _stattic_runtime_jobs_read_scoped($privateRoot, $claims, $jobId);
    if ($read['state'] === 'absent') {
        _stattic_problem_response(404, 'job_not_found', 'Job not found.');
    }
    _stattic_json_response(200, ['job' => _stattic_runtime_job_public_response($read['record'])]);
}
