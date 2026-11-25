<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * The project's styles (docs/decisions/2026-11-15-styles-from-wordpress.md). `sp/theme.json`, shipped
 * as the app plugin's `sp/app/theme.json`, is merged into the theme layer of WordPress's global
 * styles: over the active theme's theme.json (SpacePress's `spacepress-default` or an installed block theme),
 * under the site owner's Global Styles, which the Styles screen and the Site Editor save. Code is the
 * default; what the owner customizes on the site wins, and `spacepress styles pull` brings it back to code.
 */
final class ThemeStyles
{
    public const FILE = 'sp/app/theme.json';
    private const HANDLE = 'spacepress-theme';

    /** @var array<string, mixed>|null the shipped document, read once */
    private static ?array $data = null;

    public static function register(Plugin $plugin): void
    {
        $file = $plugin->dir . '/' . self::FILE;
        if (is_readable($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                self::$data = $data;
                add_filter('wp_theme_json_data_theme', [self::class, 'merge']);
            }
        }
        $command = static fn () => Runtime::command('styles', self::wpCli(...));
        if (defined('WP_CLI') && WP_CLI) {
            did_action('sp_runtime_loaded') ? $command() : add_action('sp_runtime_loaded', $command);
        }
    }

    /** The theme layer with the project's settings and styles over it. */
    public static function merge(\WP_Theme_JSON_Data $theme): \WP_Theme_JSON_Data
    {
        return self::$data === null ? $theme : $theme->update_with(self::$data);
    }

    /** Current theme and owner styles, available to app CSS and the generated Tailwind utilities. */
    public static function enqueue(): void
    {
        if (wp_style_is(self::HANDLE, 'enqueued')) {
            return;
        }
        $styles = [
            'color--background' => [['color', 'background'], 'Canvas'],
            'color--foreground' => [['color', 'text'], 'CanvasText'],
            'color--link' => [['elements', 'link', 'color', 'text'], 'var(--sp--color--foreground)'],
            'font-family--body' => [['typography', 'fontFamily'], 'inherit'],
            'font-size--body' => [['typography', 'fontSize'], 'inherit'],
            'line-height--body' => [['typography', 'lineHeight'], 'inherit'],
            'spacing--block' => [['spacing', 'blockGap'], '1.5rem'],
            'spacing--page' => [['spacing', 'padding', 'left'], '1.25rem'],
        ];
        // Resolve the theme, plugins and Site Editor overrides once for this stylesheet.
        $theme = \WP_Theme_JSON_Resolver::get_merged_data();
        $data = $theme->get_raw_data();
        $layout = $theme->get_settings()['layout'] ?? [];
        $css = '';
        foreach ($styles as $name => [$path, $fallback]) {
            $value = _wp_array_get($data['styles'] ?? [], $path);
            if (is_array($value) && is_string($value['ref'] ?? null)) {
                $value = _wp_array_get($data, explode('.', $value['ref']));
            }
            // A two-axis block gap uses its vertical spacing for the app's gap scale.
            if ($name === 'spacing--block' && is_array($value)) {
                $value = $value['top'] ?? null;
            }
            $css .= self::declaration('--sp--' . $name, $value, $fallback);
        }
        foreach (['content' => '40rem', 'wide' => '64rem'] as $name => $fallback) {
            $css .= self::declaration('--sp--width--' . $name, $layout[$name . 'Size'] ?? null, $fallback);
        }
        wp_register_style(self::HANDLE, false, [], null);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, 'body.spacepress-app{' . $css . '}');
    }

    /** Theme data crosses into an inline stylesheet here, so reject extra declarations and markup. */
    private static function declaration(string $name, mixed $value, string $fallback): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        $css = '';
        if (is_string($value) && $value !== '' && strpbrk($value, ';{}<>\\&=') === false && !str_contains($value, '/*')) {
            // Core's custom-property filter omits color functions. Allow only those functions
            // for this declaration, retaining its remaining checks and sanitized output.
            $colors = static function (bool $allowed, string $test) use ($name): bool {
                if ($allowed || !str_starts_with($name, '--sp--color--') || !str_starts_with($test, $name . ':')) {
                    return $allowed;
                }
                $test = preg_replace('/\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\((?:[^()]|(?R))*\)/i', '', $test);

                return $test !== null && strpbrk($test, '\\(&=}') === false && !str_contains($test, '/*');
            };
            add_filter('safecss_filter_attr_allow_css', $colors, 0, 2);
            try {
                $css = safecss_filter_attr($name . ':' . $value);
            } finally {
                remove_filter('safecss_filter_attr_allow_css', $colors, 0);
            }
        }

        return ($css === '' ? $name . ':' . $fallback : $css) . ';';
    }

    /**
     * What `spacepress styles pull` writes to `sp/theme.json`: the project's file with the owner's Global
     * Styles merged over it, as WordPress merges the two layers, keeping only settings and styles.
     *
     * @return array<string, mixed>
     */
    public static function export(): array
    {
        $tree = new \WP_Theme_JSON(self::$data ?? ['version' => \WP_Theme_JSON::LATEST_SCHEMA], 'theme');
        $tree->merge(\WP_Theme_JSON_Resolver::get_user_data());
        $data = $tree->get_data();
        $out = ['version' => $data['version'] ?? \WP_Theme_JSON::LATEST_SCHEMA];
        foreach (['settings', 'styles'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && $data[$key] !== []) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }

    /** `wp spacepress styles export`: one `{"data": …}` or `{"error": …}` line, like every `wp spacepress` command. */
    public static function wpCli(array $args, array $flags): int
    {
        try {
            if (($args[0] ?? '') !== 'export') {
                throw new \SpacePress\HttpError(400, 'invalid_command', 'Usage: wp spacepress styles export');
            }
            [$code, $out] = [0, ['data' => self::export()]];
        } catch (\Throwable $e) {
            [$code, $out] = [1, ['error' => \SpacePress\App::problem($e)]];
        }
        \WP_CLI::line((string) wp_json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $code;
    }
}
