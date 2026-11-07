<?php

declare(strict_types=1);

namespace Zero\Sync;

/**
 * Block markup parsing and serialization with the bundled WordPress block parser, so sync reads and
 * writes delimiters exactly as core does. The toolkit's Markdown producer and HTML processor call a
 * few WordPress functions, which the loaded WordPress provides.
 */
final class Blocks
{
    public static function boot(): void
    {
        if (!function_exists('parse_blocks')) {
            throw new \LogicException('Zero\\Sync\\Blocks needs WordPress loaded (parse_blocks)');
        }
    }

    /** @return list<array{blockName: ?string, attrs: array, innerBlocks: list<array>, innerHTML: string, innerContent: list<?string>}> */
    public static function parse(string $markup): array
    {
        return (new \Zero_Vendor_WP_Block_Parser())->parse($markup);
    }

    /** @param list<array> $blocks */
    public static function serialize(array $blocks): string
    {
        return implode('', array_map(self::serializeBlock(...), $blocks));
    }

    /** WordPress's serialize_block(), byte for byte. */
    public static function serializeBlock(array $block): string
    {
        $content = '';
        $index = 0;
        foreach ($block['innerContent'] as $chunk) {
            $content .= is_string($chunk) ? $chunk : self::serializeBlock($block['innerBlocks'][$index++]);
        }
        $name = $block['blockName'];
        if ($name === null) {
            return $content;
        }
        $name = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
        $attrs = is_array($block['attrs'] ?? null) && $block['attrs'] !== [] ? self::attributes($block['attrs']) . ' ' : '';

        return $content === ''
            ? "<!-- wp:$name $attrs/-->"
            : "<!-- wp:$name $attrs-->$content<!-- /wp:$name -->";
    }

    private static function attributes(array $attrs): string
    {
        $json = (string) json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strtr($json, ['--' => '\\u002d\\u002d', '<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', '\\"' => '\\u0022']);
    }

    /**
     * A block's own HTML with the delimiters dropped: innerContent interleaves its markup with a null
     * for each inner block.
     */
    public static function innerHtml(array $block): string
    {
        $html = '';
        $index = 0;
        foreach ($block['innerContent'] ?? [] as $chunk) {
            if (is_string($chunk)) {
                $html .= $chunk;
            } elseif (is_array($child = $block['innerBlocks'][$index++] ?? null)) {
                $html .= self::innerHtml($child);
            }
        }

        return $html;
    }
}
