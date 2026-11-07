<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\Env;
use Zero\HttpError;
use Zero\Media\S3;
use Zero\Response;

/** Shared durable upload backend; WP paths exist only inside a request's scratch directory. */
final class Uploads
{
    public const MANIFEST = '_zero_uploads';
    private const MAINTAIN = 'zero_uploads_maintain';
    private static ?S3 $store = null;
    private static ?string $scratch = null;

    public static function active(): bool
    {
        return (Runtime::plugin()?->schema->uploads()['backend'] ?? 'local') === 's3';
    }

    public static function store(): S3
    {
        return self::$store ??= new S3(Runtime::plugin()?->schema->uploads() ?? []);
    }

    public static function prefix(): string
    {
        $config = Runtime::plugin()?->schema->uploads() ?? [];
        $prefix = Env::resolve($config['prefix'] ?? 'zero');
        if ($prefix === null || preg_match('/[\x00-\x20\x7f]/', $prefix)) {
            throw S3::unavailable();
        }
        $site = get_option('zero_upload_namespace');
        if (!is_string($site) || !preg_match('/^[a-f0-9]{32}$/D', $site)) {
            add_option('zero_upload_namespace', bin2hex(random_bytes(16)), '', false);
            $site = get_option('zero_upload_namespace');
            if (!is_string($site) || !preg_match('/^[a-f0-9]{32}$/D', $site)) {
                throw S3::unavailable();
            }
        }

        return trim($prefix, '/') . '/' . $site . '/' . hash('sha256', Runtime::plugin()->schema->name()) . '/';
    }

    public static function fileKey(string $rel): string
    {
        return self::prefix() . 'files/' . hash('sha256', $rel);
    }

    /** Failed compensation is retried by the DB-backed recurring reconciliation action. */
    public static function discard(string $key): void
    {
        try {
            self::deleteUnreferenced($key);
        } catch (\Throwable) {
            error_log('Zero upload cleanup deferred');
        }
    }

