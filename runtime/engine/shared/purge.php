<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/record-store.php';
require_once __DIR__ . '/native-process.php';

// A mutation that changed served bytes writes a durable purge record here, then
// kicks a PHP CLI subprocess that loads WordPress and calls the provider bridge —
// that child, not this process, pays the `wp-load.php` bootstrap and runs tenant
// code. A failed kick leaves the record queued for the maintenance tick.

// URI purges are safe only for pure assets. Mutable document keys (directory,
// extensionless, HTML, JSON, XML) have live-disproved path-wide convergence
// across wp.cloud POPs, so any such path widens the activation to a domain
// purge. The 300 URL ceiling remains the provider's scoped-call limit.
const STATTIC_RUNTIME_PURGE_URL_MAX = 300;

const STATTIC_RUNTIME_PURGE_ASSET_EXTENSIONS = [
    'woff2', 'woff', 'ttf', 'otf', 'eot', 'css', 'js',
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg',
    'mp4', 'webm', 'mp3', 'pdf',
];

// After this many failed provider attempts the record is dead-lettered: it stops
// being retried and is journaled so an operator can see the edge is stale.
const STATTIC_RUNTIME_PURGE_MAX_ATTEMPTS = 10;

const STATTIC_RUNTIME_PURGE_KICK_TIMEOUT_MS = 30000;
const STATTIC_RUNTIME_PURGE_KICK_STDOUT_MAX_BYTES = 262144;
const STATTIC_RUNTIME_PURGE_KICK_STDERR_MAX_BYTES = 65536;
const STATTIC_RUNTIME_PURGE_RESULT_SENTINEL = '__SPACEFAST_PURGE_RESULT__';

// Dead records are evidence, not work: retention reclaims them after this.
const STATTIC_RUNTIME_PURGE_DEAD_RETENTION_SECONDS = 14 * 86400;

// The provider bridge documents a boolean receipt. `null` is not acceptance:
// some host adapters perform a call without returning its result, and treating
// that unknown outcome as success would delete the only durable retry record.
function _stattic_runtime_purge_provider_accepted(mixed $result): bool
{
    return $result === true;
}

function _stattic_runtime_purges_root(string $privateRoot): string
{
    return $privateRoot . '/runtime/purges';
}

// Only DEAD records ever expire out of the store. A queued record that outlives
// any window is still owed to the edge, and dropping it would silently leave
// stale public bytes behind.
function _stattic_runtime_purge_store(string $privateRoot): array
{
    return _stattic_record_store(_stattic_runtime_purges_root($privateRoot), [
        'retention' => [
            'mtime_seconds' => STATTIC_RUNTIME_PURGE_DEAD_RETENTION_SECONDS,
            'statuses' => ['dead'],
        ],
    ]);
}

/** @return list<string> */
function _stattic_runtime_purge_hostname_list(mixed $raw): array
{
    $hostnames = [];
    foreach (is_array($raw) ? $raw : [] as $hostname) {
        if (!is_string($hostname)) {
            continue;
        }
        $normalized = _stattic_normalize_hostname($hostname);
        if ($normalized !== '' && preg_match('/\A[a-z0-9.-]{1,253}\z/', $normalized) === 1) {
            $hostnames[$normalized] = true;
        }
    }
    return array_keys($hostnames);
}

/** @return list<string> queryless request paths, deduplicated, in input order. */
function _stattic_runtime_purge_path_list(mixed $raw): array
{
    $paths = [];
    foreach (is_array($raw) ? $raw : [] as $path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strlen($path) > 2048) {
            continue;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            continue;
        }
        $normalized = parse_url($path, PHP_URL_PATH);
        if (!is_string($normalized) || $normalized === '' || $normalized[0] !== '/') {
            continue;
        }
        $paths[$normalized] = true;
    }
    return array_keys($paths);
}

/**
 * The provider purges URLs, the runtime knows paths and hostnames: the cross
 * product IS the changed set. Two hostnames and twenty paths are forty edge
 * entries, not twenty.
 *
 * @return list<string>
 */
