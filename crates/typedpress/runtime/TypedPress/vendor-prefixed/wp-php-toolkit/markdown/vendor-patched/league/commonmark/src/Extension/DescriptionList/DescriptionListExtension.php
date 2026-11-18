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

namespace TypedPress\Vendor\League\CommonMark\Extension\DescriptionList;

use TypedPress\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use TypedPress\Vendor\League\CommonMark\Event\DocumentParsedEvent;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Event\ConsecutiveDescriptionListMerger;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Event\LooseDescriptionHandler;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Node\Description;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionList;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionTerm;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Parser\DescriptionStartParser;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Renderer\DescriptionListRenderer;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Renderer\DescriptionRenderer;
use TypedPress\Vendor\League\CommonMark\Extension\DescriptionList\Renderer\DescriptionTermRenderer;
use TypedPress\Vendor\League\CommonMark\Extension\ExtensionInterface;

final class DescriptionListExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addBlockStartParser(new DescriptionStartParser());

        $environment->addEventListener(DocumentParsedEvent::class, new LooseDescriptionHandler(), 1001);
        $environment->addEventListener(DocumentParsedEvent::class, new ConsecutiveDescriptionListMerger(), 1000);

        $environment->addRenderer(DescriptionList::class, new DescriptionListRenderer());
        $environment->addRenderer(DescriptionTerm::class, new DescriptionTermRenderer());
        $environment->addRenderer(Description::class, new DescriptionRenderer());
    }
}
