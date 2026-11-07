<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Counter;

use Zero\Identity\Vendor\Webauthn\CredentialRecord;

interface CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void;
}
