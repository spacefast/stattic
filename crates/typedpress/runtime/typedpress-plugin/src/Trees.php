<?php
declare(strict_types=1);

namespace TypedPressPlugin;

/**
 * Writes uploaded trees into the build store. A tree is named by the hash of its paths and bytes (the
 * compiler's digest), so an upload is unpacked beside the store, hashed, and published by one rename
 * only when the hash is the name it was uploaded under. Nothing in the store is edited afterwards.
 */
final class Trees
{
    private const DIRS = ['runtime', 'app', 'build', 'ready', '.tmp'];
    /**
     * What one tree may unpack to. TypedPress's runtime tree is about 4,700 files and 60 MB; these bounds
     * are there so an archive that expands far past that fills no disk before its hash is checked.
     */
    private const FILES = 20000;
    private const BYTES = 512 * 1048576;

    /** What a browser may fetch from a stored tree outside the app's `public/` folder. */
    private const PUBLIC = [
        'js', 'mjs', 'css',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'apng',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp4', 'webm', 'ogv', 'ogg', 'mp3', 'wav', 'flac', 'aac', 'm4a', 'opus', 'mov',
        'pdf', 'wasm', 'txt', 'csv', 'xml', 'webmanifest', 'glb', 'gltf', 'zip', 'vtt',
    ];
    /** Never mirrored into `typedpress-assets`, even inside `public/`: PHP and server config. */
    private const REFUSED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'htaccess', 'htpasswd', 'ini', 'user.ini'];

    /** Create the store's directories. Where `.htaccess` is read, nothing in the store is served at all. */
    public static function prepare(string $root): void
    {
        foreach (self::DIRS as $dir) {
            if (!is_dir($root . '/' . $dir) && !wp_mkdir_p($root . '/' . $dir)) {
                throw new \RuntimeException("Cannot create the TypedPress build store at $root");
            }
        }
        $rules = "# TypedPress's build store: code and data, never served.\nRequire all denied\n";
        if (@file_get_contents($root . '/.htaccess') !== $rules) {
            @file_put_contents($root . '/.htaccess', $rules);
        }
        if (!is_file($root . '/index.php')) {
            @file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Copy a stored tree's browser files to the public directory, at the same relative paths, so the
     * URLs the build's plugins make (`plugins_url`) resolve there. Only the plugins inside the tree are
     * copied, and only {@see PUBLIC} file types: never PHP, a manifest, a migration or seed content.
     */
    public static function mirror(string $root, string $public, string $kind, string $id): void
    {
        $target = "$public/$kind/$id";
        if (is_dir($target)) {
            return;
        }
        $from = "$root/$kind/$id" . ($kind === 'app' ? '/plugins' : '');
        $files = [];
        if (is_dir($from)) {
            self::walk($from, '', $files);
        }
        if (!wp_mkdir_p("$public/.tmp")) {
            throw new \RuntimeException("Cannot create $public");
        }
        // Every file here sits under the hash of the tree it came from, so its URL never changes
        // meaning: browsers may keep it. (The build's own rule for this is not copied: no file an
        // upload supplies configures the public directory.)
        $rules = "# TypedPress: each path here carries the hash of its tree.\n"
            . "<IfModule mod_headers.c>\n"
            . "  Header set Cache-Control \"public, max-age=31536000, immutable\"\n"
            . "  Header set X-Content-Type-Options \"nosniff\"\n"
            . "  Header set Access-Control-Allow-Origin \"*\"\n"
            . "</IfModule>\n"
            . "<FilesMatch \"\\.(?i:svg|xml)$\">\n"
            . "  <IfModule mod_headers.c>\n"
            . "    Header set Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox\"\n"
            . "  </IfModule>\n"
            . "</FilesMatch>\n";
        if (@file_get_contents("$public/.htaccess") !== $rules) {
            @file_put_contents("$public/.htaccess", $rules);
        }
        $staged = "$public/.tmp/" . bin2hex(random_bytes(8));
        $to = $staged . ($kind === 'app' ? '/plugins' : '');
        wp_mkdir_p($to);
        foreach ($files as $relative) {
            if (self::mirrorable($kind, $relative)) {
                wp_mkdir_p(dirname("$to/$relative"));
                if (!copy("$from/$relative", "$to/$relative")) {
                    self::remove($staged);
                    throw new \RuntimeException("Cannot copy $relative to $public");
                }
            }
        }
        wp_mkdir_p("$public/$kind");
        if (is_dir($target) || !rename($staged, $target)) {
            self::remove($staged);
        }
    }

    /**
     * Outside `typedpress-app-<name>/public/`, only {@see PUBLIC} asset extensions are mirrored
     * (never PHP, SQL, manifests or app metadata JSON). Inside `public/`, every non-dotfile static
     * file except {@see REFUSED} code/config extensions is mirrored so public files (including JSON,
     * ZIPs, text, PDFs, HTML) serve directly from `wp-content/typedpress-assets/`.
     */
    private static function mirrorable(string $kind, string $relative): bool
    {
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if ($kind === 'app' && preg_match('#\Atypedpress-app-[a-z0-9-]+/public/(.+)\z#', $relative, $m) === 1) {
            $sub = $m[1];
            foreach (explode('/', $sub) as $segment) {
                if ($segment === '' || str_starts_with($segment, '.') || str_contains($segment, ':') || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                    return false;
                }
            }
            $base = strtolower(basename($sub));
            if (in_array($base, self::REFUSED, true)) {
                return false;
            }
            foreach (explode('.', $base) as $part) {
                if (in_array($part, self::REFUSED, true)) {
                    return false;
                }
            }

            return true;
        }

        return in_array($ext, self::PUBLIC, true);
    }

    public static function staging(string $root, string $purpose): string
    {
        return $root . '/.tmp/' . $purpose . '-' . bin2hex(random_bytes(8));
    }

    /** Write a small file by rename, so a reader sees the old bytes or the new ones. */
    public static function write(string $root, string $relative, string $bytes): void
    {
        $staged = self::staging($root, 'file');
        if (file_put_contents($staged, $bytes) === false || !rename($staged, $root . '/' . $relative)) {
            @unlink($staged);
            throw new \RuntimeException("Cannot write $relative in the TypedPress build store");
        }
    }

    /**
     * The compiler's tree digest (`src/store.rs`): for each file in path order, its path, a zero byte,
     * its length as 8 big-endian bytes, then its bytes. A link or special file has no digest.
     */
    public static function digest(string $dir): string
    {
        $files = [];
        self::walk($dir, '', $files);
        sort($files, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($files as $relative) {
            $path = $dir . '/' . $relative;
            hash_update($hash, $relative . "\0" . pack('J', filesize($path)));
            hash_update_file($hash, $path);
        }

        return hash_final($hash);
    }

    /** @param list<string> $out */
    private static function walk(string $dir, string $prefix, array &$out): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path)) {
                throw new \RuntimeException("$prefix$name is a link");
            }
            if (is_dir($path)) {
                self::walk($path, $prefix . $name . '/', $out);
            } elseif (is_file($path)) {
                $out[] = $prefix . $name;
            } else {
                throw new \RuntimeException("$prefix$name is not a file");
            }
        }
    }

    /**
     * Unpack an uploaded ZIP and publish it as `<kind>/<id>` when its digest is `<id>`. A tree that is
     * already in the store is left as it is. Every entry name is checked before anything is written.
     */
    public static function publish(string $root, string $kind, string $id, string $zip): void
    {
        $target = $root . '/' . $kind . '/' . $id;
        if (is_dir($target)) {
            return;
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP\'s zip extension is required to take a deployment');
        }
        $archive = new \ZipArchive();
        if ($archive->open($zip, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException("The uploaded $kind tree is not a ZIP archive");
        }
        $staged = self::staging($root, $kind);
        try {
            if ($archive->numFiles > self::FILES) {
                throw new \RuntimeException("The uploaded $kind tree has more files than a tree may hold (" . self::FILES . ')');
            }
            $bytes = 0;
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = (string) $archive->getNameIndex($i);
                $bytes += (int) ($archive->statIndex($i)['size'] ?? 0);
                if ($bytes > self::BYTES) {
                    throw new \RuntimeException("The uploaded $kind tree unpacks to more than a tree may hold (" . intdiv(self::BYTES, 1048576) . ' MB)');
                }
                $segments = explode('/', rtrim($name, '/'));
                if ($name === '' || $name[0] === '/' || str_contains($name, '\\') || str_contains($name, "\0")
                    || array_filter($segments, static fn (string $s): bool => $s === '' || $s === '.' || $s === '..') !== []) {
                    throw new \RuntimeException("The uploaded $kind tree has an unsafe path");
                }
                $system = 0;
                $attributes = 0;
                // Unix entries carry the file type in the high bits: only files and directories are taken.
                if ($archive->getExternalAttributesIndex($i, $system, $attributes) && $system === \ZipArchive::OPSYS_UNIX
                    && !in_array(($attributes >> 16) & 0170000, [0, 0100000, 0040000], true)) {
                    throw new \RuntimeException("The uploaded $kind tree has a link or special file");
                }
            }
            // Unpacked entry by entry, counting the bytes that come out: the sizes an archive declares
            // are its own claim, and one that understates them is stopped at the same bound.
            if (!mkdir($staged, 0755)) {
                throw new \RuntimeException("Cannot unpack the uploaded $kind tree");
            }
            $written = 0;
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = (string) $archive->getNameIndex($i);
                $path = $staged . '/' . rtrim($name, '/');
                if (str_ends_with($name, '/')) {
                    if (!is_dir($path) && !mkdir($path, 0755, true)) {
                        throw new \RuntimeException("Cannot unpack the uploaded $kind tree");
                    }
                    continue;
                }
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
                    throw new \RuntimeException("Cannot unpack the uploaded $kind tree");
                }
                $in = $archive->getStreamIndex($i);
                $out = $in === false ? false : fopen($path, 'xb');
                if ($in === false || $out === false) {
                    throw new \RuntimeException("Cannot unpack the uploaded $kind tree");
                }
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 1048576);
                        if ($chunk === false) {
                            throw new \RuntimeException("Cannot unpack the uploaded $kind tree");
                        }
                        $written += strlen($chunk);
                        if ($written > self::BYTES) {
                            throw new \RuntimeException("The uploaded $kind tree unpacks to more than a tree may hold (" . intdiv(self::BYTES, 1048576) . ' MB)');
                        }
                        if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException("Cannot unpack the uploaded $kind tree");
                        }
                    }
                } finally {
                    fclose($in);
                    fclose($out);
                }
            }
            $archive->close();
            $digest = self::digest($staged);
            if (!hash_equals($id, $digest)) {
                throw new \RuntimeException("The uploaded $kind tree is not the one the build names (its contents hash to $digest)");
            }
            // Another upload of the same tree may have finished first; the trees are identical.
            if (!is_dir($target) && !rename($staged, $target)) {
                throw new \RuntimeException("Cannot publish the $kind tree");
            }
        } finally {
            self::remove($staged);
        }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            @rmdir($path);
        }
    }
}
