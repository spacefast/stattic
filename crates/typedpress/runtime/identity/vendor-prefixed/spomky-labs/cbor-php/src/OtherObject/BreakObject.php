<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\CBOR\OtherObject;

use TypedPress\Identity\Vendor\CBOR\OtherObject as Base;

final class BreakObject extends Base
{
    public function __construct()
    {
        parent::__construct(self::OBJECT_BREAK, null);
    }

    public static function create(): self
    {
        return new self();
    }

    public static function supportedAdditionalInformation(): array
    {
        return [self::OBJECT_BREAK];
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data): Base
    {
        return new self();
    }
}
