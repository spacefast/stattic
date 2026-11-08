<?php

declare(strict_types=1);

namespace Zero;

/**
 * WHATWG ReadableStream (default controller and reader) as a pull-driven chunk queue. There is
 * no event loop: `start` runs at construction, `pull` runs when a read finds the queue below the
 * high-water mark, and every promise settles through JsJobs. A PHP stream resource can back the
 * stream so large bodies are read in chunks, never buffered whole.
 * See docs/decisions/2026-11-07-promises-generators-streams.md.
 */
final class JsReadableStream
{
    private const READABLE = 0;
    private const CLOSED = 1;
    private const ERRORED = 2;

    private int $state = self::READABLE;
    private mixed $storedError = JsUndefined::Value;
    /** @var list<mixed> */
    private array $queue = [];
    /** @var list<JsPromise> pending read requests */
    private array $reads = [];
    private bool $closeRequested = false;
    private bool $started = false;
    private bool $pulling = false;
    private bool $pullAgain = false;
    public ?JsObject $reader = null;
    private ?JsPromise $closed = null;
    private JsObject $controller;
    private mixed $source;
    private int|float $highWaterMark;

    public function __construct(mixed $source = JsUndefined::Value, mixed $strategy = JsUndefined::Value)
    {
        $this->source = Js::isNullish($source) ? new JsObject() : $source;
        $hwm = Js::isNullish($strategy) ? JsUndefined::Value : Js::dynamicGet($strategy, 'highWaterMark');
        $this->highWaterMark = $hwm === JsUndefined::Value ? 1 : Js::toNumber($hwm);
        if (!Js::isNullish($strategy) && !Js::isNullish(Js::dynamicGet($strategy, 'size'))) {
            throw new JsError('ReadableStream size() strategies are not supported', 'TypeError');
        }
        if (!Js::isNullish(Js::dynamicGet($this->source, 'type'))) {
            throw new JsError('Byte streams (type: "bytes") are not supported', 'TypeError');
        }
        $this->controller = new JsObject([
            'enqueue' => fn (mixed $chunk = JsUndefined::Value) => $this->enqueue($chunk),
            'close' => fn () => $this->requestClose(),
            'error' => fn (mixed $e = JsUndefined::Value) => $this->error($e),
        ]);
        $start = $this->sourceMethod('start');
        $started = $start === null ? JsUndefined::Value : $this->callSource($start, [$this->controller]);
        JsPromise::from($started)->then(function (): JsUndefined {
            $this->started = true;
            $this->pullIfNeeded();
            return JsUndefined::Value;
        }, function ($reason): JsUndefined {
            $this->error($reason);
            return JsUndefined::Value;
        }, false);
    }

    /** A stream over a PHP stream resource (`fopen`, `php://temp`, upload temp files). */
    public static function fromResource(mixed $handle, int $chunkSize = 65536): self
    {
        if (!is_resource($handle)) { throw new \InvalidArgumentException('fromResource needs an open stream resource'); }
        return new self(new JsObject([
            'pull' => static function ($controller) use ($handle, $chunkSize) {
                $bytes = fread($handle, $chunkSize);
                if ($bytes === false) { throw new JsError('The stream resource could not be read', 'TypeError'); }
                if ($bytes !== '') {
                    Js::dynamicCallMethod($controller, 'enqueue', new JsArray([self::chunk($bytes)]));
                }
                if (feof($handle)) {
                    fclose($handle);
                    Js::dynamicCallMethod($controller, 'close', new JsArray());
                }
                return JsUndefined::Value;
            },
            'cancel' => static function () use ($handle) { if (is_resource($handle)) { fclose($handle); } return JsUndefined::Value; },
        ]), new JsObject(['highWaterMark' => 0]));
    }

    /** Bytes as a Uint8Array once binary data is available (feat/binary-data), a string until then. */
    private static function chunk(string $bytes): mixed
    {
        return class_exists(JsUint8Array::class) ? JsUint8Array::fromBytes($bytes) : $bytes;
    }

    /** Reads the whole stream into a PHP resource (php://temp), chunk by chunk, driving the job queue. */
    public function toResource(): mixed
    {
        $out = fopen('php://temp', 'w+b');
        $reader = $this->getReader();
        while (true) {
            $result = JsJobs::await(Js::dynamicCallMethod($reader, 'read', new JsArray()));
            if (Js::truthy(Js::dynamicGet($result, 'done'))) { break; }
            $value = Js::dynamicGet($result, 'value');
            fwrite($out, is_string($value) ? $value : (method_exists($value, 'bytes') ? $value->bytes() : Js::str($value)));
        }
        rewind($out);
        return $out;
    }

