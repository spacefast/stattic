<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/context.php';
require_once __DIR__ . '/../../shared/storage.php';
require_once __DIR__ . '/../../shared/artifacts.php';
require_once __DIR__ . '/../../shared/db-broker.php';
require_once __DIR__ . '/../../shared/native-process.php';
require_once __DIR__ . '/../../shared/spacepress.php';
require_once __DIR__ . '/zero-v2.php';

/** Only the current production binding may execute autonomous work. */
function _stattic_zero_worker_target(string $privateRoot, string $spaceId, string $environment): ?array
{
    if ($environment !== 'production' || !_stattic_id_valid($spaceId)) throw new InvalidArgumentException('Invalid Zero worker scope.');
    $pointer = _stattic_runtime_read_json_strict($privateRoot . '/spaces/' . $spaceId . '/space.json');
    $overlay = _stattic_v4_overlay($privateRoot, $spaceId, $pointer);
    if ($overlay === false) throw new RuntimeException('Zero worker routing is unavailable.');
    if (!is_array($overlay)) return null;
    $version = $overlay['versions']['production'] ?? null;
    if (!is_string($version) || !_stattic_id_valid($version)) return null;
    $versionRoot = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $version;
    $spacepressRoot = _stattic_spacepress_version_root($versionRoot);
    if ($spacepressRoot !== null) {
        if (!is_file($spacepressRoot . '/ready.json')) throw new RuntimeException('SpacePress live version is not ready.');
        $manifest = _stattic_spacepress_manifest($spacepressRoot);
        return [
            'space_id' => $spaceId,
            'environment' => $environment,
            'version_id' => $version,
            'runtime' => 'spacepress',
            'legacy_prefix' => _stattic_spacepress_uses_legacy_prefix($manifest, $overlay),
            'legacy_typedpress' => _stattic_spacepress_is_legacy_typedpress($manifest),
        ];
    }
    if (($overlay['zero_generation'] ?? null) !== 'v2') return null;
    $root = $versionRoot . '/.zero-compiled';
    if (!is_file($root . '/ready.json')) throw new RuntimeException('Zero worker live version is not ready.');
    return [
        'space_id' => $spaceId,
        'environment' => $environment,
        'version_id' => $version,
        'runtime' => 'zero_v2',
        'legacy_prefix' => true,
        'legacy_typedpress' => false,
    ];
}

/** Native, scoped SQL only: an idle tick never loads WordPress or any app code. */
function _stattic_zero_worker_probe(
    string $spaceId,
    bool $spacepress = false,
    ?string $tablePrefix = null,
    bool $legacyTypedpress = false
): array {
    _stattic_db_broker_bind_provider();
    _stattic_db_broker_set_read_deadline_ms(2000);
    $db = _stattic_db_broker_connection();
    if (!$db instanceof mysqli) throw new RuntimeException('Zero worker database is unavailable.');
    $prefix = is_string($tablePrefix) && $tablePrefix !== ''
        ? $tablePrefix
        : 'sfz_' . substr(hash('sha256', $spaceId), 0, 24) . '_';
    $read = static function (string $suffix, string $columns, string $where = '') use ($db, $prefix): array {
        $result = $db->query('SELECT ' . $columns . ' FROM `' . $prefix . $suffix . '` ' . $where);
        if ($result === false) {
            if ($db->errno === 1146) return []; // Features with no tables have no queued work.
            throw new RuntimeException('Cannot inspect the scoped Zero queue.');
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    };
    // Due means work `wp cron event run --due-now` would do: a due Action Scheduler
    // action (jobs, mail, maintenance) or a due core event other than AS's own runner.
    // Queue rows only age the status; their AS action is what runs them.
    $oldest = [];
    $due = false;
    $queuePrefix = !$spacepress ? 'zero__' : ($legacyTypedpress ? 'tp__' : 'sp__');
    $outbox = $read($queuePrefix . 'outbox', 'MIN(`createdAt`) AS oldest');
    if (isset($outbox[0]['oldest'])) $oldest[] = strtotime($outbox[0]['oldest']);
    $jobs = $read($queuePrefix . 'jobs', 'MIN(`createdAt`) AS oldest', "WHERE `state` IN ('pending','failed')");
    if (isset($jobs[0]['oldest'])) $oldest[] = strtotime($jobs[0]['oldest']);
    $actions = $read('actionscheduler_actions', 'MIN(`scheduled_date_gmt`) AS next', "WHERE `status` = 'pending'");
    if (isset($actions[0]['next'])) {
        $at = strtotime($actions[0]['next'] . ' UTC');
        if ($at <= time()) { $due = true; $oldest[] = $at; }
    }
    $cron = $read('options', '`option_value`', "WHERE `option_name` = 'cron'");
    $events = isset($cron[0]['option_value']) ? @unserialize($cron[0]['option_value'], ['allowed_classes' => false]) : [];
    if (is_array($events)) foreach ($events as $at => $hooks) {
        if (is_numeric($at) && (int) $at <= time() && is_array($hooks) && array_diff(array_keys($hooks), ['action_scheduler_run_queue']) !== []) { $due = true; $oldest[] = (int) $at; }
    }
    return ['due' => $due, 'oldest_pending_age_seconds' => $oldest === [] ? 0 : max(0, time() - min($oldest))];
}

/**
 * The cause of a failed tick: WP-CLI prints it first on stderr, ahead of the
 * stack trace, while stdout lists events that already ran. Notices are noise.
 * Printable ASCII only, so a cut multibyte character never breaks the status.
 */
function _stattic_zero_worker_error_detail(string $stderr, string $stdout): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr)), static fn (string $line): bool => $line !== ''
        && preg_match('/^(PHP )?(Deprecated|Notice|Warning|Strict Standards):/', $line) !== 1 && !str_starts_with($line, '#') && !str_starts_with($line, 'Stack trace:')));
    $detail = $lines === [] ? trim(substr(trim($stdout), -300)) : implode("\n", array_slice($lines, 0, 3));
    return trim(substr((string) preg_replace('/[^\x20-\x7e\n]+/', '?', $detail), 0, 500));
}

