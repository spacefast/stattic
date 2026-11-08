<?php
declare(strict_types=1);
namespace Zero;

/** The opaque handle checked JavaScript gets from setTimeout, as Node's Timeout object. */
final class JsTimeout
{
    public function __construct(public readonly int $id) {}
}
