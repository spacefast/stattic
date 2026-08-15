<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/record-store.php';

const STATTIC_RUNTIME_JOB_LANES = ['interactive', 'bulk'];

const STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS = 5;
const STATTIC_RUNTIME_JOB_HEARTBEAT_TIMEOUT_SECONDS = 120;
const STATTIC_RUNTIME_JOB_TIME_STOP_SECONDS = 8 * 3600;
const STATTIC_RUNTIME_JOB_BACKOFF_MIN_SECONDS = 10;
const STATTIC_RUNTIME_JOB_BACKOFF_MULTIPLIER = 1.5;
const STATTIC_RUNTIME_JOB_BACKOFF_MAX_SECONDS = 15 * 60;
const STATTIC_RUNTIME_JOB_DEAD_LETTER_RETENTION_SECONDS = 14 * 86400;
// A finished job stays readable — and holds its idempotency key — for this long.
const STATTIC_RUNTIME_JOB_COMPLETE_RETENTION_SECONDS = 86400;
const STATTIC_RUNTIME_JOB_DEFAULT_BUDGET_MS = 50000;
const STATTIC_RUNTIME_JOB_MAX_BUDGET_MS = 600000;
const STATTIC_RUNTIME_JOB_EXECUTION_TIMEOUT_MARGIN_SECONDS = 30;

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

// Records carrying no id are skipped: the store key alone does not make a job.
function _stattic_runtime_job_records(array $store): array
{
    return array_filter(
        _stattic_record_store_records($store),
        static fn (array $record): bool => is_string($record['id'] ?? null)
    );
}

function _stattic_runtime_job_load_any(string $privateRoot, string $jobId): ?array
{
    return _stattic_record_store_get(_stattic_runtime_jobs_queue_store($privateRoot), $jobId)
        ?? _stattic_record_store_get(_stattic_runtime_jobs_dead_store($privateRoot), $jobId);
}

// Never expose payload secrets — today that is the claims job_create captured.
function _stattic_runtime_job_public_response(array $record): array
{
    if (is_array($record['payload'] ?? null)) {
        unset($record['payload']['_claims']);
    }
    return $record;
}

// space_id/operation_id come from the VERIFIED management JWT claims, never from
// the request body; $claims is stashed under payload._claims so lifecycle events
// can record management events.
function _stattic_runtime_job_create(
    string $privateRoot,
    string $type,
    string $idempotencyKey,
    array $payload,
    array $claims
): array {
    if ($type === 'tier_demote' && !_stattic_tiering_enabled()) {
        throw new StatticJobFatal('tiering_disabled');
    }
    $lane = _stattic_runtime_job_lane_for_type($type);
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        throw new StatticJobFatal('idempotency_key_required');
    }

    $existing = _stattic_runtime_job_find_by_idempotency_key($privateRoot, $idempotencyKey);
    if ($existing !== null) {
        return $existing;
    }

    $spaceId = is_string($claims['space_id'] ?? null) && trim($claims['space_id']) !== '' ? trim($claims['space_id']) : null;
    $operationId = is_string($claims['operation_id'] ?? null) && trim($claims['operation_id']) !== '' ? trim($claims['operation_id']) : null;
    $maxAttempts = STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS;
    $body = $payload;
    $body['_claims'] = $claims;

    $now = gmdate('c');
    $jobId = _stattic_runtime_new_id('job');
    $record = [
        'id' => $jobId,
        'type' => $type,
        'lane' => $lane,
        'space_id' => $spaceId,
        'operation_id' => $operationId,
        'idempotency_key' => $idempotencyKey,
        'status' => 'pending',
        'attempt' => 0,
        'max_attempts' => $maxAttempts,
        'first_failed_at' => null,
        'not_before' => null,
        'heartbeat' => null,
        'cursor' => (object) [],
        'progress' => ['done' => 0, 'total' => 0],
        'payload' => $body,
        'result' => null,
        'error' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    _stattic_record_store_put(_stattic_runtime_jobs_queue_store($privateRoot), $jobId, $record);
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'job_created',
        'job_id' => $jobId,
        'type' => $type,
        'lane' => $lane,
        'space_id' => $spaceId,
        'operation_id' => $operationId,
    ]);
    return $record;
}

