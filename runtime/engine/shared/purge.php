<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/record-store.php';

// A mutation that changed served bytes writes a durable purge record here, then
// drains the queue IN-PROCESS after the response closes: the deferred shutdown
// pass (after fastcgi_finish_request) loads `wp-load.php`, gets the provider
// bridge, and pays the bootstrap plus tenant code off the visitor path. There
// is no subprocess transport — wp.cloud's FPM pool runs in a mount namespace
// with NO php CLI anywhere (bare `php` with PATH set, /usr/local/bin/php and
// the versioned prefix all fail proc_open with ENOENT, measured live), so a
// spawned php child can never exist there. A failed drain leaves the record
// queued for the maintenance tick.

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

// No documented provider maximum for one purge batch; chunk conservatively so
// a several-hundred-alias space never rides a single unproven mega-batch.
const STATTIC_RUNTIME_PURGE_HOST_BATCH_SIZE = 50;

/**
 * Host-level purges for every hostname a record names. Each hostname is its
 * own edge key, and a root-URI purge leaves its deeper paths stale — custom
 * domains served old documents until this owned full-host purges (PR #1996
 * fixed it control-plane-side; the runtime owns purging now). The provider
 * library's host bucket is the primary lane; root-URI expansion is the
 * fallback for a plugin variant without it.
 *
 * @param list<string> $hostnames
 */
function _stattic_runtime_purge_hosts_now(object $edge, array $hostnames, string $reason): bool
{
    if ($hostnames === []) {
        return true;
    }
    if (class_exists('Edge_Cache') && method_exists('Edge_Cache', 'edge_cache_purge')) {
        $bucket = defined('Edge_Cache::HOST_CACHE') ? constant('Edge_Cache::HOST_CACHE') : 'edge_cache_host';
        $accepted = true;
        foreach (array_chunk($hostnames, STATTIC_RUNTIME_PURGE_HOST_BATCH_SIZE) as $chunk) {
            $accepted = _stattic_runtime_purge_provider_accepted(
                Edge_Cache::edge_cache_purge([$bucket => $chunk], [$reason]),
            ) && $accepted;
        }
        return $accepted;
    }
    if (!method_exists($edge, 'purge_uris_now')) {
        return false;
    }
    return _stattic_runtime_purge_provider_accepted(
        $edge->purge_uris_now(_stattic_runtime_purge_expand_urls($hostnames, ['/']), $reason),
    );
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

// The public→private edge is the ONE access transition that owes the edge a
// full sweep: every alias may hold year-TTL copies of formerly-public HTML.
// The reverse owes nothing — denied responses are `private, no-store` by
// construction — and a missing or malformed NEW descriptor reads as
// not-public, so the ambiguous side still sweeps.
function _stattic_runtime_exposure_became_private(?array $previous, ?array $next): bool
{
    return is_array($previous)
        && ($previous['public'] ?? null) === true
        && !(is_array($next) && ($next['public'] ?? null) === true);
}

/**
 * Every hostname the space answers on — route intent plus tombstones — ordered
 * for a full access sweep: live serving hostnames first, then version-pinned
 * hostnames newest→oldest (the `v<number>--` label token is the only recency
 * the intent carries; underivable ones follow in intent order), then
 * redirect/proxy hosts and tombstoned hostnames. Order decides who gets fresh
 * bytes first, never who gets purged — the host-batch drain preserves it.
 *
 * @return list<string>
 */
function _stattic_runtime_access_sweep_hostnames(?array $intent, ?array $tombstones): array
{
    $production = [];
    $numbered = [];
    $unnumbered = [];
    $rest = [];
    foreach (is_array($intent['routes'] ?? null) ? $intent['routes'] : [] as $route) {
        if (!is_array($route) || !is_string($route['hostname'] ?? null)) {
            continue;
        }
        $type = is_array($route['target'] ?? null) ? ($route['target']['type'] ?? null) : null;
        if ($type === 'route') {
            $production[] = $route['hostname'];
        } elseif ($type === 'version') {
            $label = strstr($route['hostname'], '.', true);
            if (preg_match('/\Av(\d+)--/', $label === false ? $route['hostname'] : $label, $token) === 1) {
                $numbered[(int) $token[1]][] = $route['hostname'];
            } else {
                $unnumbered[] = $route['hostname'];
            }
        } else {
            $rest[] = $route['hostname'];
        }
    }
    krsort($numbered);
    $newestFirst = $numbered === [] ? [] : array_merge(...array_values($numbered));
    $hostnames = [...$production, ...$newestFirst, ...$unnumbered, ...$rest];
    if (is_array($tombstones) && is_array($tombstones['hostnames'] ?? null)) {
        foreach ($tombstones['hostnames'] as $hostname) {
            if (is_string($hostname)) {
                $hostnames[] = $hostname;
            }
        }
    }
    // First occurrence wins, so a tombstoned hostname the intent still serves
    // keeps its serving-order slot.
    return array_values(array_unique($hostnames));
}

/**
 * Writes one durable purge record and returns it.
 *
 * `$input`: hostnames (required — a purge with no hostname is unaddressable),
 * paths, reason. Scope is DERIVED, never passed: a non-empty set of at most 300
 * normalized URLs purges by URI only when every URL is a pure asset; document,
 * oversized, and unaddressable sets purge the whole domain.
 */
function _stattic_runtime_purge_enqueue(string $privateRoot, array $input): array
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
    return $record;
}

