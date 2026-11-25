<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\MetadataService;

use SpacePress\Identity\Vendor\Webauthn\MetadataService\Statement\StatusReport;

interface StatusReportRepository
{
    /**
     * @return StatusReport[]
     */
    public function findStatusReportsByAAGUID(string $aaguid): array;
}
