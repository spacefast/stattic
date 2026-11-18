<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\AuthenticationExtensions;

interface ExtensionOutputChecker
{
    public function check(AuthenticationExtensions $inputs, AuthenticationExtensions $outputs): void;
}
