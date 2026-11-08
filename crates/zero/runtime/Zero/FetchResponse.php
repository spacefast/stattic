<?php

declare(strict_types=1);

namespace Zero;

/**
 * What `await fetch(...)` returns: the WHATWG Response fields a handler reads. The body was read
 * in full before the call returned; `text()` and `json()` may consume it once, as in fetch.
 */
final class FetchResponse implements \JsonSerializable, \Stringable
{
    public readonly bool $ok;
    private bool $used = false;

    public function __construct(
        public readonly int $status,
        public readonly string $statusText,
        public readonly string $url,
        public readonly bool $redirected,
        public readonly FetchHeaders $headers,
        private readonly string $body,
    ) {
        $this->ok = $status >= 200 && $status <= 299;
    }

    public function bodyUsed(): bool
    {
        return $this->used;
    }

    public function text(): string
    {
        if ($this->used) {
            throw new JsError('Body is unusable: Body has already been read', 'TypeError');
        }
        $this->used = true;

        return $this->body;
    }

    public function json(): mixed
    {
        return Js::jsonParse($this->text());
    }

    public function jsonSerialize(): \stdClass
    {
        return new \stdClass();
    }

    public function __toString(): string
    {
        return '[object Response]';
    }
}
