<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\ColumnRef;
use Zero\Dialect;
use Zero\HttpError;
use Zero\Media;
use Zero\Media\Images;
use Zero\Wp\Media\ImageCdn;
use Zero\Schema;
use Zero\Source;
use Zero\Sql;
use Zero\Store;

/**
 * The built-in media collection on WordPress (§4.6): attachments. Upload = wp_handle_sideload +
 * wp_insert_attachment + wp_generate_attachment_metadata, inside the request transaction (the file
 * is removed again if it rolls back). `GET /media?where=` compiles through Sql against a Source that
 * maps the media row fields onto wp_posts/wp_postmeta.
 *
 * Named sizes (phase 2 §7.2) are registered as core image sizes `zero_<name>` (so themes and
 * wp_get_attachment_image_src know them) but rendered by Zero through wp_get_image_editor: core's own
 * sub-size pass skips them, and wp_generate_attachment_metadata adds them with the focal crop from
 * `_zero_focal`. Every rendering gets a fresh file `<original>-zero_<name>-<random>.<ext>`: a new
 * focal point writes new files inside the transaction, a rollback removes only those, a commit
 * removes only the files they replaced, and no other size ever shares or changes a Zero file.
 */
final class WpMedia implements Media
{
    public const FOCAL = '_zero_focal';
    /** @var list<string> sizes that failed to render during the last metadata pass */
    private static array $failed = [];
    /** @var array<int, array<string, string>> attachment → Zero rendition files core's full pass is about to forget */
    private static array $replaced = [];

    private const TABLE = '__zero_media';
    private const COLUMNS = [
        'id' => ['type' => 'integer', 'primaryKey' => true, 'autoIncrement' => true, 'notNull' => true],
        'filename' => ['type' => 'text', 'notNull' => true],
        'mime' => ['type' => 'text', 'notNull' => true],
        'alt' => ['type' => 'text'],
        'createdAt' => ['type' => 'timestamp', 'notNull' => true],
    ];

    private readonly Schema $schema;
    private readonly Store $view;

    public function __construct(private readonly Connection $db, Dialect $dialect)
    {
        $this->schema = new Schema(['version' => 3, 'runtime' => Schema::SERIES, 'collections' => [self::TABLE => ['primaryKey' => 'id', 'columns' => self::COLUMNS, 'relations' => []]]]);
        $wpdb = $db->wpdb();
        $alt = "LEFT JOIN `{$wpdb->postmeta}` {j} ON {j}.post_id = {a}.ID AND {j}.meta_key = '_wp_attachment_image_alt'";
        $file = "LEFT JOIN `{$wpdb->postmeta}` {j} ON {j}.post_id = {a}.ID AND {j}.meta_key = '_wp_attached_file'";
        $source = new Source("`{$wpdb->posts}`", "{a}.post_type = 'attachment'", [
            'id' => new ColumnRef('{a}.ID', '', '', 'scalar'),
            'filename' => new ColumnRef("SUBSTRING_INDEX({j}.meta_value, '/', -1)", $file, '', 'scalar'),
            'mime' => new ColumnRef('{a}.post_mime_type', '', '', 'scalar'),
            'alt' => new ColumnRef("NULLIF({j}.meta_value, '')", $alt, '', 'scalar'),
            'createdAt' => new ColumnRef($dialect->isoTimestamp('{a}.post_date_gmt'), '', '', 'scalar'),
        ]);
        $this->view = new MediaView($dialect, $source);
    }

