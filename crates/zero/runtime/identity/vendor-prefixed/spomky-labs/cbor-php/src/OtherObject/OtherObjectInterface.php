<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\CBOR\OtherObject;

use Zero\Identity\Vendor\CBOR\CBORObject;

interface OtherObjectInterface extends CBORObject
{
    /**
     * @return int[]
     */
    public static function supportedAdditionalInformation(): array;

    public static function createFromLoadedData(int $additionalInformation, ?string $data): self;
}
