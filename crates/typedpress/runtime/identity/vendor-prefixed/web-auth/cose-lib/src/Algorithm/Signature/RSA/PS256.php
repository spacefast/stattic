<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Cose\Algorithm\Signature\RSA;

use TypedPress\Identity\Vendor\Cose\Hash;
use TypedPress\Identity\Vendor\Cose\Key\RsaKeyValidator;

final class PS256 extends PSSRSA
{
    public const ID = -37;

    public static function create(?RsaKeyValidator $keyValidator = null): self
    {
        return new self($keyValidator);
    }

    public static function identifier(): int
    {
        return self::ID;
    }

    protected function getHashAlgorithm(): Hash
    {
        return Hash::sha256();
    }
}
