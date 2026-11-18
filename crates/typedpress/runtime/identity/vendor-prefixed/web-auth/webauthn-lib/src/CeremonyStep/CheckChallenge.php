<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\CeremonyStep;

use function typedpress_identity_vendor_trigger_deprecation as trigger_deprecation;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use TypedPress\Identity\Vendor\Webauthn\CredentialRecord;
use TypedPress\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

final class CheckChallenge implements CeremonyStep
{
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
        $publicKeyCredentialOptions->challenge !== '' || throw AuthenticatorResponseVerificationException::create(
            'Invalid challenge.'
        );
        hash_equals(
            $publicKeyCredentialOptions->challenge,
            $authenticatorResponse->clientDataJSON->challenge
        ) || throw AuthenticatorResponseVerificationException::create('Invalid challenge.');
    }
}
