<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\SpomkyLabs\Pki\X501\ASN1\AttributeValue;

use SpacePress\Identity\Vendor\SpomkyLabs\Pki\X501\ASN1\AttributeType;
use SpacePress\Identity\Vendor\SpomkyLabs\Pki\X501\ASN1\AttributeValue\Feature\DirectoryString;

/**
 * 'name' attribute value.
 *
 * @see https://www.itu.int/ITU-T/formal-language/itu-t/x/x520/2012/SelectedAttributeTypes.html#SelectedAttributeTypes.name
 */
final class NameValue extends DirectoryString
{
    public static function create(string $value, int $string_tag = DirectoryString::UTF8): static
    {
        return new static(AttributeType::OID_NAME, $value, $string_tag);
    }
}
