<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\SpomkyLabs\Pki\ASN1\Type;

use TypedPress\Identity\Vendor\SpomkyLabs\Pki\ASN1\Feature\ElementBase;
use TypedPress\Identity\Vendor\SpomkyLabs\Pki\ASN1\Feature\Stringable;

/**
 * Interface to mark types that correspond to ASN.1 specification's character strings. That being all simple strings and
 * time types.
 */
interface StringType extends ElementBase, Stringable
{
}
