<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\ClientDataCollector;

use Zero\Identity\Vendor\Webauthn\AuthenticatorResponse;
use Zero\Identity\Vendor\Webauthn\CollectedClientData;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialOptions;

interface ClientDataCollector
{
    /**
     * @return string[]
     */
    public function supportedTypes(): array;

    public function verifyCollectedClientData(
        CollectedClientData $collectedClientData,
        PublicKeyCredentialOptions $publicKeyCredentialOptions,
        AuthenticatorResponse $authenticatorResponse,
        string $host
    ): void;
}
