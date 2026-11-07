<?php

declare(strict_types=1);

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zero\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block;

use Zero\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use Zero\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use Zero\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use Zero\Vendor\League\CommonMark\Parser\Cursor;

final class BlockQuoteParser extends AbstractBlockContinueParser
{
    /** @psalm-readonly
     * @var \Zero\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote */
    private $block;

    public function __construct()
    {
        $this->block = new BlockQuote();
    }

    public function getBlock(): \Zero\Vendor\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if (! $cursor->isIndented() && $cursor->getNextNonSpaceCharacter() === '>') {
            $cursor->advanceToNextNonSpaceOrTab();
            $cursor->advanceBy(1);
            $cursor->advanceBySpaceOrTab();

            return BlockContinue::at($cursor);
        }

        return BlockContinue::none();
    }
}
