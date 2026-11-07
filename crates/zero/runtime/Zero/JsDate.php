<?php
declare(strict_types=1);
namespace Zero;

final class JsDate
{
    public function __construct(public int|float $milliseconds) {}
}
