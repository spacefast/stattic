<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\Media\FileStorage;
use Zero\Media\Files;

/**
 * Private files on WordPress: app-scoped rows in `{prefix}zero__files`, bytes in S3 or
 * local `uploads/zero-private/<app>/<bucket>/<uuid>`, denied by `.htaccess`.
 *
 * Nothing guarantees the web server reads `.htaccess` (nginx ignores it), so the runtime proves it:
 * on activation and daily on admin_init it writes a probe file and fetches its direct URL. Anything
 * but a refusal sets `zero_runtime_private_ok` false, and uploads answer 503 until a probe passes.
 */
final class WpFiles implements FileStorage
{
    public const SECRET = 'zero_runtime_secret';
    public const OK = 'zero_runtime_private_ok';
    public const CHECKED = 'zero_runtime_private_checked';
    private const DIR = 'zero-private';
    private const HTACCESS = "# Zero private files: served only through signed download URLs.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

    public function __construct(private readonly Connection $db, private readonly string $app)
    {
    }

    /**
     * Activation of any Zero plugin, and the runtime's table install/upgrade, write the deny rules,
     * create the secret and probe; admin_init re-probes daily without rewriting anything, so a
     * removed `.htaccess` is noticed, not hidden.
     */
    public static function boot(): void
    {
        $install = static function (): void {
            self::ensureSecret();
            if (!Uploads::active()) {
                self::protect();
                self::probe();
            }
        };
        add_action('activated_plugin', static fn (string $plugin) => str_starts_with($plugin, 'zero-') ? $install() : null);
        // Sites upgraded in place (no reactivation) install protection when the runtime adds the table.
        add_action('zero_runtime_tables_installed', $install);
        add_action('admin_init', static function (): void {
            if (!Uploads::active() && (int) get_option(self::CHECKED, 0) < time() - DAY_IN_SECONDS) {
                self::probe();
            }
        });
        // Editor saves (wp-admin and core REST `acf`) bypass the API pipeline: check file ids there too.
        // Top-level file fields are SCF numbers; nested ones are reached through their top-level
        // group/repeater/flexible_content field (SCF validates REST values per top-level field).
        foreach (['number', 'group', 'repeater', 'flexible_content'] as $type) {
            add_filter("acf/validate_value/type=$type", static fn ($valid, $value, $field) => self::validateEditor($valid, $value, $field, false), 30, 3);
            add_filter("acf/validate_rest_value/type=$type", static fn ($valid, $value, $field) => self::validateEditor($valid, $value, $field, true), 30, 3);
        }
    }

    /** Every file id in an editor value (top-level or nested) must name a file in its bucket. */
    private static function validateEditor(mixed $valid, mixed $value, mixed $field, bool $rest): mixed
    {
        $def = is_array($field) ? ($field['zero'] ?? null) : null;
        if (($valid !== true && $valid !== 1) || !is_array($def) || empty($field['zero_top']) || $value === null || $value === '') {
            return $valid;
        }
        if (!in_array($def['type'], ['file', 'group', 'repeater', 'blocks'], true)) {
            return $valid;
        }
        try {
            $refs = Files::refs($def, Scf::fromScf($def, $value, (string) $field['key']), '');
        } catch (\Throwable) {
            return $valid; // shape errors are Scf's to report
        }
        if ($refs === []) {
            return $valid;
        }
        Runtime::plugin()?->app(); // editor saves run before any Zero request has booted the app
        foreach ($refs as [$id, $bucket]) {
            if (($message = Files::missing($id, $bucket)) !== null) {
                return $rest ? new \WP_Error('rest_invalid_param', $message, ['status' => 400]) : $message;
            }
        }

        return $valid;
    }

    /** The runtime secret, created once (activation, or first use). */
    public static function ensureSecret(): string
    {
        $secret = get_option(self::SECRET);
        if (!is_string($secret) || strlen($secret) !== 64) {
            $secret = bin2hex(random_bytes(32));
            update_option(self::SECRET, $secret, false);
        }

        return $secret;
    }

    /** `WP_CONTENT_DIR/uploads/zero-private` exactly, whatever the media upload directory is. */
    public static function root(): string
    {
        return WP_CONTENT_DIR . '/uploads/' . self::DIR;
    }

