<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\phpDocumentor\Reflection\PseudoTypes;

use SpacePress\Identity\Vendor\phpDocumentor\Reflection\PseudoType;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\Type;
use SpacePress\Identity\Vendor\phpDocumentor\Reflection\Types\Object_;

use function implode;

/** @psalm-immutable */
final class ObjectShape extends Object_ implements PseudoType
{
    /** @var ObjectShapeItem[] */
    private $items;

    public function __construct(ObjectShapeItem ...$items)
    {
        $this->items = $items;
    }

    /**
     * @return ObjectShapeItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function underlyingType(): Type
    {
        return new Object_();
    }

    public function __toString(): string
    {
        return 'object{' . implode(', ', $this->items) . '}';
    }
}
