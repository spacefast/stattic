<?php

declare(strict_types=1);

namespace Zero\Sync;

/**
 * Block markup parsing and serialization through WordPress core (parse_blocks, serialize_blocks), so sync
 * reads and writes delimiters exactly as the editor does.
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
        self::boot();

        return parse_blocks($markup);
    }

    /** @param list<array> $blocks */
    public static function serialize(array $blocks): string
    {
        self::boot();

        return serialize_blocks($blocks);
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
