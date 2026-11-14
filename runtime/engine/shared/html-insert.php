<?php
declare(strict_types=1);

// THE platform SDK tag, and the streaming filter that carries the version's
// injections into dynamic HTML (D44–D46, D77, D94, D95).
//
// The SDK is idempotent: its bootstrap latches every side effect on
// `window.Spacefast` (runtime/spacefast-sdk.php), so a page that loads it twice
// runs it once. The engine therefore never looks for an existing tag:
//   * finalize bakes the tag into every static page it can decorate
//     (`SPACEFAST_SDK_INJECT` in apps/control-plane/src/versions/lifecycle.ts),
//     so an engine rollback, which rebuilds no version, never strips it;
//   * the static lane adds the engine's tag only to a page the version's
//     injection record lists as undecorated (no `<body>`), a set lookup with
//     no blob read. A version without the record baked every page itself;
//   * every dynamic lane (the function proxy and `env.ASSETS.fetch`, route
//     proxies, Zero, WordPress pages) always gets the engine's tag. A page
//     that already carries one boots once.
// Exactly one applier, none at the edge (D47).
//
// HTML this filter changes is a different representation from the upstream's,
// so the upstream's validators never describe it: they are dropped, and the
// dynamic lanes withhold a document request's conditionals from the upstream
// (_stattic_html_insert_withholds_validators), or a 304 would reuse markup an
// older version injected.
//
// The rules that make it safe to run on a stream:
//   * Uncompressed text/html with a whole body, whatever its status, never an
//     event stream or a byte range (D46/D77).
//   * The head snippets and the SDK tag go after the first `<head` start tag
//     (or `<html` with no head) inside the first 16 KiB, never one inside
//     `<template>`, `<svg>` or `<math>`, and bytes are held only until that
//     is settled (D94). With neither, the response is a fragment: nothing is
//     inserted, as on main, and the miss is journaled. Bytes are lexed once
//     however the body is chunked.
//   * Past the head, bytes stream through and only a bounded partial token is
//     held for the bodyStart and bodyEnd anchors. A token longer than 64 KiB
//     ends that search: the rest is relayed as it is, and the SDK tag, when it
//     is still to place, is appended at the end.
//   * The upstream Content-Length is removed and the body goes out chunked
//     (D95): the insert changes the length, and with an output handler
//     installed PHP owns the framing.
require_once __DIR__ . '/context.php';

const STATTIC_HTML_INSERT_SCAN_LIMIT_BYTES = 16384;
// What a stream may hold while one token is still open: an attribute list,
// a comment, or the closing tag of a raw-text element.
const STATTIC_HTML_INSERT_TOKEN_LIMIT_BYTES = 65536;

const STATTIC_HTML_RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'];

/**
 * Splits HTML into the tokens a browser's tokenizer would, as far as placing
 * an anchor needs: comments, start and end tags with quoted attributes, and
 * the raw text inside script, style and the other raw-text elements, which is
 * never markup. Calls `$visit($kind, $name, $token, $offset)` per tag, where
 * kind is `start` or `end`; returning false stops the walk.
 *
 * Resumable over a stream: `$state` carries the raw-text element still open
 * and how far into an unfinished token the walk already looked, and the
 * return value is the offset of the first byte not yet consumed. The caller
 * keeps the bytes from there and calls again with more appended, passing that
 * offset as `$offset` when the bytes before it stay in the buffer. Each call
 * examines only bytes no earlier call did, plus a few at the boundary, so a
 * body trickled a few bytes at a time costs what one write does.
 * `$state['scanned']` adds up how far each search step moved, the bytes the
 * walk actually read. With `$final` the unfinished tail is consumed as text.
 */
