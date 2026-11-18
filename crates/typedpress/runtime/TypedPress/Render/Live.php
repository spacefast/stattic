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

    /** @var list<string> region ids this page rendered, for the browser module */
    private static array $rendered = [];

    /**
     * A `<Live>` region: its children rendered now, in an element the browser keeps current. In a
     * page's own render the element also carries the region's first answer for the subscription
     * (`data-tp-live-seed`: its sealed token and the kinds it read), so the browser asks nothing
     * until a notice, a write or the poll says it may have changed.
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
        // The region's first answer for the browser's subscription: the endpoint's own render of this
        // URL and region, so its token and kinds are exactly what the next poll compares against.
        $seed = self::seed($id);

        return Html::element('div', ['data-tp-live' => $id] + ($seed === null ? [] : ['data-tp-live-seed' => (string) json_encode($seed, JSON_UNESCAPED_SLASHES)]), Html::raw($html));
    }

    /** @return ?array{token: string, kinds: list<string>} */
    private static function seed(string $id): ?array
    {
        $plugin = \TypedPress\Wp\Runtime::plugin();
        $viewer = \TypedPress\Wp\CoreAccess::viewer();
        $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($plugin === null || $viewer === null || preg_match('~\A/(?![/\\\\])[^\x00-\x20\x7f#]*\z~', $url) !== 1) {
            return null;
        }
        // A form's flash marker is this visitor's alone: the subscription names the page without it.
        $url = preg_replace('~([?&])_flash=[0-9a-f]+(&|$)~', '$1', $url) ?? $url;
        $url = rtrim($url, '?&');
        try {
            [$result] = \TypedPress\Live\Module::evaluate($plugin->app()->db, $viewer, [['op' => 'page', 'args' => ['url' => $url, 'region' => $id]]]);
        } catch (\Throwable) {
            return null;
        }
        if (!isset($result['token'], $result['data']['kinds'])) {
            return null;
        }

        return ['url' => $url, 'token' => $result['token'], 'kinds' => $result['data']['kinds']];
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