    /** Register the sizes on every request, so wp-admin and core REST uploads get them too. */
    public static function boot(): void
    {
        \Zero\Wp\Media\Rest::boot();
        add_action('init', static function (): void {
            // After Runtime::app's description hook (plugins_loaded), which reports platform versions.
            \Zero\Extensions::schemaDescribe(static function (array $description): array {
                if (isset($description['runtime']['wp'])) {
                    $description['runtime']['wp']['imageCdn'] = ImageCdn::active();
                }

                return $description;
            });
            foreach (self::sizes() as $name => $size) {
                add_image_size('zero_' . $name, (int) $size['width'], (int) ($size['height'] ?? 0), ($size['fit'] ?? 'inside') === 'cover');
            }
        });
        // Core never renders Zero sizes (neither its full pass nor its missing-sizes pass, which would
        // write them to a dimension-named file a theme size may share); Zero does, below.
        $skip = static function (array $sizes): array {
            foreach (array_keys(self::sizes()) as $name) {
                unset($sizes['zero_' . $name]);
            }

            return $sizes;
        };
        add_filter('intermediate_image_sizes_advanced', $skip);
        // Core's missing-sizes pass (wp_update_image_subsizes): when only Zero sizes are missing core
        // returns early, so Zero fills and saves them here; otherwise core goes on to make its own
        // sizes and Zero fills its absent ones in the 'update' metadata pass below.
        add_filter('wp_get_missing_image_subsizes', static function (array $missing, array $meta, int $id) use ($skip): array {
            $rest = $skip($missing);
            $zero = array_filter(array_keys(self::sizes()), static fn ($n) => isset($missing['zero_' . $n]));
            if ($rest === [] && $zero !== [] && isset($meta['width'], $meta['height']) && !ImageCdn::active()) {
                Runtime::plugin()?->connection()->touchPost($id);
                $filled = self::fill($id, $meta, static fn (string $name) => in_array($name, $zero, true));
                if ($filled !== $meta) {
                    wp_update_attachment_metadata($id, $filled);
                }
            }

            return $rest;
        }, 10, 3);
        // On a fresh pass ('create') every size is rendered and the renditions it replaces are removed
        // after commit; on core's missing-sizes pass ('update') only absent Zero sizes are filled in.
        // Core's full pass first saves metadata without sizes: remember the renditions it drops.
        add_filter('wp_update_attachment_metadata', static function ($meta, int $id) {
            $stored = get_post_meta($id, '_wp_attachment_metadata', true);
            if (is_array($meta) && is_array($stored) && ($meta['sizes'] ?? null) === []) {
                foreach (array_keys(self::sizes()) as $name) {
                    if (isset($stored['sizes']['zero_' . $name]['file'])) {
                        self::$replaced[$id][$name] = (string) $stored['sizes']['zero_' . $name]['file'];
                    }
                }
            }

            return $meta;
        }, 10, 2);
        // Core's full pass (upload, regenerate tools) renders every Zero size afresh; its 'update'
        // pass after making missing core sizes fills only the absent Zero ones.
        add_filter('wp_generate_attachment_metadata', static function (array $meta, int $id, string $context = 'create'): array {
            self::$failed = [];
            if (!isset($meta['width'], $meta['height']) || ImageCdn::active()) {
                return $meta;
            }
            Runtime::plugin()?->connection()->touchPost($id);

            return self::fill($id, $meta, $context === 'update' ? static fn (string $name) => !isset($meta['sizes']['zero_' . $name]) : null);
        }, 10, 3);
    }

    /** @return array<string, array{width: int, height: ?int, fit: string}> */
    private static function sizes(): array
    {
        return Runtime::plugin()?->schema->media()['sizes'] ?? [];
    }

    /**
     * Render Zero sizes into $meta outside Zero's own API paths. New files are removed if the
     * surrounding transaction rolls back; the renditions they replace go after commit (at once
     * when no transaction is open).
     *
     * @param ?callable(string $name, bool $cover): bool $which
     */
    private static function fill(int $id, array $meta, ?callable $which): array
    {
        $previous = wp_get_attachment_metadata($id);
        $previous = is_array($previous) ? ($previous['sizes'] ?? []) : [];
        foreach (self::$replaced[$id] ?? [] as $name => $file) {
            $previous['zero_' . $name] ??= ['file' => $file];
        }
        unset(self::$replaced[$id]);
        $connection = Runtime::plugin()?->connection();
        $dir = dirname((string) get_attached_file($id));
        foreach (self::render($id, $meta, self::focalOf($id), $which) as $name => $size) {
            if ($size !== null) {
                $new = $dir . '/' . $size['file'];
                $connection?->onRollback(static fn () => @unlink($new));
            }
            $old = $previous['zero_' . $name]['file'] ?? null;
            if (is_string($old) && $old !== ($size['file'] ?? null)) {
                $old = $dir . '/' . $old;
                $connection === null ? @unlink($old) : $connection->afterCommit(static fn () => @unlink($old));
            }
            if ($size === null) {
                unset($meta['sizes']['zero_' . $name]);
            } else {
                $meta['sizes']['zero_' . $name] = $size;
            }
        }

        return $meta;
    }

