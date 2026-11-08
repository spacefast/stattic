<?php
declare(strict_types=1);
namespace Zero;

final class JsArguments
{
    public array $p = [];
    public mixed $length;
    /** `delete arguments.length` removed the own property; a later write restores it. */
    public bool $lengthDeleted = false;

    public function __construct(public array $a) { $this->length = count($a); }
}
