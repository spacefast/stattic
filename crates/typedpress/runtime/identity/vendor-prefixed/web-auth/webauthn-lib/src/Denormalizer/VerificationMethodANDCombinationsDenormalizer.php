<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Denormalizer;

use function assert;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use TypedPress\Identity\Vendor\Webauthn\MetadataService\Statement\VerificationMethodANDCombinations;
use TypedPress\Identity\Vendor\Webauthn\MetadataService\Statement\VerificationMethodDescriptor;

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
