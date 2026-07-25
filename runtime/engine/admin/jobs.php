<?php
declare(strict_types=1);

// Engine job runner (plan §22): two lanes ('interactive', 'bulk'), one live job
// per lane, kicked by management dispatch and by the site-cron watchdog. Job
// state lives as one JSON record per job under runtime/jobs/{queue,dead}/ —
// the SAME atomic tmp+rename primitives as everything else in shared/storage.php,
// no daemon, no database. This file wires the runner, the state machine, and
// the steppers for every job type the runner dispatches.

const STATTIC_RUNTIME_JOB_LANES = ['interactive', 'bulk'];

// Lane is derived from type, never chosen by the caller (§22: "Lane derivation
// from type"). 'test_counter'/'test_poison' are harmless fixtures for the
// runner's own test suite — cheap no-ops if ever reached in production.
const STATTIC_RUNTIME_JOB_TYPE_LANE = [
    'space_transfer_push' => 'bulk',
    'space_transfer_install' => 'bulk',
    'tier_demote' => 'bulk',
    'tier_promote' => 'bulk',
    // Cancellation runs in the INTERACTIVE lane on purpose: a bulk-lane cancel
    // would queue BEHIND the very tier job it exists to abort (one live job
    // per lane, oldest-first claim) and never run until that job finished.
    'tier_cancel' => 'interactive',
    'blob_report' => 'bulk',
    'test_counter' => 'bulk',
    'test_poison' => 'bulk',
];

const STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS = 5;
const STATTIC_RUNTIME_JOB_HEARTBEAT_TIMEOUT_SECONDS = 120;
const STATTIC_RUNTIME_JOB_TIME_STOP_SECONDS = 8 * 3600;
const STATTIC_RUNTIME_JOB_BACKOFF_MIN_SECONDS = 10;
const STATTIC_RUNTIME_JOB_BACKOFF_MULTIPLIER = 1.5;
const STATTIC_RUNTIME_JOB_BACKOFF_MAX_SECONDS = 15 * 60;
const STATTIC_RUNTIME_JOB_DEAD_LETTER_RETENTION_SECONDS = 14 * 86400;
const STATTIC_RUNTIME_JOB_DEFAULT_BUDGET_MS = 50000;
const STATTIC_RUNTIME_JOB_MAX_BUDGET_MS = 600000;
const STATTIC_RUNTIME_JOB_EXECUTION_TIMEOUT_MARGIN_SECONDS = 30;

// Failure classes (plan §22, modeled on wpcom async-jobs/janitor.php +
// class-long-retry-exception.php): Retry backs off and re-queues until
// max_attempts/time-stop, Fatal dead-letters immediately, any other Throwable
// is treated like an unclassified Retry.
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

function _stattic_runtime_job_path(string $privateRoot, string $jobId): string
{
    return _stattic_runtime_jobs_queue_dir($privateRoot) . '/' . $jobId . '.json';
}

function _stattic_runtime_job_dead_path(string $privateRoot, string $jobId): string
{
    return _stattic_runtime_jobs_dead_dir($privateRoot) . '/' . $jobId . '.json';
}

function _stattic_runtime_job_lane_lock_path(string $privateRoot, string $lane): string
{
    return _stattic_runtime_jobs_root($privateRoot) . '/lane-' . $lane . '.lock';
}

function _stattic_runtime_job_lane_for_type(string $type): string
{
    $lane = STATTIC_RUNTIME_JOB_TYPE_LANE[$type] ?? null;
    if (!is_string($lane)) {
        throw new StatticJobFatal('unknown_job_type');
    }
    return $lane;
}

// --- Backoff policy (pure — unit-tested directly in runtime/tests/unit.php) --------

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

// --- Record I/O ----------------------------------------------------------------------

function _stattic_runtime_job_list(string $dir): array
{
    $jobs = [];
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        _stattic_runtime_assert_private_path($path);
        $record = _stattic_runtime_read_json($path);
        if (is_array($record) && is_string($record['id'] ?? null)) {
            $jobs[] = $record;
        }
    }
    return $jobs;
}

