<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Denormalizer;

use RuntimeException;
use function sprintf;
use Zero\Identity\Vendor\Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Zero\Identity\Vendor\Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Zero\Identity\Vendor\Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Encoder\JsonEncoder;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Serializer;
use Zero\Identity\Vendor\Symfony\Component\Serializer\SerializerInterface;
use Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatementSupportManager;

final readonly class WebauthnSerializerFactory
{
    private const PACKAGE_SYMFONY_PROPERTY_INFO = 'symfony/property-info';

    private const PACKAGE_SYMFONY_SERIALIZER = 'symfony/serializer';

    private const PACKAGE_PHPDOCUMENTOR_REFLECTION_DOCBLOCK = 'phpdocumentor/reflection-docblock';

    public function __construct(
        private AttestationStatementSupportManager $attestationStatementSupportManager
    ) {
    }

    public function create(): SerializerInterface
    {
        foreach (self::getRequiredSerializerClasses() as $class => $package) {
            if (! class_exists($class)) {
                throw new RuntimeException(sprintf(
                    'The class "%s" is required. Please install the package "%s" to use this feature.',
                    $class,
                    $package
                ));
            }
        }

        $denormalizers = [
            new ExtensionDescriptorDenormalizer(),
            new VerificationMethodANDCombinationsDenormalizer(),
            new AuthenticationExtensionNormalizer(),
            new PublicKeyCredentialDescriptorNormalizer(),
            new AttestedCredentialDataNormalizer(),
            new UrlNormalizer(),
            new AttestationObjectDenormalizer(),
            new AttestationStatementDenormalizer($this->attestationStatementSupportManager),
            new AuthenticationExtensionsDenormalizer(),
            new AuthenticatorAssertionResponseDenormalizer(),
            new AuthenticatorAttestationResponseDenormalizer(),
            new AuthenticatorDataDenormalizer(),
            new AuthenticatorResponseDenormalizer(),
            new CollectedClientDataDenormalizer(),
            new PublicKeyCredentialDenormalizer(),
            new PublicKeyCredentialOptionsDenormalizer(),
            new CredentialRecordDenormalizer(),
            new PublicKeyCredentialSourceDenormalizer(),
            new PublicKeyCredentialRpEntityDenormalizer(),
            new PublicKeyCredentialUserEntityDenormalizer(),
            new SignalAllAcceptedCredentialsDenormalizer(),
            new SignalCurrentUserDetailsDenormalizer(),
            new SignalUnknownCredentialDenormalizer(),
            new TrustPathDenormalizer(),
            new UidNormalizer(),
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                propertyTypeExtractor: new PropertyInfoExtractor(typeExtractors: [
                    new PhpDocExtractor(),
                    new ReflectionExtractor(),
                ])
            ),
        ];

        return new Serializer($denormalizers, [new JsonEncoder()]);
    }

    /**
     * @return array<class-string, string>
     */
    private static function getRequiredSerializerClasses(): array
    {
        return [
            UidNormalizer::class => self::PACKAGE_SYMFONY_SERIALIZER,
            ArrayDenormalizer::class => self::PACKAGE_SYMFONY_SERIALIZER,
            AbstractNormalizer::class => self::PACKAGE_SYMFONY_SERIALIZER,
            PropertyInfoExtractor::class => self::PACKAGE_SYMFONY_PROPERTY_INFO,
            PhpDocExtractor::class => self::PACKAGE_PHPDOCUMENTOR_REFLECTION_DOCBLOCK,
            ReflectionExtractor::class => self::PACKAGE_SYMFONY_PROPERTY_INFO,
            JsonEncoder::class => self::PACKAGE_SYMFONY_SERIALIZER,
            Serializer::class => self::PACKAGE_SYMFONY_SERIALIZER,
        ];
    }
}
