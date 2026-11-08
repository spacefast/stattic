<?php

declare(strict_types=1);

namespace Zero\Sync;

use Zero\HttpError;
use Zero\Vendor\WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use Zero\Vendor\WordPress\Markdown\MarkdownConsumer;
use Zero\Vendor\WordPress\Markdown\MarkdownProducer;
use WP_HTML_Tag_Processor as Tags;

/**
 * Markdown ⇄ Gutenberg blocks on the bundled WordPress php-toolkit (§5.2), whose HTML processing is core's
 * HTML API. Nothing here parses Markdown.
 *
 * Two measured properties shape it:
 *
 * 1. Markdown → blocks → Markdown is a fixed point, not an identity: the first pass may normalise, every
 *    later pass is byte-stable. Pull therefore returns the canonical Markdown, so a no-op sync stays one.
 * 2. Markdown can't carry every block. Before blocks flow out as Markdown they must survive the round trip
 *    as the same document (key()), else the record is refused rather than silently flattened.
 *
 * "The same document" is what the block editor reads, not the same bytes (key()). Heading ids are derived
 * from heading text, never stored, so editing a heading never strands a stale anchor. A fence language is
 * stored as the editor spells it (a `language-<x>` class), images as the editor writes the figure.
 */
final class Markdown
{
    /** Blocks MarkdownProducer writes as Markdown syntax (table included; the round trip then judges it). */
    private const MARKDOWN_BLOCKS = ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote',
        'core/code', 'core/image', 'core/table', 'core/separator'];

    public static function toBlocks(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (trim($markdown) === '') {
            return '';
        }

        return self::asStored(self::consume($markdown));
    }

    /** Stored blocks → Markdown, or 422 not_representable naming what Markdown can't carry. */
    public static function fromBlocks(string $blocks): string
    {
        if (trim($blocks) === '') {
            return '';
        }
        $unsupported = self::unsupported(Blocks::parse($blocks));
        if ($unsupported !== null) {
            throw new HttpError(422, 'not_representable', "$unsupported can't be written as Markdown; use *.html");
        }
        // The producer ends on a blank line; a file ends on one newline. Both read back as the same blocks.
        // The producer also closes every quote with empty `> ` lines; the gate below proves dropping them is lossless.
        $markdown = rtrim((string) preg_replace('/(?:^>[> ]*\n)+(?=\n|\z)/m', '', self::produce($blocks)), "\n") . "\n";
        $original = self::key(self::respellLegacy($blocks));
        $reparsed = self::key(self::toBlocks($markdown));
        $lost = self::firstLoss($reparsed, $original);
        if ($lost !== null) {
            throw new HttpError(422, 'not_representable', "$lost can't be written as Markdown; use *.html");
        }

        return $markdown;
    }

    /**
     * The first block Markdown has no syntax for. The producer would fence such a block's raw markup
     * (```block), which reads back but is not Markdown anyone can edit.
     */
    private static function unsupported(array $blocks): ?string
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name !== null && !in_array($name, self::MARKDOWN_BLOCKS, true)) {
                return $name;
            }
            if (($inner = self::unsupported($block['innerBlocks'] ?? [])) !== null) {
                return $inner;
            }
        }

        return null;
    }

    /** The toolkit's own markup, derived heading ids included. */
    private static function consume(string $markdown): string
    {
        Blocks::boot();
        try {
            return (new MarkdownConsumer($markdown))->consume()->get_block_markup();
        } catch (\Throwable $e) {
            throw new HttpError(422, 'invalid_input', 'The Markdown could not be converted to blocks: ' . $e->getMessage());
        }
    }

    private static function produce(string $blocks): string
    {
        Blocks::boot();
        if (str_contains($blocks, 'language-')) {
            $blocks = Blocks::serialize(array_map(self::fenceLanguage(...), Blocks::parse($blocks)));
        }
        try {
            return (new MarkdownProducer(new BlocksWithMetadata($blocks, [])))->produce();
        } catch (\Throwable $e) {
            throw new HttpError(422, 'not_representable', 'The blocks could not be written as Markdown: ' . $e->getMessage());
        }
    }

    // ================================================================ the editor's spelling

    private static function asStored(string $blocks): string
    {
        if (!preg_match('/<!-- wp:(heading|code|image)[ \n]/', $blocks)) {
            return $blocks;
        }

        return Blocks::serialize(array_map(static fn (array $b): array => self::storeBlock($b, true), Blocks::parse($blocks)));
    }

    /** Code/image blocks in the toolkit's own spelling, respelled as stored; one document either way. */
    private static function respellLegacy(string $blocks): string
    {
        if (!preg_match('/<!-- wp:(code|image)[ \n]/', $blocks)) {
            return $blocks;
        }

        return Blocks::serialize(array_map(static fn (array $b): array => self::storeBlock($b, false), Blocks::parse($blocks)));
    }

    private static function storeBlock(array $block, bool $stripHeadingIds): array
    {
        $block = match ($block['blockName'] ?? null) {
            'core/heading' => $stripHeadingIds ? self::stripHeadingId($block) : $block,
            'core/code' => self::storeCode($block),
            'core/image' => self::storeImage($block),
            default => $block,
        };
        $block['innerBlocks'] = array_map(static fn (array $inner): array => self::storeBlock($inner, $stripHeadingIds), $block['innerBlocks'] ?? []);

        return $block;
    }

    private static function stripHeadingId(array $block): array
    {
        if (!is_string($html = $block['innerContent'][0] ?? null)) {
            return $block;
        }
        $tags = new Tags($html);
        $id = $tags->next_tag() ? $tags->get_attribute('id') : null;
        if (is_string($id)) {
            // The toolkit writes ` id="<slug>"`; cutting exactly those bytes leaves the editor's spelling.
            $spelled = ' id="' . $id . '"';
            $at = strpos($html, $spelled);
            if ($at !== false && $at < (int) strpos($html, '>')) {
                $html = substr_replace($html, '', $at, strlen($spelled));
            } else {
                $tags->remove_attribute('id');
                $html = $tags->get_updated_html();
            }
            $block['innerContent'][0] = $html;
            $block['innerHTML'] = $html;
        }

        return $block;
    }

    /** Core's code block has no `language` attribute; the editor keeps a `language-<x>` class. */
    private static function storeCode(array $block): array
    {
        $language = $block['attrs']['language'] ?? null;
        unset($block['attrs']['language']);
        if (!is_string($language) || $language === '' || !is_string($block['innerContent'][0] ?? null)) {
            return $block;
        }
        $class = 'language-' . $language;
        $tags = new Tags($block['innerContent'][0]);
        if (!$tags->next_tag('PRE')) {
            return $block;
        }
        $tags->add_class($class);
        $html = $tags->get_updated_html();
        $block['attrs']['className'] = $class;
        $block['innerContent'][0] = $html;
        $block['innerHTML'] = $html;

        return $block;
    }

    /** The figure on one line, extra classes as `className`, an `alt` even when empty. */
    private static function storeImage(array $block): array
    {
        if (!is_string($block['innerContent'][0] ?? null)) {
            return $block;
        }
        $html = (string) preg_replace('/>\s+</', '><', trim($block['innerContent'][0]));
        $tags = new Tags($html);
        if (!$tags->next_tag('FIGURE')) {
            return $block;
        }
        $classes = array_values(array_diff(iterator_to_array($tags->class_list()), ['wp-block-image']));
        if ($classes !== []) {
            $block['attrs']['className'] = implode(' ', $classes);
        }
        if ($tags->next_tag('IMG') && $tags->get_attribute('alt') === null) {
            $tags->set_attribute('alt', '');
        }
        $html = "\n" . $tags->get_updated_html() . "\n";
        $block['innerContent'][0] = $html;
        $block['innerHTML'] = $html;

        return $block;
    }

    /** The `language` attribute the producer reads, from the class the editor keeps. */
    private static function fenceLanguage(array $block): array
    {
        if (($block['blockName'] ?? null) === 'core/code' && !isset($block['attrs']['language'])
            && preg_match('/(?:^|\s)language-(\S+)/', (string) ($block['attrs']['className'] ?? ''), $m) === 1) {
            $block['attrs']['language'] = $m[1];
        }
        $block['innerBlocks'] = array_map(self::fenceLanguage(...), $block['innerBlocks'] ?? []);

        return $block;
    }

    // ================================================================ document key

    /**
     * A block document as the editor reads it: two markups with the same key are one document. Left out is
     * only what the editor treats as spelling: whitespace between delimiters, attribute order and text
     * escaping, an `anchor` equal to the root id, a heading id equal to the derived one, `<b>`/`<strong>`
     * and `<i>`/`<em>`, and in code `<br>` vs newline plus the one trailing newline a fence implies.
     *
     * @return list<array>
     */
    public static function key(string $blocks): array
    {
        Blocks::boot();
        $key = [];
        foreach (Blocks::parse($blocks) as $block) {
            if (($node = self::blockKey($block)) !== null) {
                $key[] = $node;
            }
        }

        return $key;
    }

    private static function blockKey(array $block): ?array
    {
        $children = $block['innerBlocks'] ?? [];
        $index = 0;
        $inner = [];
        foreach ($block['innerContent'] ?? [] as $chunk) {
            if (is_string($chunk)) {
                array_push($inner, ...self::htmlKey($chunk));
                continue;
            }
            $child = is_array($children[$index] ?? null) ? self::blockKey($children[$index]) : null;
            $index++;
            if ($child !== null) {
                $inner[] = $child;
            }
        }
        $name = $block['blockName'] ?? null;
        if (!is_string($name)) {
            return $inner === [] ? null : ['block' => 'core/freeform', 'attrs' => [], 'inner' => $inner];
        }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $root = null;
        foreach ($inner as $position => $token) {
            if (isset($token['tag'])) {
                $root = $position;
                break;
            }
        }
        $rootId = $root === null ? null : ($inner[$root]['attrs']['id'] ?? null);
        if (is_string($rootId) && ($attrs['anchor'] ?? null) === $rootId) {
            unset($attrs['anchor']);
        }
        if ($name === 'core/heading' && is_string($rootId) && $rootId === self::headingId((string) ($block['innerHTML'] ?? ''), $inner)) {
            unset($inner[$root]['attrs']['id']);
        }
        if ($name === 'core/code') {
            $inner = self::codeLines($inner);
        }

        return ['block' => $name, 'attrs' => self::sorted($attrs), 'inner' => $inner];
    }

    /** @param list<array> $tokens */
    private static function codeLines(array $tokens): array
    {
        $lines = [];
        foreach ($tokens as $token) {
            if (($token['tag'] ?? null) === 'BR') {
                $token = ['token' => '#text', 'text' => "\n"];
            }
            $last = array_key_last($lines);
            if ($last !== null && ($token['token'] ?? null) === '#text' && ($lines[$last]['token'] ?? null) === '#text') {
                $lines[$last]['text'] .= $token['text'];
                continue;
            }
            $lines[] = $token;
        }
        foreach ($lines as $position => $token) {
            if (($token['token'] ?? null) === '#text' && str_ends_with((string) $token['text'], "\n")
                && (($lines[$position + 1]['close'] ?? null) === 'CODE')) {
                $lines[$position]['text'] = substr((string) $token['text'], 0, -1);
            }
        }

        return $lines;
    }

    /** @return list<array> one chunk of a block's own HTML as tokens */
    private static function htmlKey(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }
        $tokens = [];
        $tags = new Tags($html);
        while ($tags->next_token()) {
            $type = $tags->get_token_type();
            if ($type === '#tag') {
                $tag = match ($name = (string) $tags->get_tag()) {
                    'B' => 'STRONG',
                    'I' => 'EM',
                    default => $name,
                };
                if ($tags->is_tag_closer()) {
                    $tokens[] = ['close' => $tag];
                    continue;
                }
                $attributes = [];
                foreach ($tags->get_attribute_names_with_prefix('') ?? [] as $attribute) {
                    $attributes[$attribute] = $tags->get_attribute($attribute);
                }
                ksort($attributes, SORT_STRING);
                $tokens[] = ['tag' => $tag, 'attrs' => $attributes];
                continue;
            }
            $tokens[] = ['token' => $type, 'text' => $tags->get_modifiable_text()];
        }

        return $tokens;
    }

    /**
     * The id Markdown gives a heading: the consumer slugs CommonMark text nodes (links, emphasis, image alt;
     * not code spans or inline HTML). The cheap reading matches it unless the heading has an image or `<`
     * in its text; then the toolkit itself answers.
     *
     * @param list<array> $tokens
     */
    private static function headingId(string $html, array $tokens): string
    {
        $text = '';
        $code = 0;
        foreach ($tokens as $token) {
            if (($token['tag'] ?? null) === 'IMG') {
                return self::headingIdByToolkit($html);
            }
            if (($token['tag'] ?? null) === 'CODE') {
                $code++;
            } elseif (($token['close'] ?? null) === 'CODE') {
                $code = max(0, $code - 1);
            } elseif (($token['token'] ?? null) === '#text' && $code === 0) {
                if (str_contains((string) $token['text'], '<')) {
                    return self::headingIdByToolkit($html);
                }
                $text .= (string) $token['text'];
            }
        }

        return (string) preg_replace('/[^a-z0-9]+/i', '-', trim(strtolower($text)));
    }

    private static function headingIdByToolkit(string $html): string
    {
        try {
            $tags = new Tags(self::consume(self::produce("<!-- wp:heading -->\n" . trim($html) . "\n<!-- /wp:heading -->")));
        } catch (HttpError) {
            return '';
        }
        while ($tags->next_tag()) {
            if (preg_match('/^H[1-6]$/', (string) $tags->get_tag()) === 1) {
                $id = $tags->get_attribute('id');

                return is_string($id) ? $id : '';
            }
        }

        return '';
    }

    /**
     * The first block whose meaning the round trip lost, as "core/table", or null when everything the
     * original says survives. Attributes compare one way: the round trip may spell out a default the
     * original left implicit, never drop or change what it says.
     */
    private static function firstLoss(array $reparsed, array $original): ?string
    {
        $context = null;
        foreach ($original as $position => $node) {
            $candidate = $reparsed[$position] ?? null;
            $name = $node['block'] ?? $context ?? 'the document';
            if (!is_array($candidate)) {
                return $name;
            }
            if (!isset($node['block'])) {
                if ($candidate !== $node) {
                    return $name;
                }
                continue;
            }
            if (($candidate['block'] ?? null) !== $node['block']) {
                return $name;
            }
            foreach ($node['attrs'] as $attr => $value) {
                if (!array_key_exists($attr, $candidate['attrs']) || $candidate['attrs'][$attr] !== $value) {
                    return $name;
                }
            }
            if (count($candidate['inner']) !== count($node['inner'])) {
                return $name;
            }
            foreach ($node['inner'] as $i => $inner) {
                if (isset($inner['block'])) {
                    if (($lost = self::firstLoss([$candidate['inner'][$i]], [$inner])) !== null) {
                        return $lost;
                    }
                } elseif ($candidate['inner'][$i] !== $inner) {
                    return $name;
                }
            }
        }

        return count($reparsed) === count($original) ? null : 'the document';
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::sorted(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
