<?php

declare(strict_types=1);

namespace Zero;

/**
 * The request's microtask queue (ECMA-262 HostEnqueuePromiseJob) and async-function bodies
 * on PHP Fibers. There is no event loop: the queue drains when the root awaits and at
 * handler end. See docs/decisions/2026-11-07-promises-generators-streams.md.
 */
final class JsJobs
{
    /** @var \SplQueue<\Closure> */
    private static ?\SplQueue $queue = null;
    /** Rejected promises without a handler, in rejection order (HostPromiseRejectionTracker). */
    private static ?\SplObjectStorage $unhandled = null;

    public static function enqueue(\Closure $job): void
    {
        (self::$queue ??= new \SplQueue())->enqueue($job);
    }

    public static function trackRejection(JsPromise $promise): void
    {
        (self::$unhandled ??= new \SplObjectStorage())->offsetSet($promise);
    }

    public static function untrackRejection(JsPromise $promise): void
    {
        self::$unhandled?->offsetUnset($promise);
    }

    /** Runs queued jobs, including jobs they queue, until the queue is empty. */
    public static function drain(): void
    {
        $queue = self::$queue ??= new \SplQueue();
        while (!$queue->isEmpty()) {
            ($queue->dequeue())();
        }
    }

    /**
     * Calls an async function body. It runs synchronously until its first `await` that suspends,
     * then the returned promise settles when the body completes (AsyncFunctionStart).
     */
    public static function async(\Closure $body, array $args = []): JsPromise
    {
        $promise = new JsPromise();
        $fiber = new \Fiber(static function () use ($body, $args, $promise): void {
            try {
                $promise->resolve($body(...$args));
            } catch (JsError $error) {
                $promise->reject(Js::thrownValue($error));
            }
        });
        self::step($fiber, static fn () => $fiber->start());
        return $promise;
    }

    private static int $depth = 0;

    /**
     * A handler root. The outermost root starts on an empty queue and finishes it; a root
     * called from another (a hook during an action) shares the caller's queue.
     */
    public static function run(\Closure $root, array $args): mixed
    {
        if (self::$depth > 0) {
            return $root(...$args);
        }
        self::$queue = new \SplQueue();
        self::$unhandled = null;
        self::$depth++;
        try {
            return self::finish($root(...$args));
        } finally {
            self::$depth--;
            self::$queue = null;
            self::$unhandled = null;
        }
    }

    /** An async function value: each call starts a new body. */
    public static function asyncFunction(\Closure $body): \Closure
    {
        return static fn (mixed ...$args): JsPromise => self::async($body, $args);
    }

    /** Resumes a fiber and lets the value it suspended with (a promise) schedule the next resume. */
    private static function step(\Fiber $fiber, \Closure $resume): void
    {
        $awaited = $resume();
        if ($fiber->isTerminated()) { return; }
        if (!$awaited instanceof JsPromise) {
            throw new \LogicException('An async body suspended without a promise');
        }
        $awaited->then(
            static function ($value) use ($fiber): JsUndefined {
                self::step($fiber, static fn () => $fiber->resume([false, $value]));
                return JsUndefined::Value;
            },
            static function ($reason) use ($fiber): JsUndefined {
                self::step($fiber, static fn () => $fiber->resume([true, $reason]));
                return JsUndefined::Value;
            },
            false,
        );
    }

    /**
     * `await value`. Inside an async body this suspends its fiber until the promise settles
     * (one job for a non-promise, as specified since ES2019). At the root it drives the queue.
     */
    public static function await(mixed $value, string $site = ''): mixed
    {
        $promise = JsPromise::from($value);
        if (\Fiber::getCurrent() !== null) {
            [$rejected, $result] = \Fiber::suspend($promise);
            if ($rejected) { throw Js::throwable($result); }
            return $result;
        }
        return self::settle($promise, $site);
    }

    /**
     * The root's `await`: run jobs one at a time, in order, until the job that would resume the
     * root runs. Jobs queued after that stay queued, exactly as they would behind the root's
     * continuation. When no job is left, the timer seam runs one macrotask; when no timer is left
     * either, nothing can settle the promise.
     */
    public static function settle(JsPromise $promise, string $site = ''): mixed
    {
        $resumed = false;
        $promise->then(static function () use (&$resumed): JsUndefined {
            $resumed = true;
            return JsUndefined::Value;
        }, static function () use (&$resumed): JsUndefined {
            $resumed = true;
            return JsUndefined::Value;
        }, false);
        $queue = self::$queue ??= new \SplQueue();
        while (!$resumed) {
            if (!$queue->isEmpty()) {
                ($queue->dequeue())();
                continue;
            }
            if (!self::runTimer()) {
                throw new JsError('This promise can never settle: nothing left to run' . ($site === '' ? '' : ' (awaited at ' . $site . ')'), 'Error');
            }
        }
        if ($promise->state === JsPromise::REJECTED) {
            throw Js::throwable($promise->result);
        }
        return $promise->result;
    }

    /** One macrotask from the timer seam (agent 6's Timers), if it exists and has one due. */
    private static function runTimer(): bool
    {
        return class_exists(Timers::class) && Timers::runNext();
    }

    /**
     * Handler end: run the root's result to completion, drain the queue, then refuse unhandled
     * rejections (Node's default `--unhandled-rejections=throw`) and pending user timers.
     */
    public static function finish(mixed $result): mixed
    {
        if ($result instanceof JsPromise) {
            $result = self::settle($result);
        }
        self::drain();
        $unhandled = self::$unhandled;
        self::$unhandled = null;
        if ($unhandled !== null && $unhandled->count() > 0) {
            $unhandled->rewind();
            $first = $unhandled->current();
            throw Js::throwable($first->result);
        }
        if (class_exists(Timers::class)) {
            Timers::assertNonePending();
        }
        return $result;
    }
}
