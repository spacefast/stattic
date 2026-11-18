<?php

declare(strict_types=1);

namespace TypedPress\Vendor\Rowbot\URL\State;

use TypedPress\Vendor\Rowbot\URL\ParserContext;

interface State
{
    public function handle(ParserContext $context, string $codePoint): StatusCode;
}