function _stattic_html_lex(string $html, array &$state, bool $final, callable $visit, int $offset = 0): int
{
    $length = strlen($html);
    // How many bytes of the token at `$offset` an earlier call already scanned
    // without finding its end, and the quote it stopped inside, if any.
    $partial = $state['partial'] ?? 0;
    $partialQuote = $state['quote'] ?? null;
    $state['partial'] = 0;
    $state['quote'] = null;
    $resumeAt = $offset;
    $unfinished = static function (int $lt, int $through, ?string $quote = null) use (&$state, $final, $length): int {
        if ($final) {
            return $length;
        }
        $state['partial'] = $through - $lt;
        $state['quote'] = $quote;
        return $lt;
    };
    // Every search below adds the distance it moved (to what it found, or to
    // the end): the bytes the walk actually read.
    $state['scanned'] ??= 0;
    while ($offset < $length) {
        $raw = $state['raw'] ?? null;
        if ($raw !== null) {
            if (preg_match('~</' . $raw . '(?=[\s/>])~i', $html, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                $state['scanned'] += $length - $offset;
                // The raw text continues past this buffer; keep only enough to
                // recognize a closing tag that straddles the boundary.
                return $final ? $length : max($offset, $length - (strlen($raw) + 3));
            }
            $state['scanned'] += $match[0][1] - $offset;
            $offset = $match[0][1];
            $state['raw'] = null;
        }
        $lt = strpos($html, '<', $offset);
        $state['scanned'] += ($lt === false ? $length : $lt) - $offset;
        if ($lt === false) {
            return $length;
        }
        $next = $html[$lt + 1] ?? null;
        if ($next === null) {
            return $final ? $length : $lt;
        }
        if ($next === '!' || $next === '?' || ($next === '/' && !ctype_alpha($html[$lt + 2] ?? ($final ? ' ' : 'a')))) {
            if (!$final && $next === '!' && $length - $lt < 4 && str_starts_with('<!--', substr($html, $lt))) {
                return $lt;
            }
            $from = $lt === $resumeAt ? $lt + $partial : $lt;
            if (substr($html, $lt, 4) === '<!--') {
                // `<!-->` and `<!--->` end a comment at once, as browsers do.
                foreach (['<!-->', '<!--->'] as $abrupt) {
                    if (substr($html, $lt, strlen($abrupt)) === $abrupt) {
                        $offset = $lt + strlen($abrupt);
                        continue 2;
                    }
                }
                $start = max($lt + 4, $from);
                $close = strpos($html, '-->', $start);
                $state['scanned'] += ($close === false ? $length : $close) - $start;
                if ($close === false) {
                    // The last two bytes may begin a `-->` the next write ends.
                    return $unfinished($lt, max($lt + 4, $length - 2));
                }
                $offset = $close + 3;
                continue;
            }
            $start = max($lt + 2, $from);
            $close = strpos($html, '>', $start);
            $state['scanned'] += ($close === false ? $length : $close) - $start;
            if ($close === false) {
                return $unfinished($lt, $length);
            }
            $offset = $close + 1;
            continue;
        }
        if (!ctype_alpha($next) && $next !== '/') {
            $offset = $lt + 1;
            continue;
        }
        $end = null;
        $resumed = $lt === $resumeAt && $partial > 0;
        $i = $resumed ? $lt + $partial : $lt + 1;
        $quote = $resumed ? $partialQuote : null;
        while (true) {
            if ($quote !== null) {
                $close = strpos($html, $quote, $i);
                $state['scanned'] += ($close === false ? $length : $close) - $i;
                if ($close === false) {
                    $i = $length;
                    break;
                }
                $i = $close + 1;
                $quote = null;
            }
            $run = strcspn($html, '"\'>', $i);
            $state['scanned'] += $run;
            $i += $run;
            if ($i >= $length) {
                break;
            }
            if ($html[$i] === '>') {
                $end = $i + 1;
                break;
            }
            $quote = $html[$i++];
        }
        if ($end === null) {
            return $unfinished($lt, $length, $quote);
        }
        $token = substr($html, $lt, $end - $lt);
        if (preg_match('~^<(/?)([a-z][^\s/>]*)~i', $token, $parsed) !== 1) {
            $offset = $end;
            continue;
        }
        $name = strtolower($parsed[2]);
        $kind = $parsed[1] === '/' ? 'end' : 'start';
        if ($visit($kind, $name, $token, $lt) === false) {
            return $end;
        }
        if ($kind === 'start' && in_array($name, STATTIC_HTML_RAW_TEXT_ELEMENTS, true)) {
            $state['raw'] = $name;
        }
        $offset = $end;
    }
    return $length;
}

/**
 * Where head markup goes: just past the first `<head` start tag, else the
 * first `<html` one, among tokens starting in the first `$limit` bytes. Null
 * when there is neither; false while the bytes held so far cannot settle it
 * (a `<head` may still arrive inside the window).
 *
 * Neither tag counts inside `<template>`, `<svg>` or `<math>`: a browser
 * never makes one there the document's head, so markup placed after it would
 * never run. The finalizer applies the same rule to the pages it decorates
 * (`head_anchor` in crates/stattic-runtime-core/src/content/mod.rs); the
 * cases both suites read are in runtime/tests/fixtures/html-head-anchor.json.
 *
 * Resumable: `$scan` keeps where the previous call over the same, growing
 * buffer stopped, so a body that arrives in small chunks is lexed once, not
 * once per chunk.
 */
function _stattic_html_head_anchor(string $html, bool $final, int $limit, array &$scan): int|false|null
{
    $scan += ['offset' => 0, 'lex' => [], 'inert' => 0, 'root' => null];
    $head = null;
    $consumed = _stattic_html_lex($html, $scan['lex'], $final, static function (string $kind, string $name, string $token, int $offset) use (&$head, &$scan, $limit): bool {
        if ($offset >= $limit) {
            return false;
        }
        if ($name === 'template' || $name === 'svg' || $name === 'math') {
            if ($kind === 'end') {
                $scan['inert'] = max(0, $scan['inert'] - 1);
            } elseif ($name === 'template' || !str_ends_with($token, '/>')) {
                // `<svg/>` is empty; `<template/>` still opens, as in a browser.
                $scan['inert']++;
            }
            return true;
        }
        if ($kind !== 'start' || $scan['inert'] > 0) {
            return true;
        }
        if ($name === 'head') {
            $head = $offset + strlen($token);
            return false;
        }
        if ($name === 'html') {
            $scan['root'] ??= $offset + strlen($token);
        }
        return true;
    }, $scan['offset']);
    $scan['offset'] = $consumed;
    $settled = $head !== null
        || $final
        || $consumed >= $limit
        || strlen($html) >= $limit + STATTIC_HTML_INSERT_TOKEN_LIMIT_BYTES;
    return $settled ? ($head ?? $scan['root']) : false;
}

/**
 * Whether the Space set `superpowers.disable_all_injection`, which the route's
 * SDK section carries as `injection: false`, or the served version's own
 * `sf.jsonc` did, which its root's injection record carries as
 * `platform: false`. Then the runtime adds no SDK
 * tag or custom snippets. A section without the field comes from a control
 * plane that projected one for
 * every Space and said nothing about the setting, so it keeps that engine's
 * behaviour.
 */
function _stattic_platform_injection_disabled(array $serving): bool
{
    // The served version's own `sf.jsonc` opt-out, recorded on its root.
    if (($serving['inject']['platform'] ?? null) === false) {
        return true;
    }
    $sdk = $serving['sdk'] ?? null;
    return is_array($sdk) && ($sdk['injection'] ?? null) === false;
}

// What the runtime adds markup on: an SDK section that does not opt out.
function _stattic_platform_injection_enabled(array $serving): bool
{
    return is_array($serving['sdk'] ?? null) && !_stattic_platform_injection_disabled($serving);
}

// The engine's SDK tag, or '' when the Space turned platform injection off.
// `defer`, not `async`: the loader's placeholder orb reads `document.body`,
// which an async head script can run before. A tag-preview request names its
// draft release on the script URL; a baked tag needs no token, since sdk.js
// reads the draft from the page URL.
function _stattic_spacefast_sdk_tag(array $serving, ?string $previewToken): string
{
    if (!_stattic_platform_injection_enabled($serving)) {
        return '';
    }
    $src = STATTIC_SPACEFAST_SDK_PATH . ($previewToken === null ? '' : '?preview=' . rawurlencode($previewToken));
    return '<script defer src="' . $src . '" data-spacefast-sdk="v1"></script>';
}

function _stattic_spacefast_tag_preview_token(string $requestMethod): ?string
{
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        return null;
    }
    $raw = $_GET[STATTIC_TAG_PREVIEW_QUERY_NAME] ?? null;
    return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
}

