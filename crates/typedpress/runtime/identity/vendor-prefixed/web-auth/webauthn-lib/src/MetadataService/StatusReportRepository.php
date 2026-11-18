<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\MetadataService;

use TypedPress\Identity\Vendor\Webauthn\MetadataService\Statement\StatusReport;

interface StatusReportRepository
{
    /**
     * @return StatusReport[]
     */
    public function findStatusReportsByAAGUID(string $aaguid): array;
}
