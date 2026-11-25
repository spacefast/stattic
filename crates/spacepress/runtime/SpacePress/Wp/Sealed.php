<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Schema;

/**
 * Sealed mode (`defineConfig({ sealed: true })`, docs/sealed.md): the only code that runs as a plugin
 * is SpacePress's own plugins, the platform plugins SpacePress pins, the plugins the config declares (exact
 * versions, checksums in the committed lock) and plugin packages (compiled into the app plugin).
 *
 * What this class can do is limited by where it runs. WordPress loads drop-ins, then mu-plugins, then
 * network-active and active plugins, each before SpacePress's app plugin, and nothing an ordinary plugin
 * does stops another plugin WordPress has already loaded in the same request. So on a plain WordPress
 * host sealed mode is:
 *
 * - a refusal to activate anything not on the list: wp-admin (one, bulk, network), core's REST
 *   plugins route and WP-CLI, all of which go through `activate_plugin()` and its requirement check;
 * - a refusal, in wp-admin and REST, to deactivate the plugins the app runs on (SpacePress's own and the
 *   platform's): with the app stopped nothing would refuse anything;
 * - a report: the active plugins that aren't on the list, and every must-use plugin, drop-in and theme
 *   PHP file WordPress loads, in `GET /wp-json/spacepress/v1` under `runtime.wp.sealed` and `spacepress doctor`.
 *
 * Someone with a shell (WP-CLI with `--skip-plugins`, the database, the file system) is outside all of
 * this. Nothing is deactivated or deleted. A host that mounts SpacePress through the build store reads the same
 * list from the build manifest and can enforce it before any plugin loads.
 */
final class Sealed
{
    /**
     * Plugins a sealed site never runs, whatever the list says: each serves stored content around
     * SpacePress's read rules. The Block Data API answers a post's raw `post_content` after an item-level
     * check only, which shows a rich text field that a field rule hides; the decoupled bundle ships
     * that API and WPGraphQL. The compiler refuses a sealed config that declares one (the same list,
     * `SEALED_DENIED`); this is for a list written some other way, and for the report.
     */
    public const DENIED = ['vip-block-data-api', 'vip-decoupled-bundle'];

    /** @var ?array<string, true> plugin directory slugs that may run: the list the build wrote */
    private static ?array $allowed = null;
    /** @var array<string, true> the plugins the app can't run without: SpacePress's own and the platform's */
    private static array $required = [];

    public static function apply(Schema $schema): void
    {
        $listed = $schema->sealed();
        if ($listed === null) {
            return;
        }
        // The list is the build's (schema.php, the same one its manifests carry): nothing is added here.
        self::$allowed = self::listed($listed);
        // What the app can't run without: the runtime, the app itself, identity and the platform pins.
        // SpacePress's admin is SpacePress's own and always listed, but the site runs without it (classic
        // wp-admin stays), so it deactivates like any declared plugin.
        self::$required = array_fill_keys(array_filter($listed, static fn (string $slug): bool => in_array($slug, ['spacepress', 'spacepress-runtime', 'spacepress-identity', 'spacepress-app-' . $schema->name()], true)
            || isset(Platform::PLUGINS[$slug])), true);
        // Every activation path (wp-admin's single, bulk and network screens, core's REST plugins
        // route, WP-CLI) calls activate_plugin(), which asks this filter before it loads the plugin.
        add_filter('validate_plugin_requirements', [self::class, 'requirements'], PHP_INT_MAX, 2);
        // The screens and the REST route also ask the capability first: answer there too, so the
        // Activate link is gone and REST refuses with its own 403 before the plugin is read.
        add_filter('map_meta_cap', [self::class, 'capability'], PHP_INT_MAX, 4);
        add_filter('plugin_action_links', [self::class, 'actionLinks'], PHP_INT_MAX, 2);
        add_filter('network_admin_plugin_action_links', [self::class, 'actionLinks'], PHP_INT_MAX, 2);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('network_admin_notices', [self::class, 'notice']);
    }

    /**
     * The slugs that may run, from a list: never one of {@see DENIED}, whoever wrote the list.
     *
     * @param list<string> $listed
     * @return array<string, true>
     */
    public static function listed(array $listed): array
    {
        return array_diff_key(array_fill_keys($listed, true), array_fill_keys(self::DENIED, true));
    }

    /**
     * map_meta_cap. Activating a plugin that isn't listed is refused. So is deactivating one the app
     * can't run without (SpacePress's own and the platform's), from wp-admin and REST: with the app stopped
     * nothing would refuse anything, so that would be the way around the list. WP-CLI doesn't ask
     * capabilities; it stays the operator's way to take a site out of service.
     *
     * @param list<string> $caps
     */
    public static function capability(mixed $caps, mixed $cap, mixed $user = null, mixed $args = []): mixed
    {
        $plugin = is_array($args) && is_string($args[0] ?? null) ? $args[0] : null;
        if ($plugin === null) {
            return $caps;
        }
        $refused = match ($cap) {
            'activate_plugin' => !self::allows($plugin),
            'deactivate_plugin' => isset(self::$required[self::slug($plugin)]),
            default => false,
        };

        return $refused ? ['do_not_allow'] : $caps;
    }

    /** A plugin file is allowed when it is a listed plugin's main file: `<slug>/<file>.php`. */
    public static function allows(string $plugin): bool
    {
        return self::$allowed === null || isset(self::$allowed[self::slug($plugin)]);
    }