    /**
     * Render configured sizes for an attachment with wp_get_image_editor ($which picks sizes by
     * name and fit; null = all). A size the original already fits maps to null: the original serves
     * it, and a rendition left from an earlier configuration must go.
     *
     * @param ?callable(string $name, bool $cover): bool $which
     * @return array<string, ?array{file: string, width: int, height: int, mime-type: string, filesize: int}>
     */
    private static function render(int $id, array $meta, ?array $focal, ?callable $which = null): array
    {
        $file = (string) get_attached_file($id);
        $mime = (string) get_post_mime_type($id);
        if (!is_file($file) || !Images::resizable($file, $mime)) {
            return [];
        }
        $out = [];
        foreach (self::sizes() as $name => $size) {
            $cover = ($size['fit'] ?? 'inside') === 'cover';
            if ($which !== null && !$which((string) $name, $cover)) {
                continue;
            }
            $dims = Images::dimensions((int) $meta['width'], (int) $meta['height'], (int) $size['width'], isset($size['height']) ? (int) $size['height'] : null, $cover, $focal);
            if ($dims === null) {
                $out[$name] = null; // the original fits: no rendition; any previous one goes
                continue;
            }
            [, , $x, $y, $w, $h, $sw, $sh] = $dims;
            $editor = wp_get_image_editor($file);
            $info = pathinfo($file);
            $dest = $info['dirname'] . '/' . $info['filename'] . '-zero_' . $name . '-' . bin2hex(random_bytes(4)) . '.' . $info['extension'];
            $saved = is_wp_error($editor) ? $editor : $editor->crop($x, $y, $sw, $sh, $w, $h);
            $saved = is_wp_error($saved) ? $saved : $editor->save($dest);
            if (is_wp_error($saved)) {
                self::$failed[] = $name;
                continue;
            }
            $out[$name] = ['file' => wp_basename($saved['path']), 'width' => (int) $saved['width'], 'height' => (int) $saved['height'],
                'mime-type' => (string) $saved['mime-type'], 'filesize' => (int) ($saved['filesize'] ?? filesize($saved['path']))];
        }

        return $out;
    }

    private static function focalOf(int $id): ?array
    {
        $raw = get_post_meta($id, self::FOCAL, true);
        $focal = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($focal) && isset($focal['x'], $focal['y']) ? ['x' => $focal['x'], 'y' => $focal['y']] : null;
    }

    /** API writes refuse a partial set of sizes (the transaction rolls back and can be retried). */
    public static function requireRendered(): void
    {
        if (self::$failed !== []) {
            $failed = self::$failed;
            self::$failed = [];
            throw new \RuntimeException('Could not render image sizes: ' . implode(', ', $failed));
        }
    }