/**
 * The SDK tag a static page served from blob `$blobSha` gets added in its
 * head, or ''. Only a page the version's injection record lists as one finalize
 * could not decorate: every other page carries the baked tag, and a version
 * without the record (finalized before it existed) baked every page. Never
 * reads the blob.
 */
function _stattic_static_page_sdk_tag(array $serving, ?string $previewToken, string $blobSha): string
{
    $undecorated = $serving['inject']['undecorated'] ?? null;
    return is_array($undecorated) && in_array($blobSha, $undecorated, true)
        ? _stattic_spacefast_sdk_tag($serving, $previewToken)
        : '';
}

// What a dynamic lane's response gets: the version's own snippets by placement,
// and the engine's SDK tag. The baked SDK snippet is left out of the bodyEnd
// placement, so the response gets one tag from this filter. A static page a
// worker relays from its assets binding already carries the snippets finalize
// baked; for that response the filter keeps only the SDK tag
// (_stattic_html_insert_payload_for_response).
function _stattic_html_insert_payload(array $serving): array
{
    $inject = is_array($serving['inject'] ?? null) ? $serving['inject'] : [];
    $snippets = static fn (string $placement): string => implode('', array_filter(
        is_array($inject[$placement] ?? null) ? $inject[$placement] : [],
        static fn ($snippet): bool => is_string($snippet)
            && !str_contains($snippet, 'data-spacefast-sdk=')
    ));
    return [
        'head' => $snippets('head'),
        'bodyStart' => $snippets('noscript') . $snippets('bodyStart'),
        'bodyEnd' => $snippets('bodyEnd'),
        'sdk' => _stattic_spacefast_sdk_tag(
            $serving,
            _stattic_spacefast_tag_preview_token(_stattic_runtime_request_method())
        ),
    ];
}

