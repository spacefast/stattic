<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\OTPHP;

use DateTimeImmutable;
use TypedPress\Identity\Vendor\Psr\Clock\ClockInterface;

/**
 * @readonly
 *
 * @internal
 */
final class InternalClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
