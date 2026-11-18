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

namespace TypedPress\Vendor\League\CommonMark\Xml;

use TypedPress\Vendor\League\CommonMark\ConverterInterface;
use TypedPress\Vendor\League\CommonMark\Environment\EnvironmentInterface;
use TypedPress\Vendor\League\CommonMark\Exception\CommonMarkException;
use TypedPress\Vendor\League\CommonMark\Output\RenderedContentInterface;
use TypedPress\Vendor\League\CommonMark\Parser\MarkdownParser;
use TypedPress\Vendor\League\CommonMark\Parser\MarkdownParserInterface;
use TypedPress\Vendor\League\CommonMark\Renderer\DocumentRendererInterface;

final class MarkdownToXmlConverter implements ConverterInterface
{
    /** @psalm-readonly
     * @var \TypedPress\Vendor\League\CommonMark\Parser\MarkdownParserInterface */
    private $parser;

    /** @psalm-readonly
     * @var \TypedPress\Vendor\League\CommonMark\Renderer\DocumentRendererInterface */
    private $renderer;

    public function __construct(EnvironmentInterface $environment)
    {
        $this->parser   = new MarkdownParser($environment);
        $this->renderer = new XmlRenderer($environment);
    }

    /**
     * Converts Markdown to XML
     *
     * @throws CommonMarkException
     */
    public function convert(string $input): RenderedContentInterface
    {
        return $this->renderer->renderDocument($this->parser->parse($input));
    }

    /**
     * Converts CommonMark to HTML.
     *
     * @see MarkdownToXmlConverter::convert()
     *
     * @throws CommonMarkException
     */
    public function __invoke(string $input): RenderedContentInterface
    {
        return $this->convert($input);
    }
}
