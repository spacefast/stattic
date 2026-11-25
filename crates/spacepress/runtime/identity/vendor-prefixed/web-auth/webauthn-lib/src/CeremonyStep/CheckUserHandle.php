<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\CeremonyStep;

use function spacepress_identity_vendor_trigger_deprecation as trigger_deprecation;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use SpacePress\Identity\Vendor\Webauthn\CredentialRecord;
use SpacePress\Identity\Vendor\Webauthn\Exception\InvalidUserHandleException;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

final class CheckUserHandle implements CeremonyStep
{
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
        if (! $authenticatorResponse instanceof AuthenticatorAssertionResponse) {
            return;
        }
        $credentialUserHandle = $credentialRecord->userHandle;
        $responseUserHandle = $authenticatorResponse->userHandle;
        if ($userHandle !== null) { // If the user was identified before the authentication ceremony was initiated,
            hash_equals($credentialUserHandle, $userHandle) || throw InvalidUserHandleException::create();
            if ($responseUserHandle !== null && $responseUserHandle !== '') {
                hash_equals($credentialUserHandle, $responseUserHandle) || throw InvalidUserHandleException::create();
            }
        } else {
            ($responseUserHandle !== null && $responseUserHandle !== '' && hash_equals(
                $credentialUserHandle,
                $responseUserHandle
            ))
                || throw InvalidUserHandleException::create();
        }
    }
}
