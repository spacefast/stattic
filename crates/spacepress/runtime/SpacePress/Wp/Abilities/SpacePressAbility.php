<?php

declare(strict_types=1);

namespace SpacePress\Wp\Abilities;

use SpacePress\Wp\Facade;

/**
 * A SpacePress ability. Core's run route serves only abilities that are `show_in_rest`; an `agents: false`
 * one is hidden from REST discovery (`/wp-abilities/v1/abilities`) and MCP, but it is still the app's
 * own operation, which its typed client and the facade (`/wp-json/spacepress/v1/actions/<name>`, spec
 * §3a) run. So while core's run route is running this ability by name, it reports itself shown, and
 * the run route serves it with core's checks: input, permission, execution, output. Listing it,
 * describing it and MCP still read `show_in_rest` as registered.
 */
final class SpacePressAbility extends \WP_Ability
{
    public function get_meta_item(string $key, $default_value = null)
    {
        if ($key === 'show_in_rest' && in_array($this->get_name(), [Facade::running(), Register::running()], true)) {
            return true; // the one named elevation: the app's own client runs its own ability
        }

        return parent::get_meta_item($key, $default_value);
    }

    /**
     * WordPress 7.2 types an ability's input by its schema here, inside execute(), where 7.1 typed
     * it as a REST argument. SpacePress's pipeline is the one validator of its abilities
     * (Register::validate), so JSON input is read as it was sent. A query string (core's GET and
     * DELETE runs) carries only strings, so core types it, and only when all of it validates: typing
     * part of an invalid filter would read "false" as true. Why it was left untyped is kept for
     * Invoke::requireTyped.
     */
    protected function sanitize_input($input)
    {
        // No input at all has nothing to type: SpacePress's own validation names what is missing.
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