function _stattic_runtime_job_load_any(string $privateRoot, string $jobId): ?array
{
    foreach ([_stattic_runtime_job_path($privateRoot, $jobId), _stattic_runtime_job_dead_path($privateRoot, $jobId)] as $path) {
        if (!is_file($path)) {
            continue;
        }
        _stattic_runtime_assert_private_path($path);
        $record = _stattic_runtime_read_json($path);
        if (is_array($record)) {
            return $record;
        }
    }
    return null;
}

function _stattic_runtime_job_load_queue(string $privateRoot, string $jobId): ?array
{
    $path = _stattic_runtime_job_path($privateRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    _stattic_runtime_assert_private_path($path);
    $record = _stattic_runtime_read_json($path);
    return is_array($record) ? $record : null;
}

// GET /jobs/{jobId}: record minus payload secrets (§22) — the only secret we
// stash in payload today is the callback claims job_create captured.
function _stattic_runtime_job_public_response(array $record): array
{
    if (is_array($record['payload'] ?? null)) {
        unset($record['payload']['_claims']);
    }
    return $record;
}

// --- Create (upsert by idempotency_key) -----------------------------------------------

// space_id/operation_id come from the caller's VERIFIED management JWT claims
// (like every other route in this file), never from the request body —
// $payload is the stepper's opaque job-specific data. $claims is stashed
// under payload._claims so job lifecycle events can call
// _stattic_runtime_record_management_event exactly like every other mutation.
function _stattic_runtime_job_create(
    string $privateRoot,
    string $type,
    string $idempotencyKey,
    array $payload,
    array $claims,
    ?int $maxAttempts = null
): array {
    $lane = _stattic_runtime_job_lane_for_type($type);
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        throw new StatticJobFatal('idempotency_key_required');
    }

    $existing = _stattic_runtime_job_find_by_idempotency_key($privateRoot, $idempotencyKey);
    // An aborted job is a cancellation, not a terminal failure of the work:
    // /transfer/abort and tier_cancel dead-letter queued/running jobs, and a
    // later re-dispatch under the same idempotency key must be able to start
    // fresh instead of reading the cancellation back as a failure.
    if ($existing !== null && !in_array($existing['error']['code'] ?? null, ['transfer_aborted', 'tier_aborted'], true)) {
        return $existing;
    }

    $spaceId = is_string($claims['space_id'] ?? null) && trim($claims['space_id']) !== '' ? trim($claims['space_id']) : null;
    $operationId = is_string($claims['operation_id'] ?? null) && trim($claims['operation_id']) !== '' ? trim($claims['operation_id']) : null;
    $maxAttempts = $maxAttempts !== null && $maxAttempts >= 1 ? $maxAttempts : STATTIC_RUNTIME_JOB_DEFAULT_MAX_ATTEMPTS;
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
    _stattic_runtime_write_json_atomic(_stattic_runtime_job_path($privateRoot, $jobId), $record);
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

// O(pending+dead jobs) directory scan — fine at V1's one-live-job-per-lane
// scale; revisit with an index file if the backlog ever grows large.
function _stattic_runtime_job_find_by_idempotency_key(string $privateRoot, string $idempotencyKey): ?array
{
    foreach ([_stattic_runtime_jobs_queue_dir($privateRoot), _stattic_runtime_jobs_dead_dir($privateRoot)] as $dir) {
        foreach (_stattic_runtime_job_list($dir) as $job) {
            if (($job['idempotency_key'] ?? null) === $idempotencyKey) {
                return $job;
            }
        }
    }
    return null;
}

// --- Callback emission (reuses the existing pending-callback mechanism) --------------

function _stattic_runtime_job_emit_callback(string $privateRoot, array $job, array $entry): void
{
    $claims = is_array($job['payload']['_claims'] ?? null) ? $job['payload']['_claims'] : [];
    if (!isset($claims['operation_id']) && is_string($job['operation_id'] ?? null) && $job['operation_id'] !== '') {
        $claims['operation_id'] = $job['operation_id'];
    }
    _stattic_runtime_record_management_event($privateRoot, $claims, $entry);
}

// job_complete/job_failed callback fields (control plane: asEngineJobCallbackEvent,
// packages/common/src/contracts/runtime-api.ts runtimeCallbackEventSchema). space_id
// is a typed string-or-absent field there — omit it rather than send an explicit
// null, which the CP's schema parse would reject outright.
function _stattic_runtime_job_callback_fields(array $job): array
{
    $fields = ['job_type' => $job['type'], 'lane' => $job['lane']];
    if (is_string($job['space_id'] ?? null) && $job['space_id'] !== '') {
        $fields['space_id'] = $job['space_id'];
    }
    return $fields;
}

// --- Failure / dead-letter transitions -------------------------------------------------

function _stattic_runtime_job_dead_letter(string $privateRoot, array $job, string $code, int $now): void
{
    $job['status'] = 'failed';
    $job['error'] = ['code' => $code, 'message' => $code];
    $job['updated_at'] = gmdate('c', $now);
    _stattic_runtime_mkdir(_stattic_runtime_jobs_dead_dir($privateRoot));
    _stattic_runtime_write_json_atomic(_stattic_runtime_job_dead_path($privateRoot, $job['id']), $job);
    @unlink(_stattic_runtime_job_path($privateRoot, $job['id']));
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'job_dead_lettered',
        'job_id' => $job['id'],
        'type' => $job['type'],
        'code' => $code,
    ]);
    _stattic_runtime_job_emit_callback($privateRoot, $job, array_merge(
        _stattic_runtime_job_callback_fields($job),
        [
            'event' => 'job_failed',
            'job_id' => $job['id'],
            'error' => ['code' => $code, 'message' => $code],
        ]
    ));
}

