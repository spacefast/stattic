<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\CeremonyStep;

use function spacepress_identity_vendor_trigger_deprecation as trigger_deprecation;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use SpacePress\Identity\Vendor\Webauthn\ClientDataCollector\ClientDataCollectorManager;
use SpacePress\Identity\Vendor\Webauthn\ClientDataCollector\WebauthnAuthenticationCollector;
use SpacePress\Identity\Vendor\Webauthn\CredentialRecord;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

final readonly class CheckClientDataCollectorType implements CeremonyStep
{
    private ClientDataCollectorManager $clientDataCollectorManager;

    public function __construct(null|ClientDataCollectorManager $clientDataCollectorManager = null)
    {
        $this->clientDataCollectorManager = $clientDataCollectorManager ?? new ClientDataCollectorManager([
            new WebauthnAuthenticationCollector(),
        ]);
    }

    public function process(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse|AuthenticatorAttestationResponse $authenticatorResponse,
        PublicKeyCredentialRequestOptions|PublicKeyCredentialCreationOptions $publicKeyCredentialOptions,
        ?string $userHandle,
        string $host
    ): void {
        if ($credentialRecord instanceof PublicKeyCredentialSource) {
            spacepress_identity_vendor_trigger_deprecation(
                'web-auth/webauthn-lib',
                '5.3',
                'Passing a PublicKeyCredentialSource to "%s::process()" is deprecated, pass a CredentialRecord instead.',
                self::class
            );
        }
        $this->clientDataCollectorManager->collect(
            $authenticatorResponse->clientDataJSON,
            $publicKeyCredentialOptions,
            $authenticatorResponse,
            $host
        );
    }
}
