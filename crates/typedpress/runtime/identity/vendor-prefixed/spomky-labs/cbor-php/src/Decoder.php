<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\CBOR;

use TypedPress\Identity\Vendor\CBOR\OtherObject\BreakObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\DoublePrecisionFloatObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\FalseObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\HalfPrecisionFloatObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\NullObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\OtherObjectManager;
use TypedPress\Identity\Vendor\CBOR\OtherObject\OtherObjectManagerInterface;
use TypedPress\Identity\Vendor\CBOR\OtherObject\SimpleObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\SinglePrecisionFloatObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\TrueObject;
use TypedPress\Identity\Vendor\CBOR\OtherObject\UndefinedObject;
use TypedPress\Identity\Vendor\CBOR\Tag\Base16EncodingTag;
use TypedPress\Identity\Vendor\CBOR\Tag\Base64EncodingTag;
use TypedPress\Identity\Vendor\CBOR\Tag\Base64Tag;
use TypedPress\Identity\Vendor\CBOR\Tag\Base64UrlEncodingTag;
use TypedPress\Identity\Vendor\CBOR\Tag\Base64UrlTag;
use TypedPress\Identity\Vendor\CBOR\Tag\BigFloatTag;
use TypedPress\Identity\Vendor\CBOR\Tag\BinaryMimeTag;
use TypedPress\Identity\Vendor\CBOR\Tag\CBOREncodingTag;
use TypedPress\Identity\Vendor\CBOR\Tag\CBORSequenceTag;
use TypedPress\Identity\Vendor\CBOR\Tag\CBORTag;
use TypedPress\Identity\Vendor\CBOR\Tag\ColumnMajorMultiDimensionalArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\CoseEncrypt0Tag;
use TypedPress\Identity\Vendor\CBOR\Tag\CoseEncryptTag;
use TypedPress\Identity\Vendor\CBOR\Tag\CoseMac0Tag;
use TypedPress\Identity\Vendor\CBOR\Tag\CoseMacTag;
use TypedPress\Identity\Vendor\CBOR\Tag\CoseSign1Tag;
use TypedPress\Identity\Vendor\CBOR\Tag\CoseSignTag;
use TypedPress\Identity\Vendor\CBOR\Tag\CwtTag;
use TypedPress\Identity\Vendor\CBOR\Tag\DateStringTag;
use TypedPress\Identity\Vendor\CBOR\Tag\DateTag;
use TypedPress\Identity\Vendor\CBOR\Tag\DatetimeTag;
use TypedPress\Identity\Vendor\CBOR\Tag\DecimalFractionTag;
use TypedPress\Identity\Vendor\CBOR\Tag\DurationTag;
use TypedPress\Identity\Vendor\CBOR\Tag\ExplicitMapTag;
use TypedPress\Identity\Vendor\CBOR\Tag\ExtendedTimeTag;
use TypedPress\Identity\Vendor\CBOR\Tag\HomogeneousArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\IdentifierTag;
use TypedPress\Identity\Vendor\CBOR\Tag\IpldContentIdentifierTag;
use TypedPress\Identity\Vendor\CBOR\Tag\Ipv4Tag;
use TypedPress\Identity\Vendor\CBOR\Tag\Ipv6Tag;
use TypedPress\Identity\Vendor\CBOR\Tag\LanguageIndependentObjectTag;
use TypedPress\Identity\Vendor\CBOR\Tag\LanguageTaggedStringTag;
use TypedPress\Identity\Vendor\CBOR\Tag\MimeTag;
use TypedPress\Identity\Vendor\CBOR\Tag\NegativeBigIntegerTag;
use TypedPress\Identity\Vendor\CBOR\Tag\NetworkAddressPrefixTag;
use TypedPress\Identity\Vendor\CBOR\Tag\NetworkAddressTag;
use TypedPress\Identity\Vendor\CBOR\Tag\PeriodTag;
use TypedPress\Identity\Vendor\CBOR\Tag\PerlObjectTag;
use TypedPress\Identity\Vendor\CBOR\Tag\RationalNumberTag;
use TypedPress\Identity\Vendor\CBOR\Tag\RegexpTag;
use TypedPress\Identity\Vendor\CBOR\Tag\RowMajorMultiDimensionalArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\SetTag;
use TypedPress\Identity\Vendor\CBOR\Tag\ShareableTag;
use TypedPress\Identity\Vendor\CBOR\Tag\SharedReferenceTag;
use TypedPress\Identity\Vendor\CBOR\Tag\StringReferenceNamespaceTag;
use TypedPress\Identity\Vendor\CBOR\Tag\StringReferenceTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TagInterface;
use TypedPress\Identity\Vendor\CBOR\Tag\TagManager;
use TypedPress\Identity\Vendor\CBOR\Tag\TagManagerInterface;
use TypedPress\Identity\Vendor\CBOR\Tag\TimestampTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float128BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float128LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float16BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float16LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float32BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float32LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float64BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Float64LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Sint16BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Sint16LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Sint32BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Sint32LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Sint64BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Sint64LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Sint8ArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint16BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint16LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint32BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint32LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint64BigEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint64LittleEndianArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint8ArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\TypedArray\Uint8ClampedArrayTag;
use TypedPress\Identity\Vendor\CBOR\Tag\UnsignedBigIntegerTag;
use TypedPress\Identity\Vendor\CBOR\Tag\UriTag;
use TypedPress\Identity\Vendor\CBOR\Tag\UuidTag;
use InvalidArgumentException;
use function ord;
use RuntimeException;
use function sprintf;
use const STR_PAD_LEFT;