// Dead-letters a space's queued/running jobs of the given types and returns
// their ids. $exceptJobId skips the caller's own record (tier_cancel runs as a
// job itself). Completed jobs stay: rewriting finished work as a failure would
// poison later reads.
function _stattic_runtime_job_cancel_space_jobs(string $privateRoot, string $spaceId, array $types, string $code, int $now, ?string $exceptJobId = null): array
{
    $canceled = [];
    foreach (_stattic_runtime_job_list(_stattic_runtime_jobs_queue_dir($privateRoot)) as $target) {
        if ((string) ($target['id'] ?? '') === $exceptJobId || ($target['space_id'] ?? null) !== $spaceId) {
            continue;
        }
        if (!in_array($target['type'] ?? '', $types, true) || ($target['status'] ?? null) === 'complete') {
            continue;
        }
        _stattic_runtime_job_dead_letter($privateRoot, $target, $code, $now);
        $canceled[] = (string) $target['id'];
    }
    return $canceled;
}

// $job['attempt']/['first_failed_at'] must already reflect this failure
// before calling — dead-letters via time-stop or max_attempts, else
// re-queues with backoff (delayHintSeconds overrides the computed schedule).
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
    _stattic_runtime_write_json_atomic(_stattic_runtime_job_path($privateRoot, $job['id']), $job);
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

// --- Reap: orphaned 'running' jobs whose worker died mid-step -----------------------

