<?php
declare(strict_types=1);

// A Zero v2 production request that stores a due Action Scheduler action runs
// one bounded batch after its response, under the box worker's lock and the
// worker's own live-binding check. Zero v2 has no core cron, so without this
// the action waits for the next worker tick, which stays the fallback for
// delayed work, lock contention, overflow and a killed PHP worker.

const SPACEFAST_ZERO_V2_DRAIN_SECONDS = 5;
const SPACEFAST_ZERO_V2_DRAIN_ACTIONS = 10;
// The triggering visitor's identity never reaches a queued job.
const SPACEFAST_ZERO_V2_DRAIN_VISITOR_GLOBALS = [
    'SPACEFAST_CONTENT_PRINCIPAL',
    'SPACEFAST_CONTENT_WORDPRESS_ROLE',
    'SPACEFAST_CONTENT_ADMIN_USER_ID',
    'SPACEFAST_CONTENT_ADMIN_LAUNCH',
    'SPACEFAST_CONTENT_ADMIN_SESSION_EXPIRES_AT',
    'SPACEFAST_CONTENT_ADMIN_ACCESS',
    'SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN',
    'SPACEFAST_SPACE_ROLE',
    'SPACEFAST_SPACE_USERS_AUTH',
    'SPACEFAST_SPACE_USERS_WP_SESSION_USER',
    'SPACEFAST_SPACE_USERS_AUTHENTICATED_USER_ID',
    'SPACEFAST_SPACE_USERS_NATIVE_IDENTITY',
];
const SPACEFAST_ZERO_V2_DRAIN_VISITOR_SERVER_KEYS = [
    'HTTP_COOKIE',
    'HTTP_AUTHORIZATION',
    'REDIRECT_HTTP_AUTHORIZATION',
    'HTTP_X_SPACEFAST_AUTHORIZATION',
    'REDIRECT_HTTP_X_SPACEFAST_AUTHORIZATION',
    'HTTP_X_WP_NONCE',
    'HTTP_X_IDENTITY_CSRF',
    'PHP_AUTH_USER',
    'PHP_AUTH_PW',
    'REMOTE_USER',
    'REDIRECT_REMOTE_USER',
];

/** @return array{armed: bool, deadline: ?float} */
function &_stattic_zero_v2_drain_state(): array
{
    static $state = ['armed' => false, 'deadline' => null];
    return $state;
}

/** Called from the Zero v2 binding; CLI, cron and initialization never arm. */
function _stattic_zero_v2_drain_install(): void
{
    if (
        PHP_SAPI === 'cli'
        || (defined('WP_CLI') && WP_CLI)
        || (defined('DOING_CRON') && DOING_CRON)
        || ($GLOBALS['SPACEFAST_ZERO_V2_INITIALIZING'] ?? false) === true
    ) {
        return;
    }
    add_action('action_scheduler_stored_action', '_stattic_zero_v2_drain_on_stored');
}

function _stattic_zero_v2_drain_on_stored(mixed $actionId): void
{
    $state = &_stattic_zero_v2_drain_state();
    if ($state['armed'] || $state['deadline'] !== null || !function_exists('fastcgi_finish_request')) {
        return;
    }
    $serving = $GLOBALS['SPACEFAST_ZERO_V2_SERVING'] ?? null;
    $privateRoot = (string) ($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '');
    $spaceId = (string) ($GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? '');
    $versionId = (string) ($GLOBALS['SPACEFAST_ZERO_V2_VERSION_ID'] ?? '');
    if (
        !is_array($serving)
        || !_stattic_serving_is_production_host($serving)
        || is_array($GLOBALS['SPACEFAST_PREVIEW_GUARD'] ?? null)
        || $privateRoot === ''
        || !_stattic_id_valid($spaceId)
        || !_stattic_id_valid($versionId)
        || !_stattic_zero_v2_drain_action_due($actionId)
    ) {
        return;
    }
    $state['armed'] = true;
    _stattic_flush_response_before_deferred(true);
    // Nginx would otherwise buffer the upstream body until the drain ends.
    if (!headers_sent()) {
        header('X-Accel-Buffering: no');
    }
    _stattic_defer(static fn () => _stattic_zero_v2_drain($privateRoot, $spaceId, $versionId));
}

function _stattic_zero_v2_drain_action_due(mixed $actionId): bool
{
    if (!is_numeric($actionId) || (int) $actionId <= 0) {
        return false;
    }
    try {
        $store = ActionScheduler::store();
        if ($store->get_status((string) $actionId) !== ActionScheduler_Store::STATUS_PENDING) {
            return false;
        }
        $date = $store->fetch_action((string) $actionId)->get_schedule()->get_date();
        return $date === null || $date->getTimestamp() <= time();
    } catch (Throwable) {
        return false;
    }
}

