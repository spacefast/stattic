<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use function assert;
use function count;
use Zero\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;

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
