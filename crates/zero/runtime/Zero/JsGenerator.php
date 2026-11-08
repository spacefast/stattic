<?php

declare(strict_types=1);

namespace Zero;

/**
 * A JavaScript generator object (ECMA-262 §27.5) over a native PHP Generator. `yield` is PHP's
 * `yield`; the first `next(x)` ignores x, as in JavaScript; `return(v)` runs only `finally` blocks.
 */
final class JsGenerator
{
    private bool $started = false;
    private bool $done = false;
    private bool $running = false;

    public function __construct(private readonly \Generator $generator) {}

    /** A generator function value: each call creates a suspended generator object. */
    public static function function(\Closure $body): \Closure
    {
        return static fn (mixed ...$args): self => new self($body(...$args));
    }

    public static function result(mixed $value, bool $done): JsObject
    {
        return new JsObject(['value' => $value, 'done' => $done]);
    }

    public function call(string $method, array $args): JsObject
    {
        $value = array_key_exists(0, $args) ? $args[0] : JsUndefined::Value;
        return match ($method) {
            'next' => $this->next($value),
            'return' => $this->return($value),
            'throw' => $this->throw($value),
            default => throw new JsError($method . ' is not a function', 'TypeError'),
        };
    }

    public function next(mixed $value = JsUndefined::Value): JsObject
    {
        if ($this->done) { return self::result(JsUndefined::Value, true); }
        return $this->resume(function () use ($value): void {
            if (!$this->started) {
                $this->started = true;
                $this->generator->current();
            } else {
                $this->generator->send($value);
            }
        });
    }

    public function throw(mixed $reason): JsObject
    {
        if (!$this->started || $this->done) {
            $this->done = true;
            throw Js::throwable($reason);
        }
        return $this->resume(fn () => $this->generator->throw(Js::throwable($reason)));
    }

    public function return(mixed $value = JsUndefined::Value): JsObject
    {
        if (!$this->started || $this->done) {
            $this->done = true;
            return self::result($value, true);
        }
        return $this->resume(fn () => $this->generator->throw(new JsGeneratorReturn($value)));
    }

    private function resume(\Closure $step): JsObject
    {
        if ($this->running) { throw new JsError('Generator is already running', 'TypeError'); }
        $this->running = true;
        try {
            $step();
        } catch (JsGeneratorReturn $returned) {
            // return() finished every `finally` (which may have yielded on an earlier resume).
            $this->done = true;
            return self::result($returned->value, true);
        } catch (\Throwable $error) {
            $this->done = true;
            throw $error;
        } finally {
            $this->running = false;
        }
        if ($this->generator->valid()) { return self::result($this->generator->current(), false); }
        $this->done = true;
        return self::result($this->generator->getReturn(), true);
    }
}
