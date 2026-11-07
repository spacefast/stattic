<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\MetadataService\Service;

use function array_key_exists;
use Zero\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
use Zero\Identity\Vendor\Symfony\Component\Serializer\Encoder\JsonEncoder;
use Zero\Identity\Vendor\Symfony\Component\Serializer\SerializerInterface;
use Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Zero\Identity\Vendor\Webauthn\Denormalizer\WebauthnSerializerFactory;
use Zero\Identity\Vendor\Webauthn\Event\CanDispatchEvents;
use Zero\Identity\Vendor\Webauthn\Event\MetadataStatementFound;
use Zero\Identity\Vendor\Webauthn\Event\NullEventDispatcher;
use Zero\Identity\Vendor\Webauthn\Exception\MissingMetadataStatementException;
use Zero\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

final class JsonMetadataService implements MetadataService, CanDispatchEvents
{
    /**
     * @var MetadataStatement[]
     */
    private array $statements = [];

    private EventDispatcherInterface $dispatcher;

    private readonly SerializerInterface $serializer;

    /**
     * @param string[] $statements
     */
    public function __construct(array $statements, ?SerializerInterface $serializer = null)
    {
        $this->dispatcher = new NullEventDispatcher();
        $this->serializer = $serializer ?? (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create()
        ))->create();
        foreach ($statements as $statement) {
            $this->addStatement($statement);
        }
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->dispatcher = $eventDispatcher;
    }

    public function list(): iterable
    {
        yield from array_keys($this->statements);
    }

    public function has(string $aaguid): bool
    {
        return array_key_exists($aaguid, $this->statements);
    }

    public function get(string $aaguid): MetadataStatement
    {
        array_key_exists($aaguid, $this->statements) || throw MissingMetadataStatementException::create($aaguid);
        $mds = $this->statements[$aaguid];
        $this->dispatcher->dispatch(MetadataStatementFound::create($mds));

        return $mds;
    }

    private function addStatement(string $statement): void
    {
        $mds = $this->serializer->deserialize($statement, MetadataStatement::class, JsonEncoder::FORMAT);
        if ($mds->aaguid === null) {
            return;
        }
        $this->statements[$mds->aaguid] = $mds;
    }
}
