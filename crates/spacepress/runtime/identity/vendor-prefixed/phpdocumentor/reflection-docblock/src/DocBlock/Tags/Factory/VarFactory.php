<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use SpacePress\Identity\Vendor\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Var_;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\TypeResolver;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\Types\Context;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use SpacePress\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use SpacePress\Identity\Vendor\Webmozart\Assert\Assert;

use function is_string;
use function trim;

/**
 * @internal This class is not part of the BC promise of this library.
 */
final class VarFactory implements PHPStanFactory
{
    private DescriptionFactory $descriptionFactory;
    private TypeResolver $typeResolver;

    public function __construct(TypeResolver $typeResolver, DescriptionFactory $descriptionFactory)
    {
        $this->descriptionFactory = $descriptionFactory;
        $this->typeResolver = $typeResolver;
    }

    public function create(PhpDocTagNode $node, Context $context): Tag
    {
        $tagValue = $node->value;
        Assert::isInstanceOf($tagValue, VarTagValueNode::class);

        $description = $tagValue->getAttribute('description');
        if (is_string($description) === false) {
            $description = $tagValue->description;
        }

        return new Var_(
            trim($tagValue->variableName, '$'),
            $this->typeResolver->createType($tagValue->type, $context),
            $this->descriptionFactory->create($description, $context)
        );
    }

    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof VarTagValueNode;
    }
}
