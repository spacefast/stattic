<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\AttestationStatement;

use SpacePress\Identity\Vendor\Webauthn\AuthenticatorData;

interface AttestationStatementSupport
{
    public function name(): string;

    /**
     * @param array<string, mixed> $attestation
     */
    public function load(array $attestation): AttestationStatement;

    public function isValid(
        string $clientDataJSONHash,
        AttestationStatement $attestationStatement,
        AuthenticatorData $authenticatorData
    ): bool;
}
