<?php

declare(strict_types=1);

namespace Zero\Pages;

/**
 * Per-request rendering of compiled block markup.
 *
 * The template is serialized block markup with two kinds of placeholders:
 *
 * - Tokens (U+E000 `v<n>` U+E001) in text and attribute values. They are
 *   replaced after WordPress renders the blocks, by walking the final HTML
 *   with the HTML API: a token in a text node is replaced with escaped text
 *   (`set_modifiable_text`), a token in an attribute value through
 *   `set_attribute`, which escapes and runs `esc_url` for URL attributes.
 *   Values never pass through the block parser, so a value containing
 *   `<!-- wp:` or a token character cannot become structure.
 * - Region blocks (`zero-pages/each|show|island|rich-text`). They are
 *   dynamic blocks whose inner blocks WordPress does not pre-render
 *   (`skip_inner_blocks`); the render callback evaluates the region for the
 *   request and renders its inner blocks once per row or not at all.
 *
 * A value that reaches the output as text is escaped exactly once. Raw
 * HTML enters only through `<RichText>`, which renders the routed item's own
 * stored body (read as the visitor) through the core `the_content` pipeline,
 * the same way WordPress renders a post body.
 */
final class Renderer
{
    private const START = "\u{E000}";
    private const END = "\u{E001}";
    private const PATTERN = '/\x{E000}([a-z][a-z0-9]*)\x{E001}/u';

    private static ?self $current = null;
    /** Region nesting depth, a guard against runaway recursion. */
    private int $depth = 0;

