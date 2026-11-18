<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\AttestationStatement;

interface AttestationStatementSupportManagerAwareInterface
{
    public function setAttestationStatementSupportManager(AttestationStatementSupportManager $manager): void;
}
