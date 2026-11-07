<?php

namespace Zero\Vendor\AsyncAws\S3\Exception;

use Zero\Vendor\AsyncAws\Core\Exception\Http\ClientException;

/**
 * The source object of the COPY action is not in the active tier and is only stored in Amazon S3 Glacier.
 */
final class ObjectNotInActiveTierErrorException extends ClientException
{
}
