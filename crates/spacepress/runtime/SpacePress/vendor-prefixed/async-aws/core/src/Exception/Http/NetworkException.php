<?php

declare(strict_types=1);

namespace SpacePress\Vendor\AsyncAws\Core\Exception\Http;

use SpacePress\Vendor\AsyncAws\Core\Exception\Exception;
use SpacePress\Vendor\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Request could not be sent due network error.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class NetworkException extends \RuntimeException implements Exception, TransportExceptionInterface
{
}
