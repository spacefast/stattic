<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Event;

use Zero\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;

final class NullEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}
