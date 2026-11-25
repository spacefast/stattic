<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\CBOR\Tag;

use SpacePress\Identity\Vendor\CBOR\CBORObject;
use SpacePress\Identity\Vendor\CBOR\IndefiniteLengthTextStringObject;
use SpacePress\Identity\Vendor\CBOR\Normalizable;
use SpacePress\Identity\Vendor\CBOR\Tag;
use SpacePress\Identity\Vendor\CBOR\TextStringObject;
use InvalidArgumentException;

/**
 * @see \CBOR\Test\Tag\MimeTagTest
 */
final class MimeTag extends Tag implements Normalizable
{
    public function __construct(int $additionalInformation, ?string $data, CBORObject $object)
    {
        if (! $object instanceof TextStringObject && ! $object instanceof IndefiniteLengthTextStringObject) {
            throw new InvalidArgumentException('This tag only accepts a Byte String object.');
        }

        parent::__construct($additionalInformation, $data, $object);
    }

    public static function getTagId(): int
    {
        return self::TAG_MIME;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_MIME);

        return new self($ai, $data, $object);
    }

    public function normalize(): string
    {
        /** @var TextStringObject|IndefiniteLengthTextStringObject $object */
        $object = $this->object;

        return $object->normalize();
    }
}
