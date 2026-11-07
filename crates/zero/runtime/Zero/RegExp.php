<?php

declare(strict_types=1);

namespace Zero;

/**
 * A JavaScript RegExp, translated to a PCRE pattern once (Js::re caches instances).
 *
 * PCRE always runs in UTF mode, so offsets are UTF-8 byte offsets internally and
 * `.`/classes consume whole code points. JS semantics kept explicitly: ASCII-only
 * \d \w \b, JS \s, `.` excluding JS line terminators, `$` only at the very end
 * (without m), `[]`/`[^]`, and backreferences to unset groups matching empty.
 */
final class RegExp implements \Stringable
{
    private const LINE_TERMINATORS = '\n\r\x{2028}\x{2029}';
    private const WORD = 'A-Za-z0-9_';
    private const HALF = "\u{FDD0}";

    public readonly string $source;
    public readonly string $flags;
    public readonly bool $global;
    public mixed $lastIndex = 0;
    /** The compiled PCRE pattern, including delimiters and modifiers. */
    public readonly string $pattern;
    private readonly string $anchoredPattern;
    /** @var list<string> named groups, in declaration order */
    private array $names = [];
    private int $groupCount = 0;

    // translation state
    private string $src;
    private int $pos;
    private bool $dotAll;
    private bool $multiline;
    private bool $unicode;
    /** These atoms can consume a surrogate half or distinguish it from a UTF-8 stand-in. */
    private bool $requiresUnicodeOnAstral = false;

