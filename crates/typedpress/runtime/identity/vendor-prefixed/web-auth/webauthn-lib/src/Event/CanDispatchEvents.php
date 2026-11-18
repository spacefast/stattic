<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Event;

use TypedPress\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;

interface CanDispatchEvents
{
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void;
}
