<?php
declare(strict_types=1);
namespace Zero;
final class JsObject
{
    public bool $nullPrototype = false;

    public function __construct(public array $p = [], public readonly ?JsClass $class = null, bool $nullPrototype = false)
    {
        $this->nullPrototype = $nullPrototype;
    }
}
