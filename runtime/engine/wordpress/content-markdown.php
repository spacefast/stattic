<?php
/**
 * The Markdown serializer: one of the two formats `content-source-sync.php`
 * reconciles through.
 *
 * Serialization is WordPress's pinned php-toolkit: MarkdownConsumer
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
 *
 * "Unchanged" means the same document, not the same bytes. The block editor
 * re-spells every document it saves, even one nobody touched: it moves whitespace,
 * drops attributes equal to their defaults, reorders HTML attributes, and copies
 * each root `id` into an `anchor` attribute. None of that is an edit, so the gate
 * compares documents in the form the editor itself reads them
 * (spacefast_content_markdown_document_key), and every round-trip fixture in the
 * tests is markup the editor's own serializer wrote.
 *
 * Heading ids are not stored. Markdown has no syntax for one; a heading's id
 * is derived from its text. The toolkit writes that derivation into the HTML,
 * and the editor would then adopt it as an authored anchor and keep it after
 * every later change to the heading's text. That stale anchor is something
 * Markdown cannot carry, so one edit to a heading's text would block every
 * publish. So the import omits the id, and spacefast_content_markdown_render_heading
 * derives it when the document is rendered.
 *
 * Two more of the toolkit's spellings are not the editor's, and the import
 * rewrites them so that what WordPress stores is what the editor would store
 * (spacefast_content_markdown_as_stored). A fence's language is a `language`
 * attribute to the toolkit, which core's code block does not have, so the
 * editor drops it on save; the editor's spelling is a `language-<x>` class, and
 * the way out (spacefast_content_markdown_from_blocks) reads the fence language
 * back from it. An image is a figure the toolkit indents with tabs and the
 * editor stores on one line, with the figure's extra classes as `className`.
 */
declare(strict_types=1);

/**
 * Make the php-toolkit classes loadable, or fail closed.
 *
 * The immutable engine release ships the PHAR. Load it lazily after core's
 * media declarations so its polyfills cannot collide with WordPress.
 * The explicit PHAR path lets runtime tests use the same pinned bytes.
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
    $candidates[] = __DIR__ . '/../vendor/php-toolkit.phar';
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

/**
 * Markdown -> blocks, in the form WordPress stores: the toolkit's markup as the
 * editor spells it (see the file header).
 */
function spacefast_content_markdown_to_blocks(string $markdown): string
{
    return spacefast_content_markdown_as_stored(spacefast_content_markdown_consume($markdown));
}

/** The toolkit's own markup for a Markdown document, derived heading ids included. */
function spacefast_content_markdown_consume(string $markdown): string
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

/** The toolkit's markup in the form the editor stores it. */
function spacefast_content_markdown_as_stored(string $blocks): string
{
    if (!preg_match('/<!-- wp:(heading|code|image)[ \n]/', $blocks)) {
        return $blocks;
    }
    return serialize_blocks(array_map('spacefast_content_markdown_store_block', parse_blocks($blocks)));
}

/**
 * Code and image blocks an earlier engine stored in the toolkit's own spelling
 * (a `language` attribute, an indented figure), respelled the way the import
 * stores them now. Both spellings are one document, so the gate keys a stored
 * document through this. Heading ids are left alone: whether a stored id is
 * derived or authored is the key's decision, not a respelling.
 */
function spacefast_content_markdown_respell_legacy(string $blocks): string
{
    if (!preg_match('/<!-- wp:(code|image)[ \n]/', $blocks)) {
        return $blocks;
    }
    return serialize_blocks(array_map(
        static fn (array $block): array => spacefast_content_markdown_store_block($block, false),
        parse_blocks($blocks)
    ));
}

function spacefast_content_markdown_store_block(array $block, bool $stripHeadingIds = true): array
{
    $block = match ($block['blockName'] ?? null) {
        'core/heading' => $stripHeadingIds ? spacefast_content_markdown_strip_heading_id($block) : $block,
        'core/code' => spacefast_content_markdown_store_code($block),
        'core/image' => spacefast_content_markdown_store_image($block),
        default => $block,
    };
    $block['innerBlocks'] = array_map(
        static fn (array $inner): array => spacefast_content_markdown_store_block($inner, $stripHeadingIds),
        $block['innerBlocks'] ?? []
    );
    return $block;
}

