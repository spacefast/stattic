<?php
declare(strict_types=1);

// Executed only by the engine worker through the selected version's WP-CLI boot.
if (!defined('WP_CLI') || !WP_CLI || !\Zero\Wp\Runtime::managed()
    || ($GLOBALS['SPACEFAST_ZERO_V2_ENVIRONMENT'] ?? null) !== 'production') {
    throw new RuntimeException('An explicit managed Zero worker binding is required.');
}
(static function (array $args): void {
    global $wpdb;
    $plugin = \Zero\Wp\Runtime::plugin();
    if ($plugin === null || $plugin->unavailable() !== null) throw new RuntimeException('Zero worker storage is unavailable.');
    $plugin->app();
    $limit = max(1, min(100, (int) ($args[0] ?? 25)));
    $processed = 0;
    $failures = 0;
    // The upstream maintenance sweep is unbounded. Keep its recovery/retention rules,
    // using its public constants, but cap each page in the host's scheduled worker.
    remove_all_actions(\Zero\Wp\Jobs\ActionSchedulerQueue::MAINTAIN);
    add_action(\Zero\Wp\Jobs\ActionSchedulerQueue::MAINTAIN, static function () use ($limit): void {
        \Zero\db()->transaction(static function () use ($limit): void {
            $now = \Zero\Js::now();
            $stuck = \Zero\Jobs\Storage::run('SELECT "id" FROM {jobs} WHERE "state" = \'running\' AND "lockedAt" < ? ORDER BY "lockedAt", "id" LIMIT ' . $limit . \Zero\Jobs\Storage::forUpdate(), [\Zero\Js::iso($now - \Zero\Jobs\Runner::STUCK_MS)]);
            foreach ($stuck as $row) {
                \Zero\Jobs\Storage::run('UPDATE {jobs} SET "state" = \'pending\', "lockedAt" = NULL, "runAt" = ? WHERE "id" = ?', [\Zero\Js::iso($now), $row['id']]);
                \Zero\Jobs\Outbox::queue()->schedule($row['id'], \Zero\Js::iso($now));
            }
            \Zero\Jobs\Storage::run('DELETE FROM {receipts} WHERE "createdAt" < ? LIMIT ' . $limit, [\Zero\Js::iso($now - \Zero\Jobs\Runner::RECEIPT_TTL_MS)]);
            if (count($stuck) === $limit || \Zero\Jobs\Storage::changes() === $limit) {
                if (as_schedule_single_action(time() + 60, \Zero\Wp\Jobs\ActionSchedulerQueue::MAINTAIN, [], 'zero') === 0) {
                    throw new RuntimeException('Cannot schedule bounded Zero maintenance continuation.');
                }
            }
        });
    });
    $tickets = \Zero\Jobs\Storage::run('SELECT "id" FROM {outbox} ORDER BY "createdAt", "id" LIMIT ' . $limit);
    $dispatched = \Zero\Jobs\Outbox::dispatch(array_column($tickets, 'id'));
    if ($dispatched < count($tickets)) $failures += count($tickets) - $dispatched;
    $core = wp_get_ready_cron_jobs();
    $reserveCore = false;
    foreach ($core as $hooks) {
        if (array_diff(array_keys($hooks), ['action_scheduler_run_queue']) !== []) { $reserveCore = true; break; }
    }
    // A full app queue must leave room for AS and identity/core maintenance.
    $directLimit = max(1, intdiv($limit, 2));
    $now = \Zero\Js::iso(\Zero\Js::now());
    $jobs = \Zero\Jobs\Storage::run('SELECT "id" FROM {jobs} WHERE "state" IN (\'pending\',\'failed\') AND "runAt" <= ? ORDER BY "runAt", "id" LIMIT ' . $directLimit, [$now]);
    $runJob = static function (string $id) use (&$failures): void {
        // The module's after-commit boundary keeps native effects outside the CLI transaction.
        $run = WP_CLI::runcommand('zero jobs run ' . escapeshellarg($id), ['return' => 'all', 'exit_error' => false, 'launch' => false]);
        $data = json_decode($run->stdout, true);
        if ($run->return_code !== 0 || ($data['data']['state'] ?? null) !== 'done') $failures++;
    };
    foreach ($jobs as $job) {
        $runJob($job['id']);
        $processed++;
    }
    // An old AS ticket may still be due after direct CLI execution failed and
    // moved the job's runAt forward. Respect that retry boundary before dispatch.
    remove_all_actions(\Zero\Wp\Jobs\ActionSchedulerQueue::HOOK);
    add_action(\Zero\Wp\Jobs\ActionSchedulerQueue::HOOK, static function (string $id) use ($runJob): void {
        $due = \Zero\Jobs\Storage::run('SELECT "id" FROM {jobs} WHERE "id" = ? AND "state" IN (\'pending\',\'failed\') AND "runAt" <= ?', [$id, \Zero\Js::iso(\Zero\Js::now())]);
        if ($due !== []) $runJob($id);
    });
    // Platform maintenance (uploads, job recovery, identity dispatch) shares the scoped WP tables.
    $actionLimit = max(0, $limit - $processed - ($reserveCore ? 1 : 0));
    if ($actionLimit > 0 && class_exists('ActionScheduler')) {
        $actions = ActionScheduler::store()->query_actions(['status' => 'pending', 'date' => new DateTime('now', new DateTimeZone('UTC')), 'date_compare' => '<=', 'per_page' => $actionLimit, 'orderby' => 'date', 'order' => 'ASC']);
        foreach ($actions as $id) {
            ActionScheduler::runner()->process_action($id, 'spacefast-zero-worker');
            $processed++;
            if (ActionScheduler::store()->get_status($id) === 'failed') $failures++;
        }
    }
    // Execute core's scoped due events directly; never spawn wp-cron or an HTTP request.
    foreach ($core as $timestamp => $hooks) foreach ($hooks as $hook => $events) foreach ($events as $event) {
        if ($processed >= $limit) break 3;
        if (!empty($event['schedule'])) wp_reschedule_event($timestamp, $event['schedule'], $hook, $event['args'], true);
        wp_unschedule_event($timestamp, $hook, $event['args'], true);
        if ($hook === 'action_scheduler_run_queue') continue; // AS is bounded explicitly above.
        try { do_action_ref_array($hook, $event['args']); } catch (Throwable) { $failures++; }
        $processed++;
    }
    WP_CLI::line(json_encode(['processed' => $processed, 'failures' => $failures, 'outbox_dispatched' => $dispatched], JSON_THROW_ON_ERROR));
})(isset($args) && is_array($args) ? $args : []);
