<?php
declare(strict_types=1);

// Zero v2 change notices on Spacefast. The runtime's change hook hands each
// committed transaction's notice (collection, operations, ids; never rows) to
// this publisher. It posts the notice to the control plane under the
// version's callback token, after the response, inside a small time budget.
// The control plane puts it in the Space's Cast room. Browsers re-run their
// own access-checked reads, so a lost notice costs one poll interval.

// One post per request, after the response. The whole delivery, connect included,
// fits this budget: a slow or unreachable control plane costs a worker at most this.
const STATTIC_ZERO_V2_REALTIME_BUDGET_MS = 250;
const STATTIC_ZERO_V2_REALTIME_MAX_BYTES = 65536;
const STATTIC_ZERO_V2_REALTIME_TOKEN_MAX_BYTES = 2048;
// The control plane's zeroV2ChangeNoticeSchema limits. Past them a notice collapses (fewer
// collections, ids dropped to null), so the receiver never refuses what this box sends.
const STATTIC_ZERO_V2_REALTIME_MAX_COLLECTIONS = 64;
const STATTIC_ZERO_V2_REALTIME_MAX_IDS = 100;
const STATTIC_ZERO_V2_REALTIME_MAX_ID_LENGTH = 191;
const STATTIC_ZERO_V2_REALTIME_COLLECTION = '/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/';

/** Authenticated finalize input: the version's callback, or none (realtime off). */
function _stattic_zero_v2_write_realtime(string $privateRoot, string $spaceId, string $versionId, mixed $realtime): void
{
    $root = $privateRoot . '/spaces/' . $spaceId . '/versions/' . $versionId . '/.zero-compiled';
    if (!is_file($root . '/manifest.json')) return;
    if ($realtime === null) {
        if (is_file($root . '/realtime.json')) unlink($root . '/realtime.json');
        return;
    }
    $url = is_array($realtime) ? ($realtime['url'] ?? null) : null;
    $token = is_array($realtime) ? ($realtime['token'] ?? null) : null;
    $resource = is_array($realtime) ? ($realtime['castResourceKey'] ?? null) : null;
    $ws = is_array($realtime) ? ($realtime['castWsUrl'] ?? null) : null;
    if (!is_string($url) || !is_string($token) || !_stattic_zero_v2_realtime_token_valid($token)
        || !is_string($resource) || preg_match('/\A[A-Za-z0-9_.:-]{1,256}\z/', $resource) !== 1
        || !is_string($ws) || !in_array(parse_url($ws, PHP_URL_SCHEME), ['wss', 'ws'], true)) {
        throw new RuntimeException('Invalid Zero realtime callback.');
    }
    require_once __DIR__ . '/../shared/egress.php';
    if (!_stattic_platform_destination_allowed($url)) throw new RuntimeException('Invalid Zero realtime callback.');
    _stattic_runtime_write_json_atomic($root . '/realtime.json', [
        'url' => $url, 'token' => $token, 'space_id' => $spaceId, 'version_id' => $versionId,
        'cast_resource_key' => $resource, 'cast_ws_url' => $ws,
    ]);
}

function _stattic_zero_v2_realtime_token_valid(string $token): bool
{
    return $token !== '' && strlen($token) <= STATTIC_ZERO_V2_REALTIME_TOKEN_MAX_BYTES
        && !str_contains($token, "\r") && !str_contains($token, "\n");
}

/** Called once per mount. Without a callback or without the runtime hook, nothing is registered. */
function _stattic_zero_v2_register_realtime(string $compiledRoot): void
{
    if (!interface_exists(\Zero\Realtime\Publisher::class)) return;
    $file = $compiledRoot . '/realtime.json';
    if (!is_file($file)) return;
    $config = json_decode((string) file_get_contents($file), true);
    if (!is_array($config) || !is_string($config['url'] ?? null) || !is_string($config['token'] ?? null)
        || !is_string($config['space_id'] ?? null) || !is_string($config['version_id'] ?? null)
        || !_stattic_zero_v2_realtime_token_valid($config['token'])) {
        error_log('[spacefast] Zero realtime callback config is invalid; live updates fall back to polling.');
        return;
    }
    // The class implements a Zero interface, so it loads only once Zero is mounted.
    require_once __DIR__ . '/zero-v2-realtime-publisher.php';
    \Zero\Realtime\Module::publish(new Spacefast_Zero_Realtime_Publisher($config));
}

/**
 * Fold one transaction's changes into the request's batch. A collection's ids
 * become null (that is, "some rows changed") once they pass the id limit or any
 * id is too long to send; that is the same refresh for clients, never a drop.
 *
 * @param array<string, array{ops: array<string, true>, ids: array<string, int|string>|null}> $batch
 * @param list<array{collection: string, ops: list<string>, ids: ?list<int|string>}> $changes
 */
