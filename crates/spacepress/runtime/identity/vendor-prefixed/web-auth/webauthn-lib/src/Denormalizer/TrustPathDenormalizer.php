<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Denormalizer;

use function array_key_exists;
use function assert;
use function is_array;
use SpacePress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use SpacePress\Identity\Vendor\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use SpacePress\Identity\Vendor\Webauthn\Exception\InvalidTrustPathException;
use SpacePress\Identity\Vendor\Webauthn\TrustPath\CertificateTrustPath;
use SpacePress\Identity\Vendor\Webauthn\TrustPath\EmptyTrustPath;
use SpacePress\Identity\Vendor\Webauthn\TrustPath\TrustPath;

final class TrustPathDenormalizer implements DenormalizerInterface, NormalizerInterface
{
    /**
     * @throws InvalidTrustPathException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var array{x5c?: list<string>, type?: string} $data */
        return match (true) {
            array_key_exists('x5c', $data) && is_array($data['x5c']) => CertificateTrustPath::create($data['x5c']),
            $data === [], isset($data['type']) && $data['type'] === EmptyTrustPath::class => EmptyTrustPath::create(),
            default => throw new InvalidTrustPathException('Unsupported trust path type'),
        };
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === TrustPath::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            TrustPath::class => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        assert($data instanceof TrustPath);
        return match (true) {
            $data instanceof CertificateTrustPath => [
                'x5c' => $data->certificates,
            ],
            $data instanceof EmptyTrustPath => [],
            default => throw new InvalidTrustPathException('Unsupported trust path type'),
        };
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof TrustPath;
    }
}
