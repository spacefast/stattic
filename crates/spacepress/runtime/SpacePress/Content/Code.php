<?php

declare(strict_types=1);

namespace SpacePress\Content;

use SpacePress\Vendor\Highlight\Highlighter;

/**
 * Code blocks (docs/decisions/2026-11-13-code-blocks.md): core's `core/code`, with a title,
 * highlighted lines and a tab group, highlighted when the page renders, in PHP, with no JavaScript.
 *
 * In Markdown the fence carries the options: ```` ```ts title="spacepress.config.ts" {2,4-5} group="setup" ````.
 * They are stored as attributes on core's block (`title`, `highlight`, `group`) and written back to
 * the fence by `spacepress sync`. At render, `render_block_core/code` replaces the block's code with
 * highlighted HTML; consecutive blocks sharing a `group` render as one set of tabs.
 */
final class Code
{
    /** Fence spellings for a language highlight.php names differently, or reads badly (JSX). */
    private const ALIASES = [
        'ts' => 'typescript', 'tsx' => 'javascript', 'jsx' => 'javascript', 'js' => 'javascript',
        'sh' => 'bash', 'shell' => 'bash', 'zsh' => 'bash', 'console' => 'bash', 'yml' => 'yaml',
        'html' => 'xml', 'svg' => 'xml', 'md' => 'markdown', 'rs' => 'rust', 'py' => 'python',
    ];

    private static ?Highlighter $highlighter = null;

