<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zero\Vendor\Symfony\Contracts\Service;

use Zero\Vendor\Psr\Container\ContainerInterface;

/**
 * Implemented by objects that expose a service container.
 */
interface ContainerProviderInterface
{
    public function getContainer(): ContainerInterface;
}
