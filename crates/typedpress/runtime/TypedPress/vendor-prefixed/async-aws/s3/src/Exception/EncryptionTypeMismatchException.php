<?php

namespace TypedPress\Vendor\AsyncAws\S3\Exception;

use TypedPress\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The existing object was created with a different encryption type. Subsequent write requests must include the
 * appropriate encryption parameters in the request or while creating the session.
 */
final class EncryptionTypeMismatchException extends ClientException
{
}
