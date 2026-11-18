<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Extensions;
use TypedPress\SystemTables;
use TypedPress\Wp\CompositeStore;

use function TypedPress\db;

/**
 * Cache policy and URL purges for `tp/app/` pages (docs/spec/rendering.md §6.1, §6.2).
 *
 * Policy, per response, from what the render was:
 * - an anonymous visitor's 200 render of a page no layout guards is **cacheable**:
 *   `Cache-Control: public, max-age=0, s-maxage=<window>, must-revalidate`, so Batcache and the
 *   edge keep it for everyone (a twin is always rendered as an anonymous visitor too). `s-maxage`
 *   comes after `max-age`: Batcache reads the last `max-age` in the header as its own window;
 * - anything else (a signed-in viewer, a guarded section, an error, a 404) is
 *   `private, no-store`, and calls `batcache_cancel()` where Batcache runs.
 *
 * Purges: each cacheable render records the rows it read in `tp__app_reads` (kind, id or `*`
 * for a listing, URL path with its query), under the address WordPress serves the page at
 * (`user_trailingslashit`), whichever spelling the visitor typed. On a write to a kind, the URLs
 * that read that row, and every URL that listed the kind, join the write's other purges
 * (`Wp\Purges::recorded`): one `tp_purge_urls` call after the commit, with their `.md` twins.
 * Nothing is recorded or announced when no host listens (`has_action('tp_purge_urls')`); the
 * runtime listens itself where Batcache, the VIP edge cache or Jetpack Boost runs (`Wp\PageCaches`).
 */
final class Cache
{
    /** The edge window for a cacheable page, in seconds; `tp_app_cache_window` changes it. */
    public const WINDOW = 300;
    /** Rows recorded per render; a page that reads more records its kinds as listings. */
    public const MAX_IDS = 500;
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
            return ['private, no-store, max-age=0', true];
        }
        if ($status === 200 && $anonymous && !$guarded) {
            $window = max(0, (int) apply_filters('tp_app_cache_window', self::WINDOW));
            return ["public, max-age=0, s-maxage=$window, must-revalidate", true];
        }
        return ['private, no-store, max-age=0', false];
    }

    /**
     * Records what a cacheable render of `$uri` read, replacing what that URL read before.
     *
     * @param array<string, array{ids: list<int|string>, listed: bool}> $reads
     */
    public static function record(string $uri, array $reads): void
    {
        // A prerender records what it read, so a write knows which prebuilt URLs it changed; other
        // renders only where a host's page cache listens for purges.
        if (!(function_exists('has_action') && has_action('tp_purge_urls') !== false) && !Prebuilt::prerendering()) {
            return;
        }
        $url = self::path($uri);
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
        $wanted = [];
        foreach ($rows as [$kind, $item]) {
            $wanted[$kind . "\0" . $item] = [$kind, $item];
        }
        // The same reads as the URL's last render (the usual case: a page re-rendered after its cache
        // window): nothing to write.
        $stored = [];
        foreach (self::run('SELECT "kind", "item" FROM {reads} WHERE "url" = ?', [$url]) as $row) {
            $stored[$row['kind'] . "\0" . $row['item']] = true;
        }
        if (array_diff_key($wanted, $stored) === [] && array_diff_key($stored, $wanted) === []) {
            return;
        }
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
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
    }

    /** Extensions::onWritten: inside the write's transaction; Wp\Purges announces after commit. */
    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        if (!self::listening()) {
            return;
        }
        $spec = db()->schema()->collections()[$collection] ?? null;
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
     * visit to `/notes/a`), with its query string. A `.md` twin keeps its own address.
     */
    private static function path(string $uri): string
    {
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($uri, PHP_URL_QUERY) ?? '');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return substr(\TypedPress\Wp\AppRouter::address($path) . ($query !== '' ? '?' . $query : ''), 0, 512);
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
