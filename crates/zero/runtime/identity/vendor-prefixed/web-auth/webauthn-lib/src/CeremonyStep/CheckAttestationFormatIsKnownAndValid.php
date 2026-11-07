<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\CeremonyStep;

use function zero_identity_vendor_trigger_deprecation as trigger_deprecation;
use Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use Zero\Identity\Vendor\Webauthn\CredentialRecord;
use Zero\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

final readonly class CheckAttestationFormatIsKnownAndValid implements CeremonyStep
{
    public function __construct(
        private AttestationStatementSupportManager $attestationStatementSupportManager,
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
        $attestationObject = $authenticatorResponse->attestationObject;
        if ($attestationObject === null) {
            return;
        }

        $fmt = $attestationObject->attStmt
            ->fmt;
        $this->attestationStatementSupportManager->has(
            $fmt
        ) || throw AuthenticatorResponseVerificationException::create('Unsupported attestation statement format.');

        $attestationStatementSupport = $this->attestationStatementSupportManager->get($fmt);
        $clientDataJSONHash = hash('sha256', $authenticatorResponse->clientDataJSON->rawData, true);
        $attestationStatementSupport->isValid(
            $clientDataJSONHash,
            $attestationObject->attStmt,
            $attestationObject->authData
        ) || throw AuthenticatorResponseVerificationException::create('Invalid attestation statement.');
    }
}
