<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Extensions;
use TypedPress\SystemTables;
use TypedPress\Wp\CompositeStore;
use TypedPress\Wp\LockBusy;

use function TypedPress\db;

/**
 * Cache policy and URL purges for `tp/app/` pages (docs/spec/rendering.md §6.1, §6.2).
 *
 * Policy, per response, from what the render was:
 * - an anonymous visitor's 200 render of a page no layout guards, to a request with no viewer cookie
 *   ({@see viewerCookie()}), is **cacheable**:
 *   `Cache-Control: public, max-age=0, s-maxage=<window>, must-revalidate` and
 *   `A8C-Edge-Cache: cache`, so Batcache and the edge (WP Cloud's included) keep it for everyone (a
 *   twin is always rendered as an anonymous visitor too). `s-maxage` comes after `max-age`: Batcache
 *   reads the last `max-age` in the header as its own window;
 * - anything else (a signed-in viewer, a viewer cookie, a guarded section, an error, a 404, a
 *   response that sets a cookie) is
 *   `private, no-store` with `A8C-Edge-Cache: no-cache`, and calls `batcache_cancel()` where
 *   Batcache runs ({@see send()}).
 *
 * Purges: each cacheable render records the rows it read in `tp__app_reads` (kind, id or `*`
 * for a listing, URL path with its query), under the address WordPress serves the page at
 * (`user_trailingslashit`), whichever spelling the visitor typed. A global it read is recorded as
 * the kind `global:<name>` with `*`. On a write to a kind, the URLs that read that row, and every
 * URL that listed the kind, join the write's other purges (`Wp\Purges::recorded`): one
 * `tp_purge_urls` call after the commit, with their `.md` twins; a write to a global announces the
 * URLs that read it ({@see globalWritten()}).
 * Nothing is recorded or announced when no host listens (`has_action('tp_purge_urls')`); the
 * runtime listens itself where Batcache, the VIP edge cache or Jetpack Boost runs (`Wp\PageCaches`).
 *
 * Query strings ({@see shareable()}, {@see record()}): a URL is shared only when every parameter
 * in its query is one the page declares (`export const search`), since a cache keeps each query
 * string as a page of its own and only a recorded URL can be purged. A page's recorded query
 * variants are capped at MAX_VARIANTS; one more is answered privately.
 */
final class Cache
{
    /** The edge window for a cacheable page, in seconds; `tp_app_cache_window` changes it. */
    public const WINDOW = 300;
    /** Rows recorded per render; a page that reads more records its kinds as listings. */
    public const MAX_IDS = 500;
    /**
     * Query variants of one path recorded at a time (`/catalog/?media=nvme`, `?media=ssd`, …). Each
     * is a URL of its own to purge, with its twin: 20 variants of a listing are 40 of the
     * `Wp\Purges::PURGE_LIMIT` paths one write may name before it purges the whole host.
     */
    public const MAX_VARIANTS = 20;
    /** Named locks that admit query variants; a path always takes the same one. */
    private const LOCK_BUCKETS = 16;
    /** The unique index covers the first 191 characters. Longer keys can alias another URL. */
    private const MAX_URL = 191;
    /** Rows per insert when recording reads (at most about 900 bytes each). */
    private const BATCH = 100;

    public static function register(): void
    {
        $text = 'COLLATE utf8mb4_bin';
        $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        // `item` is the row's id as text, or `*` for "this URL listed the kind".
        SystemTables::register('__tp_app_reads', [
            "CREATE TABLE IF NOT EXISTS `{prefix}tp__app_reads` (`kind` VARCHAR(191) $text NOT NULL, `item` VARCHAR(191) $text NOT NULL, `url` VARCHAR(512) $text NOT NULL, `seenAt` CHAR(24) NOT NULL, PRIMARY KEY (`kind`, `item`, `url`(191)), KEY `url` (`url`(191))) $tail",
        ]);
        Extensions::onWritten(self::written(...));
    }

    /** A host's page cache listens for purges, or prebuilt pages need to know what changed. */
    private static function listening(): bool
    {
        return function_exists('has_action') && (has_action('tp_purge_urls') !== false || Prebuilt::active());
    }

