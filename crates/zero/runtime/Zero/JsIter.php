<?php

declare(strict_types=1);

namespace Zero;

/**
 * One `for…of` / `for await…of` loop over a checked JavaScript value (GetIterator, IteratorStep,
 * IteratorClose, CreateAsyncFromSyncIterator). Compiled loops call step() before each body and
 * close() when the loop ends for any reason, so an early exit runs the iterator's `return()`
 * (and a generator's `finally`) at that point, not at garbage collection.
 */
final class JsIter
{
    public mixed $value = JsUndefined::Value;
    private ?\Generator $native = null;
    private mixed $iterator = null;
    private bool $done = false;
    private bool $fromSync = false;

    private function __construct(private readonly bool $async) {}

    public static function open(mixed $iterable): self
    {
        $iter = new self(false);
        $iter->init($iterable);
        return $iter;
    }

    public static function openAsync(mixed $iterable): self
    {
        $iter = new self(true);
        $iter->init($iterable);
        return $iter;
    }

    private function init(mixed $iterable): void
    {
        if ($this->async) {
            $method = self::wellKnownMethod($iterable, 'asyncIterator');
            if ($iterable instanceof JsAsyncGenerator) {
                $this->iterator = $iterable;
                return;
            }
            if ($method !== null) {
                $this->iterator = self::iteratorFrom($method, $iterable);
                return;
            }
            $this->fromSync = true;
        }
        if ($iterable instanceof JsGenerator) {
            $this->iterator = $iterable;
            return;
        }
        $method = self::wellKnownMethod($iterable, 'iterator');
        if ($method !== null) {
            $this->iterator = self::iteratorFrom($method, $iterable);
            return;
        }
        $this->native = Js::dynamicIterator($iterable);
    }

    private static function iteratorFrom(\Closure $method, mixed $receiver): mixed
    {
        $iterator = Js::invokeWithReceiver($method, $receiver, []);
        if (!is_object($iterator) || $iterator instanceof \Closure) {
            throw new JsError('Result of the Symbol.iterator method is not an object', 'TypeError');
        }
        return $iterator;
    }

    /**
     * The `[Symbol.iterator]` / `[Symbol.asyncIterator]` method of a value, if it has one.
     * Native values (promises, generators, streams) answer here; symbol-keyed properties on
     * plain objects are read through the Symbol runtime once it is available.
     */
    public static function wellKnownMethod(mixed $value, string $name): ?\Closure
    {
        if ($value instanceof JsReadableStream && $name === 'asyncIterator') {
            return static fn () => $value->values();
        }
        if ($value instanceof JsGenerator && $name === 'iterator' || $value instanceof JsAsyncGenerator && $name === 'asyncIterator') {
            return static fn () => $value;
        }
        if ($value instanceof JsObject && method_exists(Js::class, 'symbolMethod')) {
            return Js::symbolMethod($value, $name);
        }
        return null;
    }

    public function step(): bool
    {
        if ($this->done) { return false; }
        if ($this->native !== null) {
            if ($this->started) { $this->native->next(); } else { $this->started = true; }
            if (!$this->native->valid()) { $this->done = true; return false; }
            $this->value = $this->native->current();
            if ($this->fromSync) { $this->value = $this->awaitStep($this->value); }
            return true;
        }
        try {
            $result = self::call($this->iterator, 'next', []);
            if ($this->async && !$this->fromSync) { $result = JsJobs::await($result); }
        } catch (\Throwable $error) {
            // An iterator whose next() throws is not closed (IteratorStep is not IteratorClose).
            $this->done = true;
            throw $error;
        }
        if (!$result instanceof JsObject) {
            $this->done = true;
            throw new JsError('Iterator result ' . Js::thrownDescription($result) . ' is not an object', 'TypeError');
        }
        if (Js::truthy(Js::dynamicGet($result, 'done'))) {
            $this->done = true;
            return false;
        }
        $this->value = Js::dynamicGet($result, 'value');
        if ($this->fromSync) { $this->value = $this->awaitStep($this->value); }
        return true;
    }

    private bool $started = false;

