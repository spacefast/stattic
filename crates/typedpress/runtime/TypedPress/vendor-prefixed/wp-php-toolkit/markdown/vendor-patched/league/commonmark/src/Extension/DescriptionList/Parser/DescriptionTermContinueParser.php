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

namespace TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Parser;

use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionTerm;
use TypedPress\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use TypedPress\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use TypedPress\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use TypedPress\Vendor\League\CommonMark\Parser\Block\BlockContinueParserWithInlinesInterface;
use TypedPress\Vendor\League\CommonMark\Parser\Cursor;
use TypedPress\Vendor\League\CommonMark\Parser\InlineParserEngineInterface;

final class DescriptionTermContinueParser extends AbstractBlockContinueParser implements BlockContinueParserWithInlinesInterface
{
    /**
     * @var \TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionTerm
     */
    private $block;

    /**
     * @var string
     */
    private $term;

    public function __construct(string $term)
    {
        $this->block = new DescriptionTerm();
        $this->term  = $term;
    }

    public function getBlock(): \TypedPress\Vendor\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::finished();
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        if ($this->term !== '') {
            $inlineParser->parse($this->term, $this->block);
        }
    }
}
