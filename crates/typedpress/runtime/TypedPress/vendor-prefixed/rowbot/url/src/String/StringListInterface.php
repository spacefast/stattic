<?php

declare(strict_types=1);

namespace TypedPress\Vendor\Rowbot\URL\String;

use Countable;
use IteratorAggregate;

/**
 * @extends \IteratorAggregate<int, \TypedPress\Vendor\Rowbot\URL\String\USVStringInterface>
 */
interface StringListInterface extends Countable, IteratorAggregate
{
    /**
     * @return \TypedPress\Vendor\Rowbot\URL\String\USVStringInterface
     */
    public function first();

    public function isEmpty(): bool;

    /**
     * @return \TypedPress\Vendor\Rowbot\URL\String\USVStringInterface
     */
    public function last();

    /**
     * @return \TypedPress\Vendor\Rowbot\URL\String\USVStringInterface|null
     */
    public function pop();

    /**
     * @param \TypedPress\Vendor\Rowbot\URL\String\USVStringInterface $item
     */
    public function push($item): void;
}
