<?php
declare(strict_types=1);

// THE streaming HTML insert filter (D44–D46, D77, D94, D95).
//
// Static HTML gets its snippets at compile time. Everything dynamic — the
// function proxy, route proxies, Zero — gets them here, at the one point where
// a response body leaves the runtime, so there is exactly one applier and none
// at the edge (D47).
//
// The rules that make it safe to run on a stream:
//   * Bytes are held only until the anchor is found, or until 16 KiB, and never
//     after (D94). The anchor is the first `<head` START tag: a start tag
//     cannot appear inside a script, because no script can precede it. We do
//     not look for `</head>` — that text CAN appear inside a script. With no
//     `<head` in the window, the `<html` start tag is the anchor. With neither,
//     the response passes through untouched and the miss is journaled once.
//   * text/html only, never an event stream (D46/D77).
//   * The upstream Content-Length is removed and the body goes out chunked
//     (D95) — the insert changes the length, and with an output handler
//     installed PHP owns the framing.
require_once __DIR__ . '/context.php';

const STATTIC_HTML_INSERT_SCAN_LIMIT_BYTES = 16384;
// Slack past the scan window so an anchor that STARTS inside the window but
// carries a long attribute list still completes. Bounds what we ever hold.
const STATTIC_HTML_INSERT_TAG_SLACK_BYTES = 4096;
// Mirrors the compiler's config limits (CONFIG_INJECT_SNIPPET_LIMIT /
// CONFIG_INJECT_SNIPPET_MAX_BYTES in crates/stattic-runtime-core/src/protocol.rs).
const STATTIC_HTML_INSERT_SNIPPET_LIMIT = 16;
const STATTIC_HTML_INSERT_SNIPPET_MAX_BYTES = 8192;

// The dynamic lanes mirror the compile-time head placement only: bodyStart /
// bodyEnd / noscript anchors are not byte-findable on a stream without a real
// parse, and D115 keeps the streaming filter on the `<head` start tag.
function _stattic_html_insert_snippets(array $servingOrConfig): array
{
    $inject = $servingOrConfig['inject'] ?? null;
    if (!is_array($inject) || !is_array($inject['head'] ?? null)) {
        return [];
    }
    $snippets = [];
    foreach ($inject['head'] as $snippet) {
        if (!is_string($snippet) || $snippet === '' || strlen($snippet) > STATTIC_HTML_INSERT_SNIPPET_MAX_BYTES) {
            continue;
        }
        $snippets[] = $snippet;
        if (count($snippets) >= STATTIC_HTML_INSERT_SNIPPET_LIMIT) {
            break;
        }
    }
    return $snippets;
}

// Installs the filter for the response about to be written. Call it as late as
// possible — after the response headers are registered and immediately before
// the first body byte — so no platform error page can ever be filtered.
function _stattic_html_insert_stream_begin(array $snippets): void
{
    if (!empty($GLOBALS['SPACEFAST_HTML_INSERT_ACTIVE'])) {
        return;
    }
    $payload = implode('', _stattic_html_insert_snippets(['inject' => ['head' => $snippets]]));
    if ($payload === '') {
        return;
    }
    // Headers already on the wire means the Content-Length we must strip is
    // gone too, and a wrong length truncates the response. Leave it alone.
    if (headers_sent()) {
        return;
    }
    $state = ['mode' => 'probe', 'held' => '', 'journaled' => false];
    $GLOBALS['SPACEFAST_HTML_INSERT_ACTIVE'] = true;
    // chunk_size 1: hand every write to the callback instead of buffering the
    // whole response, which is what makes this a streaming filter.
    ob_start(
        static function (string $chunk, int $phase) use (&$state, $payload): string {
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

function _stattic_html_insert_filter(array &$state, string $payload, string $chunk, int $phase): string
{
    if ($state['mode'] === 'off') {
        return $chunk;
    }
    $final = ($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0;
    if ($state['mode'] === 'probe') {
        if (!_stattic_html_insert_eligible()) {
            $state['mode'] = 'off';
            return $chunk;
        }
        // D95. With an output handler installed PHP frames the body itself, so
        // the only wrong answer here is a stale upstream length.
        header_remove('Content-Length');
        $state['mode'] = 'scan';
    }

    $state['held'] .= $chunk;
    $held = $state['held'];
    $exhausted = $final || strlen($held) >= STATTIC_HTML_INSERT_SCAN_LIMIT_BYTES + STATTIC_HTML_INSERT_TAG_SLACK_BYTES;

    $anchor = _stattic_html_insert_anchor($held, '<head', STATTIC_HTML_INSERT_SCAN_LIMIT_BYTES);
    if ($anchor['state'] === 'pending' && !$exhausted) {
        return '';
    }
    if ($anchor['state'] === 'absent' && !$exhausted && strlen($held) < STATTIC_HTML_INSERT_SCAN_LIMIT_BYTES) {
        // `<head` may still arrive inside the window.
        return '';
    }
    if ($anchor['state'] !== 'found') {
        $anchor = _stattic_html_insert_anchor($held, '<html', STATTIC_HTML_INSERT_SCAN_LIMIT_BYTES);
        if ($anchor['state'] === 'pending' && !$exhausted) {
            return '';
        }
    }

    $state['held'] = '';
    $state['mode'] = 'off';
    if ($anchor['state'] !== 'found') {
        _stattic_html_insert_journal_miss($state);
        return $held;
    }
    $at = $anchor['end'];
    return substr($held, 0, $at) . $payload . substr($held, $at);
}

// Byte-based, deliberately (D94/D115): returns where the anchor tag CLOSES.
//   found   → ['state' => 'found', 'end' => offset just past '>']
//   pending → a candidate starts inside the window but has not closed yet
//   absent  → no candidate starts inside the window
function _stattic_html_insert_anchor(string $subject, string $tag, int $maxStart): array
{
    $length = strlen($subject);
    $tagLength = strlen($tag);
    $offset = 0;
    while (true) {
        $start = stripos($subject, $tag, $offset);
        if ($start === false || $start >= $maxStart) {
            return ['state' => 'absent'];
        }
        $after = $start + $tagLength;
        if ($after >= $length) {
            // Cannot yet tell `<head` from `<header`.
            return ['state' => 'pending'];
        }
        $next = $subject[$after];
        if ($next !== '>' && $next !== '/' && trim($next) !== '') {
            $offset = $after; // `<header`, `<htmlish` — keep looking.
            continue;
        }
        $end = strpos($subject, '>', $after);
        if ($end === false) {
            return ['state' => 'pending'];
        }
        return ['state' => 'found', 'end' => $end + 1];
    }
}

// D46/D77. Decided at the first written byte, not at install time: the proxy
// and function lanes register their response headers between the two.
function _stattic_html_insert_eligible(): bool
{
    if (headers_sent()) {
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
            return false; // A real stream (D77) — never hold its bytes.
        }
        if ($name === 'content-type') {
            $contentType = $value;
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