/**
 * The header the assets lane stamps on a finalize-decorated page it serves to
 * a worker, and that the dispatch filter verifies and removes. `env.ASSETS`
 * is a separate request from the worker back to this origin, so the two share
 * no process; the stamp rides the worker's response. It is an HMAC under this
 * box's key over the dispatch's nonce (random, minted per dispatch, never
 * taken from a request header), the Space, the version and the blob, so a
 * worker can strip it (its page gets the snippets again, as before the stamp)
 * or forward it unchanged, but never mint one for another page, and a stamp
 * captured on one dispatch never verifies on another. Within one dispatch the
 * worker can copy a valid stamp onto other HTML it returns, which suppresses
 * only its own Space's snippets there, something its owner can do anyway by
 * removing them. The `sf-fx-` prefix keeps it out of every response the
 * visitor sees.
 */
const STATTIC_HTML_INSERT_DECORATED_HEADER = 'sf-fx-decorated';

/** The nonce for one dispatch: what binds a decorated stamp to it alone. */
function _stattic_html_insert_dispatch_nonce(): string
{
    return bin2hex(random_bytes(16));
}

function _stattic_html_insert_decorated_stamp(string $privateRoot, string $nonce, string $spaceId, string $versionId, string $blobSha): ?string
{
    if (
        preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
        || $spaceId === '' || $versionId === ''
        || preg_match('/^[a-f0-9]{64}$/', $blobSha) !== 1
    ) {
        return null;
    }
    $key = _stattic_lazy_minted_secret($privateRoot, 'html-insert-key', 32);
    return is_string($key)
        ? $blobSha . '.' . hash_hmac(
            'sha256',
            implode("\0", ['html-insert-decorated:v2', $nonce, $spaceId, $versionId, $blobSha]),
            $key
        )
        : null;
}

/**
 * Whether finalize decorated the page served from `$blobSha`, read from the
 * version's injection record: every page it does not list as undecorated.
 */
function _stattic_html_insert_blob_decorated(array $serving, string $blobSha): bool
{
    $inject = $serving['inject'] ?? null;
    if (!is_array($inject)) {
        // A version from before the record names no page either way; it is
        // stamped never, and the relayed page keeps today's insertion.
        return false;
    }
    $undecorated = is_array($inject['undecorated'] ?? null) ? $inject['undecorated'] : [];
    return !in_array($blobSha, $undecorated, true);
}

