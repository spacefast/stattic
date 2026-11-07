<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\CeremonyStep;

use function zero_identity_vendor_trigger_deprecation as trigger_deprecation;
use Zero\Identity\Vendor\Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use Zero\Identity\Vendor\Webauthn\CredentialRecord;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

final readonly class CheckExtensions implements CeremonyStep
{
    public function __construct(
        private ExtensionOutputCheckerHandler $extensionOutputCheckerHandler,
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
        $authData = $authenticatorResponse instanceof AuthenticatorAssertionResponse ? $authenticatorResponse->authenticatorData : $authenticatorResponse->attestationObject->authData;
        $extensionsClientOutputs = $authData->extensions;
        if ($extensionsClientOutputs !== null) {
            $this->extensionOutputCheckerHandler->check(
                $publicKeyCredentialOptions->extensions,
                $extensionsClientOutputs
            );
        }
    }
}
