<?php
declare(strict_types=1);
namespace Zero;
final class JsArray
{
    public array $p = [];

    public function __construct(public array $a = []) {}
}
