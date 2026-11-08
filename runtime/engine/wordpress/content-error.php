<?php
declare(strict_types=1);

/** A content operation's refusal: the entrypoint answers it as a problem document. */
final class Spacefast_Content_Error extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $codeName,
        string $message,
    ) {
        parent::__construct($message);
    }
}