    /** CreateAsyncFromSyncIterator awaits each value; a rejection closes the sync iterator. */
    private function awaitStep(mixed $value): mixed
    {
        try {
            return JsJobs::await($value);
        } catch (JsError $error) {
            $this->done = true;
            throw $error;
        }
    }

    /** The loop threw: close the iterator, and let the loop's own error win over return()'s. */
    public function abrupt(): void
    {
        if ($this->done) { return; }
        $this->done = true;
        try {
            $this->callReturn();
        } catch (JsError) {
        }
    }

    /** The loop finished, broke or returned: an unfinished iterator gets return(), whose errors propagate. */
    public function close(): void
    {
        if ($this->done) { return; }
        $this->done = true;
        $result = $this->callReturn();
        if ($result !== null && !$result instanceof JsObject) {
            throw new JsError('Iterator result ' . Js::thrownDescription($result) . ' is not an object', 'TypeError');
        }
    }

    private function callReturn(): mixed
    {
        if ($this->native !== null) { return null; }
        if ($this->iterator instanceof JsGenerator || $this->iterator instanceof JsAsyncGenerator) {
            $result = $this->iterator->call('return', []);
        } else {
            $method = Js::dynamicGet($this->iterator, 'return');
            if (Js::isNullish($method)) { return null; }
            $result = Js::invokeWithReceiver($method, $this->iterator, []);
        }
        return $this->async && !$this->fromSync ? JsJobs::await($result) : $result;
    }

    private static function call(mixed $iterator, string $method, array $args): mixed
    {
        if ($iterator instanceof JsGenerator || $iterator instanceof JsAsyncGenerator) {
            return $iterator->call($method, $args);
        }
        return Js::invokeWithReceiver(Js::dynamicGet($iterator, $method), $iterator, $args);
    }

    /** `yield* iterable` in a generator: forwards next/throw/return to the inner iterator. */
    public static function delegate(mixed $iterable): \Generator
    {
        return self::delegation($iterable, false);
    }

    /** `yield* iterable` in an async generator. */
    public static function asyncDelegate(mixed $iterable): \Generator
    {
        return self::delegation($iterable, true);
    }

    private static function delegation(mixed $iterable, bool $async): \Generator
    {
        $iter = $async ? self::openAsync($iterable) : self::open($iterable);
        if ($iter->native !== null) {
            // Built-in iterators have no throw(): the delegation closes and throws a TypeError
            // into the delegating generator (YieldExpression evaluation, step 7.b.iii).
            while ($iter->step()) {
                try {
                    yield $iter->value;
                } catch (JsError) {
                    $iter->done = true;
                    throw new JsError('The iterator does not provide a \'throw\' method', 'TypeError');
                }
            }
            return JsUndefined::Value;
        }
        $iterator = $iter->iterator;
        $received = JsUndefined::Value;
        $mode = 'next';
        while (true) {
            if ($mode === 'return') {
                $method = $iterator instanceof JsGenerator || $iterator instanceof JsAsyncGenerator ? null : Js::dynamicGet($iterator, 'return');
                if (!$iterator instanceof JsGenerator && !$iterator instanceof JsAsyncGenerator && Js::isNullish($method)) {
                    throw new JsGeneratorReturn($received);
                }
            }
            $result = self::call($iterator, $mode, [$received]);
            if ($async && !$iter->fromSync) { $result = JsJobs::await($result); }
            if (!$result instanceof JsObject) {
                throw new JsError('Iterator result ' . Js::thrownDescription($result) . ' is not an object', 'TypeError');
            }
            $done = Js::truthy(Js::dynamicGet($result, 'done'));
            $value = Js::dynamicGet($result, 'value');
            if ($done) {
                if ($mode === 'return') { throw new JsGeneratorReturn($value); }
                return $value;
            }
            try {
                $received = yield $value;
                $mode = 'next';
            } catch (JsGeneratorReturn $returned) {
                $received = $returned->value;
                $mode = 'return';
            } catch (JsError $error) {
                $received = Js::thrownValue($error);
                $mode = 'throw';
            }
        }
    }
}
