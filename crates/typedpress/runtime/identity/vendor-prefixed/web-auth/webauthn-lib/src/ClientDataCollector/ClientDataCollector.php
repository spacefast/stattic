<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\ClientDataCollector;

use TypedPress\Identity\Vendor\Webauthn\AuthenticatorResponse;
use TypedPress\Identity\Vendor\Webauthn\CollectedClientData;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialOptions;

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
