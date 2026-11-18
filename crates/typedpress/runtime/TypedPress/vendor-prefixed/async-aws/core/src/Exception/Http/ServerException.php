<?php

declare(strict_types=1);

namespace TypedPress\Vendor\AsyncAws\Core\Exception\Http;

use TypedPress\Vendor\Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

/**
 * Represents a 5xx response.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ServerException extends \RuntimeException implements HttpException, ServerExceptionInterface
{
    use HttpExceptionTrait;
}
