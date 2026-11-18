<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Signal;

use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialDescriptor;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialRpEntity;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialUserEntity;

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