function _stattic_runtime_purge_expand_urls(array $hostnames, array $paths): array
{
    $urls = [];
    foreach ($hostnames as $hostname) {
        foreach ($paths as $path) {
            $urls[] = 'https://' . $hostname . $path;
        }
    }
    return $urls;
}

/** @param list<string> $urls */
function _stattic_runtime_purge_urls_are_assets(array $urls): bool
{
    foreach ($urls as $url) {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || str_ends_with($path, '/')) {
            return false;
        }
        $segment = substr($path, (int) strrpos($path, '/') + 1);
        $dot = strrpos($segment, '.');
        if ($dot === false || $dot === 0) {
            return false;
        }
        if (!in_array(strtolower(substr($segment, $dot + 1)), STATTIC_RUNTIME_PURGE_ASSET_EXTENSIONS, true)) {
            return false;
        }
    }
    return $urls !== [];
}

/** @param list<string> $urls */
function _stattic_runtime_purge_scope(array $urls): string
{
    return count($urls) <= STATTIC_RUNTIME_PURGE_URL_MAX
        && _stattic_runtime_purge_urls_are_assets($urls)
        ? 'urls'
        : 'domain';
}

/**
 * Writes one durable purge record and returns its id.
 *
 * `$input`: hostnames (required — a purge with no hostname is unaddressable),
 * paths, reason. Scope is DERIVED, never passed: a non-empty set of at most 300
 * normalized URLs purges by URI only when every URL is a pure asset; document,
 * oversized, and unaddressable sets purge the whole domain.
 */
function _stattic_runtime_purge_enqueue(string $privateRoot, array $input): string
{
    $hostnames = _stattic_runtime_purge_hostname_list($input['hostnames'] ?? null);
    $paths = _stattic_runtime_purge_path_list($input['paths'] ?? null);
    $urls = _stattic_runtime_purge_expand_urls($hostnames, $paths);
    $scope = _stattic_runtime_purge_scope($urls);

    $id = _stattic_runtime_new_id('pg');
    $record = [
        'id' => $id,
        'created_at' => gmdate('c'),
        'scope' => $scope,
        'hostnames' => $hostnames,
        'urls' => $scope === 'urls' ? $urls : [],
        'reason' => is_string($input['reason'] ?? null) ? $input['reason'] : 'runtime_mutation',
        // A receipt forces the synchronous provider call; without one the drain
        // may dispatch the provider's own action hook instead.
        'receipt' => ($input['receipt'] ?? true) !== false,
        'attempts' => 0,
        'status' => 'queued',
    ];
    $store = _stattic_runtime_purge_store($privateRoot);
    _stattic_record_store_ensure($store);
    _stattic_record_store_put($store, $id, $record);
    return $id;
}

function _stattic_runtime_purge_entrypoint_file(): string
{
    return dirname(__DIR__) . '/entrypoints/purge.php';
}

// Under FPM, PHP_BINARY names the FPM binary, which cannot run a script; `php`
// on PATH is the fallback, and a site without one just fails the kick.
function _stattic_runtime_purge_php_binary(): string
{
    $configured = _stattic_config_value('SPACEFAST_RUNTIME_PHP_CLI_BIN');
    if ($configured !== '') {
        return $configured;
    }
    return PHP_SAPI === 'cli' && PHP_BINARY !== '' ? PHP_BINARY : 'php';
}

/**
 * ONE PHP CLI subprocess running the purge entrypoint. It takes its targets from
 * the durable queue; this process only waits, capped.
 *
 * @return array{ok: bool, status: int, results: array<string,string>, error: ?string}
 */