    private function sourceMethod(string $name): ?\Closure
    {
        $method = Js::dynamicGet($this->source, $name);
        if (Js::isNullish($method)) { return null; }
        if (!$method instanceof \Closure) { throw new JsError("ReadableStream source $name is not a function", 'TypeError'); }
        return $method;
    }

    /** Calls a source method, turning a throw into a rejected promise (as the spec's promise-calls do). */
    private function callSource(\Closure $method, array $args): mixed
    {
        try {
            return Js::invokeWithReceiver($method, $this->source, $args);
        } catch (JsError $error) {
            return JsPromise::rejected(Js::thrownValue($error));
        }
    }

    public function desiredSize(): int|float|null
    {
        return match ($this->state) {
            self::ERRORED => null,
            self::CLOSED => 0,
            default => $this->highWaterMark - count($this->queue),
        };
    }

    private function enqueue(mixed $chunk): JsUndefined
    {
        if ($this->closeRequested || $this->state !== self::READABLE) {
            throw new JsError('Cannot enqueue a chunk into a closed or errored stream', 'TypeError');
        }
        if ($this->reads !== []) {
            array_shift($this->reads)->resolve(JsGenerator::result($chunk, false));
        } else {
            $this->queue[] = $chunk;
        }
        $this->pullIfNeeded();
        return JsUndefined::Value;
    }

    private function requestClose(): JsUndefined
    {
        if ($this->closeRequested || $this->state !== self::READABLE) {
            throw new JsError('Cannot close a closed or errored stream', 'TypeError');
        }
        $this->closeRequested = true;
        if ($this->queue === []) { $this->finishClose(); }
        return JsUndefined::Value;
    }

    private function finishClose(): void
    {
        $this->state = self::CLOSED;
        foreach ($this->reads as $read) { $read->resolve(JsGenerator::result(JsUndefined::Value, true)); }
        $this->reads = [];
        $this->closed?->resolve(JsUndefined::Value);
    }

    public function error(mixed $reason): JsUndefined
    {
        if ($this->state !== self::READABLE) { return JsUndefined::Value; }
        $this->state = self::ERRORED;
        $this->storedError = $reason;
        $this->queue = [];
        foreach ($this->reads as $read) { $read->reject($reason); }
        $this->reads = [];
        if ($this->closed !== null) {
            $this->closed->reject($reason);
            $this->closed->then(null, null, false);
        }
        return JsUndefined::Value;
    }

    /** ReadableStreamDefaultControllerCallPullIfNeeded. */
    private function pullIfNeeded(): void
    {
        if (!$this->started || $this->closeRequested || $this->state !== self::READABLE) { return; }
        if ($this->reads === [] && $this->desiredSize() <= 0) { return; }
        if ($this->pulling) { $this->pullAgain = true; return; }
        $pull = $this->sourceMethod('pull');
        if ($pull === null) { return; }
        $this->pulling = true;
        JsPromise::from($this->callSource($pull, [$this->controller]))->then(function (): JsUndefined {
            $this->pulling = false;
            if ($this->pullAgain) {
                $this->pullAgain = false;
                $this->pullIfNeeded();
            }
            return JsUndefined::Value;
        }, function ($reason): JsUndefined {
            $this->error($reason);
            return JsUndefined::Value;
        }, false);
    }

    public function read(): JsPromise
    {
        if ($this->state === self::ERRORED) { return JsPromise::rejected($this->storedError); }
        if ($this->queue !== []) {
            $chunk = array_shift($this->queue);
            if ($this->closeRequested && $this->queue === []) { $this->finishClose(); } else { $this->pullIfNeeded(); }
            return JsPromise::from(JsGenerator::result($chunk, false));
        }
        if ($this->state === self::CLOSED) { return JsPromise::from(JsGenerator::result(JsUndefined::Value, true)); }
        $promise = new JsPromise();
        $this->reads[] = $promise;
        $this->pullIfNeeded();
        return $promise;
    }