// Deployed layout: <siteRoot>/.stattic/storage — the drain derives the site
// root from the private root it is handed; a differing layout degrades to "no
// bridge found" through the same feature detection.
function _stattic_runtime_purge_site_root(string $privateRoot): string
{
    return dirname($privateRoot, 2);
}

/**
 * Loads WordPress far enough for the provider edge-cache bridge to exist.
 *
 * This is the ONE place in the runtime that loads WordPress, and it runs
 * post-response (or in the CLI worker): a full `wp-load.php` bootstrap fires MU
 * and customer plugin hooks (measured 80-99 ms on an empty site) and is what
 * makes the provider's `Edge_Cache_Plugin` bridge exist at all — SHORTINIT
 * returns before MU plugins load and never exposes it. No serving or management
 * RESPONSE may pay that or execute tenant code, which is why every caller
 * reaches this only after its response closed.
 *
 * Safe from the loader hijack: `wp-load.php`'s config chain can re-include the
 * provider's `custom-redirects.php`, but the FPM requests that get here carry a
 * REQUEST_URI the loader classifies as an entrypoint (/__spacefast/api.php) or
 * a request whose engine is already loaded (require_once no-ops), and the CLI
 * worker carries no REQUEST_METHOD at all — the loader returns for each.
 *
 * Feature detection all the way down, never a provider-internal path: a live
 * site with NO `wp-content/mu-plugins/edge-cache.php` still got
 * `Edge_Cache_Plugin` injected during the full bootstrap, and `wp-load.php` is
 * the only stable entry.
 */
function _stattic_runtime_purge_bootstrap_wordpress(string $siteRoot): bool
{
    // Success is cached for the process; failure is re-probed — an FPM worker
    // outlives many requests, and the bridge can appear between drains (an
    // engine converge, a provider MU-plugin roll).
    static $loaded = false;
    if ($loaded || class_exists('Edge_Cache_Plugin')) {
        $loaded = true;
        return true;
    }
    $documentRoot = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : '';
    foreach ([$siteRoot, $documentRoot, dirname($siteRoot)] as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        $wpLoad = rtrim($candidate, '/') . '/wp-load.php';
        if (!is_file($wpLoad)) {
            continue;
        }
        require_once $wpLoad;
        $loaded = true;
        return true;
    }
    return false;
}

/**
 * THE provider call. Live-verified surface (wpcloud-runtime-behavior skill):
 * `Edge_Cache_Plugin::get_instance()->purge_uris_now($urls, $reason)` and
 * `->purge_domain_now()` both returned true from PHP on the site itself.
 *
 * Every provider surface is feature-detected on every call — these are injected
 * by the host and can drift without notice. When wp-load or the bridge is
 * absent the record is NOT consumed: it stays queued, journaled as
 * `purge_provider_unavailable`, and the maintenance tick keeps re-draining
 * until the attempt cap.
 */