    /**
     * The options of every fence in a Markdown document, in order: a list with one entry per
     * code fence, each the attributes its info string sets beyond the language.
     *
     * @return list<array{title?: string, highlight?: string, group?: string}>
     */
    public static function fences(string $markdown): array
    {
        $out = [];
        $open = null;
        foreach (explode("\n", $markdown) as $line) {
            if ($open === null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $m) === 1 && !str_contains($m[1][0] === '`' ? $m[2] : '', '`')) {
                    $open = $m[1];
                    $out[] = self::options(trim($m[2]));
                }
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*$/', $line, $m) === 1 && $m[1][0] === $open[0] && strlen($m[1]) >= strlen($open)) {
                $open = null;
            }
        }

        return $out;
    }

    /** `ts title="a.ts" {1,3-4} group="install"` → the attributes after the language. */
    private static function options(string $info): array
    {
        $rest = trim((string) preg_replace('/^\S+/', '', $info, 1));
        $out = [];
        if (preg_match('/\btitle="([^"]*)"/', $rest, $m) === 1) {
            $out['title'] = $m[1];
            $rest = str_replace($m[0], '', $rest);
        }
        if (preg_match('/\bgroup="([^"]*)"/', $rest, $m) === 1) {
            $out['group'] = $m[1];
            $rest = str_replace($m[0], '', $rest);
        }
        if (preg_match('/\{([\d,\s-]+)\}/', $rest, $m) === 1) {
            $ranges = self::ranges($m[1]);
            if ($ranges !== '') {
                $out['highlight'] = $ranges;
            }
            $rest = str_replace($m[0], '', $rest);
        }
        // Any other words are the title, as other docs tools read them: ```ts spacepress.config.ts
        if (!isset($out['title']) && trim($rest) !== '') {
            $out['title'] = trim($rest);
        }

        return $out;
    }

    /** `1, 3-4` → `1,3-4`, ignoring anything that isn't a line number or range. */
    private static function ranges(string $spec): string
    {
        $parts = [];
        foreach (preg_split('/\s*,\s*/', trim($spec)) ?: [] as $part) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', $part, $m) === 1 && (int) $m[1] > 0 && (!isset($m[2]) || (int) $m[2] >= (int) $m[1])) {
                $parts[] = $part;
            }
        }

        return implode(',', $parts);
    }

    /** The attributes back as a fence's info string after its language (`title="…" {1,3} group="…"`). */
    public static function info(array $attrs): string
    {
        $out = [];
        if (is_string($attrs['title'] ?? null) && $attrs['title'] !== '') {
            $out[] = 'title="' . str_replace('"', '', $attrs['title']) . '"';
        }
        if (is_string($attrs['highlight'] ?? null) && $attrs['highlight'] !== '') {
            $out[] = '{' . $attrs['highlight'] . '}';
        }
        if (is_string($attrs['group'] ?? null) && $attrs['group'] !== '') {
            $out[] = 'group="' . str_replace('"', '', $attrs['group']) . '"';
        }

        return implode(' ', $out);
    }

    /**
     * Stored blocks with each code block's fence options added, in document order: the toolkit
     * reads only a fence's language, so its other words are attached here.
     *
     * @param list<array> $blocks
     * @param list<array> $fences from fences()
     * @return list<array>
     */
    public static function attach(array $blocks, array &$fences): array
    {
        foreach ($blocks as $i => $block) {
            if (($block['blockName'] ?? null) === 'core/code') {
                $options = array_shift($fences) ?? [];
                if ($options !== []) {
                    $blocks[$i]['attrs'] = ($block['attrs'] ?? []) + $options;
                }
            }
            if (($block['innerBlocks'] ?? []) !== []) {
                $blocks[$i]['innerBlocks'] = self::attach($block['innerBlocks'], $fences);
            }
        }

        return $blocks;
    }

    // ------------------------------------------------------------------ rendering

    /** `render_block_core/code`: the block's code, highlighted, with its title and marked lines. */
    public static function render(string $html, array $block): string
    {
        $attrs = $block['attrs'] ?? [];
        $class = (string) ($attrs['className'] ?? '');
        $language = preg_match('/(?:^|\s)language-(\S+)/', $class, $m) === 1 ? strtolower($m[1]) : null;
        if (preg_match('#<code[^>]*>(.*)</code>#s', $html, $code) !== 1) {
            return $html;
        }
        $source = html_entity_decode(str_replace(['<br>', '<br/>', '<br />'], "\n", $code[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $source = rtrim($source, "\n");
        $lines = self::lines(self::highlight($source, $language));
        $marked = self::marked((string) ($attrs['highlight'] ?? ''));
        $body = '';
        foreach ($lines as $n => $line) {
            $body .= '<span class="spacepress-code-line' . (isset($marked[$n + 1]) ? ' spacepress-code-marked' : '') . '">' . $line . "</span>\n";
        }
        $title = is_string($attrs['title'] ?? null) && $attrs['title'] !== '' ? $attrs['title'] : null;
        self::style();
        $pre = '<pre class="spacepress-code' . ($language !== null ? ' language-' . esc_attr($language) : '') . '"><code>' . $body . '</code></pre>';
        $label = $title !== null ? '<figcaption class="spacepress-code-title">' . esc_html($title) . '</figcaption>' : '';

        return '<figure class="wp-block-code spacepress-code-block"' . (is_string($attrs['group'] ?? null) && $attrs['group'] !== '' ? ' data-sp-group="' . esc_attr($attrs['group']) . '" data-sp-tab="' . esc_attr($title ?? $language ?? 'code') . '"' : '') . '>' . $label . $pre . '</figure>';
    }

    /** highlight.php's HTML for the code, or the code escaped when the language is plain or unknown. */
    private static function highlight(string $source, ?string $language): string
    {
        self::$highlighter ??= new Highlighter();
        try {
            // A language highlight.php doesn't have (`text`, a typo) throws: the code shows as written.
            return self::$highlighter->highlight(self::ALIASES[$language] ?? (string) $language, $source)->value;
        } catch (\Throwable) {
            return esc_html($source);
        }
    }

    /**
     * Highlighted HTML split into lines, each with its spans closed at the line's end and reopened
     * on the next, so a line can carry its own class.
     *
     * @return list<string>
     */
    private static function lines(string $html): array
    {
        $out = [];
        $open = [];
        $line = '';
        foreach (preg_split('/(<span[^>]*>|<\/span>|\n)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if ($part === "\n") {
                $out[] = $line . str_repeat('</span>', count($open));
                $line = implode('', $open);
            } elseif (str_starts_with($part, '<span')) {
                $open[] = $part;
                $line .= $part;
            } elseif ($part === '</span>') {
                array_pop($open);
                $line .= $part;
            } else {
                $line .= $part;
            }
        }
        $out[] = $line . str_repeat('</span>', count($open));

        return $out;
    }

    /** @return array<int, true> line numbers from `1,3-4` */
    private static function marked(string $ranges): array
    {
        $out = [];
        foreach (explode(',', $ranges) as $range) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', trim($range), $m) === 1) {
                for ($n = (int) $m[1], $end = (int) ($m[2] ?? $m[1]); $n <= $end && $n - (int) $m[1] < 10000; $n++) {
                    $out[$n] = true;
                }
            }
        }

        return $out;
    }

    /**
     * `render_block` for the whole content: consecutive code blocks with one `group` become one set
     * of tabs, switched by radio inputs and CSS, so it reads without JavaScript.
     */
    public static function groups(string $html): string
    {
        if (!str_contains($html, 'data-sp-group="')) {
            return $html;
        }
        static $n = 0;

        return (string) preg_replace_callback(
            '/(?:<figure class="wp-block-code spacepress-code-block" data-sp-group="([^"]*)" data-sp-tab="[^"]*">.*?<\/figure>\s*)(?:<figure class="wp-block-code spacepress-code-block" data-sp-group="\1" data-sp-tab="[^"]*">.*?<\/figure>\s*)+/s',
            static function (array $m) use (&$n): string {
                $n++;
                preg_match_all('/<figure class="wp-block-code spacepress-code-block" data-sp-group="[^"]*" data-sp-tab="([^"]*)">(.*?)<\/figure>/s', $m[0], $tabs, PREG_SET_ORDER);
                $name = 'spacepress-code-group-' . $n;
                $out = '<div class="spacepress-code-group" role="group" aria-label="' . esc_attr(html_entity_decode($m[1], ENT_QUOTES)) . '">';
                foreach ($tabs as $i => $tab) {
                    $id = $name . '-' . $i;
                    $out .= '<input type="radio" class="spacepress-code-tab-input" name="' . $name . '" id="' . $id . '"' . ($i === 0 ? ' checked' : '') . '>'
                        . '<label class="spacepress-code-tab" for="' . $id . '">' . $tab[1] . '</label>';
                }
                foreach ($tabs as $tab) {
                    $out .= '<figure class="wp-block-code spacepress-code-block spacepress-code-panel">' . $tab[2] . '</figure>';
                }

                return $out . '</div>';
            },
            $html,
        );
    }

    /** The stylesheet: Atom One Light and Atom One Dark by colour scheme, tabs and marked lines. */
    private static function style(): void
    {
        static $done = false;
        if ($done || !function_exists('wp_register_style')) {
            return;
        }
        $done = true;
        wp_register_style('spacepress-code', plugins_url('sp/Content/client/code.css', \SpacePress\Wp\Runtime::file()), [], null);
        wp_enqueue_style('spacepress-code');
    }
}
