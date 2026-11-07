<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Event;

use Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatement;

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
