<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use function array_key_exists;
use Zero\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Zero\Identity\Vendor\Webauthn\AuthenticatorResponse;
use Zero\Identity\Vendor\Webauthn\Exception\InvalidDataException;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredential;
use Zero\Identity\Vendor\Webauthn\Util\Base64;

final class PublicKeyCredentialDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{id?: string, rawId: string, type: string, response: array<string, mixed>} $data */
        if (! array_key_exists('id', $data)) {
            return $data;
        }
        $id = Base64UrlSafe::decodeNoPadding($data['id']);
        $rawId = Base64::decode($data['rawId']);
        hash_equals($id, $rawId) || throw InvalidDataException::create($data, 'Invalid ID');
        $data['rawId'] = $rawId;

        /** @var AuthenticatorResponse $response */
        $response = $this->denormalizer->denormalize(
            $data['response'],
            AuthenticatorResponse::class,
            $format,
            $context
        );

        return PublicKeyCredential::create($data['type'], $data['rawId'], $response);
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === PublicKeyCredential::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            PublicKeyCredential::class => true,
        ];
    }
}