function _stattic_runtime_job_find_by_idempotency_key(string $privateRoot, string $idempotencyKey): ?array
{
    foreach ([_stattic_runtime_jobs_queue_store($privateRoot), _stattic_runtime_jobs_dead_store($privateRoot)] as $store) {
        foreach (_stattic_runtime_job_records($store) as $job) {
            if (($job['idempotency_key'] ?? null) === $idempotencyKey) {
                return $job;
            }
        }
    }
    return null;
}

function _stattic_runtime_job_emit_callback(string $privateRoot, array $job, array $entry): void
{
    $claims = is_array($job['payload']['_claims'] ?? null) ? $job['payload']['_claims'] : [];
    if (!isset($claims['operation_id']) && is_string($job['operation_id'] ?? null) && $job['operation_id'] !== '') {
        $claims['operation_id'] = $job['operation_id'];
    }
    _stattic_runtime_record_management_event($privateRoot, $claims, array_merge(
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

function _stattic_runtime_job_dead_letter(string $privateRoot, array $job, string $code, int $now): void
{
    $job['status'] = 'failed';
    $job['error'] = ['code' => $code, 'message' => $code];
    $job['updated_at'] = gmdate('c', $now);
    _stattic_record_store_put(_stattic_runtime_jobs_dead_store($privateRoot), $job['id'], $job);
    _stattic_record_store_delete(_stattic_runtime_jobs_queue_store($privateRoot), $job['id']);
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'job_dead_lettered',
        'job_id' => $job['id'],
        'type' => $job['type'],
        'code' => $code,
    ]);
    _stattic_runtime_job_emit_callback($privateRoot, $job, [
        'event' => 'job_failed',
        'error' => ['code' => $code, 'message' => $code],
    ]);
}

// Precondition: $job['attempt']/['first_failed_at'] must already reflect this failure.
function _stattic_runtime_job_transition_after_failure(string $privateRoot, array $job, string $code, ?int $delayHintSeconds, int $now): string
{
    $attempt = max(0, (int) ($job['attempt'] ?? 0));
    $maxAttempts = max(1, (int) ($job['max_attempts'] ?? STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS));
    $timeStopped = _stattic_runtime_job_time_stopped($job['first_failed_at'] ?? null, $now);

    if ($timeStopped || $attempt >= $maxAttempts) {
        _stattic_runtime_job_dead_letter($privateRoot, $job, $code, $now);
        return 'dead_letter';
    }

    $delay = $delayHintSeconds ?? (int) round(_stattic_runtime_job_backoff_delay_seconds($attempt));
    $job['status'] = 'pending';
    $job['not_before'] = gmdate('c', $now + max(0, $delay));
    $job['heartbeat'] = null;
    $job['error'] = ['code' => $code, 'message' => $code];
    $job['updated_at'] = gmdate('c', $now);
    _stattic_record_store_put(_stattic_runtime_jobs_queue_store($privateRoot), $job['id'], $job);
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'job_retry_scheduled',
        'job_id' => $job['id'],
        'type' => $job['type'],
        'attempt' => $attempt,
        'code' => $code,
        'not_before' => $job['not_before'],
    ]);
    return 'retry_scheduled';
}

// Returns the untouched records for the claim below: a reaped job is never
// claimable in the same tick.
function _stattic_runtime_job_reap_lane(string $privateRoot, string $lane, int $now): array
{
    $remaining = [];
    foreach (_stattic_runtime_job_records(_stattic_runtime_jobs_queue_store($privateRoot)) as $job) {
        if (($job['lane'] ?? null) !== $lane || ($job['status'] ?? null) !== 'running') {
            $remaining[] = $job;
            continue;
        }
        $heartbeat = is_numeric($job['heartbeat'] ?? null) ? (int) $job['heartbeat'] : 0;
        if (($now - $heartbeat) <= STATTIC_RUNTIME_JOB_HEARTBEAT_TIMEOUT_SECONDS) {
            $remaining[] = $job;
            continue;
        }
        $job['attempt'] = max(0, (int) ($job['attempt'] ?? 0)) + 1;
        if ($job['first_failed_at'] === null) {
            $job['first_failed_at'] = gmdate('c', $now);
        }
        _stattic_runtime_job_transition_after_failure($privateRoot, $job, 'heartbeat_timeout', null, $now);
    }
    return $remaining;
}

