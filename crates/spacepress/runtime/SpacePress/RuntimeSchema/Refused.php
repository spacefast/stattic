<?php

declare(strict_types=1);

namespace SpacePress\RuntimeSchema;

/**
 * A runtime schema change that isn't applied (spec §2.10). `diagnostic` is the compiler's code
 * (`F3001`, …) when the compiler refuses the same change in source; refusals only a running site
 * makes have their own problem code and no diagnostic.
 */
final class Refused extends \SpacePress\HttpError
{
    public function __construct(string $code, string $message, public readonly ?string $diagnostic = null, ?string $pointer = null, int $status = 422)
    {
        parent::__construct($status, $code, $message, $pointer);
    }

    /** A rule the compiler has too. */
    public static function rule(string $diagnostic, string $at, string $message): self
    {
        return new self('schema_invalid', "$at: $message", $diagnostic);
    }

    /** What the corpus records for this refusal: the diagnostic, or the runtime's own code. */
    public function verdict(): string
    {
        return $this->diagnostic ?? $this->errorCode;
    }
}
