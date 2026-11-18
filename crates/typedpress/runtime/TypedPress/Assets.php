<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * Stylesheets and images that components and pages import (`src/assets.rs`): copied into the app
 * plugin's `tp/app/assets/`, each named by a hash of its bytes.
 */
final class Assets
{
    public const DIR = 'tp/app/assets/';

    /** An imported file's URL: `import logo from "./compass.svg"` reads this. */
    public static function url(string $file): string
    {
        $plugin = Wp\Runtime::plugin();
        return $plugin === null ? '' : $plugin->url(self::DIR . $file);
    }

    /**
     * Links a page's stylesheets, in the order its layouts and page import them. The handle is the
     * file name, so a stylesheet two pages share is one handle, and the version is in the name.
     *
     * @param list<string> $files
     */
    public static function enqueue(array $files): void
    {
        if (!did_action('wp_print_styles')) {
            // Include theme styles queued during wp_head before WordPress prints the queue.
            add_action('wp_print_styles', static fn () => self::enqueue($files), PHP_INT_MAX);
            return;
        }
        Wp\ThemeStyles::enqueue();
        foreach ($files as $file) {
            $handle = 'typedpress-app-' . sanitize_key(str_replace('.', '-', $file));
            wp_enqueue_style($handle, self::url($file), [], null);
        }
    }
}
