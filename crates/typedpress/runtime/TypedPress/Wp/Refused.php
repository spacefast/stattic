<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;

/** An editor save TypedPress's rules refuse (EditorSave): carries the problem the caller answers with. */
final class Refused extends \RuntimeException
{
    public function __construct(public readonly HttpError $error)
    {
        parent::__construct($error->getMessage());
    }
}
