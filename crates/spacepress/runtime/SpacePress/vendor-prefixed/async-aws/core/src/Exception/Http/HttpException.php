<?php

declare(strict_types=1);

namespace SpacePress\Vendor\AsyncAws\Core\Exception\Http;

use SpacePress\Vendor\AsyncAws\Core\Exception\Exception;
use SpacePress\Vendor\Symfony\Contracts\HttpClient\ResponseInterface;

interface HttpException extends Exception
{
    public function getResponse(): ResponseInterface;

    public function getAwsCode(): ?string;

    public function getAwsType(): ?string;

    public function getAwsMessage(): ?string;

    public function getAwsDetail(): ?string;
}
