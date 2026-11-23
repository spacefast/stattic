<?php

declare(strict_types=1);

namespace TypedPress\Wp\Jobs;

use TypedPress\Jobs\Runner;
use TypedPress\Jobs\Storage;
use TypedPress\Wp\Runtime;

/**
 * WordPress delivery through Action Scheduler (§6.1). The `tp__jobs` row stays the source of truth
 * (attempts, backoff, dead); AS gets one single action per attempt, written in the transaction that
 * writes the job row. Action Scheduler writes through the global $wpdb, the connection TypedPress's
 * transactions run on (Wp\Connection), so the action commits or rolls back with the row; nothing is
 * handed over after commit, and a crash after commit loses nothing. TypedPress's own (job, key) dedupe runs
 * first, so AS uniqueness stays off.
 *
 * One thing Action Scheduler keeps outside the database: the id of each group it creates, cached for
 * ten minutes (ActionScheduler_DBStore::create_group). A group created inside a transaction that then
 * rolls back would stay cached under an id with no row, so prepare() creates TypedPress's groups at
 * `action_scheduler_init`, before any transaction, and every action later finds its group committed.
 */
final class ActionSchedulerQueue
{
    public const HOOK = 'tp_job';
    public const MAINTAIN = 'tp_jobs_maintain';
    /** The groups this site's jobs and maintenance use, recorded once they exist (prepare()). */
    public const PREPARED = 'tp_jobs_prepared';
    /**
     * A job with a schedule (`job({ every: "1h" }, fn)`, `cron("0 3 * * *", fn)`): one recurring or cron
     * action per job, args `[job, schedule]`, which queues the job each time it fires.
     */
    public const SCHEDULE = 'tp_job_schedule';
    /** The schedules of the build that last scheduled them ({@see schedules()}). */
    public const SCHEDULED = 'tp_jobs_scheduled';

    /** The group of the action Action Scheduler is executing right now. */
    private static string $group = '';
    /** When that action was scheduled for (Unix seconds), or null. */
    private static ?int $scheduled = null;

