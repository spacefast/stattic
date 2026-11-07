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

namespace Zero\Vendor\League\CommonMark\Extension\Autolink;

use Zero\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use Zero\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use Zero\Vendor\League\Config\ConfigurationBuilderInterface;
use Zero\Vendor\Nette\Schema\Expect;

final class AutolinkExtension implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $builder->addSchema('autolink', Expect::structure([
            'allowed_protocols' => Expect::listOf('string')->default(['http', 'https', 'ftp'])->mergeDefaults(false),
            'default_protocol' => Expect::string()->default('http'),
        ]));
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new EmailAutolinkParser());
        $environment->addInlineParser(new UrlAutolinkParser($environment->getConfiguration()->get('autolink.allowed_protocols'), $environment->getConfiguration()->get('autolink.default_protocol')));
    }
}
