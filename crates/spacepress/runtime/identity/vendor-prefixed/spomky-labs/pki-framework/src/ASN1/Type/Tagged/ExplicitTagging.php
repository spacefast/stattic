<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\SpomkyLabs\Pki\ASN1\Type\Tagged;

use SpacePress\Identity\Vendor\SpomkyLabs\Pki\ASN1\Feature\ElementBase;
use SpacePress\Identity\Vendor\SpomkyLabs\Pki\ASN1\Type\UnspecifiedType;

/**
 * Interface for classes providing explicit tagging.
 */
interface ExplicitTagging extends ElementBase
{
    /**
     * Get explicitly tagged wrapped element.
     */
    public function explicit(): UnspecifiedType;
}
