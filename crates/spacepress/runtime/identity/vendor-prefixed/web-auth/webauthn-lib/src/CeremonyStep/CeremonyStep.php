<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\CeremonyStep;

use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use SpacePress\Identity\Vendor\Webauthn\CredentialRecord;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;

interface CeremonyStep
{
    public function process(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse|AuthenticatorAttestationResponse $authenticatorResponse,
        PublicKeyCredentialRequestOptions|PublicKeyCredentialCreationOptions $publicKeyCredentialOptions,
        ?string $userHandle,
        string $host
    ): void;
}
