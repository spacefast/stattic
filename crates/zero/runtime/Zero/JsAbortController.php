<?php
declare(strict_types=1);
namespace Zero;

/** DOM `AbortController`: owns one AbortSignal. */
final class JsAbortController
{
    public readonly JsAbortSignal $signal;

    public function __construct() { $this->signal = new JsAbortSignal(); }

    public function abort(mixed $reason = JsUndefined::Value): JsUndefined
    {
        $this->signal->signalAbort($reason);
        return JsUndefined::Value;
    }
}