    private static function deleteUnreferenced(string $key): void
    {
        // A retired or compensated object no longer proves request-local reuse is safe.
        foreach (self::$written as $cache => $entry) {
            if ($entry['key'] === $key) {
                unset(self::$written[$cache]);
            }
        }
        $plugin = Runtime::plugin() ?? throw S3::unavailable();
        $db = $plugin->connection();
        // Commit/rollback callbacks must consult final durable state, not WP's metadata cache.
        if ($db->run('SELECT id FROM `' . $db->prefix() . 'zero__files` WHERE app = ? AND path = ? LIMIT 1', [$plugin->schema->name(), 's3:' . $key]) !== []) {
            return;
        }
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key) . '%';
        $rows = $db->run('SELECT m.meta_value FROM `' . $db->wpdb()->postmeta . '` m INNER JOIN `' . $db->wpdb()->posts . '` p ON p.ID = m.post_id WHERE p.post_type = ? AND m.meta_key = ? AND m.meta_value LIKE ?', ['attachment', self::MANIFEST, $pattern]);
        foreach ($rows as $row) {
            $manifest = unserialize($row['meta_value'], ['allowed_classes' => false]);
            foreach (is_array($manifest) ? $manifest : [] as $entry) {
                if (($entry['key'] ?? null) === $key) {
                    return;
                }
            }
        }
        self::store()->delete($key);
    }

    public static function boot(): void
    {
        // App registration happens after the runtime loads. Decide inside each hook.
        add_action('init', static function (): void {
            if (self::active()) {
                self::prefix(); // Install site identity before request transactions can begin.
            }
        }, 2);
        add_filter('upload_dir', static function (array $dir): array {
            if (!self::active()) {
                return $dir;
            }
            $root = self::scratch();
            $dir['basedir'] = $root;
            $dir['path'] = $root . $dir['subdir'];
            $dir['error'] = false;

            return $dir;
        });
        add_filter('wp_handle_upload', static function (array $upload): array {
            if (!self::active() || isset($upload['error'])) {
                return $upload;
            }
            try {
                // Prove durability before core can report success (also covers non-image attachments).
                self::object((string) $upload['file']);
            } catch (\Throwable) {
                @unlink($upload['file']);
                return ['error' => 'The configured upload storage is unavailable', 'zero_storage_error' => true];
            }

            return $upload;
        });
        add_filter('update_attached_file', static function (string $file, int $id): string {
            if (self::active()) {
                $path = str_starts_with($file, self::scratch() . '/') ? $file : self::scratch() . '/' . ltrim($file, '/');
                self::save($id, [$path]);
            }

            return $file;
        }, 100, 2);
        add_filter('wp_update_attachment_metadata', static function ($meta, int $id) {
            if (!self::active() || !is_array($meta)) {
                return $meta;
            }
            $file = get_post_meta($id, '_wp_attached_file', true);
            if (!is_string($file) || $file === '') {
                return $meta;
            }
            $base = self::scratch() . '/' . $file;
            $paths = [$base];
            foreach ($meta['sizes'] ?? [] as $size) {
                $paths[] = dirname($base) . '/' . $size['file'];
            }
            if (isset($meta['original_image'])) {
                $paths[] = dirname($base) . '/' . $meta['original_image'];
            }
            foreach ((array) get_post_meta($id, '_wp_attachment_backup_sizes', true) as $size) {
                if (isset($size['file'])) {
                    $paths[] = dirname($base) . '/' . $size['file'];
                }
            }
            self::save($id, $paths, true);

            return $meta;
        }, PHP_INT_MAX, 2);
        // Core saves edit backups after its primary metadata update.
        add_filter('update_post_metadata', static function ($check, int $id, string $key, $value) {
            if (self::active() && $key === '_wp_attachment_backup_sizes' && is_array($value)) {
                $rel = get_post_meta($id, '_wp_attached_file', true);
                $paths = [];
                foreach ($value as $size) {
                    if (isset($size['file'])) {
                        $paths[] = self::scratch() . '/' . dirname($rel) . '/' . $size['file'];
                    }
                }
                self::save($id, $paths);
            }
            return $check;
        }, 100, 4);
        add_filter('get_attached_file', static function ($file, int $id) {
            if (!self::active()) {
                return $file;
            }
            $rel = get_post_meta($id, '_wp_attached_file', true);
            $entry = self::manifest($id)[$rel] ?? null;
            if ($entry === null) {
                throw new HttpError(503, 'uploads_migration_required', 'This attachment must be migrated before enabling S3 uploads');
            }
            $dest = self::scratch() . '/' . $rel;
            // Core image editing/restoration may need backups and sizes alongside the original.
            foreach (self::manifest($id) as $variant => $object) {
                $local = self::scratch() . '/' . $variant;
                if (!is_file($local)) {
                    wp_mkdir_p(dirname($local));
                    self::store()->hydrate($object['key'], $local);
                }
            }

            return $dest;
        }, 100, 2);
        add_filter('wp_get_attachment_url', static function ($url, int $id) {
            if (!self::active()) {
                return $url;
            }
            $rel = get_post_meta($id, '_wp_attached_file', true);
            if (!isset(self::manifest($id)[$rel])) {
                throw new HttpError(503, 'uploads_migration_required', 'This attachment must be migrated before enabling S3 uploads');
            }
            return self::url($id, $rel);
        }, 100, 2);
        add_filter('image_downsize', static function ($out, int $id, $size) {
            if (!self::active()) {
                return $out;
            }
            $meta = wp_get_attachment_metadata($id);
            $rel = get_post_meta($id, '_wp_attached_file', true);
            if (!is_array($meta) || !is_string($rel)) {
                return false;
            }
            if (is_string($size) && isset($meta['sizes'][$size])) {
                $s = $meta['sizes'][$size];
                $variant = dirname($rel) . '/' . $s['file'];
                return [self::url($id, $variant), $s['width'], $s['height'], true];
            }
            // Core chooses the best registered size for array requests; retain that behavior.
            $found = is_array($size) ? image_get_intermediate_size($id, $size) : false;
            if (is_array($found)) {
                return [self::url($id, dirname($rel) . '/' . $found['file']), $found['width'], $found['height'], true];
            }

            [$width, $height] = image_constrain_size_for_editor((int) ($meta['width'] ?? 0), (int) ($meta['height'] ?? 0), $size);
            return [self::url($id, $rel), $width, $height, false];
        }, 100, 3);
        add_filter('wp_calculate_image_srcset', static function ($sources, $size, $src, $meta, int $id) {
            if (self::active() && is_array($sources)) {
                $rel = get_post_meta($id, '_wp_attached_file', true);
                foreach ($sources as &$source) {
                    $path = dirname($rel) . '/' . wp_basename(parse_url($source['url'], PHP_URL_PATH) ?: '');
                    if (isset(self::manifest($id)[$path])) {
                        $source['url'] = self::url($id, $path);
                    } else {
                        // Already rewritten full-size URLs, or an external filter, have no local basename.
                        unset($sources[$source['value']]);
                    }
                }
            }

            return $sources;
        }, 100, 5);
        add_action('delete_attachment', static function (int $id): void {
            if (self::active()) {
                self::deleted($id);
            }
        });
        add_action(self::MAINTAIN, self::maintain(...));
        add_action('action_scheduler_init', static function (): void {
            if (self::active() && !as_has_scheduled_action(self::MAINTAIN, [], 'zero')) {
                as_schedule_recurring_action(time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::MAINTAIN, [], 'zero');
            }
        });
    }

    public static function scratch(): string
    {
        if (self::$scratch === null) {
            self::$scratch = sys_get_temp_dir() . '/zero-uploads-' . bin2hex(random_bytes(16));
            if (!wp_mkdir_p(self::$scratch)) {
                throw S3::unavailable();
            }
            register_shutdown_function(static function (): void {
                $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$scratch, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($walk as $file) {
                    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
                }
                @rmdir(self::$scratch);
            });
        }

        return self::$scratch;
    }

    /** Reuse only identical bytes, never overwrite a committed object during an edit. */
    private static array $written = [];
    private static function object(string $path): array
    {
        if (!is_file($path) || !str_starts_with($path, self::scratch() . '/')) {
            throw S3::unavailable();
        }
        $sha = hash_file('sha256', $path);
        $cache = $path . ':' . $sha;
        if (isset(self::$written[$cache])) {
            return self::$written[$cache];
        }
        $key = self::prefix() . 'media/' . bin2hex(random_bytes(24));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        self::store()->put($key, $path, $mime);
        Runtime::plugin()?->connection()->onRollback(static fn () => self::discard($key));

        return self::$written[$cache] = ['key' => $key, 'sha' => $sha, 'size' => (int) filesize($path), 'mime' => $mime, 'resizable' => $mime !== 'image/gif' || \Zero\Media\Images::resizable($path, $mime)];
    }

    public static function manifest(int $id): array
    {
        $value = get_post_meta($id, self::MANIFEST, true);
        return is_array($value) ? $value : [];
    }

    private static function save(int $id, array $paths, bool $replace = false): void
    {
        $old = self::manifest($id);
        $next = $replace ? [] : $old;
        foreach (array_unique($paths) as $path) {
            $rel = substr($path, strlen(self::scratch()) + 1);
            if (!is_file($path)) {
                if (isset($old[$rel])) {
                    $next[$rel] = $old[$rel];
                    continue;
                }
                throw S3::unavailable();
            }
            $next[$rel] = isset($old[$rel]) && hash_file('sha256', $path) === $old[$rel]['sha'] ? $old[$rel] : self::object($path);
        }
        Runtime::plugin()?->connection()->touchPost($id);
        if ($next !== $old && update_post_meta($id, self::MANIFEST, wp_slash($next)) === false) {
            throw S3::unavailable();
        }
        $retained = array_column($next, 'key');
        foreach ($old as $entry) {
            if (!in_array($entry['key'], $retained, true)) {
                Runtime::plugin()?->connection()->afterCommit(static fn () => self::discard($entry['key']));
            }
        }
    }

    public static function deleted(int $id): void
    {
        foreach (self::manifest($id) as $entry) {
            Runtime::plugin()?->connection()->afterCommit(static fn () => self::discard($entry['key']));
        }
    }

    public static function url(int $id, string $rel): string
    {
        return rest_url(Rest::NAMESPACE . '/media/' . $id . '/file/' . hash('sha256', $rel) . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))));
    }

    public static function dispatch(string $method, array $segments): Response
    {
        \Zero\App::allow($method, ['GET']);
        if (!self::active() || count($segments) < 5 || !ctype_digit($segments[1]) || get_post_type((int) $segments[1]) !== 'attachment') {
            throw HttpError::notFound('No such upload');
        }
        $manifest = self::manifest((int) $segments[1]);
        $rel = implode('/', array_slice($segments, 4));
        $entry = $manifest[$rel] ?? null;
        // Core's editor also constructs sibling-size URLs from the original's directory.
        if ($entry !== null) {
            foreach (array_keys($manifest) as $grant) {
                if (hash('sha256', $grant) !== $segments[3]) {
                    continue;
                }
                $image = in_array($entry['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true);
                return Response::raw(self::store()->stream($entry['key']), 200, [
                    'Content-Type' => $entry['mime'], 'Content-Length' => (string) $entry['size'],
                    'Content-Disposition' => ($image ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode(basename($rel)),
                    'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox', 'Cache-Control' => 'public, max-age=0, must-revalidate',
                ]);
            }
        }
        throw HttpError::notFound('No such upload');
    }

    /** Crash-safe orphan/deletion recovery: one provider page per hourly DB-backed action. */
    public static function maintain(): void
    {
        self::reconcile(time() - DAY_IN_SECONDS);
    }

    /** Trusted maintenance entry point, with an explicit cutoff for bounded operational recovery. */
    public static function reconcile(int $olderThan): void
    {
        $plugin = Runtime::plugin();
        if (!self::active() || $plugin?->unavailable() !== null) {
            return;
        }
        $option = 'zero_upload_cursor_' . hash('sha256', self::prefix());
        $cursor = get_option($option);
        [$objects, $next] = self::store()->page(self::prefix(), is_string($cursor) && $cursor !== '' ? $cursor : null);
        foreach ($objects as $object) {
            // The grace period bounds request lifetime and avoids collecting in-flight transactions.
            if ($object['time'] === null || $object['time'] >= $olderThan) {
                continue;
            }
            self::deleteUnreferenced($object['key']);
        }
        update_option($option, $next ?? '', false);
        if ($next !== null && as_schedule_single_action(time() + 60, self::MAINTAIN, [], 'zero') === 0) {
            throw new \RuntimeException('Could not schedule upload cleanup continuation');
        }
    }
}
