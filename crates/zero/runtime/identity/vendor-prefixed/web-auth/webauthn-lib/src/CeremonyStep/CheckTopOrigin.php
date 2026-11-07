<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\CeremonyStep;

use function zero_identity_vendor_trigger_deprecation as trigger_deprecation;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use Zero\Identity\Vendor\Webauthn\CredentialRecord;
use Zero\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

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
            zero_identity_vendor_trigger_deprecation(
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
