<?php

declare(strict_types=1);

namespace Zero\Vendor\Rowbot\URL\State;

use Zero\Vendor\Rowbot\URL\ParserContext;

interface State
{
    public function handle(ParserContext $context, string $codePoint): StatusCode;
}
