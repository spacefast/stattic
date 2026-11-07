<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\Exception;

use Throwable;

final class AuthenticationExtensionException extends WebauthnException
{
    public static function create(string $message, ?Throwable $previous = null): self
    {
        return new self($message, $previous);
    }
}
