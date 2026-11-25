<?php

declare(strict_types=1);

namespace SpacePress\Vendor\Rowbot\URL\String;

use Countable;
use IteratorAggregate;

/**
 * @extends \IteratorAggregate<int, \SpacePress\Vendor\Rowbot\URL\String\USVStringInterface>
 */
interface StringListInterface extends Countable, IteratorAggregate
{
    /**
     * @return \SpacePress\Vendor\Rowbot\URL\String\USVStringInterface
     */
    public function first();

    public function isEmpty(): bool;

    /**
     * @return \SpacePress\Vendor\Rowbot\URL\String\USVStringInterface
     */
    public function last();

    /**
     * @return \SpacePress\Vendor\Rowbot\URL\String\USVStringInterface|null
     */
    public function pop();

    /**
     * @param \SpacePress\Vendor\Rowbot\URL\String\USVStringInterface $item
     */
    public function push($item): void;
}
