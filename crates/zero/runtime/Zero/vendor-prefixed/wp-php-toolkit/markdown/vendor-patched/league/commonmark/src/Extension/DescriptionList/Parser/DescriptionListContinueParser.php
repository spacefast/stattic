<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Zero\Vendor\League\CommonMark\Extension\DescriptionList\Parser;

use Zero\Vendor\League\CommonMark\Extension\DescriptionList\Node\Description;
use Zero\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionList;
use Zero\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionTerm;
use Zero\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use Zero\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use Zero\Vendor\League\CommonMark\Parser\Cursor;

final class DescriptionListContinueParser extends AbstractBlockContinueParser
{
    /**
     * @var \Zero\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionList
     */
    private $block;

    public function __construct()
    {
        $this->block = new DescriptionList();
    }

    public function getBlock(): \Zero\Vendor\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::at($cursor);
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return $childBlock instanceof DescriptionTerm || $childBlock instanceof Description;
    }
}
