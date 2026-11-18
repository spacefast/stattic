<?php

namespace TypedPress\Vendor\AsyncAws\S3\Exception;

use TypedPress\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The specified key does not exist.
 */
final class NoSuchKeyException extends ClientException
{
}
