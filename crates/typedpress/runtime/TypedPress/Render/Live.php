<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Html;

/**
 * `<Live>` regions (docs/decisions/2026-11-14-live-regions.md). A page renders each region through
 * {@see region()}: in a normal render that is the region's markup inside a marked element; when the
 * subscription endpoint renders one region of the page ({@see capture()}), it also records the
 * route's reads, the region's HTML, and whether anything in it depends on data TypedPress can't count
 * ({@see unseen()}), so the endpoint can answer "not modified" by counter only when that is safe.
 */
final class Live
{
    /** @var ?array{id: string, html: ?string, reads: array, unseen: bool, found: bool} the region the endpoint asked for */
    private static ?array $capture = null;

    /** @var ?array{regions: array<string, array{string, string}>, notices: array<string, true>} */
    private static ?array $page = null;

    /** @var list<string> region ids this page rendered, for the browser module */
    private static array $rendered = [];

    /**
     * A `<Live>` region: its children rendered now, in an element the browser keeps current. In a
     * page's own render the element also carries the region's first answer for the subscription
     * (`data-tp-live-seed`). The browser checks the displayed HTML on its first subscription request
     * and uses the recorded kinds to react to later change notices.
     */
    public static function region(string $id, \Closure $render): Html
    {
        if (self::$capture !== null) {
            $html = Html::child($render());
            if (self::$capture['id'] === $id) {
                self::$capture['html'] = $html;
                self::$capture['found'] = true;
            }

            return Html::element('div', ['data-tp-live' => $id], Html::raw($html));
        }
        self::$rendered[] = $id;
        $html = Html::child($render());
        if (self::$page === null) {
            return Html::element('div', ['data-tp-live' => $id], Html::raw($html));
        }
        // Later parts of the route can record dependencies (e.g. the comments notice).
        // Fill this attribute once the entire page has rendered.
        try {
            $marker = bin2hex(random_bytes(16));
        } catch (\Random\RandomException) {
            return Html::element('div', ['data-tp-live' => $id], Html::raw($html));
        }
        self::$page['regions'][' data-tp-live-seed="' . $marker . '"'] = [$id, $html];

        return Html::element('div', ['data-tp-live' => $id, 'data-tp-live-seed' => $marker], Html::raw($html));
    }

    /** Render the HTTP page once and seed its regions from the exact HTML and reads it produced. */
    public static function page(\TypedPress\Db $db, ?\TypedPress\Viewer $viewer, callable $render, ?array &$reads = null, ?array &$also = null): array
    {
        $previous = self::$page;
        self::$page = $viewer === null ? null : ['regions' => [], 'notices' => []];
        try {
            [$head, $body] = Reads::during($render, $reads, $also);
            if ($viewer === null || self::$page['regions'] === []) {
                return [$head, $body];
            }
            $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $url = rtrim(preg_replace('~([?&])_flash=[0-9a-f]+(&|$)~', '$1', $url) ?? $url, '?&');
            $attrs = array_fill_keys(array_keys(self::$page['regions']), '');
            $seeds = $attrs;
            if (\TypedPress\Live\Module::validPageUrl($url)) {
                $kinds = array_keys($reads);
                $notices = array_keys(self::$page['notices']);
                foreach (self::$page['regions'] as $attr => [$id, $html]) {
                    // The endpoint renders nested regions without their browser seed attributes.
                    $seed = \TypedPress\Live\Module::pageSeed($db, $viewer, $url, $id, strtr($html, $attrs), $kinds, $notices);
                    $seeds[$attr] = ' data-tp-live-seed="' . Html::escape((string) json_encode($seed, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES)) . '"';
                }
            }

            return [$head, strtr($body, $seeds)];
        } finally {
            self::$page = $previous;
        }
    }

    /**
     * Records that the page being rendered for a region depends on $key, a change notice key that is
     * not an app collection's (`wp:comments`): it goes back in the answer's `kinds`, so the browser
     * asks again when a notice names it.
     */
    public static function reads(string $key): void
    {
        if (self::$capture !== null) {
            self::$capture['extra'][$key] = true;
        } elseif (self::$page !== null) {
            self::$page['notices'][$key] = true;
        }
    }

    /** Whether this render placed any `<Live>` region: the page loads the browser module. */
    public static function used(): bool
    {
        return self::$rendered !== [];
    }

    /**
     * On a page with a `<Live>` region: the browser module, which imports the app's typed client
     * (`@typedpress/client`) and loads the Interactivity router when an answer changed. A signed-in
     * visitor's reads carry the REST nonce; a signed-out page carries none and stays cacheable.
     */
    public static function enqueue(): void
    {
        if (self::$rendered === [] || !function_exists('wp_register_script_module') || !\TypedPress\Islands::registerClient()) {
            return;
        }
        wp_register_script_module('typedpress-live-region', plugins_url('tp/Render/client/live-region.js', \TypedPress\Wp\Runtime::file()), [
            ['id' => \TypedPress\Islands::CLIENT, 'import' => 'static'],
            ['id' => '@wordpress/interactivity', 'import' => 'static'],
            ['id' => '@wordpress/interactivity-router', 'import' => 'dynamic'],
        ], null);
        wp_enqueue_script_module('typedpress-live-region');
        wp_interactivity_config('tp/live', ['nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '']);
        // The region the router swaps: the page's main region, processed as Navigation does.
        Navigation::used();
    }

    /**
     * Marks the page being rendered for a region as depending on something the counters can't see
     * (the clock, WordPress options, core queries): it is rendered and compared on every poll.
     */
    public static function unseen(): void
    {
        // Anywhere in the route, like its reads: a page can read it above the region and pass it in.
        if (self::$capture !== null) {
            self::$capture['unseen'] = true;
        }
    }

    /**
     * Runs $render (one render of a whole page) and returns what region $id rendered within it:
     * its HTML, its own reads, whether it read anything unseen, and whether the page has it at all.
     *
     * @return array{html: ?string, reads: array, unseen: bool, found: bool}
     */
    public static function capture(string $id, \Closure $render): array
    {
        $previous = self::$capture;
        self::$capture = ['id' => $id, 'html' => null, 'reads' => [], 'unseen' => false, 'found' => false, 'extra' => []];
        try {
            // The whole route's reads, not the region's alone: a page reads data above its `<Live>`
            // and passes it in (`const all = await notes.list(); <Live>{all.map(…)}</Live>`), and
            // those reads happen before the region renders. Counting more kinds than the region
            // shows only makes it re-render more often, never less safely.
            Reads::during($render, $reads);
            self::$capture['reads'] = $reads;

            return array_diff_key(self::$capture, ['id' => true]);
        } finally {
            self::$capture = $previous;
        }
    }
}
