<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\ClientDataCollector;

use function in_array;
use function sprintf;
use Zero\Identity\Vendor\Webauthn\AuthenticatorResponse;
use Zero\Identity\Vendor\Webauthn\CollectedClientData;
use Zero\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialOptions;

final class WebauthnAuthenticationCollector implements ClientDataCollector
{
    public function supportedTypes(): array
    {
        return ['webauthn.get', 'webauthn.create'];
    }

    public function verifyCollectedClientData(
        CollectedClientData $collectedClientData,
        PublicKeyCredentialOptions $publicKeyCredentialOptions,
        AuthenticatorResponse $authenticatorResponse,
        string $host
    ): void {
        in_array(
            $collectedClientData->type,
            $this->supportedTypes(),
            true
        ) || throw AuthenticatorResponseVerificationException::create(
            sprintf('The client data type is not "%s" supported.', implode('", "', $this->supportedTypes()))
        );
    }
}
