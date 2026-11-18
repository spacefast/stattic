<?php

declare(strict_types=1);

namespace TypedPress\Vendor\Rowbot\URL\Component;

use TypedPress\Vendor\Rowbot\URL\String\Exception\UndefinedIndexException;

use function count;

abstract class AbstractPath implements PathInterface
{
    /**
     * @var list<\TypedPress\Vendor\Rowbot\URL\Component\PathSegment>
     */
    protected array $list;

    /**
     * @param list<\TypedPress\Vendor\Rowbot\URL\Component\PathSegment> $paths
     */
    public function __construct(array $paths = [])
    {
        $this->list = $paths;
    }

    public function count(): int
    {
        return count($this->list);
    }

    public function first(): PathSegment
    {
        return $this->list[0] ?? throw new UndefinedIndexException();
    }

    public function isEmpty(): bool
    {
        return $this->list === [];
    }

    public function __clone()
    {
        $list = [];

        foreach ($this->list as $path) {
            $list[] = clone $path;
        }

        $this->list = $list;
    }
}
