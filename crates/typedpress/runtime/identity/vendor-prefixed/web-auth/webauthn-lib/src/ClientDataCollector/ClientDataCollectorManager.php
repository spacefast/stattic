<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\ClientDataCollector;

use function in_array;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorResponse;
use TypedPress\Identity\Vendor\Webauthn\CollectedClientData;
use TypedPress\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialOptions;

final readonly class ClientDataCollectorManager
{
    /**
     * @param ClientDataCollector[] $clientDataCollectors
     */
    public function __construct(
        private iterable $clientDataCollectors,
    ) {
    }

    public function collect(
        CollectedClientData $collectedClientData,
        PublicKeyCredentialOptions $publicKeyCredentialOptions,
        AuthenticatorResponse $authenticatorResponse,
        string $host
    ): void {
        foreach ($this->clientDataCollectors as $clientDataCollector) {
            if (in_array($collectedClientData->type, $clientDataCollector->supportedTypes(), true)) {
                $clientDataCollector->verifyCollectedClientData(
                    $collectedClientData,
                    $publicKeyCredentialOptions,
                    $authenticatorResponse,
                    $host
                );
                return;
            }
        }

        throw AuthenticatorResponseVerificationException::create('No client data collector found.');
    }
}
