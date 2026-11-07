<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Event;

use Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationObject;

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
