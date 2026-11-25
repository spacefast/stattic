<?php
declare(strict_types=1);

namespace SpacePressPlugin;

/**
 * What SpacePress needs from this site, and installing the plugins among it. The setup screen uses the
 * versions and checksums of the compiler that wrote this plugin (`requirements.json`); `spacepress push`
 * sends the ones of the compiler that made the build it deploys.
 */
final class Requirements
{
    private const LABELS = [
        'secure-custom-fields' => 'Secure Custom Fields',
        'action-scheduler' => 'Action Scheduler',
        'mcp-adapter' => 'MCP Adapter',
        'two-factor' => 'Two Factor',
        'polylang' => 'Polylang',
    ];
    /** Needed only by an app that declares `locales`. */
    private const OPTIONAL = ['polylang'];

    private static function pins(): array
    {
        static $pins = null;
        $pins ??= json_decode((string) file_get_contents(dirname(__DIR__) . '/requirements.json'), true, 8, JSON_THROW_ON_ERROR);

        return $pins;
    }

    /** @return array<string, array{version: string, sha256: string}> the plugins this plugin's compiler pins */
    public static function pinned(): array
    {
        return self::pins()['plugins'];
    }

    /**
     * Every installed plugin by directory, with its version and whether it is active. `spacepress push`
     * compares this with what the build it deploys needs.
     *
     * @return array<string, array{version: string, active: bool}>
     */
    public static function installed(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = [];
        foreach (get_plugins() as $file => $data) {
            if (str_contains($file, '/')) {
                $installed[dirname($file)] = ['version' => (string) $data['Version'], 'active' => is_plugin_active($file)];
            }
        }

        return $installed;
    }

    /**
     * Every requirement of this plugin's compiler with what the site has. `ok` for a plugin means
     * installed at the pinned version or newer and active.
     *
     * @return list<array{id: string, label: string, required: string, found: ?string, ok: bool, optional: bool, plugin: bool}>
     */
    public static function checks(): array
    {
        $pins = self::pins();
        $wordpress = (string) get_bloginfo('version');
        $checks = [
            ['id' => 'wordpress', 'label' => 'WordPress', 'required' => $pins['wordpress'], 'found' => $wordpress, 'ok' => version_compare($wordpress, $pins['wordpress'], '>='), 'optional' => false, 'plugin' => false],
            ['id' => 'php', 'label' => 'PHP', 'required' => $pins['php'], 'found' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, $pins['php'], '>='), 'optional' => false, 'plugin' => false],
            ['id' => 'zip', 'label' => 'PHP zip extension', 'required' => 'enabled', 'found' => class_exists(\ZipArchive::class) ? 'enabled' : null, 'ok' => class_exists(\ZipArchive::class), 'optional' => false, 'plugin' => false],
        ];
        $installed = self::installed();
        foreach ($pins['plugins'] as $slug => $pin) {
            $found = $installed[$slug] ?? null;
            $checks[] = [
                'id' => $slug,
                'label' => self::label($slug),
                'required' => $pin['version'],
                'found' => $found === null ? null : $found['version'] . ($found['active'] ? '' : ' (inactive)'),
                'ok' => $found !== null && $found['active'] && version_compare($found['version'], $pin['version'], '>='),
                'optional' => in_array($slug, self::OPTIONAL, true),
                'plugin' => true,
            ];
        }

        return $checks;
    }

    public static function label(string $slug): string
    {
        return self::LABELS[$slug] ?? $slug;
    }

    /** True when the server and the plugins every app needs are there. */
    public static function ready(): bool
    {
        return array_filter(self::checks(), static fn (array $check): bool => !$check['ok'] && !$check['optional']) === [];
    }

    /** What the server itself lacks (WordPress, PHP, zip): nothing a deployment can install. */
    public static function server(): array
    {
        return array_values(array_filter(self::checks(), static fn (array $check): bool => !$check['plugin'] && !$check['ok']));
    }

    /** The installed plugin's main file (`slug/file.php`) for a directory slug, or null. */
    private static function file(string $slug): ?string
    {
        wp_cache_delete('plugins', 'plugins');
        foreach (array_keys(get_plugins()) as $file) {
            if (str_starts_with($file, $slug . '/')) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Install and activate each of `$pins` the site lacks: the ZIP comes from wordpress.org at the
     * pinned version and is installed only when its SHA-256 is the pinned one. A plugin already there at
     * that version or newer is only activated. Returns what it touched; throws on the first failure.
     *
     * `$network` activates them for every site of a network; left out, they follow SpacePress itself.
     *
     * @param array<string, array{version: string, sha256: string}> $pins
     * @return list<string>
     */
    public static function install(array $pins, ?bool $network = null): array
    {
        // On a network where SpacePress is active for every site, so is what it depends on.
        $network ??= is_multisite() && is_plugin_active_for_network(plugin_basename(SP_PLUGIN_FILE));
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $done = [];
        foreach ($pins as $slug => $pin) {
            if (!is_string($slug) || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $slug) !== 1 || !is_array($pin)
                || !is_string($pin['version'] ?? null) || preg_match('/\A[0-9A-Za-z][0-9A-Za-z.+-]*\z/D', $pin['version']) !== 1 || !Site::validId($pin['sha256'] ?? null)) {
                throw new \RuntimeException('A plugin to install needs a slug, a version and a SHA-256');
            }
            // Only the plugins SpacePress itself depends on, whatever version a build asks for. `spacepress push`
            // brings this plugin up to its own version first, so the two lists are the same.
            if (!isset(self::pins()['plugins'][$slug])) {
                throw new \RuntimeException("$slug is not a plugin SpacePress installs");
            }
            $label = self::label($slug);
            $found = self::installed()[$slug] ?? null;
            $everywhere = !$network || ($found !== null && is_plugin_active_for_network((string) self::file($slug)));
            if ($found !== null && $found['active'] && $everywhere && version_compare($found['version'], $pin['version'], '>=')) {
                continue;
            }
            if ($found === null || version_compare($found['version'], $pin['version'], '<')) {
                $package = download_url("https://downloads.wordpress.org/plugin/$slug.{$pin['version']}.zip", 300);
                if (is_wp_error($package)) {
                    throw new \RuntimeException("Could not download $label {$pin['version']}: " . $package->get_error_message());
                }
                try {
                    if (!hash_equals($pin['sha256'], (string) hash_file('sha256', $package))) {
                        throw new \RuntimeException("$label {$pin['version']} from wordpress.org is not the file SpacePress pins; nothing was installed");
                    }
                    $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
                    $result = $upgrader->install($package, ['overwrite_package' => true]);
                    if ($result !== true) {
                        $why = is_wp_error($result) ? $result->get_error_message() : implode(' ', $upgrader->skin->get_upgrade_messages());
                        throw new \RuntimeException("Could not install $label: $why");
                    }
                } finally {
                    @unlink($package);
                }
                wp_clean_plugins_cache();
            }
            $file = self::file($slug);
            $activated = $file === null ? new \WP_Error('missing', 'its files are not there') : activate_plugin($file, '', $network);
            if (is_wp_error($activated)) {
                throw new \RuntimeException("Could not activate $label: " . $activated->get_error_message());
            }
            // Polylang sends the next wp-admin page anyone opens to its setup wizard. SpacePress installed
            // it for the app, which configures its languages; the owner's next click stays theirs.
            delete_transient('pll_activation_redirect');
            $done[] = "$label {$pin['version']}";
        }

        return $done;
    }
}
