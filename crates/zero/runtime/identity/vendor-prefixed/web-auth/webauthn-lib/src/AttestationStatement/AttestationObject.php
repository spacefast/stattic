<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\AttestationStatement;

use Zero\Identity\Vendor\Webauthn\AuthenticatorData;
use Zero\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

class AttestationObject
{
    public ?MetadataStatement $metadataStatement = null;

    public function __construct(
        public readonly string $rawAttestationObject,
        public AttestationStatement $attStmt,
        public readonly AuthenticatorData $authData
    ) {
    }

    public static function create(
        string $rawAttestationObject,
        AttestationStatement $attStmt,
        AuthenticatorData $authData
    ): self {
        return new self($rawAttestationObject, $attStmt, $authData);
    }
}