function spacefast_content_markdown_strip_heading_id(array $block): array
{
    if (is_string($block['innerContent'][0] ?? null)) {
        $html = $block['innerContent'][0];
        $processor = new WP_HTML_Tag_Processor($html);
        $id = $processor->next_tag() ? $processor->get_attribute('id') : null;
        if (is_string($id)) {
            // The toolkit writes ` id="<slug>"`, and a slug needs no escaping.
            // Cutting those bytes leaves the tag exactly as the editor spells a
            // heading with no id. The tag processor's removal would leave a
            // stray space.
            $spelled = ' id="' . $id . '"';
            $at = strpos($html, $spelled);
            if ($at !== false && $at < (int) strpos($html, '>')) {
                $html = substr_replace($html, '', $at, strlen($spelled));
            } else {
                $processor->remove_attribute('id');
                $html = $processor->get_updated_html();
            }
            $block['innerContent'][0] = $html;
            $block['innerHTML'] = $html;
        }
    }
    return $block;
}

/**
 * A fence's language, spelled as the editor keeps it: core's code block has no
 * `language` attribute, so the editor drops the toolkit's on save. It keeps a
 * `language-<x>` class, which is also what highlighters read.
 */
function spacefast_content_markdown_store_code(array $block): array
{
    $language = $block['attrs']['language'] ?? null;
    unset($block['attrs']['language']);
    if (!is_string($language) || $language === '' || !is_string($block['innerContent'][0] ?? null)) {
        return $block;
    }
    $class = 'language-' . $language;
    $processor = new WP_HTML_Tag_Processor($block['innerContent'][0]);
    if (!$processor->next_tag('PRE')) {
        return $block;
    }
    $processor->add_class($class);
    $html = $processor->get_updated_html();
    $block['attrs']['className'] = $class;
    $block['innerContent'][0] = $html;
    $block['innerHTML'] = $html;
    return $block;
}

/**
 * An image as the editor stores it: the figure on one line, its classes beyond
 * `wp-block-image` as `className`, and an `alt` on the image even when empty.
 */
function spacefast_content_markdown_store_image(array $block): array
{
    if (!is_string($block['innerContent'][0] ?? null)) {
        return $block;
    }
    $html = (string) preg_replace('/>\s+</', '><', trim($block['innerContent'][0]));
    $processor = new WP_HTML_Tag_Processor($html);
    if (!$processor->next_tag('FIGURE')) {
        return $block;
    }
    $classes = array_values(array_diff(iterator_to_array($processor->class_list()), ['wp-block-image']));
    if ($classes !== []) {
        $block['attrs']['className'] = implode(' ', $classes);
    }
    if ($processor->next_tag('IMG') && $processor->get_attribute('alt') === null) {
        $processor->set_attribute('alt', '');
    }
    $html = "\n" . $processor->get_updated_html() . "\n";
    $block['innerContent'][0] = $html;
    $block['innerHTML'] = $html;
    return $block;
}

/** The `language` attribute the producer reads, from the class the editor keeps. */
function spacefast_content_markdown_fence_language(array $block): array
{
    if (($block['blockName'] ?? null) === 'core/code' && !isset($block['attrs']['language'])
        && preg_match('/(?:^|\s)language-(\S+)/', (string) ($block['attrs']['className'] ?? ''), $match) === 1) {
        $block['attrs']['language'] = $match[1];
    }
    $block['innerBlocks'] = array_map('spacefast_content_markdown_fence_language', $block['innerBlocks'] ?? []);
    return $block;
}