// Returns the records this pass left alone, so the claim below selects from
// them instead of reading the queue directory a second time. A reaped job is
// never claimable in the same tick anyway: it is either dead-lettered or
// re-queued with at least STATTIC_RUNTIME_JOB_BACKOFF_MIN_SECONDS of backoff.
function _stattic_runtime_job_reap_lane(string $privateRoot, string $lane, int $now): array
{
    $remaining = [];
    foreach (_stattic_runtime_job_list(_stattic_runtime_jobs_queue_dir($privateRoot)) as $job) {
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

// --- Claim ---------------------------------------------------------------------------

function _stattic_runtime_job_claim_record(string $privateRoot, array $job, int $now): array
{
    $job['status'] = 'running';
    $job['heartbeat'] = $now;
    $job['updated_at'] = gmdate('c', $now);
    _stattic_runtime_write_json_atomic(_stattic_runtime_job_path($privateRoot, $job['id']), $job);
    _stattic_runtime_append_journal($privateRoot, ['event' => 'job_claimed', 'job_id' => $job['id'], 'type' => $job['type']]);
    return $job;
}

function _stattic_runtime_job_claim_by_id(string $privateRoot, string $lane, string $jobId, int $now): ?array
{
    $job = _stattic_runtime_job_load_queue($privateRoot, $jobId);
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

// --- Stepper registry ------------------------------------------------------------------

function _stattic_runtime_job_stepper_registry(): array
{
    return [
        'space_transfer_push' => '_stattic_runtime_job_step_space_transfer_push',
        'space_transfer_install' => '_stattic_runtime_job_step_space_transfer_install',
        'tier_demote' => '_stattic_runtime_job_step_tier_demote',
        'tier_promote' => '_stattic_runtime_job_step_tier_promote',
        'tier_cancel' => '_stattic_runtime_job_step_tier_cancel',
        'blob_report' => '_stattic_runtime_job_step_blob_report',
        'test_counter' => '_stattic_runtime_job_step_test_counter',
        'test_poison' => '_stattic_runtime_job_step_test_poison',
    ];
}

// Fixture stepper: counts to payload.target (default 3), one step at a time,
// optionally sleeping payload.step_delay_us microseconds per step so tests
// can widen a concurrency window with real (bounded) wall-clock work — never
// dispatched by production code.
function _stattic_runtime_job_step_test_counter(string $privateRoot, array $job): array
{
    $delayUs = max(0, (int) ($job['payload']['step_delay_us'] ?? 0));
    if ($delayUs > 0) {
        usleep($delayUs);
    }
    $target = max(1, (int) ($job['payload']['target'] ?? 3));
    $count = max(0, (int) ($job['cursor']['count'] ?? 0)) + 1;
    $done = $count >= $target;
    return [
        'done' => $done,
        'cursor' => ['count' => $count],
        'progress' => ['done' => $count, 'total' => $target],
        'result' => $done ? ['count' => $count] : null,
    ];
}

// Fixture stepper: always fails so the retry/dead-letter path is directly
// exercisable — never dispatched by production code.
function _stattic_runtime_job_step_test_poison(string $privateRoot, array $job): array
{
    throw new StatticJobRetry('test_poison_failure');
}

// The move×demote mutex's REAL abort dispatch (contract B1): dead-letters the
// space's queued/running tier_demote/tier_promote jobs with 'tier_aborted'
// (re-dispatchable under the same idempotency key, like 'transfer_aborted').
// A concurrently mid-step bulk job is stopped by the cancel-observed check in
// _stattic_runtime_job_run_claimed instead of resurrecting its queue record.
function _stattic_runtime_job_step_tier_cancel(string $privateRoot, array $job): array
{
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $rawSpaceId = $payload['space_id'] ?? $payload['spaceId'] ?? $job['space_id'] ?? '';
    $spaceId = _stattic_runtime_id(is_string($rawSpaceId) ? $rawSpaceId : '', 'space_id');
    $canceled = _stattic_runtime_job_cancel_space_jobs($privateRoot, $spaceId, ['tier_demote', 'tier_promote'], 'tier_aborted', time(), (string) $job['id']);
    return [
        'done' => true,
        'cursor' => ['complete' => true],
        'progress' => ['done' => 1, 'total' => 1],
        'result' => ['canceled_job_ids' => $canceled],
    ];
}

function _stattic_runtime_job_invoke_stepper(string $privateRoot, array $job): array
{
    $type = (string) ($job['type'] ?? '');
    $stepper = _stattic_runtime_job_stepper_registry()[$type] ?? null;
    if (!is_callable($stepper)) {
        throw new StatticJobFatal('unknown_job_type');
    }
    // Every stepper takes ($privateRoot, $job) unconditionally — the registry
    // is the sole source of truth for which function handles a type; no
    // parallel arity list to keep in sync with it.
    $result = $stepper($privateRoot, $job);
    if (!is_array($result) || !array_key_exists('done', $result)) {
        throw new StatticJobFatal('invalid_stepper_result');
    }
    return $result;
}

// --- Run the claimed job until it finishes or the tick's budget runs out -------------

// True when another lane dead-lettered this job (tier_cancel) while we were
// stepping it: the dead record exists and the queue record was unlinked. The
// runner must stop and NOT write job state back into queue/ — that would
// resurrect a canceled job.
function _stattic_runtime_job_cancel_observed(string $privateRoot, array $job): bool
{
    return is_file(_stattic_runtime_job_dead_path($privateRoot, (string) $job['id']));
}

// Checked at every yield point in the run loop below (mid-step, post-yield,
// post-deadline): if true it has ALREADY unlinked the queue record and
// journaled the observation, so the caller's only job is to stop and report
// 'dead_letter' — writing job state back into queue/ here would resurrect a
// canceled job.
function _stattic_runtime_job_abort_if_canceled(string $privateRoot, array $job): bool
{
    if (!_stattic_runtime_job_cancel_observed($privateRoot, $job)) {
        return false;
    }
    @unlink(_stattic_runtime_job_path($privateRoot, (string) $job['id']));
    _stattic_runtime_append_journal($privateRoot, ['event' => 'job_cancel_observed', 'job_id' => $job['id'], 'type' => $job['type']]);
    return true;
}

// Persists the job as pending and journals the yield — identical whether the
// stepper itself yielded or the tick's own budget deadline forced it.
function _stattic_runtime_job_yield(string $privateRoot, array $job): string
{
    $job['status'] = 'pending';
    _stattic_runtime_write_json_atomic(_stattic_runtime_job_path($privateRoot, $job['id']), $job);
    _stattic_runtime_append_journal($privateRoot, ['event' => 'job_yielded', 'job_id' => $job['id'], 'type' => $job['type']]);
    return 'yielded';
}

// Bumps the attempt counter and stamps first_failed_at once — shared by all
// three stepper-failure catch arms below.
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
            _stattic_runtime_write_json_atomic(_stattic_runtime_job_path($privateRoot, $job['id']), $job);
            _stattic_runtime_append_journal($privateRoot, ['event' => 'job_complete', 'job_id' => $job['id'], 'type' => $job['type']]);
            _stattic_runtime_job_emit_callback($privateRoot, $job, array_merge(
                _stattic_runtime_job_callback_fields($job),
                [
                    'event' => 'job_complete',
                    'job_id' => $job['id'],
                    'result' => $job['result'],
                ]
            ));
            return 'complete';
        }

        if (microtime(true) >= $deadline) {
            // Budget yield, not a failure: leave attempt/backoff untouched so a
            // legitimately long job (curl-multi transfer, tiering walk) keeps
            // making progress across ticks instead of getting poisoned by its
            // own duration.
            if (_stattic_runtime_job_abort_if_canceled($privateRoot, $job)) {
                return 'dead_letter';
            }
            return _stattic_runtime_job_yield($privateRoot, $job);
        }

        _stattic_runtime_write_json_atomic(_stattic_runtime_job_path($privateRoot, $job['id']), $job);
    }
}

