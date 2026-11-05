<?php
declare(strict_types=1);

require_once __DIR__ . '/record-store.php';

const STATTIC_SECURITY_TELEMETRY_HOURLY_LIMIT = 1200;

class StatticSecurityBudgetBusy extends RuntimeException {}

function _stattic_security_gap_store(string $privateRoot): array
{
    return _stattic_record_store($privateRoot . '/runtime/security-telemetry-gaps', [
        'throw_on_failure' => true,
        'retention' => ['mtime_seconds' => 86400],
    ]);
}

function _stattic_security_mark_contention(string $privateRoot, string $spaceId): void
{
    try {
        $store = _stattic_security_gap_store($privateRoot);
        if (!_stattic_runtime_mkdir_soft($store['root'])) throw new RuntimeException('security_gap_unavailable');
        $id = hash('sha256', $spaceId . ':' . intdiv(time(), 3600));
        // Exclusive create needs no budget lock. One marker per Space/hour,
        // retained after delivery so contention cannot create an event flood.
        if (!_stattic_record_store_claim($store, $id, [
            'space_id' => $spaceId, 'reported' => false,
            'payload' => ['kind' => 'gap', 'id' => $id, 'observedAt' => gmdate('c'), 'minimumCount' => 1, 'reason' => 'budget_contention'],
        ], time()) && _stattic_record_store_read($store, $id)['state'] !== 'present') {
            throw new RuntimeException('security_gap_unavailable');
        }
    } catch (Throwable) {
        error_log('security.telemetry_storage_failed dropped=1');
    }
}

function _stattic_security_budget_store(string $privateRoot): array
{
    // One counter and at most one unfinished append per Space. Recovery writes
    // to the canonical runtime journal; this store has no delivery cursor.
    return _stattic_record_store($privateRoot . '/runtime/security-telemetry-budget', ['throw_on_failure' => true, 'private_root' => $privateRoot]);
}

function _stattic_security_request_correlation(): string
{
    static $fallback = null;
    $fallback ??= bin2hex(random_bytes(16));
    $id = $_SERVER['HTTP_X_SPACEFAST_REQUEST_ID'] ?? $_SERVER['HTTP_X_REQUEST_ID'] ?? $fallback;
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $id) === 1 ? $id : $fallback;
}

function _stattic_security_request_id(): string
{
    return hash('sha256', 'audit:request:v1:' . _stattic_security_request_correlation());
}

function _stattic_security_session_id(string $spaceId, string $sessionId): string
{
    return hash('sha256', 'audit:session:v1:' . $spaceId . ':' . $sessionId);
}

function _stattic_security_append(string $privateRoot, string $spaceId, ?string $versionId, array $payload): bool
{
    $entry = ['event' => 'security.telemetry', 'space_id' => $spaceId, ...($versionId === null ? [] : ['version_id' => $versionId]), 'payload' => $payload];
    $entry['event_id'] = _stattic_runtime_management_event_id([], $entry);
    return _stattic_runtime_append_journal($privateRoot, $entry, false);
}

function _stattic_security_mutate_budget(array $store, string $id, callable $critical): mixed
{
    if (!_stattic_runtime_mkdir_soft($store['root'])) throw new RuntimeException('security_budget_unavailable');
    return _stattic_lock_with(
        _stattic_lock_stripe_path($store['root'], $id),
        STATTIC_LOCK_TRY,
        static fn () => throw new StatticSecurityBudgetBusy(),
        static function () use ($store, $id, $critical): mixed {
            $read = _stattic_record_store_read($store, $id);
            if ($read['state'] === 'unavailable') throw new RuntimeException('security_budget_unavailable');
            $record = $read['record'];
            if ($record !== null) {
                if (!is_string($record['space_id'] ?? null) || !hash_equals($id, hash('sha256', $record['space_id']))) throw new RuntimeException('security_budget_invalid');
                foreach (['window', 'admitted', 'dropped', 'reported'] as $field) {
                    if (!is_int($record[$field] ?? null) || $record[$field] < 0) throw new RuntimeException('security_budget_invalid');
                }
                if (array_key_exists('pending', $record)) {
                    $pending = $record['pending'];
                    if (!is_array($pending) || !is_array($pending['payload'] ?? null)
                        || !array_key_exists('version_id', $pending)
                        || ($pending['version_id'] !== null && !is_string($pending['version_id']))) throw new RuntimeException('security_budget_invalid');
                    // A worker may have died before OR after append. Reuse the
                    // original observation ID so replay persists only once.
                    if (!_stattic_security_append($store['private_root'], $record['space_id'], $pending['version_id'], $pending['payload'])) {
                        $record['dropped']++;
                    }
                    unset($record['pending']);
                    _stattic_record_store_put($store, $id, $record);
                }
            }
            return $critical($record);
        },
    );
}

