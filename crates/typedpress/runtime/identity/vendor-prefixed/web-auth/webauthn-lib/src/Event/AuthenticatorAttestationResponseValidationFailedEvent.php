<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Event;

use Throwable;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;

readonly class AuthenticatorAttestationResponseValidationFailedEvent
{
    public function __construct(
        public AuthenticatorAttestationResponse $authenticatorAttestationResponse,
        public PublicKeyCredentialCreationOptions $publicKeyCredentialCreationOptions,
        public string $host,
        public Throwable $throwable
    ) {
    }
}
