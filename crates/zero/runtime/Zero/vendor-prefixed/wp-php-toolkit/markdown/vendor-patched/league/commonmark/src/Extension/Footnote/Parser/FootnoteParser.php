<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 * (c) Rezo Zero / Ambroise Maupate
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Zero\Vendor\League\CommonMark\Extension\Footnote\Parser;

use Zero\Vendor\League\CommonMark\Extension\Footnote\Node\Footnote;
use Zero\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use Zero\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use Zero\Vendor\League\CommonMark\Parser\Cursor;
use Zero\Vendor\League\CommonMark\Reference\ReferenceInterface;

final class FootnoteParser extends AbstractBlockContinueParser
{
    /** @psalm-readonly
     * @var \Zero\Vendor\League\CommonMark\Extension\Footnote\Node\Footnote */
    private $block;

    /** @psalm-readonly-allow-private-mutation
     * @var int|null */
    private $indentation;

    public function __construct(ReferenceInterface $reference)
    {
        $this->block = new Footnote($reference);
    }

    public function getBlock(): \Zero\Vendor\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($cursor->isBlank()) {
            return BlockContinue::at($cursor);
        }

        if ($cursor->isIndented()) {
            $this->indentation = $this->indentation ?? $cursor->getIndent();
            $cursor->advanceBy($this->indentation, true);

            return BlockContinue::at($cursor);
        }

        return BlockContinue::none();
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }
}
