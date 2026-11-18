<?php
declare(strict_types=1);
namespace TypedPress;

/** A built-in constructor used as a value (`typeof Blob`, `x instanceof Uint8Array`, `const C = File`). */
final class JsConstructor
{
    private static array $all = [];

    private function __construct(public readonly string $name) {}

    public static function of(string $name): self { return self::$all[$name] ??= new self($name); }
}
