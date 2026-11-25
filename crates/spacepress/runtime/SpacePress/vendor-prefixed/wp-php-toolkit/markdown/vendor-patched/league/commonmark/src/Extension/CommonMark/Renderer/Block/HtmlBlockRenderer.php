<?php

declare(strict_types=1);

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * Original code based on the CommonMark JS reference parser (https://bitly.com/commonmark-js)
 *  - (c) John MacFarlane
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpacePress\Vendor\League\CommonMark\Extension\CommonMark\Renderer\Block;

use SpacePress\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use SpacePress\Vendor\League\CommonMark\Node\Node;
use SpacePress\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use SpacePress\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use SpacePress\Vendor\League\CommonMark\Util\HtmlFilter;
use SpacePress\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use SpacePress\Vendor\League\Config\ConfigurationAwareInterface;
use SpacePress\Vendor\League\Config\ConfigurationInterface;

final class HtmlBlockRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{
    /** @psalm-readonly-allow-private-mutation
     * @var \SpacePress\Vendor\League\Config\ConfigurationInterface */
    private $config;

    /**
     * @param HtmlBlock $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        HtmlBlock::assertInstanceOf($node);

        $htmlInput = $this->config->get('html_input');

        return HtmlFilter::filter($node->getLiteral(), $htmlInput);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'html_block';
    }

    /**
     * {@inheritDoc}
     */
    public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
