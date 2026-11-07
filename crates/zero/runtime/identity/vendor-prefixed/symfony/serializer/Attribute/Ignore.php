<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zero\Identity\Vendor\Symfony\Component\Serializer\Attribute;

/**
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY)]
class Ignore
{
}

if (!class_exists(\Zero\Identity\Vendor\Symfony\Component\Serializer\Annotation\Ignore::class, false)) {
    class_alias(Ignore::class, \Zero\Identity\Vendor\Symfony\Component\Serializer\Annotation\Ignore::class);
}
