<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\CBOR\Tag;

use Zero\Identity\Vendor\CBOR\CBORObject;
use Zero\Identity\Vendor\CBOR\IndefiniteLengthTextStringObject;
use Zero\Identity\Vendor\CBOR\Tag;
use Zero\Identity\Vendor\CBOR\TextStringObject;
use InvalidArgumentException;

final class Base64UrlTag extends Tag
{
    public function __construct(int $additionalInformation, ?string $data, CBORObject $object)
    {
        if (! $object instanceof TextStringObject && ! $object instanceof IndefiniteLengthTextStringObject) {
            throw new InvalidArgumentException('This tag only accepts a Text String object.');
        }

        parent::__construct($additionalInformation, $data, $object);
    }

    public static function getTagId(): int
    {
        return self::TAG_BASE64_URL;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_BASE64_URL);

        return new self($ai, $data, $object);
    }
}
