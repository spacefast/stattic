<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\MetadataService;

use Zero\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

interface MetadataStatementRepository
{
    public function findOneByAAGUID(string $aaguid): ?MetadataStatement;
}
