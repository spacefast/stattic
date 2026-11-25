<?php

declare(strict_types=1);

namespace SpacePress\Vendor\AsyncAws\Core\Exception\Http;

use SpacePress\Vendor\Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

/**
 * Represents a 3xx response.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class RedirectionException extends \RuntimeException implements HttpException, RedirectionExceptionInterface
{
    use HttpExceptionTrait;
}
