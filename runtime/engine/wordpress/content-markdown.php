<?php
/**
 * The Markdown serializer: one of the two formats `content-source-sync.php`
 * reconciles through.
 *
 * Serialization is WordPress's own php-toolkit (the Data Liberation plugin
 * `SPACEFAST_WORDPRESS_SUBSTRATE.dataLiberationPlugin` pins): MarkdownConsumer
 * for Markdown -> blocks and MarkdownProducer for blocks -> Markdown. Nothing
 * here hand-rolls a Markdown parser, and the blocks engine is never asked to
 * run backwards.
 *
 * Two properties of that toolkit shape this file, and both are measured, not
 * assumed (see runtime/tests/content-source-sync.test.ts):
 *
 * 1. Markdown -> blocks -> Markdown is a FIXED POINT, not an identity. The
 *    first pass may add a trailing newline or normalize a construct; every
 *    later pass is byte-stable. So the ledger stores the CANONICAL Markdown,
 *    never the raw source bytes. Digesting raw bytes would report a change on
 *    every single sync.
 * 2. Markdown cannot represent every block. A paragraph carrying `align` or
 *    `className` comes back without them. So any time WordPress content flows
 *    OUT to a source file, the blocks must first survive a round trip
 *    unchanged; if they do not, this refuses rather than silently dropping
 *    what the editor added. That is the fail-closed rule.
 */
declare(strict_types=1);

/**
 * Make the php-toolkit classes loadable, or fail closed.
 *
 * On a wp.cloud box the Data Liberation plugin is installed but never active:
 * activating it loads the PHAR before wp-admin's media declarations and can
 * fatal on a duplicate function. This loader is the single place the PHAR
 * enters a WordPress process. The explicit PHAR path also lets runtime tests
 * load the identical pinned bytes without a WordPress install.
 */
function spacefast_content_sync_require_toolkit(): void
{
    if (class_exists('WordPress\\Markdown\\MarkdownConsumer')) {
        return;
    }
    $candidates = [];
    $configured = $GLOBALS['SPACEFAST_CONTENT_PHP_TOOLKIT_PHAR'] ?? null;
    if (is_string($configured) && $configured !== '') {
        $candidates[] = $configured;
    }
    if (defined('WP_PLUGIN_DIR')) {
        $candidates[] = WP_PLUGIN_DIR . '/data-liberation/php-toolkit.phar';
    }
    foreach ($candidates as $phar) {
        if (is_file($phar)) {
            spacefast_content_sync_preload_polyfill_targets();
            require_once 'phar://' . $phar . '/vendor/autoload.php';
            if (class_exists('WordPress\\Markdown\\MarkdownConsumer')) {
                return;
            }
        }
    }
    throw new Spacefast_Content_Error(
        503,
        'content_markdown_toolkit_unavailable',
        'The WordPress Markdown toolkit is not installed on this site.'
    );
}

/** Load core's unguarded declaration before the PHAR can polyfill it. */
function spacefast_content_sync_preload_polyfill_targets(): void
{
    if (function_exists('wp_read_audio_metadata') || !defined('ABSPATH')) {
        return;
    }
    $media = ABSPATH . 'wp-admin/includes/media.php';
    if (is_file($media)) {
        require_once $media;
    }
}

function spacefast_content_markdown_to_blocks(string $markdown): string
{
    spacefast_content_sync_require_toolkit();
    try {
        $consumer = new WordPress\Markdown\MarkdownConsumer($markdown);
        $consumer->consume();
        return $consumer->get_block_markup();
    } catch (Spacefast_Content_Error $error) {
        throw $error;
    } catch (Throwable $error) {
        error_log('spacefast markdown consume failed: ' . $error->getMessage());
        throw new Spacefast_Content_Error(
            422,
            'content_markdown_compile_failed',
            'The Markdown document could not be converted to WordPress blocks.'
        );
    }
}

function spacefast_content_markdown_from_blocks(string $blocks): string
{
    if (trim($blocks) === '') {
        return '';
    }
    spacefast_content_sync_require_toolkit();
    try {
        $document = new WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata($blocks, []);
        return (new WordPress\Markdown\MarkdownProducer($document))->produce();
    } catch (Spacefast_Content_Error $error) {
        throw $error;
    } catch (Throwable $error) {
        error_log('spacefast markdown produce failed: ' . $error->getMessage());
        throw new Spacefast_Content_Error(
            422,
            'content_markdown_serialize_failed',
            'The WordPress document could not be serialized to Markdown.'
        );
    }
}

/**
 * The ledger's spelling of a Markdown document. Applying the serializer in both
 * directions lands on the toolkit's fixed point, so two Markdown files that
 * mean the same thing digest the same and a no-op sync stays a no-op.
 */
function spacefast_content_markdown_canonical(string $markdown): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
    if (trim($normalized) === '') {
        return '';
    }
    return spacefast_content_markdown_from_blocks(
        spacefast_content_markdown_to_blocks($normalized)
    );
}

/**
 * Whether these blocks survive being expressed as Markdown.
 *
 * Markdown is lossy for block attributes, so this is the gate on every path
 * that would write WordPress content back into a repo file. Failing it is not
 * an error in the document — it means this document is richer than Markdown
 * and must not be flattened into one.
 */
function spacefast_content_markdown_representable(string $blocks): bool
{
    if (trim($blocks) === '') {
        return true;
    }
    $markdown = spacefast_content_markdown_from_blocks($blocks);
    $reparsed = spacefast_content_markdown_to_blocks($markdown);
    return trim($reparsed) === trim($blocks);
}