    /**
     * The `Cache-Control` for a router response, and whether Batcache may keep it.
     *
     * @return array{string, bool}
     */
    public static function policy(int $status, bool $anonymous, bool $guarded): array
    {
        // A prerender (Prebuilt) is recorded like a cacheable render, and kept by no shared cache:
        // the file it becomes is the copy that is served.
        if ($status === 200 && $anonymous && !$guarded && Prebuilt::prerendering()) {
            return [self::PRIVATE, true];
        }
        if ($status === 200 && $anonymous && !$guarded) {
            $window = max(0, (int) apply_filters('tp_app_cache_window', self::WINDOW));
            return ["public, max-age=0, s-maxage=$window, must-revalidate", true];
        }
        return [self::PRIVATE, false];
    }

    /**
     * Sends a router response's `Cache-Control` with the WP Cloud edge's own directive beside it. That
     * edge stores a PHP response only with `A8C-Edge-Cache: cache` and a positive shared TTL, and
     * `no-cache` keeps it from storing one whatever else says it may; other hosts ignore the header.
     * Derived from the `Cache-Control` sent, so the two never disagree.
     */
    public static function send(string $cacheControl): void
    {
        // A response that sets a cookie is this visitor's (Batcache refuses to keep one too).
        if (str_starts_with($cacheControl, 'public') && preg_grep('/^set-cookie:/i', headers_list()) !== []) {
            $cacheControl = self::PRIVATE;
        }
        header('Cache-Control: ' . $cacheControl);
        header(self::EDGE_HEADER . ': ' . (str_starts_with($cacheControl, 'public') ? 'cache' : 'no-cache'));
        // Batcache reads only `max-age`, so it would keep this response for its default window.
        if (!str_starts_with($cacheControl, 'public') && function_exists('batcache_cancel')) {
            batcache_cancel();
        }
    }

    /** The WP Cloud edge's per-response cache switch. */
    public const EDGE_HEADER = 'A8C-Edge-Cache';
    public const PRIVATE = 'private, no-store, max-age=0';

