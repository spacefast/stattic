<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Event;

use SpacePress\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;

final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
