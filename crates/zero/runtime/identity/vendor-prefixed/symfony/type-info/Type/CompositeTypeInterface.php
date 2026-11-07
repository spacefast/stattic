<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zero\Identity\Vendor\Symfony\Component\TypeInfo\Type;

use Zero\Identity\Vendor\Symfony\Component\TypeInfo\Type;

/**
 * Represents a type composed of several other types.
 *
 * @author Mathias Arlaud <mathias.arlaud@gmail.com>
 *
 * @template T of Type
 */
interface CompositeTypeInterface
{
    /**
     * @return list<T>
     */
    public function getTypes(): array;

    /**
     * @param callable(Type): bool $specification
     */
    public function composedTypesAreSatisfiedBy(callable $specification): bool;
}