    public function __construct(string $source, string $flags = '')
    {
        $chars = str_split($flags);
        foreach ($chars as $f) {
            if ($f === 'y' || $f === 'd' || $f === 'v') {
                throw new JsError("RegExp flag '$f' is not supported", 'SyntaxError');
            }
            if (!in_array($f, ['g', 'i', 'm', 's', 'u'], true)) {
                throw new JsError("Invalid regular expression flags '$flags'", 'SyntaxError');
            }
        }
        if (count(array_unique($chars)) !== count($chars)) {
            throw new JsError("Invalid regular expression flags '$flags'", 'SyntaxError');
        }
        $order = array_flip(['d', 'g', 'i', 'm', 's', 'u', 'v', 'y']);
        usort($chars, static fn ($a, $b) => $order[$a] <=> $order[$b]);

        $this->source = $source === '' ? '(?:)' : $source;
        $this->flags = implode('', $chars);
        $this->global = in_array('g', $chars, true);
        $this->dotAll = in_array('s', $chars, true);
        $this->multiline = in_array('m', $chars, true);
        $this->unicode = in_array('u', $chars, true);

        $this->src = $source;
        $this->pos = 0;
        $body = $this->translate();
        $this->pattern = '/' . $body . '/u' . (in_array('i', $chars, true) ? 'i' : '');
        $this->anchoredPattern = $this->pattern . 'A';
        $this->requiresUnicodeOnAstral = $this->requiresUnicodeOnAstral
            || preg_match('/[\x{10000}-\x{10FFFF}]/u', $source) === 1;

        set_error_handler(static fn () => true);
        try {
            $ok = preg_match($this->pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
        if (!$ok) {
            throw new JsError("Invalid regular expression: /$source/{$this->flags}: " . preg_last_error_msg(), 'SyntaxError');
        }
    }

    public function __toString(): string
    {
        return '/' . $this->source . '/' . $this->flags;
    }

    public function test(string $s): bool
    {
        return $this->matchAt($s, 0) !== null;
    }

    public function hasNamedGroups(): bool
    {
        return $this->names !== [];
    }

    /**
     * First match at or after byte offset $offset.
     *
     * @return ?array{int, int, list<?string>, array<string, ?string>} [start, end, groups, named groups]
     */
    public function matchAt(string $s, int $offset, bool $anchored = false): ?array
    {
        $r = preg_match($anchored ? $this->anchoredPattern : $this->pattern, $s, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset);
        if ($r === false) {
            throw new JsError('RegExp execution failed: ' . preg_last_error_msg());
        }
        if ($r === 0) {
            return null;
        }
        $groups = [];
        $named = [];
        foreach ($m as $k => [$text]) {
            if (is_int($k) && $k > 0) {
                $groups[] = $text;
            } elseif (is_string($k)) {
                $named[$k] = $text;
            }
        }
        // PCRE omits trailing unset groups; JS reports them as undefined.
        for ($i = count($groups); $i < $this->groupCount; $i++) {
            $groups[] = null;
        }

        return [$m[0][1], $m[0][1] + strlen($m[0][0]), $groups, $named];
    }

    /**
     * All matches left to right; empty matches advance one code point, or one UTF-16 unit without
     * the u flag, as in JS. Without it, JS also tries the position between a surrogate pair's two
     * halves, including where PCRE finds no match at the character's start (see insidePair()).
     *
     * @return list<array{0: int, 1: int, 2: list<?string>, 3: array<string, ?string>, 4?: true}>
     */
    public function matchAll(string $s): array
    {
        if (!$this->unicode && $this->requiresUnicodeOnAstral
            && preg_match('/[\x{10000}-\x{10FFFF}]/u', $s) === 1) {
            throw new JsError('Dots, complemented shorthands, character classes, lookarounds and surrogate or astral literals on text outside the Basic Multilingual Plane (an emoji, say) require Unicode mode; add the u flag', 'RangeError');
        }
        // Expand once; every probe then runs anchored against this shared buffer. Mapping the
        // original byte positions keeps callback offsets and ordinary matches on the real string.
        $insideOffsets = [];
        $shadow = $this->unicode ? $s : preg_replace_callback(
            '/[\x{10000}-\x{10FFFF}]/u',
            static function (array $m) use (&$insideOffsets): string {
                $pos = $m[0][1];
                $insideOffsets[$pos] = $pos + (2 * strlen(self::HALF) - 4) * count($insideOffsets) + strlen(self::HALF);

                return self::HALF . self::HALF;
            },
            $s,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );
        // Starting at zero lets PHP cache UTF-8 validity across the interior probes.
        if ($shadow === null || preg_match('//u', $shadow) === false) {
            throw new JsError('RegExp execution failed: ' . preg_last_error_msg());
        }
        $out = [];
        $offset = 0;
        $size = strlen($s);
        while ($offset <= $size) {
            $m = $this->matchAt($s, $offset);
            if ($insideOffsets !== []) {
                // PCRE may find no boundary match at the astral character's start. Try every
                // in-pair position before the next ordinary match, including when none exists.
                $until = $m[0] ?? $size;
                for ($scan = $offset; $scan < $until; $scan += Js::charLenAt($s, $scan)) {
                    if (isset($insideOffsets[$scan]) && ($in = $this->insidePair($shadow, $insideOffsets[$scan], $scan)) !== null) {
                        $out[] = $in;
                    }
                }
            }
            if ($m === null) {
                break;
            }
            $out[] = $m;
            $offset = $m[1] > $m[0] ? $m[1] : $m[1] + Js::charLenAt($s, $m[1]);
            if ($m[1] === $m[0] && isset($insideOffsets[$m[1]]) && ($in = $this->insidePair($shadow, $insideOffsets[$m[1]], $m[1])) !== null) {
                $out[] = $in;
            }
        }

        return $out;
    }

    /**
     * The match JS finds between the two halves of the surrogate pair at byte `$pos`, if any: an
     * empty match there, flagged with a fifth element. PCRE can't stand inside a UTF-8 character,
     * so the pattern runs on a shared shadow string, where every pair becomes two noncharacter
     * stand-ins. An empty match only looks at its neighbours through anchors, `\b`/`\B` (a half
     * and a stand-in are both non-word, non-line-terminator) and literals. Dots, complemented
     * shorthands, character classes, lookarounds and surrogate or astral literals could distinguish the representation, so
     * matchAll refuses those before trying any stand-in.
     * A match that consumes a half
     * would make a lone surrogate, which throws (the documented deviation).
     *
     * @return ?array{0: int, 1: int, 2: list<?string>, 3: array<string, ?string>, 4: true}
     */
    private function insidePair(string $shadow, int $at, int $pos): ?array
    {
        $m = $this->matchAt($shadow, $at, true);
        if ($m === null || $m[0] !== $at) {
            return null;
        }
        if ($m[1] !== $at) {
            Js::splitsPair();
        }

        return [$pos, $pos, $m[2], $m[3], true];
    }

    // ---------------------------------------------------------------- translation

    private function translate(): string
    {
        $this->groupCount = preg_match_all('/\((?!\?)|\(\?<(?![=!])/', preg_replace('/\\\\.|\[(?:\\\\.|[^\]\\\\])*\]/s', '', $this->src) ?? '');
        $out = '';
        $len = strlen($this->src);
        while ($this->pos < $len) {
            $c = $this->src[$this->pos++];
            if ($c === '.') {
                $this->requiresUnicodeOnAstral = true;
            }
            $out .= match ($c) {
                '\\' => $this->escape(false),
                '[' => $this->charClass(),
                '.' => $this->dotAll ? '(?s:.)' : '[^' . self::LINE_TERMINATORS . ']',
                '^' => $this->multiline ? '(?<![^' . self::LINE_TERMINATORS . '])' : '^',
                '$' => $this->multiline ? '(?![^' . self::LINE_TERMINATORS . '])' : '\z',
                '/' => '\/',
                '(' => $this->groupOpen(),
                default => $c,
            };
        }

        return $out;
    }

    private function groupOpen(): string
    {
        if (preg_match('/\G\?<?[=!]/A', $this->src, $look, 0, $this->pos)) {
            $this->requiresUnicodeOnAstral = true;
        }
        if (preg_match('/\G\?<([A-Za-z_$][\w$]*)>/A', $this->src, $m, 0, $this->pos)) {
            if (str_contains($m[1], '$')) {
                throw new JsError("Group name '{$m[1]}' is not supported", 'SyntaxError');
            }
            $this->names[] = $m[1];
        }

        return '(';
    }

    /** Escape sequence after a backslash. Inside a class, returns a class-body fragment. */
    private function escape(bool $inClass): string
    {
        $c = $this->src[$this->pos++] ?? '\\';
        if (in_array($c, ['D', 'W', 'S'], true)) {
            $this->requiresUnicodeOnAstral = true;
        }

        switch ($c) {
            case 'd': return $inClass ? '0-9' : '[0-9]';
            case 'D': return $inClass ? "\0D" : '[^0-9]';
            case 'w': return $inClass ? self::WORD : '[' . self::WORD . ']';
            case 'W': return $inClass ? "\0W" : '[^' . self::WORD . ']';
            case 's': return $inClass ? Js::WS : '[' . Js::WS . ']';
            case 'S': return $inClass ? "\0S" : '[^' . Js::WS . ']';
            case 'b':
                return $inClass ? '\x{8}' : '(?:(?<=[' . self::WORD . '])(?![' . self::WORD . '])|(?<![' . self::WORD . '])(?=[' . self::WORD . ']))';
            case 'B':
                return $inClass ? 'B' : '(?:(?<=[' . self::WORD . '])(?=[' . self::WORD . '])|(?<![' . self::WORD . '])(?![' . self::WORD . ']))';
            case 'n': case 'r': case 't': case 'f':
                return '\\' . $c;
            case 'v': return '\x{b}';
            case 'c':
                $l = $this->src[$this->pos] ?? '';
                if (ctype_alpha($l)) {
                    $this->pos++;

                    return sprintf('\x{%x}', ord($l) % 32);
                }

                return '\\\\c';
            case 'x':
                if (preg_match('/\G[0-9A-Fa-f]{2}/A', $this->src, $m, 0, $this->pos)) {
                    $this->pos += 2;

                    return '\x{' . $m[0] . '}';
                }

                return 'x';
            case 'u':
                return $this->unicodeEscape();
            case 'k':
                if (preg_match('/\G<([^>]+)>/A', $this->src, $m, 0, $this->pos)) {
                    $this->pos += strlen($m[0]);

                    return $inClass ? 'k' : "(?:\\k<{$m[1]}>|(?(<{$m[1]}>)(?!)))";
                }

                return 'k';
            case 'p': case 'P':
                if ($this->unicode && preg_match('/\G\{[^}]*\}/A', $this->src, $m, 0, $this->pos)) {
                    $this->pos += strlen($m[0]);

                    return '\\' . $c . str_replace('Script=', 'sc=', $m[0]);
                }

                return $c;
        }
        if ($c === '0' && !ctype_digit($this->src[$this->pos] ?? '')) {
            return '\x{0}';
        }
        if (ctype_digit($c)) {
            preg_match('/\G\d*/A', $this->src, $m, 0, $this->pos);
            $n = (int) ($c . $m[0]);
            if ($c !== '0' && !$inClass && $n <= $this->groupCount) {
                $this->pos += strlen($m[0]);

                return "(?:\\g{{$n}}|(?({$n})(?!)))";
            }
            if ($this->unicode) {
                throw new JsError("Invalid escape '\\$c'", 'SyntaxError');
            }
            if ($c >= '8') {
                return $c; // legacy identity escape
            }
            // Legacy octal escape: up to three octal digits, value <= 0377.
            preg_match('/\G[0-7]{0,2}/A', $this->src, $o, 0, $this->pos);
            $oct = $c . $o[0];
            if (octdec($oct) > 0377) {
                $oct = substr($oct, 0, 2);
            }
            $this->pos += strlen($oct) - 1;

            return sprintf('\x{%x}', octdec($oct));
        }
        if (ctype_alnum($c)) {
            if ($this->unicode) {
                throw new JsError("Invalid escape '\\$c'", 'SyntaxError');
            }

            return $c; // legacy identity escape
        }

        // Punctuation (and any UTF-8 lead byte): keep it literal.
        if (ord($c) >= 0x80) {
            $this->pos--;
            $len = Js::charLenAt($this->src, $this->pos);
            $ch = substr($this->src, $this->pos, $len);
            $this->pos += $len;

            return $ch;
        }

        return '\\' . $c;
    }

    private function unicodeEscape(): string
    {
        if ($this->unicode && preg_match('/\G\{([0-9A-Fa-f]+)\}/A', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);

            return '\x{' . $m[1] . '}';
        }
        if (!preg_match('/\G[0-9A-Fa-f]{4}/A', $this->src, $m, 0, $this->pos)) {
            return 'u';
        }
        $this->pos += 4;
        $cp = hexdec($m[0]);
        if ($cp >= 0xD800 && $cp <= 0xDBFF && preg_match('/\G\\\\u([dD][c-fC-F][0-9A-Fa-f]{2})/A', $this->src, $lo, 0, $this->pos)) {
            $this->pos += 6;
            $cp = 0x10000 + (($cp - 0xD800) << 10) + (hexdec($lo[1]) - 0xDC00);
            $this->requiresUnicodeOnAstral = true;
        } elseif ($cp >= 0xD800 && $cp <= 0xDFFF) {
            $this->requiresUnicodeOnAstral = true;
            return '(?!)'; // a lone surrogate can never occur in UTF-8 input
        }

        return sprintf('\x{%x}', $cp);
    }

    /**
     * A character class. Negated shorthands (\D \W \S) cannot nest in a PCRE
     * class, so a class containing them becomes an alternation/lookahead.
     */
    private function charClass(): string
    {
        $this->requiresUnicodeOnAstral = true;
        $negated = ($this->src[$this->pos] ?? '') === '^';
        if ($negated) {
            $this->pos++;
        }
        $body = '';
        $complements = [];
        $len = strlen($this->src);
        while ($this->pos < $len && $this->src[$this->pos] !== ']') {
            $c = $this->src[$this->pos++];
            if ($c === '\\') {
                $frag = $this->escape(true);
                if (str_starts_with($frag, "\0")) {
                    $complements[] = ['D' => '0-9', 'W' => self::WORD, 'S' => Js::WS][$frag[1]];
                    continue;
                }
                $body .= $frag;
            } elseif ($c === '[' || $c === '/') {
                $body .= '\\' . $c;
            } else {
                $body .= $c;
            }
        }
        if ($this->pos >= $len) {
            throw new JsError("Invalid regular expression: /{$this->src}/: Unterminated character class", 'SyntaxError');
        }
        $this->pos++; // ']'

        if ($complements === []) {
            if ($body === '') {
                return $negated ? '(?s:.)' : '(?!)';
            }

            return '[' . ($negated ? '^' : '') . $body . ']';
        }
        if (!$negated) {
            $alts = $body === '' ? [] : ['[' . $body . ']'];
            foreach ($complements as $set) {
                $alts[] = '[^' . $set . ']';
            }

            return '(?:' . implode('|', $alts) . ')';
        }
        $out = $body === '' ? '' : '(?![' . $body . '])';
        foreach ($complements as $set) {
            $out .= '(?=[' . $set . '])';
        }

        return '(?:' . $out . '(?s:.))';
    }
}
