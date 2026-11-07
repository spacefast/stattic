<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\CeremonyStep;

use Zero\Identity\Vendor\CBOR\Decoder;
use Zero\Identity\Vendor\CBOR\Normalizable;
use Zero\Identity\Vendor\Cose\Algorithms;
use Zero\Identity\Vendor\Cose\Key\Key;
use function count;
use function in_array;
use function is_array;
use function sprintf;
use function zero_identity_vendor_trigger_deprecation as trigger_deprecation;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use Zero\Identity\Vendor\Webauthn\CredentialRecord;
use Zero\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialCreationOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialRequestOptions;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialSource;
use Zero\Identity\Vendor\Webauthn\StringStream;
use Zero\Identity\Vendor\Webauthn\U2FPublicKey;

class CheckAlgorithm implements CeremonyStep
{
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
        if (! $publicKeyCredentialOptions instanceof PublicKeyCredentialCreationOptions) {
            return;
        }
        $credentialPublicKey = $credentialRecord->getAttestedCredentialData()
            ->credentialPublicKey;
        $credentialPublicKey !== null || throw AuthenticatorResponseVerificationException::create(
            'No public key available.'
        );
        $algorithms = array_map(
            fn ($pubKeyCredParam) => $pubKeyCredParam->alg,
            $publicKeyCredentialOptions->pubKeyCredParams
        );
        if (count($algorithms) === 0) {
            $algorithms = [Algorithms::COSE_ALGORITHM_ES256, Algorithms::COSE_ALGORITHM_RS256];
        }
        $coseKey = $this->getCoseKey($credentialPublicKey);
        in_array($coseKey->alg(), $algorithms, true) || throw AuthenticatorResponseVerificationException::create(
            sprintf('Invalid algorithm. Expected one of %s but got %d', implode(', ', $algorithms), $coseKey->alg())
        );
    }

    private function getCoseKey(string $credentialPublicKey): Key
    {
        $isU2F = U2FPublicKey::isU2FKey($credentialPublicKey);
        if ($isU2F) {
            $credentialPublicKey = U2FPublicKey::convertToCoseKey($credentialPublicKey);
        }
        $stream = new StringStream($credentialPublicKey);
        $credentialPublicKeyStream = Decoder::create()->decode($stream);
        $stream->isEOF() || throw AuthenticatorResponseVerificationException::create(
            'Invalid key. Presence of extra bytes.'
        );
        $stream->close();
        $credentialPublicKeyStream instanceof Normalizable || throw AuthenticatorResponseVerificationException::create(
            'Invalid attestation object. Unexpected object.'
        );
        $normalizedData = $credentialPublicKeyStream->normalize();
        is_array($normalizedData) || throw AuthenticatorResponseVerificationException::create(
            'Invalid attestation object. Unexpected object.'
        );
        /** @var array<int|string, mixed> $normalizedData */

        return Key::create($normalizedData);
    }
}