final class Decoder implements DecoderInterface
{
    /**
     * The tag classes the decoder knows about, by tag number.
     *
     * Every entry is a class this library implements from the IANA CBOR tags registry. A tag that is not listed
     * here -- unassigned, or specific to an application -- is decoded as a GenericTag, which keeps the number and
     * the item without claiming to understand them.
     *
     * @var array<int, class-string<TagInterface>>
     */
    private const DEFAULT_TAGS = [
        CBORObject::TAG_STANDARD_DATETIME => DatetimeTag::class,
        CBORObject::TAG_EPOCH_DATETIME => TimestampTag::class,
        CBORObject::TAG_UNSIGNED_BIG_NUM => UnsignedBigIntegerTag::class,
        CBORObject::TAG_NEGATIVE_BIG_NUM => NegativeBigIntegerTag::class,
        CBORObject::TAG_DECIMAL_FRACTION => DecimalFractionTag::class,
        CBORObject::TAG_BIG_FLOAT => BigFloatTag::class,
        CBORObject::TAG_COSE_ENCRYPT0 => CoseEncrypt0Tag::class,
        CBORObject::TAG_COSE_MAC0 => CoseMac0Tag::class,
        CBORObject::TAG_COSE_SIGN1 => CoseSign1Tag::class,
        CBORObject::TAG_ENCODED_BASE64_URL => Base64UrlEncodingTag::class,
        CBORObject::TAG_ENCODED_BASE64 => Base64EncodingTag::class,
        CBORObject::TAG_ENCODED_BASE16 => Base16EncodingTag::class,
        CBORObject::TAG_ENCODED_CBOR => CBOREncodingTag::class,
        CBORObject::TAG_STRING_REFERENCE => StringReferenceTag::class,
        CBORObject::TAG_PERL_OBJECT => PerlObjectTag::class,
        CBORObject::TAG_LANGUAGE_INDEPENDENT_OBJECT => LanguageIndependentObjectTag::class,
        CBORObject::TAG_SHAREABLE => ShareableTag::class,
        CBORObject::TAG_SHARED_REFERENCE => SharedReferenceTag::class,
        CBORObject::TAG_RATIONAL_NUMBER => RationalNumberTag::class,
        CBORObject::TAG_URI => UriTag::class,
        CBORObject::TAG_BASE64_URL => Base64UrlTag::class,
        CBORObject::TAG_BASE64 => Base64Tag::class,
        CBORObject::TAG_REGULAR_EXPRESSION => RegexpTag::class,
        CBORObject::TAG_MIME => MimeTag::class,
        CBORObject::TAG_UUID => UuidTag::class,
        CBORObject::TAG_LANGUAGE_TAGGED_STRING => LanguageTaggedStringTag::class,
        CBORObject::TAG_IDENTIFIER => IdentifierTag::class,
        CBORObject::TAG_ROW_MAJOR_MULTI_DIMENSIONAL_ARRAY => RowMajorMultiDimensionalArrayTag::class,
        CBORObject::TAG_HOMOGENEOUS_ARRAY => HomogeneousArrayTag::class,
        CBORObject::TAG_IPLD_CONTENT_IDENTIFIER => IpldContentIdentifierTag::class,
        CBORObject::TAG_IPV4 => Ipv4Tag::class,
        CBORObject::TAG_IPV6 => Ipv6Tag::class,
        CBORObject::TAG_CWT => CwtTag::class,
        CBORObject::TAG_ENCODED_CBOR_SEQUENCE => CBORSequenceTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT8 => Uint8ArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT16_BE => Uint16BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT32_BE => Uint32BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT64_BE => Uint64BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT8_CLAMPED => Uint8ClampedArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT16_LE => Uint16LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT32_LE => Uint32LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_UINT64_LE => Uint64LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_SINT8 => Sint8ArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_SINT16_BE => Sint16BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_SINT32_BE => Sint32BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_SINT64_BE => Sint64BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_SINT16_LE => Sint16LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_SINT32_LE => Sint32LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_SINT64_LE => Sint64LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT16_BE => Float16BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT32_BE => Float32BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT64_BE => Float64BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT128_BE => Float128BigEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT16_LE => Float16LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT32_LE => Float32LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT64_LE => Float64LittleEndianArrayTag::class,
        CBORObject::TAG_TYPED_ARRAY_FLOAT128_LE => Float128LittleEndianArrayTag::class,
        CBORObject::TAG_COSE_ENCRYPT => CoseEncryptTag::class,
        CBORObject::TAG_COSE_MAC => CoseMacTag::class,
        CBORObject::TAG_COSE_SIGN => CoseSignTag::class,
        CBORObject::TAG_DATE => DateTag::class,
        CBORObject::TAG_STRING_REFERENCE_NAMESPACE => StringReferenceNamespaceTag::class,
        CBORObject::TAG_BINARY_MIME => BinaryMimeTag::class,
        CBORObject::TAG_SET => SetTag::class,
        CBORObject::TAG_EXPLICIT_MAP => ExplicitMapTag::class,
        CBORObject::TAG_NETWORK_ADDRESS => NetworkAddressTag::class,
        CBORObject::TAG_NETWORK_ADDRESS_PREFIX => NetworkAddressPrefixTag::class,
        CBORObject::TAG_EXTENDED_TIME => ExtendedTimeTag::class,
        CBORObject::TAG_DURATION => DurationTag::class,
        CBORObject::TAG_PERIOD => PeriodTag::class,
        CBORObject::TAG_DATE_STRING => DateStringTag::class,
        CBORObject::TAG_COLUMN_MAJOR_MULTI_DIMENSIONAL_ARRAY => ColumnMajorMultiDimensionalArrayTag::class,
        CBORObject::TAG_CBOR => CBORTag::class,
    ];

