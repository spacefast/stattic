<?php
declare(strict_types=1);
namespace Zero;

final class JsArguments
{
    public array $p = [];
    public mixed $length;

    public function __construct(public array $a) { $this->length = count($a); }
}
