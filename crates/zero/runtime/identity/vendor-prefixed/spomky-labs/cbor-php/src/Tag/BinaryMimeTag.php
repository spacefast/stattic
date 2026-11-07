<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\CBOR\Tag;

use Zero\Identity\Vendor\CBOR\ByteStringObject;
use Zero\Identity\Vendor\CBOR\CBORObject;
use Zero\Identity\Vendor\CBOR\IndefiniteLengthByteStringObject;
use Zero\Identity\Vendor\CBOR\Normalizable;
use Zero\Identity\Vendor\CBOR\Tag;
use InvalidArgumentException;

/**
 * Tag 257: a MIME message, headers included, carried as a byte string.
 *
 * It is the counterpart of tag 36 for a message that is not valid UTF-8 -- a transfer encoding of binary, say --
 * which a text string could not hold.
 *
 * @see \CBOR\Test\Tag\RegistryTagsTest
 */
final class BinaryMimeTag extends Tag implements Normalizable
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
        return self::TAG_BINARY_MIME;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): self
    {
        [$ai, $data] = self::determineComponents(self::TAG_BINARY_MIME);

        return new self($ai, $data, $object);
    }

    public function normalize(): string
    {
        /** @var ByteStringObject|IndefiniteLengthByteStringObject $object */
        $object = $this->object;

        return $object->normalize();
    }
}
