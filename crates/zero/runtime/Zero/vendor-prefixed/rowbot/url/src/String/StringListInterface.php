<?php

declare(strict_types=1);

namespace Zero\Vendor\Rowbot\URL\String;

use Countable;
use IteratorAggregate;

/**
 * @extends \IteratorAggregate<int, \Zero\Vendor\Rowbot\URL\String\USVStringInterface>
 */
interface StringListInterface extends Countable, IteratorAggregate
{
    /**
     * @return \Zero\Vendor\Rowbot\URL\String\USVStringInterface
     */
    public function first();

    public function isEmpty(): bool;

    /**
     * @return \Zero\Vendor\Rowbot\URL\String\USVStringInterface
     */
    public function last();

    /**
     * @return \Zero\Vendor\Rowbot\URL\String\USVStringInterface|null
     */
    public function pop();

    /**
     * @param \Zero\Vendor\Rowbot\URL\String\USVStringInterface $item
     */
    public function push($item): void;
}
