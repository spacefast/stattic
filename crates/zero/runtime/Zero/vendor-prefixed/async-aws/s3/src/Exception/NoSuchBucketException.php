<?php

namespace Zero\Vendor\AsyncAws\S3\Exception;

use Zero\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The specified bucket does not exist.
 */
final class NoSuchBucketException extends ClientException
{
}
