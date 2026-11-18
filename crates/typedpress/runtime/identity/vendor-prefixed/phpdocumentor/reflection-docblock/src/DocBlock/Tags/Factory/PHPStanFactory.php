<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use TypedPress\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use TypedPress\Identity\Vendor\phpDocumentor\Reflection\Types\Context;
use TypedPress\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;

interface PHPStanFactory
{
    public function create(PhpDocTagNode $node, Context $context): Tag;

    public function supports(PhpDocTagNode $node, Context $context): bool;
}
