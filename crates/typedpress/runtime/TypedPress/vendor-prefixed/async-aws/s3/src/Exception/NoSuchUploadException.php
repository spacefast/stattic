<?php

namespace TypedPress\Vendor\AsyncAws\S3\Exception;

use TypedPress\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The specified multipart upload does not exist.
 */
final class NoSuchUploadException extends ClientException
{
}
