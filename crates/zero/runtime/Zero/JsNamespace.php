<?php
declare(strict_types=1);
namespace Zero;

/** Identity for standard JavaScript namespace objects. */
final class JsNamespace
{
    public function __construct(public readonly string $name) {}
}
