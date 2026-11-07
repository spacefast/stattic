<?php

declare(strict_types=1);

namespace Zero\Vendor\AsyncAws\Core\Exception\Http;

use Zero\Vendor\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

/**
 * Represents a 4xx response.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ClientException extends \RuntimeException implements ClientExceptionInterface, HttpException
{
    use HttpExceptionTrait;
}
