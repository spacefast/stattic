<?php
declare(strict_types=1);

namespace SpacePressPlugin;

/** What this site is to SpacePress: where its builds are kept, which one is live, and who may deploy. */
final class Site
{
    /** The deploy protocol `spacepress push` speaks; a CLI that doesn't know it asks for a newer plugin. */
    public const PROTOCOL = 1;
    public const REST = 'spacepress-deploy/v1';
    /** Option: the build ID every request mounts. */
    public const LIVE = 'sp_live_build';
    /** Option: every deployment and rollback, oldest first. Never pruned. */
    public const HISTORY = 'sp_deployments';
    /** Option: deployments that are uploading or waiting to be made live, by ID. */
    public const PENDING = 'sp_pending_deployments';

    /** Option: the random part of the store directory's name. */
    public const KEY = 'sp_store_key';
    /** The capability the receiver and the SpacePress screen ask for; see {@see deployer()}. */
    public const CAPABILITY = 'sp_deploy';
    /** The `app_id` `spacepress login` names on WordPress's Authorize Application screen (src/commands/login.rs). */
    public const CLI = '0d1f6c5e-3b55-4f0e-9c2a-6a1e5f1b7a10';

    public static function register(): void
    {
        add_filter('map_meta_cap', static fn (array $caps, string $cap, int $user): array => $cap === self::CAPABILITY
            ? [self::deployer($user) ? 'exist' : 'do_not_allow']
            : $caps, 10, 3);
    }

    /**
     * The build store: `SP_STORE_DIR`, else `wp-content/spacepress-store-<key>`. It holds PHP, schema
     * snapshots, migrations and seed content, and many servers ignore `.htaccess`, so its name carries
     * a random key and no URL ever names it. What a browser needs is copied to {@see assets()}.
     * Null while the site has no key: nothing was ever uploaded to it. Only {@see store()} makes one.
     */
    public static function root(): ?string
    {
        if (defined('SP_STORE_DIR') && is_string(SP_STORE_DIR) && SP_STORE_DIR !== '') {
            // One constant serves a whole network: each site keeps its builds, and the record of which
            // of them it has initialized, in a directory of its own.
            return rtrim(SP_STORE_DIR, '/') . (is_multisite() ? '/site-' . get_current_blog_id() : '');
        }
        $key = get_option(self::KEY);

        return is_string($key) && preg_match('/\A[0-9a-f]{32}\z/D', $key) === 1 ? WP_CONTENT_DIR . '/spacepress-store-' . $key : null;
    }

    /** {@see root()}, with its key made first when the site has none: for a request that writes to the store. */
    public static function store(): string
    {
        $root = self::root();
        if ($root === null) {
            // add_option keeps the first writer's key when two requests create it at once.
            add_option(self::KEY, bin2hex(random_bytes(16)), '', true);
            wp_cache_delete(self::KEY, 'options');
            wp_cache_delete('alloptions', 'options');
            $root = self::root() ?? throw new \RuntimeException('Cannot create the SpacePress build store: the ' . self::KEY . ' option holds something else');
        }

        return $root;
    }

    /**
     * What to tell the site's owner about where the store is, or null. The store holds PHP, schema
     * snapshots, migrations and seed content. Under the web root, what keeps it from being served is
     * its `.htaccess`, which Apache and LiteSpeed read and nginx does not; a random directory name
     * no URL carries is the only thing left on the others. A site that can keeps it outside the web
     * root with `SP_STORE_DIR`.
     */
    public static function exposure(): ?string
    {
        // Before the first upload the store has no name yet; it will be made in wp-content.
        $root = self::root() ?? WP_CONTENT_DIR . '/spacepress-store-<random>';
        $store = self::real($root);
        $web = self::real(ABSPATH);
        // Core's own answer to "where does the document root end": wp-content can be moved out of it.
        $content = self::real(WP_CONTENT_DIR);
        $inside = static fn (string $base): bool => $base !== '' && ($store === $base || str_starts_with($store, $base . '/'));
        if (!$inside($web) && !$inside($content)) {
            return null;
        }
        $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (preg_match('/apache|litespeed/i', $server) === 1) {
            return null;
        }

        return 'SpacePress keeps this site\'s builds (PHP, database migrations, seed content) in ' . $root
            . ', which is inside the folder the web server serves. '
            . ($server === '' ? 'This server' : 'This server (' . preg_replace('/[^A-Za-z0-9 ._\/-]/', '', substr($server, 0, 40)) . ')')
            . ' may not read the .htaccess file that blocks it, so only the folder\'s random name keeps those files private.'
            . ' Set SP_STORE_DIR in wp-config.php to a folder outside the web root, or have the server deny that folder.';
    }

