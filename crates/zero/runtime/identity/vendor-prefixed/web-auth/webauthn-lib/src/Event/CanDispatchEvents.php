<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Event;

use Zero\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;

interface CanDispatchEvents
{
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void;
}
