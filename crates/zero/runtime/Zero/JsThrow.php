<?php

declare(strict_types=1);

namespace Zero;

/**
 * A JavaScript `throw` of a value that is not an Error (`throw "x"`, `Promise.reject(42)`).
 * Compiled checked-JavaScript `catch` blocks unwrap it back to the thrown value; typed
 * TypeScript catch blocks refuse it, because their binding is typed as an Error.
 */
final class JsThrow extends JsError
{
    public function __construct(public readonly mixed $value)
    {
        parent::__construct('Uncaught ' . Js::thrownDescription($value), 'Error');
    }
}
