<?php

declare(strict_types=1);

namespace TypedPress\Vendor\AsyncAws\Core\Exception\Http;

use TypedPress\Vendor\AsyncAws\Core\Exception\Exception;
use TypedPress\Vendor\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Request could not be sent due network error.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class NetworkException extends \RuntimeException implements Exception, TransportExceptionInterface
{
}
