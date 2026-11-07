<?php

namespace Zero\Vendor\AsyncAws\Core\Sts\Exception;

use Zero\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The web identity token that was passed is expired or is not valid. Get a new identity token from the identity
 * provider and then retry the request.
 */
final class ExpiredTokenException extends ClientException
{
}