    public function cancel(mixed $reason = JsUndefined::Value): JsPromise
    {
        if ($this->state === self::CLOSED) { return JsPromise::from(JsUndefined::Value); }
        if ($this->state === self::ERRORED) { return JsPromise::rejected($this->storedError); }
        $this->queue = [];
        $this->closeRequested = true;
        $this->finishClose();
        $cancel = $this->sourceMethod('cancel');
        $result = $cancel === null ? JsUndefined::Value : $this->callSource($cancel, [$reason]);
        return JsPromise::from($result)->then(static fn () => JsUndefined::Value);
    }

    public function locked(): bool
    {
        return $this->reader !== null;
    }

    /** A default reader. Locking is enforced, as in JavaScript. */
    public function getReader(mixed $options = JsUndefined::Value): JsObject
    {
        if (!Js::isNullish($options) && !Js::isNullish(Js::dynamicGet($options, 'mode'))) {
            throw new JsError('BYOB readers are not supported', 'TypeError');
        }
        if ($this->reader !== null) { throw new JsError('ReadableStream is locked to a reader', 'TypeError'); }
        $this->closed = new JsPromise();
        if ($this->state === self::CLOSED) { $this->closed->resolve(JsUndefined::Value); }
        if ($this->state === self::ERRORED) { $this->closed->reject($this->storedError); $this->closed->then(null, null, false); }
        $released = false;
        $guard = static function () use (&$released): void {
            if ($released) { throw new JsError('This reader has been released', 'TypeError'); }
        };
        $closed = $this->closed;
        $reader = new JsObject();
        $reader->p = [
            'read' => function () use ($guard) { $guard(); return $this->read(); },
            'cancel' => function (mixed $reason = JsUndefined::Value) use ($guard) { $guard(); return $this->cancel($reason); },
            'releaseLock' => function () use (&$released, $reader, $closed) {
                if ($released) { return JsUndefined::Value; }
                $released = true;
                foreach ($this->reads as $read) { $read->reject(new JsError('Reader was released', 'TypeError')); }
                $this->reads = [];
                if ($closed->state === JsPromise::PENDING) {
                    $closed->reject(new JsError('Reader was released', 'TypeError'));
                    $closed->then(null, null, false);
                }
                if ($this->reader === $reader) { $this->reader = null; }
                return JsUndefined::Value;
            },
            'closed' => $closed,
        ];
        $this->reader = $reader;
        return $reader;
    }

    /** Async iteration (`for await`, `values()`): reads until done; an early exit cancels the stream. */
    public function values(mixed $options = JsUndefined::Value): JsObject
    {
        $preventCancel = !Js::isNullish($options) && Js::truthy(Js::dynamicGet($options, 'preventCancel'));
        $reader = $this->getReader();
        return new JsObject([
            'next' => function () use ($reader) {
                return Js::dynamicCallMethod($reader, 'read', new JsArray())->then(static function ($result) use ($reader) {
                    if (Js::truthy(Js::dynamicGet($result, 'done'))) { Js::dynamicCallMethod($reader, 'releaseLock', new JsArray()); }
                    return $result;
                });
            },
            'return' => function (mixed $value = JsUndefined::Value) use ($reader, $preventCancel) {
                $done = static function () use ($reader, $value) {
                    Js::dynamicCallMethod($reader, 'releaseLock', new JsArray());
                    return JsGenerator::result($value, true);
                };
                if ($preventCancel) { return JsPromise::from(JsUndefined::Value)->then($done); }
                return Js::dynamicCallMethod($reader, 'cancel', new JsArray([$value]))->then($done);
            },
        ]);
    }

