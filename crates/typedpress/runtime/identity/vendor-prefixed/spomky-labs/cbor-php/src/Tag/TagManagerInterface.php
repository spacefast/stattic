<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\CBOR\Tag;

use TypedPress\Identity\Vendor\CBOR\CBORObject;

interface TagManagerInterface
{
    public function createObjectForValue(int $additionalInformation, ?string $data, CBORObject $object): TagInterface;
}
