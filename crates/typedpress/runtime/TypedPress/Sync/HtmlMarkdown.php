<?php

declare(strict_types=1);

namespace TypedPress\Sync;

use WP_HTML_Processor as Html;

/**
 * Rendered HTML → Markdown for reading (Markdown::renderPost()), on core's HTML Processor, so the HTML is
 * read the way a browser reads it. It writes what a reader sees: headings, paragraphs, lists, quotes,
 * code, tables, rules, links, images, emphasis. Every other element contributes its text, as a block
 * when it is a block-level element. Hidden elements (`hidden`, `<script>`, `<style>`, `<template>`,
 * form controls) contribute nothing.
 *
 * The output is Markdown text, never HTML: text is written as the reader saw it, and the caller
 * escapes any `<` that could start a tag.
 */
final class HtmlMarkdown
{
    private const BLOCK = ['ADDRESS', 'ARTICLE', 'ASIDE', 'DETAILS', 'DIV', 'DL', 'DD', 'DT', 'FIELDSET', 'FIGCAPTION',
        'FIGURE', 'FOOTER', 'FORM', 'HEADER', 'MAIN', 'NAV', 'P', 'SECTION', 'SUMMARY'];
    private const HEADINGS = ['H1' => 1, 'H2' => 2, 'H3' => 3, 'H4' => 4, 'H5' => 5, 'H6' => 6];
    /** Elements whose content a reader never sees. */
    private const SKIP = ['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH',
        'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'OPTION', 'LABEL', 'CANVAS', 'VIDEO', 'AUDIO', 'HEAD', 'TITLE'];

    /** @var list<string> finished Markdown blocks */
    private array $blocks = [];
    /** Inline Markdown of the block being written. */
    private string $line = '';
    /** @var list<array{tag: string, prefix: string, ordered: bool, n: int}> open lists and quotes */
    private array $containers = [];
    /** @var list<string> hrefs of open links */
    private array $links = [];
    private int $pre = 0;
    private string $code = '';
    /** The fence language of the open `<pre>`: its or its `<code>`'s `language-<x>` class. */
    private string $language = '';
    /** Whether the last block written is a list item, so the next item joins it without a blank line. */
    private bool $listOpen = false;
    private ?array $table = null;

    public static function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $converter = new self();
        $processor = Html::create_fragment($html);
        if ($processor === null) {
            return self::fallback($html);
        }
        $converter->walk($processor);
        if ($processor->get_last_error() !== null) {
            // Markup the HTML Processor doesn't support yet: its text, without structure.
            return self::fallback($html);
        }
        $converter->flush();

        return implode("\n\n", $converter->blocks);
    }

    private static function fallback(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template)\b.*?</\1>#is', '', $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $paragraphs = preg_split('/\n\s*\n/', trim($text)) ?: [];

