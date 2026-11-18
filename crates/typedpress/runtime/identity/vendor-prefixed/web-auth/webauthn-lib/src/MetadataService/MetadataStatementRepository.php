<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\MetadataService;

use TypedPress\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

interface MetadataStatementRepository
{
    public function findOneByAAGUID(string $aaguid): ?MetadataStatement;
}
