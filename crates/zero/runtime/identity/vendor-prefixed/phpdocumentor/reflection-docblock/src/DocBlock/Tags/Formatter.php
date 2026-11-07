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

namespace Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags;

use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tag;

interface Formatter
{
    /**
     * Formats a tag into a string representation according to a specific format, such as Markdown.
     */
    public function format(Tag $tag): string;
}
