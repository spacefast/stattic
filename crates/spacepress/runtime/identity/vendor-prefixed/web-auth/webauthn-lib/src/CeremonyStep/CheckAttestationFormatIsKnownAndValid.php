<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\CeremonyStep;

use function spacepress_identity_vendor_trigger_deprecation as trigger_deprecation;
use SpacePress\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatementSupportManager;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use SpacePress\Identity\Vendor\Webauthn\CredentialRecord;
use SpacePress\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

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
            spacepress_identity_vendor_trigger_deprecation(
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
