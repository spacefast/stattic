<?php
declare(strict_types=1);

// Use the pinned HTML tokenizer so scripts, comments and quoted attributes
// cannot masquerade as preview metadata. The document's requested URL is the
// base for host-dependent normalization. Finalization anchors relative tags
// to their published page, so rewrites keep the same admitted image.
function _stattic_normalize_preview_images(string $html, array $context): string
{
    if (!class_exists('WP_HTML_Tag_Processor')) {
        require_once __DIR__ . '/../wordpress/content-markdown.php';
        spacefast_content_sync_require_toolkit();
    }
    require_once __DIR__ . '/context.php';
    $origin = _stattic_request_scheme() . '://' . (string) $context['host'];
    $documentPath = (string) ($context['client_path'] ?? '/');
    $base = Uri\WhatWg\Url::parse($origin . $documentPath);
    if ($base === null) {
        return $html;
    }
    $baseProcessor = new WP_HTML_Tag_Processor($html);
    while ($baseProcessor->next_tag()) {
        if ($baseProcessor->get_tag() === 'BODY') {
            break;
        }
        if ($baseProcessor->get_tag() === 'BASE' && is_string($href = $baseProcessor->get_attribute('href'))) {
            $base = Uri\WhatWg\Url::parse($href, $base) ?? $base;
            break;
        }
    }
    $processor = new WP_HTML_Tag_Processor($html);
    while ($processor->next_tag()) {
        $tag = $processor->get_tag();
        if ($tag === 'BODY') {
            break;
        }
        if ($tag !== 'META') {
            continue;
        }
        $keys = [$processor->get_attribute('property'), $processor->get_attribute('name')];
        $advertised = array_any($keys, static fn ($key): bool => is_string($key) && in_array(strtolower(trim($key)), [
            'og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src',
        ], true));
        $reference = $processor->get_attribute('content');
        if (!$advertised || !is_string($reference)) {
            continue;
        }
        $reference = trim($reference);
        // Explicit origins and non-HTTP schemes remain the author's values.
        if ($reference === '' || str_starts_with($reference, '//') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) {
            continue;
        }
        $absolute = Uri\WhatWg\Url::parse($reference, $base);
        if ($absolute !== null) {
            $processor->set_attribute('content', $absolute->toAsciiString());
        }
    }
    return $processor->get_updated_html();
}
