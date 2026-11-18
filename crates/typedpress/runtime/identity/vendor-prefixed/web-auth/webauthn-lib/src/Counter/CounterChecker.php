<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Counter;

use TypedPress\Identity\Vendor\Webauthn\CredentialRecord;

interface CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void;
}
