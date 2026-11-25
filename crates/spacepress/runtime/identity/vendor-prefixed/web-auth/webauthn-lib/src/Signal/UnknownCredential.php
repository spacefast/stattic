<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Signal;

use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;

readonly class UnknownCredential implements Signal
{
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialDescriptor $credential,
    ) {
    }
}
