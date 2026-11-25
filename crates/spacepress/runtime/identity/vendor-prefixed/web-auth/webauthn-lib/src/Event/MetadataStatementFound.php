<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn\Event;

use SpacePress\Identity\Vendor\Webauthn\MetadataService\Statement\MetadataStatement;

final readonly class MetadataStatementFound implements WebauthnEvent
{
    public function __construct(
        public MetadataStatement $metadataStatement
    ) {
    }

    public static function create(MetadataStatement $metadataStatement): self
    {
        return new self($metadataStatement);
    }
}
