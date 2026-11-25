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

namespace SpacePress\Vendor\League\CommonMark\Extension\FrontMatter;

use SpacePress\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use SpacePress\Vendor\League\CommonMark\Event\DocumentPreParsedEvent;
use SpacePress\Vendor\League\CommonMark\Event\DocumentRenderedEvent;
use SpacePress\Vendor\League\CommonMark\Extension\ExtensionInterface;
use SpacePress\Vendor\League\CommonMark\Extension\FrontMatter\Data\FrontMatterDataParserInterface;
use SpacePress\Vendor\League\CommonMark\Extension\FrontMatter\Data\LibYamlFrontMatterParser;
use SpacePress\Vendor\League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use SpacePress\Vendor\League\CommonMark\Extension\FrontMatter\Listener\FrontMatterPostRenderListener;
use SpacePress\Vendor\League\CommonMark\Extension\FrontMatter\Listener\FrontMatterPreParser;

final class FrontMatterExtension implements ExtensionInterface
{
    /** @psalm-readonly
     * @var \SpacePress\Vendor\League\CommonMark\Extension\FrontMatter\FrontMatterParserInterface */
    private $frontMatterParser;

    public function __construct(?FrontMatterDataParserInterface $dataParser = null)
    {
        $this->frontMatterParser = new FrontMatterParser($dataParser ?? LibYamlFrontMatterParser::capable() ?? new SymfonyYamlFrontMatterParser());
    }

    public function getFrontMatterParser(): FrontMatterParserInterface
    {
        return $this->frontMatterParser;
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentPreParsedEvent::class, new FrontMatterPreParser($this->frontMatterParser));
        $environment->addEventListener(DocumentRenderedEvent::class, new FrontMatterPostRenderListener(), -500);
    }
}
