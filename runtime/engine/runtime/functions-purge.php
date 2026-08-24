<?php

/**
 * Machine purge intake: POST /__spacefast/functions/purge.
 *
 * A deployed Functions worker (or the control plane acting for it) asks the
 * origin to drop its hosts' edge entries after a mutation the static publish
 * pipeline never saw: an ISR revalidate, a webhook-driven content change. The
 * caller authenticates with the bearer purge credential in `sf-purge-token`,
 * carries `{paths: string[]}`, and gets a 202: accepted into the durable purge
 * queue, not confirmed by the edge. It is the same queue and provider bridge
 * every management mutation already drains through (shared/purge.php).
 *
 * Paths only, no tags: a tag names a group only the worker's own cache object
 * understands, and OpenNext resolves tags to concrete routes BEFORE minting
 * the purge frame, so a tag could never reach this route with meaning. The
 * paths validate and coalesce the request; the eviction itself is whole-host
 * (shared/purge.php header explains why no narrower scope exists).
 *
 * Like the relay and log intake beside it, this route holds no per-tenant
 * state and resolves no content: authority travels in the presented token.
 */

require_once __DIR__ . '/../shared/artifacts.php';
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/response.php';
// Config reads answer empty until this has run, which for the purge kick means
// silently failing to find the CLI binary it spawns.
require_once __DIR__ . '/../shared/bootstrap-config.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/purge.php';

// A purge body is a list of paths, never content; anything above this is a
// broken caller, not a big purge.
const STATTIC_FUNCTIONS_PURGE_MAX_BODY_BYTES = 65536;

// Per-space admission: at most this many ACCEPTED purges per rolling window.
// The ceiling exists because every accepted request becomes provider API calls
// against a shared edge: a runaway revalidate loop must exhaust its own quota,
// not the provider's.
const STATTIC_FUNCTIONS_PURGE_QUOTA_PER_WINDOW = 10;
const STATTIC_FUNCTIONS_PURGE_QUOTA_WINDOW_SECONDS = 60;
// An identical set accepted this recently is already owed to the edge by a
// durable record, so a repeat is acknowledged without minting a second one.
const STATTIC_FUNCTIONS_PURGE_COALESCE_SECONDS = 10;

/**
 * The purge credential, read from the version-adjacent functions/config.json
 * the control plane wrote at finalize under `purge.token` (the mint is
 * control-plane-side; this origin only compares). Version fencing is inherent:
 * the document is version-scoped, so the pointer flip that activates a
 * successor version swaps the credential with it, and a caller still holding
 * the old token fences itself out with no extra generation check.
 */
function _stattic_functions_purge_expected_token(string $versionRoot): ?string
{
    $read = _stattic_functions_config_read($versionRoot);
    if ($read['kind'] !== 'present') {
        return null;
    }
    $purge = is_array($read['value']['purge'] ?? null) ? $read['value']['purge'] : [];
    $token = $purge['token'] ?? null;
    return is_string($token) && $token !== '' ? $token : null;
}

// Deliberately the same 404 for every refusal: no purge block, a non-Functions
// version, a wrong token. Same as the functions host treats its signed bundle
// route, because distinguishing them would confirm which spaces carry a purge
// credential.
function _stattic_functions_purge_refused(): never
{
    _stattic_response_send(404, "Not found.\n", 'text/plain; charset=utf-8', ['Cache-Control' => 'no-store']);
}

function _stattic_functions_purge_bearer(): string
{
    $token = $_SERVER['HTTP_SF_PURGE_TOKEN'] ?? '';
    return is_string($token) ? trim($token) : '';
}

// The per-space admission ledger: one record per space, quota window plus the
// last accepted signature for coalescing. A record is debris once its window
// and coalesce horizon have passed; retention reclaims it on the store's own
// sweep cadence.
function _stattic_functions_purge_admission_store(string $privateRoot): array
{
    $root = $privateRoot . '/runtime/functions-purge';
    return _stattic_record_store($root, [
        'retention' => [
            'mtime_seconds' => STATTIC_FUNCTIONS_PURGE_QUOTA_WINDOW_SECONDS * 2,
            'throttle_seconds' => STATTIC_FUNCTIONS_PURGE_QUOTA_WINDOW_SECONDS,
            'marker' => $root . '/.last-cleanup',
        ],
    ]);
}

/**
 * Quota + coalescing in one critical section under the space's stripe lock, so
 * two concurrent purges cannot both pass a nearly-full window or double-mint a
 * coalesced set.
 *
 * @return array{verdict: string, retry_after?: int}
 */