/**
 * The payload for one dynamic response: the request's payload, or only its SDK
 * tag when the response carries a valid decorated stamp for this dispatch's
 * nonce, Space and version. `$headerPairs` is the upstream's header list as
 * the relay received it.
 */
function _stattic_html_insert_payload_for_response(array $payload, array $headerPairs, string $privateRoot, string $nonce, string $spaceId, string $versionId): array
{
    foreach ($headerPairs as $pair) {
        if (!is_array($pair) || strtolower(trim((string) ($pair[0] ?? ''))) !== STATTIC_HTML_INSERT_DECORATED_HEADER) {
            continue;
        }
        $value = trim((string) ($pair[1] ?? ''));
        $blobSha = strstr($value, '.', true);
        $expected = is_string($blobSha)
            ? _stattic_html_insert_decorated_stamp($privateRoot, $nonce, $spaceId, $versionId, $blobSha)
            : null;
        if ($expected !== null && hash_equals($expected, $value)) {
            return ['head' => '', 'bodyStart' => '', 'bodyEnd' => '', 'sdk' => $payload['sdk']];
        }
    }
    return $payload;
}

// Whether a dynamic lane keeps this request's conditionals from its upstream.
// Decided before any response exists, so on the request's own statement that
// it loads a document: the filter drops the validators of the HTML it changes,
// and an upstream 304 must never stand in for a page whose injection moved.
// Subresources keep revalidating against the upstream.
function _stattic_html_insert_withholds_validators(array $payload): bool
{
    if (implode('', $payload) === '') {
        return false;
    }
    $destination = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
    return in_array($destination, ['document', 'iframe', 'frame'], true)
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
}

const STATTIC_HTML_INSERT_WITHHELD_REQUEST_HEADERS = ['if-none-match', 'if-modified-since'];

/**
 * Splices `$markup` in just past the head anchor of a buffered page, or
 * returns it unchanged. A page with neither `<head` nor `<html` in the window
 * is a fragment and keeps its bytes.
 */
function _stattic_splice_head_markup(string $html, string $markup): string
{
    if ($markup === '') {
        return $html;
    }
    // The static lane's head window is the compiler's 64 KiB, all of it held.
    $scan = [];
    $at = _stattic_html_head_anchor($html, true, 65536, $scan);
    return !is_int($at) ? $html : substr($html, 0, $at) . $markup . substr($html, $at);
}


// Installs the filter for the response about to be written. Call it as late as
// possible, after the response headers are registered and immediately before
// the first body byte, so no platform error page can ever be filtered.
function _stattic_html_insert_stream_begin(array $payload): void
{
    if (!empty($GLOBALS['SPACEFAST_HTML_INSERT_ACTIVE'])) {
        return;
    }
    if (implode('', $payload) === '') {
        return;
    }
    // Headers already on the wire means the Content-Length we must strip is
    // gone too, and a wrong length truncates the response. Leave it alone.
    if (headers_sent()) {
        return;
    }
    $state = ['mode' => 'probe', 'held' => '', 'lex' => [], 'scan' => [], 'journaled' => false];
    $GLOBALS['SPACEFAST_HTML_INSERT_ACTIVE'] = true;
    // chunk_size 1: hand every write to the callback instead of buffering the
    // whole response, which is what makes this a streaming filter.
    ob_start(
        static function (string $chunk, int $phase) use (&$state, &$payload): string {
            return _stattic_html_insert_filter($state, $payload, $chunk, $phase);
        },
        1
    );
}

// Deterministic end of the filtered response: flushes whatever the filter still
// holds through the FINAL phase. Callers that `exit` would get this from PHP's
// shutdown anyway; doing it explicitly keeps the decision at the call site.
function _stattic_html_insert_stream_end(): void
{
    if (empty($GLOBALS['SPACEFAST_HTML_INSERT_ACTIVE'])) {
        return;
    }
    $GLOBALS['SPACEFAST_HTML_INSERT_ACTIVE'] = false;
    ob_end_flush();
}

