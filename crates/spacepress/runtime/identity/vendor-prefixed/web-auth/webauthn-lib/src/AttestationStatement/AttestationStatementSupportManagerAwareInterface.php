<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\AttestationStatement;

interface AttestationStatementSupportManagerAwareInterface
{
    public function setAttestationStatementSupportManager(AttestationStatementSupportManager $manager): void;
}