function _stattic_security_record(string $privateRoot, string $spaceId, ?string $versionId, array $payload): void
{
    if ($privateRoot === '' || $spaceId === '') return;
    try {
        $store = _stattic_security_budget_store($privateRoot);
        $id = hash('sha256', $spaceId);
        _stattic_security_mutate_budget($store, $id, static function (?array $record) use ($privateRoot, $spaceId, $versionId, $payload, $store, $id): void {
            $window = intdiv(time(), 3600);
            $record ??= ['space_id' => $spaceId, 'window' => $window, 'admitted' => 0, 'dropped' => 0, 'reported' => 0];
            if ($record['window'] !== $window) {
                $record['window'] = $window;
                $record['admitted'] = 0;
            }
            if ($record['admitted'] < STATTIC_SECURITY_TELEMETRY_HOURLY_LIMIT) {
                $record['admitted']++;
                $record['pending'] = ['version_id' => $versionId, 'payload' => $payload];
                _stattic_record_store_put($store, $id, $record);
                $appended = _stattic_security_append($privateRoot, $spaceId, $versionId, $payload);
                unset($record['pending']);
                if ($appended) {
                    _stattic_record_store_put($store, $id, $record);
                    return;
                }
            }
            $record['dropped']++;
            _stattic_record_store_put($store, $id, $record);
        });
    } catch (StatticSecurityBudgetBusy) {
        _stattic_security_mark_contention($privateRoot, $spaceId);
    } catch (Throwable) {
        // No raw exception text: filesystem or URL context may carry secrets.
        error_log('security.telemetry_storage_failed dropped=1');
    }
}

// Called before a drain page. Counters survive quiet periods, so the final
// partial burst is reported too. Advance only after a successful journal write.
function _stattic_security_flush_drops(string $privateRoot): void
{
    try {
        $gaps = _stattic_security_gap_store($privateRoot);
        // One unreadable record must not hold back the ones after it.
        foreach (_stattic_record_store_ids($gaps) as $id) {
            try {
                _stattic_lock_with(_stattic_lock_stripe_path($gaps['root'], $id), STATTIC_LOCK_TRY, null,
                    static function () use ($privateRoot, $gaps, $id): void {
                        $read = _stattic_record_store_read($gaps, $id);
                        if ($read['state'] === 'unavailable') throw new RuntimeException('security_gap_unavailable');
                        $record = $read['record'];
                        if ($record === null || ($record['reported'] ?? null) === true
                            || !is_string($record['space_id'] ?? null) || !is_array($record['payload'] ?? null)) return;
                        if (_stattic_security_append($privateRoot, $record['space_id'], null, $record['payload'])) {
                            $record['reported'] = true;
                            _stattic_record_store_put($gaps, $id, $record);
                        }
                    });
            } catch (Throwable) {
                error_log('security.telemetry_storage_failed');
            }
        }
        $store = _stattic_security_budget_store($privateRoot);
        foreach (_stattic_record_store_ids($store) as $id) {
            try {
                _stattic_security_mutate_budget($store, $id, static function (?array $record) use ($privateRoot, $store, $id): void {
                    if ($record === null || $record['dropped'] <= $record['reported']) return;
                    $payload = ['kind' => 'dropped', 'id' => hash('sha256', 'runtime-dropped:' . $record['space_id'] . ':' . $record['dropped']), 'observedAt' => gmdate('c'), 'count' => $record['dropped'], 'reason' => 'producer_budget'];
                    if (_stattic_security_append($privateRoot, $record['space_id'], null, $payload)) {
                        $record['reported'] = $record['dropped'];
                        _stattic_record_store_put($store, $id, $record);
                    }
                });
            } catch (StatticSecurityBudgetBusy) {
                // A writer holds this Space's budget lock; the next drain retries.
            } catch (Throwable) {
                error_log('security.telemetry_storage_failed');
            }
        }
    } catch (Throwable) {
        error_log('security.telemetry_storage_failed');
    }
}

