<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\AuthenticationExtensions;

use Exception;
use Throwable;

class ExtensionOutputError extends Exception
{
    public function __construct(
        public readonly AuthenticationExtension $authenticationExtension,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