function _stattic_runtime_job_claim_record(string $privateRoot, array $job, int $now): array
{
    $job['status'] = 'running';
    $job['heartbeat'] = $now;
    $job['updated_at'] = gmdate('c', $now);
    _stattic_record_store_put(_stattic_runtime_jobs_queue_store($privateRoot), $job['id'], $job);
    _stattic_runtime_append_journal($privateRoot, ['event' => 'job_claimed', 'job_id' => $job['id'], 'type' => $job['type']]);
    return $job;
}

function _stattic_runtime_job_claim_by_id(string $privateRoot, string $lane, string $jobId, int $now): ?array
{
    $job = _stattic_record_store_get(_stattic_runtime_jobs_queue_store($privateRoot), $jobId);
    if ($job === null || ($job['lane'] ?? null) !== $lane || ($job['status'] ?? null) !== 'pending') {
        return null;
    }
    $notBefore = is_string($job['not_before'] ?? null) ? strtotime($job['not_before']) : false;
    if ($notBefore !== false && $notBefore > $now) {
        return null;
    }
    return _stattic_runtime_job_claim_record($privateRoot, $job, $now);
}

function _stattic_runtime_job_claim_next(string $privateRoot, string $lane, int $now, array $jobs): ?array
{
    $best = null;
    foreach ($jobs as $job) {
        if (($job['lane'] ?? null) !== $lane || ($job['status'] ?? null) !== 'pending') {
            continue;
        }
        $notBefore = is_string($job['not_before'] ?? null) ? strtotime($job['not_before']) : false;
        if ($notBefore !== false && $notBefore > $now) {
            continue;
        }
        if ($best === null || strcmp((string) $job['created_at'], (string) $best['created_at']) < 0) {
            $best = $job;
        }
    }
    if ($best === null) {
        return null;
    }
    return _stattic_runtime_job_claim_record($privateRoot, $best, $now);
}

// Lane is derived from type, never chosen by the caller.
function _stattic_runtime_job_type_registry(): array
{
    return [
        'maintenance_tick' => ['lane' => 'bulk', 'stepper' => '_stattic_runtime_job_step_maintenance_tick'],
        'tier_demote' => ['lane' => 'bulk', 'stepper' => '_stattic_runtime_job_step_tier_demote'],
    ];
}

function _stattic_runtime_job_step_maintenance_tick(string $privateRoot, array $job): array
{
    $claims = is_array($job['payload']['_claims'] ?? null) ? $job['payload']['_claims'] : [];
    $ran = _stattic_runtime_job_maintenance_tick($privateRoot, $claims);
    return [
        'done' => true,
        'cursor' => ['complete' => true],
        'progress' => ['done' => count($ran), 'total' => count($ran)],
        'result' => ['steps' => $ran],
    ];
}

function _stattic_runtime_job_invoke_stepper(string $privateRoot, array $job): array
{
    $type = (string) ($job['type'] ?? '');
    $stepper = _stattic_runtime_job_type_registry()[$type]['stepper'] ?? null;
    if (!is_callable($stepper)) {
        throw new StatticJobFatal('unknown_job_type');
    }
    $result = $stepper($privateRoot, $job);
    if (!is_array($result) || !array_key_exists('done', $result)) {
        throw new StatticJobFatal('invalid_stepper_result');
    }
    return $result;
}

// True when another lane dead-lettered this job mid-step: the runner must stop
// and NOT write job state back into queue/, which would resurrect it.
function _stattic_runtime_job_cancel_observed(string $privateRoot, array $job): bool
{
    return is_file(_stattic_runtime_job_dead_path($privateRoot, (string) $job['id']));
}

