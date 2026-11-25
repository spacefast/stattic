<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\CBOR\Tag;

use SpacePress\Identity\Vendor\CBOR\ByteStringObject;
use SpacePress\Identity\Vendor\CBOR\CBORObject;
use SpacePress\Identity\Vendor\CBOR\IndefiniteLengthByteStringObject;
use SpacePress\Identity\Vendor\CBOR\Tag;
use InvalidArgumentException;

final class CBOREncodingTag extends Tag
{
    public function __construct(int $additionalInformation, ?string $data, CBORObject $object)
    {
        if (! $object instanceof ByteStringObject && ! $object instanceof IndefiniteLengthByteStringObject) {
            throw new InvalidArgumentException('This tag only accepts a Byte String object.');
        }

        parent::__construct($additionalInformation, $data, $object);
    }

    public static function getTagId(): int
    {
        return self::TAG_ENCODED_CBOR;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_ENCODED_CBOR);

        return new self($ai, $data, $object);
    }
}
