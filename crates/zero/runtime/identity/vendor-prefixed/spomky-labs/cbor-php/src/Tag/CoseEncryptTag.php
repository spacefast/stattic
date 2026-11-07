<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\CBOR\Tag;

use Zero\Identity\Vendor\CBOR\ByteStringObject;
use Zero\Identity\Vendor\CBOR\CBORObject;
use Zero\Identity\Vendor\CBOR\IndefiniteLengthByteStringObject;
use Zero\Identity\Vendor\CBOR\IndefiniteLengthListObject;
use Zero\Identity\Vendor\CBOR\ListObject;
use Zero\Identity\Vendor\CBOR\MapObject;
use Zero\Identity\Vendor\CBOR\OtherObject\NullObject;
use Zero\Identity\Vendor\CBOR\Tag;

/**
 * Tag 96: COSE_Encrypt, an encrypted message with one or more recipients, each of which carries the content
 * encryption key in its own way.
 *
 * Content: [protected header, unprotected header, ciphertext, recipients].
 */
final class CoseEncryptTag extends AbstractCoseTag
{
    private const STRUCTURE_NAME = 'CoseEncrypt';

    private const ITEM_COUNT = 4;

    public function __construct(int $additionalInformation, ?string $data, CBORObject $object)
    {
        $structure = self::assertStructure($object, self::ITEM_COUNT, self::STRUCTURE_NAME);
        self::assertPayloadAt($structure, 2, self::STRUCTURE_NAME);
        self::assertListAt($structure, 3, self::STRUCTURE_NAME);

        parent::__construct($additionalInformation, $data, $object);
    }

    public static function getTagId(): int
    {
        return self::TAG_COSE_ENCRYPT;
    }

    public static function createFromLoadedData(int $additionalInformation, ?string $data, CBORObject $object): Tag
    {
        return new self($additionalInformation, $data, $object);
    }

    public static function create(CBORObject $object): self
    {
        [$ai, $data] = self::determineComponents(self::TAG_COSE_ENCRYPT);

        return new self($ai, $data, $object);
    }

    /**
     * Builds the structure from its parts, serializing the protected header into the byte string COSE signs.
     */
    public static function createFromComponents(
        MapObject $protectedHeader,
        MapObject $unprotectedHeader,
        ByteStringObject|NullObject $ciphertext,
        ListObject $recipients
    ): self {
        return self::create(ListObject::create([
            ByteStringObject::create((string) $protectedHeader),
            $unprotectedHeader,
            $ciphertext,
            $recipients,
        ]));
    }

    /**
     * The ciphertext, or null when it is detached and carried out of band.
     */
    public function getCiphertext(): ByteStringObject|IndefiniteLengthByteStringObject|NullObject
    {
        /** @var ByteStringObject|IndefiniteLengthByteStringObject|NullObject $item */
        $item = $this->itemAt(2);

        return $item;
    }

    public function getRecipients(): ListObject|IndefiniteLengthListObject
    {
        /** @var IndefiniteLengthListObject|ListObject $item */
        $item = $this->itemAt(3);

        return $item;
    }
}