    public function __construct(private readonly Request $request, private readonly Plugin $plugin)
    {
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /** Outside a page request (public REST, feeds, other renderers) placeholders resolve to nothing but asset URLs. */
    public static function strip(string $html, string $assetBase): string
    {
        return (string) preg_replace(self::PATTERN, '', str_replace(self::START . 'asset' . self::END, $assetBase, $html));
    }

    /** Render a template file to final HTML for this request. */
    public function render(string $markup): string
    {
        $previous = self::$current;
        self::$current = $this;
        try {
            $html = do_blocks($markup);
        } finally {
            self::$current = $previous;
        }
        return $this->fill($html);
    }

    /** The region block render callback. */
    public function region(array $attributes, \WP_Block $block): string
    {
        $id = (string) ($attributes['region'] ?? '');
        $region = $this->request->route['regions'][$id] ?? null;
        if (!is_array($region)) {
            return '';
        }
        if (++$this->depth > 16) {
            $this->depth--;
            return '';
        }
        try {
            return match ($region['kind']) {
                'each' => $this->each($region, $block),
                'show' => $this->show($region, $block),
                'island' => $this->island($region, $block),
                'rich-text' => $this->richText($region),
                default => '',
            };
        } finally {
            $this->depth--;
        }
    }

    /** The region's content: build-time HTML chunks and inner blocks, in order, as core renders them. */
    private function inner(\WP_Block $block): string
    {
        $html = '';
        $index = 0;
        foreach ($block->inner_content as $chunk) {
            if (is_string($chunk)) {
                $html .= $chunk;
                continue;
            }
            $inner = $block->inner_blocks[$index++] ?? null;
            if ($inner instanceof \WP_Block) {
                $html .= $inner->render();
            }
        }
        return $html;
    }

    private function each(array $region, \WP_Block $block): string
    {
        $rows = $this->request->read($region['of']);
        if (!is_array($rows) || !array_is_list($rows)) {
            return '';
        }
        $loop = (string) $region['loop'];
        $html = '';
        foreach ($rows as $index => $row) {
            $this->request->scope[$loop] = is_array($row) ? $row : null;
            $this->request->index[$loop] = $index;
            // Tokens inside the body read the current row, so fill them now, before the next row replaces it.
            $html .= $this->fill($this->inner($block));
        }
        unset($this->request->scope[$loop], $this->request->index[$loop]);
        return $html;
    }

    private function show(array $region, \WP_Block $block): string
    {
        $value = $this->request->read($region['when']);
        $value ??= $region['fallback'] ?? null;
        $pass = array_key_exists('equals', $region)
            ? self::strictEquals($value, $region['equals'])
            : self::truthy($value);
        if (!empty($region['negate'])) {
            $pass = !$pass;
        }
        return $pass ? $this->inner($block) : '';
    }

    private function island(array $region, \WP_Block $block): string
    {
        $context = [];
        foreach ($region['context'] as $key => $value) {
            if (is_array($value) && array_key_exists('$zp', $value)) {
                $read = $this->request->read($value['$zp']);
                $context[$key] = $read ?? ($value['fallback'] ?? null);
                continue;
            }
            $context[$key] = $value;
        }
        $this->plugin->enqueueIsland((string) $region['namespace']);
        $inner = $this->fill($this->inner($block));
        // The Interactivity API escapes the JSON for the attribute; the server-side pass evaluates directives.
        $html = sprintf(
            '<div class="zero-pages-island" data-wp-interactive="%s" %s>%s</div>',
            esc_attr((string) $region['namespace']),
            wp_interactivity_data_wp_context($context),
            $inner,
        );
        return wp_interactivity_process_directives($html);
    }

    private function richText(array $region): string
    {
        $value = $this->request->read($region['value']);
        if (!is_string($value) || $value === '') {
            return '';
        }
        // The visitor read this row; its body was sanitized at the write boundary. Render it the way
        // WordPress renders a post body. Region blocks inside stored content render nothing (no current
        // renderer), and stored placeholders are removed, so content can never reach page data.
        self::$current = null;
        try {
            $html = str_replace([self::START, self::END], '', (string) apply_filters('the_content', $value));
        } finally {
            self::$current = $this;
        }
        return self::START . 'raw' . self::END . base64_encode($html) . self::START . '/raw' . self::END;
    }

    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false, $value === '', $value === 0 => false,
            is_float($value) => !($value == 0.0 || is_nan($value)),
            default => true,
        };
    }

    private static function strictEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }

    /** A value as text, the way JavaScript would print it in JSX or a template string. */
    private function text(mixed $value, string $mode): ?string
    {
        if ($value === null) {
            return $mode === 'string' ? 'null' : null;
        }
        if (is_bool($value)) {
            return $mode === 'string' ? ($value ? 'true' : 'false') : null;
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return class_exists(\Zero\Js::class) ? \Zero\Js::str($value) : (string) $value;
        }
        // Lists and objects have no text form a page should show.
        return null;
    }

    /** The token's value as text: its fallback when null, and never containing token characters. */
    private function tokenText(string $id): string
    {
        $spec = $this->request->route['tokens'][$id] ?? null;
        if (!is_array($spec)) {
            return '';
        }
        $value = $this->request->read($spec['path']);
        if ($value === null && array_key_exists('fallback', $spec)) {
            $value = $spec['fallback'];
        }
        $text = $this->text($value, (string) $spec['mode']) ?? '';
        // A stored value can never smuggle a placeholder for another value.
        return str_replace([self::START, self::END], '', $text);
    }

    /** Replace tokens in a run of text with their values (unescaped; the HTML API or the caller escapes). */
    public function substitute(string $text): string
    {
        return (string) preg_replace_callback(self::PATTERN, fn (array $m): string => $m[1] === 'asset' ? $this->plugin->assetBase() : $this->tokenText($m[1]), $text);
    }

    /**
     * Replace every token in rendered HTML, escaping for the context it is in.
     * Raw rich-text chunks are spliced back unchanged at the end.
     */
    public function fill(string $html): string
    {
        if (!str_contains($html, self::START)) {
            return $html;
        }
        $raw = [];
        // A fresh name per call: stored content can never spell the placeholder of another chunk.
        $slot = 'zero-pages-raw-' . bin2hex(random_bytes(8));
        $html = (string) preg_replace_callback('/\x{E000}raw\x{E001}([A-Za-z0-9+\/=]*)\x{E000}\/raw\x{E001}/u', static function (array $m) use (&$raw, $slot): string {
            $raw[] = base64_decode($m[1], true) ?: '';
            return '<' . $slot . ' data-i="' . (count($raw) - 1) . '"></' . $slot . '>';
        }, $html);
        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_token()) {
            $type = $processor->get_token_type();
            if ($type === '#text') {
                $text = $processor->get_modifiable_text();
                if (str_contains($text, self::START)) {
                    $processor->set_modifiable_text($this->substitute($text));
                }
                continue;
            }
            if ($type !== '#tag' || $processor->is_tag_closer()) {
                continue;
            }
            foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $name) {
                $value = $processor->get_attribute($name);
                if (is_string($value) && str_contains($value, self::START)) {
                    $filled = $this->substitute($value);
                    if (in_array(strtolower($name), wp_kses_uri_attributes(), true) && !self::safeUrl($filled)) {
                        $processor->remove_attribute($name);
                        continue;
                    }
                    $processor->set_attribute($name, $filled);
                }
            }
        }
        // A token left in a context the HTML API does not expose as text (inside <script>, <style>,
        // <textarea>, <title>, a comment) is dropped, never written unescaped.
        $html = (string) preg_replace(self::PATTERN, '', $processor->get_updated_html());
        if ($raw !== []) {
            $html = (string) preg_replace_callback('/<' . $slot . ' data-i="(\d+)"><\/' . $slot . '>/', static fn (array $m): string => $raw[(int) $m[1]] ?? '', $html);
        }
        return $html;
    }

    /** Only http(s), root-relative, fragment and mailto URLs survive a per-request value. */
    private static function safeUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//'))) {
            return true;
        }
        $scheme = strtolower((string) wp_parse_url($trimmed, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
