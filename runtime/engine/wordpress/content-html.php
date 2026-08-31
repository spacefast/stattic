<?php
/**
 * The HTML serializer: the other format `content-source-sync.php` reconciles
 * through, and the one customers actually see.
 *
 * Block markup is an internal storage detail. A `<!-- wp: -->` comment never
 * reaches a repo file, an API response, or an agent — HTML does, in both
 * directions. This file is the whole of that conversion.
 *
 * The two directions are deliberately asymmetric, because only one of them
 * needs to guess:
 *
 * - **blocks -> HTML** is WordPress's own `parse_blocks()` plus a walk of
 *   `innerContent`. A static block's saved markup already CONTAINS its HTML;
 *   serializing is dropping the delimiter comments, not rendering. Nothing is
 *   interpreted, so nothing can be misinterpreted.
 * - **HTML -> blocks** is Automattic's pinned blocks-engine transformer
 *   (`SPACEFAST_WORDPRESS_COMPONENT_RELEASE.blockTransformer`). Deciding that a
 *   `<figure>` wrapping an `<img>` and a `<figcaption>` is a `core/image` is
 *   real analysis, and we do not do it ourselves.
 *
 * Everything below is measured against those pinned bytes, not assumed; the
 * table lives in runtime/tests/content-html-sync.test.ts. Three findings drive
 * the design:
 *
 * 1. HTML -> blocks -> HTML is a FIXED POINT for the supported vocabulary, the
 *    same way Markdown is: the first pass normalizes (a heading gains
 *    `wp-block-heading`, `<img>` gains its self-closing slash) and every later
 *    pass is byte-stable. The ledger therefore stores the CANONICAL HTML.
 * 2. Some documents never reach a fixed point. A `<table>` is given a class
 *    derived from its own class list, so the class feeds back into the hash and
 *    the markup oscillates with period two. Buttons and embeds decay one step
 *    further on each pass.
 * 3. Some documents reach a fixed point by LOSING something. A
 *    `<div class="wp-block-group">` wrapper is dropped outright, `<form>`
 *    disappears, and `<marquee>` becomes a paragraph — all stable, all wrong.
 *    A fixed-point check alone would call these fine.
 *
 * So the gate is two-sided: the canonical form must be a fixed point AND it
 * must contain the same elements, in the same order, as what it came from.
 * Attribute and class changes are canonicalization and are allowed; a tag
 * appearing or vanishing is not. Failing either way is
 * `content_html_not_representable` — the twin of the Markdown lane's refusal,
 * and never a silent rewrite of a customer's file.
 */
declare(strict_types=1);

/**
 * Make the blocks-engine transformer callable, or fail closed.
 *
 * On a wp.cloud box the plugin is already active by the time WordPress finishes
 * booting, so the first branch is the live one. The explicit path is how the
 * runtime tests load the identical pinned bytes without a WordPress install;
 * the glob covers the versioned directory name the plugin archive unpacks to.
 */
function spacefast_content_html_require_transformer(): void
{
    if (function_exists('blocks_engine_php_transformer_transform_html')) {
        return;
    }
    $candidates = [];
    $configured = $GLOBALS['SPACEFAST_CONTENT_BLOCKS_ENGINE_PLUGIN'] ?? null;
    if (is_string($configured) && $configured !== '') {
        $candidates[] = $configured;
    }
    if (defined('WP_PLUGIN_DIR')) {
        $matches = glob(WP_PLUGIN_DIR . '/blocks-engine-php-transformer*/php-transformer.php');
        foreach (is_array($matches) ? $matches : [] as $match) {
            $candidates[] = $match;
        }
    }
    foreach ($candidates as $entrypoint) {
        if (is_file($entrypoint)) {
            require_once $entrypoint;
            if (function_exists('blocks_engine_php_transformer_transform_html')) {
                return;
            }
        }
    }
    throw new Spacefast_Content_Error(
        503,
        'content_html_transformer_unavailable',
        'The blocks-engine HTML transformer is not installed on this site.'
    );
}

/**
 * `parse_blocks()` and `WP_HTML_Tag_Processor` come from WordPress core in
 * production. The runtime tests have no WordPress install, and the pinned
 * php-toolkit PHAR carries the identical implementations, so the Markdown
 * lane's loader doubles as this one's fallback.
 */
function spacefast_content_html_require_block_parser(): void
{
    if (function_exists('parse_blocks') && class_exists('WP_HTML_Tag_Processor')) {
        return;
    }
    spacefast_content_sync_require_toolkit();
    if (!function_exists('parse_blocks') || !class_exists('WP_HTML_Tag_Processor')) {
        throw new Spacefast_Content_Error(
            503,
            'content_html_block_parser_unavailable',
            'This site cannot parse block markup.'
        );
    }
}

function spacefast_content_html_from_blocks(string $blocks): string
{
    if (trim($blocks) === '') {
        return '';
    }
    spacefast_content_html_require_block_parser();
    try {
        $html = '';
        foreach (parse_blocks($blocks) as $block) {
            $html .= spacefast_content_html_block_inner($block);
        }
        return trim($html);
    } catch (Spacefast_Content_Error $error) {
        throw $error;
    } catch (Throwable $error) {
        error_log('spacefast html serialize failed: ' . $error->getMessage());
        throw new Spacefast_Content_Error(
            422,
            'content_html_serialize_failed',
            'The WordPress document could not be serialized to HTML.'
        );
    }
}

