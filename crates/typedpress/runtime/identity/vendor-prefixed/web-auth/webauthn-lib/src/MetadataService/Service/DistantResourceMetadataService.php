<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\MetadataService\Service;

use TypedPress\Identity\Vendor\ParagonIE\ConstantTime\Base64;
use TypedPress\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
use function sprintf;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\Encoder\JsonEncoder;
use TypedPress\Identity\Vendor\Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TypedPress\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatementSupportManager;
use TypedPress\Identity\Vendor\Webauthn\Denormalizer\WebauthnSerializerFactory;
use TypedPress\Identity\Vendor\Webauthn\Event\CanDispatchEvents;
use TypedPress\Identity\Vendor\Webauthn\Event\MetadataStatementFound;
use TypedPress\Identity\Vendor\Webauthn\Event\NullEventDispatcher;
use TypedPress\Identity\Vendor\Webauthn\Exception\MetadataStatementLoadingException;
use TypedPress\Identity\Vendor\Webauthn\Exception\MissingMetadataStatementException;
use TypedPress\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

final class DistantResourceMetadataService implements MetadataService, CanDispatchEvents
{
    private ?MetadataStatement $statement = null;

    private EventDispatcherInterface $dispatcher;

    private readonly SerializerInterface $serializer;

    /**
     * @param array<string, string> $additionalHeaderParameters
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $uri,
        private readonly bool $isBase64Encoded = false,
        private readonly array $additionalHeaderParameters = [],
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer = $serializer ?? (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create()
        ))->create();
        $this->dispatcher = new NullEventDispatcher();
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->dispatcher = $eventDispatcher;
    }

    /**
     * @param array<string, string> $additionalHeaderParameters
     */
    public static function create(
        HttpClientInterface $httpClient,
        string $uri,
        bool $isBase64Encoded = false,
        array $additionalHeaderParameters = [],
        ?SerializerInterface $serializer = null
    ): self {
        return new self($httpClient, $uri, $isBase64Encoded, $additionalHeaderParameters, $serializer);
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

        $content = $this->fetch();
        if ($this->isBase64Encoded) {
            $content = Base64::decode($content, true);
        }
        $this->statement = $this->serializer->deserialize($content, MetadataStatement::class, JsonEncoder::FORMAT);
    }

    private function fetch(): string
    {
        $response = $this->httpClient->request('GET', $this->uri, [
            'headers' => $this->additionalHeaderParameters,
        ]);
        $response->getStatusCode() === 200 || throw MetadataStatementLoadingException::create(sprintf(
            'Unable to contact the server. Response code is %d',
            $response->getStatusCode()
        ));

        $content = $response->getContent();
        $content !== '' || throw MetadataStatementLoadingException::create(
            'Unable to contact the server. The response has no content'
        );

        return $content;
    }
}
