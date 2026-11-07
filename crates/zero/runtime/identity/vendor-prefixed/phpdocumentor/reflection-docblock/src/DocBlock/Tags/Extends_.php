<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link http://phpdoc.org
 */

namespace Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags;

use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Description;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Type;

/**
 * Reflection class for a {@}extends tag in a Docblock.
 */
class Extends_ extends TagWithType
{
    public function __construct(Type $type, ?Description $description = null)
    {
        $this->name        = 'extends';
        $this->type        = $type;
        $this->description = $description;
    }
}