    /** Write the deny rules (and an empty index) into the private root. */
    public static function protect(): void
    {
        $root = self::root();
        if (!is_dir($root) && !wp_mkdir_p($root)) {
            throw new \RuntimeException("Cannot create $root");
        }
        if (@file_get_contents("$root/.htaccess") !== self::HTACCESS) {
            file_put_contents("$root/.htaccess", self::HTACCESS);
        }
        if (!is_file("$root/index.php")) {
            file_put_contents("$root/index.php", "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Fetch a probe file through the web server. Only a 401/403/404 counts as protected; a 200,
     * another status or a failed request leaves uploads closed. Returns the stored verdict.
     */
    public static function probe(): bool
    {
        if (!is_dir(self::root()) && !wp_mkdir_p(self::root())) {
            update_option(self::OK, '0', true);

            return false;
        }
        $name = 'probe-' . bin2hex(random_bytes(8)) . '.txt';
        $file = self::root() . '/' . $name;
        if (@file_put_contents($file, 'zero') !== 4) {
            // No probe file means no proof: a 404 for a missing file says nothing about the web server.
            update_option(self::OK, '0', true);
            update_option(self::CHECKED, time(), false);

            return false;
        }
        try {
            $url = self::url($name);
            $response = wp_remote_get(self::loopback($url), ['timeout' => 5, 'redirection' => 0, 'sslverify' => false]);
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $ok = in_array($code, [401, 403, 404], true);
        } finally {
            @unlink($file);
        }
        update_option(self::OK, $ok ? '1' : '0', true);
        update_option(self::CHECKED, time(), false);

        return $ok;
    }

    /** Direct URL of a path below the private root. */
    public static function url(string $rel): string
    {
        return content_url('uploads/' . self::DIR . '/' . $rel);
    }

    /** `zero_private_probe_url` lets a host route the probe (e.g. a container's internal name). */
    private static function loopback(string $url): string
    {
        return (string) apply_filters('zero_private_probe_url', $url);
    }

    public function secret(): string
    {
        return self::ensureSecret();
    }

    public function unavailable(): ?string
    {
        if (Uploads::active()) {
            Uploads::store();
            return null;
        }
        if (get_option(self::OK) === '1') {
            return null;
        }

        return 'private files are reachable at ' . self::url('') . '; deny ' . (string) wp_parse_url(self::url(''), PHP_URL_PATH) . ' in your web server config';
    }

    public function store(string $tmpPath, string $rel): void
    {
        if (Uploads::active()) {
            $key = Uploads::fileKey($rel);
            Uploads::store()->put($key, $tmpPath, (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream');
            $this->db->onRollback(static fn () => Uploads::discard($key));
            @unlink($tmpPath);
            return;
        }
        self::protect();
        $dest = $this->absolute($rel);
        if (!is_dir(dirname($dest)) && !wp_mkdir_p(dirname($dest))) {
            throw new \RuntimeException('Cannot create the private files directory');
        }
        if (!(is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : rename($tmpPath, $dest))) {
            throw new \RuntimeException('Cannot store the uploaded file');
        }
        $this->db->onRollback(static fn () => @unlink($dest));
    }

    /** Guarded upgrade for tables created before rows carried their app. */
    public static function upgrade(\Zero\Store $store): void
    {
        $db = $store instanceof CompositeStore ? $store->db : null;
        if ($db === null) {
            return;
        }
        $table = $db->prefix() . 'zero__files';
        $have = $db->run('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'app']);
        if ($have === []) {
            $store->applySystemStatements(["ALTER TABLE `{prefix}zero__files` ADD COLUMN `app` VARCHAR(191) NOT NULL DEFAULT '' AFTER `id`, ADD KEY `app` (`app`)"]);
        }
    }

    public function insert(array $row): int
    {
        if (Uploads::active()) {
            $row['path'] = 's3:' . Uploads::fileKey($row['path']);
        }
        $this->db->run(
            'INSERT INTO `' . $this->table() . '` (`app`, `bucket`, `filename`, `mime`, `size`, `path`, `ownerId`, `createdAt`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->app, $row['bucket'], $row['filename'], $row['mime'], $row['size'], $row['path'], $row['ownerId'], $row['createdAt']],
        );

        return $this->db->insertId();
    }

    public function getMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        // Rows belong to one app: another app on the same site (or a replaced one) never resolves them.
        foreach ($this->db->run('SELECT * FROM `' . $this->table() . '` WHERE `app` = ? AND `id` IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', [$this->app, ...$ids]) as $r) {
            $out[(int) $r['id']] = Files::normalizeRow($r);
        }

        return $out;
    }

    public function delete(array $row): void
    {
        $this->db->run('DELETE FROM `' . $this->table() . '` WHERE `app` = ? AND `id` = ?', [$this->app, $row['id']]);
        if (str_starts_with($row['path'], 's3:')) {
            if (!Uploads::active()) {
                throw \Zero\Media\S3::unavailable();
            }
            $this->db->afterCommit(static fn () => Uploads::discard(substr($row['path'], 3)));
        } else {
            $file = $this->absolute($row['path']);
            $this->db->afterCommit(static fn () => @unlink($file));
        }
    }

    private function absolute(string $rel): string
    {
        return self::root() . '/' . $this->app . '/' . $rel;
    }

    public function stream(string $rel): \Closure
    {
        if (str_starts_with($rel, 's3:')) {
            if (!Uploads::active()) {
                throw \Zero\Media\S3::unavailable();
            }
            return Uploads::store()->stream(substr($rel, 3));
        }
        if (Uploads::active()) {
            throw new \Zero\HttpError(503, 'uploads_migration_required', 'This file must be migrated before enabling S3 uploads');
        }
        $path = $this->absolute($rel);
        if (!is_file($path)) {
            throw \Zero\HttpError::notFound('No such file');
        }
        return static function () use ($path): void {
            $in = fopen($path, 'rb');
            if ($in !== false) {
                fpassthru($in);
                fclose($in);
            }
        };
    }

    public function apiUrl(string $route): string
    {
        return rest_url(Rest::NAMESPACE . $route);
    }

    public function describe(): array
    {
        if (Uploads::active()) {
            return ['backend' => 's3', 'protected' => true, 'checkedAt' => null];
        }
        return ['protected' => get_option(self::OK) === '1', 'checkedAt' => (int) get_option(self::CHECKED, 0) ?: null];
    }

    private function table(): string
    {
        return $this->db->prefix() . 'zero__files';
    }
}