    public static function register(): void
    {
        add_action('action_scheduler_before_execute', static function (int $actionId): void {
            $action = \ActionScheduler::store()->fetch_action($actionId);
            self::$group = (string) ($action->get_group() ?? '');
            $date = $action->get_schedule()?->get_date();
            self::$scheduled = $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
        });
        add_action(self::HOOK, static function (string $id): void {
            $plugin = Runtime::plugin();
            if ($plugin !== null && $plugin->unavailable() !== null) {
                // Storage is behind this build: run the job once it has caught up, not on the old schema.
                self::schedule($id, \TypedPress\Js::iso(\TypedPress\Js::now() + 60_000));
            } elseif ($plugin?->app() !== null) {
                // A delivery scheduled for before its job's time is an older one: a failed attempt moved
                // the job later and scheduled its own delivery for then, so running now would skip the
                // backoff. The job's current delivery runs even if someone runs it early by hand.
                $runAt = \TypedPress\Jobs\Storage::run('SELECT "runAt" FROM {jobs} WHERE "id" = ?', [$id])[0]['runAt'] ?? null;
                if (is_string($runAt) && self::$scheduled !== null && \TypedPress\Js::date($runAt) > \TypedPress\Js::now()
                    && self::$scheduled < intdiv((int) \TypedPress\Js::date($runAt), 1000)) {
                    return;
                }
                Runner::run($id);
            } else {
                // No TypedPress app is running (deactivated or uninstalled, runtime still active). A job
                // still waiting to run keeps its delivery, in the same app's group, so a reactivated
                // or reinstalled app runs it; a cancelled, finished or missing job needs none.
                global $wpdb;
                // Through TypedPress's connection, which throws on a database error: a failed read must fail
                // this action, not complete it as if the job were gone.
                $state = (new \TypedPress\Wp\Connection($wpdb))->run('SELECT `state` FROM `' . $wpdb->prefix . 'tp__jobs` WHERE `id` = ?', [$id])[0]['state'] ?? null;
                if (!in_array($state, ['pending', 'failed'], true)) {
                    return;
                }
                if (as_schedule_single_action(time() + HOUR_IN_SECONDS, self::HOOK, ['id' => $id], self::$group !== '' ? self::$group : 'typedpress-app', false) === 0) {
                    // Couldn't keep a delivery: fail this action loudly (Action Scheduler records it as
                    // failed, with this message, in Tools > Scheduled Actions) rather than complete it.
                    throw new \RuntimeException("TypedPress job $id is waiting for its app, and Action Scheduler refused to keep its delivery");
                }
            }
        });
        add_action(self::SCHEDULE, static function (string $job, string $schedule): void {
            $plugin = Runtime::plugin();
            if ($plugin === null || $plugin->unavailable() !== null || $plugin->app() === null) {
                return; // no app, or storage behind the build: the schedule fires again
            }
            // Another app's schedule (a site that ran another TypedPress app) never runs this one's job.
            if (self::$group !== self::group($plugin)) {
                return;
            }
            if ((self::wanted($plugin)[$job] ?? null) !== $schedule) {
                // The build no longer has this schedule: its next instance is cancelled as it is stored.
                return;
            }
            // One run at a time: a run still waiting from the last firing takes this one's place.
            \TypedPress\Jobs\Jobs::enqueue($job, [], ['key' => 'schedule']);
        }, 10, 2);
        // Action Scheduler stores a recurring action's next instance after the current one runs, also
        // when the build changed while it ran. An instance of this app's schedules that the build doesn't
        // have, or a second pending one of a schedule it has, is cancelled as soon as it is stored.
        add_action('action_scheduler_stored_action', static function (int $actionId): void {
            $plugin = Runtime::plugin();
            if ($plugin === null || !\ActionScheduler::is_initialized()) {
                return;
            }
            $action = \ActionScheduler::store()->fetch_action($actionId);
            if ($action->get_hook() !== self::SCHEDULE || $action->get_group() !== self::group($plugin)) {
                return;
            }
            $args = array_values($action->get_args());
            $job = (string) ($args[0] ?? '');
            $schedule = (string) ($args[1] ?? '');
            $pending = as_get_scheduled_actions(['hook' => self::SCHEDULE, 'args' => $args, 'group' => self::group($plugin),
                'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 2, 'orderby' => 'none'], 'ids');
            $duplicate = array_filter($pending, static fn ($id): bool => (int) $id !== $actionId) !== [];
            if ((self::wanted($plugin)[$job] ?? null) !== $schedule || $duplicate) {
                \ActionScheduler::store()->cancel_action($actionId);
            }
        });
        add_action(self::MAINTAIN, static function (): void {
            // Maintenance mutates jobs and receipts: skip it while storage is behind; it recurs.
            $plugin = Runtime::plugin();
            if ($plugin !== null && $plugin->unavailable() === null && $plugin->app() !== null) {
                Runner::maintain();
            }
        });
        // Installing, not serving: a managed build prepares and schedules from initialize.
        add_action('action_scheduler_init', static fn () => Runtime::installs() ? self::install() : null, 0);
    }

    /**
     * How often maintenance runs. Recovery of jobs that lost their action or their runner waits for it,
     * so it is hourly; each run is a few indexed queries.
     */
    public const MAINTAIN_EVERY = HOUR_IN_SECONDS;

    /** TypedPress's Action Scheduler groups, the jobs left without an action, and the hourly maintenance. */
    public static function install(): void
    {
        if (Runtime::plugin() === null) {
            return;
        }
        self::prepare();
        if (!self::maintenanceScheduled()) {
            $connection = Runtime::plugin()->connection();
            if (!$connection->inTransaction()) {
                try {
                    // One request at a time, so two first requests don't both add a schedule.
                    \TypedPress\Wp\Migrator::locked($connection, self::MAINTAIN, static function (): void {
                        if (self::maintenanceScheduled()) {
                            return;
                        }
                        // A site scheduled by an older runtime keeps its old interval: Action Scheduler repeats a
                        // recurring action as it was scheduled. Schedule the current one first and cancel the old
                        // run only once that worked, so a refused schedule never leaves the site without maintenance.
                        $old = self::pendingMaintenance();
                        if (as_schedule_recurring_action(time() + self::MAINTAIN_EVERY, self::MAINTAIN_EVERY, self::MAINTAIN, [], 'typedpress') === 0) {
                            throw new \RuntimeException('Action Scheduler refused to schedule TypedPress job maintenance');
                        }
                        if ($old !== null) {
                            \ActionScheduler::store()->cancel_action($old);
                        }
                    });
                } catch (\Throwable $e) {
                    // initialize's receipt must mean maintenance is scheduled; a request keeps serving, leaves
                    // the install marker unrecorded (installed() is false) and the next request retries.
                    if (Runtime::managed()) {
                        throw $e;
                    }
                    error_log('[typedpress] scheduling job maintenance failed (next request retries): ' . $e->getMessage());
                }
            }
        }
        self::schedule_jobs();
    }

    /** What {@see install()} leaves: the groups prepared for this app, the maintenance and the job schedules scheduled. */
    public static function installed(): bool
    {
        $plugin = Runtime::plugin();

        return $plugin === null || (get_option(self::PREPARED) === self::prepared($plugin) && self::maintenanceScheduled()
            && get_option(self::SCHEDULED) === self::fingerprint($plugin));
    }

    /**
     * Whether maintenance recurs every {@see MAINTAIN_EVERY}. A run in progress counts as scheduled, as
     * in as_has_scheduled_action(): it schedules its next instance when it finishes, and replacing it
     * now would leave two.
     */
    private static function maintenanceScheduled(): bool
    {
        $pending = self::pendingMaintenance();
        if ($pending === null) {
            return as_has_scheduled_action(self::MAINTAIN, [], 'typedpress');
        }
        $schedule = \ActionScheduler::store()->fetch_action($pending)->get_schedule();

        return $schedule instanceof \ActionScheduler_IntervalSchedule && (int) $schedule->get_recurrence() === self::MAINTAIN_EVERY;
    }

    /** The id of the pending maintenance run, or null. */
    private static function pendingMaintenance(): ?string
    {
        $id = \ActionScheduler::store()->query_action(['hook' => self::MAINTAIN, 'group' => 'typedpress', 'status' => \ActionScheduler_Store::STATUS_PENDING]);

        return $id === null || $id === '' || $id === 0 ? null : (string) $id;
    }

    /** The Action Scheduler group of this app's jobs. */
    private static function group(\TypedPress\Wp\Plugin $plugin): string
    {
        return 'typedpress-' . $plugin->schema->name();
    }

    /**
     * Each scheduled job of the build and its schedule as the action's second argument:
     * `every:3600` or `cron:0 3 * * *`.
     *
     * @return array<string, string>
     */
    private static function wanted(\TypedPress\Wp\Plugin $plugin): array
    {
        $out = [];
        foreach ($plugin->schema->jobs() as $name => $job) {
            $schedule = $job['schedule'] ?? null;
            if (isset($schedule['every'])) {
                $out[(string) $name] = 'every:' . (int) $schedule['every'];
            } elseif (isset($schedule['cron'])) {
                $out[(string) $name] = 'cron:' . $schedule['cron'];
            }
        }
        ksort($out);

        return $out;
    }

    private static function fingerprint(\TypedPress\Wp\Plugin $plugin): string
    {
        return 'v1:' . self::group($plugin) . ':' . hash('sha256', (string) json_encode(self::wanted($plugin)));
    }

    /**
     * Once per build: the build's job schedules in Action Scheduler, each as one recurring or cron action,
     * and the app's schedule actions no job of this build has (a removed job, a changed schedule) taken
     * out. Outside any transaction, like prepare(), and one request at a time.
     */
    private static function schedule_jobs(): void
    {
        $plugin = Runtime::plugin();
        if ($plugin === null || $plugin->unavailable() !== null) {
            return;
        }
        $done = self::fingerprint($plugin);
        if (get_option(self::SCHEDULED) === $done) {
            return;
        }
        $connection = $plugin->connection();
        if ($connection->inTransaction()) {
            return;
        }
        try {
            \TypedPress\Wp\Migrator::locked($connection, self::SCHEDULED, static function () use ($plugin, $done): void {
                wp_cache_delete(self::SCHEDULED, 'options');
                if (get_option(self::SCHEDULED) === $done) {
                    return;
                }
                $group = self::group($plugin);
                $wanted = self::wanted($plugin);
                $have = [];
                $pending = as_get_scheduled_actions(['hook' => self::SCHEDULE, 'group' => $group, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1], 'ids');
                foreach ($pending as $id) {
                    $args = array_values(\ActionScheduler::store()->fetch_action((int) $id)->get_args());
                    [$job, $schedule] = [(string) ($args[0] ?? ''), (string) ($args[1] ?? '')];
                    if (($wanted[$job] ?? null) === $schedule && !isset($have[$job])) {
                        $have[$job] = true;
                    } else {
                        \ActionScheduler::store()->cancel_action((int) $id);
                    }
                }
                foreach ($wanted as $job => $schedule) {
                    if (isset($have[$job])) {
                        continue;
                    }
                    [$kind, $value] = explode(':', $schedule, 2);
                    $id = $kind === 'every'
                        ? as_schedule_recurring_action(time() + (int) $value, (int) $value, self::SCHEDULE, [$job, $schedule], $group)
                        : as_schedule_cron_action(time(), $value, self::SCHEDULE, [$job, $schedule], $group);
                    if ($id === 0) {
                        throw new \RuntimeException("Action Scheduler refused the schedule of job $job ($schedule)");
                    }
                }
                update_option(self::SCHEDULED, $done, true);
            });
        } catch (\Throwable $e) {
            // The next request (or deploy) tries again; the jobs themselves still run when queued.
            error_log('[typedpress] scheduling jobs failed (next request retries): ' . $e->getMessage());
        }
    }

    /** A runtime that changes what preparing checks or creates bumps the version, so sites prepare again. */
    private static function prepared(\TypedPress\Wp\Plugin $plugin): string
    {
        return 'v1:typedpress,typedpress-' . $plugin->schema->name();
    }

    /**
     * Create the Action Scheduler groups outside app transactions. Retry on the next request
     * after a failure.
     */
    private static function prepare(): void
    {
        $plugin = Runtime::plugin();
        if ($plugin === null || $plugin->unavailable() !== null) {
            return; // storage is behind this build; a later request prepares
        }
        $groups = ['typedpress', 'typedpress-' . $plugin->schema->name()];
        $done = self::prepared($plugin);
        if (get_option(self::PREPARED) === $done) {
            return;
        }
        $connection = $plugin->connection();
        if ($connection->inTransaction()) {
            return; // never inside a transaction: that is the hazard this avoids
        }
        try {
            // One request at a time, so two first requests don't both create a group.
            \TypedPress\Wp\Migrator::locked($connection, self::PREPARED, static function () use ($connection, $groups, $done, $plugin): void {
                wp_cache_delete(self::PREPARED, 'options');
                if (get_option(self::PREPARED) === $done) {
                    return;
                }
                $myisam = self::nonTransactional($connection);
                if ($myisam !== []) {
                    // A job's action is written in its transaction; on these tables a rollback would leave it.
                    throw new \RuntimeException('TypedPress needs InnoDB tables for Action Scheduler; these are not: ' . implode(', ', $myisam));
                }
                self::createGroups($connection, $groups);
                // Recover waiting jobs whose delivery is missing.
                $plugin->app();
                Runner::recover();
                update_option(self::PREPARED, $done, true); // read on every request
            });
        } catch (\Throwable $e) {
            // TypedPress refuses writes for this request; the next one retries.
            $plugin->refuse('preparing Action Scheduler for jobs failed: ' . $e->getMessage());
            error_log('[typedpress] preparing Action Scheduler for jobs failed (next request retries): ' . $e->getMessage());
        }
    }

    /** @return list<string> Action Scheduler's actions and groups tables when they aren't InnoDB (none on SQLite). */
    private static function nonTransactional(\TypedPress\Wp\Connection $connection): array
    {
        global $wpdb;
        $rows = $connection->run('SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)',
            [$wpdb->prefix . 'actionscheduler_actions', $wpdb->prefix . 'actionscheduler_groups']);

        return array_values(array_map(static fn (array $r): string => (string) $r['t'], array_filter($rows, static fn (array $r): bool => strcasecmp((string) $r['e'], 'InnoDB') !== 0)));
    }

    /** @param list<string> $groups */
    private static function createGroups(\TypedPress\Wp\Connection $connection, array $groups): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'actionscheduler_groups';
        foreach ($groups as $slug) {
            $connection->run("INSERT INTO `$table` (`slug`) SELECT ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `$table` WHERE `slug` = ?)", [$slug, $slug]);
            // The committed row, so an id a rolled-back creation left in a persistent cache is replaced.
            $groupId = (int) ($connection->run("SELECT MIN(`group_id`) AS `id` FROM `$table` WHERE `slug` = ?", [$slug])[0]['id'] ?? 0);
            if ($groupId > 0) {
                wp_cache_set($slug, $groupId, \ActionScheduler_DBStore::GROUP_IDS_CACHE_GROUP, 10 * MINUTE_IN_SECONDS);
            }
        }
    }

    /** One delivery of job $id at $runAtIso (never in the past), in the caller's transaction when one is open. */
    public static function schedule(string $id, string $runAtIso): void
    {
        if (!\ActionScheduler::is_initialized()) {
            // Before `init` (a wp-hooks handler at priority 0): Action Scheduler can't write yet. The job
            // row commits; its action follows once Action Scheduler is ready, and if this request dies
            // first, Runner::recover gives it one.
            // Through ensureDelivery: Runner::recover may have given it its action first.
            \TypedPress\Extensions::afterCommit(static function () use ($id): void {
                \ActionScheduler::is_initialized() ? Runner::ensureDelivery($id)
                    : add_action('action_scheduler_init', static fn () => Runner::ensureDelivery($id));
            });

            return;
        }
        $app = Runtime::plugin()?->schema->name() ?? 'app';
        $at = (int) floor(\TypedPress\Js::date($runAtIso) / 1000);
        if (as_schedule_single_action(max($at, time()), self::HOOK, ['id' => $id], 'typedpress-' . $app, false) === 0) {
            throw new \RuntimeException("Action Scheduler refused to schedule job $id");
        }
    }
}
