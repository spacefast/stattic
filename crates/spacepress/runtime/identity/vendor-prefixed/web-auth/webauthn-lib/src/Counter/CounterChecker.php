<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Counter;

use SpacePress\Identity\Vendor\Webauthn\CredentialRecord;

interface CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void;
}
