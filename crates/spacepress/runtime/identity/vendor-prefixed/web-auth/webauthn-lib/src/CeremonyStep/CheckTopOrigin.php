<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\CeremonyStep;

use function spacepress_identity_vendor_trigger_deprecation as trigger_deprecation;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use SpacePress\Identity\Vendor\Webauthn\CredentialRecord;
use SpacePress\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

class CheckTopOrigin implements CeremonyStep
{
    public function __construct(
        private readonly null|TopOriginValidator $topOriginValidator = null
    ) {
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
        $topOrigin = $authenticatorResponse->clientDataJSON->topOrigin;
        if ($topOrigin === null) {
            return;
        }
        if (! $authenticatorResponse->clientDataJSON->crossOrigin) {
            throw AuthenticatorResponseVerificationException::create('The response is not cross-origin.');
        }
        if ($this->topOriginValidator === null) {
            throw AuthenticatorResponseVerificationException::create(
                'A cross-origin response was received but no TopOriginValidator is configured. '
                . 'Configure one via CeremonyStepManagerFactory::enableTopOriginValidator() '
                . 'to opt in to cross-origin (iframe) ceremonies.'
            );
        }
        $this->topOriginValidator->validate($topOrigin);
    }
}
