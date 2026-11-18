<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\MetadataService\Statement;

class AuthenticatorGetInfo
{
    /**
     * @param array<array-key, mixed> $info
     */
    public function __construct(
        public array $info = []
    ) {
    }

    /**
     * @param array<array-key, mixed> $info
     */
    public static function create(array $info = []): self
    {
        return new self($info);
    }
}
