<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\AuthenticationExtensions;

final class AppIdInputExtension extends AuthenticationExtension
{
    public static function enable(): AuthenticationExtension
    {
        return self::create('appid', true);
    }

    public static function disable(): AuthenticationExtension
    {
        return self::create('appid', false);
    }
}
