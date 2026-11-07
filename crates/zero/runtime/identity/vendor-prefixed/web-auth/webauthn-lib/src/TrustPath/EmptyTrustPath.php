<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\TrustPath;

final readonly class EmptyTrustPath implements TrustPath
{
    public static function create(): self
    {
        return new self();
    }
}
