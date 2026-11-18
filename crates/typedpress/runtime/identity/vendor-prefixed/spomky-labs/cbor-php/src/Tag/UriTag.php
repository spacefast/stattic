<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\CBOR\Tag;

use TypedPress\Identity\Vendor\CBOR\CBORObject;
use TypedPress\Identity\Vendor\CBOR\IndefiniteLengthTextStringObject;
use TypedPress\Identity\Vendor\CBOR\Normalizable;
use TypedPress\Identity\Vendor\CBOR\Tag;
use TypedPress\Identity\Vendor\CBOR\TextStringObject;
use InvalidArgumentException;

final class UriTag extends Tag implements Normalizable
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
        return self::TAG_URI;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): Tag
    {
        [$ai, $data] = self::determineComponents(self::TAG_URI);

        return new self($ai, $data, $object);
    }

    public function normalize(): string
    {
        /** @var TextStringObject|IndefiniteLengthTextStringObject $object */
        $object = $this->object;

        return $object->normalize();
    }
}
