<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TypedPress\Identity\Vendor\Symfony\Component\Serializer\Annotation;

class_exists(\TypedPress\Identity\Vendor\Symfony\Component\Serializer\Attribute\MaxDepth::class);

if (false) {
    /**
     * @deprecated since Symfony 7.4, use {@see \TypedPress\Identity\Vendor\Symfony\Component\Serializer\Attribute\MaxDepth} instead
     */
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY)]
    class MaxDepth extends \TypedPress\Identity\Vendor\Symfony\Component\Serializer\Attribute\MaxDepth
    {
    }
}
