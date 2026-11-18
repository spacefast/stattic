<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\MetadataService;

use TypedPress\Identity\Vendor\Psr\Log\LoggerInterface;

interface CanLogData
{
    public function setLogger(LoggerInterface $logger): void;
}
