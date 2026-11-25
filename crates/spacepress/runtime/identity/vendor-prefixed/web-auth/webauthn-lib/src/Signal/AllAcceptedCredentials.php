<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Signal;

use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;
use SpacePress\Identity\Vendor\Webauthn\PublicKeyCredentialUserEntity;

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
