<?php

namespace SpacePress\Vendor\AsyncAws\S3\Exception;

use SpacePress\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The write offset value that you specified does not match the current object size.
 */
final class InvalidWriteOffsetException extends ClientException
{
}
