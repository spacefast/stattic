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
 * - Jetpack Boost's page cache, through its public `jetpack_boost_clear_page_cache_url` action;
 * - the WP Cloud (Atomic) edge, through the platform's `edge_cache_purge_uris` action (absolute
 *   URLs) and `edge_cache_purge_domain` for the whole host, the actions Jetpack fires. The platform
 *   registers them on `init`, only where its purge API is configured, queues what they ask for and
 *   sends it once at shutdown, clearing the host's Batcache entries first. The edge stores public
 *   pages and keeps serving one past its `s-maxage` while it refreshes in the background, so a
 *   purge is what makes a write show at once.
 * A whole-host purge (`/*`), or more paths than VIP queues in one request (VIP_URLS), bans the VIP
 * site; `/*` also clears Boost's whole cache and purges the WP Cloud edge domain. Batcache itself has
 * no whole-site clear (its object-cache group is shared by every site of a network), so outside WP
 * Cloud its entries run out with the cache window. Boost clears a URL recursively, so the home page
 * goes to its own home action. Batcache counts only while its drop-in runs (`$batcache` is set): its
 * manager plugin defines `batcache_clear_url()` even when WP_CACHE is off.
 *
 * Batcache stores what a request prints whatever its `Cache-Control` says, and skips only requests
 * with WordPress cookies. A request signed in another way (an application password, a bearer token,
 * an Identity session) would be stored and replayed to anonymous visitors, so any request that runs
 * as a user cancels Batcache ({@see private()}).
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
        add_action('set_current_user', [self::class, 'private'], 0, 0);
        // A plugin that loaded first may have signed the request in already (the deploy plugin does).
        if (did_action('set_current_user') > 0) {
            self::private();
        }
        // After every plugin (Batcache, Jetpack Boost) and mu-plugin (VIP) has defined its functions,
        // and again after `init`, where the WP Cloud platform adds its purge actions; at once when the
        // runtime is mounted later than that.
        $listen = static function (): void {
            if (self::present() !== [] && !has_action('tp_purge_urls', [self::class, 'purge'])) {
                add_action('tp_purge_urls', [self::class, 'purge']);
            }
        };
        foreach (['plugins_loaded', 'init'] as $hook) {
            if (did_action($hook) > 0 && !doing_action($hook)) {
                $listen();
            } else {
                add_action($hook, $listen, PHP_INT_MAX);
            }
        }
    }

    /** set_current_user: a response this request makes as a signed-in user is never Batcache's to keep. */
    public static function private(): void
    {
        // Read without resolving the user: this can run while plugins load, before every sign-in
        // check is registered (the deploy plugin unsets the user for exactly that reason).
        $user = $GLOBALS['current_user'] ?? null;
        if ($user instanceof \WP_User && $user->ID > 0 && function_exists('batcache_cancel')) {
            batcache_cancel();
        }
    }

    /** @return list<string> the page caches loaded on this site */
    private static function present(): array
    {
        return array_keys(array_filter([
            'batcache' => self::batcache(),
            'vip' => function_exists('wpvip_purge_edge_cache_for_url') || function_exists('wpcom_vip_purge_edge_cache_for_url'),
            'jetpack-boost' => has_action('jetpack_boost_clear_page_cache_url') !== false,
            'wpcloud-edge' => has_action('edge_cache_purge_uris') !== false,
        ]));
    }

    /** @param list<string>|null $urls absolute URLs for the WP Cloud edge, or null for the whole domain */
    private static function purgeEdge(?array $urls): void
    {
        $urls === null ? do_action('edge_cache_purge_domain') : do_action('edge_cache_purge_uris', $urls);
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
        $edge = [];
        foreach ($paths as $path) {
            if ($path === '/*') {
                do_action('jetpack_boost_clear_page_cache_all');
                self::purgeEdge(null);
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
            $edge[] = $url;
        }
        // One call for every URL: the platform sends them to the edge in one purge request.
        if ($edge !== [] && !in_array('/*', $paths, true)) {
            self::purgeEdge($edge);
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
