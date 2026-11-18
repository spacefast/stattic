<?php
declare(strict_types=1);
namespace TypedPress;

/**
 * `C.prototype` of a compiled class. It holds no state of its own: string members
 * (methods, accessors, constructor) are the class descriptor's, and symbol-keyed
 * members live on the descriptor (see JsClass::defineSymbol). Reads, `in` and
 * Object.defineProperty with a symbol key are modeled; writes and deletes are refused.
 */
final class JsPrototype
{
    public function __construct(public readonly JsClass $class) {}
}
