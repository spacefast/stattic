<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * Default `tp_purge_urls` listeners for the page caches WordPress sites already run, so purges
 * reach them without a host of its own (docs/runtime-contract.md, "Purges"):
 * - Batcache (`batcache_clear_url()`): it keys a page on `http://<host><path>`, with no query
 *   string except `?p=`, so each path is cleared in that form;
 * - the VIP edge cache (`wpvip_purge_edge_cache_for_url()`, or `wpcom_vip_purge_edge_cache_for_url()`
 *   on older VIP mu-plugins): absolute URLs, query strings dropped by VIP itself;
 * - Jetpack Boost's page cache, through its public `jetpack_boost_clear_page_cache_url` action.
 * A whole-host purge (`/*`), or more paths than VIP queues in one request (VIP_URLS), bans the VIP
 * site; `/*` also clears Boost's whole cache. Batcache has no whole-site clear, so its entries run out
 * with the cache window. Boost clears a URL recursively, so the home page goes to its own home action.
 * Batcache counts only while its drop-in runs (`$batcache` is set): its manager plugin defines
 * `batcache_clear_url()` even when WP_CACHE is off.
 *
 * Nothing listens when none of them is loaded, so on a site without a page cache TypedPress records and
 * announces nothing (`has_action('tp_purge_urls')` stays false).
 */
final class PageCaches
{
    /** VIP queues at most this many URL purges per request (WPCOM_VIP_Cache_Manager::MAX_PURGE_URLS). */
    public const VIP_URLS = 100;

    public static function register(): void
    {
        // After every plugin (Batcache, Jetpack Boost) and mu-plugin (VIP) has defined its functions;
        // at once when the runtime is mounted later than that.
        $listen = static function (): void {
            if (self::present() !== [] && !has_action('tp_purge_urls', [self::class, 'purge'])) {
                add_action('tp_purge_urls', [self::class, 'purge']);
            }
        };
        if (did_action('plugins_loaded') > 0 && !doing_action('plugins_loaded')) {
            $listen();
        } else {
            add_action('plugins_loaded', $listen, PHP_INT_MAX);
        }
    }

    /** @return list<string> the page caches loaded on this site */
    private static function present(): array
    {
        return array_keys(array_filter([
            'batcache' => self::batcache(),
            'vip' => function_exists('wpvip_purge_edge_cache_for_url') || function_exists('wpcom_vip_purge_edge_cache_for_url'),
            'jetpack-boost' => has_action('jetpack_boost_clear_page_cache_url') !== false,
        ]));
    }

    /** @param list<string> $paths root-relative paths with their query, as `tp_purge_urls` gives them */
    public static function purge(array $paths): void
    {
        $origin = self::origin();
        $home = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/');
        $vipSite = function_exists('wpvip_purge_edge_cache_for_site') && (in_array('/*', $paths, true) || count($paths) > self::VIP_URLS);
        if ($vipSite) {
            wpvip_purge_edge_cache_for_site();
        }
        foreach ($paths as $path) {
            if ($path === '/*') {
                do_action('jetpack_boost_clear_page_cache_all');
                continue;
            }
            $url = $origin . $path;
            if (self::batcache()) {
                batcache_clear_url(self::batcacheUrl($url));
            }
            if (!$vipSite && function_exists('wpvip_purge_edge_cache_for_url')) {
                wpvip_purge_edge_cache_for_url($url);
            } elseif (!$vipSite && function_exists('wpcom_vip_purge_edge_cache_for_url')) {
                wpcom_vip_purge_edge_cache_for_url($url);
            }
            if ($path === $home) {
                do_action('jetpack_boost_clear_page_cache_home');
            } else {
                do_action('jetpack_boost_clear_page_cache_url', $url);
            }
        }
    }

    private static function batcache(): bool
    {
        return function_exists('batcache_clear_url') && isset($GLOBALS['batcache']) && is_object($GLOBALS['batcache']);
    }

    /** `https://example.com` (with its port): what a root-relative path is joined to. */
    private static function origin(): string
    {
        $home = (array) wp_parse_url(home_url('/'));
        $port = isset($home['port']) ? ':' . $home['port'] : '';

        return ($home['scheme'] ?? 'http') . '://' . ($home['host'] ?? '') . $port;
    }

    /** The URL as Batcache keys it: its query string goes, except a `?p=<id>` permalink. */
    private static function batcacheUrl(string $url): string
    {
        [$bare, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $args);

        return $bare . (isset($args['p']) && is_scalar($args['p']) ? '?p=' . $args['p'] : '');
    }
}
