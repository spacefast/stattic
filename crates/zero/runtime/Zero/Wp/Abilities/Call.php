<?php

declare(strict_types=1);

namespace Zero\Wp\Abilities;

use Zero\HttpError;
use Zero\Js;

/**
 * `abilities.call(name, input)` from "php/wordpress": core's `WP_Ability::execute()` for any registered
 * ability, Zero's own or another plugin's. Core validates the input, checks the ability's permission
 * for the current WordPress user, runs it and validates the output; a WP_Error becomes an HttpError.
 */
final class Call
{
    public static function run(string $name, mixed $input): mixed
    {
        // The build typed this call from a lock the site no longer satisfies: a deployment problem.
        if (!function_exists('wp_has_ability') || !wp_has_ability($name)) {
            throw new HttpError(503, 'ability_unavailable', "The ability '$name' is not registered on this site");
        }
        $result = wp_get_ability($name)->execute(self::wire($input));
        if ($result instanceof \WP_Error) {
            Invoke::throw($result);
        }

        return self::value($result);
    }

    /** A compiled JS value as the arrays core's schema validation and PHP callbacks expect. */
    private static function wire(mixed $input): mixed
    {
        if ($input === null || $input === \Zero\JsUndefined::Value) {
            return null;
        }
        $json = Js::jsonStringify($input) ?? 'null';

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** The ability's result as JSON would carry it (objects with JsonSerializable, WP_Post's public fields). */
    private static function value(mixed $result): mixed
    {
        $json = wp_json_encode($result);
        if ($json === false) {
            throw new HttpError(500, 'ability_result_invalid', 'The ability returned a value that is not JSON');
        }

        return Js::jsonParse($json);
    }
}
