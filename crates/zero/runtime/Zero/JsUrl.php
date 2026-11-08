<?php
declare(strict_types=1);
namespace Zero;

use Zero\Vendor\Rowbot\URL\URL as WhatwgUrl;

/**
 * WHATWG `URL`, backed by the bundled rowbot/url (MIT), which implements the URL Standard parser.
 * See docs/decisions/2026-11-07-stdlib-objects.md. Parse failures are JavaScript TypeErrors.
 */
final class JsUrl implements \Stringable
{
    public const PROPERTIES = ['href', 'origin', 'protocol', 'username', 'password', 'host', 'hostname', 'port', 'pathname', 'search', 'hash'];
    public const WRITABLE = ['href', 'protocol', 'username', 'password', 'host', 'hostname', 'port', 'pathname', 'search', 'hash'];

    private ?JsUrlSearchParams $params = null;

    private function __construct(private readonly WhatwgUrl $url) {}

    public static function construct(mixed $url, mixed $base = JsUndefined::Value): self
    {
        try {
            return new self(new WhatwgUrl(self::text($url), $base === JsUndefined::Value ? null : self::text($base)));
        } catch (\Zero\Vendor\Rowbot\URL\Exception\TypeError $e) {
            throw new JsError($e->getMessage(), 'TypeError');
        }
    }

    public static function parse(mixed $url, mixed $base = JsUndefined::Value): self|null
    {
        try { return self::construct($url, $base); } catch (JsError) { return null; }
    }

    public static function canParse(mixed $url, mixed $base = JsUndefined::Value): bool
    {
        return WhatwgUrl::canParse(self::text($url), $base === JsUndefined::Value ? null : self::text($base));
    }

    public function get(string $name): string|JsUrlSearchParams
    {
        if ($name === 'searchParams') { return $this->params ??= JsUrlSearchParams::of($this->url->searchParams); }
        if (!in_array($name, self::PROPERTIES, true)) { throw new JsError('Unsupported URL property ' . $name, 'TypeError'); }
        return $this->url->$name;
    }

    public function set(string $name, mixed $value): void
    {
        if (!in_array($name, self::WRITABLE, true)) { throw new JsError('Cannot assign to URL property ' . $name, 'TypeError'); }
        try {
            $this->url->$name = self::text($value);
        } catch (\Zero\Vendor\Rowbot\URL\Exception\TypeError $e) {
            throw new JsError($e->getMessage(), 'TypeError');
        }
    }

    public function toString(): string { return $this->url->href; }
    public function toJSON(): string { return $this->url->href; }
    public function __toString(): string { return $this->url->href; }

    /** USVString conversion: a JavaScript value to text, lone surrogates already replaced by PHP's UTF-8. */
    private static function text(mixed $value): string
    {
        return $value instanceof self ? $value->url->href : Js::str($value);
    }
}
