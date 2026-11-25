<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\MetadataService\Service;

use function assert;
use function file_get_contents;
use SpacePress\Identity\Vendor\ParagonIE\ConstantTime\Base64;
use SpacePress\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
use SpacePress\Identity\Vendor\Symfony\Component\Serializer\Encoder\JsonEncoder;
use SpacePress\Identity\Vendor\Symfony\Component\Serializer\SerializerInterface;
use SpacePress\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatementSupportManager;
use SpacePress\Identity\Vendor\Webauthn\Denormalizer\WebauthnSerializerFactory;
use SpacePress\Identity\Vendor\Webauthn\Event\CanDispatchEvents;
use SpacePress\Identity\Vendor\Webauthn\Event\MetadataStatementFound;
use SpacePress\Identity\Vendor\Webauthn\Event\NullEventDispatcher;
use SpacePress\Identity\Vendor\Webauthn\Exception\MetadataStatementLoadingException;
use SpacePress\Identity\Vendor\Webauthn\Exception\MissingMetadataStatementException;
use SpacePress\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

final class LocalResourceMetadataService implements MetadataService, CanDispatchEvents
{
    private ?MetadataStatement $statement = null;

    private EventDispatcherInterface $dispatcher;

    private readonly SerializerInterface $serializer;

    public function __construct(
        private readonly string $filename,
        private readonly bool $isBase64Encoded = false,
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer = $serializer ?? (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create()
        ))->create();
        $this->dispatcher = new NullEventDispatcher();
    }

    public static function create(
        string $filename,
        bool $isBase64Encoded = false,
        ?SerializerInterface $serializer = null
    ): self {
        return new self($filename, $isBase64Encoded, $serializer);
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->dispatcher = $eventDispatcher;
    }

    public function list(): iterable
    {
        $this->loadData();
        $this->statement !== null || throw MetadataStatementLoadingException::create();
        $aaguid = $this->statement->aaguid;
        if ($aaguid === null) {
            yield from [];
        } else {
            yield from [$aaguid];
        }
    }

    public function has(string $aaguid): bool
    {
        $this->loadData();
        $this->statement !== null || throw MetadataStatementLoadingException::create();

        return $aaguid === $this->statement->aaguid;
    }

    public function get(string $aaguid): MetadataStatement
    {
        $this->loadData();
        $this->statement !== null || throw MetadataStatementLoadingException::create();

        if ($aaguid === $this->statement->aaguid) {
            $this->dispatcher->dispatch(MetadataStatementFound::create($this->statement));

            return $this->statement;
        }

        throw MissingMetadataStatementException::create($aaguid);
    }

    private function loadData(): void
    {
        if ($this->statement !== null) {
            return;
        }

        $content = file_get_contents($this->filename);
        assert($content !== false, 'The file could not be read');
        if ($this->isBase64Encoded) {
            $content = Base64::decode($content, true);
        }
        $this->statement = $this->serializer->deserialize($content, MetadataStatement::class, JsonEncoder::FORMAT);
    }
}
