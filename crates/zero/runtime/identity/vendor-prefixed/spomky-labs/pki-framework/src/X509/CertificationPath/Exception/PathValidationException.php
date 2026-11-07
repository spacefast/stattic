<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\SpomkyLabs\Pki\X509\CertificationPath\Exception;

use Zero\Identity\Vendor\SpomkyLabs\Pki\X509\Exception\X509ValidationException;

/**
 * Exception thrown on certification path validation errors.
 */
final class PathValidationException extends X509ValidationException
{
}