function _stattic_functions_purge_admit(array $store, string $spaceId, string $signature, int $now): array
{
    $decision = _stattic_record_store_mutate(
        $store,
        $spaceId,
        static function (?array $record) use ($store, $spaceId, $signature, $now): array {
            $windowStartedAt = is_array($record) && is_int($record['window_started_at'] ?? null)
                ? $record['window_started_at']
                : 0;
            $count = is_array($record) && is_int($record['count'] ?? null) ? max(0, $record['count']) : 0;
            if ($now - $windowStartedAt >= STATTIC_FUNCTIONS_PURGE_QUOTA_WINDOW_SECONDS) {
                $windowStartedAt = $now;
                $count = 0;
                $record = null;
            }
            $lastSignature = is_array($record) && is_string($record['last_signature'] ?? null)
                ? $record['last_signature']
                : '';
            $lastAcceptedAt = is_array($record) && is_int($record['last_accepted_at'] ?? null)
                ? $record['last_accepted_at']
                : 0;
            if (
                $lastSignature !== ''
                && hash_equals($lastSignature, $signature)
                && $now - $lastAcceptedAt < STATTIC_FUNCTIONS_PURGE_COALESCE_SECONDS
            ) {
                // Coalesced repeats spend no quota: the earlier record already
                // owes the identical set to the edge.
                return ['verdict' => 'coalesced'];
            }
            if ($count >= STATTIC_FUNCTIONS_PURGE_QUOTA_PER_WINDOW) {
                return [
                    'verdict' => 'over_quota',
                    'retry_after' => max(1, $windowStartedAt + STATTIC_FUNCTIONS_PURGE_QUOTA_WINDOW_SECONDS - $now),
                ];
            }
            _stattic_record_store_put($store, $spaceId, [
                'window_started_at' => $windowStartedAt,
                'count' => $count + 1,
                'last_signature' => $signature,
                'last_accepted_at' => $now,
            ]);
            return ['verdict' => 'accepted'];
        },
    );
    return is_array($decision) ? $decision : ['verdict' => 'accepted'];
}

// Never returns.
function _stattic_functions_purge_serve(string $privateRoot, string $spaceId, string $versionRoot, string $requestMethod): void
{
    if ($requestMethod !== 'POST') {
        _stattic_method_not_allowed('POST', ['code' => 'method_not_allowed', 'message' => 'Purge accepts POST.']);
    }
    $expected = _stattic_functions_purge_expected_token($versionRoot);
    $presented = _stattic_functions_purge_bearer();
    if ($expected === null || $presented === '' || !hash_equals($expected, $presented)) {
        _stattic_functions_purge_refused();
    }

    $body = _stattic_bounded_request_body(STATTIC_FUNCTIONS_PURGE_MAX_BODY_BYTES);
    if ($body === null) {
        _stattic_problem_refused(413, 'purge_payload_too_large', 'Purge request exceeds the size limit.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        _stattic_problem_refused(422, 'purge_invalid_body', 'Purge body must be a JSON object with a paths array.');
    }
    $paths = _stattic_runtime_purge_path_list($decoded['paths'] ?? null);
    if ($paths === []) {
        _stattic_problem_refused(422, 'purge_empty', 'Purge names no valid paths.');
    }

    $hostnames = _stattic_runtime_route_intent_hostnames(_stattic_space_root($privateRoot, $spaceId));
    $signature = hash('sha256', (string) json_encode([$hostnames, $paths], JSON_UNESCAPED_SLASHES));
    $store = _stattic_functions_purge_admission_store($privateRoot);
    $decision = _stattic_functions_purge_admit($store, $spaceId, $signature, time());
    if (($decision['verdict'] ?? null) === 'over_quota') {
        _stattic_problem_response(429, 'purge_rate_limited', 'Purge quota for this space is exhausted; retry shortly.', [], [
            'Retry-After' => (string) (int) ($decision['retry_after'] ?? 1),
            'Cache-Control' => 'no-store',
        ]);
    }

    // A space whose intent names no hostname is unaddressable at the provider
    // (shared/purge.php requires hostnames); accepting and doing nothing is the
    // honest answer: there is no edge entry to drop. 202 is the contract:
    // accepted, never edge-confirmed. purge_now defers the loopback provider
    // call past fastcgi_finish_request, so the caller gets its 202 immediately.
    if (($decision['verdict'] ?? null) === 'accepted' && $hostnames !== []) {
        _stattic_runtime_purge_now($privateRoot, [
            'hostnames' => $hostnames,
            'reason' => 'functions_purge',
        ]);
        _stattic_defer(static function () use ($store): void {
            _stattic_record_store_sweep($store);
        });
    }
    _stattic_json_response(202, ['accepted' => true]);
}
