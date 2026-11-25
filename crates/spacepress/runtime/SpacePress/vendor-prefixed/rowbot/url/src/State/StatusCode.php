<?php

declare(strict_types=1);

namespace SpacePress\Vendor\Rowbot\URL\State;

enum StatusCode
{
    case OK;
    case CONTINUE;
    case BREAK;
    case FAILURE;
}
