<?php

namespace SpacePress\Vendor\AsyncAws\Core\AwsError;

use SpacePress\Vendor\Symfony\Contracts\HttpClient\ResponseInterface;

trait AwsErrorFactoryFromResponseTrait
{
    public function createFromResponse(ResponseInterface $response): AwsError
    {
        $content = $response->getContent(false);
        $headers = $response->getHeaders(false);

        return $this->createFromContent($content, $headers);
    }
}
