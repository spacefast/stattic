<?php

namespace TypedPress\Vendor\AsyncAws\S3\Exception;

use TypedPress\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The specified bucket does not exist.
 */
final class NoSuchBucketException extends ClientException
{
}
