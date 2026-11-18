<?php

namespace TypedPress\Vendor\AsyncAws\Core\HttpClient;

use TypedPress\Vendor\Psr\Log\LoggerInterface;
use TypedPress\Vendor\Symfony\Component\HttpClient\HttpClient;
use TypedPress\Vendor\Symfony\Component\HttpClient\RetryableHttpClient;
use TypedPress\Vendor\Symfony\Contracts\HttpClient\HttpClientInterface;

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