    /**
     * The request carries a cookie a render may answer differently for: a WordPress or Identity
     * sign-in, an unlocked password-protected post, a comment author's saved name and email, a login
     * language. Such a request is never given, or served, a shared copy, whoever WordPress says it is.
     */
    public static function viewerCookie(): bool
    {
        foreach (array_keys($_COOKIE) as $name) {
            if (preg_match('/^(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|wp_lang$|zi_session$|__Host-zi_session$)/', (string) $name) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a response to `$uri` may be shared as far as its query string goes: every parameter is
     * one the page declares, and the address fits the read index. A shared cache keeps each query
     * string as its own page, so `/?junk=1` … `/?junk=500` would be 500 copies of one page, each
     * needing its own purge. A request with any other parameter is answered, privately.
     *
     * @param list<string> $declared the page's `search` field names
     */
    public static function shareable(string $uri, array $declared): bool
    {
        // A cache keeps the URI as sent, and a purge names the path the router served: `//shop/a/`
        // is the page `/shop/a/` to the router (AppRouter::requestPath) but another URL to a cache,
        // so no purge would reach that copy. Only a URI that starts with its own path is shared.
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            return false;
        }
        $query = (string) (wp_parse_url($uri, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            // `/about/?` is another URL to a cache, and the index records it as `/about/`.
            return !str_contains($uri, '?') && strlen(self::path($uri)) <= self::MAX_URL;
        }
        foreach (\TypedPress\QueryString::parse($query) as [$name]) {
            if (!in_array($name, $declared, true)) {
                return false;
            }
        }

        return strlen(self::path($uri)) <= self::MAX_URL;
    }

    /**
     * Records what a cacheable render of `$uri` read, replacing what that URL read before. False
     * when the URL can't be recorded, so it can't be purged and its response must not be shared: its
     * query string is not the page's own ({@see shareable()}), or the path already has MAX_VARIANTS
     * query variants on record.
     *
     * @param array<string, array{ids: list<int|string>, listed: bool}> $reads
     * @param list<string> $also what the render read besides rows: `global:<name>` (Reads::during)
     * @param list<string> $declared the page's `search` field names
     */
    public static function record(string $uri, array $reads, array $also = [], array $declared = []): bool
    {
        if (!self::shareable($uri, $declared)) {
            return false;
        }
        // A prerender records what it read, so a write knows which prebuilt URLs it changed; other
        // renders only where a host's page cache listens for purges.
        if (!(function_exists('has_action') && has_action('tp_purge_urls') !== false) && !Prebuilt::prerendering()) {
            return true;
        }
        $url = self::path($uri);
        if (!str_contains($url, '?')) {
            return self::recordUrl($url, $reads, $also, false);
        }
        $store = db()->store();
        if (!$store instanceof CompositeStore) {
            return false;
        }
        // The limit counts URLs across rows. Admit variants under the same lock even when two
        // requests with different query strings arrive at the last free slot. The limit is per
        // path, so the lock is too: a variant of one page never waits on another page's. Paths
        // share LOCK_BUCKETS locks, which bounds the lock files beside a SQLite database.
        $bucket = crc32(substr($url, 0, (int) strpos($url, '?'))) % self::LOCK_BUCKETS;
        $lock = 'tp:page-variants:' . substr(hash('sha256', DB_NAME . ':' . $store->db->prefix() . ':' . $bucket), 0, 40);
        try {
            return $store->db->locked($lock, 0, static fn (): bool => self::recordUrl($url, $reads, $also, true));
        } catch (LockBusy) {
            return false;
        } catch (\RuntimeException $error) {
            // The lock itself failed (GET_LOCK answered NULL, the SQLite lock file can't be opened or
            // locked): the page has rendered, so it is answered, privately, rather than as an error.
            error_log('TypedPress: could not record the reads of ' . $url . ' for page cache purges: ' . $error->getMessage());
            return false;
        }
    }

    private static function recordUrl(string $url, array $reads, array $also, bool $variant): bool
    {
        $rows = [];
        foreach ($reads as $kind => $read) {
            if ($read['listed'] || count($read['ids']) > self::MAX_IDS) {
                $rows[] = [$kind, '*'];
            }
            if (count($read['ids']) <= self::MAX_IDS) {
                foreach ($read['ids'] as $id) {
                    $rows[] = [$kind, (string) $id];
                }
            }
        }
        foreach ($also as $key) {
            $rows[] = [$key, '*'];
        }
        if ($variant) {
            // Count an admitted query URL even if this render read no data.
            $rows[] = ['', '*'];
        }
        $wanted = [];
        foreach ($rows as [$kind, $item]) {
            $wanted[$kind . "\0" . $item] = [$kind, $item];
        }
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        // The same reads as the URL's last render (the usual case: a page re-rendered after its cache
        // window): nothing to write.
        $stored = [];
        $seen = null;
        foreach (self::run('SELECT "kind", "item", "seenAt" FROM {reads} WHERE "url" = ?', [$url]) as $row) {
            $stored[$row['kind'] . "\0" . $row['item']] = true;
            $seen = $seen === null ? (string) $row['seenAt'] : min($seen, (string) $row['seenAt']);
        }
        if (array_diff_key($wanted, $stored) === [] && array_diff_key($stored, $wanted) === []) {
            // A query variant still being rendered keeps its place among its path's variants.
            if ($variant && $seen !== null && $seen < self::ago(self::window())) {
                self::run('UPDATE {reads} SET "seenAt" = ? WHERE "url" = ?', [$now, $url]);
            }
            return true;
        }
        if ($variant && $stored === [] && !self::room($url)) {
            return false;
        }
        self::run('DELETE FROM {reads} WHERE "url" = ?', [$url]);
        // Multi-row inserts, BATCH rows each. A row's parameters are at most (191 + 191 + 512)
        // characters of utf8mb4, 4 bytes each, plus the 24-byte timestamp: 3,600 bytes, so a statement
        // carries at most 100 × 3,600 = 360,000 bytes of parameters. That is below the default
        // max_allowed_packet of MySQL 8 (64 MiB) and MariaDB (16 MiB); it is not checked against the
        // server's own setting, so a server set below about 360 KB would refuse a full batch.
        foreach (array_chunk(array_values($wanted), self::BATCH) as $chunk) {
            $params = [];
            foreach ($chunk as [$kind, $item]) {
                array_push($params, $kind, $item, $url, $now);
            }
            self::run('INSERT IGNORE INTO {reads} ("kind", "item", "url", "seenAt") VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)')), $params);
        }

        return true;
    }

    /**
     * Whether a path has room for one more recorded query variant. At the limit, the variants no
     * render has recorded or confirmed for two cache windows go first: no shared cache still holds a
     * copy it considers fresh.
     */
    private static function room(string $url): bool
    {
        // Every recorded URL from `<path>?` up to, not including, `<path>@` (the byte after `?`).
        $path = substr($url, 0, (int) strpos($url, '?'));
        $range = [$path . '?', $path . '@'];
        $count = static fn (): int => (int) (self::run('SELECT COUNT(DISTINCT "url") AS n FROM {reads} WHERE "url" >= ? AND "url" < ?', $range)[0]['n'] ?? 0);
        if ($count() < self::MAX_VARIANTS) {
            return true;
        }
        self::run('DELETE FROM {reads} WHERE "url" >= ? AND "url" < ? AND "seenAt" < ?', [...$range, self::ago(2 * self::window())]);

        return $count() < self::MAX_VARIANTS;
    }

    /** The cache window in seconds, at least WINDOW: how long a shared copy may be served as fresh. */
    private static function window(): int
    {
        return max(self::WINDOW, (int) apply_filters('tp_app_cache_window', self::WINDOW));
    }

    private static function ago(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', time() - $seconds);
    }

    /**
     * A global was written (`db.globals.<name>.update()`, the API, or its options page in wp-admin,
     * all of which save its fields as options): the URLs whose render read it join the write's
     * announcement, after the commit like a row's.
     */
    public static function globalWritten(string $name): void
    {
        if (!self::listening()) {
            return;
        }
        $found = self::run('SELECT DISTINCT "url" FROM {reads} WHERE "kind" = ? AND "item" = ?', ['global:' . $name, '*']);
        \TypedPress\Wp\Purges::recorded(array_map(static fn (array $r): string => self::address((string) $r['url']), $found));
    }

    /** Extensions::onWritten: inside the write's transaction; Wp\Purges announces after commit. */
    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        if (!self::listening()) {
            return;
        }
        $spec = db()->schema()->kinds()[$collection] ?? null;
        if ($spec === null) {
            return;
        }
        $pk = $spec['primaryKey'] ?? 'id';
        $id = $row[$pk] ?? $previous[$pk] ?? null;
        $found = self::run(
            'SELECT DISTINCT "url" FROM {reads} WHERE "kind" = ? AND ("item" = ? OR "item" = ?)',
            [$collection, '*', (string) $id],
        );
        \TypedPress\Wp\Purges::recorded(array_map(static fn (array $r): string => self::address((string) $r['url']), $found));
    }

