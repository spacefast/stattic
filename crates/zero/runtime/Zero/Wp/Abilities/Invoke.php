<?php

declare(strict_types=1);

namespace Zero\Wp\Abilities;

use Zero\Access;
use Zero\App;
use Zero\HttpError;
use Zero\Viewer;
use Zero\Wp\Runtime;
use Zero\Wp\WpAuth;

/**
 * The runtime half of an ability (§7.1): the permission pre-check and the call into the app.
 * Execution goes through App::invoke with the WordPress user as the Viewer, so an agent runs the
 * same pipeline, access rules and hooks as REST; the permission callback is only the early answer.
 */
final class Invoke
{
    public static function viewer(): Viewer
    {
        return new Viewer(WpAuth::current());
    }

    /**
     * The permission callback gives REST's answer early only where REST checks access before it
     * reads the input: update/delete/revisions, actions and globals. A filter rule is allowed here
     * and narrows rows in execute. Reads and creates validate input first over REST (`defer`), and
     * input REST would refuse defers too, so the pipeline in execute gives REST's exact answer.
     */
    public static function allowed(array $op, mixed $input): bool|\WP_Error
    {
        [$rule, $ruleOp, $what] = $op['rule'];
        $plugin = Runtime::plugin();
        // While storage is behind, every ability answers the same 503 before any access check
        // boots the app (call() repeats this for abilities whose check is deferred).
        if ($plugin !== null && $plugin->unavailable() !== null) {
            return new \WP_Error('zero_unavailable', \Zero\Wp\Plugin::UNAVAILABLE, ['status' => 503]);
        }
        $input = Register::sentInput($op['name'], $input);
        if (($op['defer'] ?? false) || $plugin === null || self::object($input) === null) {
            return true;
        }
        $plugin->app(); // rule functions live in the app's functions.php
        try {
            $viewer = self::viewer();
        } catch (HttpError $e) {
            return self::error($e); // a suspended account (§6.2)
        }
        try {
            $input = \Zero\Seam::toPhp($input, $op['shape']);
            $data = isset($op['data']) ? ($op['data'])(self::object($input)) : null;
            $allowed = Access::evaluate($rule, $viewer, $ruleOp, $data, $op['dataShape'] ?? null, $op['zero']['collection'] ?? null) !== false;
        } catch (\Throwable) {
            return true;
        }

        return $allowed ? true : self::error(Access::deny($viewer->user, $what));
    }

