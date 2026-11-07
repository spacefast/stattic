<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\AttestationStatement;

trait AttestationStatementSupportManagerAwareTrait
{
    private AttestationStatementSupportManager $attestationStatementSupportManager;

    public function setAttestationStatementSupportManager(AttestationStatementSupportManager $manager): void
    {
        $this->attestationStatementSupportManager = $manager;
    }
}
