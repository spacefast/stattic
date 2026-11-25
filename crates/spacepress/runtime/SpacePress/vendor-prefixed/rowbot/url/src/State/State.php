<?php

declare(strict_types=1);

namespace SpacePress\Vendor\Rowbot\URL\State;

use SpacePress\Vendor\Rowbot\URL\ParserContext;

interface State
{
    public function handle(ParserContext $context, string $codePoint): StatusCode;
}
