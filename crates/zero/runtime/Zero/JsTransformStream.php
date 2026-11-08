<?php

declare(strict_types=1);

namespace Zero;

/**
 * WHATWG TransformStream: a writable side whose writes run `transform(chunk, controller)` and a
 * readable side that receives what the controller enqueues. `flush` runs on close.
 */
final class JsTransformStream
{
    public JsReadableStream $readable;
    public JsWritableStream $writable;

    public function __construct(mixed $transformer = JsUndefined::Value)
    {
        $transformer = Js::isNullish($transformer) ? new JsObject() : $transformer;
        $readableController = null;
        $this->readable = new JsReadableStream(new JsObject([
            'start' => static function ($controller) use (&$readableController) { $readableController = $controller; return JsUndefined::Value; },
        ]), new JsObject(['highWaterMark' => 0]));
        $controller = new JsObject([
            'enqueue' => static function (mixed $chunk = JsUndefined::Value) use (&$readableController) {
                return Js::dynamicCallMethod($readableController, 'enqueue', new JsArray([$chunk]));
            },
            'error' => static function (mixed $reason = JsUndefined::Value) use (&$readableController) {
                return Js::dynamicCallMethod($readableController, 'error', new JsArray([$reason]));
            },
            'terminate' => static function () use (&$readableController) {
                return Js::dynamicCallMethod($readableController, 'close', new JsArray());
            },
        ]);
        $method = static function (string $name) use ($transformer): ?\Closure {
            $value = Js::dynamicGet($transformer, $name);
            return $value instanceof \Closure ? $value : null;
        };
        $start = $method('start');
        if ($start !== null) { Js::invokeWithReceiver($start, $transformer, [$controller]); }
        $transform = $method('transform');
        $flush = $method('flush');
        $this->writable = new JsWritableStream(new JsObject([
            'write' => static function ($chunk) use ($transform, $transformer, $controller, &$readableController) {
                if ($transform === null) { return Js::dynamicCallMethod($controller, 'enqueue', new JsArray([$chunk])); }
                // TransformStreamDefaultControllerPerformTransform: a failure errors the readable side too.
                $fail = static function ($reason) use (&$readableController) {
                    Js::dynamicCallMethod($readableController, 'error', new JsArray([$reason]));
                    throw Js::throwable($reason);
                };
                try {
                    $result = Js::invokeWithReceiver($transform, $transformer, [$chunk, $controller]);
                } catch (JsError $error) {
                    $fail(Js::thrownValue($error));
                }
                return JsPromise::from($result)->then(null, $fail);
            },
            'close' => static function () use ($flush, $transformer, $controller, &$readableController) {
                $done = $flush === null ? JsUndefined::Value : Js::invokeWithReceiver($flush, $transformer, [$controller]);
                return JsPromise::from($done)->then(static function () use (&$readableController) {
                    Js::dynamicCallMethod($readableController, 'close', new JsArray());
                    return JsUndefined::Value;
                });
            },
            'abort' => static function ($reason) use (&$readableController) {
                return Js::dynamicCallMethod($readableController, 'error', new JsArray([$reason]));
            },
        ]));
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'readable' => $this->readable,
            'writable' => $this->writable,
            default => JsUndefined::Value,
        };
    }
}
