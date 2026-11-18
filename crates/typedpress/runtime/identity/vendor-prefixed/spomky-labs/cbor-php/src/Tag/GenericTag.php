<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\CBOR\Tag;

use TypedPress\Identity\Vendor\CBOR\CBORObject;
use TypedPress\Identity\Vendor\CBOR\Normalizable;
use TypedPress\Identity\Vendor\CBOR\Tag;

final class GenericTag extends Tag implements Normalizable
{
    public static function getTagId(): int
    {
        return -1;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    /**
     * The tag number carries no semantics known to this library, so the tagged item is normalized on its own. Use
     * getValue() when the tag number matters.
     */
    public function normalize(): mixed
    {
        $object = $this->object;

        return $object instanceof Normalizable ? $object->normalize() : $object;
    }
}
