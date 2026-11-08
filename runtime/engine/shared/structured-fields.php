<?php
declare(strict_types=1);

/**
 * RFC 9651 dictionary parser and serializer. Invalid members are discarded at
 * the policy boundary; only parsed values can reach the serializer.
 */
final class StatticStructuredFields
{
    private int $offset = 0;

    public function __construct(private readonly string $input) {}

    /** @return array<string,string> Valid dictionary members, serialized. */
    public function dictionaryMembers(): array
    {
        $members = [];
        while ($this->offset < strlen($this->input)) {
            $start = $this->offset;
            try {
                $this->spaces(" \t");
                $key = $this->key();
                if ($this->take('=')) {
                    $member = $this->peek() === '(' ? $this->innerList() : $this->item();
                } else {
                    $member = ['bare' => ['type' => 'boolean', 'value' => true], 'parameters' => $this->parameters()];
                }
                $this->spaces(" \t");
                if ($this->offset < strlen($this->input) && $this->peek() !== ',') {
                    throw new UnexpectedValueException('Invalid dictionary delimiter');
                }
                $members[$key] = $key . self::serializeMember($member, true);
            } catch (UnexpectedValueException) {
                // Recovery is deliberately outside the RFC parser. Even if a
                // comma belonged to a broken string, every recovered member
                // must parse independently and is serialized from its value.
                $comma = strpos($this->input, ',', $start);
                if ($comma === false) {
                    break;
                }
                $this->offset = $comma;
            }
            $this->take(',');
        }
        return $members;
    }

    private function peek(): string
    {
        return $this->input[$this->offset] ?? '';
    }

    private function take(string $character): bool
    {
        if ($this->peek() !== $character) {
            return false;
        }
        $this->offset++;
        return true;
    }

    private function spaces(string $characters = ' '): void
    {
        $this->offset += strspn($this->input, $characters, $this->offset);
    }

    private function match(string $pattern): string
    {
        if (preg_match($pattern, $this->input, $match, 0, $this->offset) !== 1) {
            throw new UnexpectedValueException('Invalid structured field');
        }
        $this->offset += strlen($match[0]);
        return $match[0];
    }

    private function key(): string
    {
        return $this->match('/\G[a-z*][a-z0-9_.*-]*/');
    }

    private function parameters(): array
    {
        $parameters = [];
        while ($this->take(';')) {
            $this->spaces();
            $key = $this->key();
            $parameters[$key] = $this->take('=')
                ? $this->bareItem()
                : ['type' => 'boolean', 'value' => true];
        }
        return $parameters;
    }

    private function item(): array
    {
        return ['bare' => $this->bareItem(), 'parameters' => $this->parameters()];
    }

    private function innerList(): array
    {
        $this->take('(');
        $items = [];
        while (true) {
            $this->spaces();
            if ($this->take(')')) {
                return ['items' => $items, 'parameters' => $this->parameters()];
            }
            $items[] = $this->item();
            if ($this->peek() !== ' ' && $this->peek() !== ')') {
                throw new UnexpectedValueException('Invalid inner list delimiter');
            }
        }
    }

    private function bareItem(): array
    {
        $first = $this->peek();
        if ($first === '"' || $first === '%') {
            $display = $this->take('%');
            if (!$this->take('"')) {
                throw new UnexpectedValueException('Invalid string');
            }
            $value = '';
            while ($this->offset < strlen($this->input)) {
                $character = $this->input[$this->offset++];
                if ($character === '"') {
                    if ($display && preg_match('//u', $value) !== 1) {
                        throw new UnexpectedValueException('Invalid display string UTF-8');
                    }
                    return ['type' => $display ? 'display' : 'string', 'value' => $value];
                }
                if (ord($character) < 0x20 || ord($character) > 0x7e) {
                    throw new UnexpectedValueException('Invalid string character');
                }
                if ($display && $character === '%') {
                    $hex = $this->match('/\G[0-9a-f]{2}/');
                    $character = chr(hexdec($hex));
                } elseif (!$display && $character === '\\') {
                    $character = $this->peek();
                    if ($character !== '"' && $character !== '\\') {
                        throw new UnexpectedValueException('Invalid string escape');
                    }
                    $this->offset++;
                }
                $value .= $character;
            }
            throw new UnexpectedValueException('Unterminated string');
        }
        if ($this->take(':')) {
            $encoded = $this->match('/\G[A-Za-z0-9+\/=]*:/');
            $value = base64_decode(substr($encoded, 0, -1), true);
            if ($value === false) {
                throw new UnexpectedValueException('Invalid byte sequence');
            }
            return ['type' => 'bytes', 'value' => $value];
        }
        if ($this->take('?')) {
            return ['type' => 'boolean', 'value' => $this->match('/\G[01]/') === '1'];
        }
        if ($first === '@' || $first === '-' || ($first !== '' && ctype_digit($first))) {
            $date = $this->take('@');
            $number = $this->match('/\G-?[0-9]+(?:\.[0-9]+)?/');
            $unsigned = ltrim($number, '-');
            $parts = explode('.', $unsigned);
            if (count($parts) === 2) {
                if ($date || strlen($parts[0]) > 12 || strlen($parts[1]) > 3) {
                    throw new UnexpectedValueException('Invalid decimal');
                }
                $value = (int) $parts[0] * 1000 + (int) str_pad($parts[1], 3, '0');
                return ['type' => 'decimal', 'value' => str_starts_with($number, '-') ? -$value : $value];
            }
            if (strlen($unsigned) > 15) {
                throw new UnexpectedValueException('Invalid integer');
            }
            return ['type' => $date ? 'date' : 'integer', 'value' => (int) $number];
        }
        return ['type' => 'token', 'value' => $this->match("/\\G[a-zA-Z*][a-zA-Z0-9!#$%&'*+.^_`|:\/~\\-]*/")];
    }

    private static function serializeMember(array $member, bool $dictionary = false): string
    {
        if (isset($member['items'])) {
            $value = '(' . implode(' ', array_map(self::serializeMember(...), $member['items'])) . ')';
        } else {
            $bare = $member['bare'];
            $value = $dictionary && $bare['type'] === 'boolean' && $bare['value'] === true
                ? '' : self::serializeBareItem($bare);
        }
        if ($dictionary && $value !== '') {
            $value = '=' . $value;
        }
        foreach ($member['parameters'] as $key => $parameter) {
            $value .= ';' . $key;
            if ($parameter['type'] !== 'boolean' || $parameter['value'] !== true) {
                $value .= '=' . self::serializeBareItem($parameter);
            }
        }
        return $value;
    }

    private static function serializeBareItem(array $item): string
    {
        $value = $item['value'];
        return match ($item['type']) {
            'boolean' => $value ? '?1' : '?0',
            'integer', 'token' => (string) $value,
            'date' => '@' . $value,
            'decimal' => ($value < 0 ? '-' : '') . intdiv(abs($value), 1000) . '.'
                . (rtrim(str_pad((string) (abs($value) % 1000), 3, '0', STR_PAD_LEFT), '0') ?: '0'),
            'string' => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"',
            'bytes' => ':' . base64_encode($value) . ':',
            'display' => '%"' . preg_replace_callback('/[\x00-\x1f\x7f-\xff"%]/',
                static fn (array $match): string => '%' . bin2hex($match[0]), $value) . '"',
        };
    }
}
