<?php
declare(strict_types=1);
namespace Zero;

/**
 * Request-scoped timers. There is no event loop: a timer callback runs only when the request waits
 * (the promise job loop calls runNext() once microtasks are empty and its promise is still pending).
 * See docs/decisions/2026-11-07-stdlib-objects.md.
 *
 * - Ordering is (due time, creation order), as in Node and the HTML timer task source.
 * - The clock is Js::now(). With ZERO_NOW pinned, waiting advances a virtual clock instead of sleeping.
 * - Total waiting is capped by the request budget; exceeding it is a RangeError at the wait.
 */
final class Timers
{
    /** @var array<int, array{due: int|float, seq: int, callback: \Closure, args: list<mixed>, site: string, internal: bool}> */
    private static array $pending = [];
    private static int $next = 1;
    private static int|float $waited = 0;
    private static int|float|null $virtual = null;

    public static function set(\Closure $callback, mixed $delay, array $args = [], string $site = '', bool $internal = false): int
    {
        // HTML timer initialization: ToNumber, NaN and negatives are 0, and Node clamps
        // delays outside the signed 32-bit range to 1ms.
        $ms = Js::toNumber($delay);
        $ms = is_nan((float) $ms) || $ms < 0 ? 0 : $ms;
        if ($ms > 2147483647) { $ms = 1; }
        $ms = max(1, (int) floor($ms));
        $id = self::$next++;
        self::$pending[$id] = ['due' => self::clock() + $ms, 'seq' => $id, 'callback' => $callback, 'args' => $args, 'site' => $site, 'internal' => $internal];
        return $id;
    }

    public static function clear(mixed $id): JsUndefined
    {
        if ($id instanceof JsTimeout) { $id = $id->id; }
        if (is_int($id) || is_float($id) && floor($id) === $id) { unset(self::$pending[(int) $id]); }
        return JsUndefined::Value;
    }

    /** Node's setTimeout returns a Timeout object; checked JavaScript gets an opaque handle like it. */
    public static function handle(int $id): JsTimeout { return new JsTimeout($id); }

    public static function pending(): int { return count(self::$pending); }

    /** Waits for the earliest timer, runs exactly that callback, and reports whether one ran. */
    public static function runNext(): bool
    {
        if (self::$pending === []) { return false; }
        $id = null;
        foreach (self::$pending as $candidate => $timer) {
            if ($id === null || $timer['due'] < self::$pending[$id]['due']) { $id = $candidate; }
        }
        $timer = self::$pending[$id];
        unset(self::$pending[$id]);
        $wait = $timer['due'] - self::clock();
        if ($wait > 0) {
            if (self::$waited + $wait > self::budget()) {
                throw new JsError('Timer wait of ' . $wait . 'ms exceeds the request budget of ' . self::budget() . 'ms', 'RangeError');
            }
            self::$waited += $wait;
            if (self::pinned()) { self::$virtual = $timer['due']; } else { usleep((int) ($wait * 1000)); }
        }
        ($timer['callback'])(...$timer['args']);
        return true;
    }

    /** Handler end: a pending user timer would fire after the response in Node; PHP ends first. */
    public static function assertNonePending(): void
    {
        foreach (self::$pending as $id => $timer) {
            if (!$timer['internal']) {
                self::reset();
                throw new JsError('A setTimeout callback is still pending when the handler returns' . ($timer['site'] === '' ? '' : ' (' . $timer['site'] . ')') . '; await it or clearTimeout it first', 'Error');
            }
        }
        self::reset();
    }

    /**
     * Runs one handler with request-scoped timers. Handlers nest (a hook running inside an action), so
     * only the outermost call owns the timer list and performs the handler-end check.
     *
     * @template T
     * @param \Closure(): T $handler
     * @return T
     */
    public static function run(\Closure $handler): mixed
    {
        if (self::$depth++ > 0) {
            try { return $handler(); } finally { self::$depth--; }
        }
        self::reset();
        try {
            $result = $handler();
        } catch (\Throwable $e) {
            self::$depth--;
            self::reset();
            throw $e;
        }
        self::$depth--;
        self::assertNonePending();
        return $result;
    }

    private static int $depth = 0;

    public static function reset(): void
    {
        self::$pending = [];
        self::$waited = 0;
        self::$virtual = null;
    }

    public static function clock(): int|float
    {
        return self::$virtual !== null && self::pinned() ? max(self::$virtual, Js::now()) : Js::now();
    }

    private static function pinned(): bool
    {
        $pinned = getenv('ZERO_NOW');
        return $pinned !== false && $pinned !== '';
    }

    private static function budget(): int|float
    {
        $configured = getenv('ZERO_TIMER_BUDGET_MS');
        $budget = $configured !== false && preg_match('/^\d+$/D', $configured) ? (int) $configured : 30000;
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            $elapsed = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000 : 0;
            $budget = min($budget, max(0, $limit * 1000 - $elapsed - 1000));
        }
        return $budget;
    }
}
