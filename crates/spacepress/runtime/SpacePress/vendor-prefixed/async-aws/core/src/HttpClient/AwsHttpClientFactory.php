<?php

namespace SpacePress\Vendor\AsyncAws\Core\HttpClient;

use SpacePress\Vendor\Psr\Log\LoggerInterface;
use SpacePress\Vendor\Symfony\Component\HttpClient\HttpClient;
use SpacePress\Vendor\Symfony\Component\HttpClient\RetryableHttpClient;
use SpacePress\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class AwsHttpClientFactory
{
    public static function createRetryableClient(?HttpClientInterface $httpClient = null, ?LoggerInterface $logger = null): HttpClientInterface
    {
        if (null === $httpClient) {
            $httpClient = HttpClient::create();
        }
        if (class_exists(RetryableHttpClient::class)) {
            /** @psalm-suppress MissingDependency */
            $httpClient = new RetryableHttpClient(
                $httpClient,
                new AwsRetryStrategy(),
                3,
                $logger
            );
        }

        return $httpClient;
    }
}