function _stattic_runtime_purge_kick(string $privateRoot): array
{
    $entrypoint = _stattic_runtime_purge_entrypoint_file();
    if (!is_file($entrypoint)) {
        return ['ok' => false, 'status' => 0, 'results' => [], 'error' => 'purge_entrypoint_missing'];
    }
    // Environment is inherited, not rebuilt: wp-config.php and the provider's own
    // bootstrap read host-supplied environment this process cannot enumerate.
    $result = _stattic_runtime_run_subprocess(
        [
            _stattic_runtime_purge_php_binary(),
            $entrypoint,
            '--private-root=' . $privateRoot,
        ],
        null,
        null,
        null,
        STATTIC_RUNTIME_PURGE_KICK_TIMEOUT_MS,
        STATTIC_RUNTIME_PURGE_KICK_STDOUT_MAX_BYTES,
        STATTIC_RUNTIME_PURGE_KICK_STDERR_MAX_BYTES
    );
    if (($result['spawned'] ?? false) !== true) {
        return ['ok' => false, 'status' => 0, 'results' => [], 'error' => 'purge_kick_spawn_failed'];
    }
    if (($result['timedOut'] ?? false) === true) {
        return ['ok' => false, 'status' => 0, 'results' => [], 'error' => 'purge_kick_timed_out'];
    }
    $summary = _stattic_runtime_purge_kick_summary((string) ($result['stdout'] ?? ''));
    $exitCode = (int) ($result['exitCode'] ?? -1);
    if ($summary === null || $exitCode !== 0) {
        return [
            'ok' => false,
            // `status` is the child's exit code on this transport: same field, same
            // meaning to the caller (0 / 2xx is the only success).
            'status' => $exitCode,
            'results' => [],
            'error' => $summary === null ? 'purge_kick_no_result' : 'purge_kick_failed',
        ];
    }
    $results = is_array($summary['results'] ?? null) ? $summary['results'] : [];
    return ['ok' => true, 'status' => 0, 'results' => $results, 'error' => null];
}

// The child's summary, read from its sentinel line: WordPress and its plugins
// print freely during a full bootstrap, so nothing else on stdout is parsed.
function _stattic_runtime_purge_kick_summary(string $stdout): ?array
{
    $position = strrpos($stdout, STATTIC_RUNTIME_PURGE_RESULT_SENTINEL);
    if ($position === false) {
        return null;
    }
    $line = substr($stdout, $position + strlen(STATTIC_RUNTIME_PURGE_RESULT_SENTINEL));
    $end = strpos($line, "\n");
    $decoded = json_decode(trim($end === false ? $line : substr($line, 0, $end)), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Enqueue + kick + receipt: the mutation's own API response carries the receipt,
 * so a control-plane call returns when compile AND purge are done.
 *
 * `status`: 'ok' the edge confirmed this record, 'failed' the provider refused it
 * (it stays queued until the attempt cap), 'pending' the kick never reached the
 * entrypoint and the maintenance tick still owes it.
 *
 * @return array{status: string, mode: string, urls?: int}
 */
function _stattic_runtime_purge_now(string $privateRoot, array $input): array
{
    $store = _stattic_runtime_purge_store($privateRoot);
    $purgeId = _stattic_runtime_purge_enqueue($privateRoot, $input);
    $record = _stattic_record_store_get($store, $purgeId);
    $mode = is_array($record) && ($record['scope'] ?? null) === 'urls' ? 'urls' : 'domain';
    $urlCount = is_array($record) && is_array($record['urls'] ?? null) ? count($record['urls']) : 0;

    $kick = _stattic_runtime_purge_kick($privateRoot);
    $outcome = $kick['results'][$purgeId] ?? null;
    $status = match (true) {
        $outcome === 'ok' => 'ok',
        $outcome === 'failed', $outcome === 'dead' => 'failed',
        // Not in OUR kick's summary. A drain deletes a record only on acceptance,
        // so a record that is gone was accepted by a concurrent drain; one still
        // on disk means the kick never landed and it is still owed.
        default => _stattic_record_store_get($store, $purgeId) === null
            ? 'ok'
            : ($kick['ok'] === true ? 'failed' : 'pending'),
    };
    if ($status !== 'ok') {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'edge_purge_incomplete',
            'purge_id' => $purgeId,
            'mode' => $mode,
            'status' => $status,
            'kick_status' => $kick['status'],
            'kick_error' => $kick['error'],
        ]);
    }

    return $mode === 'urls'
        ? ['status' => $status, 'mode' => $mode, 'urls' => $urlCount]
        : ['status' => $status, 'mode' => $mode];
}

