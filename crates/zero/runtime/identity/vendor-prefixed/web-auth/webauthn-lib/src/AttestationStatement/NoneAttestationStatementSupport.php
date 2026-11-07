<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\AttestationStatement;

use function count;
use function is_array;
use function is_string;
use Zero\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
use Zero\Identity\Vendor\Webauthn\AuthenticatorData;
use Zero\Identity\Vendor\Webauthn\Event\AttestationStatementLoaded;
use Zero\Identity\Vendor\Webauthn\Event\CanDispatchEvents;
use Zero\Identity\Vendor\Webauthn\Event\NullEventDispatcher;
use Zero\Identity\Vendor\Webauthn\Exception\AttestationStatementLoadingException;
use Zero\Identity\Vendor\Webauthn\TrustPath\EmptyTrustPath;

final class NoneAttestationStatementSupport implements AttestationStatementSupport, CanDispatchEvents
{
    private EventDispatcherInterface $dispatcher;

    public function __construct()
    {
        $this->dispatcher = new NullEventDispatcher();
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->dispatcher = $eventDispatcher;
    }

    public static function create(): self
    {
        return new self();
    }

    public function name(): string
    {
        return 'none';
    }

    /**
     * @param array<string, mixed> $attestation
     */
    public function load(array $attestation): AttestationStatement
    {
        $format = $attestation['fmt'] ?? null;
        $attestationStatement = $attestation['attStmt'] ?? [];

        (is_string($format) && $format !== '') || throw AttestationStatementLoadingException::create(
            $attestation,
            'Invalid attestation object'
        );
        (is_array(
            $attestationStatement
        ) && $attestationStatement === []) || throw AttestationStatementLoadingException::create(
            $attestation,
            'Invalid attestation object'
        );

        $attestationStatement = AttestationStatement::createNone(
            $format,
            $attestationStatement,
            EmptyTrustPath::create()
        );
        $this->dispatcher->dispatch(AttestationStatementLoaded::create($attestationStatement));

        return $attestationStatement;
    }

    public function isValid(
        string $clientDataJSONHash,
        AttestationStatement $attestationStatement,
        AuthenticatorData $authenticatorData
    ): bool {
        return count($attestationStatement->attStmt) === 0;
    }
}
