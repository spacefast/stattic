<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Event;

use Throwable;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;

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