    /**
     * The maximum nesting depth allowed by default. Deeper structures are rejected as the recursive processing of the
     * data may exhaust the call stack.
     */
    public const DEFAULT_MAX_DEPTH = 1000;

    private TagManagerInterface $tagObjectManager;

    private OtherObjectManagerInterface $otherTypeManager;

    private int $maxDepth;

    public function __construct(
        ?TagManagerInterface $tagObjectManager = null,
        ?OtherObjectManagerInterface $otherTypeManager = null,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) {
        if ($maxDepth < 1) {
            throw new InvalidArgumentException(sprintf(
                'The maximum nesting depth shall be at least 1. Got %d.',
                $maxDepth
            ));
        }
        $this->tagObjectManager = $tagObjectManager ?? $this->generateTagManager();
        $this->otherTypeManager = $otherTypeManager ?? $this->generateOtherObjectManager();
        $this->maxDepth = $maxDepth;
    }

    public static function create(
        ?TagManagerInterface $tagObjectManager = null,
        ?OtherObjectManagerInterface $otherTypeManager = null,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ): self {
        return new self($tagObjectManager, $otherTypeManager, $maxDepth);
    }

    public function decode(Stream $stream): CBORObject
    {
        return $this->process($stream, false, 0);
    }

    private function process(Stream $stream, bool $breakable, int $depth): CBORObject
    {
        if ($depth > $this->maxDepth) {
            throw new InvalidArgumentException(sprintf(
                'Cannot parse the data. Maximum nesting depth of %d exceeded.',
                $this->maxDepth
            ));
        }
        $ib = ord($stream->read(1));
        $mt = $ib >> 5;
        $ai = $ib & 0b00011111;
        $val = null;
        switch ($ai) {
            case CBORObject::LENGTH_1_BYTE: // 24
            case CBORObject::LENGTH_2_BYTES: // 25
            case CBORObject::LENGTH_4_BYTES: // 26
            case CBORObject::LENGTH_8_BYTES: // 27
                // 24..27 carry 1, 2, 4 and 8 bytes of argument; a table beats a float exponentiation per head.
                $val = $stream->read(match ($ai) {
                    CBORObject::LENGTH_1_BYTE => 1,
                    CBORObject::LENGTH_2_BYTES => 2,
                    CBORObject::LENGTH_4_BYTES => 4,
                    default => 8,
                });
                break;
            case CBORObject::FUTURE_USE_1: // 28
            case CBORObject::FUTURE_USE_2: // 29
            case CBORObject::FUTURE_USE_3: // 30
                throw new InvalidArgumentException(sprintf(
                    'Cannot parse the data. Found invalid Additional Information "%s" (%d).',
                    str_pad(decbin($ai), 8, '0', STR_PAD_LEFT),
                    $ai
                ));
            case CBORObject::LENGTH_INDEFINITE: // 31
                return $this->processInfinite($stream, $mt, $breakable, $depth);
        }

        return $this->processFinite($stream, $mt, $ai, $val, $depth);
    }