function _stattic_html_insert_filter(array &$state, array &$payload, string $chunk, int $phase): string
{
    $final = ($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0;
    if ($state['mode'] === 'off') {
        return $chunk;
    }
    if ($state['mode'] === 'tail') {
        // The anchors are past; only the SDK tag is still to place.
        return $final ? $chunk . $payload['sdk'] : $chunk;
    }
    if ($state['mode'] === 'probe') {
        if (!_stattic_html_insert_eligible()) {
            $state['mode'] = 'off';
            return $chunk;
        }
        // D95. With an output handler installed PHP frames the body itself, so
        // the only wrong answer here is a stale upstream length.
        header_remove('Content-Length');
        $state['mode'] = 'head';
    }
    $state['held'] .= $chunk;
    if ($state['mode'] === 'head') {
        $held = $state['held'];
        $anchor = _stattic_html_head_anchor($held, $final, STATTIC_HTML_INSERT_SCAN_LIMIT_BYTES, $state['scan']);
        if ($anchor === false) {
            return '';
        }
        if ($anchor === null) {
            // A fragment (an htmx or Turbo partial, even one with a <body>)
            // keeps every byte, as on main: no SDK tag and no snippets, so a
            // swap never runs either again.
            _stattic_html_insert_journal_miss($state);
            $state['held'] = '';
            $state['mode'] = 'off';
            return $held;
        }
        // Nothing has been emitted yet, so headers can still change. The
        // upstream's validators describe bytes this filter is about to change,
        // and a 304 against them would reuse markup another version injected.
        // A partial that keeps its bytes keeps them too.
        header_remove('ETag');
        header_remove('Last-Modified');
        $state['held'] = substr($held, 0, $anchor) . $payload['sdk'] . $payload['head'] . substr($held, $anchor);
        $payload['sdk'] = '';
        $state['mode'] = 'body';
    }
    return _stattic_html_insert_body($state, $payload, $final);
}

/**
 * The body pass over what the filter holds: emits every byte it has decided
 * and keeps only an unfinished token. bodyStart goes after the first `<body>`
 * start tag and bodyEnd before the first `</body>`. An SDK tag the head could
 * not take goes at the end of the stream.
 */
function _stattic_html_insert_body(array &$state, array &$payload, bool $final): string
{
    $sdk = $payload['sdk'];
    if ($payload['bodyStart'] === '' && $payload['bodyEnd'] === '') {
        $out = $state['held'];
        $state['held'] = '';
        if ($final) {
            $state['mode'] = 'off';
            return $out . $sdk;
        }
        $state['mode'] = $sdk === '' ? 'off' : 'tail';
        return $out;
    }
    $held = $state['held'];
    $out = '';
    $cursor = 0;
    $consumed = _stattic_html_lex(
        $held,
        $state['lex'],
        $final,
        static function (string $kind, string $name, string $token, int $offset) use (&$payload, &$out, &$cursor, $held): bool {
            if ($name !== 'body') {
                return true;
            }
            if ($kind === 'start' && $payload['bodyStart'] !== '') {
                $end = $offset + strlen($token);
                $out .= substr($held, $cursor, $end - $cursor) . $payload['bodyStart'];
                $cursor = $end;
                $payload['bodyStart'] = '';
            } elseif ($kind === 'end' && $payload['bodyEnd'] !== '') {
                $out .= substr($held, $cursor, $offset - $cursor) . $payload['bodyEnd'];
                $cursor = $offset;
                $payload['bodyEnd'] = '';
            }
            return $payload['bodyStart'] !== '' || $payload['bodyEnd'] !== '';
        }
    );
    $out .= substr($held, $cursor, $consumed - $cursor);
    $rest = substr($held, $consumed);
    $state['held'] = $rest;
    if ($final) {
        $state['mode'] = 'off';
        $state['held'] = '';
        return $out . $rest . $sdk;
    }
    if ($payload['bodyStart'] === '' && $payload['bodyEnd'] === '') {
        // Both anchors placed: the rest streams as it is.
        $state['held'] = '';
        $state['mode'] = $sdk === '' ? 'off' : 'tail';
        return $out . $rest;
    }
    if (strlen($rest) > STATTIC_HTML_INSERT_TOKEN_LIMIT_BYTES) {
        // A token this long is not one the filter can place anything around:
        // the rest is relayed as it is, and the SDK tag still lands at the end.
        $state['held'] = '';
        $state['mode'] = $sdk === '' ? 'off' : 'tail';
        return $out . $rest;
    }
    return $out;
}

// D46/D77. Decided at the first written byte, not at install time: the proxy
// and function lanes register their response headers between the two.
// Every whole HTML document is changed, a 404 or 500 page as much as a 200,
// so a Space's error page reads the same on every lane. A byte range never
// is: a 206, or any response with Content-Range, would no longer match the
// range it declares. A 204 or 304 has no body to change.
function _stattic_html_insert_eligible(): bool
{
    if (headers_sent() || in_array(http_response_code(), [204, 206, 304], true)) {
        return false;
    }
    $contentType = null;
    foreach (headers_list() as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $separator)));
        $value = strtolower(trim(substr($line, $separator + 1)));
        if ($name === 'x-accel-buffering' && $value === 'no') {
            return false; // A real stream (D77): never hold its bytes.
        }
        if ($name === 'content-type') {
            $contentType = $value;
        }
        if ($name === 'content-encoding' && $value !== '' && $value !== 'identity') {
            return false; // Compressed bytes: no anchor can be found in them.
        }
        if ($name === 'content-range') {
            return false; // Part of a document, whatever its status says.
        }
    }
    // No declared type is not HTML as far as this filter is concerned: the
    // insert is never applied on a guess.
    return $contentType !== null
        && (str_starts_with($contentType, 'text/html;') || $contentType === 'text/html');
}

