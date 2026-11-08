<?php
/**
 * Zero build loader. One standalone file, shipped as <store>/runtime/<revision>/zero-loader.php.
 *
 * The host requires it and calls Zero\Loader\Loader::load() before WordPress initializes, with the
 * build ID it selected for this request. It never compiles: builds come from compile_to_store and
 * are immutable once written. Only the host collects builds (Store::gc).
 *
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Zero\Loader;

defined('ABSPATH') || exit;

if (!class_exists(Store::class, false)) {

/** Reads and collects `<root>/{runtime,app,build,ready}`; the same layout and lock as the compiler. */
final class Store
{
    public const FORMAT = 'zero.build.v1';

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9a-f]{64}\z/D', $id) === 1;
    }

    /** The build's manifest, refused unless its bytes still hash to its ID. */
    public static function manifest(string $root, string $build): array
    {
        if (!self::validId($build)) {
            throw new \InvalidArgumentException('Invalid Zero build ID');
        }
        $bytes = @file_get_contents($root . '/build/' . $build . '.json');
        if (!is_string($bytes)) {
            throw new \RuntimeException("Unknown Zero build $build");
        }
        if (!hash_equals($build, hash('sha256', $bytes))) {
            throw new \RuntimeException("Zero build $build does not match its ID");
        }
        $manifest = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT
            || !is_string($manifest['name'] ?? null) || preg_match('/\A[a-z0-9-]+\z/D', $manifest['name']) !== 1
            || !self::validId($manifest['runtime'] ?? null) || !self::validId($manifest['app'] ?? null)
            || !is_bool($manifest['identity'] ?? null) || !is_bool($manifest['admin'] ?? null)
            || !(($manifest['pages'] ?? null) === null || $manifest['pages'] === 'zero-pages-' . $manifest['name'])) {
            throw new \RuntimeException("Invalid Zero build manifest $build");
        }
        return $manifest;
    }

    /** The initialization receipt, or null while the build has not initialized on this site. */
    public static function receipt(string $root, string $build): ?array
    {
        if (!self::validId($build) || !is_file($root . '/ready/' . $build . '.json')) {
            return null;
        }
        $receipt = json_decode((string) file_get_contents($root . '/ready/' . $build . '.json'), true, 64, JSON_THROW_ON_ERROR);
        return is_array($receipt) ? $receipt : null;
    }

    /** Write a small file by rename so readers see the old bytes or the new ones. */
    public static function writeJson(string $root, string $relative, array $value): void
    {
        self::directories($root);
        $staged = self::staging($root, 'json');
        if (file_put_contents($staged, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false
            || !rename($staged, $root . '/' . $relative)) {
            @unlink($staged);
            throw new \RuntimeException("Cannot write $relative in the Zero build store");
        }
    }

    /**
     * Keep exactly the given builds; delete every other build manifest and ready marker, and every
     * runtime and app directory no kept build references. The store never chooses what to keep: the
     * host passes the builds of every version it still serves, including candidates it is finalizing.
     *
     * @param list<string> $keep
     * @return list<string> removed paths
     */
    public static function gc(string $root, array $keep, bool $dry = false): array
    {
        return self::locked($root, static function () use ($root, $keep, $dry): array {
            $kept = [];
            foreach ($keep as $id) {
                $kept[(string) $id] = true;
            }
            $live = ['build' => $kept, 'ready' => $kept, 'runtime' => [], 'app' => []];
            foreach (array_keys($kept) as $id) {
                // An unknown kept ID is a caller mistake; collecting around it could delete a live build.
                $manifest = self::manifest($root, (string) $id);
                $live['runtime'][$manifest['runtime']] = true;
                $live['app'][$manifest['app']] = true;
            }
            $doomed = [];
            foreach ($live as $dir => $names) {
                $entries = @scandir($root . '/' . $dir) ?: [];
                sort($entries, SORT_STRING);
                foreach ($entries as $name) {
                    $id = str_ends_with($name, '.json') ? substr($name, 0, -5) : $name;
                    if (self::validId($id) && !isset($names[$id])) {
                        $doomed[] = $root . '/' . $dir . '/' . $name;
                    }
                }
            }
            if (!$dry) {
                foreach ($doomed as $path) {
                    // Out of the store first: a reader sees the whole tree or none of it.
                    $trash = self::staging($root, 'gc');
                    if (!rename($path, $trash)) {
                        throw new \RuntimeException("Cannot collect $path");
                    }
                    self::remove($trash);
                }
                // Staging left by an interrupted writer; every live writer holds this lock.
                foreach (@scandir($root . '/.tmp') ?: [] as $name) {
                    if ($name !== '.' && $name !== '..') {
                        self::remove($root . '/.tmp/' . $name);
                    }
                }
            }
            return $doomed;
        });
    }

    /** Run $fn holding flock(<root>/.lock), the compiler's writer lock. */
    public static function locked(string $root, callable $fn): mixed
    {
        self::directories($root);
        $handle = fopen($root . '/.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the Zero build store');
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function directories(string $root): void
    {
        foreach (['runtime', 'app', 'build', 'ready', '.tmp'] as $dir) {
            if (!is_dir($root . '/' . $dir) && !@mkdir($root . '/' . $dir, 0755, true) && !is_dir($root . '/' . $dir)) {
                throw new \RuntimeException("Cannot create the Zero build store at $root");
            }
        }
    }

    private static function staging(string $root, string $purpose): string
    {
        return $root . '/.tmp/' . $purpose . '-' . getmypid() . '-' . bin2hex(random_bytes(8));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}

/** Mounts exactly one build per request, then initializes it once per site. */
final class Loader
{
    private static ?array $loaded = null;
    private static ?\Zero\Schema $schema = null;

    /**
     * Select and prepare one build before plugins_loaded; it mounts at plugins_loaded priority 1.
     *
     * Options: `variables` (array<string,string>: the app's only runtime variables; missing means none),
     * `users` (load zero-identity
     * even without an auth collection), `beforeIdentity` (callable run just before zero-identity loads),
     * `url` (the public base URL of `$root`, for plugin asset URLs).
     */
    public static function load(string $root, string $build, array $options = []): void
    {
        $root = rtrim($root, '/');
        if (self::$loaded !== null) {
            if (self::$loaded['root'] === $root && self::$loaded['build'] === $build) {
                return;
            }
            throw new \RuntimeException('Only one Zero build may load in a request');
        }
        if (class_exists(\Zero\Wp\Runtime::class, false)) {
            throw new \RuntimeException('A site-wide Zero runtime is already loaded; only one Zero app can be active');
        }
        if (did_action('init') > 0) {
            throw new \RuntimeException('Load a Zero build before WordPress initializes');
        }
        $variables = $options['variables'] ?? [];
        if (!is_array($variables)) {
            throw new \InvalidArgumentException('Runtime variables must map names to strings');
        }
        $before = $options['beforeIdentity'] ?? null;
        if ($before !== null && !is_callable($before)) {
            throw new \InvalidArgumentException('beforeIdentity must be callable');
        }
        $manifest = Store::manifest($root, $build);
        $runtime = $root . '/runtime/' . $manifest['runtime'];
        $app = $root . '/app/' . $manifest['app'] . '/plugins/zero-app-' . $manifest['name'];
        if (!is_file($runtime . '/zero-runtime/zero/bootstrap.php') || !is_file($app . '/zero/app/schema.php')) {
            throw new \RuntimeException("Zero build $build is incomplete");
        }
        self::$loaded = ['root' => $root, 'build' => $build, 'manifest' => $manifest];
        // Restrict lookup before feature registration, schema loading, or app boot.
        require_once $runtime . '/zero-runtime/zero/Env.php';
        \Zero\Env::isolate($variables);
        require_once $runtime . '/zero-runtime/zero/bootstrap.php';
        self::$schema = \Zero\Schema::load($app . '/zero/app/schema.php');
        $identity = !empty($options['users']) || (self::$schema->auth()['collection'] ?? null) !== null;
        self::$loaded['identity'] = $identity;
        $url = $options['url'] ?? null;
        if (is_string($url) && $url !== '') {
            self::mapUrls($root, rtrim($url, '/'));
        }
        $mount = static function () use ($root, $runtime, $app, $manifest, $variables, $identity, $before): void {
            if ($identity) {
                if ($before !== null) {
                    $before();
                }
                require_once $runtime . '/zero-identity/zero-identity.php';
            }
            \Zero\Wp\Runtime::mount($runtime . '/zero-runtime/zero-runtime.php', $app, $variables);
            if ($manifest['admin']) {
                require_once $runtime . '/zero-admin/zero-admin.php';
            }
            if ($manifest['pages'] !== null) {
                require_once $root . '/app/' . $manifest['app'] . '/plugins/' . $manifest['pages'] . '/' . $manifest['pages'] . '.php';
            }
        };
        if (doing_action('plugins_loaded') || did_action('plugins_loaded') > 0) {
            $mount();
        } else {
            add_action('plugins_loaded', $mount, 1);
        }
    }

    /** The loaded build's schema; available as soon as load() returns. */
    public static function schema(): \Zero\Schema
    {
        if (self::$schema === null) {
            throw new \LogicException('Load a Zero build first');
        }
        return self::$schema;
    }

    /** `{root, build, manifest, identity}` of this request's build, or null. */
    public static function loaded(): ?array
    {
        return self::$loaded;
    }

    /**
     * Prepare this site for the loaded build: system tables and the app's pending migrations (forward
     * only), identity maintenance, the content seed, then the ready marker. Replaces activation hooks.
     * A build that is already ready returns its stored receipt and changes nothing.
     *
     * @return array{migrations: list<string>, seed: ?array}
     */
    public static function initialize(string $root, string $build): array
    {
        $root = rtrim($root, '/');
        if (self::$loaded === null || self::$loaded['root'] !== $root || self::$loaded['build'] !== $build) {
            throw new \LogicException('Load this Zero build before initializing it');
        }
        $receipt = Store::receipt($root, $build);
        if ($receipt !== null) {
            return ['migrations' => $receipt['migrations'] ?? [], 'seed' => $receipt['seed'] ?? null];
        }
        if (!class_exists(\Zero\Wp\Runtime::class, false) || !\Zero\Wp\Runtime::managed() || \Zero\Wp\Runtime::plugin() === null) {
            throw new \RuntimeException('The Zero build did not mount; WordPress must reach plugins_loaded first');
        }
        $migrations = \Zero\Wp\Runtime::initialize();
        if (self::$loaded['identity'] && !wp_next_scheduled('zero_identity_maintenance')) {
            $scheduled = wp_schedule_event(time() + 60, 'hourly', 'zero_identity_maintenance', [], true);
            if ($scheduled !== true) {
                throw new \RuntimeException('Cannot schedule Zero identity maintenance');
            }
        }
        $seed = $root . '/app/' . self::$loaded['manifest']['app'] . '/seed';
        $counts = is_dir($seed) ? \Zero\Sync\Seed::run(\Zero\Wp\Runtime::plugin(), $seed) : null;
        $result = ['migrations' => array_values($migrations), 'seed' => $counts];
        Store::writeJson($root, 'ready/' . $build . '.json', ['build' => $build, 'initializedAt' => gmdate('c')] + $result);
        return $result;
    }

    /** plugins_url() for files under the store, which lives outside WP_PLUGIN_DIR. */
    private static function mapUrls(string $root, string $url): void
    {
        $prefix = wp_normalize_path($root) . '/';
        add_filter('plugins_url', static function (string $result, string $path, string $plugin) use ($prefix, $url): string {
            $plugin = wp_normalize_path($plugin);
            if (!str_starts_with($plugin, $prefix)) {
                return $result;
            }
            $dir = dirname(substr($plugin, strlen($prefix)));
            return $url . '/' . $dir . ($path !== '' ? '/' . ltrim($path, '/') : '');
        }, 10, 3);
    }
}

}