/** One Action Scheduler run after the response, as the worker would, bounded in time and actions. */
function _stattic_zero_v2_drain(
    string $privateRoot,
    string $spaceId,
    string $versionId,
    float $seconds = SPACEFAST_ZERO_V2_DRAIN_SECONDS
): void {
    $state = &_stattic_zero_v2_drain_state();
    // A response FPM did not confirm as sent must not wait on jobs.
    if ($state['deadline'] !== null || !_stattic_response_finished_before_deferred()) {
        return;
    }
    $error = error_get_last();
    if (is_array($error) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    // A rolled-back write or another runner leaves nothing due: skip the lock.
    try {
        if (!ActionScheduler::store()->has_pending_actions_due()) {
            return;
        }
    } catch (Throwable) {
        return;
    }
    require_once __DIR__ . '/../../shared/lock.php';
    $lock = _stattic_lock_acquire($privateRoot . '/spaces/' . $spaceId . '/zero-v2-worker.lock', STATTIC_LOCK_TRY);
    if ($lock === false) {
        return;
    }
    try {
        // Under the lock, the version this request bound must still be live.
        require_once __DIR__ . '/zero-v2-worker.php';
        try {
            $live = _stattic_zero_worker_target($privateRoot, $spaceId, 'production');
        } catch (Throwable) {
            return;
        }
        if (($live['runtime'] ?? null) !== 'zero_v2' || ($live['version_id'] ?? null) !== $versionId) {
            return;
        }
        $deadline = microtime(true) + $seconds;
        $hooks = [
            'action_scheduler_queue_runner_time_limit' => [static fn (): int => max(1, (int) ceil($seconds)), 1],
            'action_scheduler_queue_runner_batch_size' => [static fn (): int => SPACEFAST_ZERO_V2_DRAIN_ACTIONS, 1],
            'action_scheduler_queue_runner_concurrent_batches' => [static fn (): int => 1, 1],
            // The runner loops batches until this says stop; its own clock starts at WordPress boot.
            // The limit only stops new actions: a started one keeps its calls' normal timeouts, so a
            // send is never cut short after the broker took it.
            'action_scheduler_maximum_execution_time_likely_to_be_exceeded' => [
                static fn (bool $exceeded, mixed $runner = null, int $processed = 0): bool =>
                    $processed >= SPACEFAST_ZERO_V2_DRAIN_ACTIONS || microtime(true) >= $deadline,
                3,
            ],
            'determine_current_user' => [static fn (): int => 0, 1],
        ];
        $saved = _stattic_zero_v2_drain_enter_anonymous();
        $state['deadline'] = $deadline;
        try {
            foreach ($hooks as $name => [$callback, $args]) {
                add_filter($name, $callback, PHP_INT_MAX, $args);
            }
            ActionScheduler::runner()->run('spacefast-immediate');
        } catch (Throwable $error) {
            error_log(sprintf('Zero v2 post-response drain failed space=%s: %s', $spaceId, $error->getMessage()));
        } finally {
            $state['deadline'] = null;
            foreach ($hooks as $name => [$callback]) {
                remove_filter($name, $callback, PHP_INT_MAX);
            }
            _stattic_zero_v2_drain_leave_anonymous($saved);
        }
    } finally {
        _stattic_lock_release($lock);
    }
}

/** Run jobs as the anonymous worker does; returns what to restore. */
function _stattic_zero_v2_drain_enter_anonymous(): array
{
    $saved = ['cookies' => $_COOKIE, 'server' => [], 'globals' => [], 'user_id' => get_current_user_id()];
    $_COOKIE = [];
    foreach (SPACEFAST_ZERO_V2_DRAIN_VISITOR_SERVER_KEYS as $key) {
        if (array_key_exists($key, $_SERVER)) {
            $saved['server'][$key] = $_SERVER[$key];
            unset($_SERVER[$key]);
        }
    }
    foreach (SPACEFAST_ZERO_V2_DRAIN_VISITOR_GLOBALS as $key) {
        if (array_key_exists($key, $GLOBALS)) {
            $saved['globals'][$key] = $GLOBALS[$key];
            unset($GLOBALS[$key]);
        }
    }
    // Space Users memoizes the request's auth here; null means no visitor.
    $GLOBALS['SPACEFAST_SPACE_USERS_AUTH'] = null;
    wp_set_current_user(0);
    return $saved;
}

function _stattic_zero_v2_drain_leave_anonymous(array $saved): void
{
    $_COOKIE = $saved['cookies'];
    foreach (SPACEFAST_ZERO_V2_DRAIN_VISITOR_SERVER_KEYS as $key) {
        unset($_SERVER[$key]);
    }
    $_SERVER = $saved['server'] + $_SERVER;
    foreach (SPACEFAST_ZERO_V2_DRAIN_VISITOR_GLOBALS as $key) {
        unset($GLOBALS[$key]);
    }
    foreach ($saved['globals'] as $key => $value) {
        $GLOBALS[$key] = $value;
    }
    wp_set_current_user($saved['user_id']);
}