// --- Callback self-drain + housekeeping (bulk lane only) -----------------------------

function _stattic_runtime_job_bulk_self_drain(string $privateRoot, array $claims = []): array
{
    // Housekeeping runs FIRST so the callbacks it stages (disk reports, prune
    // confirmations, spooled tier failures) ride THIS tick's drain pass
    // instead of waiting for the next one. The tick's verified claims carry
    // the callback scope (cron watchdog + CP-driven ticks both attach one).
    _stattic_runtime_job_housekeeping($privateRoot, $claims);
    // No HTTP caller to hand unreachable-origin events back to (§10 CP-outage
    // resilience) — a push failure here must stay retryable in pending/, not
    // get moved to delivered/ as 'returned' and lost.
    $result = _stattic_runtime_drain_callback_events_core($privateRoot, false);
    if ($result['delivered_count'] > 0 || $result['failed_count'] > 0 || $result['expired_count'] > 0) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'runtime_callbacks_self_drained',
            'delivered_count' => $result['delivered_count'],
            'failed_count' => $result['failed_count'],
            'expired_count' => $result['expired_count'],
        ]);
    }
    return $result;
}

// Extensible registry: later waves append their own hook function here (each
// must be cheap and idempotent — this runs on every bulk tick, busy or idle).
// Repair-state retries and staging GC have no backing mechanism yet (no
// staging/ directory, no repair-state retry queue — just the single-snapshot
// repair-state.json from storage.php) — re-add their hook names here once
// they exist instead of carrying empty no-op reservations in the meantime.
function _stattic_runtime_job_housekeeping_hooks(): array
{
    return [
        '_stattic_runtime_job_housekeeping_prune_dead_jobs',
        '_stattic_runtime_job_housekeeping_local_blob_gc',
        '_stattic_runtime_job_housekeeping_disk_report',
        '_stattic_runtime_job_housekeeping_prune_versions',
        '_stattic_runtime_job_housekeeping_tier_events',
    ];
}

