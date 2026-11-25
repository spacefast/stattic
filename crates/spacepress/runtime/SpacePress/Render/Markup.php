<?php

declare(strict_types=1);

namespace SpacePress\Render;

use SpacePress\Html;
use SpacePress\JsUndefined;

/**
 * The markup components of "spacepress/app" (docs/decisions/2026-11-08-tsx-to-php.md, "Raw HTML"):
 * `<Content>` runs a post body through `the_content`, `<Html>` places author literals as they are
 * and runs anything else through `wp_kses_post`, and `<Link>` is an `<a>` the router region can
 * take over (rendering.md §4.3).
 */
final class Markup
{
    public static function content(mixed $value): Html
    {
        return Html::raw(self::nullish($value) ? '' : (string) apply_filters('the_content', (string) $value));
    }

    public static function trusted(mixed $value): Html
    {
        return Html::raw(self::nullish($value) ? '' : (string) $value);
    }

    public static function kses(mixed $value): Html
    {
        return Html::raw(self::nullish($value) ? '' : wp_kses_post((string) $value));
    }

    /**
     * `<Link href prefetch?>children</Link>`: an `<a>` with React's attribute rules. A same-site
     * URL gets `data-wp-on--click` for the router region's navigation once the router store is
     * on the page; without JavaScript it is an ordinary link. A path a `sp/app/` page serves is
     * written as that page's one address (`/docs/intro/` under `/%postname%/`, AppRouter::href),
     * so following it is never a redirect.
     *
     * @param array<string, mixed> $props
     */
    public static function link(array $props, mixed $children): Html
    {
        $prefetch = $props['prefetch'] ?? false;
        $requireAuth = $props['requireAuth'] ?? null;
        unset($props['prefetch'], $props['requireAuth']);
        $href = $props['href'] ?? null;
        $local = is_string($href) ? self::localHref($href) : null;
        if ($local !== null) {
            $props['href'] = home_url(\SpacePress\Wp\AppRouter::href($local));
            $path = (string) (wp_parse_url($local, PHP_URL_PATH) ?? '/');
            $router = \SpacePress\Wp\AppRouter::instance();
            if ($path === '/' && \SpacePress\Wp\AppRouter::wordPressHomeQuery($local)) {
                $router = null;
            }
            if (is_bool($requireAuth) ? $requireAuth : $router?->linkRequiresLogin($path)) {
                // Static HTML can be opened by anyone. An inferred guard will run at the destination.
                if (Prebuilt::prerendering()) {
                    if ($requireAuth === true) {
                        Prebuilt::viewerCall('Link requireAuth');
                    }
                    return Html::element('a', $props, $children);
                }
                if (!is_user_logged_in()) {
                    $props['href'] = wp_login_url($props['href']);
                    return Html::element('a', $props, $children);
                }
            }
            $template = $router?->linkTemplateFor($path);
            if ($template !== null) {
                Navigation::used();
                $props['data-wp-interactive'] = Navigation::NAMESPACE;
                $props['data-wp-on--click'] = 'actions.navigate';
                $props['data-sp-template'] = $template;
                if ($prefetch === true) {
                    $props['data-wp-init---sp-prefetch'] = 'callbacks.preparePrefetch';
                    $props['data-wp-on--mouseenter'] = 'actions.prefetch';
                }
            }
        }
        return Html::element('a', $props, $children);
    }

    /**
     * `<Image src alt width? height? sizes? priority? loading? decoding? className? id? style? />`:
     * renders an `<img>` with intrinsic dimensions from imported asset metadata or WordPress media
     * objects, lazy loading and async decoding by default, responsive `srcset` from WordPress media
     * sizes, and automatic `<link rel="preload" as="image">` + `fetchpriority="high"` when `priority` is true.
     *
     * @param array<string, mixed> $props
     */
    public static function image(array $props): Html
    {
        $priority = ($props['priority'] ?? false) === true;
        unset($props['priority']);
        $src = $props['src'] ?? null;
        $width = $props['width'] ?? null;
        $height = $props['height'] ?? null;
        $srcSet = null;

        if ($src instanceof \SpacePress\JsObject) {
            $map = $src->p;
            $url = is_string($map['src'] ?? null) ? $map['src'] : (is_string($map['url'] ?? null) ? $map['url'] : '');
            if ($width === null && isset($map['width']) && is_numeric($map['width'])) {
                $width = (int) $map['width'];
            }
            if ($height === null && isset($map['height']) && is_numeric($map['height'])) {
                $height = (int) $map['height'];
            }
            // WordPress media objects carry responsive `sizes` variants ({ name => { url, width } }).
            $variants = $map['sizes'] ?? null;
            if ($variants instanceof \SpacePress\JsObject) {
                $variants = $variants->p;
            }
            if (is_array($variants)) {
                $candidates = [];
                foreach ($variants as $variant) {
                    $v = $variant instanceof \SpacePress\JsObject ? $variant->p : (is_array($variant) ? $variant : null);
                    if (is_array($v) && is_string($v['url'] ?? null) && isset($v['width']) && is_numeric($v['width']) && (int) $v['width'] > 0) {
                        $candidates[(int) $v['width']] = $v['url'] . ' ' . (int) $v['width'] . 'w';
                    }
                }
                if ($url !== '' && is_numeric($width) && (int) $width > 0) {
                    $candidates[(int) $width] = $url . ' ' . (int) $width . 'w';
                }
                if (count($candidates) > 1) {
                    ksort($candidates);
                    $srcSet = implode(', ', $candidates);
                }
            }
            $src = $url;
        }

        if (is_string($src) && ($width === null || $height === null)) {
            $meta = \SpacePress\Assets::metaForUrl($src);
            if ($meta !== null) {
                $width ??= $meta['width'];
                $height ??= $meta['height'];
            }
        }

        $props['src'] = is_string($src) ? $src : '';
        if ($width !== null) {
            $props['width'] = $width;
        }
        if ($height !== null) {
            $props['height'] = $height;
        }
        if ($srcSet !== null) {
            $props['srcSet'] = $srcSet;
        }
        if (!isset($props['decoding']) || self::nullish($props['decoding'])) {
            $props['decoding'] = 'async';
        }
        if ($priority) {
            $props['loading'] = 'eager';
            $props['fetchpriority'] = 'high';
        } elseif (!isset($props['loading']) || self::nullish($props['loading'])) {
            $props['loading'] = 'lazy';
        }

        return Html::element('img', $props);
    }

    /** A path below this site's home URL, including a same-site absolute URL's query and fragment. */
    private static function localHref(string $href): ?string
    {
        if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
            return $href;
        }
        $url = wp_parse_url($href);
        $home = wp_parse_url(home_url('/'));
        $scheme = strtolower($url['scheme'] ?? '');
        $homeScheme = strtolower($home['scheme']);
        if (!is_array($url) || isset($url['user']) || isset($url['pass'])
            || $scheme !== $homeScheme
            || strtolower($url['host'] ?? '') !== strtolower($home['host'])
            || ($url['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($home['port'] ?? ($homeScheme === 'https' ? 443 : 80))) {
            return null;
        }
        $path = $url['path'] ?? '/';
        $base = rtrim($home['path'] ?? '', '/');
        if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
            return null;
        }
        return (substr($path, strlen($base)) ?: '/') . substr($href, strcspn($href, '?#'));
    }

    private static function nullish(mixed $value): bool
    {
        return $value === null || $value === JsUndefined::Value;
    }
}
