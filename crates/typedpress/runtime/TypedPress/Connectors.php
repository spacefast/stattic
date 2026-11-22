<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * `connectors.call(role, tool, args?)` from "spacepress" / "typedpress": invokes a host-managed
 * connector (such as a Spacefast Connector) through the `sp_connectors_call` WordPress filter.
 *
 * Credentials and authorization stay owned by the host; compiled app code never handles raw tokens.
 */
final class Connectors
{
    /**
     * Invoke a host connector tool for a bound role.
     *
     * @param string $role Connector role name declared by the app / Space (e.g. "slack", "github").
     * @param string $tool Tool or operation name on that connector.
     * @param mixed $args Optional JSON-compatible arguments object passed to the connector.
     */
    public static function call(string $role, string $tool, mixed $args = null): mixed
    {
        $role = trim($role);
        $tool = trim($tool);
        if ($role === '') {
            throw HttpError::invalidInput('Connector role must be a non-empty string.', '/role');
        }
        if ($tool === '') {
            throw HttpError::invalidInput('Connector tool must be a non-empty string.', '/tool');
        }
        if (!function_exists('has_filter') || !has_filter('sp_connectors_call')) {
            throw new HttpError(
                503,
                'connectors_unavailable',
                'This host has not connected a connectors transport (sp_connectors_call).'
            );
        }
        $payload = [];
        if ($args !== null && $args !== JsUndefined::Value) {
            $converted = Seam::toPhp($args);
            if (!is_array($converted)) {
                throw HttpError::invalidInput('Connector arguments must be an object.', '/args');
            }
            $payload = $converted;
        }
        $result = apply_filters('sp_connectors_call', null, $role, $tool, $payload);
        if ($result instanceof \WP_Error) {
            $data = $result->get_error_data();
            $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : 502;
            $code = (string) ($result->get_error_code() ?: 'connector_error');
            throw new HttpError($status, $code, $result->get_error_message() ?: 'Connector call failed.');
        }

        return Seam::toJs($result, ['type' => 'json']);
    }
}
