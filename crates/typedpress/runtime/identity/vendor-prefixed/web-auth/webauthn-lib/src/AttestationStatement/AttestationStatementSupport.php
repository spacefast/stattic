<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\AttestationStatement;

use TypedPress\Identity\Vendor\Webauthn\AuthenticatorData;

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
