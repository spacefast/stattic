<?php

declare(strict_types=1);

namespace TypedPress\Wp\Abilities;

use TypedPress\Wp\Facade;

/**
 * A TypedPress ability. Core's run route serves only abilities that are `show_in_rest`; an `agents: false`
 * one is hidden from REST discovery and MCP, but it is still the app's own operation, so for the
 * facade's own dispatches (`/wp-json/typedpress/v1/actions/<name>`, spec §3a) it reports itself shown and
 * the run route serves it with core's checks: input, permission, execution, output.
 */
final class TypedPressAbility extends \WP_Ability
{
    public function get_meta_item(string $key, $default_value = null)
    {
        if ($key === 'show_in_rest' && Facade::running() === $this->get_name()) {
            return true; // the one named elevation: the app's own API runs its own ability
        }

        return parent::get_meta_item($key, $default_value);
    }

    /**
     * WordPress 7.2 types an ability's input by its schema here, inside execute(), where 7.1 typed
     * it as a REST argument. TypedPress's pipeline is the one validator of its abilities
     * (Register::validate), so JSON input is read as it was sent. A query string (core's GET and
     * DELETE runs) carries only strings, so core types it, and only when all of it validates: typing
     * part of an invalid filter would read "false" as true. Why it was left untyped is kept for
     * Invoke::requireTyped.
     */
    protected function sanitize_input($input)
    {
        // No input at all has nothing to type: TypedPress's own validation names what is missing.
        if (!Register::queryString() || $input === null || $input === []) {
            return $input;
        }
        $schema = $this->get_input_schema();
        $valid = $schema === [] ? true : rest_validate_value_from_schema($input, $schema, 'input');
        if ($valid instanceof \WP_Error) {
            Register::untyped($valid->get_error_message());

            return $input;
        }
        Register::untyped(null);

        return parent::sanitize_input($input);
    }
}