function spacefast_content_markdown_from_blocks(string $blocks): string
{
    if (trim($blocks) === '') {
        return '';
    }
    if (str_contains($blocks, 'language-')) {
        $blocks = serialize_blocks(array_map('spacefast_content_markdown_fence_language', parse_blocks($blocks)));
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
 * an error in the document. It means the document is richer than Markdown
 * and must not be flattened into one.
 *
 * The question is whether anything the document carries is lost, so the
 * comparison runs one way. The round trip may spell out what the original
 * leaves implicit, such as a list's `ordered: false`, the default the editor
 * omits. It may not drop or change anything the original says.
 */
function spacefast_content_markdown_representable(string $blocks): bool
{
    if (trim($blocks) === '') {
        return true;
    }
    $markdown = spacefast_content_markdown_from_blocks($blocks);
    $reparsed = spacefast_content_markdown_to_blocks($markdown);
    return spacefast_content_markdown_carries(
        spacefast_content_markdown_document_key($reparsed),
        spacefast_content_markdown_document_key(spacefast_content_markdown_respell_legacy($blocks))
    );
}

/**
 * A block document in the form the block editor reads it.
 *
 * Two markups with the same key are one document: the editor opens both to the
 * same blocks and saves both to the same bytes. The key leaves out only what
 * the editor itself treats as spelling:
 *
 * - whitespace between block delimiters and around inner blocks;
 * - the order of a tag's attributes, and how its text escapes characters;
 * - an `anchor` attribute equal to the root element's `id`. Anchor support
 *   writes the attribute as that id, and on load it copies a lone id into the
 *   attribute, so the two are one value spelled twice;
 * - a heading id equal to the one Markdown derives from the heading's text,
 *   which rendering adds anyway (spacefast_content_markdown_render_heading);
 * - `<b>` versus `<strong>` and `<i>` versus `<em>`. Markdown has one strong
 *   and one emphasis. The importer spells strong `<b>` and the editor's bold
 *   button writes `<strong>`;
 * - in a code block, `<br>` versus a newline, and one trailing newline. The
 *   importer spells every line break `<br>` and ends on one; the editor keeps
 *   newlines, and a fence ends on one newline whether or not the text does.
 *
 * @return list<array<string, mixed>>
 */
function spacefast_content_markdown_document_key(string $blocks): array
{
    $key = [];
    foreach (parse_blocks($blocks) as $block) {
        $node = spacefast_content_markdown_block_key($block);
        if ($node !== null) {
            $key[] = $node;
        }
    }
    return $key;
}

/** @return array{block:string, attrs:array<string, mixed>, inner:list<array<string, mixed>>}|null */
function spacefast_content_markdown_block_key(array $block): ?array
{
    $children = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
    $index = 0;
    $inner = [];
    foreach (is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [] as $chunk) {
        if (is_string($chunk)) {
            array_push($inner, ...spacefast_content_markdown_html_key($chunk));
            continue;
        }
        $child = is_array($children[$index] ?? null) ? spacefast_content_markdown_block_key($children[$index]) : null;
        $index++;
        if ($child !== null) {
            $inner[] = $child;
        }
    }
    $name = $block['blockName'] ?? null;
    if (!is_string($name)) {
        // Freeform markup between blocks: whitespace, unless it holds something.
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
    if ($name === 'core/heading' && is_string($rootId) && $rootId === spacefast_content_markdown_heading_id((string) ($block['innerHTML'] ?? ''), $inner)) {
        unset($inner[$root]['attrs']['id']);
    }
    if ($name === 'core/code') {
        $inner = spacefast_content_markdown_code_lines($inner);
    }
    return ['block' => $name, 'attrs' => spacefast_content_markdown_sorted($attrs), 'inner' => $inner];
}

/**
 * A code block's tokens with every `<br>` read as the newline it is, and the
 * text joined, less the one trailing newline a fence always implies.
 *
 * @param list<array<string, mixed>> $tokens
 * @return list<array<string, mixed>>
 */
function spacefast_content_markdown_code_lines(array $tokens): array
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

/**
 * One chunk of a block's own HTML as tokens.
 *
 * @return list<array<string, mixed>>
 */
function spacefast_content_markdown_html_key(string $html): array
{
    $html = trim($html);
    if ($html === '') {
        return [];
    }
    $tokens = [];
    $processor = new WP_HTML_Tag_Processor($html);
    while ($processor->next_token()) {
        $type = $processor->get_token_type();
        if ($type === '#tag') {
            $tag = spacefast_content_markdown_tag_name((string) $processor->get_tag());
            if ($processor->is_tag_closer()) {
                $tokens[] = ['close' => $tag];
                continue;
            }
            $attributes = [];
            foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $attribute) {
                $attributes[$attribute] = $processor->get_attribute($attribute);
            }
            ksort($attributes, SORT_STRING);
            $tokens[] = ['tag' => $tag, 'attrs' => $attributes];
            continue;
        }
        $tokens[] = ['token' => $type, 'text' => $processor->get_modifiable_text()];
    }
    return $tokens;
}

function spacefast_content_markdown_tag_name(string $tag): string
{
    return match ($tag) {
        'B' => 'STRONG',
        'I' => 'EM',
        default => $tag,
    };
}

/**
 * The id Markdown gives a heading: the id the importer writes for the Markdown
 * this heading serializes to.
 *
 * Parity with the toolkit's MarkdownConsumer is the contract, so existing
 * fragment links keep resolving. The consumer slugs a heading's CommonMark text
 * nodes. That covers link, emphasis and image-alt text and leaves out code spans
 * and inline HTML. The cheap reading below matches it whenever the heading has
 * no image and no `<` in its text. Inline HTML reaches the stored heading as
 * escaped text, indistinguishable from an escaped `\<` the author typed, so for
 * those headings the toolkit itself answers.
 *
 * @param list<array<string, mixed>> $tokens The heading's own HTML as tokens.
 */
function spacefast_content_markdown_heading_id(string $html, array $tokens): string
{
    $text = '';
    $code = 0;
    foreach ($tokens as $token) {
        if (($token['tag'] ?? null) === 'IMG') {
            return spacefast_content_markdown_heading_id_by_toolkit($html);
        }
        if (($token['tag'] ?? null) === 'CODE') {
            $code++;
        } elseif (($token['close'] ?? null) === 'CODE') {
            $code = max(0, $code - 1);
        } elseif (($token['token'] ?? null) === '#text' && $code === 0) {
            if (str_contains((string) $token['text'], '<')) {
                return spacefast_content_markdown_heading_id_by_toolkit($html);
            }
            $text .= (string) $token['text'];
        }
    }
    return (string) preg_replace('/[^a-z0-9]+/i', '-', trim(strtolower($text)));
}

/** The importer's own id for a heading, by serializing it and importing it again. */
function spacefast_content_markdown_heading_id_by_toolkit(string $html): string
{
    try {
        $markdown = spacefast_content_markdown_from_blocks("<!-- wp:heading -->\n" . trim($html) . "\n<!-- /wp:heading -->");
        $processor = new WP_HTML_Tag_Processor(spacefast_content_markdown_consume($markdown));
    } catch (Spacefast_Content_Error) {
        return '';
    }
    while ($processor->next_tag()) {
        if (preg_match('/^H[1-6]$/', (string) $processor->get_tag()) === 1) {
            $id = $processor->get_attribute('id');
            return is_string($id) ? $id : '';
        }
    }
    return '';
}

/**
 * Whether the round trip kept everything the original document says.
 *
 * Attributes compare one way, original into round trip. Everything else must
 * match exactly.
 */
function spacefast_content_markdown_carries(array $reparsed, array $original): bool
{
    if (count($reparsed) !== count($original)) {
        return false;
    }
    foreach ($original as $position => $node) {
        $candidate = $reparsed[$position] ?? null;
        if (!is_array($candidate) || !is_array($node)) {
            return false;
        }
        if (!isset($node['block'])) {
            if ($candidate !== $node) {
                return false;
            }
            continue;
        }
        if (($candidate['block'] ?? null) !== $node['block']) {
            return false;
        }
        foreach ($node['attrs'] as $name => $value) {
            if (!array_key_exists($name, $candidate['attrs']) || $candidate['attrs'][$name] !== $value) {
                return false;
            }
        }
        if (!spacefast_content_markdown_carries($candidate['inner'], $node['inner'])) {
            return false;
        }
    }
    return true;
}

function spacefast_content_markdown_sorted(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $value = array_map('spacefast_content_markdown_sorted', $value);
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    return $value;
}

/**
 * A rendered heading of a Markdown document, with the id Markdown gives it.
 *
 * The `render_block_core/heading` filter. Stored Markdown documents carry no
 * heading ids (see the file header), so this is where a fragment link to
 * `#heading-text` resolves. A heading the editor gave an anchor keeps it.
 */
function spacefast_content_markdown_render_heading(string $content, array $block = [], mixed $instance = null): string
{
    if (!spacefast_content_markdown_renders_document($instance)) {
        return $content;
    }
    return spacefast_content_markdown_with_heading_id($content);
}

function spacefast_content_markdown_with_heading_id(string $html): string
{
    $processor = new WP_HTML_Tag_Processor($html);
    if (!$processor->next_tag() || preg_match('/^H[1-6]$/', (string) $processor->get_tag()) !== 1
        || $processor->get_attribute('id') !== null) {
        return $html;
    }
    $slug = spacefast_content_markdown_heading_id($html, spacefast_content_markdown_html_key($html));
    if ($slug === '') {
        return $html;
    }
    $processor->set_attribute('id', $slug);
    return $processor->get_updated_html();
}

/**
 * Whether the post being rendered is a Markdown-bound document.
 *
 * A stored post answers through its ledger. A sealed snapshot is not a stored
 * post (its ID is 0), so the document lane names the format it is rendering in
 * SPACEFAST_CONTENT_RENDER_FORMAT.
 */
function spacefast_content_markdown_renders_document(mixed $instance): bool
{
    $postId = is_object($instance) && is_array($instance->context ?? null) && isset($instance->context['postId'])
        ? (int) $instance->context['postId']
        : (function_exists('get_the_ID') ? (int) get_the_ID() : 0);
    if ($postId <= 0) {
        return ($GLOBALS['SPACEFAST_CONTENT_RENDER_FORMAT'] ?? null) === 'md';
    }
    static $formats = [];
    if (!array_key_exists($postId, $formats)) {
        $ledger = function_exists('spacefast_content_sync_ledger') ? spacefast_content_sync_ledger($postId) : null;
        $formats[$postId] = is_array($ledger) ? ($ledger['format'] ?? null) : null;
    }
    return $formats[$postId] === 'md';
}
