<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\CeremonyStep;

use function typedpress_identity_vendor_trigger_deprecation as trigger_deprecation;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use TypedPress\Identity\Vendor\Webauthn\Counter\CounterChecker;
use TypedPress\Identity\Vendor\Webauthn\CredentialRecord;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

final readonly class CheckCounter implements CeremonyStep
{
    public function __construct(
        private CounterChecker $counterChecker
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
            typedpress_identity_vendor_trigger_deprecation(
                'web-auth/webauthn-lib',
                '5.3',
                'Passing a PublicKeyCredentialSource to "%s::process()" is deprecated, pass a CredentialRecord instead.',
                self::class
            );
        }
        $authData = $authenticatorResponse instanceof AuthenticatorAssertionResponse ? $authenticatorResponse->authenticatorData : $authenticatorResponse->attestationObject->authData;
        $storedCounter = $credentialRecord->counter;
        $responseCounter = $authData->signCount;
        if ($responseCounter !== 0 || $storedCounter !== 0) {
            $this->counterChecker->check($credentialRecord, $responseCounter);
        }
        $credentialRecord->counter = $responseCounter;
    }
}
