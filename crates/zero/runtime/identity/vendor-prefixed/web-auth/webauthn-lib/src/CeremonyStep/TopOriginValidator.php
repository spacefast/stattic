<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\CeremonyStep;

interface TopOriginValidator
{
    public function validate(string $topOrigin): void;
}
