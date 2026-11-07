<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Cose\Algorithm;

interface Algorithm
{
    public static function identifier(): int;
}
