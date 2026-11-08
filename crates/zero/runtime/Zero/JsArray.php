<?php
declare(strict_types=1);
namespace Zero;
final class JsArray
{
    public array $p = [];
    /** A frozen array (a tagged template's strings): every write throws TypeError. */
    public bool $frozen = false;

    public function __construct(public array $a = []) {}
}