// When true it has ALREADY unlinked the queue record: the caller must only stop
// and report 'dead_letter', never persist job state.
function _stattic_runtime_job_abort_if_canceled(string $privateRoot, array $job): bool
{
    if (!_stattic_runtime_job_cancel_observed($privateRoot, $job)) {
        return false;
    }
    _stattic_record_store_delete(_stattic_runtime_jobs_queue_store($privateRoot), (string) $job['id']);
    _stattic_runtime_append_journal($privateRoot, ['event' => 'job_cancel_observed', 'job_id' => $job['id'], 'type' => $job['type']]);
    return true;
}

function _stattic_runtime_job_yield(string $privateRoot, array $job): string
{
    $job['status'] = 'pending';
    _stattic_record_store_put(_stattic_runtime_jobs_queue_store($privateRoot), $job['id'], $job);
    _stattic_runtime_append_journal($privateRoot, ['event' => 'job_yielded', 'job_id' => $job['id'], 'type' => $job['type']]);
    return 'yielded';
}

function _stattic_runtime_job_bump_attempt(array $job, int $now): array
{
    $job['attempt'] = max(0, (int) $job['attempt']) + 1;
    if ($job['first_failed_at'] === null) {
        $job['first_failed_at'] = gmdate('c', $now);
    }
    return $job;
}

function _stattic_runtime_job_run_claimed(string $privateRoot, array $job, float $deadline): string
{
    while (true) {
        if (_stattic_runtime_job_abort_if_canceled($privateRoot, $job)) {
            return 'dead_letter';
        }
        $now = time();
        try {
            $result = _stattic_runtime_job_invoke_stepper($privateRoot, $job);
        } catch (StatticJobFatal $error) {
            $job = _stattic_runtime_job_bump_attempt($job, $now);
            _stattic_runtime_job_dead_letter($privateRoot, $job, $error->getMessage(), $now);
            return 'dead_letter';
        } catch (StatticJobRetry $error) {
            $job = _stattic_runtime_job_bump_attempt($job, $now);
            return _stattic_runtime_job_transition_after_failure($privateRoot, $job, $error->getMessage(), $error->delayHintSeconds, $now);
        } catch (Throwable $error) {
            $job = _stattic_runtime_job_bump_attempt($job, $now);
            return _stattic_runtime_job_transition_after_failure($privateRoot, $job, 'unknown_error', null, $now);
        }

        $job['cursor'] = $result['cursor'] ?? $job['cursor'];
        if (is_array($result['progress'] ?? null)) {
            $job['progress'] = [
                'done' => max(0, (int) ($result['progress']['done'] ?? 0)),
                'total' => max(0, (int) ($result['progress']['total'] ?? 0)),
            ];
        }
        $job['heartbeat'] = time();
        $job['updated_at'] = gmdate('c');

        if (!empty($result['yield'])) {
            if (_stattic_runtime_job_abort_if_canceled($privateRoot, $job)) {
                return 'dead_letter';
            }
            return _stattic_runtime_job_yield($privateRoot, $job);
        }

        if ((bool) ($result['done'] ?? false)) {
            $job['status'] = 'complete';
            $job['result'] = $result['result'] ?? null;
            _stattic_record_store_put(_stattic_runtime_jobs_queue_store($privateRoot), $job['id'], $job);
            _stattic_runtime_append_journal($privateRoot, ['event' => 'job_complete', 'job_id' => $job['id'], 'type' => $job['type']]);
            _stattic_runtime_job_emit_callback($privateRoot, $job, [
                'event' => 'job_complete',
                'result' => $job['result'],
            ]);
            return 'complete';
        }

        if (microtime(true) >= $deadline) {
            // Budget yield, not a failure: attempt/backoff stay untouched so a
            // long job keeps progressing across ticks.
            if (_stattic_runtime_job_abort_if_canceled($privateRoot, $job)) {
                return 'dead_letter';
            }
            return _stattic_runtime_job_yield($privateRoot, $job);
        }

        _stattic_record_store_put(_stattic_runtime_jobs_queue_store($privateRoot), $job['id'], $job);
    }
}