function _stattic_runtime_job_housekeeping(string $privateRoot, array $claims = []): void
{
    foreach (_stattic_runtime_job_housekeeping_hooks() as $hook) {
        if (is_callable($hook)) {
            $hook($privateRoot, $claims);
        }
    }
}

// dead/{jobId}.json is TTL-pruned, 14d (§22) — real (not a placeholder): a
// dead-lettered record has no other retention path once §22's own storage
// layout is followed.
function _stattic_runtime_job_housekeeping_prune_dead_jobs(string $privateRoot, array $claims = []): void
{
    $deadline = time() - STATTIC_RUNTIME_JOB_DEAD_LETTER_RETENTION_SECONDS;
    foreach (glob(_stattic_runtime_jobs_dead_dir($privateRoot) . '/*.json') ?: [] as $path) {
        if (!is_string($path)) {
            continue;
        }
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $deadline) {
            _stattic_runtime_assert_private_path($path);
            @unlink($path);
        }
    }
}

// --- The tick driver (plan §22) -------------------------------------------------------

function _stattic_runtime_job_tick(string $privateRoot, string $lane, int $budgetMs = STATTIC_RUNTIME_JOB_DEFAULT_BUDGET_MS, array $claims = [], ?string $jobId = null): array
{
    if (!in_array($lane, STATTIC_RUNTIME_JOB_LANES, true)) {
        throw new StatticJobFatal('invalid_lane');
    }
    _stattic_runtime_mkdir(_stattic_runtime_jobs_root($privateRoot));
    _stattic_runtime_mkdir(_stattic_runtime_jobs_queue_dir($privateRoot));
    _stattic_runtime_mkdir(_stattic_runtime_jobs_dead_dir($privateRoot));

    $lockPath = _stattic_runtime_job_lane_lock_path($privateRoot, $lane);
    _stattic_runtime_assert_private_path($lockPath);
    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
        throw new StatticJobFatal('lane_lock_unavailable');
    }
    // Non-blocking: a lane already ticking elsewhere means this call is a
    // no-op (§22 "held → exit 0"), never a queued wait — distinct from
    // _stattic_runtime_with_write_lock's blocking+503 semantics.
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return ['lane' => $lane, 'ranJobId' => null, 'status' => 'lane_locked'];
    }

    try {
        $budgetMs = max(0, min($budgetMs, STATTIC_RUNTIME_JOB_MAX_BUDGET_MS));
        // The lane lock is an OS flock, so a wedged PHP worker can otherwise
        // pin the lane forever. Bound the whole tick process slightly beyond
        // the requested cooperative budget; if a stepper ignores/yields late,
        // PHP tears the worker down and releases the flock. The job record is
        // then recovered by the existing heartbeat reaper on a later tick.
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

        if ($lane === 'bulk') {
            _stattic_runtime_job_bulk_self_drain($privateRoot, $claims);
        }

        return [
            'executionTimeoutSeconds' => $executionTimeoutSeconds,
            'lane' => $lane,
            'ranJobId' => $ranJobId,
            'status' => $status,
        ];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// --- Management routes (admin/management.php dispatch table) -------------------------

function _stattic_runtime_jobs_tick_lane_param(): string
{
    $lane = isset($_GET['lane']) && is_string($_GET['lane']) ? trim($_GET['lane']) : '';
    if (!in_array($lane, STATTIC_RUNTIME_JOB_LANES, true)) {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_lane', 'message' => 'lane must be one of: ' . implode(', ', STATTIC_RUNTIME_JOB_LANES) . '.']]);
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
        _stattic_json_response(422, ['error' => ['code' => 'invalid_budget_ms', 'message' => 'budget_ms must be a non-negative integer.']]);
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

// POST /jobs (create_engine_job): {type, payload, idempotency_key} — space_id
// and operation_id ride the verified JWT claims, not the body, like every
// other mutation route here.
function _stattic_runtime_jobs_create_route(string $privateRoot, array $claims): void
{
    $body = _stattic_json_body();
    $type = is_string($body['type'] ?? null) ? trim($body['type']) : '';
    $idempotencyKey = is_string($body['idempotency_key'] ?? null) ? trim($body['idempotency_key']) : '';
    $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
    if ($type === '' || $idempotencyKey === '') {
        _stattic_json_response(422, ['error' => ['code' => 'invalid_job_create_request', 'message' => 'type and idempotency_key are required.']]);
    }
    try {
        $record = _stattic_runtime_job_create($privateRoot, $type, $idempotencyKey, $payload, $claims);
    } catch (StatticJobFatal $error) {
        _stattic_json_response(422, ['error' => ['code' => $error->getMessage(), 'message' => 'Job could not be created.']]);
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
        _stattic_json_response(422, ['error' => ['code' => $error->getMessage(), 'message' => 'Job tick could not run.']]);
    }
    $job = $result['ranJobId'] !== null ? _stattic_runtime_job_load_any($privateRoot, $result['ranJobId']) : null;
    _stattic_json_response(200, [
        'lane' => $result['lane'],
        'job' => $job !== null ? _stattic_runtime_job_public_response($job) : null,
        // Self-drain runs on every bulk tick regardless of whether anything
        // was pending (§22) — this reports that the pass happened, not that
        // it delivered something.
        'drained' => $lane === 'bulk',
        'execution_timeout_seconds' => $result['executionTimeoutSeconds'] ?? null,
        // Extra (non-contractual) diagnostic beyond job.status: distinguishes
        // WHY the tick returned (idle/lane_locked/yielded/retry_scheduled/
        // dead_letter/complete), useful for the site-cron watchdog's own logs
        // and for tests — safe to add since callers parse this response
        // permissively and ignore unknown keys.
        'tick_status' => $result['status'],
    ]);
}

function _stattic_runtime_jobs_get_route(string $privateRoot, string $jobId): void
{
    $jobId = _stattic_runtime_id($jobId, 'job_id');
    $record = _stattic_runtime_job_load_any($privateRoot, $jobId);
    if ($record === null) {
        _stattic_json_response(404, ['error' => ['code' => 'job_not_found', 'message' => 'Job not found.']]);
    }
    _stattic_json_response(200, ['job' => _stattic_runtime_job_public_response($record)]);
}
