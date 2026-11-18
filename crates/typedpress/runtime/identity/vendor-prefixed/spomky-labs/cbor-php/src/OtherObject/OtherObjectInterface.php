<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\CBOR\OtherObject;

use TypedPress\Identity\Vendor\CBOR\CBORObject;

interface OtherObjectInterface extends CBORObject
{
    /**
     * @return int[]
     */
    public static function supportedAdditionalInformation(): array;

    public static function createFromLoadedData(int $additionalInformation, ?string $data): self;
}