    /**
     * The listed-plugin slug a plugin file belongs to: the directory of `<slug>/<file>.php`. Anything
     * else has none: a single file in the plugins directory (`hello.php`, even one named after a listed
     * directory), a file deeper inside a plugin, a path with `..`.
     */
    private static function slug(string $plugin): string
    {
        $plugin = plugin_basename($plugin);

        return preg_match('#\A([^/]+)/[^/]+\.php\z#D', $plugin, $m) === 1 && $m[1] !== '..' && $m[1] !== '.' ? $m[1] : '';
    }

    public static function requirements(mixed $valid, mixed $plugin): mixed
    {
        // WP-CLI's `plugin activate` comes through here too. (`wp --skip-plugins` loads no plugin, SpacePress
        // included, so nothing is asked: that is the operator's shell, like editing the option.)
        if ($valid !== true || !is_string($plugin) || self::allows($plugin)) {
            return $valid;
        }

        return new \WP_Error('sp_sealed', self::message($plugin), ['status' => 403]);
    }

    public static function message(string $plugin): string
    {
        if (in_array(self::slug($plugin), self::DENIED, true)) {
            return "Refused: this site is sealed (sealed: true in spacepress.config.ts), and a sealed site never runs $plugin: it serves stored content around SpacePress's read rules. Turn sealed mode off to run it.";
        }

        return "Refused: this site is sealed (sealed: true in spacepress.config.ts), and $plugin isn't one of the plugins it runs. Declare it under `plugins` in spacepress.config.ts and deploy, or turn sealed mode off.";
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public static function actionLinks(mixed $links, mixed $plugin): mixed
    {
        if (!is_array($links) || !is_string($plugin)) {
            return $links;
        }
        if (!self::allows($plugin)) {
            unset($links['activate'], $links['network_activate']);
            $links['sp_sealed'] = '<span title="' . esc_attr(self::message($plugin)) . '">Sealed: not in spacepress.config.ts</span>';
        } elseif (isset(self::$required[self::slug($plugin)])) {
            unset($links['deactivate'], $links['network_deactivate']);
            $links['sp_sealed'] = '<span title="' . esc_attr('This sealed site runs on this plugin. Turn sealed mode off in spacepress.config.ts to deactivate it here.') . '">Sealed: required</span>';
        }

        return $links;
    }

    /**
     * wp-admin, for administrators: plugins that are active and not on the list. Those are what the
     * owner can act on from here; must-use plugins, drop-ins and theme PHP are in the report only.
     */
    public static function notice(): void
    {
        $report = self::report();
        $unlisted = $report === null ? [] : [...$report['plugins'], ...$report['networkPlugins']];
        if ($unlisted === [] || !current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>This site is sealed</strong> (sealed: true in spacepress.config.ts), and plugins it doesn\'t list are active: '
            . esc_html(implode(', ', $unlisted)) . '. SpacePress doesn\'t stop or remove them. Deactivate them, or declare them under <code>plugins</code> in spacepress.config.ts and deploy.</p></div>';
    }

    /**
     * What is loaded in this request, or null when the site isn't sealed. `plugins` and
     * `networkPlugins` are the active plugins that aren't on the list: a sealed site has none. The
     * rest is everything of its kind that WordPress loads, listed so the owner sees it; no config can
     * list a must-use plugin, a drop-in or a theme, and a host's own are among them.
     *
     * @return ?array{plugins: list<string>, networkPlugins: list<string>, muPlugins: list<string>, dropins: list<string>, theme: list<string>, enforced: string}
     */
    public static function report(): ?array
    {
        if (self::$allowed === null) {
            return null;
        }
        $unlisted = static fn (array $plugins): array => array_values(array_filter(array_map('plugin_basename', array_filter($plugins, 'is_string')), static fn (string $p): bool => !self::allows($p)));
        $network = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', [])) : [];

        return [
            'plugins' => $unlisted((array) get_option('active_plugins', [])),
            'networkPlugins' => $unlisted($network),
            'muPlugins' => array_values(array_map('basename', wp_get_mu_plugins())),
            'dropins' => self::dropins(),
            'theme' => self::theme(),
            // A host says so by defining the constant when it filters the plugin list ({@see \SpacePress\Loader\Loader::allowedPlugins}).
            'enforced' => defined('SP_SEALED_ENFORCED') && SP_SEALED_ENFORCED ? 'host' : 'report',
        ];
    }

    /** Drop-ins present in wp-content (core loads each in its own case: cache, database, errors). */
    private static function dropins(): array
    {
        if (!function_exists('_get_dropins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $found = [];
        foreach (array_keys(_get_dropins()) as $file) {
            if (is_file(WP_CONTENT_DIR . '/' . $file)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * The active theme's PHP: `functions.php` (WordPress includes it on every request) and, for a
     * block theme, its PHP pattern files (included to render each pattern), as `<theme>/functions.php`
     * and `<theme>/patterns/*.php (<count>)`. SpacePress's own block theme ships neither and isn't listed.
     *
     * @return list<string>
     */
    private static function theme(): array
    {
        $found = [];
        foreach (array_unique([get_stylesheet_directory(), get_template_directory()]) as $dir) {
            if (str_starts_with(wp_normalize_path($dir), wp_normalize_path(dirname(__DIR__) . '/theme/'))) {
                continue;
            }
            if (is_file($dir . '/functions.php')) {
                $found[] = basename($dir) . '/functions.php';
            }
            $patterns = count(glob($dir . '/patterns/*.php') ?: []);
            if ($patterns > 0) {
                $found[] = basename($dir) . "/patterns/*.php ($patterns)";
            }
        }

        return $found;
    }
}
