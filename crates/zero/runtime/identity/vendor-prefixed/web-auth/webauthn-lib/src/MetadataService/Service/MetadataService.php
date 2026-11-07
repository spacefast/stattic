<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\Webauthn\MetadataService\Service;

use Zero\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

interface MetadataService
{
    /**
     * @return string[] The list of AAGUID supported by the service
     */
    public function list(): iterable;

    public function has(string $aaguid): bool;

    public function get(string $aaguid): MetadataStatement;
}