        return implode("\n\n", array_filter(array_map(static fn (string $p): string => trim((string) preg_replace('/\s+/u', ' ', $p)), $paragraphs)));
    }

    private function walk(Html $p): void
    {
        $skipDepth = null;
        while ($p->next_token()) {
            $type = $p->get_token_type();
            $depth = $p->get_current_depth();
            if ($skipDepth !== null) {
                if ($depth < $skipDepth || ($depth === $skipDepth && $type === '#tag' && $p->is_tag_closer())) {
                    $skipDepth = null;
                }
                continue;
            }
            if ($type === '#text') {
                $this->text($p->get_modifiable_text());
                continue;
            }
            if ($type !== '#tag') {
                continue;
            }
            $tag = (string) $p->get_tag();
            $closer = $p->is_tag_closer();
            if (!$closer && (in_array($tag, self::SKIP, true) || $p->get_attribute('hidden') !== null
                || strtolower((string) $p->get_attribute('aria-hidden')) === 'true' && $tag !== 'IMG')) {
                if ($p->expects_closer() !== false) {
                    $skipDepth = $depth;
                }
                continue;
            }
            $closer ? $this->close($tag) : $this->open($tag, $p);
        }
    }

    private function open(string $tag, Html $p): void
    {
        if ($this->table !== null && !in_array($tag, ['TD', 'TH', 'TR', 'THEAD', 'TBODY', 'TFOOT', 'TABLE'], true)) {
            $this->inline($tag, $p);

            return;
        }
        match (true) {
            isset(self::HEADINGS[$tag]) => $this->startBlock(str_repeat('#', self::HEADINGS[$tag]) . ' '),
            in_array($tag, self::BLOCK, true) => $this->flush(),
            $tag === 'UL' || $tag === 'OL' => $this->openList($tag, $p),
            $tag === 'LI' => $this->openItem(),
            $tag === 'BLOCKQUOTE' => $this->openQuote(),
            $tag === 'PRE' => $this->openPre(self::language($p)),
            $tag === 'HR' => $this->rule(),
            $tag === 'BR' => $this->pre > 0 ? $this->code .= "\n" : $this->line .= "  \n",
            $tag === 'TABLE' => $this->openTable(),
            $tag === 'TR' => $this->table === null ?: $this->table['row'] = [],
            $tag === 'TD' || $tag === 'TH' => $this->table === null ?: $this->table['cell'] = '',
            default => $this->inline($tag, $p),
        };
    }

    private function close(string $tag): void
    {
        if ($this->table !== null && !in_array($tag, ['TD', 'TH', 'TR', 'TABLE'], true)) {
            $this->inlineClose($tag);

            return;
        }
        match (true) {
            isset(self::HEADINGS[$tag]), in_array($tag, self::BLOCK, true), $tag === 'LI' => $this->flush(),
            $tag === 'UL' || $tag === 'OL' || $tag === 'BLOCKQUOTE' => $this->closeContainer(),
            $tag === 'PRE' => $this->closePre(),
            $tag === 'TD' || $tag === 'TH' => $this->closeCell(),
            $tag === 'TR' => $this->closeRow(),
            $tag === 'TABLE' => $this->closeTable(),
            default => $this->inlineClose($tag),
        };
    }

    private function inline(string $tag, Html $p): void
    {
        if ($this->pre > 0) {
            if ($tag === 'CODE' && $this->language === '') {
                $this->language = self::language($p);
            }

            return;
        }
        match ($tag) {
            'STRONG', 'B' => $this->emit('**'),
            'EM', 'I' => $this->emit('*'),
            'DEL', 'S' => $this->emit('~~'),
            'CODE' => $this->emit('`'),
            'A' => $this->openLink((string) $p->get_attribute('href')),
            'IMG' => $this->image((string) $p->get_attribute('alt'), (string) $p->get_attribute('src')),
            default => null,
        };
    }

    private function inlineClose(string $tag): void
    {
        if ($this->pre > 0) {
            return;
        }
        match ($tag) {
            'STRONG', 'B' => $this->emit('**'),
            'EM', 'I' => $this->emit('*'),
            'DEL', 'S' => $this->emit('~~'),
            'CODE' => $this->emit('`'),
            'A' => $this->closeLink(),
            default => null,
        };
    }

    // ================================================================ text and inline

    private function text(string $text): void
    {
        if ($this->pre > 0) {
            $this->code .= $text;

            return;
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $target = $this->target();
        $lineStart = $target === '' || str_ends_with($target, "\n") || preg_match('/^#{1,6} $/', $target) === 1;
        if ($lineStart || str_ends_with($target, ' ')) {
            $text = ltrim($text);
        }
        $text = str_replace(['\\', '[', ']', '*', '_', '`'], ['\\\\', '\\[', '\\]', '\\*', '\\_', '\\`'], $text);
        if ($lineStart) {
            // Text a reader saw as text, never Markdown structure: a heading (`\#`), quote (`\>`), list
            // item (`\-`, `1\.`), `~~~` fence, `---` rule or `===` setext underline. Backticks, `*` and
            // `_` are escaped everywhere above, so ``` fences and `***`/`___` rules can't start here.
            $text = (string) preg_replace(['/^(#{1,6}|[>+-])(?=\s|$)/', '/^(\d{1,9})([.)])(?=\s|$)/', '/^(~{3,}|-{2,}|={1,})/'], ['\\\\$1', '$1\\\\$2', '\\\\$1'], $text);
        }
        $this->emit($text);
    }

    private function emit(string $markdown): void
    {
        if ($this->table !== null && isset($this->table['cell'])) {
            $this->table['cell'] .= $markdown;
        } else {
            $this->line .= $markdown;
        }
    }

    private function target(): string
    {
        return $this->table !== null && isset($this->table['cell']) ? $this->table['cell'] : $this->line;
    }

    private function openLink(string $href): void
    {
        $this->links[] = $href;
        $this->emit('[');
    }

    private function closeLink(): void
    {
        $href = array_pop($this->links);
        if ($href === null) {
            return;
        }
        $this->emit('](' . self::url($href) . ')');
    }

    private function image(string $alt, string $src): void
    {
        if ($src === '') {
            return;
        }
        $this->emit('![' . str_replace(['[', ']'], '', $alt) . '](' . self::url($src) . ')');
    }

    private static function url(string $url): string
    {
        return str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $url);
    }

    // ================================================================ blocks

    private function startBlock(string $prefix): void
    {
        $this->flush();
        $this->line = $prefix;
    }

    /** Finish the block being written, with the open lists' and quotes' prefixes. */
    private function flush(): void
    {
        $line = rtrim((string) preg_replace('/[ \t]+\n/', "  \n", $this->line));
        $this->line = '';
        if (trim($line) === '' || preg_match('/^#{1,6} *$/', $line) === 1) {
            return;
        }
        // Strikethrough markup (`~~`) can line up into a `~~~` fence at a line start, which the
        // per-text escape never sees: escape it on the assembled line, after each hard break too.
        $line = (string) preg_replace('/(^|\n)(~{3,})/', '$1\\\\$2', $line);
        $this->push($line);
    }

    private function push(string $markdown): void
    {
        $lines = explode("\n", $markdown);
        $first = true;
        foreach ($lines as &$text) {
            $prefix = '';
            foreach ($this->containers as $i => &$container) {
                if ($container['tag'] === 'BLOCKQUOTE') {
                    $prefix .= '> ';
                } elseif ($i === array_key_last($this->containers) && $first && $container['pending']) {
                    $prefix .= $container['bullet'];
                    $container['pending'] = false;
                } else {
                    $prefix .= str_repeat(' ', strlen($container['bullet']));
                }
            }
            unset($container);
            $text = $prefix . $text;
            $first = false;
        }
        unset($text);
        $inList = $this->containers !== [] && end($this->containers)['tag'] !== 'BLOCKQUOTE';
        $previous = array_key_last($this->blocks);
        if ($inList && $previous !== null && ($this->blocks[$previous][-1] ?? '') !== "\n" && $this->listOpen) {
            $this->blocks[$previous] .= "\n" . implode("\n", $lines);
        } else {
            $this->blocks[] = implode("\n", $lines);
        }
        $this->listOpen = $inList;
    }

    private function openList(string $tag, Html $p): void
    {
        $this->flush();
        $start = (int) ($p->get_attribute('start') ?? 1);
        $this->containers[] = ['tag' => $tag, 'ordered' => $tag === 'OL', 'n' => $start - 1, 'bullet' => '- ', 'pending' => false];
    }

    private function openItem(): void
    {
        $this->flush();
        $last = array_key_last($this->containers);
        if ($last === null || $this->containers[$last]['tag'] === 'BLOCKQUOTE') {
            return;
        }
        $list = &$this->containers[$last];
        $list['n']++;
        $list['bullet'] = $list['ordered'] ? $list['n'] . '. ' : '- ';
        $list['pending'] = true;
    }

    private function openQuote(): void
    {
        $this->flush();
        $this->listOpen = false;
        $this->containers[] = ['tag' => 'BLOCKQUOTE', 'ordered' => false, 'n' => 0, 'bullet' => '> ', 'pending' => false];
    }

    private function closeContainer(): void
    {
        $this->flush();
        $closed = array_pop($this->containers);
        // A list nested in an item hands back to its parent list: the next item joins it.
        $this->listOpen = $closed !== null && $closed['tag'] !== 'BLOCKQUOTE' && $this->containers !== [] && end($this->containers)['tag'] !== 'BLOCKQUOTE';
    }

    private function openPre(string $language): void
    {
        $this->flush();
        $this->pre++;
        $this->code = '';
        $this->language = $language;
    }

    /** `x` from a `language-x` class, as the code block writes the fence language. */
    private static function language(Html $p): string
    {
        return preg_match('/(?:^|\s)language-([A-Za-z0-9_+#.-]+)/', (string) $p->get_attribute('class'), $m) === 1 ? $m[1] : '';
    }

    private function closePre(): void
    {
        if ($this->pre === 0) {
            return;
        }
        $this->pre--;
        $code = rtrim($this->code, "\n");
        $this->code = '';
        $fence = str_repeat('`', max(3, self::longestRun($code, '`') + 1));
        $this->listOpen = false;
        $this->push($fence . $this->language . "\n" . $code . "\n" . $fence);
        $this->language = '';
        $this->listOpen = false;
    }

    private static function longestRun(string $text, string $char): int
    {
        preg_match_all('/' . preg_quote($char, '/') . '+/', $text, $m);

        return $m[0] === [] ? 0 : max(array_map('strlen', $m[0]));
    }

    private function rule(): void
    {
        $this->flush();
        $this->listOpen = false;
        $this->push('---');
        $this->listOpen = false;
    }

    // ================================================================ tables

    private function openTable(): void
    {
        $this->flush();
        $this->table = ['rows' => []];
    }

    private function closeCell(): void
    {
        if ($this->table === null || !isset($this->table['cell'])) {
            return;
        }
        $this->table['row'][] = trim(str_replace('|', '\\|', (string) preg_replace('/\s+/u', ' ', $this->table['cell'])));
        unset($this->table['cell']);
    }

    private function closeRow(): void
    {
        if ($this->table === null) {
            return;
        }
        $this->closeCell();
        if (($this->table['row'] ?? []) !== []) {
            $this->table['rows'][] = $this->table['row'];
        }
        $this->table['row'] = [];
    }

    private function closeTable(): void
    {
        if ($this->table === null) {
            return;
        }
        $this->closeRow();
        $rows = $this->table['rows'];
        $this->table = null;
        if ($rows === []) {
            return;
        }
        $width = max(array_map('count', $rows));
        $lines = [];
        foreach ($rows as $i => $row) {
            $row = array_pad($row, $width, '');
            $lines[] = '| ' . implode(' | ', $row) . ' |';
            if ($i === 0) {
                $lines[] = '|' . str_repeat('---|', $width);
            }
        }
        $this->listOpen = false;
        $this->push(implode("\n", $lines));
        $this->listOpen = false;
    }
}
