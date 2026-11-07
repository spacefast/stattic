<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Signal;

use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialUserEntity;

readonly class CurrentUserDetails implements Signal
{
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialUserEntity $user,
    ) {
    }
}
