<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use function assert;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Zero\Identity\Vendor\Webauthn\MetadataService\Statement\VerificationMethodANDCombinations;
use Zero\Identity\Vendor\Webauthn\MetadataService\Statement\VerificationMethodDescriptor;

final class VerificationMethodANDCombinationsDenormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            VerificationMethodANDCombinations::class => true,
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        assert($data instanceof VerificationMethodANDCombinations);

        /** @var array<array<string, mixed>> */
        return array_map(
            fn (VerificationMethodDescriptor $verificationMethod) => $this->normalizer->normalize(
                $verificationMethod,
                $format,
                $context
            ),
            $data->verificationMethods
        );
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof VerificationMethodANDCombinations;
    }
}