    private function processFinite(Stream $stream, int $mt, int $ai, ?string $val, int $depth): CBORObject
    {
        switch ($mt) {
            case CBORObject::MAJOR_TYPE_UNSIGNED_INTEGER: // 0
                return UnsignedIntegerObject::createObjectForValue($ai, $val);
            case CBORObject::MAJOR_TYPE_NEGATIVE_INTEGER: // 1
                return NegativeIntegerObject::createObjectForValue($ai, $val);
            case CBORObject::MAJOR_TYPE_BYTE_STRING: // 2
                $length = $val === null ? $ai : Utils::binToInt($val);

                return ByteStringObject::create($stream->read($length));
            case CBORObject::MAJOR_TYPE_TEXT_STRING: // 3
                $length = $val === null ? $ai : Utils::binToInt($val);

                return TextStringObject::create($stream->read($length));
            case CBORObject::MAJOR_TYPE_LIST: // 4
                $object = ListObject::create();
                $nbItems = $val === null ? $ai : Utils::binToInt($val);
                for ($i = 0; $i < $nbItems; ++$i) {
                    $object->add($this->process($stream, false, $depth + 1));
                }

                return $object;
            case CBORObject::MAJOR_TYPE_MAP: // 5
                $object = MapObject::create();
                $nbItems = $val === null ? $ai : Utils::binToInt($val);
                for ($i = 0; $i < $nbItems; ++$i) {
                    $object->add(
                        $this->process($stream, false, $depth + 1),
                        $this->process($stream, false, $depth + 1)
                    );
                }

                return $object;
            case CBORObject::MAJOR_TYPE_TAG: // 6
                return $this->tagObjectManager->createObjectForValue(
                    $ai,
                    $val,
                    $this->process($stream, false, $depth + 1)
                );
            case CBORObject::MAJOR_TYPE_OTHER_TYPE: // 7
                return $this->otherTypeManager->createObjectForValue($ai, $val);
            default:
                throw new RuntimeException(sprintf(
                    'Unsupported major type "%s" (%d).',
                    str_pad(decbin($mt), 5, '0', STR_PAD_LEFT),
                    $mt
                )); // Should never append
        }
    }

