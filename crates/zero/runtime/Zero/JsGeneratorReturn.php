<?php

declare(strict_types=1);

namespace Zero;

/**
 * `generator.return(value)`: thrown into a suspended PHP Generator so only its `finally` blocks run.
 * It is not a JsError, so compiled JavaScript `catch` blocks never see it.
 */
final class JsGeneratorReturn extends \Exception
{
    public function __construct(public readonly mixed $value)
    {
        parent::__construct('generator return');
    }
}
