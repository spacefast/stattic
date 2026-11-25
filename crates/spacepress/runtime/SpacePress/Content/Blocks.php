<?php

declare(strict_types=1);

namespace SpacePress\Content;

use SpacePress\Html;
use SpacePress\JsObject;
use SpacePress\Seam;
use SpacePress\Wp\Runtime;
use WP_HTML_Tag_Processor as Tags;

/**
 * Components in Markdown (docs/decisions/2026-11-13-components-in-markdown.md).
 *
 * A server component its file lists in `export const blocks = ["Callout"]` is one block in the
 * editor and one tag in a Markdown file:
 *
 *     <Callout type="warning" title="Heads up">
 *     Mind the **gap**.
 *     </Callout>
 *
 * is stored as
 *
 *     <!-- wp:<app>/callout {"type":"warning","title":"Heads up"} -->
 *     <!-- wp:paragraph --><p>Mind the <strong>gap</strong>.</p><!-- /wp:paragraph -->
 *     <!-- /wp:<app>/callout -->
 *
 * The tag's attributes are the block's attributes, typed by the component's props; what is between
 * the tags is Markdown, stored as the block's inner blocks, so the editor edits it as any content.
 * The block renders on the server through the component itself: its `children` are the inner
 * blocks, rendered. Writing the block back gives the same tag, so `spacepress sync` round-trips it.
 */
final class Blocks
{
    /** Set while core's block renderer (the editor's preview) is answering. */
    private static bool $previewing = false;

    /** @return array<string, array{tag: string, component: string, attributes: array<string, array>}> by block name */
    public static function all(): array
    {
        return Runtime::plugin()?->schema->contentBlocks() ?? [];
    }

    /** @return ?array{string, array} the block name and spec for a tag (`Callout`) */
    private static function byTag(string $tag): ?array
    {
        foreach (self::all() as $name => $spec) {
            if ($spec['tag'] === $tag) {
                return [$name, $spec];
            }
        }
        return null;
    }

    /**
     * init: each component block is a dynamic block, rendered by its component. One without
     * `children` is all props: core's `autoRegister` support (WordPress 7.0+) registers it in the
     * editor with a server-rendered preview and a sidebar field per prop. One with `children` holds
     * inner blocks, which `autoRegister` can't edit, so client/blocks.js registers it.
     */
    public static function register(): void
    {
        $blocks = self::all();
        if ($blocks !== []) {
            add_filter('rest_request_before_callbacks', static function (mixed $response, array $handler, \WP_REST_Request $request): mixed {
                self::$previewing = str_starts_with($request->get_route(), '/wp/v2/block-renderer/');
                return $response;
            }, 10, 3);
        }
        $nested = array_filter($blocks, static fn (array $spec): bool => !empty($spec['children']));
        if ($nested !== []) {
            add_action('enqueue_block_editor_assets', static function () use ($nested): void {
                wp_enqueue_script('spacepress-content-blocks', plugins_url('sp/Content/client/blocks.js', \SpacePress\Wp\Runtime::file()), ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element'], null, true);
                wp_add_inline_script('spacepress-content-blocks', 'window.spContentBlocks = ' . wp_json_encode($nested) . ';', 'before');
            });
        }
        foreach ($blocks as $name => $spec) {
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                continue;
            }
            register_block_type($name, [
                'title' => $spec['tag'],
                'category' => 'design',
                'attributes' => self::attributes($spec),
                'supports' => ['html' => false] + (empty($spec['children']) ? ['autoRegister' => true] : []),
                'render_callback' => static fn (array $attrs, string $content): string => self::render($spec, $attrs, $content),
            ]);
        }
    }

    /**
     * The attributes as the editor holds them. A choice prop the component may go without gets an empty
     * choice, so the editor's select can show and return to "not set"; a choice it needs defaults to its
     * first value, so the select shows what is saved. An empty string is an unset prop (self::props).
     */
    private static function attributes(array $spec): array
    {
        $attributes = $spec['attributes'];
        foreach ($attributes as $key => $attribute) {
            if (!isset($attribute['enum'])) {
                continue;
            }
            if (in_array($key, $spec['required'] ?? [], true)) {
                $attributes[$key]['default'] = $attribute['enum'][0];
            } else {
                $attributes[$key]['enum'] = ['', ...$attribute['enum']];
            }
        }

        return $attributes;
    }

    /**
     * A block's props, in the order they are stored: the attributes the component declares, without
     * an empty string (the editor stores a cleared field as `""`), and a required choice the block
     * doesn't store yet as its default.
     *
     * @return array<string, mixed>
     */
    private static function props(array $spec, array $attrs): array
    {
        $attributes = self::attributes($spec);
        $props = [];
        foreach ($attrs as $key => $value) {
            if (isset($attributes[$key]) && $value !== null && $value !== '') {
                $props[$key] = $value;
            }
        }
        foreach ($attributes as $key => $attribute) {
            if (!array_key_exists($key, $props) && isset($attribute['default'])) {
                $props[$key] = $attribute['default'];
            }
        }

        return $props;
    }