function _stattic_zero_v2_realtime_merge(array $batch, array $changes): array
{
    foreach ($changes as $change) {
        $key = (string) ($change['collection'] ?? '');
        if (preg_match(STATTIC_ZERO_V2_REALTIME_COLLECTION, $key) !== 1) continue;
        $entry = $batch[$key] ?? ['ops' => [], 'ids' => []];
        foreach ($change['ops'] ?? [] as $op) {
            if (in_array($op, ['create', 'update', 'delete'], true)) $entry['ops'][$op] = true;
        }
        if (!is_array($change['ids'] ?? null)) {
            $entry['ids'] = null;
        } elseif ($entry['ids'] !== null) {
            foreach ($change['ids'] as $id) {
                $valid = is_int($id) || (is_string($id) && $id !== '' && strlen($id) <= STATTIC_ZERO_V2_REALTIME_MAX_ID_LENGTH);
                if (!$valid || count($entry['ids']) >= STATTIC_ZERO_V2_REALTIME_MAX_IDS) {
                    $entry['ids'] = null;
                    break;
                }
                $entry['ids'][is_int($id) ? 'i' . $id : 's' . $id] = $id;
            }
        }
        $batch[$key] = $entry;
    }
    return $batch;
}

/**
 * The callback bodies for a batch, each within the receiver's limits: at most 64
 * collections and 64 KB per notice. A body over 64 KB collapses to collection-level
 * changes (ids null, the same refresh for clients). More than 64 collections become
 * several notices. Nothing is dropped. Empty when the batch is.
 *
 * @return list<string>
 */
function _stattic_zero_v2_realtime_bodies(array $config, array $batch, string $at): array
{
    ksort($batch);
    $changes = [];
    foreach ($batch as $key => $entry) {
        $ops = array_keys($entry['ops']);
        if ($ops === []) continue;
        sort($ops);
        $changes[] = ['collection' => (string) $key, 'ops' => $ops, 'ids' => $entry['ids'] === null ? null : array_values($entry['ids'])];
    }
    $encode = static function (array $changes) use ($config, $at): string {
        $id = bin2hex(random_bytes(12));
        return (string) json_encode(['event' => [
            'event' => 'zero.changes',
            'event_id' => 'zch_' . $id,
            'space_id' => $config['space_id'],
            'version_id' => $config['version_id'],
            'payload' => ['id' => $id, 'at' => $at, 'changes' => $changes],
        ]], JSON_UNESCAPED_SLASHES);
    };
    $bodies = [];
    foreach (array_chunk($changes, STATTIC_ZERO_V2_REALTIME_MAX_COLLECTIONS) as $chunk) {
        $body = $encode($chunk);
        // 64 collections × (64-byte name + ops) stays far under 64 KB once ids are gone.
        if (strlen($body) > STATTIC_ZERO_V2_REALTIME_MAX_BYTES) {
            $body = $encode(array_map(static fn (array $c): array => ['ids' => null] + $c, $chunk));
        }
        $bodies[] = $body;
    }
    return $bodies;
}

/**
 * Posts one batch inside the request's budget. Connect and total timeouts are
 * the remaining budget, never more, so the worker is free by the deadline.
 */
function _stattic_zero_v2_realtime_post(string $url, string $token, string $body, ?float $deadline = null): void
{
    require_once __DIR__ . '/../shared/http.php';
    require_once __DIR__ . '/../shared/egress.php';
    $deadline ??= microtime(true) + STATTIC_ZERO_V2_REALTIME_BUDGET_MS / 1000;
    $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
    if ($remainingMs < 1 || !_stattic_platform_destination_allowed($url)) return;
    // Best effort: the receipt is not read, but PHP 8.5's NoDiscard needs it acknowledged.
    $receipt = _stattic_http_request([
        'url' => $url,
        'method' => 'POST',
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        'body' => $body,
        'connect_timeout_ms' => $remainingMs,
        'timeout_ms' => $remainingMs,
        'schemes' => ['https', 'http'],
        'max_body_bytes' => 4096,
    ]);
    if (($receipt['status'] ?? 0) < 200 || ($receipt['status'] ?? 0) > 299) {
        error_log('[spacefast] Zero realtime notice was not accepted: ' . ($receipt['error'] ?? 'HTTP ' . ($receipt['status'] ?? 0)));
    }
}

/**
 * The browser half: the Cast transport bundle with this version's public room
 * settings. Without a callback (no room) it is an empty script and pages poll.
 * The callback token never leaves the box.
 */
function _stattic_zero_v2_realtime_script(string $versionRoot, string $method): never
{
    if (!in_array($method, ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
    $config = is_file($versionRoot . '/.zero-compiled/realtime.json')
        ? json_decode((string) file_get_contents($versionRoot . '/.zero-compiled/realtime.json'), true)
        : null;
    $public = is_array($config) && is_string($config['cast_resource_key'] ?? null) && is_string($config['cast_ws_url'] ?? null)
        ? ['wsUrl' => $config['cast_ws_url'], 'resourceKey' => $config['cast_resource_key'], 'ticketPath' => STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH]
        : null;
    $bundle = file_get_contents(__DIR__ . '/zero-realtime.js');
    if (!is_string($bundle)) throw new RuntimeException('The Zero realtime bundle is unavailable.');
    $body = $public === null
        ? "/* No live updates on this version: watched reads poll. */\n"
        : '(function(__SPACEFAST_ZERO_REALTIME__){' . $bundle . "\n})(" . json_encode($public, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ");\n";
    _stattic_response_send(200, $body, 'text/javascript; charset=utf-8',
        ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'ETag' => '"' . hash('sha256', $body) . '"']);
}
