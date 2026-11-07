<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\TemplateCovariant;
use Zero\Identity\Vendor\phpDocumentor\Reflection\TypeResolver;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Types\Context;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Zero\Identity\Vendor\Webmozart\Assert\Assert;

use function is_string;

/**
 * @internal This class is not part of the BC promise of this library.
 */
final class TemplateCovariantFactory implements PHPStanFactory
{
    private DescriptionFactory $descriptionFactory;
    private TypeResolver $typeResolver;

    public function __construct(TypeResolver $typeResolver, DescriptionFactory $descriptionFactory)
    {
        $this->descriptionFactory = $descriptionFactory;
        $this->typeResolver = $typeResolver;
    }

    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof TemplateTagValueNode && $node->name === '@template-covariant';
    }

    public function create(PhpDocTagNode $node, Context $context): Tag
    {
        $tagValue = $node->value;
        Assert::isInstanceOf($tagValue, TemplateTagValueNode::class);

        $description = $tagValue->getAttribute('description');
        if (is_string($description) === false) {
            $description = $tagValue->description;
        }

        return new TemplateCovariant(
            $this->typeResolver->createType(new IdentifierTypeNode($tagValue->name), $context),
            $this->descriptionFactory->create($description, $context)
        );
    }
}
