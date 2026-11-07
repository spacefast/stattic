<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use Zero\Identity\Vendor\CBOR\Decoder;
use Zero\Identity\Vendor\CBOR\Normalizable;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationObject;
use Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatement;
use Zero\Identity\Vendor\Webauthn\AuthenticatorData;
use Zero\Identity\Vendor\Webauthn\Exception\InvalidDataException;
use Zero\Identity\Vendor\Webauthn\StringStream;

final class AttestationObjectDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{
    use DenormalizerAwareTrait;

    /**
     * @throws InvalidDataException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        /** @var string $data */
        $stream = new StringStream($data);
        $parsed = Decoder::create()->decode($stream);

        $parsed instanceof Normalizable || throw InvalidDataException::create(
            $parsed,
            'Invalid attestation object. Unexpected object.'
        );
        /** @var array<string, mixed> $attestationObject */
        $attestationObject = $parsed->normalize();
        $stream->isEOF() || throw InvalidDataException::create(
            null,
            'Invalid attestation object. Presence of extra bytes.'
        );
        $stream->close();
        $authData = $attestationObject['authData'] ?? throw InvalidDataException::create(
            $attestationObject,
            'Invalid attestation object. Missing "authData" field.'
        );

        return AttestationObject::create(
            $data,
            $this->denormalizer->denormalize($attestationObject, AttestationStatement::class, $format, $context),
            $this->denormalizer->denormalize($authData, AuthenticatorData::class, $format, $context),
        );
    }

    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return $type === AttestationObject::class;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            AttestationObject::class => true,
        ];
    }
}
