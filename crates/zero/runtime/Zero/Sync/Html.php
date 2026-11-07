<?php

declare(strict_types=1);

namespace Zero\Sync;

use Zero\HttpError;

/**
 * The `.html` escape hatch (§5.2): push stores the HTML as-is (one classic block); pull drops the block
 * delimiters, which is lossless only when every block is static, i.e. its saved markup holds its whole
 * output. A dynamic block (`<!-- wp:latest-posts /-->`) renders on the server from attributes alone and
 * would vanish, so it is refused by name.
 */
final class Html
{
    /** Core blocks whose saved markup is their whole output (WordPress 7.1). */
    private const STATIC_CORE = ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/pullquote',
        'core/code', 'core/preformatted', 'core/verse', 'core/image', 'core/gallery', 'core/table', 'core/separator', 'core/spacer',
        'core/group', 'core/columns', 'core/column', 'core/buttons', 'core/button', 'core/cover', 'core/media-text', 'core/html',
        'core/freeform', 'core/details', 'core/audio', 'core/video', 'core/file'];

    public static function strip(string $blocks): string
    {
        if (trim($blocks) === '') {
            return '';
        }
        Blocks::boot();
        $html = '';
        foreach (Blocks::parse($blocks) as $block) {
            self::requireStatic($block);
            $html .= Blocks::innerHtml($block);
        }

        return trim($html);
    }

    public static function fromHtml(string $html): string
    {
        return str_replace(["\r\n", "\r"], "\n", $html);
    }

    /**
     * A block is static when its saved markup is its whole output. WordPress knows from the block
     * registry (a block without a render callback); without WordPress only core blocks known to save
     * their full output count, and anything else is refused rather than guessed.
     */
    private static function requireStatic(array $block): void
    {
        $name = $block['blockName'] ?? null;
        if (is_string($name) && !self::isStatic($name)) {
            throw new HttpError(422, 'not_representable', "$name renders on the server and can't be written as HTML");
        }
        foreach ($block['innerBlocks'] ?? [] as $inner) {
            self::requireStatic($inner);
        }
    }

    private static function isStatic(string $name): bool
    {
        if (class_exists(\WP_Block_Type_Registry::class)) {
            $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);

            return $type !== null && !$type->is_dynamic();
        }

        return in_array($name, self::STATIC_CORE, true);
    }
}