    public function upload(string $tmpPath, string $filename, ?array $user, ?string $alt = null): array
    {
        if (!is_file($tmpPath)) {
            throw HttpError::invalidInput('No file was uploaded', '/file');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $name = sanitize_file_name(basename(str_replace('\\', '/', $filename)) ?: 'file');
        $upload = ['name' => $name, 'tmp_name' => $tmpPath, 'error' => 0, 'size' => (int) filesize($tmpPath)];
        $moved = wp_handle_sideload($upload, ['test_form' => false]);
        if (isset($moved['error'])) {
            if (!empty($moved['zero_storage_error'])) {
                throw \Zero\Media\S3::unavailable();
            }
            throw HttpError::invalidInput((string) $moved['error'], '/file');
        }
        $path = (string) $moved['file'];
        $this->db->onRollback(static fn () => @unlink($path));
        $id = $this->db->core(static fn () => wp_insert_attachment([
            'post_mime_type' => (string) $moved['type'],
            'post_title' => sanitize_text_field(pathinfo($name, PATHINFO_FILENAME)),
            'post_status' => 'inherit',
            'post_author' => $user['id'] ?? 0,
        ], $path, 0, true));
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('wp_insert_attachment failed: ' . $id->get_error_message());
        }
        $id = (int) $id;
        $this->db->touchPost($id);
        $meta = wp_generate_attachment_metadata($id, $path);
        $this->db->onRollback(static function () use ($meta, $path) {
            foreach (($meta['sizes'] ?? []) as $size) {
                @unlink(dirname($path) . '/' . $size['file']);
            }
        });
        self::requireRendered();
        wp_update_attachment_metadata($id, $meta);
        if ($alt !== null && $alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
        }

        return $this->get($id) ?? throw new \RuntimeException('Attachment vanished after upload');
    }

    public function get(int $id): ?array
    {
        return $this->getMany([$id])[$id] ?? null;
    }

    public function getMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return array_column($this->list(['where' => ['id' => ['in' => $ids]]]), null, 'id');
    }

    public function list(array $opts): array
    {
        $q = Sql::select($this->schema, $this->view, self::TABLE, $opts);
        $rows = $this->db->run($q['sql'], $q['params']);

        return array_map(fn (array $r) => $this->row((int) $r['id']), $rows);
    }

    public function update(int $id, array $patch): ?array
    {
        if (get_post_type($id) !== 'attachment') {
            return null;
        }
        $this->db->touchPost($id);
        if (array_key_exists('alt', $patch)) {
            $patch['alt'] === null ? delete_post_meta($id, '_wp_attachment_image_alt') : update_post_meta($id, '_wp_attachment_image_alt', wp_slash($patch['alt']));
        }
        if (array_key_exists('focal', $patch) && $patch['focal'] !== self::focalOf($id)) {
            $patch['focal'] === null ? delete_post_meta($id, self::FOCAL) : update_post_meta($id, self::FOCAL, wp_slash((string) \Zero\Js::jsonStringify($patch['focal'])));
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta) && isset($meta['width'], $meta['height']) && !ImageCdn::active()) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                // Cover sizes follow the focal point; sizes configured after the upload are filled in.
                self::$failed = [];
                $fresh = self::render($id, $meta, $patch['focal'], static fn (string $name, bool $cover) => $cover || !isset($meta['sizes']['zero_' . $name]));
                $dir = dirname((string) get_attached_file($id));
                foreach ($fresh as $name => $size) {
                    if ($size !== null) {
                        $new = $dir . '/' . $size['file'];
                        $this->db->onRollback(static fn () => @unlink($new));
                    }
                    if (isset($meta['sizes']['zero_' . $name])) {
                        $old = $dir . '/' . $meta['sizes']['zero_' . $name]['file'];
                        $this->db->afterCommit(static fn () => @unlink($old));
                    }
                    if ($size === null) {
                        unset($meta['sizes']['zero_' . $name]);
                    } else {
                        $meta['sizes']['zero_' . $name] = $size;
                    }
                }
                self::requireRendered();
                wp_update_attachment_metadata($id, $meta);
            }
        }

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->db->touchPost($id);
        $file = get_attached_file($id, true);
        if (Uploads::active()) {
            Uploads::deleted($id);
        }
        $meta = wp_get_attachment_metadata($id);
        // Rows only now; files go once the transaction commits (a rollback keeps them in place).
        $this->db->run('DELETE FROM `' . $this->db->wpdb()->postmeta . '` WHERE post_id = ?', [$id]);
        $this->db->run('DELETE FROM `' . $this->db->wpdb()->posts . '` WHERE ID = ?', [$id]);
        $this->db->afterCommit(static function () use ($id, $file, $meta) {
            clean_post_cache($id);
            if (is_string($file) && $file !== '') {
                foreach (($meta['sizes'] ?? []) as $size) {
                    @unlink(dirname($file) . '/' . $size['file']);
                }
                @unlink($file);
            }
        });
    }

    /** media row: { id, url, mime, size, width, height, alt, filename, createdAt, sizes, focal } */
    private function row(int $id): array
    {
        $post = get_post($id);
        $meta = wp_get_attachment_metadata($id) ?: [];
        $file = (string) get_attached_file($id, true);
        $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
        $focal = self::focalOf($id);
        $mime = (string) $post?->post_mime_type;
        $rel = (string) get_post_meta($id, '_wp_attached_file', true);
        $object = Uploads::active() ? (Uploads::manifest($id)[$rel] ?? null) : null;
        $animated = $mime === 'image/gif' && !(Uploads::active() ? ($object['resizable'] ?? false) : Images::resizable($file, $mime));
        $sizes = [];
        if (isset($meta['width'], $meta['height']) && in_array($mime, Images::RESIZABLE, true) && !$animated) {
            $cdn = ImageCdn::active();
            foreach (self::sizes() as $name => $size) {
                if ($cdn) {
                    $sizes[$name] = ImageCdn::size((string) wp_get_attachment_url($id), (int) $meta['width'], (int) $meta['height'], $size, $focal);
                    continue;
                }
                $variant = $meta['sizes']['zero_' . $name] ?? null;
                $src = $variant === null ? false : (Uploads::active()
                    ? [Uploads::url($id, dirname($rel) . '/' . $variant['file']), $variant['width'], $variant['height']]
                    : wp_get_attachment_image_src($id, 'zero_' . $name));
                $sizes[$name] = $src === false
                    ? ['url' => (string) wp_get_attachment_url($id), 'width' => (int) $meta['width'], 'height' => (int) $meta['height']]
                    : ['url' => (string) $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2]];
            }
        }

        return [
            'id' => $id,
            'url' => (string) wp_get_attachment_url($id),
            'mime' => (string) $post?->post_mime_type,
            'size' => (int) ($meta['filesize'] ?? ($object['size'] ?? null) ?? (is_file($file) ? filesize($file) : 0)),
            'width' => isset($meta['width']) ? (int) $meta['width'] : null,
            'height' => isset($meta['height']) ? (int) $meta['height'] : null,
            'alt' => is_string($alt) && $alt !== '' ? $alt : null,
            'filename' => basename($file),
            'createdAt' => str_replace(' ', 'T', (string) $post?->post_date_gmt) . '.000Z',
            'sizes' => $sizes === [] ? new \stdClass() : $sizes,
            'focal' => $focal,
        ];
    }
}

