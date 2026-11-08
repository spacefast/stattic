<?php

declare(strict_types=1);

namespace Zero;

/** Thrown values, promises and iteration for checked JavaScript (see JsJobs, JsPromise). */
trait JsAsync
{
    /** The JavaScript value a caught PHP exception carries. */
    public static function thrownValue(JsError $error): mixed
    {
        return $error instanceof JsThrow ? $error->value : $error;
    }

    /** A PHP exception that carries a JavaScript thrown value. */
    public static function throwable(mixed $value): JsError
    {
        return $value instanceof JsError ? $value : new JsThrow($value);
    }

    /**
     * A typed TypeScript `catch (e)` binding is an Error: a built-in error, or an instance of a
     * class that extends Error (bound as itself, so its fields stay readable). Other thrown
     * values are refused, not coerced.
     */
    public static function caughtError(JsError $error): JsError|JsObject
    {
        if ($error instanceof JsThrow) {
            if (self::isError($error->value)) { return $error->value; }
            throw new JsError('A non-Error value was thrown into typed code: ' . self::thrownDescription($error->value), 'TypeError');
        }
        return $error;
    }

    public static function thrownDescription(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === null, $value === JsUndefined::Value, is_bool($value), is_int($value), is_float($value) => self::str($value),
            default => self::dynamicObjectTag($value),
        };
    }

    /** Calls a JavaScript function value with an explicit receiver (a thenable's `then`). */
    public static function invokeWithReceiver(mixed $function, mixed $receiver, array $args): mixed
    {
        return self::dynamicInvoke($function, $receiver, new JsArray($args));
    }
}
