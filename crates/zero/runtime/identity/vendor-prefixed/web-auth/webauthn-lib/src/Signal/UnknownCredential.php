<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Signal;

use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;

readonly class UnknownCredential implements Signal
{
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialDescriptor $credential,
    ) {
    }
}
