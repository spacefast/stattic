<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Method;
use Zero\Identity\Vendor\phpDocumentor\Reflection\DocBlock\Tags\MethodParameter;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Type;
use Zero\Identity\Vendor\phpDocumentor\Reflection\TypeResolver;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Types\Context;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Types\Mixed_;
use Zero\Identity\Vendor\phpDocumentor\Reflection\Types\Void_;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueParameterNode;
use Zero\Identity\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Zero\Identity\Vendor\Webmozart\Assert\Assert;

use function array_map;
use function trim;

/**
 * @internal This class is not part of the BC promise of this library.
 */
final class MethodFactory implements PHPStanFactory
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
        Assert::isInstanceOf($tagValue, MethodTagValueNode::class);

        return new Method(
            $tagValue->methodName,
            array_map(
                function (MethodTagValueParameterNode $param) use ($context) {
                    return new MethodParameter(
                        trim($param->parameterName, '$'),
                        $param->type === null ? new Mixed_() : $this->typeResolver->createType(
                            $param->type,
                            $context
                        ),
                        $param->isReference,
                        $param->isVariadic,
                        $param->defaultValue === null ?
                            MethodParameter::NO_DEFAULT_VALUE :
                            (string) $param->defaultValue
                    );
                },
                $tagValue->parameters
            ),
            $this->createReturnType($tagValue, $context),
            $tagValue->isStatic,
            $this->descriptionFactory->create($tagValue->description, $context),
            false,
        );
    }

    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof MethodTagValueNode;
    }

    private function createReturnType(MethodTagValueNode $tagValue, Context $context): Type
    {
        if ($tagValue->returnType === null) {
            return new Void_();
        }

        return $this->typeResolver->createType($tagValue->returnType, $context);
    }
}
