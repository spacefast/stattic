<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Event;

use SpacePress\Identity\Vendor\Webauthn\AttestationStatement\AttestationObject;

class AttestationObjectLoaded implements WebauthnEvent
{
    public function __construct(
        public readonly AttestationObject $attestationObject
    ) {
    }

    public static function create(AttestationObject $attestationObject): self
    {
        return new self($attestationObject);
    }
}
