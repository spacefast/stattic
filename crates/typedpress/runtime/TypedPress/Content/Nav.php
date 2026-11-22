<?php

declare(strict_types=1);

namespace TypedPress\Content;

use TypedPress\Db;
use TypedPress\JsArray;
use TypedPress\JsObject;
use TypedPress\JsUndefined;
use TypedPress\Seam;
use TypedPress\Wp\KindUrls;
use WP_HTML_Tag_Processor as Tags;

/**
 * `"typedpress/content"` at run time (docs/decisions/2026-11-13-docs-kind.md): the navigation tree,
 * previous and next, and the headings of a hierarchical kind's items.
 *
 * The tree holds the published items the visitor may read (their read rule and field masks
 * apply, as for every query), even when the visitor could also read drafts: a page's navigation
 * is the published site, the same for an editor as for the cached anonymous page. Siblings sort
 * by `order`, then title. An item whose parent the visitor may not read is left out with it.
 */
final class Nav
{
    /** Items read per tree: a docs section larger than this is a sign the kind wants paging. */
    private const LIMIT = 2000;

    /** @var array<string, list<array{id: int, title: string, parent: ?int, href: string}>> per kind, per request */
    private static array $items = [];
    /** @var array<string, list<string>> each heading's id, in order, by the hash of the block markup headings() read */
    private static array $anchored = [];

    /** The id of the current item: a row, an id, or nothing. */
    public static function id(mixed $row): ?int
    {
        if ($row === null || $row === JsUndefined::Value) {
            return null;
        }
        $row = Seam::container($row);
        return is_array($row) && is_int($row['id'] ?? null) ? $row['id'] : null;
    }

    /** @return list<array{id: int, title: string, parent: ?int, href: string}> sorted siblings first by order then title */
    private static function items(string $kind): array
    {
        $viewer = Seam::viewer();
        // Trusted code (no viewer) and an anonymous visitor read different rows: never share a key.
        $key = $kind . ':' . ($viewer === null ? 'trusted' : ($viewer->user['id'] ?? 0));
        if (isset(self::$items[$key])) {
            return self::$items[$key];
        }
        $spec = Db::instance()->schema()->collection($kind);
        $where = ($spec['drafts'] ?? false) ? ['status' => 'published'] : [];
        $rows = Db::instance()->findMany($kind, [
            'where' => $where,
            // The id breaks ties, so which items a section past the limit leaves out is stable.
            'orderBy' => ['order' => 'asc', $spec['primaryKey'] => 'asc'],
            'limit' => self::LIMIT,
            'columns' => [$spec['primaryKey'] => true, $spec['title'] => true, 'parent' => true, 'order' => true],
        ], $viewer);
        $urls = KindUrls::instance();
        // One read for every item's post (and what its URL is built from), not one per item.
        $urls?->prime($kind, array_map(static fn (array $row): int => (int) $row[$spec['primaryKey']], $rows));
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row[$spec['primaryKey']];
            $post = get_post($id);
            $path = $post instanceof \WP_Post && $urls !== null ? $urls->path($kind, $post) : null;
            if ($path === null) {
                continue;
            }
            $out[] = ['id' => $id, 'title' => (string) $row[$spec['title']], 'parent' => $row['parent'] === null ? null : (int) $row['parent'], 'order' => (int) $row['order'], 'href' => $path];
        }
        // Order, then title, so siblings with the same order keep a stable, readable order.
        // Titles compare as people read them: case aside, numbers by value ("Step 9" before "Step 10").
        usort($out, static fn (array $a, array $b): int => [$a['order'], strnatcasecmp($a['title'], $b['title']), $a['id']] <=> [$b['order'], 0, $b['id']]);