    /** ReadableStreamDefaultTee: both branches see every chunk; each cancels independently. */
    public function tee(): JsArray
    {
        $reader = $this->getReader();
        $branches = [];
        $canceled = [false, false];
        $reasons = [JsUndefined::Value, JsUndefined::Value];
        $reading = false;
        $controllers = [null, null];
        $pull = function () use ($reader, &$branches, &$canceled, &$reading, &$controllers) {
            if ($reading) { return JsUndefined::Value; }
            $reading = true;
            return Js::dynamicCallMethod($reader, 'read', new JsArray())->then(function ($result) use (&$canceled, &$reading, &$controllers) {
                $reading = false;
                $done = Js::truthy(Js::dynamicGet($result, 'done'));
                foreach ([0, 1] as $i) {
                    if ($canceled[$i]) { continue; }
                    $done
                        ? Js::dynamicCallMethod($controllers[$i], 'close', new JsArray())
                        : Js::dynamicCallMethod($controllers[$i], 'enqueue', new JsArray([Js::dynamicGet($result, 'value')]));
                }
                return JsUndefined::Value;
            });
        };
        foreach ([0, 1] as $i) {
            $branches[$i] = new self(new JsObject([
                'start' => function ($controller) use (&$controllers, $i) { $controllers[$i] = $controller; return JsUndefined::Value; },
                'pull' => $pull,
                'cancel' => function ($reason) use (&$canceled, &$reasons, $i) {
                    $canceled[$i] = true;
                    $reasons[$i] = $reason;
                    return $canceled[0] && $canceled[1] ? $this->cancel(new JsArray($reasons)) : JsUndefined::Value;
                },
            ]), new JsObject(['highWaterMark' => 0]));
        }
        return new JsArray($branches);
    }

    /** ReadableStreamPipeTo with the default options (and preventClose/preventAbort/preventCancel). */
    public function pipeTo(mixed $destination, mixed $options = JsUndefined::Value): JsPromise
    {
        if (!$destination instanceof JsWritableStream) {
            return JsPromise::rejected(new JsError('pipeTo needs a WritableStream', 'TypeError'));
        }
        $flag = static fn (string $name): bool => !Js::isNullish($options) && Js::truthy(Js::dynamicGet($options, $name));
        if (!Js::isNullish($options) && !Js::isNullish(Js::dynamicGet($options, 'signal'))) {
            return JsPromise::rejected(new JsError('pipeTo signal is not supported', 'TypeError'));
        }
        return JsJobs::async(function () use ($destination, $flag) {
            $reader = $this->getReader();
            $writer = $destination->getWriter();
            try {
                while (true) {
                    try {
                        $result = JsJobs::await(Js::dynamicCallMethod($reader, 'read', new JsArray()));
                    } catch (JsError $error) {
                        if (!$flag('preventAbort')) { JsJobs::await(Js::dynamicCallMethod($writer, 'abort', new JsArray([Js::thrownValue($error)]))); }
                        throw $error;
                    }
                    if (Js::truthy(Js::dynamicGet($result, 'done'))) { break; }
                    try {
                        JsJobs::await(Js::dynamicCallMethod($writer, 'write', new JsArray([Js::dynamicGet($result, 'value')])));
                    } catch (JsError $error) {
                        if (!$flag('preventCancel')) { JsJobs::await($this->cancel(Js::thrownValue($error))); }
                        throw $error;
                    }
                }
                if (!$flag('preventClose')) { JsJobs::await(Js::dynamicCallMethod($writer, 'close', new JsArray())); }
            } finally {
                Js::dynamicCallMethod($reader, 'releaseLock', new JsArray());
                Js::dynamicCallMethod($writer, 'releaseLock', new JsArray());
            }
            return JsUndefined::Value;
        });
    }

    public function pipeThrough(mixed $transform, mixed $options = JsUndefined::Value): mixed
    {
        $writable = Js::dynamicGet($transform, 'writable');
        $readable = Js::dynamicGet($transform, 'readable');
        $this->pipeTo($writable, $options)->then(null, null, false);
        return $readable;
    }

    /** `stream.method(...)` from checked JavaScript. */
    public function call(string $method, array $args): mixed
    {
        $arg = static fn (int $i) => array_key_exists($i, $args) ? $args[$i] : JsUndefined::Value;
        return match ($method) {
            'getReader' => $this->getReader($arg(0)),
            'cancel' => $this->locked() ? JsPromise::rejected(new JsError('Cannot cancel a locked stream', 'TypeError')) : $this->cancel($arg(0)),
            'tee' => $this->tee(),
            'pipeTo' => $this->pipeTo($arg(0), $arg(1)),
            'pipeThrough' => $this->pipeThrough($arg(0), $arg(1)),
            'values' => $this->values($arg(0)),
            default => throw new JsError($method . ' is not a function', 'TypeError'),
        };
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'locked' => $this->locked(),
            default => in_array($key, ['getReader', 'cancel', 'tee', 'pipeTo', 'pipeThrough', 'values'], true)
                ? fn (mixed ...$args) => $this->call($key, $args)
                : JsUndefined::Value,
        };
    }
}
