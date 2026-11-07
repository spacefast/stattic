<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Cose\Algorithm\Signature\ECDSA;

use Zero\Identity\Vendor\Cose\Key\Ec2Key;
use const OPENSSL_ALGO_SHA256;

final class ES256K extends ECDSA
{
    public const ID = -47;

    public static function create(): self
    {
        return new self();
    }

    public static function identifier(): int
    {
        return self::ID;
    }

    protected function getHashAlgorithm(): int
    {
        return OPENSSL_ALGO_SHA256;
    }

    protected function getCurve(): int
    {
        return Ec2Key::CURVE_P256K;
    }

    protected function getSignaturePartLength(): int
    {
        return 64;
    }
}
