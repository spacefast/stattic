<?php
/*
 * This file is part of phpDocumentor.
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 *
 *  @link      http://phpdoc.org
 *
 */

declare(strict_types=1);

namespace Zero\Identity\Vendor\phpDocumentor\Reflection\PseudoTypes;

use Zero\Identity\Vendor\phpDocumentor\Reflection\PseudoType;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Type;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Types\Integer;

use function implode;

/**
 * Value Object representing the `int-mask` type.
 *
 * @psalm-immutable
 */
final class IntMask extends Integer implements PseudoType
{
    /** @var Type[] */
    private $types;

    public function __construct(Type ...$types)
    {
        $this->types = $types;
    }

    /**
     * @return Type[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    public function underlyingType(): Type
    {
        return new Integer();
    }

    public function __toString(): string
    {
        return 'int-mask<' . implode(', ', $this->types) . '>';
    }
}
