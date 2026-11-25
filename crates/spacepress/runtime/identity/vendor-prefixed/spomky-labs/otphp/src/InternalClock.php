<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\OTPHP;

use DateTimeImmutable;
use SpacePress\Identity\Vendor\Psr\Clock\ClockInterface;

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
