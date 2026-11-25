<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\CBOR\Tag;

use SpacePress\Identity\Vendor\CBOR\CBORObject;

interface TagManagerInterface
{
    public function createObjectForValue(int $additionalInformation, ?string $data, CBORObject $object): TagInterface;
}
