<?php
declare(strict_types=1);
namespace Zero;
final class JsObject
{
    public function __construct(public array $p = [], public readonly ?JsClass $class = null) {}
}