    /** The component, called with the block's attributes as props and its rendered inner blocks as `children`. */
    private static function render(array $spec, array $attrs, string $content): string
    {
        $plugin = Runtime::plugin();
        $function = $plugin?->schema->components()[$spec['component']] ?? null;
        if ($function === null) {
            return '';
        }
        $plugin->app();
        $props = self::props($spec, $attrs);
        $props['children'] = Html::raw($content);
        $fn = '\\SpacePressApp\\' . $function;
        $html = Html::child(Html::component($fn(...), new JsObject($props)));

        // The editor's preview (core's block renderer) puts the block's own box on the output's one
        // root element. Output with none (nothing, or bare text) or several gets one, so the block
        // can still be selected.
        if (self::previewing() && self::roots($html) !== 1) {
            return '<div class="spacepress-block-preview">' . ($html !== '' ? $html : esc_html('<' . $spec['tag'] . ' />')) . '</div>';
        }

        return $html;
    }

    /** Whether this is the block editor asking the server to render a block for its preview. */
    private static function previewing(): bool
    {
        return self::$previewing;
    }

    /** How many elements the HTML has at its top level; null if it has text there too. */
    private static function roots(string $html): ?int
    {
        $tags = \WP_HTML_Processor::create_fragment($html);
        if ($tags === null) {
            return null;
        }
        $roots = 0;
        $top = null;
        while ($tags->next_token()) {
            $depth = $tags->get_current_depth();
            $top ??= $depth;
            if ($depth !== $top) {
                continue;
            }
            $type = $tags->get_token_type();
            if ($type === '#text' && trim($tags->get_modifiable_text()) !== '') {
                return null;
            }
            if ($type === '#tag' && !$tags->is_tag_closer()) {
                $roots++;
            }
        }

        return $roots;
    }

    // ------------------------------------------------------------------ Markdown ⇄ blocks

