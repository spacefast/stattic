<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/db-broker.php';
require_once __DIR__ . '/../shared/native-process.php';
require_once __DIR__ . '/../shared/zero-v2.php';

/** Only the current production binding may execute autonomous work. */
function _stattic_zero_worker_target(string $privateRoot, string $spaceId, string $environment): ?array
{
    if ($environment !== 'production' || !_stattic_id_valid($spaceId)) throw new InvalidArgumentException('Invalid Zero worker scope.');
    $pointer = _stattic_runtime_read_json_strict($privateRoot . '/spaces/' . $spaceId . '/space.json');
    $overlay = _stattic_v4_overlay($privateRoot, $spaceId, $pointer);
    if ($overlay === false) throw new RuntimeException('Zero worker routing is unavailable.');
    if (!is_array($overlay) || ($overlay['zero_generation'] ?? null) !== 'v2') return null;
    $version = $overlay['versions']['production'] ?? null;
    if (!is_string($version) || !_stattic_id_valid($version)) return null;
    $root = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $version . '/.zero-compiled';
    if (!is_file($root . '/ready.json')) throw new RuntimeException('Zero worker live version is not ready.');
    return ['space_id' => $spaceId, 'environment' => $environment, 'version_id' => $version];
}

/** Native, scoped SQL only: an idle tick never loads WordPress or any app code. */
function _stattic_zero_worker_probe(string $spaceId): array
{
    _stattic_db_broker_bind_provider();
    _stattic_db_broker_set_read_deadline_ms(2000);
    $db = _stattic_db_broker_connection();
    if (!$db instanceof mysqli) throw new RuntimeException('Zero worker database is unavailable.');
    $prefix = 'sfz_' . substr(hash('sha256', $spaceId), 0, 24) . '_';
    $read = static function (string $suffix, string $columns, string $where = '') use ($db, $prefix): array {
        $result = $db->query('SELECT ' . $columns . ' FROM `' . $prefix . $suffix . '` ' . $where);
        if ($result === false) {
            if ($db->errno === 1146) return []; // Features with no tables have no queued work.
            throw new RuntimeException('Cannot inspect the scoped Zero queue.');
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    };
    $now = gmdate('Y-m-d\TH:i:s') . '.000Z';
    $oldest = [];
    $due = false;
    $outbox = $read('zero__outbox', 'MIN(`createdAt`) AS oldest');
    if (isset($outbox[0]['oldest'])) { $due = true; $oldest[] = strtotime($outbox[0]['oldest']); }
    $jobs = $read('zero__jobs', 'MIN(`createdAt`) AS oldest, MIN(`runAt`) AS next', "WHERE `state` IN ('pending','failed')");
    if (isset($jobs[0]['oldest'])) $oldest[] = strtotime($jobs[0]['oldest']);
    $due = $due || (isset($jobs[0]['next']) && $jobs[0]['next'] <= $now);
    $actions = $read('actionscheduler_actions', 'MIN(`scheduled_date_gmt`) AS next', "WHERE `status` = 'pending'");
    if (isset($actions[0]['next'])) {
        $at = strtotime($actions[0]['next'] . ' UTC');
        $due = $due || $at <= time();
        if ($at <= time()) $oldest[] = $at;
    }
    $cron = $read('options', '`option_value`', "WHERE `option_name` = 'cron'");
    $events = isset($cron[0]['option_value']) ? @unserialize($cron[0]['option_value'], ['allowed_classes' => false]) : [];
    if (is_array($events)) foreach ($events as $at => $hooks) {
        if (is_numeric($at) && (int) $at <= time() && is_array($hooks) && array_diff(array_keys($hooks), ['action_scheduler_run_queue']) !== []) { $due = true; $oldest[] = (int) $at; }
    }
    return ['due' => $due, 'oldest_pending_age_seconds' => $oldest === [] ? 0 : max(0, time() - min($oldest))];
}

function _stattic_zero_worker_run(string $privateRoot, string $spaceId, string $environment, int $limit = 25, int $budgetMs = 45000): array
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
        $probe = _stattic_zero_worker_probe($spaceId);
        $status['oldest_pending_age_seconds'] = $probe['oldest_pending_age_seconds'];
        if (!$probe['due']) return $status;
        $wpLoad = realpath(dirname($privateRoot, 2) . '/wp-load.php');
        if (!is_string($wpLoad)) throw new RuntimeException('WordPress is unavailable for Zero work.');
        $bootstrap = _stattic_zero_v2_wp_bootstrap($privateRoot, $spaceId, $target['version_id']);
        // Recheck authority in the child immediately before selecting the immutable runtime.
        $bootstrap = 'require_once ' . var_export(__FILE__, true) . ';'
            . 'if ((_stattic_zero_worker_target(' . var_export($privateRoot, true) . ',' . var_export($spaceId, true) . ',' . var_export($environment, true) . ')["version_id"] ?? null) !== ' . var_export($target['version_id'], true) . ') throw new RuntimeException("Zero worker live version changed.");'
            . '$GLOBALS["SPACEFAST_ZERO_V2_ENVIRONMENT"]=' . var_export($environment, true) . ';' . $bootstrap;
        $remaining = min(50000, $budgetMs) - (int) ((microtime(true) - $started) * 1000);
        if ($remaining <= 0) throw new RuntimeException('Zero worker time budget exhausted.');
        $status['wordpress_booted'] = true;
        $result = _stattic_runtime_run_subprocess(['wp', '--path=' . dirname($wpLoad), '--exec=' . $bootstrap,
            'eval-file', __DIR__ . '/zero-v2-worker-wp.php', (string) max(1, min(100, $limit))], null, null, dirname($wpLoad), $remaining, 262144, 65536);
        $output = json_decode(trim($result['stdout']), true);
        if (!$result['spawned'] || $result['timedOut'] || $result['exitCode'] !== 0 || !is_array($output)
            || !is_int($output['processed'] ?? null) || !is_int($output['failures'] ?? null)) {
            throw new RuntimeException($result['timedOut'] ? 'Zero worker time budget exhausted.' : 'Zero worker command failed.');
        }
        $status['processed'] = $output['processed'];
        $status['failures'] = $output['failures'];
        $status['status'] = $status['failures'] > 0 ? 'failed' : 'ran';
        $status['oldest_pending_age_seconds'] = _stattic_zero_worker_probe($spaceId)['oldest_pending_age_seconds'];
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
