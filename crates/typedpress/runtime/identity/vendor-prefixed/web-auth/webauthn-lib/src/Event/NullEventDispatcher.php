<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Event;

use TypedPress\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;

final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
