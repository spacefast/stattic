<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Denormalizer;

use function array_key_exists;
use function assert;
use TypedPress\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use TypedPress\Identity\Vendor\Webauthn\Exception\InvalidDataException;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialUserEntity;
use TypedPress\Identity\Vendor\Webauthn\Util\Base64;

final class PublicKeyCredentialUserEntityDenormalizer implements DenormalizerInterface, NormalizerInterface
{
    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{id?: string, name: string, displayName: string} $data */
        if (! array_key_exists('id', $data)) {
            return $data;
        }
        $data['id'] = Base64::decode($data['id']);

        return PublicKeyCredentialUserEntity::create($data['name'], $data['id'], $data['displayName']);
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === PublicKeyCredentialUserEntity::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            PublicKeyCredentialUserEntity::class => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        assert($object instanceof PublicKeyCredentialUserEntity);
        return [
            'id' => Base64UrlSafe::encodeUnpadded($object->id),
            'name' => $object->name,
            'displayName' => $object->displayName,
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PublicKeyCredentialUserEntity;
    }
}