function _stattic_runtime_purge_provider_call(string $privateRoot, string $siteRoot, array $record): bool
{
    if (!_stattic_runtime_purge_bootstrap_wordpress($siteRoot) || !class_exists('Edge_Cache_Plugin')) {
        _stattic_runtime_purge_provider_unavailable($privateRoot, $record, 'edge_cache_plugin_absent');
        return false;
    }
    $edge = Edge_Cache_Plugin::get_instance();
    if (!is_object($edge)) {
        _stattic_runtime_purge_provider_unavailable($privateRoot, $record, 'edge_cache_instance_absent');
        return false;
    }

    $urls = is_array($record['urls'] ?? null) ? array_values(array_filter($record['urls'], 'is_string')) : [];
    $reason = 'spacefast:' . (is_string($record['reason'] ?? null) ? $record['reason'] : 'runtime_mutation');

    if (($record['scope'] ?? null) === 'urls') {
        // A url-scoped record with nothing to purge is malformed, never a licence
        // to blow away the whole domain: journal it and let the queue retry/cap.
        if ($urls === []) {
            _stattic_runtime_purge_provider_unavailable($privateRoot, $record, 'purge_urls_empty');
            return false;
        }
        // The action hook is the provider's preferred entry but reports nothing
        // back, so it is only usable for a record whose caller is not waiting on
        // acceptance; anything carrying a receipt takes the synchronous call.
        $wantsReceipt = ($record['receipt'] ?? true) !== false;
        if (!$wantsReceipt && function_exists('do_action')) {
            do_action('edge_cache_purge_uris', $urls);
            return true;
        }
        if (!method_exists($edge, 'purge_uris_now')) {
            _stattic_runtime_purge_provider_unavailable($privateRoot, $record, 'purge_uris_now_absent');
            return false;
        }
        return _stattic_runtime_purge_provider_accepted($edge->purge_uris_now($urls, $reason));
    }

    if (!method_exists($edge, 'purge_domain_now')) {
        _stattic_runtime_purge_provider_unavailable($privateRoot, $record, 'purge_domain_now_absent');
        return false;
    }
    // `purge_domain_now($action)` takes no domain: it purges THE site's own
    // domain (Edge_Cache_Purge::populate_site_info -> platform->get_domain_name)
    // and logs $action. A Space usually answers on several hostnames — custom
    // domains, the platform subdomain, preview hosts — and each is a separate
    // edge key, so the record's own hostnames get full host purges too.
    $hostnames = _stattic_runtime_purge_hostname_list($record['hostnames'] ?? null);
    $domainPurged = _stattic_runtime_purge_provider_accepted($edge->purge_domain_now($reason));
    if ($hostnames === []) {
        return $domainPurged;
    }
    return _stattic_runtime_purge_hosts_now($edge, $hostnames, $reason) && $domainPurged;
}

function _stattic_runtime_purge_provider_unavailable(string $privateRoot, array $record, string $reason): void
{
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'purge_provider_unavailable',
        'purge_id' => is_string($record['id'] ?? null) ? $record['id'] : '',
        'reason' => $reason,
    ]);
}

/**
 * The in-process drain: bootstrap WordPress, hand the provider bridge to the
 * queue processor, in THIS process. Runs post-response on FPM (deferred past
 * fastcgi_finish_request), inline on CLI dispatch and the php -S harness, and
 * from the operator CLI worker (entrypoints/purge.php).
 *
 * @return array{processed: int, ok: int, failed: int, dead: int, results: array<string,string>}
 */
function _stattic_runtime_purge_drain(string $privateRoot): array
{
    // A wedged provider bootstrap must not pin a worker forever; the queue is
    // durable and the next drain picks up whatever this pass did not finish.
    @set_time_limit(120);
    $siteRoot = _stattic_runtime_purge_site_root($privateRoot);
    return _stattic_runtime_purge_process_queue(
        $privateRoot,
        static fn (array $record): bool => _stattic_runtime_purge_provider_call($privateRoot, $siteRoot, $record),
    );
}

/**
 * Enqueue + receipt, with the provider work moved past the response: the durable
 * record is journaled BEFORE the response, then the shutdown pass closes the
 * FastCGI response and drains the queue in-process. Draining before
 * fastcgi_finish_request would put the WordPress bootstrap and the provider
 * round-trip on the visitor's critical path. `status: queued` is
 * acceptance-of-journaling — a failed drain cannot lose the purge, because the
 * record plus the maintenance re-drain lane (attempt-capped above) already own
 * it.
 *
 * Without fastcgi_finish_request (CLI dispatch, the php -S test harness) the
 * drain stays synchronous and the receipt carries the provider outcome:
 * 'ok' the edge confirmed this record, 'failed' the provider refused it
 * (it stays queued until the attempt cap), 'pending' another drain owns it and
 * the maintenance tick still owes it.
 *
 * @return array{status: string, mode: string, urls?: int}
 */
