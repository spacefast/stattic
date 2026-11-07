<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use function array_key_exists;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Zero\Identity\Vendor\Webauthn\Exception\InvalidDataException;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialParameters;

final class PublicKeyCredentialParametersDenormalizer implements DenormalizerInterface
{
    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{type?: string, alg?: int} $data */
        if (! array_key_exists('type', $data) || ! array_key_exists('alg', $data)) {
            throw new InvalidDataException($data, 'Missing type or alg');
        }

        return PublicKeyCredentialParameters::create($data['type'], $data['alg']);
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === PublicKeyCredentialParameters::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            PublicKeyCredentialParameters::class => true,
        ];
    }
}
