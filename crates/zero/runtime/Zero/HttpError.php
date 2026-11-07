<?php

declare(strict_types=1);

namespace Zero;

/** An error that maps directly onto an RFC 9457 problem response. */
class HttpError extends \Exception
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $pointer = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidInput(string $message, ?string $pointer = null): self
    {
        return new self(422, 'invalid_input', $message, $pointer);
    }

    public static function invalidQuery(string $message, ?string $pointer = null): self
    {
        return new self(400, 'invalid_query', $message, $pointer);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'not_found', $message);
    }

    /** RFC 6901 pointer segment. */
    public static function seg(string|int $key): string
    {
        return '/' . strtr((string) $key, ['~' => '~0', '/' => '~1']);
    }
}
