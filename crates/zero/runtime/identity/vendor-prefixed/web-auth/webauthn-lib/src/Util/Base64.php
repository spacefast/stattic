<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Util;

use Zero\Identity\Vendor\ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Zero\Identity\Vendor\Webauthn\Exception\InvalidDataException;

abstract class Base64
{
    public static function decode(string $data): string
    {
        try {
            return Base64UrlSafe::decode($data);
        } catch (Throwable) {
        }

        try {
            return \Zero\Identity\Vendor\ParagonIE\ConstantTime\Base64::decode($data);
        } catch (Throwable $e) {
            throw InvalidDataException::create($data, 'Invalid data submitted', $e);
        }
    }
}