    /**
     * Markdown → Markdown with each component tag replaced by a block-literal fence the converter
     * stores verbatim, and the Markdown between the tags converted on its own. Tags must open and
     * close on lines of their own.
     *
     * @param callable(string): string $convert Markdown to stored blocks
     */
    public static function toBlocks(string $markdown, callable $convert): string
    {
        if (self::all() === [] || !preg_match('/^<[A-Z]/m', $markdown)) {
            return $convert($markdown);
        }
        $lines = explode("\n", $markdown);
        $out = '';
        $text = [];
        $fence = null;
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = $fence === null ? $m[1] : (str_starts_with(ltrim($line), $fence) ? null : $fence);
            }
            $open = $fence === null ? self::openTag($line) : null;
            if ($open === null) {
                $text[] = $line;
                continue;
            }
            [$tag, $attrs, $selfClosing] = $open;
            $found = self::byTag($tag);
            if ($found === null) {
                throw new \SpacePress\HttpError(422, 'invalid_input', "<$tag> isn't a block this site has; list it in its component file's `export const blocks`");
            }
            [$name, $spec] = $found;
            $inner = [];
            if (!$selfClosing) {
                $depth = 1;
                for ($i++; $i < $n; $i++) {
                    if (self::openTag($lines[$i])?->offsetGet(0) === $tag && !(self::openTag($lines[$i])?->offsetGet(2) ?? false)) {
                        $depth++;
                    }
                    if (trim($lines[$i]) === "</$tag>" && --$depth === 0) {
                        break;
                    }
                    $inner[] = $lines[$i];
                }
                if ($depth !== 0) {
                    throw new \SpacePress\HttpError(422, 'invalid_input', "<$tag> is never closed: put </$tag> on a line of its own");
                }
            }
            $out .= self::flush($text, $convert);
            $text = [];
            $out .= self::block($name, $spec, $attrs, self::toBlocks(implode("\n", $inner), $convert));
        }

        return $out . self::flush($text, $convert);
    }

    /** @param list<string> $lines */
    private static function flush(array $lines, callable $convert): string
    {
        $markdown = implode("\n", $lines);
        return trim($markdown) === '' ? '' : $convert($markdown);
    }

    /**
     * A line that opens a component: `<Callout type="warning">` or `<Callout ... />`, alone on its
     * line. Attribute values are double-quoted strings, `{true}`/`{false}`, or `{number}`.
     *
     * @return ?\ArrayObject{0: string, 1: array<string, mixed>, 2: bool}
     */
    private static function openTag(string $line): ?\ArrayObject
    {
        if (preg_match('/^\s*<([A-Z][A-Za-z0-9]*)((?:\s+[a-z][A-Za-z0-9]*(?:=(?:"[^"]*"|\{[^}]*\}))?)*)\s*(\/?)>\s*$/', $line, $m) !== 1) {
            return null;
        }
        $attrs = [];
        preg_match_all('/([a-z][A-Za-z0-9]*)(?:=("([^"]*)"|\{([^}]*)\}))?/', $m[2], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $value = match (true) {
                !isset($pair[2]) => true,
                isset($pair[4]) && $pair[4] !== '' => match (trim($pair[4])) {
                    'true' => true,
                    'false' => false,
                    default => is_numeric(trim($pair[4])) ? 0 + trim($pair[4]) : throw new \SpacePress\HttpError(422, 'invalid_input', "{$pair[1]}={{$pair[4]}} isn't a value a block holds: use \"text\", {true}, {false} or a number"),
                },
                default => html_entity_decode($pair[3], ENT_QUOTES | ENT_HTML5),
            };
            $attrs[$pair[1]] = $value;
        }

        return new \ArrayObject([$m[1], $attrs, $m[3] === '/']);
    }

    /** One component block: its attributes checked against the component's props, its content inside. */
    private static function block(string $name, array $spec, array $attrs, string $inner): string
    {
        $tag = $spec['tag'];
        foreach ($attrs as $key => $value) {
            $type = $spec['attributes'][$key]['type'] ?? null;
            if ($type === null) {
                throw new \SpacePress\HttpError(422, 'invalid_input', "<$tag> has no prop `$key`; it takes " . implode(', ', array_keys($spec['attributes'])));
            }
            $ok = match ($type) {
                'string' => is_string($value) && (!isset($spec['attributes'][$key]['enum']) || in_array($value, $spec['attributes'][$key]['enum'], true)),
                'boolean' => is_bool($value),
                'number' => is_int($value) || is_float($value),
                default => false,
            };
            if (!$ok) {
                throw new \SpacePress\HttpError(422, 'invalid_input', "<$tag $key> takes " . (isset($spec['attributes'][$key]['enum']) ? implode(' | ', array_map(static fn ($v) => "\"$v\"", $spec['attributes'][$key]['enum'])) : $type));
            }
        }
        foreach ($spec['required'] ?? [] as $key) {
            if (!array_key_exists($key, $attrs)) {
                throw new \SpacePress\HttpError(422, 'invalid_input', "<$tag> needs `$key`");
            }
        }
        $comment = $attrs === [] ? '' : ' ' . (string) wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return "<!-- wp:$name$comment -->\n" . ($inner === '' ? '' : rtrim($inner, "\n") . "\n") . "<!-- /wp:$name -->\n\n";
    }

    /**
     * Stored blocks → Markdown: each component block becomes its tag around its inner blocks'
     * Markdown. Every other block goes through $produce.
     *
     * @param list<array> $blocks parsed blocks
     * @param callable(string): string $produce stored blocks (with no component block) to Markdown
     */
    public static function fromBlocks(array $blocks, callable $produce): string
    {
        $all = self::all();
        $out = [];
        $run = [];
        $flush = static function () use (&$run, &$out, $produce): void {
            if ($run !== []) {
                $markdown = trim($produce(\SpacePress\Sync\Blocks::serialize($run)));
                if ($markdown !== '') {
                    $out[] = $markdown;
                }
                $run = [];
            }
        };
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if (!is_string($name) || !isset($all[$name])) {
                if ($name !== null || trim((string) ($block['innerHTML'] ?? '')) !== '') {
                    $run[] = $block;
                }
                continue;
            }
            $flush();
            $spec = $all[$name];
            $attrs = '';
            foreach (self::props($spec, (array) ($block['attrs'] ?? [])) as $key => $value) {
                $attrs .= ' ' . $key . match (true) {
                    $value === true => '',
                    is_bool($value) => '={false}',
                    is_int($value) || is_float($value) => '={' . $value . '}',
                    default => '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5) . '"',
                };
            }
            $inner = trim(self::fromBlocks($block['innerBlocks'] ?? [], $produce));
            $out[] = $inner === '' ? "<{$spec['tag']}$attrs />" : "<{$spec['tag']}$attrs>\n$inner\n</{$spec['tag']}>";
        }
        $flush();

        return $out === [] ? '' : implode("\n\n", $out) . "\n";
    }

    /** Whether stored blocks hold a component block anywhere. */
    public static function has(array $blocks): bool
    {
        $all = self::all();
        foreach ($blocks as $block) {
            if (isset($all[$block['blockName'] ?? '']) || self::has($block['innerBlocks'] ?? [])) {
                return true;
            }
        }
        return false;
    }
}
