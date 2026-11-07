<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Signal;

use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;
use Zero\Identity\Vendor\Webauthn\PublicKeyCredentialUserEntity;

readonly class AllAcceptedCredentials implements Signal
{
    /**
     * @param PublicKeyCredentialDescriptor[] $allAcceptedCredentials
     */
    public function __construct(
        public PublicKeyCredentialRpEntity $rp,
        public PublicKeyCredentialUserEntity $user,
        public array $allAcceptedCredentials,
    ) {
    }
}