/**
 * One block's HTML.
 *
 * `innerContent` is the block's own markup with a null standing in for each
 * inner block, which is exactly the interleaving needed to drop the delimiters
 * and keep everything between them.
 */
function spacefast_content_html_block_inner(array $block): string
{
    $innerBlocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
    $index = 0;
    $html = '';
    foreach (is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [] as $chunk) {
        if (is_string($chunk)) {
            $html .= $chunk;
            continue;
        }
        $child = $innerBlocks[$index] ?? null;
        $index++;
        if (is_array($child)) {
            $html .= spacefast_content_html_block_inner($child);
        }
    }
    return $html;
}

function spacefast_content_html_to_blocks(string $html): string
{
    if (trim($html) === '') {
        return '';
    }
    spacefast_content_html_require_transformer();
    try {
        $result = blocks_engine_php_transformer_transform_html($html);
    } catch (Throwable $error) {
        error_log('spacefast html transform failed: ' . $error->getMessage());
        throw new Spacefast_Content_Error(
            422,
            'content_html_compile_failed',
            'The HTML document could not be converted to WordPress blocks.'
        );
    }
    $blocks = $result['serialized_blocks'] ?? null;
    if (!is_string($blocks)) {
        throw new Spacefast_Content_Error(
            422,
            'content_html_compile_failed',
            'The HTML document could not be converted to WordPress blocks.'
        );
    }
    return trim($blocks);
}

/**
 * The element names an HTML fragment opens, in document order.
 *
 * This is the structural half of the representability gate. It deliberately
 * ignores attributes: gaining `wp-block-heading` is canonicalization, losing
 * the `<div>` that wrapped the document is not.
 */
function spacefast_content_html_tag_sequence(string $html): array
{
    spacefast_content_html_require_block_parser();
    $tags = [];
    $processor = new WP_HTML_Tag_Processor($html);
    while ($processor->next_tag()) {
        if ($processor->is_tag_closer()) {
            continue;
        }
        $tags[] = strtolower((string) $processor->get_tag());
    }
    return $tags;
}

/** One serializer pass: what this HTML means as a WordPress document. */
function spacefast_content_html_normalize(string $html): string
{
    return spacefast_content_html_from_blocks(spacefast_content_html_to_blocks($html));
}

/**
 * The ledger's spelling of an HTML document, or a refusal.
 *
 * A source file is arbitrary bytes a person wrote; this is the one place that
 * decides what those bytes mean. Both halves of the gate apply here, because a
 * document the round trip would quietly reshape must never become the common
 * base the two sides then agree on.
 */
function spacefast_content_html_canonical(string $html): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $html);
    if (trim($normalized) === '') {
        return '';
    }
    $canonical = spacefast_content_html_normalize($normalized);
    if (spacefast_content_html_normalize($canonical) !== $canonical) {
        throw new Spacefast_Content_Error(
            409,
            'content_html_not_representable',
            'This HTML does not survive a WordPress round trip unchanged. Its source file was left untouched.'
        );
    }
    if (spacefast_content_html_tag_sequence($canonical) !== spacefast_content_html_tag_sequence($normalized)) {
        throw new Spacefast_Content_Error(
            409,
            'content_html_not_representable',
            'This HTML uses elements WordPress blocks cannot carry. Its source file was left untouched.'
        );
    }
    return $canonical;
}

/**
 * Whether these blocks survive being expressed as HTML.
 *
 * The twin of the Markdown lane's gate, and the same question: round trip the
 * document and see whether it comes back. The comparison normalizes the
 * whitespace BETWEEN delimiters, because the editor writes a newline there and
 * the transformer does not, and neither is part of the document.
 *
 * This is what catches a dynamic block. `<!-- wp:latest-posts /-->` has no
 * inner HTML at all, so it serializes to nothing and comes back as nothing —
 * a total loss that a check on the HTML alone would never see.
 */
function spacefast_content_html_representable(string $blocks): bool
{
    if (trim($blocks) === '') {
        return true;
    }
    $reparsed = spacefast_content_html_to_blocks(spacefast_content_html_from_blocks($blocks));
    return spacefast_content_html_canonical_block_markup($reparsed)
        === spacefast_content_html_canonical_block_markup($blocks);
}

/**
 * Block markup with the insignificant whitespace taken out, so two spellings of
 * one document compare equal. Nothing else is touched: block names, attributes,
 * and inner HTML all still have to match exactly.
 */
function spacefast_content_html_canonical_block_markup(string $blocks): string
{
    spacefast_content_html_require_block_parser();
    $markup = '';
    foreach (parse_blocks($blocks) as $block) {
        $markup .= spacefast_content_html_canonical_block($block);
    }
    return $markup;
}

function spacefast_content_html_canonical_block(array $block): string
{
    $name = $block['blockName'] ?? null;
    $innerBlocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
    $index = 0;
    $inner = '';
    foreach (is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [] as $chunk) {
        if (is_string($chunk)) {
            $inner .= trim($chunk);
            continue;
        }
        $child = $innerBlocks[$index] ?? null;
        $index++;
        if (is_array($child)) {
            $inner .= spacefast_content_html_canonical_block($child);
        }
    }
    // A freeform block is the whitespace between two real ones. Dropping the
    // empty ones is the whole point of this normalization.
    if (!is_string($name)) {
        return $inner;
    }
    $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
    $encoded = $attributes === []
        ? ''
        : ' ' . spacefast_content_sync_canonical_json($attributes);
    return '<!-- wp:' . $name . $encoded . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
}
