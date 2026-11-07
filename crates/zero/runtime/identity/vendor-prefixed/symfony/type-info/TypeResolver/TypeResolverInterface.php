<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zero\Identity\Vendor\Symfony\Component\TypeInfo\TypeResolver;

use Zero\Identity\Vendor\Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Zero\Identity\Vendor\Symfony\Component\TypeInfo\Type;
use Zero\Identity\Vendor\Symfony\Component\TypeInfo\TypeContext\TypeContext;

/**
 * Resolves type for a given subject.
 *
 * @author Mathias Arlaud <mathias.arlaud@gmail.com>
 * @author Baptiste Leduc <baptiste.leduc@gmail.com>
 */
interface TypeResolverInterface
{
    /**
     * Try to resolve a {@see Type} on a $subject.
     * If the resolver cannot resolve the type, it will throw a {@see UnsupportedException}.
     *
     * @throws UnsupportedException
     */
    public function resolve(mixed $subject, ?TypeContext $typeContext = null): Type;
}