/**
 * THE maintenance tick. The ORDER is load-bearing: retention shrinks the set the
 * later steps walk, version pruning is what makes blobs collectable, the blob GC
 * then sees the reduced live set, trash cleanup releases what pruning parked, and
 * the disk report is last so it measures what the pass left behind.
 */
function _stattic_runtime_job_maintenance_steps(): array
{
    return [
        'retention' => '_stattic_runtime_job_housekeeping_retention',
        'prune_versions' => '_stattic_runtime_job_housekeeping_prune_versions',
        'blob_gc' => '_stattic_runtime_job_housekeeping_local_blob_gc',
        'trash_cleanup' => '_stattic_runtime_job_housekeeping_trash_cleanup',
        'route_shard_gc' => '_stattic_runtime_job_housekeeping_route_shard_gc',
        'purge_requeue' => '_stattic_runtime_job_housekeeping_purge_requeue',
        'disk_report' => '_stattic_runtime_job_housekeeping_disk_report',
    ];
}

function _stattic_runtime_job_maintenance_tick(string $privateRoot, array $claims = []): array
{
    // Loaded here, not at the top: retention.php requires this file back, and the
    // cron/CLI entry into the tick does not go through management.php. These
    // requires are what make the steps below callable at all.
    require_once __DIR__ . '/retention.php';
    require_once __DIR__ . '/tier.php';
    require_once __DIR__ . '/generate.php';

    $ran = [];
    foreach (_stattic_runtime_job_maintenance_steps() as $name => $hook) {
        try {
            $hook($privateRoot, $claims);
            $ran[] = $name;
        } catch (Throwable $error) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'maintenance_step_failed',
                'step' => $name,
                'error' => get_debug_type($error),
                'message' => $error->getMessage(),
            ]);
        }
    }
    return $ran;
}

function _stattic_runtime_job_housekeeping_route_shard_gc(string $privateRoot, array $claims = []): void
{
    $deleted = _stattic_runtime_route_shard_gc($privateRoot);
    if ($deleted > 0) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'route_shards_reclaimed',
            'deleted' => $deleted,
        ]);
    }
}

// A purge whose loopback kick never landed is still owed to the edge. One kick
// per tick drains whatever is queued; the tick never blocks on the result.
function _stattic_runtime_job_housekeeping_purge_requeue(string $privateRoot, array $claims = []): void
{
    require_once __DIR__ . '/../shared/purge.php';
    _stattic_runtime_purge_requeue_kick($privateRoot);
}

