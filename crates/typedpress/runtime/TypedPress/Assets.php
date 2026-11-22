<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * Stylesheets and static assets that components and pages import (`src/assets.rs`): copied into
 * the app plugin's `tp/app/assets/`, each named by a hash of its bytes.
 *
 * Small stylesheets (<= {@see INLINE_LIMIT} bytes) are inlined into `<head>` with relative sibling
 * `url(./...)` references resolved to full `wp-content/` asset URLs; larger stylesheets link
 * externally with 1-year immutable caching. `@font-face` fonts and priority images emit
 * `<link rel="preload">` tags in `<head>`.
 */
final class Assets
{
    public const DIR = 'tp/app/assets/';

    /** Stylesheets at or below this byte size are inlined (Astro's `inlineStylesheets: "auto"` threshold). */
    public const INLINE_LIMIT = 4096;

    /** @var list<array{href: string, as: string, type?: string, crossorigin?: bool, fetchpriority?: string, imagesrcset?: string, imagesizes?: string}> */
    private static array $preloads = [];

    private static bool $preloadHooked = false;

    /** An imported file's URL: `import logo from "./compass.svg"` reads this. */
    public static function url(string $file): string
    {
        $plugin = Wp\Runtime::plugin();
        return $plugin === null ? '' : $plugin->url(self::DIR . $file);
    }

    /**
     * Intrinsic dimensions (`{width, height, format}`) for an imported asset URL, or null when
     * `$url` does not name a known image asset under `tp/app/assets/`.
     *
     * @return ?array{width: int, height: int, format: string}
     */
    public static function metaForUrl(string $url): ?array
    {
        $plugin = Wp\Runtime::plugin();
        if ($plugin === null) {
            return null;
        }
        $meta = $plugin->schema->assetsMeta();
        if ($meta === []) {
            return null;
        }
        $base = $plugin->url(self::DIR);
        if (!str_starts_with($url, $base)) {
            return null;
        }
        $file = substr($url, strlen($base));
        $cut = strcspn($file, '?#');
        $file = substr($file, 0, $cut);
        return $meta[$file] ?? null;
    }

    /** Record a high-priority image to emit `<link rel="preload" as="image">` in `<head>`. */
    public static function preloadImage(string $href, ?string $srcSet = null, ?string $sizes = null): void
    {
        if ($href === '' || str_starts_with($href, 'data:')) {
            return;
        }
        foreach (self::$preloads as $existing) {
            if ($existing['as'] === 'image' && $existing['href'] === $href) {
                return;
            }
        }
        $entry = ['href' => $href, 'as' => 'image', 'fetchpriority' => 'high'];
        if ($srcSet !== null && $srcSet !== '') {
            $entry['imagesrcset'] = $srcSet;
        }
        if ($sizes !== null && $sizes !== '') {
            $entry['imagesizes'] = $sizes;
        }
        self::$preloads[] = $entry;
        self::hookPreloads();
    }

    /**
     * Links (or inlines when <= {@see INLINE_LIMIT} bytes) a page's stylesheets in import order,
     * and registers `<link rel="preload">` hints for any `@font-face` fonts they reference.
     *
     * @param list<string> $files
     * @param list<array{file: string, type: string}> $preloads
     */
    public static function enqueue(array $files, array $preloads = []): void
    {
        foreach ($preloads as $font) {
            $file = $font['file'] ?? '';
            $type = $font['type'] ?? '';
            if (!is_string($file) || $file === '' || !is_string($type) || $type === '') {
                continue;
            }
            $href = self::url($file);
            if ($href === '') {
                continue;
            }
            $seen = false;
            foreach (self::$preloads as $existing) {
                if ($existing['as'] === 'font' && $existing['href'] === $href) {
                    $seen = true;
                    break;
                }
            }
            if (!$seen) {
                self::$preloads[] = ['href' => $href, 'as' => 'font', 'type' => $type, 'crossorigin' => true];
            }
        }
        if (self::$preloads !== []) {
            self::hookPreloads();
        }
        if (!did_action('wp_print_styles')) {
            // Include theme styles queued during wp_head before WordPress prints the queue.
            add_action('wp_print_styles', static fn () => self::enqueue($files), PHP_INT_MAX);
            return;
        }
        Wp\ThemeStyles::enqueue();
        $plugin = Wp\Runtime::plugin();
        foreach ($files as $file) {
            $handle = 'typedpress-app-' . sanitize_key(str_replace('.', '-', $file));
            if (str_starts_with($file, '/typedpress-styles/')) {
                wp_enqueue_style($handle, home_url($file), [], null);
                continue;
            }
            $disk = $plugin !== null ? $plugin->dir . '/' . self::DIR . $file : null;
            if ($disk !== null && is_file($disk) && ($size = @filesize($disk)) !== false && $size <= self::INLINE_LIMIT) {
                $css = (string) @file_get_contents($disk);
                if (stripos($css, '@import') === false) {
                    $css = self::resolveInlineUrls($css);
                    wp_register_style($handle, false, [], null);
                    wp_enqueue_style($handle);
                    wp_add_inline_style($handle, $css);
                    continue;
                }
            }
            wp_enqueue_style($handle, self::url($file), [], null);
        }
    }

    /** Rewrite sibling `url(./<file>)` references inside inlined CSS to full `wp-content/` asset URLs. */
    private static function resolveInlineUrls(string $css): string
    {
        if (!str_contains($css, './')) {
            return $css;
        }
        return (string) preg_replace_callback(
            '/url\(\s*(["\']?)\.\/([^)"\'\s]+)\1\s*\)/i',
            static function (array $m): string {
                $quote = $m[1];
                $raw = $m[2];
                $cut = strcspn($raw, '?#');
                $file = substr($raw, 0, $cut);
                $suffix = substr($raw, $cut);
                return 'url(' . $quote . self::url($file) . $suffix . $quote . ')';
            },
            $css,
        );
    }

    private static function hookPreloads(): void
    {
        if (self::$preloadHooked || !function_exists('add_action')) {
            return;
        }
        self::$preloadHooked = true;
        add_action('wp_head', static function (): void {
            foreach (self::$preloads as $p) {
                $attrs = 'rel="preload" href="' . esc_url($p['href']) . '" as="' . esc_attr($p['as']) . '"';
                if (isset($p['type'])) {
                    $attrs .= ' type="' . esc_attr($p['type']) . '"';
                }
                if (!empty($p['crossorigin'])) {
                    $attrs .= ' crossorigin="anonymous"';
                }
                if (isset($p['fetchpriority'])) {
                    $attrs .= ' fetchpriority="' . esc_attr($p['fetchpriority']) . '"';
                }
                if (isset($p['imagesrcset'])) {
                    $attrs .= ' imagesrcset="' . esc_attr($p['imagesrcset']) . '"';
                }
                if (isset($p['imagesizes'])) {
                    $attrs .= ' imagesizes="' . esc_attr($p['imagesizes']) . '"';
                }
                echo '<link ' . $attrs . ">\n";
            }
        }, 2);
    }
}
