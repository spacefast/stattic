<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Denormalizer;

use SpacePress\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use SpacePress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use SpacePress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use SpacePress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use SpacePress\Identity\Vendor\Webauthn\AttestationStatement\AttestationObject;
use SpacePress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use SpacePress\Identity\Vendor\Webauthn\CollectedClientData;
use SpacePress\Identity\Vendor\Webauthn\Exception\InvalidDataException;
use SpacePress\Identity\Vendor\Webauthn\Util\Base64;

final class AuthenticatorAttestationResponseDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{clientDataJSON: string, attestationObject: string, transports?: string[]} $data */
        $data['clientDataJSON'] = Base64UrlSafe::decodeNoPadding($data['clientDataJSON']);
        $data['attestationObject'] = Base64::decode($data['attestationObject']);

        /** @var CollectedClientData $clientDataJSON */
        $clientDataJSON = $this->denormalizer->denormalize(
            $data['clientDataJSON'],
            CollectedClientData::class,
            $format,
            $context
        );
        /** @var AttestationObject $attestationObject */
        $attestationObject = $this->denormalizer->denormalize(
            $data['attestationObject'],
            AttestationObject::class,
            $format,
            $context
        );

        return AuthenticatorAttestationResponse::create(
            $clientDataJSON,
            $attestationObject,
            $data['transports'] ?? [],
        );
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === AuthenticatorAttestationResponse::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            AuthenticatorAttestationResponse::class => true,
        ];
    }
}
