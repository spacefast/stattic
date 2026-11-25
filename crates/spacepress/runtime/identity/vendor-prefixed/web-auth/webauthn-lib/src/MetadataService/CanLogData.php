<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\MetadataService;

use SpacePress\Identity\Vendor\Psr\Log\LoggerInterface;

interface CanLogData
{
    public function setLogger(LoggerInterface $logger): void;
}
