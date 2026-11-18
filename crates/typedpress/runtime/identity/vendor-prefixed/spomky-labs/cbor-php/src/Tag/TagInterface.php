<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\CBOR\Tag;

use TypedPress\Identity\Vendor\CBOR\CBORObject;

interface TagInterface extends CBORObject
{
    public static function getTagId(): int;

    public function getValue(): CBORObject;

    public static function createFromLoadedData(
        int $additionalInformation,
        ?string $data,
        CBORObject $object
    ): self;
}