// D45/D94: a page with no anchor is served unchanged, and the miss is recorded
// once per request. The journal is the only sink (D53), and it is guarded
// because this file is also reachable from lanes that never load storage.php.
function _stattic_html_insert_journal_miss(array &$state): void
{
    if ($state['journaled']) {
        return;
    }
    $state['journaled'] = true;
    if (!function_exists('_stattic_runtime_append_journal') || !function_exists('_stattic_access_private_root')) {
        return;
    }
    $privateRoot = _stattic_access_private_root();
    if ($privateRoot === '') {
        return;
    }
    _stattic_runtime_append_journal(
        $privateRoot,
        [
            'event' => 'html_insert_anchor_missing',
            // The journal outlives the request; an access token in the URL must
            // not.
            'path' => is_string($_SERVER['REQUEST_URI'] ?? null)
                ? substr(_stattic_redact_access_secrets($_SERVER['REQUEST_URI']), 0, 256)
                : '',
        ],
        false
    );
}

/** Normalize only platform-owned fallback tags, using today's anonymous access. */
function _stattic_normalize_auto_share_image(string $html, bool $public, string $host, string $path): string
{
    $edits = [];
    $state = [];
    _stattic_html_lex($html, $state, false, static function (string $kind, string $name, string $token, int $offset) use (&$edits, $public, $host, $path): bool {
        if (($kind === 'end' && $name === 'head') || ($kind === 'start' && $name === 'body')) {
            return false;
        }
        if ($kind !== 'start' || $name !== 'meta') {
            return true;
        }
        // Consume complete quoted values so marker-like text in another
        // attribute cannot turn an authored tag into a platform fallback.
        preg_match_all('~([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*)))?~', substr($token, 5), $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            if (!array_key_exists($key, $attributes)) {
                $attributes[$key] = $match[2] ?? $match[3] ?? $match[4] ?? '';
            }
        }
        $marker = $attributes['data-spacefast-auto-image'] ?? '';
        if ($marker !== 'og:image' && $marker !== 'twitter:card') {
            return true;
        }
        $replacement = '';
        if ($public) {
            if ($marker === 'og:image') {
                $url = 'https://s0.wp.com/mshots/v1/' . rawurlencode('https://' . $host . $path) . '?w=1200&h=630';
                $replacement = '<meta data-spacefast-auto-image="og:image" property="og:image" content="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            } else {
                $replacement = '<meta name="twitter:card" content="summary_large_image" data-spacefast-auto-image="twitter:card">';
            }
        }
        $edits[] = [$offset, strlen($token), $replacement];
        return true;
    });
    foreach (array_reverse($edits) as [$offset, $length, $replacement]) {
        $html = substr_replace($html, $replacement, $offset, $length);
    }
    return $html;
}