/**
 * One tick for one Space: bind its live version, then let stock WP-Cron run
 * what is due. Action Scheduler's queue runner bounds itself; app job failures
 * stay in its log, and only a failed tick (boot, fatal, timeout) fails the run.
 */
function _stattic_zero_worker_run(string $privateRoot, string $spaceId, string $environment, int $budgetMs = 45000): array
{
    $started = microtime(true);
    $spaceRoot = $privateRoot . '/spaces/' . $spaceId;
    if (!_stattic_id_valid($spaceId) || !is_dir($spaceRoot)) throw new InvalidArgumentException('Unknown worker Space.');
    $lock = _stattic_lock_acquire($spaceRoot . '/zero-v2-worker.lock', STATTIC_LOCK_TRY);
    if ($lock === false) return ['space_id' => $spaceId, 'status' => 'busy', 'processed' => 0];
    $status = ['space_id' => $spaceId, 'environment' => $environment, 'last_run' => gmdate('c'), 'processed' => 0, 'failures' => 0, 'oldest_pending_age_seconds' => 0, 'status' => 'idle', 'wordpress_booted' => false];
    try {
        $target = _stattic_zero_worker_target($privateRoot, $spaceId, $environment);
        if ($target === null) { $status['status'] = 'inactive'; return $status; }
        $status['version_id'] = $target['version_id'];
        $spacepress = ($target['runtime'] ?? '') === 'spacepress';
        $legacyPrefix = ($target['legacy_prefix'] ?? false) === true;
        $legacyTypedpress = ($target['legacy_typedpress'] ?? false) === true;
        $prefix = null;
        if ($spacepress && !$legacyPrefix) {
            require_once __DIR__ . '/../../shared/space-users-site.php';
            $site = _stattic_space_users_site_config(dirname($privateRoot, 2));
            $prefix = is_array($site) && is_string($site['prefix'] ?? null) && $site['prefix'] !== ''
                ? $site['prefix']
                : 'wp_';
        }
        $probe = _stattic_zero_worker_probe($spaceId, $spacepress, $prefix, $legacyTypedpress);
        $status['oldest_pending_age_seconds'] = $probe['oldest_pending_age_seconds'];
        if (!$probe['due']) return $status;
        $wpLoad = realpath(dirname($privateRoot, 2) . '/wp-load.php');
        if (!is_string($wpLoad)) throw new RuntimeException('WordPress is unavailable for Zero work.');
        // Recheck authority in the child immediately before selecting the immutable runtime.
        $bootstrap = 'require_once ' . var_export(__FILE__, true) . ';'
            . '$_sf_current = _stattic_zero_worker_target(' . var_export($privateRoot, true) . ',' . var_export($spaceId, true) . ',' . var_export($environment, true) . ');'
            . 'if (($_sf_current["version_id"] ?? null) !== ' . var_export($target['version_id'], true) . ' || (($_sf_current["legacy_prefix"] ?? false) === true) !== ' . ($legacyPrefix ? 'true' : 'false') . ') throw new RuntimeException("Zero worker live version changed.");'
            . _stattic_zero_v2_wp_bootstrap($privateRoot, $spaceId, $target['version_id'], false, $legacyPrefix);
        $remaining = min(50000, $budgetMs) - (int) ((microtime(true) - $started) * 1000);
        if ($remaining <= 0) throw new RuntimeException('Zero worker time budget exhausted.');
        $status['wordpress_booted'] = true;
        $result = _stattic_runtime_run_subprocess(['wp', '--path=' . dirname($wpLoad), '--exec=' . $bootstrap,
            'cron', 'event', 'run', '--due-now'], null, null, dirname($wpLoad), $remaining, 262144, 65536);
        // Newer WP-CLI skips, exiting 0, while core's doing_cron lock is fresh.
        if ($result['spawned'] && !$result['timedOut'] && $result['exitCode'] === 0
            && str_contains($result['stdout'] . $result['stderr'], 'A cron event run is already in progress')) {
            return $status;
        }
        if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0
            || preg_match('/Executed a total of (\d+) cron events?\./', $result['stdout'], $match) !== 1) {
            $detail = _stattic_zero_worker_error_detail($result['stderr'], $result['stdout']);
            throw new RuntimeException(($result['timedOut'] ? 'Zero worker time budget exhausted.' : 'Zero worker command failed.') . ($detail === '' ? '' : ' ' . $detail));
        }
        $status['processed'] = (int) $match[1];
        $status['status'] = 'ran';
        $status['oldest_pending_age_seconds'] = _stattic_zero_worker_probe($spaceId, $spacepress, $prefix, $legacyTypedpress)['oldest_pending_age_seconds'];
        return $status;
    } catch (Throwable $error) {
        $status['status'] = 'failed';
        $status['failures']++;
        $status['error'] = $error->getMessage();
        return $status;
    } finally {
        try { _stattic_runtime_write_json_atomic($spaceRoot . '/zero-v2-worker-status.json', $status); }
        finally { _stattic_lock_release($lock); }
    }
}
