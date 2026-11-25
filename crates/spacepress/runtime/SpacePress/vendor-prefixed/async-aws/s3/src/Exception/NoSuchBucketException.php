<?php

namespace SpacePress\Vendor\AsyncAws\S3\Exception;

use SpacePress\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The specified bucket does not exist.
 */
final class NoSuchBucketException extends ClientException
{
}
