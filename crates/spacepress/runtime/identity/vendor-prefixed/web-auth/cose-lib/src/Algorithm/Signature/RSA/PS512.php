<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Cose\Algorithm\Signature\RSA;

use SpacePress\Identity\Vendor\Cose\Hash;
use SpacePress\Identity\Vendor\Cose\Key\RsaKeyValidator;

final class PS512 extends PSSRSA
{
    public const ID = -39;

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
        return Hash::sha512();
    }
}
