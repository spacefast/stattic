<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\CBOR\Tag;

use SpacePress\Identity\Vendor\CBOR\CBORObject;
use SpacePress\Identity\Vendor\CBOR\Tag;

final class Base16EncodingTag extends Tag
{
    public static function getTagId(): int
    {
        return self::TAG_ENCODED_BASE16;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_ENCODED_BASE16);

        return new self($ai, $data, $object);
    }
}