function _stattic_runtime_purge_now(string $privateRoot, array $input): array
{
    $record = _stattic_runtime_purge_enqueue($privateRoot, $input);
    $purgeId = $record['id'];
    $mode = $record['scope'] === 'urls' ? 'urls' : 'domain';
    $urlCount = count($record['urls']);

    if (function_exists('fastcgi_finish_request')) {
        _stattic_flush_response_before_deferred(true);
        _stattic_defer(static function () use ($privateRoot): void {
            _stattic_runtime_purge_drain($privateRoot);
        });
        $status = 'queued';
    } else {
        $status = _stattic_runtime_purge_execute($privateRoot, $purgeId, $mode);
    }

    return $mode === 'urls'
        ? ['status' => $status, 'mode' => $mode, 'urls' => $urlCount]
        : ['status' => $status, 'mode' => $mode];
}

// One drain for one record's sake, with the outcome journaled when the edge is
// still owed. The synchronous lane only — the FPM lane defers the same drain.
function _stattic_runtime_purge_execute(string $privateRoot, string $purgeId, string $mode): string
{
    $store = _stattic_runtime_purge_store($privateRoot);
    $drain = _stattic_runtime_purge_drain($privateRoot);
    $outcome = $drain['results'][$purgeId] ?? null;
    $status = match (true) {
        $outcome === 'ok' => 'ok',
        $outcome === 'failed', $outcome === 'dead' => 'failed',
        // Not in OUR drain's summary. A drain deletes a record only on acceptance,
        // so a record that is gone was accepted by a concurrent drain; one still
        // on disk is owned by a drain that snapshotted it and is still owed.
        default => _stattic_record_store_get($store, $purgeId) === null ? 'ok' : 'pending',
    };
    if ($status !== 'ok') {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'edge_purge_incomplete',
            'purge_id' => $purgeId,
            'mode' => $mode,
            'status' => $status,
        ]);
    }
    return $status;
}

// The maintenance tick's re-drain: only when something is owed, and never in
// the tick's own response — the tick is a management POST whose receipt must
// not carry the WordPress bootstrap or tenant code, so the drain is deferred
// past its response close exactly like the publish lane's.
function _stattic_runtime_purge_requeue_drain(string $privateRoot): int
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
    if (function_exists('fastcgi_finish_request')) {
        _stattic_flush_response_before_deferred(true);
    }
    _stattic_defer(static function () use ($privateRoot): void {
        _stattic_runtime_purge_drain($privateRoot);
    });
    return $queued;
}

/**
 * The coalesced drain plan: one provider call per distinct edge effect. A
 * backlog is dominated by repeats — the same activation lane writing the same
 * hostname set over and over (a 289-record operator drain fired ~1,100 provider
 * calls for ~6 distinct effects) — so records with the same scope and the same
 * hostname set (domain) or the same URL set (urls) become ONE call whose
 * outcome every member shares. A domain purge already evicts every URL on its
 * hostnames, so a urls group whose hosts sit inside a domain group's set rides
 * that domain call instead of paying its own.
 *
 * The group's representative record is its oldest member; a urls group answers
 * with a synchronous receipt when any member asked for one.
 *
 * @param  array<string,array> $records queued records keyed by id, id-ascending.
 * @return list<array{record: array, ids: list<string>}>
 */
function _stattic_runtime_purge_coalesce(array $records): array
{
    $domainGroups = [];
    $urlGroups = [];
    foreach ($records as $id => $record) {
        $hostnames = _stattic_runtime_purge_hostname_list($record['hostnames'] ?? null);
        sort($hostnames, SORT_STRING);
        if (($record['scope'] ?? null) === 'urls') {
            $urls = array_values(array_unique(array_filter(
                is_array($record['urls'] ?? null) ? $record['urls'] : [],
                'is_string'
            )));
            sort($urls, SORT_STRING);
            $key = implode("\n", $urls);
            $urlGroups[$key] ??= ['record' => $record, 'ids' => [], 'hosts' => []];
            $urlGroups[$key]['ids'][] = $id;
            $urlGroups[$key]['hosts'] += array_fill_keys($hostnames, true);
            if (($record['receipt'] ?? true) !== false) {
                $urlGroups[$key]['record']['receipt'] = true;
            }
        } else {
            $key = implode("\n", $hostnames);
            $domainGroups[$key] ??= ['record' => $record, 'ids' => []];
            $domainGroups[$key]['ids'][] = $id;
        }
    }

    foreach ($urlGroups as $key => $group) {
        foreach ($domainGroups as $hostKey => $_) {
            $domainHosts = $hostKey === '' ? [] : array_fill_keys(explode("\n", $hostKey), true);
            if ($group['hosts'] !== [] && array_diff_key($group['hosts'], $domainHosts) === []) {
                $domainGroups[$hostKey]['ids'] = [...$domainGroups[$hostKey]['ids'], ...$group['ids']];
                unset($urlGroups[$key]);
                break;
            }
        }
    }

    $plan = [];
    foreach ([...array_values($domainGroups), ...array_values($urlGroups)] as $group) {
        $plan[] = ['record' => $group['record'], 'ids' => $group['ids']];
    }
    return $plan;
}

