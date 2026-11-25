<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Event;

use SpacePress\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;

interface CanDispatchEvents
{
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void;
}
