<?php

namespace Zero\Vendor\AsyncAws\S3\Exception;

use Zero\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The specified multipart upload does not exist.
 */
final class NoSuchUploadException extends ClientException
{
}
