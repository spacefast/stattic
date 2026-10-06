<?php
declare(strict_types=1);

// The marker finalization leaves on its share-image placeholders when a page
// declares no preview image (crates/stattic-runtime-core/src/content/mod.rs).
const STATTIC_AUTO_SHARE_IMAGE_ATTR = 'data-spacefast-auto-image';

// WordPress.com mShots renders the page as a 1200x630 screenshot. It fetches
// without credentials, so it only ever sees what an anonymous visitor sees.
const STATTIC_AUTO_SHARE_IMAGE_ENDPOINT = 'https://s0.wp.com/mshots/v1/';
const STATTIC_AUTO_SHARE_IMAGE_QUERY = '?w=1200&h=630';

// The control plane's record of the screenshots it has seen rendered, written
// by _stattic_runtime_put_share_images. mShots answers a URL it has not
// finished with a generic placeholder that unfurlers cache, so only a URL on
// this list is advertised.
const STATTIC_AUTO_SHARE_IMAGE_READY_FILE = 'share-images.json';

// The page URL mShots captures. mShots keeps one screenshot per URL for a day,
// so the version in the query makes each publish its own capture. The
// control plane derives the same URL (apps/control-plane/src/runtime/share-images.ts).
function _stattic_auto_share_image_target(string $documentUrl, string $versionId): string
{
    // One spelling per page whether the request path arrived with lower- or
    // upper-case escapes.
    $documentUrl = (string) preg_replace_callback('/%[0-9a-f]{2}/i', static fn (array $escape): string => strtoupper($escape[0]), $documentUrl);
    return $documentUrl . '?v=' . substr(hash('sha256', $versionId), 0, 12);
}

function _stattic_auto_share_image_url(string $target): string
{
    return STATTIC_AUTO_SHARE_IMAGE_ENDPOINT . rawurlencode($target) . STATTIC_AUTO_SHARE_IMAGE_QUERY;
}

function _stattic_auto_share_image_ready(array $context, string $target): bool
{
    $path = _stattic_space_root((string) $context['private_root'], (string) $context['space_id'])
        . '/' . STATTIC_AUTO_SHARE_IMAGE_READY_FILE;
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $ready = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($ready)
        && ($ready['version_id'] ?? null) === ($context['version_id'] ?? null)
        && is_array($ready['urls'] ?? null)
        && in_array($target, $ready['urls'], true);
}

// Use the pinned HTML tokenizer so scripts, comments and quoted attributes
// cannot masquerade as preview metadata. The document's requested URL is the
// base for host-dependent normalization. Finalization anchors relative tags
// to their published page, so rewrites keep the same admitted image.
//
// When `share_image` is set (an anonymously readable 200 page) and the
// control plane has seen this URL's screenshot render, the finalizer's
// placeholders advertise it. Otherwise they stay inert, so private pages never
// advertise one.
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
    $shareImage = null;
    if (!empty($context['share_image']) && is_string($context['version_id'] ?? null)) {
        $target = _stattic_auto_share_image_target($base->toAsciiString(), $context['version_id']);
        if (str_contains($html, STATTIC_AUTO_SHARE_IMAGE_ATTR) && _stattic_auto_share_image_ready($context, $target)) {
            $shareImage = _stattic_auto_share_image_url($target);
        }
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
        $placeholder = $processor->get_attribute(STATTIC_AUTO_SHARE_IMAGE_ATTR);
        if (is_string($placeholder)) {
            if ($shareImage !== null) {
                if ($placeholder === 'og:image') {
                    $processor->set_attribute('property', 'og:image');
                    $processor->set_attribute('content', $shareImage);
                } elseif ($placeholder === 'twitter:card') {
                    $processor->set_attribute('content', 'summary_large_image');
                }
                $processor->remove_attribute(STATTIC_AUTO_SHARE_IMAGE_ATTR);
            }
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
