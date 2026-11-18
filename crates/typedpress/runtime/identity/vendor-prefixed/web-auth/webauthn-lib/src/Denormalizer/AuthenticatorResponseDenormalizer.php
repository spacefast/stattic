<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Denormalizer;

use function array_key_exists;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAssertionResponse;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorAttestationResponse;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorResponse;
use TypedPress\Identity\Vendor\Webauthn\Exception\InvalidDataException;

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