/** @internal Answers dialect()/source() for compiling media queries; never stores. */
final class MediaView implements Store
{
    public function readGuards(string $collection, \Zero\Viewer $viewer): array { return []; }

    public function __construct(private readonly Dialect $d, private readonly Source $source)
    {
    }

    public function dialect(): Dialect
    {
        return $this->d;
    }

    public function source(string $collection): Source
    {
        return $this->source;
    }

    public function afterCommit(callable $fn): void { throw new \LogicException('read-only view'); }
    public function applySystemStatements(array $statements): void { throw new \LogicException('read-only view'); }
    public function transaction(callable $fn): mixed { return $fn(); }
    public function select(string $collection, array $q): array { throw new \LogicException('read-only view'); }
    public function insert(string $collection, array $values): int|string { throw new \LogicException('read-only view'); }
    public function update(string $collection, int|string $pk, array $values): void { throw new \LogicException('read-only view'); }
    public function delete(string $collection, int|string $pk): void { throw new \LogicException('read-only view'); }
    public function globalRead(string $name): ?array { return null; }
    public function globalWrite(string $name, array $values): void { throw new \LogicException('read-only view'); }
    public function revisions(string $collection, int|string $pk): array { return []; }
    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId): void { throw new \LogicException('read-only view'); }
    public function revision(string $collection, int|string $pk, int $revisionId): ?array { return null; }
    public function promoteDue(string $collection, string $nowIso): array { return []; }
    public function mapError(\Throwable $e): \Throwable { return $e; }
}
