<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Event;

use SpacePress\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatement;

class AttestationStatementLoaded implements WebauthnEvent
{
    public function __construct(
        public readonly AttestationStatement $attestationStatement
    ) {
    }

    public static function create(AttestationStatement $attestationStatement): self
    {
        return new self($attestationStatement);
    }
}
