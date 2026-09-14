<?php
declare(strict_types=1);

// The platform brand document: one flat, host-owned record of every string the
// serving engine puts a product name on.
//
// There is exactly one document per site and the Spacefast values are compiled
// in as its defaults, so a bare Spacefast site configures nothing and answers
// byte-for-byte as it always has. A white-label host sets the document once and
// every branded surface follows: the problem-document `type` base, the built-in
// error/gate pages (wordmark, help link, powered-by line, font preloads) and the
// outbound mail Message-ID domain.
//
// This mirrors `Brand` in crates/stattic-runtime-policy/src/brand.rs, which the
// Zero runner and the page compiler resolve from — same members, same defaults,
// same "an override supplies only what it wants" rule. PHP cannot import it:
// change one, change the other.
//
// NOT in this document: `$schema` URLs, manifest format tags, slot markers and
// the `X-Spacefast-*` response headers. Those are format identifiers that
// tooling matches on; they never vary per site.

require_once __DIR__ . '/context.php';

// Read through the standard config lane — a wp-config constant, a process
// environment variable, or the Atomic persistent-data blob — so the document
// resolves identically on a box, in the runner subprocess and in tests.
const STATTIC_BRAND_CONFIG_KEY = 'SPACEFAST_BRAND_JSON';

/**
 * The compiled-in document. Members:
 *
 * - `name`      Product name. Also the wordmark's accessible label and the noun
 *               in "… could not serve this page".
 * - `url`       Primary marketing URL, behind the powered-by lockup.
 * - `help_url`  Where a stuck visitor is sent from a built-in page.
 * - `tagline`   The one line of copy under the powered-by wordmark.
 * - `problem_docs_base_url`  Base for RFC 9457 `type` URIs: `{base}/{code}`.
 * - `mail_domain`   Right-hand side of the outbound Message-ID addr-spec.
 * - `wordmark_url`  Image replacing the compiled-in SVG wordmark. Empty keeps
 *               the built-in mark, which is why the default path is byte-stable.
 * - `fonts`     Faces the built-in pages embed and preload, in CSS order. Empty
 *               is legitimate: a host whose pages use system stacks downloads
 *               nothing.
 *
 * The font default is `STATTIC_PLATFORM_PAGE_FONTS`, which stays in context.php
 * because it is paired with its own mirror in packages/common.
 *
 * @return array<string,mixed>
 */
function _stattic_brand_defaults(): array
{
    $fonts = [];
    foreach (STATTIC_PLATFORM_PAGE_FONTS as $url => [$family, $weight, $preload]) {
        $fonts[] = ['url' => $url, 'family' => $family, 'weight' => $weight, 'preload' => $preload];
    }
    return [
        'name' => 'Spacefast',
        'url' => 'https://spacefast.com',
        'help_url' => 'https://spacefast.com/help',
        'tagline' => 'Best way to share what your agent made',
        'problem_docs_base_url' => 'https://spacefast.com/docs/errors',
        'mail_domain' => 'mail.spacefast.com',
        'wordmark_url' => '',
        'fonts' => $fonts,
    ];
}

// camelCase on the wire (the Rust document's spelling), snake_case in PHP.
const STATTIC_BRAND_STRING_MEMBERS = [
    'name' => 'name',
    'url' => 'url',
    'helpUrl' => 'help_url',
    'tagline' => 'tagline',
    'problemDocsBaseUrl' => 'problem_docs_base_url',
    'mailDomain' => 'mail_domain',
    'wordmarkUrl' => 'wordmark_url',
];

/**
 * The document this site resolves to, once per request.
 *
 * @return array<string,mixed>
 */
function _stattic_brand(): array
{
    static $brand = null;
    if (!is_array($brand)) {
        $brand = _stattic_brand_resolve(_stattic_config_value(STATTIC_BRAND_CONFIG_KEY));
    }
    return $brand;
}

/**
 * The defaults with an override document layered over them.
 *
 * A malformed override, a non-object, or a member blanked to whitespace all keep
 * the compiled-in value: a broken brand override must never take a site's error
 * pages down with it, and an unnamed product is a configuration mistake rather
 * than a request.
 *
 * @return array<string,mixed>
 */
function _stattic_brand_resolve(string $document): array
{
    $brand = _stattic_brand_defaults();
    $decoded = json_decode($document, true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        return $brand;
    }
    foreach (STATTIC_BRAND_STRING_MEMBERS as $wireName => $key) {
        $value = $decoded[$wireName] ?? null;
        if (is_string($value) && trim($value) !== '') {
            $brand[$key] = trim($value);
        }
    }
    // Fonts are the one member an override may legitimately empty.
    if (is_array($decoded['fonts'] ?? null) && array_is_list($decoded['fonts'])) {
        $brand['fonts'] = _stattic_brand_fonts($decoded['fonts']);
    }
    return $brand;
}

/**
 * @param list<mixed> $entries
 * @return list<array{url:string,family:string,weight:string,preload:bool}>
 */
function _stattic_brand_fonts(array $entries): array
{
    $fonts = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : '';
        $family = is_string($entry['family'] ?? null) ? trim($entry['family']) : '';
        $weight = is_string($entry['weight'] ?? null) ? trim($entry['weight']) : '';
        if ($url === '' || $family === '' || $weight === '') {
            continue;
        }
        $fonts[] = [
            'url' => $url,
            'family' => $family,
            'weight' => $weight,
            'preload' => ($entry['preload'] ?? false) === true,
        ];
    }
    return $fonts;
}

/** One member of the resolved document, as a string. */
function _stattic_brand_value(string $key): string
{
    $value = _stattic_brand()[$key] ?? '';
    return is_string($value) ? $value : '';
}

/**
 * The override document to hand a subprocess that resolves brand itself (the
 * Zero runner), or '' when this site is on the compiled-in defaults and the
 * subprocess would resolve the same document from nothing.
 */
function _stattic_brand_config_env(): string
{
    return _stattic_config_value(STATTIC_BRAND_CONFIG_KEY);
}