function _stattic_security_session(array $serving, string $spaceId, string $sessionId, string $lifecycle, ?string $previousSessionId = null): void
{
    if (preg_match('/^[a-f0-9]{64}$/D', $sessionId) !== 1) return;
    _stattic_security_record(_stattic_access_private_root(), $spaceId, is_string($serving['version_id'] ?? null) ? $serving['version_id'] : null, [
        'kind' => 'session', 'id' => bin2hex(random_bytes(16)), 'observedAt' => gmdate('c'), 'lifecycle' => $lifecycle,
        'sessionId' => _stattic_security_session_id($spaceId, $sessionId),
        'previousSessionId' => $previousSessionId === null ? null : _stattic_security_session_id($spaceId, $previousSessionId),
        'requestId' => _stattic_security_request_id(),
    ]);
}

function _stattic_security_outbound(string $url, string $method, bool $permitted, ?array $result, float $durationMs, ?int $sent = null, ?int $received = null): void
{
    if (!function_exists('_stattic_page_serving')) return;
    $serving = _stattic_page_serving();
    $spaceId = (string) ($serving['space_id'] ?? '');
    if ($spaceId === '') return;
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $host = strlen($host) <= 253 && preg_match('/^[a-z0-9.:[\]-]+$/D', $host) === 1 ? $host : null;
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'], true)) $method = 'OTHER';
    $status = is_int($result['status'] ?? null) && $result['status'] >= 100 && $result['status'] <= 599 ? $result['status'] : null;
    $sid = function_exists('_stattic_access_presented_session_id') ? _stattic_access_presented_session_id() : null;
    _stattic_security_record(_stattic_access_private_root(), $spaceId, is_string($serving['version_id'] ?? null) ? $serving['version_id'] : null, [
        'kind' => 'outbound', 'id' => bin2hex(random_bytes(16)), 'observedAt' => gmdate('c'), 'host' => $host,
        'method' => $method, 'permitted' => $permitted,
        'outcome' => !$permitted ? 'denied' : (($result['error'] ?? null) !== null || $status === null ? 'failed' : ($status >= 400 ? 'http_error' : 'succeeded')),
        'status' => $status, 'durationMs' => min(86400000, max(0, $durationMs)),
        'requestBytes' => $sent, 'responseBytes' => $received, 'requestId' => _stattic_security_request_id(),
        'sessionId' => $sid === null ? null : _stattic_security_session_id($spaceId, $sid),
    ]);
}

function _stattic_security_zero_outbound(array $response, array $identity): void
{
    $root = _stattic_access_private_root();
    $spaceId = (string) ($identity['spaceId'] ?? '');
    $versionId = is_string($identity['versionId'] ?? null) && $identity['versionId'] !== '' ? $identity['versionId'] : null;
    $sid = _stattic_access_presented_session_id();
    foreach (array_slice(is_array($response['outbound'] ?? null) ? $response['outbound'] : [], 0, 32) as $event) {
        if (!is_array($event)) continue;
        // These fields come from the native host, outside the JS VM. Never
        // reuse tenant-controlled `events` for security evidence.
        $payload = array_intersect_key($event, array_flip(['host', 'method', 'permitted', 'outcome', 'status', 'durationMs', 'requestBytes', 'responseBytes']));
        $payload += ['kind' => 'outbound', 'id' => bin2hex(random_bytes(16)), 'observedAt' => gmdate('c'),
            'requestId' => _stattic_security_request_id(), 'sessionId' => $sid === null ? null : _stattic_security_session_id($spaceId, $sid)];
        _stattic_security_record($root, $spaceId, $versionId, $payload);
    }
    $dropped = $response['outboundDropped'] ?? 0;
    if (is_int($dropped) && $dropped > 0 && $spaceId !== '' && $root !== '') {
        try {
            $store = _stattic_security_budget_store($root);
            $id = hash('sha256', $spaceId);
            _stattic_security_mutate_budget($store, $id, static function (?array $record) use ($dropped, $spaceId, $store, $id): void {
                $record ??= ['space_id' => $spaceId, 'window' => intdiv(time(), 3600), 'admitted' => 0, 'dropped' => 0, 'reported' => 0];
                $record['dropped'] += $dropped;
                _stattic_record_store_put($store, $id, $record);
            });
        } catch (StatticSecurityBudgetBusy) {
            _stattic_security_mark_contention($root, $spaceId);
        } catch (Throwable) {
            error_log('security.telemetry_storage_failed');
        }
    }
}
