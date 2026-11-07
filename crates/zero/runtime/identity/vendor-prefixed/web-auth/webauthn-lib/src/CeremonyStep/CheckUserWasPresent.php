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

/**
 * Conditional check for user presence.
 *
 * The User Presence (UP) bit MUST be set unless the ceremony explicitly opts out via the
 * Conditional Create flow (e.g. SimpleWebAuthn `useAutoRegister: true`). The opt-out is
 * controlled at runtime by `PublicKeyCredentialCreationOptions::$mediation`, which the
 * options builder sets from the configured policy. The constructor flag remains a static
 * fallback used by `CeremonyStepManagerFactory::conditionalCreateCeremony()`.
 *
 * @see https://github.com/w3c/webauthn/wiki/Explainer:-Conditional-Create
 */
final readonly class CheckUserWasPresent implements CeremonyStep
{
    public function __construct(
        private bool $requireUserPresence = true
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
        if (! $this->isUserPresenceRequired($publicKeyCredentialOptions)) {
            return;
        }

        $authData = $authenticatorResponse instanceof AuthenticatorAssertionResponse
            ? $authenticatorResponse->authenticatorData
            : $authenticatorResponse->attestationObject->authData;

        $authData->isUserPresent() || throw AuthenticatorResponseVerificationException::create(
            'User was not present'
        );
    }

    private function isUserPresenceRequired(
        PublicKeyCredentialRequestOptions|PublicKeyCredentialCreationOptions $publicKeyCredentialOptions
    ): bool {
        if ($publicKeyCredentialOptions instanceof PublicKeyCredentialCreationOptions
            && $publicKeyCredentialOptions->mediation === PublicKeyCredentialCreationOptions::MEDIATION_CONDITIONAL) {
            return false;
        }

        return $this->requireUserPresence;
    }
}
