<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Failure extends \RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
