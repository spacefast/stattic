<?php
declare(strict_types=1);
namespace Zero;

/**
 * DOM `AbortSignal` (https://dom.spec.whatwg.org/#interface-AbortSignal). Without an event loop,
 * `abort()` dispatches the abort event synchronously, exactly as Node does; `AbortSignal.timeout`
 * is a Timers entry, so it fires only while the request waits and is observed at the next await.
 */
final class JsAbortSignal
{
    public bool $aborted = false;
    public mixed $reason = JsUndefined::Value;
    private ?\Closure $onabort = null;
    /** @var list<array{0: \Closure, 1: bool}|null> listener, once */
    private array $listeners = [];
    /** Dependents of AbortSignal.any: aborted with this signal's reason. */
    private array $dependents = [];
    /** For a composite (AbortSignal.any) signal: the non-composite signals it follows. */
    private ?array $sources = null;

    public function onabort(): ?\Closure { return $this->onabort; }

    public static function abortError(): JsError
    {
        return new JsError('This operation was aborted', 'AbortError');
    }

    public static function aborted(mixed $reason = JsUndefined::Value): self
    {
        $signal = new self();
        $signal->aborted = true;
        $signal->reason = $reason === JsUndefined::Value ? self::abortError() : $reason;
        return $signal;
    }

    public static function timeout(mixed $ms): self
    {
        $signal = new self();
        $delay = Js::toNumber($ms);
        if (!is_finite((float) $delay) || $delay < 0 || $delay > 9007199254740991) {
            throw new JsError("Failed to execute 'timeout' on 'AbortSignal': Value is outside the 'unsigned long long' value range.", 'TypeError');
        }
        Timers::set(static function () use ($signal): void {
            $signal->signalAbort(new JsError('The operation was aborted due to timeout', 'TimeoutError'));
        }, $delay, [], 'AbortSignal.timeout', true);
        return $signal;
    }

    /** @param iterable<mixed> $signals */
    public static function any(iterable $signals): self
    {
        $signals = is_array($signals) ? $signals : iterator_to_array($signals, false);
        $result = new self();
        foreach ($signals as $signal) {
            if (!$signal instanceof self) { throw new JsError("Failed to execute 'any' on 'AbortSignal': Failed to convert value to 'AbortSignal'.", 'TypeError'); }
            if ($signal->aborted) {
                $result->aborted = true;
                $result->reason = $signal->reason;
                return $result;
            }
        }
        // https://dom.spec.whatwg.org/#create-a-dependent-abort-signal: a composite follows the
        // sources of its composite inputs, so nested AbortSignal.any folds flatten to the roots.
        $result->sources = [];
        foreach ($signals as $signal) {
            foreach ($signal->sources ?? [$signal] as $source) {
                if (!in_array($source, $result->sources, true)) {
                    $result->sources[] = $source;
                    $source->dependents[] = \WeakReference::create($result);
                }
            }
        }
        return $result;
    }

    public function throwIfAborted(): JsUndefined
    {
        if ($this->aborted) { throw self::thrown($this->reason); }
        return JsUndefined::Value;
    }

    /** `throw reason`: Zero throws only errors, so a non-error reason is refused rather than wrapped. */
    public static function thrown(mixed $reason): \Throwable
    {
        return $reason instanceof \Throwable ? $reason : new JsError('Throwing a non-Error abort reason is not supported; abort with an Error', 'TypeError');
    }

    public function addEventListener(mixed $type, mixed $listener, mixed $options = JsUndefined::Value): JsUndefined
    {
        if (Js::str($type) !== 'abort' || Js::isNullish($listener)) { return JsUndefined::Value; }
        if (!$listener instanceof \Closure) { throw new JsError('Event listener objects are not supported; pass a function', 'TypeError'); }
        $once = false;
        if ($options instanceof JsObject) {
            foreach (['capture', 'passive', 'signal'] as $unsupported) {
                if (array_key_exists($unsupported, $options->p) && $options->p[$unsupported] !== JsUndefined::Value && $options->p[$unsupported] !== false) {
                    throw new JsError("addEventListener option '$unsupported' is not supported", 'TypeError');
                }
            }
            $once = Js::truthy($options->p['once'] ?? false);
        } elseif (!Js::isNullish($options) && !is_bool($options)) {
            throw new JsError('addEventListener options must be an object or boolean', 'TypeError');
        }
        foreach ($this->listeners as $entry) {
            if ($entry !== null && $entry[0] === $listener) { return JsUndefined::Value; }
        }
        $this->listeners[] = [$listener, $once];
        return JsUndefined::Value;
    }

    public function removeEventListener(mixed $type, mixed $listener, mixed $options = JsUndefined::Value): JsUndefined
    {
        if (Js::str($type) !== 'abort') { return JsUndefined::Value; }
        foreach ($this->listeners as $i => $entry) {
            if ($entry !== null && $entry[0] === $listener) { $this->listeners[$i] = null; }
        }
        return JsUndefined::Value;
    }

    /** https://dom.spec.whatwg.org/#abortsignal-signal-abort */
    public function signalAbort(mixed $reason): void
    {
        if ($this->aborted) { return; }
        $this->aborted = true;
        $this->reason = $reason === JsUndefined::Value ? self::abortError() : $reason;
        $dependents = [];
        foreach ($this->dependents as $ref) {
            $dependent = $ref->get();
            if ($dependent !== null && !$dependent->aborted) {
                $dependent->aborted = true;
                $dependent->reason = $this->reason;
                $dependents[] = $dependent;
            }
        }
        $this->dependents = [];
        // A listener's exception doesn't stop dispatch: every listener, then every dependent's,
        // runs; the first exception is rethrown afterwards, as Node reports it once dispatch ends
        // (there as an uncaught exception, which ends the process; here it ends the request).
        $error = $this->dispatch();
        foreach ($dependents as $dependent) { $error ??= $dependent->dispatch(); }
        if ($error !== null) { throw $error; }
    }

    private function dispatch(): ?\Throwable
    {
        $event = new JsObject(['type' => 'abort', 'target' => $this, 'currentTarget' => $this]);
        $error = null;
        // Listeners added during dispatch don't run; removed ones don't run either.
        $count = count($this->listeners);
        for ($i = 0; $i < $count; $i++) {
            $entry = $this->listeners[$i];
            if ($entry === null) { continue; }
            if ($entry[1]) { $this->listeners[$i] = null; }
            try {
                ($entry[0] === self::$handlerMarker ? $this->onabort : $entry[0])($event);
            } catch (\Throwable $e) {
                $error ??= $e;
            }
        }
        return $error;
    }

    private static ?\Closure $handlerMarker = null;

    /** An event handler attribute keeps the listener slot of its first non-null assignment. */
    public function setOnabort(mixed $handler): void
    {
        self::$handlerMarker ??= static function (): void {};
        $handler = $handler instanceof \Closure ? $handler : null;
        if ($handler !== null && $this->onabort === null) { $this->listeners[] = [self::$handlerMarker, false]; }
        if ($handler === null) {
            foreach ($this->listeners as $i => $entry) { if ($entry !== null && $entry[0] === self::$handlerMarker) { $this->listeners[$i] = null; } }
        }
        $this->onabort = $handler;
    }
}