    /** A path with its links resolved when it exists, as written otherwise, without a trailing slash. */
    private static function real(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
    }

    /**
     * The public copy of each stored tree's scripts, styles, images and fonts, at the same paths. A
     * tree is named by the hash of its contents, so the sites of a network share this directory: two
     * sites that hold the same tree hold the same files. The store itself is per site (its key is a
     * site option), as is which build is live.
     */
    public static function assets(): string
    {
        if (defined('SP_ASSETS_DIR') && is_string(SP_ASSETS_DIR) && SP_ASSETS_DIR !== '') {
            return rtrim(SP_ASSETS_DIR, '/');
        }

        return WP_CONTENT_DIR . '/spacepress-assets';
    }

    /** The URL of {@see assets()}: where a build's plugins' asset URLs point. */
    public static function url(): string
    {
        if (defined('SP_ASSETS_URL') && is_string(SP_ASSETS_URL) && SP_ASSETS_URL !== '') {
            return rtrim(SP_ASSETS_URL, '/');
        }

        return content_url('spacepress-assets');
    }

    /**
     * This request's route below the deploy namespace (`/deployments/<id>/plan`), or null when it is not
     * a request WordPress serves from the REST API: the path under the site's REST prefix, or
     * `?rest_route=` on the front controller. A deploy route named in the query string of any other
     * script (admin-ajax.php, SpacePress's own API) is not one.
     */
    public static function route(): ?string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        // WordPress reads the path as sent. One that starts `//` would be read here as a host and a
        // path below it, and WordPress serves it as a page.
        if (str_starts_with($uri, '//')) {
            return null;
        }
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '/');
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }
        $prefix = '/' . rest_get_url_prefix();
        // WordPress takes `rest_route` from the body, then the query, then the path (WP::parse_request).
        // A request that names a route in more than one of them is not taken as a deploy request.
        if (isset($_POST['rest_route'])) {
            return null;
        }
        if (str_starts_with($path, $prefix . '/')) {
            if (isset($_GET['rest_route'])) {
                return null;
            }
            $route = substr($path, strlen($prefix));
        } elseif (($path === '/' || $path === '/index.php') && is_string($_GET['rest_route'] ?? null)) {
            $route = $_GET['rest_route'];
        } else {
            return null;
        }
        $namespace = '/' . self::REST;
        // Core's REST server drops a trailing slash before it matches a route.
        $route = rtrim($route, '/');

        return $route === $namespace || str_starts_with($route, $namespace . '/') ? substr($route, strlen($namespace)) : null;
    }

    /**
     * Whether this request asks WordPress's REST server to treat it as another method than its own
     * (`_method`, `X-HTTP-Method-Override`).
     */
    public static function overridden(): bool
    {
        return isset($_GET['_method']) || isset($_POST['_method']) || isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9a-f]{64}\z/D', $id) === 1;
    }

    public static function live(): ?string
    {
        $build = get_option(self::LIVE);

        return self::validId($build) ? $build : null;
    }

    /**
     * A stored build's manifest, or null when the store has none under that ID. The ID is the hash of
     * the manifest's bytes, so one that was changed on disk is refused.
     */
    public static function manifest(string $build): ?array
    {
        if (!self::validId($build)) {
            return null;
        }
        $root = self::root();
        $bytes = $root === null ? false : @file_get_contents($root . '/build/' . $build . '.json');
        if (!is_string($bytes)) {
            return null;
        }
        if (!hash_equals($build, hash('sha256', $bytes))) {
            throw new \RuntimeException("SpacePress build $build does not match its ID");
        }

        return self::parseManifest($bytes, stored: true);
    }

    /**
     * The fields the plugin reads from a build manifest, checked; anything else in it is the loader's.
     * A build a pre-release receiver stored (its format is in {@see Legacy::BUILD_FORMAT}) is read too,
     * as the record it is: its baseline, name and history stay readable, and it is never mounted.
     */
    public static function parseManifest(string $bytes, bool $stored = false): array
    {
        $manifest = json_decode($bytes, true, 16);
        $format = $manifest['format'] ?? null;
        if (!is_array($manifest) || !($format === 'spacepress.build.v1' || ($stored && $format === Legacy::BUILD_FORMAT))
            || !is_string($manifest['name'] ?? null) || preg_match('/\A[a-z0-9-]+\z/D', $manifest['name']) !== 1
            || !self::validId($manifest['runtime'] ?? null) || !self::validId($manifest['app'] ?? null)) {
            throw new \RuntimeException('This is not a SpacePress build manifest');
        }

        return $manifest;
    }

    /** Whether the build's runtime and app trees are in the store, in the layout this plugin mounts. */
    public static function complete(array $manifest): bool
    {
        $root = self::root();
        if (Legacy::build($manifest)) {
            return false;
        }

        return $root !== null && is_file($root . '/runtime/' . $manifest['runtime'] . '/spacepress-loader.php')
            && is_dir($root . '/app/' . $manifest['app'] . '/plugins/spacepress-app-' . $manifest['name']);
    }

    /** This receiver has no checkout suspension or shipping-compatible selection/recovery yet. */
    public static function checkCommerce(array $manifest): void
    {
        $path = self::root() . '/app/' . $manifest['app'] . '/manifest.json';
        $hosted = json_decode((string) @file_get_contents($path), true);
        if (!is_array($hosted) || ($hosted['format'] ?? null) !== 'spacepress.hosted.v1') {
            throw new \RuntimeException('The build is missing its hosted manifest. Upload it again.');
        }
        if (array_key_exists('woocommerce', $hosted['requires'] ?? [])) {
            throw new \RuntimeException('The SpacePress deployment receiver does not yet support commerce builds: managed shipping selection and recovery are not qualified. Use a qualified commerce host or a standalone local/plugin installation.');
        }
    }

    /** Whether the build has been initialized on this site, so it can be made live without installing. */
    public static function ready(string $build): bool
    {
        $root = self::root();

        return $root !== null && self::validId($build) && is_file($root . '/ready/' . $build . '.json');
    }

    /** @return list<array{build: string, name: string, compiler: string, at: string, user: int, kind: string}> */
    public static function history(): array
    {
        $history = get_option(self::HISTORY, []);

        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    /** @return array<string, array{build: string, token: string, expires: int, user: int}> open deployments by ID */
    public static function pending(): array
    {
        $pending = get_option(self::PENDING, []);

        return is_array($pending) ? array_filter($pending, 'is_array') : [];
    }

    /**
     * The build the next one is compiled against: the one deployed last, whether or not its files are
     * still in the store, because the database is at its schema. Making an earlier build live again
     * switches code only.
     */
    public static function baseline(): ?string
    {
        foreach (array_reverse(self::history()) as $entry) {
            if (($entry['kind'] ?? null) === 'deploy' && self::validId($entry['build'] ?? null)) {
                return $entry['build'];
            }
        }

        return self::live();
    }

    /**
     * Run $fn as the only writer of this site's deployments: creating one, making a build live and
     * making an earlier one live again each read what is stored and write it back. The lock is the
     * store's own (`<store>/.lock`, the one the compiler and the loader take). What another request
     * wrote while this one waited is read from the database again.
     */
    public static function locked(callable $fn): mixed
    {
        // A site with no store has no deployment to lock: {@see Receiver::create()} makes it first.
        $root = self::root() ?? throw new \RuntimeException('This site has no SpacePress build store yet');
        Trees::prepare($root);
        $handle = fopen($root . '/.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the SpacePress build store');
        }
        try {
            foreach ([self::LIVE, self::HISTORY, self::PENDING, 'alloptions', 'notoptions'] as $cached) {
                wp_cache_delete($cached, 'options');
            }

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Make a build the live one and record who did it. Call it inside {@see locked()}. The history is
     * written first: a request that dies between the two writes leaves the old code live with the
     * right baseline, never new code beside a baseline the database has moved past.
     */
    public static function makeLive(string $build, array $manifest, string $kind): void
    {
        $history = self::history();
        $history[] = [
            'build' => $build,
            'name' => (string) $manifest['name'],
            'compiler' => (string) ($manifest['compiler'] ?? ''),
            'at' => gmdate('c'),
            'user' => get_current_user_id(),
            'kind' => $kind,
        ];
        update_option(self::HISTORY, $history, false);
        update_option(self::LIVE, $build, true);
        // Every page may render differently now: page caches and the edge purge the whole host, once,
        // when this request ends (nothing is announced where no page cache listens).
        if (method_exists(\SpacePress\Wp\Purges::class, 'everything')) {
            \SpacePress\Wp\Purges::everything();
        }
    }

    /**
     * Deploying installs PHP, so it is for the people whose role lets them install plugins:
     * administrators, and on a network only its super admins. Being allowed to activate plugins that
     * are already installed is not enough.
     */
    public static function mayDeploy(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    /**
     * Whether SpacePress Identity holds the user's account as anything but active (suspended, or
     * being deleted). Identity refuses such an account on every sign-in, but only once the build
     * that ships it is mounted; {@see Mount} decides which build to mount before that, so it reads
     * the account here, from the table identity keeps it in. A site without identity has none.
     */
    public static function suspended(int $user): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sp_identity_accounts';
        if ($user <= 0) {
            return false;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            // Before the site has moved identity's tables, the account is in the earlier one.
            $table = Legacy::accounts();
            if ($table === null) {
                return false;
            }
        }
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM `$table` WHERE wp_user_id = %d", $user));

        return $status !== null && $status !== 'active';
    }

    /**
     * Whether the user is granted `install_plugins`: by their role, as changed by any `user_has_cap`
     * filter (a plugin that takes the capability away, or grants it, is honored). It does not go
     * through `map_meta_cap`: a live SpacePress app refuses `install_plugins` to everyone there (its
     * hardening), and deploying is how such a site still gets new code.
     */
    public static function deployer(int $user): bool
    {
        $account = get_userdata($user);
        if (!$account instanceof \WP_User) {
            return false;
        }
        if (is_multisite()) {
            return is_super_admin($user);
        }
        // What WP_User::has_cap does after the meta mapping, for this one primitive capability.
        $granted = apply_filters('user_has_cap', $account->allcaps, ['install_plugins'], ['install_plugins', $user], $account);

        return is_array($granted) && !empty($granted['install_plugins']);
    }

    private static ?bool $fileMods = null;

    /**
     * Whether the site itself lets code be installed: WordPress's file-modification policy, which is
     * the `DISALLOW_FILE_MODS` constant and any `file_mod_allowed` filter, asked the way core asks for
     * `install_plugins`. {@see Mount} reads it before a build mounts and it is kept for the request,
     * because a live SpacePress app adds a filter of its own that refuses file changes in wp-admin (its
     * hardening), and deploying is how such a site gets new code. A filter another plugin adds after
     * plugins have loaded is not seen.
     */
    public static function fileMods(): bool
    {
        return self::$fileMods ??= wp_is_file_mod_allowed('capability_update_core');
    }

    /** Why this site takes no deployments at all, or null. A host that manages its own code says so. */
    public static function closed(): ?string
    {
        if (!self::fileMods()) {
            return 'This site does not allow file changes (DISALLOW_FILE_MODS, or a file_mod_allowed filter), so it cannot take a deployment. Deploy the build with the site\'s own code instead.';
        }
        $reason = apply_filters('sp_deploy_closed', null);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * SpacePress installed here as separate plugins (what `spacepress build` writes) runs its own app. The two
     * can't both run: one SpacePress app per site.
     */
    public static function conflict(): ?string
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (array_keys(get_plugins()) as $file) {
            if ((str_starts_with($file, 'spacepress-runtime/') || str_starts_with($file, 'spacepress-app-')) && is_plugin_active($file)) {
                return 'This site already runs SpacePress from separately installed plugins (' . dirname($file) . '). Deactivate the SpacePress Runtime and SpacePress app plugins before deploying here.';
            }
        }

        return null;
    }

    /**
     * Before the first deployment: what the site already holds besides what WordPress's installer
     * wrote (the "Hello world!" post, the sample page, the privacy policy draft), or null. A deployed
     * app keeps it, and changes how WordPress serves it, so the owner is told before installing one.
     * Installer content counts too once someone edited it: it was saved after it was made, or has
     * a revision.
     */
    public static function existing(): ?string
    {
        if (self::live() !== null) {
            return null;
        }
        global $wpdb;
        $privacy = (int) get_option('wp_page_for_privacy_policy');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.post_type, COUNT(*) AS n FROM {$wpdb->posts} p WHERE p.post_type IN ('post', 'page')"
            . " AND p.post_status IN ('publish', 'future', 'draft', 'pending', 'private')"
            . " AND NOT ((p.post_name IN ('hello-world', 'sample-page') OR p.ID = %d) AND p.post_modified_gmt = p.post_date_gmt"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} r WHERE r.post_parent = p.ID AND r.post_type = 'revision'))"
            . " GROUP BY p.post_type ORDER BY p.post_type DESC",
            $privacy,
        ), ARRAY_A);
        $found = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $n = (int) $row['n'];
            $found[] = $n . ' ' . ($row['post_type'] === 'post' ? ($n === 1 ? 'post' : 'posts') : ($n === 1 ? 'page' : 'pages'));
        }
        if ($found === []) {
            return null;
        }

        return 'This site already has content (' . implode(', ', $found) . '). Deploying an app keeps it, and changes how WordPress serves it:'
            . ' comments close, author archives go away, permalinks become /%postname%/, and an app with a home page takes over the front page.'
            . ' Try the app on a copy of this site first.';
    }

    /** Core accepts application passwords over HTTPS, or on a site whose environment type is `local`. */
    public static function passwords(): bool
    {
        return function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available();
    }
}