/**
 * Drains the queue through $provider, which returns true when the edge accepted
 * a record. Pure with respect to WordPress: the entrypoint owns the bootstrap
 * and hands the bridge in here as a callable, so this stays loadable (and
 * testable) without loading a CMS.
 *
 * The queue is snapshotted and coalesced first, then each distinct effect pays
 * one provider call — never per-record provider IO. Every member of a group
 * shares that call's outcome: acceptance deletes them all, refusal retains them
 * all (each bumping its own attempt count toward the dead-letter cap).
 * Acceptance remains the ONLY thing that deletes a record.
 *
 * Outcomes are applied under each record's stripe lock with a re-read, so a
 * record a concurrent drain already consumed is skipped and its delete is never
 * resurrected by this drain's failure path. The provider call itself runs
 * outside the locks: two concurrent drains can at worst repeat an idempotent
 * edge purge, bounded by the handful of distinct effects — never corrupt a
 * record.
 *
 * @param  callable(array): bool $provider
 * @return array{processed: int, ok: int, failed: int, dead: int, results: array<string,string>}
 */
function _stattic_runtime_purge_process_queue(string $privateRoot, callable $provider): array
{
    $store = _stattic_runtime_purge_store($privateRoot);
    _stattic_record_store_ensure($store);
    $summary = ['processed' => 0, 'ok' => 0, 'failed' => 0, 'dead' => 0, 'results' => []];

    $queued = [];
    foreach (_stattic_record_store_records($store) as $id => $record) {
        if (($record['status'] ?? 'queued') !== 'dead') {
            $queued[$id] = $record;
        }
    }

    foreach (_stattic_runtime_purge_coalesce($queued) as $group) {
        $accepted = false;
        try {
            $accepted = $provider($group['record']) === true;
        } catch (Throwable $error) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'edge_purge_provider_error',
                'purge_id' => is_string($group['record']['id'] ?? null) ? $group['record']['id'] : '',
                'error' => get_debug_type($error),
            ]);
        }
        $coalesced = count($group['ids']);

        foreach ($group['ids'] as $id) {
            $entries = [];
            $outcome = _stattic_record_store_mutate(
                $store,
                $id,
                static function (?array $record) use ($store, $id, $accepted, $coalesced, &$entries): ?string {
                    // Re-read under the lock: a concurrent drain may have
                    // consumed this record since the snapshot.
                    if ($record === null || ($record['status'] ?? 'queued') === 'dead') {
                        return null;
                    }
                    $mode = ($record['scope'] ?? null) === 'urls' ? 'urls' : 'domain';
                    $hostnames = is_array($record['hostnames'] ?? null) ? $record['hostnames'] : [];
                    $urlCount = is_array($record['urls'] ?? null) ? count($record['urls']) : 0;

                    if ($accepted) {
                        _stattic_record_store_delete($store, $id);
                        $entry = [
                            'event' => 'edge_purge',
                            'purge_id' => $id,
                            'mode' => $mode,
                            'hostnames' => $hostnames,
                            'url_count' => $urlCount,
                            'reason' => is_string($record['reason'] ?? null) ? $record['reason'] : '',
                            'attempts' => max(0, (int) ($record['attempts'] ?? 0)) + 1,
                        ];
                        if ($coalesced > 1) {
                            $entry['coalesced'] = $coalesced;
                        }
                        $entries[] = $entry;
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
            // Journaled outside the lock, so a contended stripe never waits on
            // the journal lock too.
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
    }

    return $summary;
}