    /**
     * The URLs whose cacheable render read row $id of $collection itself: the exact item, never the
     * pages that listed the kind (`*`). For a change that only shows where the item is shown (its
     * thread), not in a listing.
     *
     * @return list<string> absolute URLs, each recorded page at its one address
     */
    public static function itemUrls(string $collection, int|string $id): array
    {
        if (!self::listening()) {
            return [];
        }
        $found = self::run('SELECT DISTINCT "url" FROM {reads} WHERE "kind" = ? AND "item" = ?', [$collection, (string) $id]);

        return array_map(static fn (array $r): string => self::address((string) $r['url']), $found);
    }

    /**
     * The address a recorded URL is cached under, absolute: the page as WordPress links it, the one
     * spelling the router answers (the other redirects there). A row recorded before addresses were
     * stored canonically has the bare spelling, and gives the same address.
     */
    private static function address(string $url): string
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        return home_url(\TypedPress\Wp\AppRouter::address($path) . ($query !== '' ? '?' . $query : ''));
    }

    /**
     * A request URI as stored: the path below the home path as WordPress links it (`/notes/a/` for a
     * visit to `/notes/a`), with its query string. A `.md` twin keeps its own address. Never cut to
     * fit the column: a URL longer than MAX_URL is not recorded at all ({@see shareable()}).
     */
    private static function path(string $uri): string
    {
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        // The path the router served (`requestPath`), so a record is never filed under another page.
        $path = \TypedPress\Wp\AppRouter::requestPath($uri);
        $query = (string) (wp_parse_url($uri, PHP_URL_QUERY) ?? '');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return \TypedPress\Wp\AppRouter::address($path) . ($query !== '' ? '?' . $query : '');
    }

    /** @param list<int|float|string|null> $params @return list<array<string, mixed>> */
    private static function run(string $sql, array $params): array
    {
        $store = db()->store();
        if (!$store instanceof CompositeStore) {
            return [];
        }
        $sql = str_replace('{reads}', '"' . $store->db->prefix() . 'tp__app_reads"', $sql);
        return $store->db->run(str_replace('"', '`', $sql), $params);
    }
}