// The maintenance tick's re-kick: one call, only when something is owed. It
// carries no receipt — the tick is not blocked on the edge.
function _stattic_runtime_purge_requeue_kick(string $privateRoot): int
{
    $store = _stattic_runtime_purge_store($privateRoot);
    $queued = 0;
    foreach (_stattic_record_store_records($store) as $record) {
        if (($record['status'] ?? 'queued') !== 'dead') {
            $queued += 1;
        }
    }
    if ($queued === 0) {
        return 0;
    }
    _stattic_runtime_purge_kick($privateRoot);
    return $queued;
}

/**
 * Drains the queue through $provider, which returns true when the edge accepted
 * the record. Pure with respect to WordPress: the entrypoint owns the bootstrap
 * and hands the bridge in here as a callable, so this stays loadable (and
 * testable) without loading a CMS.
 *
 * Each record is processed under its own stripe lock, so two children spawned by
 * two mutations cannot double-purge one record — or, worse, have the loser's
 * failure path resurrect a record the winner already deleted.
 *
 * @param  callable(array): bool $provider
 * @return array{processed: int, ok: int, failed: int, dead: int, results: array<string,string>}
 */
function _stattic_runtime_purge_process_queue(string $privateRoot, callable $provider): array
{
    $store = _stattic_runtime_purge_store($privateRoot);
    _stattic_record_store_ensure($store);
    $summary = ['processed' => 0, 'ok' => 0, 'failed' => 0, 'dead' => 0, 'results' => []];

    foreach (_stattic_record_store_ids($store) as $id) {
        $entries = [];
        $outcome = _stattic_record_store_mutate(
            $store,
            $id,
            static function (?array $record) use ($store, $id, $provider, &$entries): ?string {
                // Re-read under the lock: another drain may have taken it since
                // the id listing above.
                if ($record === null || ($record['status'] ?? 'queued') === 'dead') {
                    return null;
                }
                $mode = ($record['scope'] ?? null) === 'urls' ? 'urls' : 'domain';
                $hostnames = is_array($record['hostnames'] ?? null) ? $record['hostnames'] : [];
                $urlCount = is_array($record['urls'] ?? null) ? count($record['urls']) : 0;
                $accepted = false;
                try {
                    $accepted = $provider($record) === true;
                } catch (Throwable $error) {
                    $entries[] = [
                        'event' => 'edge_purge_provider_error',
                        'purge_id' => $id,
                        'error' => get_debug_type($error),
                    ];
                }

                if ($accepted) {
                    _stattic_record_store_delete($store, $id);
                    $entries[] = [
                        'event' => 'edge_purge',
                        'purge_id' => $id,
                        'mode' => $mode,
                        'hostnames' => $hostnames,
                        'url_count' => $urlCount,
                        'reason' => is_string($record['reason'] ?? null) ? $record['reason'] : '',
                        'attempts' => max(0, (int) ($record['attempts'] ?? 0)) + 1,
                    ];
                    return 'ok';
                }

                $record['attempts'] = max(0, (int) ($record['attempts'] ?? 0)) + 1;
                $record['last_failed_at'] = gmdate('c');
                $dead = $record['attempts'] >= STATTIC_RUNTIME_PURGE_MAX_ATTEMPTS;
                $record['status'] = $dead ? 'dead' : 'queued';
                _stattic_record_store_put($store, $id, $record);
                $entries[] = [
                    'event' => $dead ? 'edge_purge_dead_lettered' : 'edge_purge_failed',
                    'purge_id' => $id,
                    'mode' => $mode,
                    'hostnames' => $hostnames,
                    'url_count' => $urlCount,
                    'attempts' => $record['attempts'],
                ];
                return $dead ? 'dead' : 'failed';
            },
        );
        // Journaled outside the lock, so a contended stripe never waits on the
        // journal lock too.
        foreach ($entries as $entry) {
            _stattic_runtime_append_journal($privateRoot, $entry);
        }
        if ($outcome === null) {
            continue;
        }
        $summary['processed'] += 1;
        $summary[$outcome] += 1;
        $summary['results'][$id] = $outcome;
    }

    return $summary;
}
