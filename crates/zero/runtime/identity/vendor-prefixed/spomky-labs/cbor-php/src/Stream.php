<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\CBOR;

interface Stream
{
    public function read(int $length): string;
}
