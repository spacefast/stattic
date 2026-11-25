<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\MetadataService;

use SpacePress\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

interface MetadataStatementRepository
{
    public function findOneByAAGUID(string $aaguid): ?MetadataStatement;
}
