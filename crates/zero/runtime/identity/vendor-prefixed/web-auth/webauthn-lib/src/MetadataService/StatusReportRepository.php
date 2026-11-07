<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\MetadataService;

use Zero\Identity\Vendor\Webauthn\MetadataService\Statement\StatusReport;

interface StatusReportRepository
{
    /**
     * @return StatusReport[]
     */
    public function findStatusReportsByAAGUID(string $aaguid): array;
}