    private function processInfinite(Stream $stream, int $mt, bool $breakable, int $depth): CBORObject
    {
        switch ($mt) {
            case CBORObject::MAJOR_TYPE_BYTE_STRING: // 2
                $object = IndefiniteLengthByteStringObject::create();
                while (! ($it = $this->process($stream, true, $depth + 1)) instanceof BreakObject) {
                    if (! $it instanceof ByteStringObject) {
                        throw new InvalidArgumentException(
                            'Unable to parse the data. Infinite Byte String object can only get Byte String objects.'
                        );
                    }
                    $object->add($it);
                }

                return $object;
            case CBORObject::MAJOR_TYPE_TEXT_STRING: // 3
                $object = IndefiniteLengthTextStringObject::create();
                while (! ($it = $this->process($stream, true, $depth + 1)) instanceof BreakObject) {
                    if (! $it instanceof TextStringObject) {
                        throw new InvalidArgumentException(
                            'Unable to parse the data. Infinite Text String object can only get Text String objects.'
                        );
                    }
                    $object->add($it);
                }

                return $object;
            case CBORObject::MAJOR_TYPE_LIST: // 4
                $object = IndefiniteLengthListObject::create();
                $it = $this->process($stream, true, $depth + 1);
                while (! $it instanceof BreakObject) {
                    $object->add($it);
                    $it = $this->process($stream, true, $depth + 1);
                }

                return $object;
            case CBORObject::MAJOR_TYPE_MAP: // 5
                $object = IndefiniteLengthMapObject::create();
                while (! ($it = $this->process($stream, true, $depth + 1)) instanceof BreakObject) {
                    $object->add($it, $this->process($stream, false, $depth + 1));
                }

                return $object;
            case CBORObject::MAJOR_TYPE_OTHER_TYPE: // 7
                if (! $breakable) {
                    throw new InvalidArgumentException('Cannot parse the data. No enclosing indefinite.');
                }

                return BreakObject::create();
            case CBORObject::MAJOR_TYPE_UNSIGNED_INTEGER: // 0
            case CBORObject::MAJOR_TYPE_NEGATIVE_INTEGER: // 1
            case CBORObject::MAJOR_TYPE_TAG: // 6
            default:
                throw new InvalidArgumentException(sprintf(
                    'Cannot parse the data. Found infinite length for Major Type "%s" (%d).',
                    str_pad(decbin($mt), 5, '0', STR_PAD_LEFT),
                    $mt
                ));
        }
    }

    private function generateTagManager(): TagManagerInterface
    {
        $manager = TagManager::create();
        foreach (self::DEFAULT_TAGS as $tagId => $class) {
            $manager->register($tagId, $class);
        }

        return $manager;
    }

    private function generateOtherObjectManager(): OtherObjectManagerInterface
    {
        return OtherObjectManager::create()
            ->add(BreakObject::class)
            ->add(SimpleObject::class)
            ->add(FalseObject::class)
            ->add(TrueObject::class)
            ->add(NullObject::class)
            ->add(UndefinedObject::class)
            ->add(HalfPrecisionFloatObject::class)
            ->add(SinglePrecisionFloatObject::class)
            ->add(DoublePrecisionFloatObject::class)
        ;
    }
}