function _stattic_runtime_job_tick(string $privateRoot, string $lane, int $budgetMs = STATTIC_RUNTIME_JOB_DEFAULT_BUDGET_MS, array $claims = [], ?string $jobId = null): array
{
    if (!in_array($lane, STATTIC_RUNTIME_JOB_LANES, true)) {
        throw new StatticJobFatal('invalid_lane');
    }
    _stattic_runtime_mkdir(_stattic_runtime_jobs_root($privateRoot));
    _stattic_runtime_mkdir(_stattic_runtime_jobs_queue_dir($privateRoot));
    _stattic_runtime_mkdir(_stattic_runtime_jobs_dead_dir($privateRoot));

    // Try-once: a lane already ticking makes this a no-op, never a queued wait —
    // unlike _stattic_runtime_with_write_lock's blocking+503 semantics.
    $handle = _stattic_lock_acquire(_stattic_runtime_job_lane_lock_path($privateRoot, $lane), STATTIC_LOCK_TRY);
    if ($handle === false) {
        return ['lane' => $lane, 'ranJobId' => null, 'status' => 'lane_locked'];
    }

    try {
        $budgetMs = max(0, min($budgetMs, STATTIC_RUNTIME_JOB_MAX_BUDGET_MS));
        // The lane lock is an OS flock, so a wedged worker would pin the lane forever: bound the tick
        // past its cooperative budget so PHP tears the worker down and the heartbeat reaper recovers.
        $executionTimeoutSeconds = max(
            1,
            (int) ceil($budgetMs / 1000) + STATTIC_RUNTIME_JOB_EXECUTION_TIMEOUT_MARGIN_SECONDS
        );
        @set_time_limit($executionTimeoutSeconds);
        $deadline = microtime(true) + ($budgetMs / 1000);
        $now = time();
        $jobs = _stattic_runtime_job_reap_lane($privateRoot, $lane, $now);

        $job = $jobId !== null
            ? _stattic_runtime_job_claim_by_id($privateRoot, $lane, $jobId, $now)
            : _stattic_runtime_job_claim_next($privateRoot, $lane, $now, $jobs);
        $ranJobId = null;
        $status = 'idle';
        if ($job !== null) {
            $ranJobId = $job['id'];
            $status = _stattic_runtime_job_run_claimed($privateRoot, $job, $deadline);
        }

        // The cron watchdog's trigger for the same pass a maintenance_tick job
        // runs — skipped when the tick just ran one, so the most expensive
        // periodic work does not happen twice in a single request.
        if ($lane === 'bulk' && ($job['type'] ?? null) !== 'maintenance_tick') {
            _stattic_runtime_job_maintenance_tick($privateRoot, $claims);
        }

        return [
            'executionTimeoutSeconds' => $executionTimeoutSeconds,
            'lane' => $lane,
            'ranJobId' => $ranJobId,
            'status' => $status,
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

function _stattic_runtime_jobs_tick_job_id_param(): ?string
{
    $raw = $_GET['job_id'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        $body = _stattic_json_body();
        $raw = $body['job_id'] ?? null;
    }
    if ($raw === null || $raw === '') {
        return null;
    }
    return _stattic_runtime_id((string) $raw, 'job_id');
}

function _stattic_runtime_jobs_create_route(string $privateRoot, array $claims): void
{
    $body = _stattic_json_body();
    $type = is_string($body['type'] ?? null) ? trim($body['type']) : '';
    $idempotencyKey = is_string($body['idempotency_key'] ?? null) ? trim($body['idempotency_key']) : '';
    $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
    if ($type === '' || $idempotencyKey === '') {
        _stattic_problem_response(422, 'invalid_job_create_request', 'type and idempotency_key are required.');
    }
    try {
        $record = _stattic_runtime_job_create($privateRoot, $type, $idempotencyKey, $payload, $claims);
    } catch (StatticJobFatal $error) {
        _stattic_problem_response(422, $error->getMessage(), 'Job could not be created.');
    }
    _stattic_json_response(201, ['job' => _stattic_runtime_job_public_response($record)]);
}

function _stattic_runtime_jobs_tick_route(string $privateRoot, array $claims = []): void
{
    $lane = _stattic_runtime_jobs_tick_lane_param();
    $budgetMs = _stattic_runtime_jobs_tick_budget_ms_param();
    $jobId = _stattic_runtime_jobs_tick_job_id_param();
    try {
        $result = _stattic_runtime_job_tick($privateRoot, $lane, $budgetMs, $claims, $jobId);
    } catch (StatticJobFatal $error) {
        _stattic_problem_response(422, $error->getMessage(), 'Job tick could not run.');
    }
    $job = $result['ranJobId'] !== null ? _stattic_runtime_job_load_any($privateRoot, $result['ranJobId']) : null;
    _stattic_json_response(200, [
        'lane' => $result['lane'],
        'job' => $job !== null ? _stattic_runtime_job_public_response($job) : null,
        // Reports that a bulk pass ran, not that anything was delivered —
        // delivery is the pull lane's (/events/drain).
        'drained' => $lane === 'bulk',
        'execution_timeout_seconds' => $result['executionTimeoutSeconds'] ?? null,
        'tick_status' => $result['status'],
    ]);
}

function _stattic_runtime_jobs_get_route(string $privateRoot, string $jobId): void
{
    $jobId = _stattic_runtime_id($jobId, 'job_id');
    $record = _stattic_runtime_job_load_any($privateRoot, $jobId);
    if ($record === null) {
        _stattic_problem_response(404, 'job_not_found', 'Job not found.');
    }
    _stattic_json_response(200, ['job' => _stattic_runtime_job_public_response($record)]);
}
