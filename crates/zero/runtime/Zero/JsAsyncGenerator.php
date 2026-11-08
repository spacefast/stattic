<?php

declare(strict_types=1);

namespace Zero;

/**
 * A JavaScript async generator object (ECMA-262 §27.6). Requests queue in order
 * (AsyncGeneratorRequest records); each resumption of the PHP Generator body runs on a Fiber,
 * so `await` inside the body suspends that resumption only.
 */
final class JsAsyncGenerator
{
    private const START = 0;
    private const YIELD = 1;
    private const EXECUTING = 2;
    private const AWAITING_RETURN = 3;
    private const COMPLETED = 4;

    private int $state = self::START;
    private bool $generatorStarted = false;
    /** @var list<array{string, mixed, JsPromise}> */
    private array $queue = [];

    public function __construct(private readonly \Generator $generator) {}

    public static function function(\Closure $body): \Closure
    {
        return static fn (mixed ...$args): self => new self($body(...$args));
    }

    public function call(string $method, array $args): JsPromise
    {
        if (!in_array($method, ['next', 'return', 'throw'], true)) {
            throw new JsError($method . ' is not a function', 'TypeError');
        }
        $promise = new JsPromise();
        $this->queue[] = [$method, array_key_exists(0, $args) ? $args[0] : JsUndefined::Value, $promise];
        if ($this->state !== self::EXECUTING && $this->state !== self::AWAITING_RETURN) {
            $this->drain();
        }
        return $promise;
    }

    /** AsyncGeneratorResumeNext / AsyncGeneratorDrainQueue. */
    private function drain(): void
    {
        while ($this->queue !== [] && $this->state !== self::EXECUTING && $this->state !== self::AWAITING_RETURN) {
            [$kind, $value, $promise] = $this->queue[0];
            if ($this->state === self::START && $kind !== 'next') {
                $this->state = self::COMPLETED;
            }
            if ($this->state === self::COMPLETED) {
                if ($kind === 'return') {
                    $this->state = self::AWAITING_RETURN;
                    JsPromise::from($value)->then(function ($result): JsUndefined {
                        $this->state = self::COMPLETED;
                        $this->complete(JsGenerator::result($result, true), false);
                        $this->drain();
                        return JsUndefined::Value;
                    }, function ($reason): JsUndefined {
                        $this->state = self::COMPLETED;
                        $this->complete($reason, true);
                        $this->drain();
                        return JsUndefined::Value;
                    }, false);
                    return;
                }
                $kind === 'next' ? $this->complete(JsGenerator::result(JsUndefined::Value, true), false) : $this->complete($value, true);
                continue;
            }
            $this->state = self::EXECUTING;
            JsJobs::async(fn () => $this->resume($kind, $value));
        }
    }

    private function complete(mixed $value, bool $rejected): void
    {
        [, , $promise] = array_shift($this->queue);
        $rejected ? $promise->reject($value) : $promise->resolve($value);
    }

    /** One resumption, on its own Fiber: run the body to its next `yield`, `return` or throw. */
    private function resume(string $kind, mixed $value): JsUndefined
    {
        try {
            if ($kind === 'next') {
                if ($this->generatorStarted) {
                    $this->generator->send($value);
                } else {
                    $this->generatorStarted = true;
                    $this->generator->current();
                }
            } elseif ($kind === 'throw') {
                $this->generator->throw(Js::throwable($value));
            } else {
                // AsyncGeneratorUnwrapYieldResumption: a return value is awaited inside the body.
                try {
                    $signal = new JsGeneratorReturn(JsJobs::await($value));
                } catch (JsError $error) {
                    $signal = $error;
                }
                $this->generator->throw($signal);
            }
        } catch (JsGeneratorReturn $returned) {
            $this->state = self::COMPLETED;
            $this->complete(JsGenerator::result($returned->value, true), false);
            $this->drain();
            return JsUndefined::Value;
        } catch (JsError $error) {
            $this->state = self::COMPLETED;
            $this->complete(Js::thrownValue($error), true);
            $this->drain();
            return JsUndefined::Value;
        }
        if ($this->generator->valid()) {
            $this->state = self::YIELD;
            $this->complete(JsGenerator::result($this->generator->current(), false), false);
        } else {
            // Still executing while the return value is awaited, so no other request completes first.
            try {
                $result = JsJobs::await($this->generator->getReturn());
                $this->state = self::COMPLETED;
                $this->complete(JsGenerator::result($result, true), false);
            } catch (JsError $error) {
                $this->state = self::COMPLETED;
                $this->complete(Js::thrownValue($error), true);
            }
        }
        $this->drain();
        return JsUndefined::Value;
    }

}
