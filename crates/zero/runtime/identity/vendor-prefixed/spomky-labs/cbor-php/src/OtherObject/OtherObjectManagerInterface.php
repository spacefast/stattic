<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\CBOR\OtherObject;

interface OtherObjectManagerInterface
{
    public function createObjectForValue(int $value, ?string $data): OtherObjectInterface;
}
