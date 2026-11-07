<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use function array_key_exists;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use Zero\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use Zero\Identity\Vendor\Webauthn\AuthenticatorResponse;
use Zero\Identity\Vendor\Webauthn\Exception\InvalidDataException;

final class AuthenticatorResponseDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array<string, mixed> $data */
        $realType = match (true) {
            array_key_exists('attestationObject', $data) => AuthenticatorAttestationResponse::class,
            array_key_exists('signature', $data) => AuthenticatorAssertionResponse::class,
            default => throw InvalidDataException::create($data, 'Unable to create the response object'),
        };

        return $this->denormalizer->denormalize($data, $realType, $format, $context);
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === AuthenticatorResponse::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            AuthenticatorResponse::class => true,
        ];
    }
}
