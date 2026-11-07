<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zero\Identity\Vendor\Symfony\Component\Serializer\Annotation;

class_exists(\Zero\Identity\Vendor\Symfony\Component\Serializer\Attribute\DiscriminatorMap::class);

if (false) {
    /**
     * @deprecated since Symfony 7.4, use {@see \Zero\Identity\Vendor\Symfony\Component\Serializer\Attribute\DiscriminatorMap} instead
     */
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class DiscriminatorMap extends \Zero\Identity\Vendor\Symfony\Component\Serializer\Attribute\DiscriminatorMap
    {
    }
}