        return self::$items[$key] = $out;
    }

    /** @return array<int, list<array>> parent id (0 for the top) => children, sorted */
    private static function byParent(array $items): array
    {
        $ids = array_flip(array_column($items, 'id'));
        $out = [];
        foreach ($items as $item) {
            $parent = $item['parent'] ?? 0;
            if ($parent !== 0 && !isset($ids[$parent])) {
                continue; // under an item the visitor can't see: not reachable, so not listed
            }
            $out[$parent][] = $item;
        }
        return $out;
    }

    /** `nav(kind, current?)`: the tree, `$depth` levels deep, marking the current item and its ancestors. */
    public static function tree(string $kind, mixed $current, int $depth): JsArray
    {
        $current = is_int($current) ? $current : (is_float($current) ? (int) $current : null);
        $children = self::byParent(self::items($kind));
        $build = static function (int $parent, int $depth) use (&$build, $children, $current): array {
            $out = [];
            foreach ($children[$parent] ?? [] as $item) {
                $below = $depth > 1 ? $build($item['id'], $depth - 1) : [];
                $open = $item['id'] === $current || array_filter($below, static fn (JsObject $c): bool => $c->p['current'] || $c->p['open']) !== [];
                // Every level has `children`; below the last level the list is empty.
                $out[] = new JsObject(['id' => $item['id'], 'title' => $item['title'], 'href' => $item['href'], 'current' => $item['id'] === $current, 'open' => $open, 'children' => new JsArray($below)]);
            }
            return $out;
        };

        return new JsArray($build(0, $depth));
    }

    /** `pager(kind, current)`: the items before and after the current one, reading the tree top to bottom. */
    public static function pager(string $kind, mixed $current): JsObject
    {
        $current = is_int($current) ? $current : (is_float($current) ? (int) $current : null);
        $children = self::byParent(self::items($kind));
        $flat = [];
        $walk = static function (int $parent) use (&$walk, &$flat, $children): void {
            foreach ($children[$parent] ?? [] as $item) {
                $flat[] = $item;
                $walk($item['id']);
            }
        };
        $walk(0);
        $at = null;
        foreach ($flat as $i => $item) {
            if ($item['id'] === $current) {
                $at = $i;
            }
        }
        $link = static fn (?array $item): ?JsObject => $item === null ? null : new JsObject(['id' => $item['id'], 'title' => $item['title'], 'href' => $item['href']]);

        return new JsObject([
            'prev' => $at === null ? null : $link($flat[$at - 1] ?? null),
            'next' => $at === null ? null : $link($flat[$at + 1] ?? null),
        ]);
    }

    /**
     * `headings(item)`: the item's `##` and `###` headings in order, with the anchor id the
     * rendered page gives each one (headingIds()), for a table of contents.
     */
    public static function headings(mixed $row, string $column): JsArray
    {
        $row = Seam::container($row);
        $blocks = is_array($row) && is_string($row[$column] ?? null) ? $row[$column] : '';
        $html = self::render($blocks);
        $scanned = self::scan($html);
        if ($html !== '') {
            self::listen();
            // `the_content` gives the page's headings these ids, computed here once: its later
            // filters (wptexturize turns `--` and ` - ` into dashes) must not change them.
            self::$anchored[md5($blocks)] = array_column($scanned, 2);
        }
        $out = [];
        foreach ($scanned as [$depth, $text, $id]) {
            if ($depth >= 2 && $depth <= 3) {
                $out[] = new JsObject(['depth' => $depth, 'text' => $text, 'id' => $id]);
            }
        }

        return new JsArray($out);
    }

    /** The block markup as `the_content` shows it, so headings in nested blocks count too. */
    private static function render(string $blocks): string
    {
        return $blocks === '' ? '' : do_blocks($blocks);
    }

    /**
     * `the_content`, once headings() ran in this request: the same content, rendered for the page
     * (`<Content value={item.content} />`), gets the ids headings() reported, so its links land.
     * The content is recognised by its block markup, as `the_content` receives it, not by its
     * rendered HTML: blocks with per-request ids (gallery, navigation, search) render differently
     * each time. The ids are added last, after shortcodes and embeds, counting headings in order.
     */
    private static function listen(): void
    {
        static $on = false;
        if ($on) {
            return;
        }
        $on = true;
        $marked = null;
        add_filter('the_content', static function (string $source) use (&$marked): string {
            $marked = self::$anchored[md5($source)] ?? null;
            return $source;
        }, PHP_INT_MIN);
        add_filter('the_content', static function (string $html) use (&$marked): string {
            $ids = $marked;
            $marked = null;
            return $ids === null ? $html : self::headingIds($html, $ids);
        }, PHP_INT_MAX);
    }

    /**
     * Every heading in rendered HTML: (level, text, id). A heading with its own id keeps it as it
     * is; any other gets one from its text (slug()), made unique in the page with `-1`, `-2`, … as
     * GitHub does, so a link to a heading of a README or an earlier docs site lands on the same one.
     * An own id counts as taken, so no generated id repeats it.
     *
     * @return list<array{int, string, string}>
     */
    private static function scan(string $html): array
    {
        $out = [];
        $seen = [];
        $doc = \WP_HTML_Processor::create_fragment($html);
        if ($doc === null) {
            return [];
        }
        $level = 0;
        $text = '';
        $own = null;
        while ($doc->next_token()) {
            $tag = $doc->get_tag();
            if ($doc->get_token_type() === '#tag' && is_string($tag) && preg_match('/^H([1-6])$/', $tag, $m) === 1) {
                if ($doc->is_tag_closer()) {
                    if ($level > 0) {
                        $out[] = [$level, $text, $own];
                    }
                    $level = 0;
                    continue;
                }
                $level = (int) $m[1];
                $text = '';
                $own = $doc->get_attribute('id');
                $own = is_string($own) && $own !== '' ? $own : null;
                continue;
            }
            if ($level > 0 && $doc->get_token_type() === '#text') {
                $text .= $doc->get_modifiable_text();
            }
        }
        foreach ($out as [, , $own]) {
            if ($own !== null) {
                $seen[$own] = true;
            }
        }
        foreach ($out as $i => [$depth, $raw, $own]) {
            // The id from the text as written (CommonMark trims a heading's ends); the text shown
            // in a table of contents with its runs of white space as one space.
            $id = $own;
            if ($id === null) {
                $base = self::slug(trim($raw, " \t\n\r\f"));
                $id = $base;
                for ($n = 1; isset($seen[$id]); $n++) {
                    $id = "$base-$n";
                }
                $seen[$id] = true;
            }
            $out[$i] = [$depth, trim((string) preg_replace('/\s+/u', ' ', $raw)), $id];
        }

        return $out;
    }

    /**
     * A heading's id from its text, by GitHub's rule (github-slugger, which GitHub, Astro and most
     * docs tools use): lowercase; keep letters, marks, digits, letter numbers, connector
     * punctuation (`_`), hyphens and spaces, and the enclosed letters it keeps (Ⓐ, 🅐); drop every
     * other character; then each space is a hyphen. `What's tp/app/?` is `whats-firstapp`, and
     * `C++ & Rust` is `c--rust`. A heading whose text keeps nothing is `section`.
     *
     * Two differences remain, both outside what headings say in practice: letters added to Unicode
     * after github-slugger's table (Unicode 13) are kept rather than dropped, and on PHP 8.2 a word
     * ending in a capital sigma lowercases to `σ`, not `ς` (8.3 follows JavaScript).
     */
    public static function slug(string $text): string
    {
        $kept = (string) preg_replace('/[^\p{L}\p{M}\p{Nd}\p{Nl}\p{Pc}\x{24B6}-\x{24E9}\x{1F130}-\x{1F149}\x{1F150}-\x{1F169}\x{1F170}-\x{1F189} \-]/u', '', mb_strtolower($text));
        $slug = str_replace(' ', '-', $kept);

        return $slug === '' ? 'section' : $slug;
    }

    /**
     * `the_content` for an item headings() read: every `h1`–`h6` without an id gets the one
     * headings() reported for it, in order, so a table of contents links land.
     *
     * @param list<string> $ids
     */
    private static function headingIds(string $html, array $ids): string
    {
        if (!str_contains($html, '<h')) {
            return $html;
        }
        $tags = new Tags($html);
        $i = 0;
        while ($tags->next_tag()) {
            if (preg_match('/^H[1-6]$/', (string) $tags->get_tag()) !== 1) {
                continue;
            }
            if ($tags->get_attribute('id') === null && isset($ids[$i])) {
                $tags->set_attribute('id', $ids[$i]);
            }
            $i++;
        }

        return $tags->get_updated_html();
    }
}
