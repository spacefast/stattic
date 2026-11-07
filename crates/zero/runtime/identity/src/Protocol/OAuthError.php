<?php

declare(strict_types=1);

namespace Zero\Identity\Protocol;

/** An RFC 6749 §5.2 token endpoint error: `{ error, error_description }` with its HTTP status. */
final class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public function body(): array
    {
        return ["error" => $this->error, "error_description" => $this->getMessage()];
    }
}