    /** Run one operation descriptor; Zero errors come back as WP_Error with their HTTP status. */
    public static function call(array $op, mixed $input): mixed
    {
        $plugin = Runtime::plugin();
        if ($plugin === null || $plugin->unavailable() !== null) {
            return new \WP_Error('zero_unavailable', $plugin === null ? 'No Zero app is active' : \Zero\Wp\Plugin::UNAVAILABLE, ['status' => 503]);
        }
        try {
            $app = $plugin->app();
            $input = self::object(\Zero\Seam::toPhp(Register::sentInput($op['name'], $input), $op['shape'])) ?? throw HttpError::invalidInput('Input must be a JSON object', '');
            if (isset($op['run'])) {
                return \Zero\Seam::toWire(($op['run'])($input));
            }
            if (Register::queryString()) {
                self::requireTyped($op, $input);
            }
            [$method, $path, $query, $body] = ($op['call'])($input);
            if ($body !== null) { $body = \Zero\Seam::inputToJs($body, $op['bodyShape']); }
            $data = $app->invoke($method, $path, $query, $body, self::viewer());

            return \Zero\Seam::toWire(isset($op['result']) ? ($op['result'])($data) : $data);
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /**
     * True when part of $value has no typed schema: core types a GET query string only where the
     * schema says how, and leaves every other value a string ("false" would read as truthy).
     * Allowed: values whose schema has a `type` other than a bare object, objects whose keys all
     * have schemas, lists whose items do. Everything else, at any depth, is untyped.
     */
    public static function untyped(mixed $value, ?array $schema): bool
    {
        if ($value === null || $value === []) {
            return false;
        }
        if ($schema === null) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        if (array_is_list($value)) {
            foreach ($value as $v) {
                if (self::untyped($v, $schema['items'] ?? null)) {
                    return true;
                }
            }

            return false;
        }
        $props = $schema['properties'] ?? null;
        if (!is_array($props)) {
            return true;
        }
        foreach ($value as $k => $v) {
            if (self::untyped($v, $props[$k] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Refuse GET input that core couldn't type; `where` and `with` accept JSON strings instead, as REST's query does. */
    private static function requireTyped(array $op, array $input): void
    {
        if (($reason = Register::untypedReason()) !== null) {
            // Core typed none of it, so every value is still a string; a filter would read "false" as true.
            preg_match('/input((?:\[[^\]]*\])+)/', $reason, $m);
            $pointer = isset($m[1]) ? implode('', array_map(HttpError::seg(...), explode('][', trim($m[1], '[]')))) : '';
            throw HttpError::invalidQuery($reason, $pointer);
        }
        foreach (['where', 'with'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key]) && self::untyped($input[$key], $op['input']['properties'][$key] ?? null)) {
                throw HttpError::invalidQuery("This `$key` nests deeper than a GET query string can type; send it as a JSON string", "/$key");
            }
        }
    }

    /** An omitted input is `{}`; anything but an object is not an operation input. */
    public static function object(mixed $input): ?array
    {
        if ($input instanceof \Zero\JsArray) { return null; }
        $input = \Zero\Seam::container($input);

        return $input === null || is_array($input) && ($input === [] || !array_is_list($input)) ? ($input ?? []) : null;
    }

    /** RFC 9457 problem → WP_Error('zero_<code>', detail, { status, pointer? }). */
    public static function error(\Throwable $e): \WP_Error
    {
        $problem = App::problem($e);
        $data = ['status' => $problem['status']];
        if (isset($problem['pointer'])) {
            $data['pointer'] = $problem['pointer'];
        }

        return new \WP_Error('zero_' . $problem['code'], $problem['detail'], $data);
    }

    /** The reverse, for `wp zero run`: a WP_Error from core or the adapter as a Zero error. */
    public static function throw(\WP_Error $error): never
    {
        $code = (string) $error->get_error_code();
        $data = $error->get_error_data();
        $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : match ($code) {
            'ability_invalid_permissions' => WpAuth::current() === null ? 401 : 403,
            'ability_invalid_input' => 422,
            default => 500,
        };
        $zero = str_starts_with($code, 'zero_') ? substr($code, 5) : match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            422 => 'invalid_input',
            default => $code,
        };

        throw new HttpError($status, $zero, $error->get_error_message(), is_array($data) ? ($data['pointer'] ?? null) : null);
    }

    /**
     * `{ where, orderBy, limit, offset, with, ... }` → REST's query values. Other keys (`locale`,
     * `status`, unknown ones) pass through as REST would receive them, so Zero accepts or rejects
     * them exactly as it does there.
     */
    public static function listQuery(array $input): array
    {
        $q = [];
        foreach ($input as $k => $v) {
            $k = (string) $k;
            if ($k === 'orderBy' && is_array($v) && !array_is_list($v)) {
                $q[$k] = implode(',', array_map(static fn ($col, $dir): string => match ($dir) {
                    'asc' => (string) $col,
                    'desc' => '-' . $col,
                    default => throw HttpError::invalidQuery('Sort direction must be "asc" or "desc"', '/orderBy' . HttpError::seg($col)),
                }, array_keys($v), $v));
            } else {
                $q[$k] = is_string($v) ? $v : (\Zero\Js::jsonStringify($v) ?? '');
            }
        }

        return $q;
    }

    /** The row id as a path segment; core no longer pre-validates, so a missing id is Zero's 422. */
    public static function id(array $input): string
    {
        $id = $input['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && $id !== '')) {
            throw HttpError::invalidInput('id is required', '/id');
        }

        return rawurlencode((string) $id);
    }
}
