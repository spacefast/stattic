<?php

declare(strict_types=1);

namespace Zero\Vendor\AsyncAws\Core\Exception\Http;

use Zero\Vendor\AsyncAws\Core\Exception\Exception;
use Zero\Vendor\Symfony\Contracts\HttpClient\ResponseInterface;

interface HttpException extends Exception
{
    public function getResponse(): ResponseInterface;

    public function getAwsCode(): ?string;

    public function getAwsType(): ?string;

    public function getAwsMessage(): ?string;

    public function getAwsDetail(): ?string;
}
