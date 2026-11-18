<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Exception;

use Throwable;
use TypedPress\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatement;

final class InvalidAttestationStatementException extends AttestationStatementException
{
    public function __construct(
        public readonly AttestationStatement $attestationStatement,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $previous);
    }

    public static function create(
        AttestationStatement $attestationStatement,
        string $message = 'Invalid attestation statement',
        ?Throwable $previous = null
    ): self {
        return new self($attestationStatement, $message, $previous);
    }
}
