<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link      http://phpdoc.org
 */

namespace SpacePress\Identity\Vendor\phpDocumentor\Reflection\PseudoTypes;

use SpacePress\Identity\Vendor\phpDocumentor\Reflection\PseudoType;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\Type;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\Types\Never_;

/**
 * Value Object representing the type 'never-return'.
 *
 * @psalm-immutable
 */
final class NeverReturn extends Never_ implements PseudoType
{
    public function underlyingType(): Type
    {
        return new Never_();
    }

    public function __toString(): string
    {
        return 'never-return';
    }
}
