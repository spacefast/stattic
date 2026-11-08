<?php

declare(strict_types=1);

namespace Zero;

/**
 * WHATWG WritableStream with a default writer. Writes queue in order; each one waits for the
 * sink's previous `write` promise, so a sink sees one chunk at a time, as in the standard.
 */
final class JsWritableStream
{
    private const WRITABLE = 0;
    private const CLOSED = 1;
    private const ERRORED = 2;

    private int $state = self::WRITABLE;
    private mixed $storedError = JsUndefined::Value;
    private JsPromise $tail;
    private bool $closing = false;
    private ?JsObject $writer = null;
    private JsObject $controller;
    private mixed $sink;

    public function __construct(mixed $sink = JsUndefined::Value, mixed $strategy = JsUndefined::Value)
    {
        $this->sink = Js::isNullish($sink) ? new JsObject() : $sink;
        if (!Js::isNullish($strategy) && !Js::isNullish(Js::dynamicGet($strategy, 'size'))) {
            throw new JsError('WritableStream size() strategies are not supported', 'TypeError');
        }
        $this->controller = new JsObject(['error' => fn (mixed $e = JsUndefined::Value) => $this->fail($e)]);
        $start = $this->sinkMethod('start');
        $this->tail = JsPromise::from($start === null ? JsUndefined::Value : $this->callSink($start, [$this->controller]));
        $this->tail->then(null, function ($reason): JsUndefined { $this->fail($reason); return JsUndefined::Value; }, false);
    }

    /** A stream into a PHP stream resource: each chunk is written as bytes. */
    public static function toResource(mixed $handle): self
    {
        return new self(new JsObject([
            'write' => static function ($chunk) use ($handle) {
                $bytes = is_string($chunk) ? $chunk : (is_object($chunk) && method_exists($chunk, 'bytes') ? $chunk->bytes() : Js::str($chunk));
                if (fwrite($handle, $bytes) !== strlen($bytes)) { throw new JsError('The stream resource could not be written', 'TypeError'); }
                return JsUndefined::Value;
            },
        ]));
    }

    private function sinkMethod(string $name): ?\Closure
    {
        $method = Js::dynamicGet($this->sink, $name);
        if (Js::isNullish($method)) { return null; }
        if (!$method instanceof \Closure) { throw new JsError("WritableStream sink $name is not a function", 'TypeError'); }
        return $method;
    }

    private function callSink(\Closure $method, array $args): mixed
    {
        try {
            return Js::invokeWithReceiver($method, $this->sink, $args);
        } catch (JsError $error) {
            return JsPromise::rejected(Js::thrownValue($error));
        }
    }

    private function fail(mixed $reason): JsUndefined
    {
        if ($this->state !== self::WRITABLE) { return JsUndefined::Value; }
        $this->state = self::ERRORED;
        $this->storedError = $reason;
        return JsUndefined::Value;
    }

    /** Runs one sink operation after every earlier one has settled. */
    private function chain(\Closure $operation): JsPromise
    {
        $next = $this->tail->then(function () use ($operation) {
            if ($this->state === self::ERRORED) { throw Js::throwable($this->storedError); }
            return $operation();
        });
        $this->tail = $next->then(null, static fn () => JsUndefined::Value);
        $next->then(null, function ($reason): JsUndefined { $this->fail($reason); return JsUndefined::Value; }, false);
        return $next;
    }

    public function write(mixed $chunk): JsPromise
    {
        if ($this->state === self::ERRORED) { return JsPromise::rejected($this->storedError); }
        if ($this->closing || $this->state === self::CLOSED) { return JsPromise::rejected(new JsError('Cannot write to a closing or closed stream', 'TypeError')); }
        return $this->chain(function () use ($chunk) {
            $write = $this->sinkMethod('write');
            return $write === null ? JsUndefined::Value : $this->callSink($write, [$chunk, $this->controller]);
        });
    }

    public function close(): JsPromise
    {
        if ($this->state === self::ERRORED) { return JsPromise::rejected($this->storedError); }
        if ($this->closing || $this->state === self::CLOSED) { return JsPromise::rejected(new JsError('The stream is already closing or closed', 'TypeError')); }
        $this->closing = true;
        return $this->chain(function () {
            $close = $this->sinkMethod('close');
            $result = $close === null ? JsUndefined::Value : $this->callSink($close, []);
            return JsPromise::from($result)->then(function () {
                $this->state = self::CLOSED;
                return JsUndefined::Value;
            });
        });
    }

    public function abort(mixed $reason = JsUndefined::Value): JsPromise
    {
        if ($this->state !== self::WRITABLE) { return JsPromise::from(JsUndefined::Value); }
        $this->fail($reason);
        $abort = $this->sinkMethod('abort');
        $result = $abort === null ? JsUndefined::Value : $this->callSink($abort, [$reason]);
        return JsPromise::from($result)->then(static fn () => JsUndefined::Value);
    }

    public function getWriter(): JsObject
    {
        if ($this->writer !== null) { throw new JsError('WritableStream is locked to a writer', 'TypeError'); }
        $released = false;
        $guard = static function () use (&$released): void {
            if ($released) { throw new JsError('This writer has been released', 'TypeError'); }
        };
        $writer = new JsObject();
        $writer->p = [
            'write' => function (mixed $chunk = JsUndefined::Value) use ($guard) { $guard(); return $this->write($chunk); },
            'close' => function () use ($guard) { $guard(); return $this->close(); },
            'abort' => function (mixed $reason = JsUndefined::Value) use ($guard) { $guard(); return $this->abort($reason); },
            'releaseLock' => function () use (&$released, $writer) {
                $released = true;
                if ($this->writer === $writer) { $this->writer = null; }
                return JsUndefined::Value;
            },
            'ready' => JsPromise::from(JsUndefined::Value),
        ];
        $this->writer = $writer;
        return $writer;
    }

    public function call(string $method, array $args): mixed
    {
        $arg = static fn (int $i) => array_key_exists($i, $args) ? $args[$i] : JsUndefined::Value;
        if ($this->writer !== null && in_array($method, ['close', 'abort'], true)) {
            return JsPromise::rejected(new JsError('Cannot ' . $method . ' a locked stream', 'TypeError'));
        }
        return match ($method) {
            'getWriter' => $this->getWriter(),
            'close' => $this->close(),
            'abort' => $this->abort($arg(0)),
            default => throw new JsError($method . ' is not a function', 'TypeError'),
        };
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'locked' => $this->writer !== null,
            default => in_array($key, ['getWriter', 'close', 'abort'], true) ? fn (mixed ...$args) => $this->call($key, $args) : JsUndefined::Value,
        };
    }
}
