<?php
declare(strict_types=1);
namespace SpacePress;

final class JsDate
{
    public function __construct(public int|float $milliseconds) {}
}
