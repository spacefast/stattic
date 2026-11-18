<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Denormalizer;

use function array_key_exists;
use TypedPress\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use TypedPress\Identity\Vendor\Webauthn\AuthenticatorResponse;
use TypedPress\Identity\Vendor\Webauthn\Exception\InvalidDataException;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredential;
use TypedPress\Identity\Vendor\Webauthn\Util\Base64;

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
