<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * One autoloaded option saying that a plugin-installed site already holds everything this build and
 * this runtime install on requests: the shared system tables, the app's
 * migrations, Action Scheduler's groups and recurring actions, route and content Pages, Polylang's
 * languages, the search index shape, the runtime-schema capability, a store's order storage. While
 * it matches, a request runs
 * none of those checks ({@see Runtime::installs()}), as a managed build never does on a request.
 *
 * Its value is a digest of the build (the loaded `schema.php`), the runtime tables version, the
 * runtime's own hash, the app, the UTC day and the active plugins (the site's and, on multisite, the
 * network's), so a new build, a runtime update, another app, a plugin change or a new day finds no
 * marker and checks again. On multisite each site has its own marker and its own answer.
 *
 * It is recorded at the end of `wp_loaded`, only after every check has been read again and passed;
 * a failed, refused or held install leaves it unrecorded, so the next request retries as before.
 * Anything that changes what it covers drops it: an option those checks keep ({@see watches()}), the
 * active plugins, activating, deactivating or migrating the app, and a change to one of the app's
 * route Pages. What it deliberately no longer re-checks on each request is in docs/runtime-contract.md.
 */
final class InstallMarker
{
    public const OPTION = 'tp_installed';

    /** Options the install checks read or write: a change to any of them means checking again. */
    private const WATCHED = [
        'tp_runtime_tables', Jobs\ActionSchedulerQueue::PREPARED, 'tp_search_shape', 'tp_kind_urls',
        'tp_polylang_languages', 'tp_schema_apply_granted', 'tp_upload_namespace', 'active_plugins', 'active_sitewide_plugins',
        // A store's order storage (Commerce\Module::checkOrderStorage).
        'woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled',
    ];

    /** @var array<int, bool> blog id => whether the marker matched, read once per site per request */
    private static array $current = [];
    /** @var array<int, int> blog id => forget() calls this request, so record() sees one made while it verified */
    private static array $forgotten = [];
    /** @var ?\Closure(): void runs between the final check and the write (tests inject an invalidation); null otherwise */
    public static ?\Closure $beforeWrite = null;

    public static function register(): void
    {
        add_action('added_option', static fn (string $option) => self::watches($option) && self::forget(), 10, 1);
        add_action('updated_option', static fn (string $option) => self::watches($option) && self::forget(), 10, 1);
        add_action('deleted_option', static fn (string $option) => self::watches($option) && self::forget(), 10, 1);
        // A recurring action of TypedPress's cancelled (Tools > Scheduled Actions): the next request schedules it again.
        add_action('action_scheduler_canceled_action', static fn () => self::forget(), 10, 0);
        // So does one whose next run Action Scheduler could not schedule: nothing else would.
        add_action('action_scheduler_failed_to_schedule_next_instance', static fn () => self::forget(), 10, 0);
        add_action('wp_loaded', [self::class, 'record'], PHP_INT_MAX);
    }

    /** Whether this request may skip the install checks: the marker matches this build. */
    public static function current(): bool
    {
        // Per site: on multisite, after switch_to_blog() another site's options decide.
        $blog = get_current_blog_id();
        if (isset(self::$current[$blog])) {
            return self::$current[$blog];
        }
        $plugin = Plugin::instance();
        if ($plugin === null || Runtime::managed()) {
            return false;
        }

        return self::$current[$blog] = get_option(self::OPTION) === self::key($plugin);
    }

    /** Drop the marker: the next check runs every install step again. Returns true, for use in expressions. */
    public static function forget(): bool
    {
        $blog = get_current_blog_id();
        self::$current[$blog] = false;
        self::$forgotten[$blog] = (self::$forgotten[$blog] ?? 0) + 1;
        // Unconditionally: this request may have cached the marker as absent (notoptions) before
        // another request wrote it. delete_option() looks the row up in the database itself (one
        // SELECT when there is none); forget() runs only on install and invalidation events.
        delete_option(self::OPTION);

        return true;
    }

    /**
     * wp_loaded, last: record the marker once every install check passes when read again now. Another
     * request can invalidate while this one verifies (cancel a recurring action, change an option the
     * checks keep); its forget() may find no marker to delete yet. So the checks run again after the
     * write, and the marker is removed when they no longer pass or a forget() ran meanwhile.
     */
    public static function record(): void
    {
        $plugin = Plugin::instance();
        $blog = get_current_blog_id();
        $seen = self::$forgotten[$blog] ?? 0;
        if ($plugin === null || Runtime::managed() || Runtime::plugin() !== $plugin || self::current() || !self::verified($plugin)) {
            return;
        }
        if (self::$beforeWrite !== null) {
            (self::$beforeWrite)();
        }
        update_option(self::OPTION, self::key($plugin), true);
        if ((self::$forgotten[$blog] ?? 0) !== $seen || !self::verified($plugin)) {
            delete_option(self::OPTION);
            self::$current[$blog] = false;

            return;
        }
        self::$current[$blog] = true;
    }

    private static function watches(string $option): bool
    {
        return in_array($option, self::WATCHED, true) || preg_match('/\Atp_[a-z0-9_-]+_(schema|refused|route_pages|content)\z/', $option) === 1;
    }

    private static function key(Plugin $plugin): string
    {
        // The active plugins are part of it: a plugin the install depends on (activated, deactivated,
        // or network-activated, which on multisite changes sitemeta and fires no option hook of this
        // site) means checking again. Both lists are already loaded on every request.
        $network = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', [])) : [];
        sort($network);

        return substr(hash('sha256', implode("\0", [
            $plugin->schema->name(), $plugin->buildHash(), Runtime::tablesVersion(), Runtime::VERSION,
            defined('TP_RUNTIME_HASH') ? (string) TP_RUNTIME_HASH : '', gmdate('Y-m-d'),
            implode(',', (array) get_option('active_plugins', [])), implode(',', $network),
        ])), 0, 32);
    }

    /** Every install step's own check, read past the option caches. */
    private static function verified(Plugin $plugin): bool
    {
        if ($plugin->unavailable() !== null) {
            return false;
        }
        wp_cache_delete('tp_runtime_tables', 'options');
        if (get_option('tp_runtime_tables') !== Runtime::tablesVersion() || !$plugin->installed()) {
            return false;
        }
        if (function_exists('as_has_scheduled_action') && class_exists(\ActionScheduler::class, false) && \ActionScheduler::is_initialized()
            && !(Jobs\ActionSchedulerQueue::installed() && Guests::installed() && Uploads::installed())) {
            return false;
        }
        // A localized app's languages are provisioned on a request where Polylang is set up (REST,
        // cron, CLI, admin); a front-end request on a site with no language yet has no PLL(), so it
        // can't provision, and must not record the marker that would skip provisioning after it.
        $locales = $plugin->schema->locales();
        if ($locales !== null && !Polylang::installed($locales)) {
            return false;
        }

        if ($plugin->schema->commerce() !== null) {
            try {
                \TypedPress\Commerce\Module::checkOrderStorage();
            } catch (\RuntimeException) {
                return false;
            }
        }

        return KindUrls::recorded() && ContentPages::plan() === [] && RoutePages::plan() === []
            && !\TypedPress\Search\Index::shapeChanged() && \TypedPress\RuntimeSchema\Surfaces::granted();
    }
}
