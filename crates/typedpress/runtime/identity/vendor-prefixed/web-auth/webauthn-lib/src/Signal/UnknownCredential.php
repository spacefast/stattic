<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Signal;

use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;

readonly class UnknownCredential implements Signal
{
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialDescriptor $credential,
    ) {
    }
}
