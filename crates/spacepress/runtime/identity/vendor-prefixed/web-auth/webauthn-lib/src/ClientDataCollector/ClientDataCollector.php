<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\ClientDataCollector;

use SpacePress\Identity\Vendor\Webauthn\AuthenticatorResponse;
use SpacePress\Identity\Vendor\Webauthn\CollectedClientData;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialOptions;

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
