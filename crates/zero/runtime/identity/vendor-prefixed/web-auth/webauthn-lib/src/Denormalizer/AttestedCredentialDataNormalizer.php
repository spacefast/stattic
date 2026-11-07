<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use function assert;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zero\Identity\Vendor\Webauthn\AttestedCredentialData;

final class AttestedCredentialDataNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        assert($data instanceof AttestedCredentialData);
        $result = [
            'aaguid' => $this->normalizer->normalize($data->aaguid, $format, $context),
            'credentialId' => base64_encode($data->credentialId),
        ];
        if ($data->credentialPublicKey !== null) {
            $result['credentialPublicKey'] = base64_encode($data->credentialPublicKey);
        }

        return $result;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof AttestedCredentialData;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            AttestedCredentialData::class => true,
        ];
    }
}
