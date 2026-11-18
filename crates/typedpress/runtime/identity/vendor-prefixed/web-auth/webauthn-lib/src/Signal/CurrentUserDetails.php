<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Signal;

use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialUserEntity;

readonly class CurrentUserDetails implements Signal
{
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialUserEntity $user,
    ) {
    }
}
