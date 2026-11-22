<?php

declare(strict_types=1);

namespace TypedPress\Render;

/**
 * Region navigation for `<Link>` (docs/spec/rendering.md §4.3): the root layout's content region is
 * a router region, and a link click fetches the next page and swaps that region with
 * `@wordpress/interactivity-router`. The store module is enqueued only on pages that render a link.
 */
final class Navigation
{
    public const NAMESPACE = 'tp/router';
    public const REGION = 'tp/main';
    /** The router region's closing tag, marked so tools that read a page can find where it ends. */
    public const END = '</div><!-- /tp/main -->';
    private static bool $used = false;

    public static function used(): void
    {
        self::$used = true;
    }

    public static function reset(): void
    {
        self::$used = false;
    }

    /**
     * The router region's opening tag; the page's HTML follows it, then `</div>`. On its own it is
     * the document's `wp-site-blocks`; inside a block template (which has that root already) it is
     * the page's own box.
     */
    public static function region(string $class = 'wp-site-blocks'): string
    {
        return '<div class="' . esc_attr($class) . '" data-wp-interactive="' . esc_attr(self::NAMESPACE) . '" data-wp-router-region="' . esc_attr(self::REGION) . '">';
    }

    /**
     * On a page that renders a link: the store module, and core's processing of the router region.
     * Core's directive pass runs over the region's empty wrapper only, so its router-region
     * processor seeds `core/router`'s `state.url`, enqueues the navigation animation styles and
     * prints the loading bar in the footer, as on a block theme's page. The page's HTML is final
     * already (compiled components print their own values), so it is not processed again.
     */
    public static function enqueue(string $url): void
    {
        // The module also removes a form's one-use flash marker from the address bar.
        if ((!self::$used && !Forms::marked()) || !function_exists('wp_register_script_module')) {
            return;
        }
        wp_register_script_module('typedpress-router', $url, [['id' => '@wordpress/interactivity', 'import' => 'static'], ['id' => '@wordpress/interactivity-router', 'import' => 'dynamic']], \TypedPress\Islands::runtimeVersion());
        wp_enqueue_script_module('typedpress-router');
        // What a form says when its post may have reached the server but no page came back.
        wp_interactivity_config(self::NAMESPACE, [
            'failed' => __('Something went wrong sending this form. Reload the page to see whether it went through.'),
            // And when another form on the page was sent after it, whose answer is the one shown.
            'overtaken' => __('Another form on this page was sent after this one, and its result is the one shown.'),
        ]);
        wp_interactivity_process_directives(self::region() . '</div>');
    }
}
