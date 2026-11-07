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

use Zero\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use Zero\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use Zero\Vendor\League\CommonMark\Parser\Block\BlockContinueParserWithInlinesInterface;
use Zero\Vendor\League\CommonMark\Parser\Cursor;
use Zero\Vendor\League\CommonMark\Parser\InlineParserEngineInterface;

final class HeadingParser extends AbstractBlockContinueParser implements BlockContinueParserWithInlinesInterface
{
    /** @psalm-readonly
     * @var \Zero\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\Heading */
    private $block;

    /**
     * @var string
     */
    private $content;

    public function __construct(int $level, string $content)
    {
        $this->block   = new Heading($level);
        $this->content = $content;
    }

    public function getBlock(): \Zero\Vendor\League\CommonMark\Node\Block\AbstractBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::none();
    }

    public function parseInlines(InlineParserEngineInterface $inlineParser): void
    {
        $inlineParser->parse($this->content, $this->block);
    }
}
