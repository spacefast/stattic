<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\JsArray;
use Zero\JsObject;

/**
 * `wp-hooks/` handlers (docs/config-api.md "WordPress hooks"): each compiled handler is registered
 * with add_filter/add_action at its priority. Handlers run in WordPress's own request, outside any
 * Zero transaction. A handler that throws is logged; a filter then returns the value it was given,
 * so one broken handler never blanks the page or its content.
 */
final class Hooks
{
    public static function register(Plugin $plugin): void
    {
        $views = $plugin->schema->wpViews();
        foreach ($plugin->schema->wpHooks() as $hook) {
            $run = static function (mixed ...$args) use ($plugin, $hook, $views): mixed {
                $given = $args[0] ?? null;
                try {
                    $plugin->app(); // the handler lives in the app's functions.php
                    $result = ('ZeroApp\\' . $hook['handler'])(...array_map(static fn ($a) => self::toJs($a, $views), array_slice($args, 0, $hook['args'])));

                    return $hook['filter'] ? self::toPhp($result, $given) : null;
                } catch (\Throwable $e) {
                    error_log(sprintf('[zero] wp-hooks/%s: %s: %s', $hook['hook'], $e::class, $e->getMessage()));

                    return $given;
                }
            };
            $hook['filter']
                ? add_filter($hook['hook'], $run, $hook['priority'], $hook['args'])
                : add_action($hook['hook'], $run, $hook['priority'], $hook['args']);
        }
    }

    /**
     * A hook argument as the handler's typed view: WP_Post and WP_User as read-only copies of the
     * catalog's properties, WP_Query as its get/set/is_main_query methods, arrays as JS values.
     *
     * @param array<string, list<string>> $views
     */
    public static function toJs(mixed $value, array $views): mixed
    {
        $class = is_object($value) ? $value::class : null;
        return match (true) {
            isset($views[$class]) => new JsObject(array_combine($views[$class], array_map(
                static fn (string $f) => self::toJs($f === 'roles' ? array_values($value->roles) : $value->$f, $views),
                $views[$class],
            ))),
            $value instanceof \WP_Query => self::query($value, $views),
            is_array($value) && array_is_list($value) => new JsArray(array_map(static fn ($v) => self::toJs($v, $views), $value)),
            is_array($value) => new JsObject(array_map(static fn ($v) => self::toJs($v, $views), $value)),
            is_int($value), is_float($value), is_string($value), is_bool($value), $value === null => $value,
            default => null,
        };
    }

    /** `pre_get_posts`: read and change query vars of the very object WordPress will run. */
    private static function query(\WP_Query $query, array $views): JsObject
    {
        return new JsObject([
            'get' => static fn (string $var): mixed => self::toJs($query->get($var), $views),
            'set' => static function (string $var, mixed $value) use ($query): void {
                $query->set($var, $value instanceof JsArray ? $value->a : $value);
            },
            'is_main_query' => static fn (): bool => $query->is_main_query(),
        ]);
    }

    /** A filter's result back in the shape WordPress handed over (a list stays a PHP list). */
    private static function toPhp(mixed $result, mixed $given): mixed
    {
        $plain = \Zero\Seam::toPhp($result);

        return is_array($given) && is_array($plain) && array_is_list($given) ? array_values($plain) : $plain;
    }
}
