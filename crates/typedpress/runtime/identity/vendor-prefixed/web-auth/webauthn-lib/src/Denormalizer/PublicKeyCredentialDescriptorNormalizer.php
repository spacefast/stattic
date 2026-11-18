<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Denormalizer;

use function assert;
use function count;
use TypedPress\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;

final class PublicKeyCredentialDescriptorNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        assert($data instanceof PublicKeyCredentialDescriptor);
        $result = [
            'type' => $data->type,
            'id' => Base64UrlSafe::encodeUnpadded($data->id),
        ];
        if (count($data->transports) !== 0) {
            $result['transports'] = $data->transports;
        }

        return $result;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PublicKeyCredentialDescriptor;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            PublicKeyCredentialDescriptor::class => true,
        ];
    }
}
