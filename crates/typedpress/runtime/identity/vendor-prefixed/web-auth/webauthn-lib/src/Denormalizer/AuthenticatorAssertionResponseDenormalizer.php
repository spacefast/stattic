<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Denormalizer;

use TypedPress\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use TypedPress\Identity\Vendor\Webauthn\AttestationStatement\AttestationObject;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorData;
use TypedPress\Identity\Vendor\Webauthn\CollectedClientData;
use TypedPress\Identity\Vendor\Webauthn\Exception\InvalidDataException;
use TypedPress\Identity\Vendor\Webauthn\Util\Base64;

final class AuthenticatorAssertionResponseDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{authenticatorData: string, signature: string, clientDataJSON: string, userHandle?: ?string, attestationObject?: string} $data */
        $data['authenticatorData'] = Base64::decode($data['authenticatorData']);
        $data['signature'] = Base64::decode($data['signature']);
        $data['clientDataJSON'] = Base64UrlSafe::decodeNoPadding($data['clientDataJSON']);
        $userHandle = $data['userHandle'] ?? null;
        if ($userHandle !== '' && $userHandle !== null) {
            $userHandle = Base64::decode($userHandle);
        }

        /** @var CollectedClientData $clientDataJSON */
        $clientDataJSON = $this->denormalizer->denormalize(
            $data['clientDataJSON'],
            CollectedClientData::class,
            $format,
            $context
        );
        /** @var AuthenticatorData $authenticatorData */
        $authenticatorData = $this->denormalizer->denormalize(
            $data['authenticatorData'],
            AuthenticatorData::class,
            $format,
            $context
        );

        return AuthenticatorAssertionResponse::create(
            $clientDataJSON,
            $authenticatorData,
            $data['signature'],
            $userHandle ?? null,
            ! isset($data['attestationObject']) ? null : $this->denormalizer->denormalize(
                $data['attestationObject'],
                AttestationObject::class,
                $format,
                $context
            ),
        );
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === AuthenticatorAssertionResponse::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            AuthenticatorAssertionResponse::class => true,
        ];
    }
}
