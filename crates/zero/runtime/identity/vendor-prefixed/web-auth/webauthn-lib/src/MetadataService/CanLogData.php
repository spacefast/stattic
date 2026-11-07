<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\MetadataService;

use Zero\Identity\Vendor\Psr\Log\LoggerInterface;

interface CanLogData
{
    public function setLogger(LoggerInterface $logger): void;
}
