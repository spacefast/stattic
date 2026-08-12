<?php
declare(strict_types=1);

// The edge-purge worker, CLI only:
// `php engine/entrypoints/purge.php --private-root=…`. shared/purge.php spawns
// this as a subprocess from the mutation that queued the record. A loopback
// HTTP kick self-deadlocks on a narrow FPM pool, because the caller holds the
// very worker the kick needs; a child process has no such dependency — so this
// file is never mounted as a public alias.
//
// This is the ONE place in the runtime that loads WordPress. A full
// `wp-load.php` bootstrap fires MU and customer plugin hooks (measured 80-99 ms
// on an empty site) and is what makes the provider's `Edge_Cache_Plugin` bridge
// exist at all — SHORTINIT returns before MU plugins load and never exposes it.
// Neither a serving request nor a management request may pay that or execute
// tenant code, which is why the work happens here and not at the call site.
//
// It takes no purge TARGET from its caller: everything it does comes from the
// durable queue.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "purge.php runs on the CLI only\n");
    exit(2);
}

$storageRoot = '';
foreach (array_slice(is_array($argv ?? null) ? $argv : [], 1) as $_stattic_purge_arg) {
    if (is_string($_stattic_purge_arg) && str_starts_with($_stattic_purge_arg, '--private-root=')) {
        $storageRoot = substr($_stattic_purge_arg, strlen('--private-root='));
    }
}
if ($storageRoot === '') {
    fwrite(STDERR, "usage: purge.php --private-root=<path>\n");
    exit(2);
}
// Deployed layout: <siteRoot>/.stattic/storage. The bootstrap probes this and
// its parent, so a differing layout degrades to "no bridge found".
$root = dirname($storageRoot, 2);
$engineRoot = dirname(__DIR__);

require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/purge.php';

if (!is_dir($storageRoot)) {
    fwrite(STDERR, "runtime storage is not provisioned\n");
    exit(3);
}

/**
 * Loads WordPress far enough for the provider edge-cache bridge to exist.
 *
 * Feature detection all the way down, never a provider-internal path: a live
 * site with NO `wp-content/mu-plugins/edge-cache.php` still got
 * `Edge_Cache_Plugin` injected during the full bootstrap, and `wp-load.php` is
 * the only stable entry.
 */
function _stattic_purge_bootstrap_wordpress(string $siteRoot): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $loaded = false;
    if (class_exists('Edge_Cache_Plugin')) {
        $loaded = true;
        return $loaded;
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
        return $loaded;
    }
    return $loaded;
}

/**
 * THE provider call. Live-verified surface (wpcloud-runtime-behavior skill):
 * `Edge_Cache_Plugin::get_instance()->purge_uris_now($urls, $reason)` and
 * `->purge_domain_now()` both returned true from PHP on the site itself.
 *
 * Every provider surface is feature-detected on every call — these are injected
 * by the host and can drift without notice. When the bridge is absent the record
 * is NOT consumed: it stays queued, journaled as `purge_provider_unavailable`,
 * and the maintenance tick keeps re-kicking until the attempt cap.
 */
function _stattic_purge_provider_call(string $privateRoot, string $siteRoot, array $record): bool
{
    if (!_stattic_purge_bootstrap_wordpress($siteRoot) || !class_exists('Edge_Cache_Plugin')) {
        _stattic_purge_provider_unavailable($privateRoot, $record, 'edge_cache_plugin_absent');
        return false;
    }
    $edge = Edge_Cache_Plugin::get_instance();
    if (!is_object($edge)) {
        _stattic_purge_provider_unavailable($privateRoot, $record, 'edge_cache_instance_absent');
        return false;
    }

    $urls = is_array($record['urls'] ?? null) ? array_values(array_filter($record['urls'], 'is_string')) : [];
    $reason = 'spacefast:' . (is_string($record['reason'] ?? null) ? $record['reason'] : 'runtime_mutation');

    if (($record['scope'] ?? null) === 'urls') {
        // A url-scoped record with nothing to purge is malformed, never a licence
        // to blow away the whole domain: journal it and let the queue retry/cap.
        if ($urls === []) {
            _stattic_purge_provider_unavailable($privateRoot, $record, 'purge_urls_empty');
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
            _stattic_purge_provider_unavailable($privateRoot, $record, 'purge_uris_now_absent');
            return false;
        }
        return _stattic_runtime_purge_provider_accepted($edge->purge_uris_now($urls, $reason));
    }

    if (!method_exists($edge, 'purge_domain_now')) {
        _stattic_purge_provider_unavailable($privateRoot, $record, 'purge_domain_now_absent');
        return false;
    }
    // `purge_domain_now($action)` takes no domain: it purges THE site's own
    // domain (Edge_Cache_Purge::populate_site_info -> platform->get_domain_name)
    // and logs $action. A Space usually answers on several hostnames — custom
    // domains, the platform subdomain, preview hosts — and each is a separate
    // edge key, so the record's own hostnames are ALSO purged explicitly.
    $hostnames = _stattic_runtime_purge_hostname_list($record['hostnames'] ?? null);
    $domainPurged = _stattic_runtime_purge_provider_accepted($edge->purge_domain_now($reason));
    if ($hostnames === [] || !method_exists($edge, 'purge_uris_now')) {
        return $domainPurged;
    }
    $hostUrls = _stattic_runtime_purge_expand_urls($hostnames, ['/']);
    return _stattic_runtime_purge_provider_accepted($edge->purge_uris_now($hostUrls, $reason))
        && $domainPurged;
}

function _stattic_purge_provider_unavailable(string $privateRoot, array $record, string $reason): void
{
    _stattic_runtime_append_journal($privateRoot, [
        'event' => 'purge_provider_unavailable',
        'purge_id' => is_string($record['id'] ?? null) ? $record['id'] : '',
        'reason' => $reason,
    ]);
}

// A wedged provider bootstrap must not pin a worker forever; the queue is durable
// and the next kick picks up whatever this run did not finish. The CLI lane is
// bounded by its parent's subprocess timeout as well.
@set_time_limit(120);

$summary = _stattic_runtime_purge_process_queue(
    $storageRoot,
    static fn (array $record): bool => _stattic_purge_provider_call($storageRoot, $root, $record),
);

// A full bootstrap prints whatever tenant code feels like printing, so the
// receipt is a sentinel-prefixed line the parent searches for rather than
// "the contents of stdout".
$encoded = json_encode($summary, JSON_UNESCAPED_SLASHES);
echo "\n" . STATTIC_RUNTIME_PURGE_RESULT_SENTINEL . (is_string($encoded) ? $encoded : '{}') . "\n";
exit(0);
